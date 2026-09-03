<?php

/*
 * QSys Router - Quellen-/Router-Umschaltung (Typ 3)
 *
 * Beide in Q-SYS uebliche Umschaltmuster, ueber "Mode" waehlbar:
 *
 *   Mode = "router"    Router-Komponente (router_with_output). Ein Auswahl-Control
 *                      je Ausgang, z. B. "select.1", Wert = Eingangsnummer (1-basiert).
 *                      Gelesen und geschrieben wird der Integer direkt.
 *
 *   Mode = "selector"  Selector-Komponente. Der aktive Eintrag steckt im Control
 *                      "selector" als JSON ({"Text":...,"Index":0-basiert}), die
 *                      komplette Optionsliste in dessen "Choices". Geschrieben wird
 *                      auf "selector.<Index>" = 1; das ist exklusiv, die uebrigen
 *                      Eintraege gehen automatisch auf false (am Core verifiziert).
 *
 * Nach aussen ist die Variable "Quelle" in beiden Faellen **1-basiert**, damit die
 * Bedienung gleich bleibt: Selector-Index 0 entspricht Quelle 1 - das ist auch die
 * Zuordnung, die Q-SYS selbst zwischen label.0 und Router-Eingang 1 verwendet.
 */

class QSysRouter extends IPSModule
{
    const CORE_GUID  = '{2D09E300-AB56-4A99-8734-580E05BDC5E0}';
    const IF_FORWARD = '{747545EE-CA0F-490F-8F42-6D240F6CAEB4}';
    const IF_FANOUT  = '{A322AA34-4023-435D-B023-1BD80BAB9E22}';

    // Bei einer Selector-Komponente heisst das Sammel-Control immer so.
    const SELECTOR_CONTROL = 'selector';

    public function Create()
    {
        parent::Create();
        $this->ConnectParent(self::CORE_GUID);

        $this->RegisterPropertyString('Mode', 'router');     // router | selector
        $this->RegisterPropertyString('ComponentName', '');
        $this->RegisterPropertyString('SelectControl', 'select.1');
        $this->RegisterPropertyString('Sources', '[]');      // [{Value:int, Label:string}]
        $this->RegisterPropertyBoolean('AutoSources', true); // nur Mode=selector

        $this->SetBuffer('LastSub', '');
        $this->SetBuffer('ChoiceHash', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Profil MUSS vor MaintainVariable existieren, sonst bricht Symcon beim
        // Anlegen der Instanz mit "Profil ... existiert nicht" ab.
        if (!IPS_VariableProfileExists($this->ProfileName())) {
            IPS_CreateVariableProfile($this->ProfileName(), 1);
        }

        // Profil aus der manuellen Liste aufbauen. Im Selector-Modus mit
        // AutoSources uebernimmt der erste Push aus "Choices" die Fuehrung.
        $sources = json_decode((string) $this->ReadPropertyString('Sources'), true);
        if (is_array($sources) && count($sources) > 0) {
            $rows = array();
            foreach ($sources as $row) {
                $val = isset($row['Value']) ? (int) $row['Value'] : 0;
                $rows[$val] = isset($row['Label']) && $row['Label'] !== ''
                    ? (string) $row['Label']
                    : ('Quelle ' . $val);
            }
            $this->WriteProfile($rows);
        }

        $this->MaintainVariable('Source', 'Quelle', VARIABLETYPE_INTEGER, $this->ProfileName(), 1, true);
        $this->EnableAction('Source');

        // Abo aktualisieren
        $component = (string) $this->ReadPropertyString('ComponentName');
        $control = $this->SubControl();
        $key = $component . "\x1f" . $control;
        $last = (string) $this->GetBuffer('LastSub');
        if ($last !== '' && $last !== $key) {
            $parts = explode("\x1f", $last, 2);
            $this->Forward(array('Type' => 'unsub', 'Component' => $parts[0], 'Control' => isset($parts[1]) ? $parts[1] : ''));
        }
        if ($control !== '') {
            $this->Forward(array('Type' => 'sub', 'Component' => $component, 'Control' => $control));
        }
        $this->SetBuffer('LastSub', $key);
    }

    // ---------------------------------------------------------------- Helfer

    private function IsSelector()
    {
        return (string) $this->ReadPropertyString('Mode') === 'selector';
    }

    // Das Control, das abonniert und gelesen wird.
    private function SubControl()
    {
        if ($this->IsSelector()) {
            return self::SELECTOR_CONTROL;
        }
        return (string) $this->ReadPropertyString('SelectControl');
    }

    private function ProfileName()
    {
        return 'QSysRouter' . $this->InstanceID;
    }

    // Schreibt die Assoziationen des instanzeigenen Profils neu.
    private function WriteProfile($rows)
    {
        $prof = $this->ProfileName();
        if (!IPS_VariableProfileExists($prof)) {
            IPS_CreateVariableProfile($prof, 1);
        }
        $existing = IPS_GetVariableProfile($prof)['Associations'];
        foreach ($existing as $assoc) {
            IPS_SetVariableProfileAssociation($prof, $assoc['Value'], '', '', -1);
        }
        foreach ($rows as $val => $label) {
            IPS_SetVariableProfileAssociation($prof, (int) $val, (string) $label, '', -1);
        }
    }

    private function Forward($obj)
    {
        $this->SendDataToParent(json_encode(array(
            'DataID' => self::IF_FORWARD,
            'Buffer' => $obj
        )));
    }

    private function SetValueIfChanged($ident, $value)
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid === false || $vid === 0) {
            return;
        }
        if (GetValue($vid) === $value) {
            return;
        }
        $this->SetValue($ident, $value);
    }

    // Liest {"Text":"...","Index":n} aus einem Selector-JSON. null wenn unbrauchbar.
    private function ParseChoice($json)
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $d = json_decode($json, true);
        if (!is_array($d) || !isset($d['Index'])) {
            return null;
        }
        return array(
            'Index' => (int) $d['Index'],
            'Text' => isset($d['Text']) ? (string) $d['Text'] : ''
        );
    }

    // ---------------------------------------------------------------- Schreiben

    public function SetSource(int $value)
    {
        $component = (string) $this->ReadPropertyString('ComponentName');

        if ($this->IsSelector()) {
            if ($component === '' || $value < 1) {
                return false;
            }
            // 1-basiert nach aussen -> 0-basierter Control-Name am Core.
            // Ein Schreibvorgang genuegt, der Selector ist exklusiv.
            $this->Forward(array(
                'Type' => 'rpc',
                'Method' => 'Component.Set',
                'Params' => array('Name' => $component, 'Controls' => array(
                    array('Name' => self::SELECTOR_CONTROL . '.' . ($value - 1), 'Value' => 1)
                ))
            ));
            return true;
        }

        $control = (string) $this->ReadPropertyString('SelectControl');
        if ($control === '') {
            return false;
        }
        if ($component === '') {
            $this->Forward(array('Type' => 'rpc', 'Method' => 'Control.Set', 'Params' => array('Name' => $control, 'Value' => $value)));
        } else {
            $this->Forward(array(
                'Type' => 'rpc',
                'Method' => 'Component.Set',
                'Params' => array('Name' => $component, 'Controls' => array(array('Name' => $control, 'Value' => $value)))
            ));
        }
        return true;
    }

    // ---------------------------------------------------------------- Empfang

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!isset($data['DataID']) || $data['DataID'] !== self::IF_FANOUT) {
            return;
        }
        $buffer = isset($data['Buffer']) ? $data['Buffer'] : null;
        if (!is_array($buffer) || !isset($buffer['Changes'])) {
            return;
        }

        $component = (string) $this->ReadPropertyString('ComponentName');
        $control = $this->SubControl();
        $selector = $this->IsSelector();

        foreach ($buffer['Changes'] as $c) {
            if (!isset($c['Name']) || (string) $c['Name'] !== $control) {
                continue;
            }
            if ((string) (isset($c['Component']) ? $c['Component'] : '') !== $component) {
                continue;
            }

            if (!$selector) {
                $this->SetValueIfChanged('Source', (int) round((float) $c['Value']));
                continue;
            }

            // Optionsliste nachziehen, sobald sie sich geaendert hat
            if ($this->ReadPropertyBoolean('AutoSources') && isset($c['Choices']) && is_array($c['Choices'])) {
                $hash = md5(json_encode($c['Choices']));
                if ($hash !== (string) $this->GetBuffer('ChoiceHash')) {
                    $rows = array();
                    foreach ($c['Choices'] as $entry) {
                        $p = $this->ParseChoice($entry);
                        if ($p === null) {
                            continue;
                        }
                        $rows[$p['Index'] + 1] = $p['Text'] !== '' ? $p['Text'] : ('Quelle ' . ($p['Index'] + 1));
                    }
                    if (count($rows) > 0) {
                        $this->WriteProfile($rows);
                        $this->SetBuffer('ChoiceHash', $hash);
                    }
                }
            }

            // Aktive Auswahl aus dem JSON des Controls
            $p = $this->ParseChoice(isset($c['String']) ? $c['String'] : '');
            if ($p !== null) {
                $this->SetValueIfChanged('Source', $p['Index'] + 1);
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident !== 'Source') {
            return;
        }
        $this->SetSource((int) $Value);
        $this->SetValueIfChanged('Source', (int) $Value);
    }
}
