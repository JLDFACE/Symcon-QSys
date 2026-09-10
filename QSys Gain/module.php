<?php

/*
 * QSys Gain - Lautstaerke/Pegel einer Q-SYS Gain-Komponente (Typ 3)
 *
 * Steuert das "gain"-Control (dB) und das "mute"-Control einer Named Component.
 *  - Level in dB (direkt) und Level in % ueber eine waehlbare Fader-Kennlinie
 *
 * Zur Kennlinie: Q-SYS bildet "Position" linear auf den dB-Bereich des Controls
 * ab, es gibt dort keinen Taper (am Core gemessen: Position 0.75 = -25 dB bei
 * einem -100..0-Gain). Ein Prozentregler ueber die Position ist damit unbrauchbar,
 * weil der gesamte nutzbare Bereich in den obersten Prozenten liegt. Deshalb
 * rechnet dieses Modul selbst um, wahlweise:
 *
 *   iec     IEC-60268-Skala (stueckweise linear, wie in Ardour/JACK gebraeuchlich).
 *           Halbe Reglerstellung = -20 dB, 0 % laeuft bis zum Minimum durch.
 *   power   Potenzkennlinie (Audio-Taper): Amplitude = Position^k, Vorgabe k = 3.
 *   linear  linear in dB ueber MinDB..MaxDB -- das alte Verhalten.
 *
 * Alle Kennlinien haengen bei 100 % an MaxDB und bei 0 % an MinDB.
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
        // 0 dB ist Schluss: der Fader regelt, er verstaerkt nicht. Wer
        // Kopffreiheit braucht, hebt MaxDB an der Instanz an.
        $this->RegisterPropertyFloat('MaxDB', 0.0);
        $this->RegisterPropertyFloat('Ramp', 0.0);
        $this->RegisterPropertyString('Curve', 'iec');   // iec | power | linear
        $this->RegisterPropertyFloat('PowerK', 3.0);     // nur bei Curve = power

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

    // ---------------------------------------------------------- Fader-Kennlinie

    // Stueckweise IEC-60268-Skala: dB (relativ zu 0) -> Prozent.
    private function IecFwd($db)
    {
        if ($db < -70.0) { return 0.0; }
        if ($db < -60.0) { return ($db + 70.0) * 0.25; }
        if ($db < -50.0) { return ($db + 60.0) * 0.50 + 2.5; }
        if ($db < -40.0) { return ($db + 50.0) * 0.75 + 7.5; }
        if ($db < -30.0) { return ($db + 40.0) * 1.50 + 15.0; }
        if ($db < -20.0) { return ($db + 30.0) * 2.00 + 30.0; }
        return ($db + 20.0) * 2.50 + 50.0;
    }

    // Umkehrung: Prozent -> dB (relativ zu 0). Stueckweise linear, exakt invertierbar.
    private function IecInv($p)
    {
        if ($p < 2.5)  { return $p / 0.25 - 70.0; }
        if ($p < 7.5)  { return ($p - 2.5) / 0.50 - 60.0; }
        if ($p < 15.0) { return ($p - 7.5) / 0.75 - 50.0; }
        if ($p < 30.0) { return ($p - 15.0) / 1.50 - 40.0; }
        if ($p < 50.0) { return ($p - 30.0) / 2.00 - 30.0; }
        return ($p - 50.0) / 2.50 - 20.0;
    }

    private function PercentToDb($percent)
    {
        $min = (float) $this->ReadPropertyFloat('MinDB');
        $max = (float) $this->ReadPropertyFloat('MaxDB');
        $p = (float) $percent;
        if ($p <= 0.0) { return $min; }
        if ($p >= 100.0) { return $max; }

        switch ((string) $this->ReadPropertyString('Curve')) {
            case 'linear':
                $db = $min + ($p / 100.0) * ($max - $min);
                break;
            case 'power':
                $k = (float) $this->ReadPropertyFloat('PowerK');
                if ($k <= 0.0) { $k = 3.0; }
                $db = $max + 20.0 * $k * log10($p / 100.0);
                break;
            default: // iec
                $db = $max + $this->IecInv($p);
                break;
        }
        if ($db < $min) { $db = $min; }
        if ($db > $max) { $db = $max; }
        return $db;
    }

    private function DbToPercent($db)
    {
        $min = (float) $this->ReadPropertyFloat('MinDB');
        $max = (float) $this->ReadPropertyFloat('MaxDB');
        $db = (float) $db;
        if ($db <= $min) { return 0; }
        if ($db >= $max) { return 100; }

        switch ((string) $this->ReadPropertyString('Curve')) {
            case 'linear':
                $span = $max - $min;
                $p = ($span != 0.0) ? (($db - $min) / $span) * 100.0 : 0.0;
                break;
            case 'power':
                $k = (float) $this->ReadPropertyFloat('PowerK');
                if ($k <= 0.0) { $k = 3.0; }
                $p = 100.0 * pow(10.0, ($db - $max) / (20.0 * $k));
                break;
            default: // iec
                $p = $this->IecFwd($db - $max);
                break;
        }
        if ($p < 0.0) { $p = 0.0; }
        if ($p > 100.0) { $p = 100.0; }
        return (int) round($p);
    }

    public function SetLevelPercent(int $percent)
    {
        if ($percent < 0) {
            $percent = 0;
        }
        if ($percent > 100) {
            $percent = 100;
        }
        // Nicht mehr ueber "Position": die ist am Core linear in dB und damit als
        // Regler unbrauchbar. Wir rechnen selbst um und schreiben den dB-Wert.
        $this->ComponentSet((string) $this->ReadPropertyString('GainControl'), 'Value',
            round($this->PercentToDb($percent), 1), true);
        return true;
    }

    public function SetMute(bool $mute)
    {
        $this->ComponentSet((string) $this->ReadPropertyString('MuteControl'), 'Value', $mute ? 1 : 0, false);
        return true;
    }

    // Meldet die aktuellen Abos erneut an. AddSub im Core ist idempotent, ein
    // doppelter Aufruf schadet also nicht.
    private function Resubscribe()
    {
        $component = (string) $this->ReadPropertyString('ComponentName');
        $gain = (string) $this->ReadPropertyString('GainControl');
        $mute = (string) $this->ReadPropertyString('MuteControl');
        if ($component === '') {
            return;
        }
        if ($gain !== '') {
            $this->Forward(array('Type' => 'sub', 'Component' => $component, 'Control' => $gain));
        }
        if ($mute !== '') {
            $this->Forward(array('Type' => 'sub', 'Component' => $component, 'Control' => $mute));
        }
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!isset($data['DataID']) || $data['DataID'] !== self::IF_FANOUT) {
            return;
        }
        $buffer = isset($data['Buffer']) ? $data['Buffer'] : null;
        // Der Core bittet nach dem Verbindungsaufbau um erneute Anmeldung, weil ein
        // beim Hochlauf verlorenes Abo sonst nie wiederkaeme.
        if (is_array($buffer) && isset($buffer['Resync'])) {
            $this->Resubscribe();
            return;
        }
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
                $db = round((float) $c['Value'], 1);
                $this->SetValueIfChanged('Level', $db);
                // Prozent aus dB ueber dieselbe Kennlinie wie beim Schreiben --
                // "Position" waere linear in dB und wuerde nicht dazu passen.
                $this->SetValueIfChanged('LevelPercent', $this->DbToPercent($db));
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
