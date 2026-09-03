<?php

/*
 * QSys Control - generisches benanntes Control (Typ 3)
 *
 * Bindet eine einzelne Variable an ein Q-SYS-Control:
 *  - Named Control  (Komponente leer)  -> Control.Get / Control.Set
 *  - Component-Control (Komponente gesetzt) -> Component.Get / Component.Set
 *
 * Der Wert wird ueber die ChangeGroup des Cores gepusht (kein eigenes Polling).
 */

class QSysControl extends IPSModule
{
    const CORE_GUID   = '{2D09E300-AB56-4A99-8734-580E05BDC5E0}';
    const IF_FORWARD  = '{747545EE-CA0F-490F-8F42-6D240F6CAEB4}';
    const IF_FANOUT   = '{A322AA34-4023-435D-B023-1BD80BAB9E22}';

    public function Create()
    {
        parent::Create();
        $this->ConnectParent(self::CORE_GUID);

        $this->RegisterPropertyString('ComponentName', '');
        $this->RegisterPropertyString('ControlName', '');
        $this->RegisterPropertyString('ValueType', 'float'); // float | bool | string
        $this->RegisterPropertyString('Profile', '');
        $this->RegisterPropertyFloat('Ramp', 0.0);
        $this->RegisterPropertyBoolean('Writable', true);
        $this->RegisterPropertyBoolean('ShowString', false);

        $this->SetBuffer('LastSub', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $type = (string) $this->ReadPropertyString('ValueType');
        $profile = (string) $this->ReadPropertyString('Profile');

        // Hauptvariable je nach Typ anlegen
        if ($type === 'bool') {
            $this->MaintainVariable('Value', 'Wert', VARIABLETYPE_BOOLEAN, $profile, 1, true);
        } elseif ($type === 'string') {
            $this->MaintainVariable('Value', 'Wert', VARIABLETYPE_STRING, $profile, 1, true);
        } else {
            $this->MaintainVariable('Value', 'Wert', VARIABLETYPE_FLOAT, $profile, 1, true);
        }

        if ($this->ReadPropertyBoolean('Writable')) {
            $this->EnableAction('Value');
        }

        $this->MaintainVariable('Text', 'Anzeige', VARIABLETYPE_STRING, '', 2, $this->ReadPropertyBoolean('ShowString'));

        // Abo aktualisieren (altes abmelden, neues anmelden)
        $component = (string) $this->ReadPropertyString('ComponentName');
        $control = (string) $this->ReadPropertyString('ControlName');
        $key = $component . "\x1f" . $control;
        $last = (string) $this->GetBuffer('LastSub');
        if ($last !== $key && $last !== '') {
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
        // Beim Anlegen der Instanz laeuft ApplyChanges, bevor ein Core verbunden
        // ist. Ohne diese Pruefung meldet Symcon "Keine uebergeordnete Instanz ist
        // konfiguriert, welche die Daten verarbeiten kann" und bricht das Anlegen
        // ab. Sobald der Core haengt, laeuft ApplyChanges erneut und abonniert.
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
        $old = GetValue($vid);
        if (is_float($old) || is_float($value)) {
            if (round((float) $old, 3) === round((float) $value, 3)) {
                return;
            }
        } else {
            if ($old === $value) {
                return;
            }
        }
        $this->SetValue($ident, $value);
    }

    // Wert an den Core schreiben
    private function SendSet($value, $useRamp)
    {
        $component = (string) $this->ReadPropertyString('ComponentName');
        $control = (string) $this->ReadPropertyString('ControlName');
        if ($control === '') {
            return;
        }
        $ramp = (float) $this->ReadPropertyFloat('Ramp');

        if ($component === '') {
            $params = array('Name' => $control, 'Value' => $value);
            if ($useRamp && $ramp > 0) {
                $params['Ramp'] = $ramp;
            }
            $this->Forward(array('Type' => 'rpc', 'Method' => 'Control.Set', 'Params' => $params));
        } else {
            $ctrl = array('Name' => $control, 'Value' => $value);
            if ($useRamp && $ramp > 0) {
                $ctrl['Ramp'] = $ramp;
            }
            $this->Forward(array(
                'Type' => 'rpc',
                'Method' => 'Component.Set',
                'Params' => array('Name' => $component, 'Controls' => array($ctrl))
            ));
        }
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
        $control = (string) $this->ReadPropertyString('ControlName');
        $type = (string) $this->ReadPropertyString('ValueType');

        foreach ($buffer['Changes'] as $c) {
            if ((string) $c['Name'] !== $control) {
                continue;
            }
            if ((string) $c['Component'] !== $component) {
                continue;
            }

            if ($type === 'bool') {
                $this->SetValueIfChanged('Value', ((float) $c['Value']) >= 0.5);
            } elseif ($type === 'string') {
                $this->SetValueIfChanged('Value', (string) $c['String']);
            } else {
                $this->SetValueIfChanged('Value', (float) $c['Value']);
            }
            if ($this->ReadPropertyBoolean('ShowString')) {
                $this->SetValueIfChanged('Text', (string) $c['String']);
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident !== 'Value') {
            return;
        }
        $type = (string) $this->ReadPropertyString('ValueType');
        if ($type === 'bool') {
            $this->SendSet($Value ? 1 : 0, false);
        } elseif ($type === 'string') {
            $this->SendSet((string) $Value, false);
        } else {
            $this->SendSet((float) $Value, true);
        }
        $this->SetValueIfChanged('Value', $Value);
    }

    // Manuelle Sofort-Abfrage (Konsole/Skript)
    public function Sync()
    {
        $this->Forward(array(
            'Type' => 'get',
            'Component' => (string) $this->ReadPropertyString('ComponentName'),
            'Controls' => array((string) $this->ReadPropertyString('ControlName'))
        ));
    }
}
