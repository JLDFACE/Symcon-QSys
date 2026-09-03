<?php

/*
 * QSys EQ - parametrischer Equalizer mit Kurvendarstellung (Typ 3)
 *
 * Liest eine equalizer_parametric-Komponente und zeichnet ihren Frequenzgang als
 * SVG in eine HTMLBox-Variable. Bedienbar sind Gain, Q und Bypass -- jeweils fuer
 * das ueber "Band" gewaehlte Band, damit die Visu mit vier Bedienelementen
 * auskommt statt mit einem Satz pro Band.
 *
 * Am Core verifizierte Grundlagen (Design SKUZ_FACE_190826):
 *   - pro Band: frequency.N, gain.N, bandwidth.N, q.factor.N, type.N, bypass.N
 *   - type.N: 1 = Parametric, 2 = Low-Shelf, 3 = High-Shelf
 *   - q.factor.N ist zwar Typ "Virtual", pusht aber ueber die ChangeGroup und
 *     ist direkt beschreibbar; bandwidth zieht dabei automatisch nach.
 *   - Q aus Bandbreite in Oktaven: Q = sqrt(2^BW) / (2^BW - 1)  (am Core geprueft)
 *   - global: master.gain, bypass, mute, invert
 *
 * Die Kurve ist berechnet, nicht gemessen: RBJ-Biquads bei 48 kHz, Betragsgang an
 * logarithmisch verteilten Stuetzstellen, Baender in dB addiert.
 */

class QSysEQ extends IPSModule
{
    const CORE_GUID  = '{2D09E300-AB56-4A99-8734-580E05BDC5E0}';
    const IF_FORWARD = '{747545EE-CA0F-490F-8F42-6D240F6CAEB4}';
    const IF_FANOUT  = '{A322AA34-4023-435D-B023-1BD80BAB9E22}';

    const MAX_BANDS = 32;
    const TYPE_PEAK = 1;
    const TYPE_LOWSHELF = 2;
    const TYPE_HIGHSHELF = 3;

    public function Create()
    {
        parent::Create();
        $this->ConnectParent(self::CORE_GUID);

        $this->RegisterPropertyString('ComponentName', '');
        $this->RegisterPropertyInteger('BandCount', 0);      // 0 = automatisch ermitteln
        $this->RegisterPropertyInteger('SampleRate', 48000);
        $this->RegisterPropertyFloat('RangeDB', 18.0);       // vertikale Halbskala
        $this->RegisterPropertyBoolean('ShowBands', true);   // Einzelbaender duenn mitzeichnen
        $this->RegisterPropertyBoolean('DarkMode', true);

        if (!IPS_VariableProfileExists('QSysEQBypass')) {
            IPS_CreateVariableProfile('QSysEQBypass', 0);
        }
        IPS_SetVariableProfileAssociation('QSysEQBypass', false, 'Aktiv', 'Filter', 0x00ff00);
        IPS_SetVariableProfileAssociation('QSysEQBypass', true, 'Umgangen', 'Filter', 0x808080);

        $this->SetBuffer('Bands', '{}');
        $this->SetBuffer('Global', '{}');
        $this->SetBuffer('LastSub', '');
        $this->SetBuffer('KnownBands', '0');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $prefix = 'QSysEQ' . $this->InstanceID;
        foreach (array(
            array($prefix . 'Gain', 2, -20.0, 20.0, 0.1, 1, ' dB'),
            array($prefix . 'Q', 2, 0.1, 25.0, 0.05, 2, '')
        ) as $p) {
            if (!IPS_VariableProfileExists($p[0])) {
                IPS_CreateVariableProfile($p[0], $p[1]);
            }
            IPS_SetVariableProfileValues($p[0], $p[2], $p[3], $p[4]);
            IPS_SetVariableProfileDigits($p[0], $p[5]);
            IPS_SetVariableProfileText($p[0], '', $p[6]);
        }
        $bandProf = $prefix . 'Band';
        if (!IPS_VariableProfileExists($bandProf)) {
            IPS_CreateVariableProfile($bandProf, 1);
        }
        $masterProf = $prefix . 'Master';
        if (!IPS_VariableProfileExists($masterProf)) {
            IPS_CreateVariableProfile($masterProf, 2);
        }
        IPS_SetVariableProfileValues($masterProf, -20.0, 20.0, 0.1);
        IPS_SetVariableProfileDigits($masterProf, 1);
        IPS_SetVariableProfileText($masterProf, '', ' dB');

        $this->MaintainVariable('Curve', 'Frequenzgang', VARIABLETYPE_STRING, '~HTMLBox', 1, true);
        $this->MaintainVariable('Band', 'Band', VARIABLETYPE_INTEGER, $bandProf, 2, true);
        $this->MaintainVariable('Gain', 'Gain', VARIABLETYPE_FLOAT, $prefix . 'Gain', 3, true);
        $this->MaintainVariable('Q', 'Q', VARIABLETYPE_FLOAT, $prefix . 'Q', 4, true);
        $this->MaintainVariable('Bypass', 'Band umgangen', VARIABLETYPE_BOOLEAN, 'QSysEQBypass', 5, true);
        $this->MaintainVariable('MasterGain', 'Master-Gain', VARIABLETYPE_FLOAT, $masterProf, 6, true);
        $this->MaintainVariable('EQBypass', 'EQ umgangen', VARIABLETYPE_BOOLEAN, 'QSysEQBypass', 7, true);
        $this->MaintainVariable('Mute', 'Stumm', VARIABLETYPE_BOOLEAN, 'QSysMute', 8, true);

        foreach (array('Band', 'Gain', 'Q', 'Bypass', 'MasterGain', 'EQBypass', 'Mute') as $i) {
            $this->EnableAction($i);
        }

        $component = (string) $this->ReadPropertyString('ComponentName');
        $bands = (int) $this->ReadPropertyInteger('BandCount');
        if ($bands < 1) {
            $bands = (int) $this->GetBuffer('KnownBands');
        }

        $key = $component . '#' . $bands;
        if ((string) $this->GetBuffer('LastSub') !== $key) {
            $this->Unsubscribe();
            $this->SetBuffer('Bands', '{}');
            $this->SetBuffer('Global', '{}');
        }

        if ($component === '') {
            $this->SetBuffer('LastSub', $key);
            return;
        }

        // Bandzahl noch unbekannt: Controlliste anfordern. Der Core reicht die
        // Antwort von Component.GetControls als Changes an die Kinder weiter.
        if ($bands < 1) {
            $this->Forward(array('Type' => 'rpc', 'Method' => 'Component.GetControls',
                'Params' => array('Name' => $component)));
            $this->SetBuffer('LastSub', $key);
            return;
        }

        $this->Subscribe($component, $bands);
        $this->SetBuffer('LastSub', $key);
        $this->BuildBandProfile($bands);
        $this->Render();
    }

    // ------------------------------------------------------------- Abos

    private function BandControls($n)
    {
        return array('frequency.' . $n, 'gain.' . $n, 'q.factor.' . $n, 'type.' . $n, 'bypass.' . $n);
    }

    private function GlobalControls()
    {
        return array('master.gain', 'bypass', 'mute');
    }

    private function Subscribe($component, $bands)
    {
        foreach ($this->GlobalControls() as $c) {
            $this->Forward(array('Type' => 'sub', 'Component' => $component, 'Control' => $c));
        }
        for ($n = 1; $n <= $bands; $n++) {
            foreach ($this->BandControls($n) as $c) {
                $this->Forward(array('Type' => 'sub', 'Component' => $component, 'Control' => $c));
            }
        }
    }

    private function Unsubscribe()
    {
        $last = (string) $this->GetBuffer('LastSub');
        if ($last === '') {
            return;
        }
        $parts = explode('#', $last);
        $comp = $parts[0];
        $bands = isset($parts[1]) ? (int) $parts[1] : 0;
        if ($comp === '' || $bands < 1) {
            return;
        }
        foreach ($this->GlobalControls() as $c) {
            $this->Forward(array('Type' => 'unsub', 'Component' => $comp, 'Control' => $c));
        }
        for ($n = 1; $n <= $bands; $n++) {
            foreach ($this->BandControls($n) as $c) {
                $this->Forward(array('Type' => 'unsub', 'Component' => $comp, 'Control' => $c));
            }
        }
    }

    private function Forward($obj)
    {
        // Beim Anlegen laeuft ApplyChanges, bevor ein Core haengt.
        if (!$this->HasActiveParent()) {
            return;
        }
        $this->SendDataToParent(json_encode(array('DataID' => self::IF_FORWARD, 'Buffer' => $obj)));
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

    // ------------------------------------------------------------- Zustand

    private function GetBands()
    {
        $b = json_decode((string) $this->GetBuffer('Bands'), true);
        return is_array($b) ? $b : array();
    }

    private function GetGlobal()
    {
        $g = json_decode((string) $this->GetBuffer('Global'), true);
        return is_array($g) ? $g : array();
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
        if ($component === '') {
            return;
        }

        $bands = $this->GetBands();
        $global = $this->GetGlobal();
        $dirty = false;
        $maxBand = 0;

        foreach ($buffer['Changes'] as $c) {
            if (!isset($c['Name']) || (string) (isset($c['Component']) ? $c['Component'] : '') !== $component) {
                continue;
            }
            $name = (string) $c['Name'];
            $val = isset($c['Value']) ? $c['Value'] : null;

            if (preg_match('/^(frequency|gain|q\.factor|type|bypass)\.(\d+)$/', $name, $m)) {
                $feld = str_replace('.', '', $m[1]);   // qfactor
                $n = (int) $m[2];
                if ($n > $maxBand) {
                    $maxBand = $n;
                }
                $bands[$n][$feld] = ($feld === 'bypass') ? (bool) $val : (float) $val;
                $dirty = true;
                continue;
            }
            if ($name === 'master.gain') { $global['master'] = (float) $val; $dirty = true; }
            elseif ($name === 'bypass')  { $global['bypass'] = (bool) $val; $dirty = true; }
            elseif ($name === 'mute')    { $global['mute'] = (bool) $val; $dirty = true; }
        }

        if (!$dirty) {
            return;
        }
        ksort($bands);
        $this->SetBuffer('Bands', json_encode($bands));
        $this->SetBuffer('Global', json_encode($global));

        // Bandzahl aus einer Controlliste gelernt -> Abos nachziehen
        if ((int) $this->ReadPropertyInteger('BandCount') < 1 && $maxBand > (int) $this->GetBuffer('KnownBands')) {
            $this->SetBuffer('KnownBands', (string) $maxBand);
            $this->Subscribe($component, $maxBand);
            $this->SetBuffer('LastSub', $component . '#' . $maxBand);
            $this->BuildBandProfile($maxBand);
        }

        $this->SyncSelected();
        if (isset($global['master'])) { $this->SetValueIfChanged('MasterGain', round($global['master'], 1)); }
        if (isset($global['bypass'])) { $this->SetValueIfChanged('EQBypass', (bool) $global['bypass']); }
        if (isset($global['mute']))   { $this->SetValueIfChanged('Mute', (bool) $global['mute']); }

        $this->Render();
    }

    // Uebernimmt Gain/Q/Bypass des gewaehlten Bandes in die Bedienvariablen.
    private function SyncSelected()
    {
        $sel = (int) $this->GetValue('Band');
        $bands = $this->GetBands();
        if ($sel < 1 || !isset($bands[$sel])) {
            return;
        }
        $b = $bands[$sel];
        if (isset($b['gain']))    { $this->SetValueIfChanged('Gain', round((float) $b['gain'], 1)); }
        if (isset($b['qfactor'])) { $this->SetValueIfChanged('Q', round((float) $b['qfactor'], 2)); }
        if (isset($b['bypass']))  { $this->SetValueIfChanged('Bypass', (bool) $b['bypass']); }
    }

    // Bandauswahl mit sprechenden Namen: "4 · 690 Hz"
    private function BuildBandProfile($count)
    {
        $prof = 'QSysEQ' . $this->InstanceID . 'Band';
        if (!IPS_VariableProfileExists($prof)) {
            IPS_CreateVariableProfile($prof, 1);
        }
        foreach (IPS_GetVariableProfile($prof)['Associations'] as $a) {
            IPS_SetVariableProfileAssociation($prof, $a['Value'], '', '', -1);
        }
        $bands = $this->GetBands();
        for ($n = 1; $n <= $count; $n++) {
            $label = (string) $n;
            if (isset($bands[$n]['frequency'])) {
                $label .= ' · ' . $this->FormatHz((float) $bands[$n]['frequency']);
            }
            IPS_SetVariableProfileAssociation($prof, $n, $label, '', -1);
        }
    }

    private function FormatHz($f)
    {
        if ($f >= 1000) {
            return rtrim(rtrim(number_format($f / 1000, 2, ',', ''), '0'), ',') . ' kHz';
        }
        return round($f) . ' Hz';
    }

    // ------------------------------------------------------------- Schreiben

    private function ComponentSet($control, $value)
    {
        $this->Forward(array('Type' => 'rpc', 'Method' => 'Component.Set', 'Params' => array(
            'Name' => (string) $this->ReadPropertyString('ComponentName'),
            'Controls' => array(array('Name' => $control, 'Value' => $value))
        )));
    }

    public function SetBandGain(float $db)
    {
        $n = (int) $this->GetValue('Band');
        if ($n < 1) { return false; }
        $this->ComponentSet('gain.' . $n, round($db, 2));
        return true;
    }

    public function SetBandQ(float $q)
    {
        $n = (int) $this->GetValue('Band');
        if ($n < 1 || $q <= 0) { return false; }
        $this->ComponentSet('q.factor.' . $n, round($q, 3));
        return true;
    }

    public function SetBandBypass(bool $bypass)
    {
        $n = (int) $this->GetValue('Band');
        if ($n < 1) { return false; }
        $this->ComponentSet('bypass.' . $n, $bypass ? 1 : 0);
        return true;
    }

    public function SelectBand(int $n)
    {
        $this->SetValueIfChanged('Band', $n);
        $this->SyncSelected();
        $this->Render();
        return true;
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Band':       $this->SelectBand((int) $Value); break;
            case 'Gain':       $this->SetBandGain((float) $Value); $this->SetValueIfChanged('Gain', round((float) $Value, 1)); break;
            case 'Q':          $this->SetBandQ((float) $Value); $this->SetValueIfChanged('Q', round((float) $Value, 2)); break;
            case 'Bypass':     $this->SetBandBypass((bool) $Value); $this->SetValueIfChanged('Bypass', (bool) $Value); break;
            case 'MasterGain': $this->ComponentSet('master.gain', round((float) $Value, 2)); $this->SetValueIfChanged('MasterGain', round((float) $Value, 1)); break;
            case 'EQBypass':   $this->ComponentSet('bypass', $Value ? 1 : 0); $this->SetValueIfChanged('EQBypass', (bool) $Value); break;
            case 'Mute':       $this->ComponentSet('mute', $Value ? 1 : 0); $this->SetValueIfChanged('Mute', (bool) $Value); break;
        }
    }

    // ------------------------------------------------------------- Mathematik

    /*
     * Biquad-Koeffizienten nach RBJ Audio EQ Cookbook.
     * Rueckgabe: array(b0,b1,b2,a0,a1,a2)
     */
    public static function Coeffs($type, $f0, $gainDB, $q, $fs)
    {
        if ($q <= 0) { $q = 0.001; }
        if ($f0 <= 0) { $f0 = 1.0; }
        if ($f0 > $fs / 2 * 0.999) { $f0 = $fs / 2 * 0.999; }

        $A = pow(10.0, $gainDB / 40.0);
        $w0 = 2.0 * M_PI * $f0 / $fs;
        $cw = cos($w0);
        $sw = sin($w0);
        $alpha = $sw / (2.0 * $q);
        $sqA = sqrt($A);

        if ($type == self::TYPE_LOWSHELF) {
            $b0 =      $A * (($A + 1) - ($A - 1) * $cw + 2 * $sqA * $alpha);
            $b1 =  2 * $A * (($A - 1) - ($A + 1) * $cw);
            $b2 =      $A * (($A + 1) - ($A - 1) * $cw - 2 * $sqA * $alpha);
            $a0 =           ($A + 1) + ($A - 1) * $cw + 2 * $sqA * $alpha;
            $a1 =     -2 * (($A - 1) + ($A + 1) * $cw);
            $a2 =           ($A + 1) + ($A - 1) * $cw - 2 * $sqA * $alpha;
        } elseif ($type == self::TYPE_HIGHSHELF) {
            $b0 =      $A * (($A + 1) + ($A - 1) * $cw + 2 * $sqA * $alpha);
            $b1 = -2 * $A * (($A - 1) + ($A + 1) * $cw);
            $b2 =      $A * (($A + 1) + ($A - 1) * $cw - 2 * $sqA * $alpha);
            $a0 =           ($A + 1) - ($A - 1) * $cw + 2 * $sqA * $alpha;
            $a1 =      2 * (($A - 1) - ($A + 1) * $cw);
            $a2 =           ($A + 1) - ($A - 1) * $cw - 2 * $sqA * $alpha;
        } else {
            $b0 = 1 + $alpha * $A;
            $b1 = -2 * $cw;
            $b2 = 1 - $alpha * $A;
            $a0 = 1 + $alpha / $A;
            $a1 = -2 * $cw;
            $a2 = 1 - $alpha / $A;
        }
        return array($b0, $b1, $b2, $a0, $a1, $a2);
    }

    // Betragsgang eines Biquads bei Frequenz f in dB.
    public static function MagnitudeDB($co, $f, $fs)
    {
        list($b0, $b1, $b2, $a0, $a1, $a2) = $co;
        $w = 2.0 * M_PI * $f / $fs;
        $cw = cos($w); $sw = sin($w);
        $c2 = cos(2 * $w); $s2 = sin(2 * $w);
        // Zaehler/Nenner als komplexe Summen mit e^{-jw}
        $nr = $b0 + $b1 * $cw + $b2 * $c2;
        $ni = -($b1 * $sw + $b2 * $s2);
        $dr = $a0 + $a1 * $cw + $a2 * $c2;
        $di = -($a1 * $sw + $a2 * $s2);
        $num = sqrt($nr * $nr + $ni * $ni);
        $den = sqrt($dr * $dr + $di * $di);
        if ($den <= 1e-20) { return 0.0; }
        if ($num <= 1e-20) { return -200.0; }
        return 20.0 * log10($num / $den);
    }

    /*
     * Summenkurve: fuer jede Stuetzstelle die dB-Beitraege aller aktiven Baender
     * plus Master-Gain. $bands: [n => [frequency,gain,qfactor,type,bypass]]
     */
    public static function Curve($bands, $master, $freqs, $fs)
    {
        $out = array();
        foreach ($freqs as $f) { $out[] = (float) $master; }
        foreach ($bands as $b) {
            if (!empty($b['bypass'])) { continue; }
            $g = isset($b['gain']) ? (float) $b['gain'] : 0.0;
            if (abs($g) < 0.005) { continue; }
            $co = self::Coeffs(
                isset($b['type']) ? (int) $b['type'] : self::TYPE_PEAK,
                isset($b['frequency']) ? (float) $b['frequency'] : 1000.0,
                $g,
                isset($b['qfactor']) ? (float) $b['qfactor'] : 1.0,
                $fs
            );
            foreach ($freqs as $i => $f) { $out[$i] += self::MagnitudeDB($co, $f, $fs); }
        }
        return $out;
    }

    public static function LogFreqs($from, $to, $count)
    {
        $f = array();
        $lf = log10($from); $lt = log10($to);
        for ($i = 0; $i < $count; $i++) {
            $f[] = pow(10, $lf + ($lt - $lf) * $i / ($count - 1));
        }
        return $f;
    }

    // ------------------------------------------------------------- Zeichnen

    public function Render()
    {
        $vid = @$this->GetIDForIdent('Curve');
        if ($vid === false || $vid === 0) {
            return;
        }
        $this->SetValue('Curve', $this->BuildSVG());
    }

    private function BuildSVG()
    {
        $fs = (int) $this->ReadPropertyInteger('SampleRate');
        if ($fs < 8000) { $fs = 48000; }
        $range = (float) $this->ReadPropertyFloat('RangeDB');
        if ($range < 3) { $range = 18.0; }
        $dark = (bool) $this->ReadPropertyBoolean('DarkMode');

        $bands = $this->GetBands();
        $global = $this->GetGlobal();
        $master = isset($global['master']) ? (float) $global['master'] : 0.0;
        $sel = (int) $this->GetValue('Band');

        $W = 640; $H = 260;
        $L = 44; $R = 12; $T = 12; $B = 26;
        $pw = $W - $L - $R; $ph = $H - $T - $B;
        $fMin = 20.0; $fMax = 20000.0;

        $fg     = $dark ? '#e6e6e6' : '#222222';
        $grid   = $dark ? '#3a3a3a' : '#d8d8d8';
        $gridHi = $dark ? '#4d4d4d' : '#bcbcbc';
        $bg     = $dark ? '#1b1b1b' : '#ffffff';
        $curve  = '#4da3ff';
        $selCol = '#ffb74d';
        $bandCol = $dark ? '#5a6b7a' : '#9fb0bd';

        $x = function ($f) use ($L, $pw, $fMin, $fMax) {
            return $L + $pw * (log10($f) - log10($fMin)) / (log10($fMax) - log10($fMin));
        };
        $y = function ($db) use ($T, $ph, $range) {
            $v = max(-$range, min($range, $db));
            return $T + $ph * (1 - ($v + $range) / (2 * $range));
        };

        $svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $W . ' ' . $H . '" '
              . 'preserveAspectRatio="xMidYMid meet" style="width:100%;height:auto;display:block">';
        $svg .= '<rect x="0" y="0" width="' . $W . '" height="' . $H . '" rx="6" fill="' . $bg . '"/>';

        // Frequenzraster
        foreach (array(20,30,50,100,200,300,500,1000,2000,3000,5000,10000,20000) as $f) {
            $px = round($x($f), 1);
            $stark = in_array($f, array(100, 1000, 10000));
            $svg .= '<line x1="' . $px . '" y1="' . $T . '" x2="' . $px . '" y2="' . ($T + $ph)
                  . '" stroke="' . ($stark ? $gridHi : $grid) . '" stroke-width="1"/>';
            if (in_array($f, array(20,100,1000,10000,20000))) {
                $lbl = $f >= 1000 ? ($f / 1000) . 'k' : (string) $f;
                $svg .= '<text x="' . $px . '" y="' . ($H - 8) . '" fill="' . $fg
                      . '" font-family="sans-serif" font-size="10" text-anchor="middle">' . $lbl . '</text>';
            }
        }
        // dB-Raster
        $step = ($range > 12) ? 6 : 3;
        for ($db = -$range; $db <= $range + 0.01; $db += $step) {
            $py = round($y($db), 1);
            $null = (abs($db) < 0.01);
            $svg .= '<line x1="' . $L . '" y1="' . $py . '" x2="' . ($L + $pw) . '" y2="' . $py
                  . '" stroke="' . ($null ? $gridHi : $grid) . '" stroke-width="' . ($null ? 1.5 : 1) . '"/>';
            $svg .= '<text x="' . ($L - 6) . '" y="' . ($py + 3) . '" fill="' . $fg
                  . '" font-family="sans-serif" font-size="10" text-anchor="end">'
                  . ($db > 0 ? '+' : '') . round($db) . '</text>';
        }

        $freqs = self::LogFreqs($fMin, $fMax, 240);

        // Einzelbaender duenn
        if ((bool) $this->ReadPropertyBoolean('ShowBands')) {
            foreach ($bands as $n => $b) {
                if (!empty($b['bypass'])) { continue; }
                $g = isset($b['gain']) ? (float) $b['gain'] : 0.0;
                if (abs($g) < 0.005) { continue; }
                $einzel = self::Curve(array($b), 0.0, $freqs, $fs);
                $pts = array();
                foreach ($freqs as $i => $f) { $pts[] = round($x($f), 1) . ',' . round($y($einzel[$i]), 1); }
                $ist = ($n == $sel);
                $svg .= '<polyline fill="none" stroke="' . ($ist ? $selCol : $bandCol)
                      . '" stroke-width="' . ($ist ? 1.6 : 1) . '" stroke-opacity="' . ($ist ? 0.95 : 0.55)
                      . '" points="' . implode(' ', $pts) . '"/>';
            }
        }

        // Summenkurve
        $sum = self::Curve($bands, $master, $freqs, $fs);
        $pts = array();
        foreach ($freqs as $i => $f) { $pts[] = round($x($f), 1) . ',' . round($y($sum[$i]), 1); }
        $aus = !empty($global['bypass']) || !empty($global['mute']);
        $svg .= '<polyline fill="none" stroke="' . $curve . '" stroke-width="2.4" stroke-linejoin="round"'
              . ($aus ? ' stroke-opacity="0.35" stroke-dasharray="5 4"' : '')
              . ' points="' . implode(' ', $pts) . '"/>';

        // Marker fuer das gewaehlte Band
        if ($sel > 0 && isset($bands[$sel]['frequency'])) {
            $bf = (float) $bands[$sel]['frequency'];
            if ($bf >= $fMin && $bf <= $fMax) {
                $px = round($x($bf), 1);
                $svg .= '<line x1="' . $px . '" y1="' . $T . '" x2="' . $px . '" y2="' . ($T + $ph)
                      . '" stroke="' . $selCol . '" stroke-width="1" stroke-dasharray="3 3" stroke-opacity="0.8"/>';
            }
        }

        if ($aus) {
            $svg .= '<text x="' . ($L + $pw / 2) . '" y="' . ($T + 18) . '" fill="' . $selCol
                  . '" font-family="sans-serif" font-size="12" text-anchor="middle">'
                  . (!empty($global['mute']) ? 'STUMM' : 'EQ UMGANGEN') . '</text>';
        }
        if (count($bands) === 0) {
            $svg .= '<text x="' . ($L + $pw / 2) . '" y="' . ($T + $ph / 2) . '" fill="' . $fg
                  . '" font-family="sans-serif" font-size="12" text-anchor="middle" opacity="0.7">'
                  . 'warte auf Daten vom Core …</text>';
        }
        $svg .= '</svg>';
        return $svg;
    }
}
