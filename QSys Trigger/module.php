<?php

/*
 * QSys Trigger - momentane Trigger/Tasten (Typ 3)
 *
 * Legt pro konfiguriertem Trigger eine Boolean-Taste an. Ein Klick sendet
 * Component.Set (bzw. Control.Set) mit Value = 1 (momentan) und setzt die
 * Variable sofort wieder zurueck. Fuer Page/Bell/Mute-All etc.
 */

class QSysTrigger extends IPSModule
{
    const CORE_GUID  = '{2D09E300-AB56-4A99-8734-580E05BDC5E0}';
    const IF_FORWARD = '{747545EE-CA0F-490F-8F42-6D240F6CAEB4}';
    const MAX_ROWS   = 32;

    public function Create()
    {
        parent::Create();
        $this->ConnectParent(self::CORE_GUID);

        $this->RegisterPropertyString('Triggers', '[]'); // [{Label, Component, Control}]

        if (!IPS_VariableProfileExists('QSysTrigger')) {
            IPS_CreateVariableProfile('QSysTrigger', 0);
        }
        IPS_SetVariableProfileAssociation('QSysTrigger', false, 'Auslösen', 'Lightning', 0x1f78ff);
        IPS_SetVariableProfileAssociation('QSysTrigger', true, 'Auslösen', 'Lightning', 0x1f78ff);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $rows = json_decode((string) $this->ReadPropertyString('Triggers'), true);
        if (!is_array($rows)) {
            $rows = array();
        }

        for ($i = 0; $i < self::MAX_ROWS; $i++) {
            $ident = 'Trig_' . $i;
            if ($i < count($rows)) {
                $label = isset($rows[$i]['Label']) && $rows[$i]['Label'] !== ''
                    ? (string) $rows[$i]['Label']
                    : ('Trigger ' . ($i + 1));
                $this->MaintainVariable($ident, $label, VARIABLETYPE_BOOLEAN, 'QSysTrigger', 10 + $i, true);
                $this->EnableAction($ident);
                $vid = @$this->GetIDForIdent($ident);
                if ($vid > 0 && IPS_GetName($vid) !== $label) {
                    IPS_SetName($vid, $label);
                }
            } else {
                $this->MaintainVariable($ident, '', VARIABLETYPE_BOOLEAN, '', 0, false);
            }
        }
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

    public function Fire(int $index)
    {
        $rows = json_decode((string) $this->ReadPropertyString('Triggers'), true);
        if (!is_array($rows) || !isset($rows[$index])) {
            return false;
        }
        $component = isset($rows[$index]['Component']) ? (string) $rows[$index]['Component'] : '';
        $control = isset($rows[$index]['Control']) ? (string) $rows[$index]['Control'] : '';
        if ($control === '') {
            return false;
        }

        if ($component === '') {
            $this->Forward(array('Type' => 'rpc', 'Method' => 'Control.Set', 'Params' => array('Name' => $control, 'Value' => 1)));
        } else {
            $this->Forward(array(
                'Type' => 'rpc',
                'Method' => 'Component.Set',
                'Params' => array('Name' => $component, 'Controls' => array(array('Name' => $control, 'Value' => 1)))
            ));
        }
        return true;
    }

    public function RequestAction($Ident, $Value)
    {
        if (strpos($Ident, 'Trig_') !== 0) {
            return;
        }
        $index = (int) substr($Ident, 5);
        $this->Fire($index);
        // Momentan: Taste sofort zuruecksetzen
        @$this->SetValue($Ident, false);
    }
}
