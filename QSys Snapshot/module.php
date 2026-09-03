<?php

/*
 * QSys Snapshot - Snapshot-Bank laden/speichern (Typ 3)
 *
 * Snapshot.Load { Name = Bankname, Bank = Snapshot-Nummer, Ramp }
 * Snapshot.Save { Name = Bankname, Bank = Snapshot-Nummer }
 *
 * Ein Snapshot meldet keinen Rueckkanal; die Variable haelt die zuletzt
 * geladene Nummer.
 */

class QSysSnapshot extends IPSModule
{
    const CORE_GUID  = '{2D09E300-AB56-4A99-8734-580E05BDC5E0}';
    const IF_FORWARD = '{747545EE-CA0F-490F-8F42-6D240F6CAEB4}';

    public function Create()
    {
        parent::Create();
        $this->ConnectParent(self::CORE_GUID);

        $this->RegisterPropertyString('BankName', '');
        $this->RegisterPropertyInteger('Count', 8);
        $this->RegisterPropertyFloat('Ramp', 0.0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $count = (int) $this->ReadPropertyInteger('Count');
        if ($count < 1) {
            $count = 1;
        }

        $prof = 'QSysSnapshot' . $this->InstanceID;
        if (!IPS_VariableProfileExists($prof)) {
            IPS_CreateVariableProfile($prof, 1);
        }
        $existing = IPS_GetVariableProfile($prof)['Associations'];
        foreach ($existing as $assoc) {
            IPS_SetVariableProfileAssociation($prof, $assoc['Value'], '', '', -1);
        }
        IPS_SetVariableProfileValues($prof, 0, $count, 1);
        IPS_SetVariableProfileAssociation($prof, 0, '—', '', -1);
        for ($i = 1; $i <= $count; $i++) {
            IPS_SetVariableProfileAssociation($prof, $i, 'Snapshot ' . $i, 'Database', -1);
        }

        $this->MaintainVariable('Snapshot', 'Snapshot', VARIABLETYPE_INTEGER, $prof, 1, true);
        $this->EnableAction('Snapshot');
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

    public function LoadSnapshot(int $number)
    {
        $bank = (string) $this->ReadPropertyString('BankName');
        if ($bank === '' || $number < 1) {
            return false;
        }
        $params = array('Name' => $bank, 'Bank' => $number);
        $ramp = (float) $this->ReadPropertyFloat('Ramp');
        if ($ramp > 0) {
            $params['Ramp'] = $ramp;
        }
        $this->Forward(array('Type' => 'rpc', 'Method' => 'Snapshot.Load', 'Params' => $params));
        @$this->SetValue('Snapshot', $number);
        return true;
    }

    public function SaveSnapshot(int $number)
    {
        $bank = (string) $this->ReadPropertyString('BankName');
        if ($bank === '' || $number < 1) {
            return false;
        }
        $this->Forward(array(
            'Type' => 'rpc',
            'Method' => 'Snapshot.Save',
            'Params' => array('Name' => $bank, 'Bank' => $number)
        ));
        return true;
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident !== 'Snapshot') {
            return;
        }
        if ((int) $Value >= 1) {
            $this->LoadSnapshot((int) $Value);
        } else {
            @$this->SetValue('Snapshot', 0);
        }
    }
}
