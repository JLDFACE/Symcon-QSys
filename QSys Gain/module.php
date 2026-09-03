<?php

/*
 * QSys Gain - Lautstaerke/Pegel einer Q-SYS Gain-Komponente (Typ 3)
 *
 * Steuert das "gain"-Control (dB) und das "mute"-Control einer Named Component.
 *  - Level in dB (direkt) und Level in % (ueber die Fader-Position 0..1, taper-korrekt)
 *  - fluessige Fades ueber Component.Set mit Ramp
 *  - optionaler KNX-Relativ-Dimm-Block (uebernommen aus dem Bose-Gain)
 */

class QSysGain extends IPSModule
{
    const CORE_GUID  = '{2D09E300-AB56-4A99-8734-580E05BDC5E0}';
    const IF_FORWARD = '{747545EE-CA0F-490F-8F42-6D240F6CAEB4}';
    const IF_FANOUT  = '{A322AA34-4023-435D-B023-1BD80BAB9E22}';

    public function Create()
    {
        parent::Create();
        $this->ConnectParent(self::CORE_GUID);

        $this->RegisterPropertyString('ComponentName', '');
        $this->RegisterPropertyString('GainControl', 'gain');
        $this->RegisterPropertyString('MuteControl', 'mute');
        $this->RegisterPropertyFloat('MinDB', -100.0);
        $this->RegisterPropertyFloat('MaxDB', 20.0);
        $this->RegisterPropertyFloat('Ramp', 0.0);

        // KNX Relativ-Dimm (optional)
        $this->RegisterPropertyInteger('KnxDirectionVarID', 0);
        $this->RegisterPropertyInteger('KnxMoveVarID', 0);
        $this->RegisterPropertyInteger('KnxStepPercent', 3);

        $this->RegisterTimer('KnxDimTimer', 0, 'QSYS_KnxDimStep(' . $this->InstanceID . ');');

        $this->SetBuffer('LastSub', '');

        if (!IPS_VariableProfileExists('QSysMute')) {
            IPS_CreateVariableProfile('QSysMute', 0);
        }
        IPS_SetVariableProfileAssociation('QSysMute', false, 'Ton Ein', 'Speaker', 0x00ff00);
        IPS_SetVariableProfileAssociation('QSysMute', true, 'Stumm', 'Mute', 0xff0000);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $min = (float) $this->ReadPropertyFloat('MinDB');
        $max = (float) $this->ReadPropertyFloat('MaxDB');
        if ($max <= $min) {
            $max = $min + 1.0;
        }

        // Instanz-eigenes dB-Profil (fuer den Slider-Bereich)
        $prof = 'QSysGainDB' . $this->InstanceID;
        if (!IPS_VariableProfileExists($prof)) {
            IPS_CreateVariableProfile($prof, 2);
        }
        IPS_SetVariableProfileText($prof, '', ' dB');
        IPS_SetVariableProfileValues($prof, $min, $max, 0.5);
        IPS_SetVariableProfileDigits($prof, 1);
        IPS_SetVariableProfileIcon($prof, 'Speaker');

        $this->MaintainVariable('Level', 'Pegel dB', VARIABLETYPE_FLOAT, $prof, 1, true);
        $this->MaintainVariable('LevelPercent', 'Pegel %', VARIABLETYPE_INTEGER, '~Intensity.100', 2, true);
        $this->MaintainVariable('Mute', 'Stumm', VARIABLETYPE_BOOLEAN, 'QSysMute', 3, true);
        $this->EnableAction('Level');
        $this->EnableAction('LevelPercent');
        $this->EnableAction('Mute');

        // Abo aktualisieren
        $component = (string) $this->ReadPropertyString('ComponentName');
        $gain = (string) $this->ReadPropertyString('GainControl');
        $mute = (string) $this->ReadPropertyString('MuteControl');
        $key = $component . '|' . $gain . '|' . $mute;
        $last = (string) $this->GetBuffer('LastSub');
        if ($last !== '' && $last !== $key) {
            $lp = explode('|', $last);
            if (isset($lp[1]) && $lp[1] !== '') {
                $this->Forward(array('Type' => 'unsub', 'Component' => $lp[0], 'Control' => $lp[1]));
            }
            if (isset($lp[2]) && $lp[2] !== '') {
                $this->Forward(array('Type' => 'unsub', 'Component' => $lp[0], 'Control' => $lp[2]));
            }
        }
        if ($component !== '' && $gain !== '') {
            $this->Forward(array('Type' => 'sub', 'Component' => $component, 'Control' => $gain));
        }
        if ($component !== '' && $mute !== '') {
            $this->Forward(array('Type' => 'sub', 'Component' => $component, 'Control' => $mute));
        }
        $this->SetBuffer('LastSub', $key);

        // KNX: alte VM_UPDATE-Registrierungen loeschen, neue setzen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                if ($message == VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }
        $moveVarID = (int) $this->ReadPropertyInteger('KnxMoveVarID');
        if ($moveVarID > 0 && IPS_VariableExists($moveVarID)) {
            $this->RegisterMessage($moveVarID, VM_UPDATE);
        }
        $this->SetTimerInterval('KnxDimTimer', 0);
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

    private function ComponentSet($control, $field, $value, $useRamp)
    {
        $ctrl = array('Name' => $control, $field => $value);
        $ramp = (float) $this->ReadPropertyFloat('Ramp');
        if ($useRamp && $ramp > 0) {
            $ctrl['Ramp'] = $ramp;
        }
        $this->Forward(array(
            'Type' => 'rpc',
            'Method' => 'Component.Set',
            'Params' => array('Name' => (string) $this->ReadPropertyString('ComponentName'), 'Controls' => array($ctrl))
        ));
    }

    public function SetLevel(float $db)
    {
        $min = (float) $this->ReadPropertyFloat('MinDB');
        $max = (float) $this->ReadPropertyFloat('MaxDB');
        if ($db < $min) {
            $db = $min;
        }
        if ($db > $max) {
            $db = $max;
        }
        $this->ComponentSet((string) $this->ReadPropertyString('GainControl'), 'Value', round($db, 1), true);
        return true;
    }

    public function SetLevelPercent(int $percent)
    {
        if ($percent < 0) {
            $percent = 0;
        }
        if ($percent > 100) {
            $percent = 100;
        }
        // Prozent = Fader-Position (0..1), taper-korrekt vom Core interpretiert
        $this->ComponentSet((string) $this->ReadPropertyString('GainControl'), 'Position', $percent / 100.0, true);
        return true;
    }

    public function SetMute(bool $mute)
    {
        $this->ComponentSet((string) $this->ReadPropertyString('MuteControl'), 'Value', $mute ? 1 : 0, false);
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
        $gain = (string) $this->ReadPropertyString('GainControl');
        $mute = (string) $this->ReadPropertyString('MuteControl');

        foreach ($buffer['Changes'] as $c) {
            if ((string) $c['Component'] !== $component) {
                continue;
            }
            $name = (string) $c['Name'];
            if ($name === $gain) {
                $this->SetValueIfChanged('Level', round((float) $c['Value'], 1));
                if (isset($c['Position']) && $c['Position'] !== null) {
                    $this->SetValueIfChanged('LevelPercent', (int) round(((float) $c['Position']) * 100));
                }
            } elseif ($name === $mute && $mute !== '') {
                $this->SetValueIfChanged('Mute', ((float) $c['Value']) >= 0.5);
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Level') {
            $this->SetLevel((float) $Value);
            $this->SetValueIfChanged('Level', round((float) $Value, 1));
        } elseif ($Ident === 'LevelPercent') {
            $this->SetLevelPercent((int) $Value);
            $this->SetValueIfChanged('LevelPercent', (int) $Value);
        } elseif ($Ident === 'Mute') {
            $this->SetMute((bool) $Value);
            $this->SetValueIfChanged('Mute', (bool) $Value);
        }
    }

    // ---- KNX Relativ-Dimm (aus Bose-Gain) ----

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message != VM_UPDATE) {
            return;
        }
        $moveVarID = (int) $this->ReadPropertyInteger('KnxMoveVarID');
        if ($SenderID != $moveVarID) {
            return;
        }
        if ((int) GetValueInteger($moveVarID) == 1) {
            $this->KnxDimStep();
            $this->SetTimerInterval('KnxDimTimer', 1000);
        } else {
            $this->SetTimerInterval('KnxDimTimer', 0);
        }
    }

    public function KnxDimStep()
    {
        $dirVarID = (int) $this->ReadPropertyInteger('KnxDirectionVarID');
        $step = max(1, (int) $this->ReadPropertyInteger('KnxStepPercent'));
        $goUp = ($dirVarID > 0 && IPS_VariableExists($dirVarID)) ? GetValueBoolean($dirVarID) : true;

        $current = (int) $this->GetValue('LevelPercent');
        $newLevel = max(0, min(100, $current + ($goUp ? $step : -$step)));

        if ($newLevel !== $current) {
            $this->SetLevelPercent($newLevel);
            $this->SetValueIfChanged('LevelPercent', $newLevel);
        }
        if ($newLevel <= 0 || $newLevel >= 100) {
            $this->SetTimerInterval('KnxDimTimer', 0);
        }
    }
}
