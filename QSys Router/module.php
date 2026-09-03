<?php

/*
 * QSys Router - Quellen-/Router-Umschaltung (Typ 3)
 *
 * Bildet ein Auswahl-Control (z. B. "select.1" einer Router-Komponente) auf eine
 * Integer-Variable mit sprechenden Quellen-Namen ab. Umschalten schreibt den
 * Integer-Wert per Component.Set (oder Control.Set bei leerer Komponente).
 */

class QSysRouter extends IPSModule
{
    const CORE_GUID  = '{2D09E300-AB56-4A99-8734-580E05BDC5E0}';
    const IF_FORWARD = '{747545EE-CA0F-490F-8F42-6D240F6CAEB4}';
    const IF_FANOUT  = '{A322AA34-4023-435D-B023-1BD80BAB9E22}';

    public function Create()
    {
        parent::Create();
        $this->ConnectParent(self::CORE_GUID);

        $this->RegisterPropertyString('ComponentName', '');
        $this->RegisterPropertyString('SelectControl', 'select.1');
        $this->RegisterPropertyString('Sources', '[]'); // [{Value:int, Label:string}]

        $this->SetBuffer('LastSub', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Instanz-eigenes Profil aus der Quellenliste bauen
        $prof = 'QSysRouter' . $this->InstanceID;
        if (!IPS_VariableProfileExists($prof)) {
            IPS_CreateVariableProfile($prof, 1);
        }
        // vorhandene Assoziationen leeren
        $existing = IPS_GetVariableProfile($prof)['Associations'];
        foreach ($existing as $assoc) {
            IPS_SetVariableProfileAssociation($prof, $assoc['Value'], '', '', -1);
        }
        $sources = json_decode((string) $this->ReadPropertyString('Sources'), true);
        if (is_array($sources)) {
            foreach ($sources as $row) {
                $val = isset($row['Value']) ? (int) $row['Value'] : 0;
                $label = isset($row['Label']) ? (string) $row['Label'] : ('Quelle ' . $val);
                IPS_SetVariableProfileAssociation($prof, $val, $label, '', -1);
            }
        }

        $this->MaintainVariable('Source', 'Quelle', VARIABLETYPE_INTEGER, $prof, 1, true);
        $this->EnableAction('Source');

        // Abo aktualisieren
        $component = (string) $this->ReadPropertyString('ComponentName');
        $control = (string) $this->ReadPropertyString('SelectControl');
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

    public function SetSource(int $value)
    {
        $component = (string) $this->ReadPropertyString('ComponentName');
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
        $control = (string) $this->ReadPropertyString('SelectControl');

        foreach ($buffer['Changes'] as $c) {
            if ((string) $c['Name'] !== $control || (string) $c['Component'] !== $component) {
                continue;
            }
            $this->SetValueIfChanged('Source', (int) round((float) $c['Value']));
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
