<?php

/*
 * QSys Text Controller - Text-/Auswahlcontrol eines Text Controllers (Typ 3)
 *
 * Q-SYS Text Controller melden sich ueber QRC als Komponententyp
 * "device_controller_script". Ihre Nutz-Controls sind Text-Controls; im
 * SKUZ-Design heissen sie "dd.routing" und tragen ihre Auswahlliste im Feld
 * "Choices" (einfache Strings, anders als beim Selector, wo je Eintrag ein
 * JSON-Objekt steht).
 *
 * Fuehrt das Control eine Auswahlliste, entsteht eine Integer-Variable
 * "Auswahl" mit instanzeigenem Profil -- 1-basiert wie beim Router, damit die
 * Bedienung ueber alle QSys-Module gleich bleibt. Geschrieben wird immer der
 * Text selbst, das erwartet der Core bei Text-Controls.
 *
 * SymBox-sicher: kein declare(strict_types), keine PHP8-only-Konstrukte.
 */

class QSysTextController extends IPSModule
{
    const CORE_GUID  = '{2D09E300-AB56-4A99-8734-580E05BDC5E0}';
    const IF_FORWARD = '{747545EE-CA0F-490F-8F42-6D240F6CAEB4}';
    const IF_FANOUT  = '{A322AA34-4023-435D-B023-1BD80BAB9E22}';

    public function Create()
    {
        parent::Create();
        $this->ConnectParent(self::CORE_GUID);

        $this->RegisterPropertyString('ComponentName', '');
        $this->RegisterPropertyString('ControlName', 'dd.routing');
        $this->RegisterPropertyBoolean('AutoChoices', true);
        $this->RegisterPropertyString('Choices', '[]'); // ["Bluetooth", ...] als Startwert

        $this->SetBuffer('LastSub', '');
        $this->SetBuffer('ChoiceHash', '');
        $this->SetBuffer('Choices', '[]');
    }

    // ConnectParent() in Create() greift nur, wenn Symcon die Instanz selbst ueber
    // den Instanz-Dialog anlegt. Per Skript oder ueber den Configurator erzeugte
    // Instanzen bleiben auf ConnectionID 0 und haengen an keinem Core -- dann gibt
    // es kein Abo, keinen Fan-out und keine Werte, obwohl die Instanz auf Status 102
    // steht (auf der Catan C1 mit Symcon 9.0 verifiziert). Deshalb hier nachziehen.
    private function EnsureCoreConnection()
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        if (is_array($inst) && (int) $inst['ConnectionID'] > 0) {
            return;
        }
        $cores = @IPS_GetInstanceListByModuleID(self::CORE_GUID);
        if (!is_array($cores) || count($cores) !== 1) {
            return; // kein oder mehrere Cores -- nicht raten, das muss der Anwender entscheiden
        }
        @IPS_ConnectInstance($this->InstanceID, $cores[0]);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->EnsureCoreConnection();

        $choices = $this->CurrentChoices();

        // Profil MUSS vor MaintainVariable existieren, sonst bricht Symcon beim
        // Anlegen der Variable ab (gleiche Reihenfolge wie im Router).
        if (count($choices) > 0) {
            $this->WriteProfile($choices);
        }

        $hasChoices = count($choices) > 0;
        $this->MaintainVariable('Selection', 'Auswahl', VARIABLETYPE_INTEGER, $this->ProfileName(), 1, $hasChoices);
        $this->MaintainVariable('Text', 'Text', VARIABLETYPE_STRING, '', 2, true);

        if ($hasChoices) {
            $this->EnableAction('Selection');
            $this->DisableAction('Text'); // die Auswahl ist der Bedienweg
        } else {
            $this->EnableAction('Text');
        }

        // Abo aktualisieren
        $component = (string) $this->ReadPropertyString('ComponentName');
        $control = (string) $this->ReadPropertyString('ControlName');
        $key = $component . "\x1f" . $control;
        $last = (string) $this->GetBuffer('LastSub');
        if ($last !== '' && $last !== $key) {
            $parts = explode("\x1f", $last, 2);
            $this->Forward(array(
                'Type' => 'unsub',
                'Component' => $parts[0],
                'Control' => isset($parts[1]) ? $parts[1] : ''
            ));
        }
        if ($component !== '' && $control !== '') {
            $this->Forward(array('Type' => 'sub', 'Component' => $component, 'Control' => $control));
        }
        $this->SetBuffer('LastSub', $key);
    }

    // ---------------------------------------------------------------- Helfer

    private function ProfileName()
    {
        return 'QSysTC' . $this->InstanceID;
    }

    // Zuletzt bekannte Auswahlliste: bevorzugt die vom Core gemeldete aus dem
    // Puffer, sonst der in der Konfiguration hinterlegte Startwert.
    private function CurrentChoices()
    {
        $buffered = json_decode((string) $this->GetBuffer('Choices'), true);
        if (is_array($buffered) && count($buffered) > 0) {
            return $buffered;
        }
        $configured = json_decode((string) $this->ReadPropertyString('Choices'), true);
        return is_array($configured) ? $configured : array();
    }

    private function StoreChoices($choices)
    {
        $this->SetBuffer('Choices', json_encode(array_values($choices)));
    }

    // Schreibt die Assoziationen des instanzeigenen Profils neu (1-basiert).
    private function WriteProfile($choices)
    {
        $prof = $this->ProfileName();
        if (!IPS_VariableProfileExists($prof)) {
            IPS_CreateVariableProfile($prof, 1);
        }
        $existing = IPS_GetVariableProfile($prof)['Associations'];
        foreach ($existing as $assoc) {
            IPS_SetVariableProfileAssociation($prof, $assoc['Value'], '', '', -1);
        }
        $i = 1;
        foreach ($choices as $label) {
            $text = (string) $label;
            IPS_SetVariableProfileAssociation($prof, $i, $text !== '' ? $text : ('Auswahl ' . $i), '', -1);
            $i++;
        }
    }

    private function Forward($obj)
    {
        // Beim Anlegen der Instanz laeuft ApplyChanges, bevor ein Core verbunden
        // ist. Ohne diese Pruefung meldet Symcon "Keine uebergeordnete Instanz ist
        // konfiguriert, welche die Daten verarbeiten kann" und bricht das Anlegen ab.
        if (!$this->HasActiveParent()) {
            return;
        }
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

    // ---------------------------------------------------------------- Schreiben

    // Setzt den Text direkt. Text-Controls erwarten den String im Feld "Value".
    public function SetText(string $text)
    {
        $component = (string) $this->ReadPropertyString('ComponentName');
        $control = (string) $this->ReadPropertyString('ControlName');
        if ($component === '' || $control === '') {
            return false;
        }
        $this->Forward(array(
            'Type' => 'rpc',
            'Method' => 'Component.Set',
            'Params' => array('Name' => $component, 'Controls' => array(
                array('Name' => $control, 'Value' => $text)
            ))
        ));
        return true;
    }

    // Waehlt den n-ten Eintrag der Auswahlliste (1-basiert).
    public function SetSelection(int $value)
    {
        $choices = $this->CurrentChoices();
        if ($value < 1 || $value > count($choices)) {
            return false;
        }
        return $this->SetText((string) $choices[$value - 1]);
    }

    // Holt den aktuellen Wert beim Core (Erst-Sync von Hand).
    public function SyncText()
    {
        $component = (string) $this->ReadPropertyString('ComponentName');
        $control = (string) $this->ReadPropertyString('ControlName');
        if ($component === '' || $control === '') {
            return false;
        }
        $this->Forward(array(
            'Type' => 'get',
            'Component' => $component,
            'Controls' => array($control)
        ));
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
        $control = (string) $this->ReadPropertyString('ControlName');

        foreach ($buffer['Changes'] as $c) {
            if (!isset($c['Name']) || (string) $c['Name'] !== $control) {
                continue;
            }
            if ((string) (isset($c['Component']) ? $c['Component'] : '') !== $component) {
                continue;
            }

            // Auswahlliste nachziehen, sobald sie sich geaendert hat. Die Choices
            // eines Text-Controls sind einfache Strings.
            if ($this->ReadPropertyBoolean('AutoChoices') && isset($c['Choices']) && is_array($c['Choices'])) {
                $hash = md5(json_encode($c['Choices']));
                if ($hash !== (string) $this->GetBuffer('ChoiceHash') && count($c['Choices']) > 0) {
                    $labels = array();
                    foreach ($c['Choices'] as $entry) {
                        $labels[] = (string) $entry;
                    }
                    $this->StoreChoices($labels);
                    $this->WriteProfile($labels);
                    $this->SetBuffer('ChoiceHash', $hash);
                    // Variable erst jetzt anlegen, wenn sie vorher nicht noetig war
                    $this->MaintainVariable('Selection', 'Auswahl', VARIABLETYPE_INTEGER, $this->ProfileName(), 1, true);
                    $this->EnableAction('Selection');
                    $this->DisableAction('Text');
                }
            }

            $text = isset($c['String']) ? (string) $c['String'] : '';
            $this->SetValueIfChanged('Text', $text);

            $choices = $this->CurrentChoices();
            if (count($choices) > 0) {
                $idx = array_search($text, array_map('strval', $choices), true);
                if ($idx !== false) {
                    $this->SetValueIfChanged('Selection', ((int) $idx) + 1);
                }
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Selection') {
            $this->SetSelection((int) $Value);
            $this->SetValueIfChanged('Selection', (int) $Value);
            return;
        }
        if ($Ident === 'Text') {
            $this->SetText((string) $Value);
            $this->SetValueIfChanged('Text', (string) $Value);
        }
    }
}
