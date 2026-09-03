<?php
/**
 * SCHREIBTEST gegen einen echten Q-SYS Core.  ==>  VERAENDERT WERTE AM CORE <==
 *
 *   php tests/live_write_test.php [host] [port]      (Default: core-demo 1710)
 *
 * Treibt die ECHTEN Kindmodule (Gain / Control / Router / Trigger) ueber den
 * echten Core an einem echten Socket und prueft, ob die Aenderung per Push
 * zurueckkommt. Getestet werden Component.Set mit Value, Position und Ramp,
 * der generische Control-Pfad, die Router-Umschaltung und ein Trigger.
 *
 * Sicherheitsnetz: alle Ausgangswerte werden vorher eingelesen und am Ende
 * per register_shutdown_function() wieder gesetzt -- auch wenn ein Test
 * fehlschlaegt oder das Skript abbricht. Snapshots bleiben unangetastet,
 * Snapshot.Load/Save waere nicht wiederherstellbar.
 *
 * ACHTUNG: Die unten gesetzten Komponentennamen stammen aus dem Testdesign
 * SKUZ_FACE_190826. Vor dem Lauf gegen ein anderes Design anpassen --
 * waehrend des Tests ist der Pegel kurzzeitig hoerbar veraendert.
 */
define('KL_MESSAGE', 10); define('KL_ERROR', 30); define('KL_WARNING', 20); define('KL_NOTIFY', 40);
define('VARIABLETYPE_BOOLEAN', 0); define('VARIABLETYPE_INTEGER', 1);
define('VARIABLETYPE_FLOAT', 2); define('VARIABLETYPE_STRING', 3);
define('IS_ACTIVE', 102); define('IS_INACTIVE', 104);
define('VM_UPDATE', 10603);

$HOST = isset($argv[1]) ? $argv[1] : 'core-demo';
$PORT = isset($argv[2]) ? (int) $argv[2] : 1710;
$GLOBALS['sock'] = null;
$GLOBALS['children'] = array();
$GLOBALS['HOST'] = $HOST;

function IPS_VariableProfileExists($n) { return true; }
function IPS_CreateVariableProfile($n, $t) {}
function IPS_SetVariableProfileAssociation() {}
function IPS_SetVariableProfileText() {} function IPS_SetVariableProfileValues() {}
function IPS_SetVariableProfileDigits() {} function IPS_SetVariableProfileIcon() {}
function IPS_GetVariableProfile($n) { return array('Associations' => array()); }
function IPS_GetName($id) { return ''; } function IPS_SetName($id, $n) {}
function IPS_GetInstance($id) {
    if ($id == 4711) { return array('ConnectionID' => 9999, 'InstanceStatus' => IS_ACTIVE); }
    return array('ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE);
}
function IPS_GetProperty($id, $p) { if ($p === 'Host') return $GLOBALS['HOST']; if ($p === 'Port') return 1710; return ''; }
function IPS_SetProperty($id, $p, $v) {} function IPS_ApplyChanges($id) {}
function IPS_InstanceExists($id) { return true; } function IPS_HasChanges($id) { return false; }
function IPS_SemaphoreEnter($n, $ms) { return true; } function IPS_SemaphoreLeave($n) { return true; }
function IPS_LogMessage($s, $m) {} function IPS_Sleep($ms) { usleep($ms * 1000); }
function Sys_Ping($h, $t) { return true; }
$GLOBALS['varstore'] = array();
function GetValue($id) { return isset($GLOBALS['varstore'][$id]) ? $GLOBALS['varstore'][$id] : null; }
function SetValue($id, $v) { $GLOBALS['varstore'][$id] = $v; }

abstract class IPSModule
{
    public $InstanceID = 0;
    public $props = array(); public $buffers = array(); public $vars = array();
    public $timers = array(); public $status = 0; public $logs = array();
    public function __construct($id) { $this->InstanceID = $id; }
    public function Create() {} public function ApplyChanges() {} public function Destroy() {}
    public function RequireParent($g) {} public function ConnectParent($g) {}
    public function RegisterPropertyString($n, $v) { if (!array_key_exists($n, $this->props)) $this->props[$n] = $v; }
    public function RegisterPropertyInteger($n, $v) { if (!array_key_exists($n, $this->props)) $this->props[$n] = $v; }
    public function RegisterPropertyBoolean($n, $v) { if (!array_key_exists($n, $this->props)) $this->props[$n] = $v; }
    public function RegisterPropertyFloat($n, $v) { if (!array_key_exists($n, $this->props)) $this->props[$n] = $v; }
    public function ReadPropertyString($n) { return isset($this->props[$n]) ? $this->props[$n] : ''; }
    public function ReadPropertyInteger($n) { return isset($this->props[$n]) ? (int) $this->props[$n] : 0; }
    public function ReadPropertyBoolean($n) { return isset($this->props[$n]) ? (bool) $this->props[$n] : false; }
    public function ReadPropertyFloat($n) { return isset($this->props[$n]) ? (float) $this->props[$n] : 0.0; }
    public function SetBuffer($n, $v) { $this->buffers[$n] = $v; }
    public function GetBuffer($n) { return isset($this->buffers[$n]) ? $this->buffers[$n] : ''; }
    private function reg($i, $d) { if (!array_key_exists($i, $this->vars)) { $this->vars[$i] = $d; SetValue($this->vid($i), $d); } return $this->vid($i); }
    public function vid($i) { return crc32($this->InstanceID . ':' . $i) & 0x7fffffff; }
    public function RegisterVariableBoolean($i, $n, $p = '', $pos = 0) { return $this->reg($i, false); }
    public function RegisterVariableInteger($i, $n, $p = '', $pos = 0) { return $this->reg($i, 0); }
    public function RegisterVariableFloat($i, $n, $p = '', $pos = 0) { return $this->reg($i, 0.0); }
    public function RegisterVariableString($i, $n, $p = '', $pos = 0) { return $this->reg($i, ''); }
    public function MaintainVariable($i, $n, $t, $p, $pos, $keep)
    {
        if ($keep) { $d = ($t == VARIABLETYPE_BOOLEAN) ? false : (($t == VARIABLETYPE_STRING) ? '' : (($t == VARIABLETYPE_INTEGER) ? 0 : 0.0)); $this->reg($i, $d); }
        else { unset($this->vars[$i]); }
    }
    public function GetIDForIdent($i) { if (!array_key_exists($i, $this->vars)) return false; return $this->vid($i); }
    public function SetValue($i, $v) { $this->vars[$i] = $v; SetValue($this->vid($i), $v); }
    public function GetValue($i) { return isset($this->vars[$i]) ? $this->vars[$i] : null; }
    public function EnableAction($i) {} public function DisableAction($i) {}
    public function RegisterTimer($n, $ms, $s) { $this->timers[$n] = $ms; }
    public function SetTimerInterval($n, $ms) { $this->timers[$n] = $ms; }
    public function SetStatus($s) { $this->status = $s; }
    public function GetStatus() { return $this->status; }
    public function LogMessage($m, $t) { $this->logs[] = $m; }
    public function SendDebug($n, $d, $f) {}
    public function RegisterMessage($id, $m) {} public function UnregisterMessage($id, $m) {}
    public function GetMessageList() { return array(); }
    public function HasActiveParent() { return true; }
    public function GetConfigurationForm() { return '{}'; }

    public function SendDataToParent($json)
    {
        $d = json_decode($json, true);
        if (isset($d['Buffer']) && is_string($d['Buffer']) && $GLOBALS['sock']) {
            @fwrite($GLOBALS['sock'], $d['Buffer']);   // Core -> Socket
            return '';
        }
        if (isset($GLOBALS['core']) && $this !== $GLOBALS['core']) {
            $GLOBALS['core']->ForwardData($json);      // Kind -> Core
        }
        return '';
    }
    public function SendDataToChildren($json)
    {
        foreach ($GLOBALS['children'] as $c) { if (method_exists($c, 'ReceiveData')) { $c->ReceiveData($json); } }
        return '';
    }
}

$BASE = __DIR__ . '/../';
require $BASE . 'QSys Core/module.php';
require $BASE . 'QSys Gain/module.php';
require $BASE . 'QSys Router/module.php';
require $BASE . 'QSys Control/module.php';
require $BASE . 'QSys Trigger/module.php';

// ---------------------------------------------------------------- Aufbau
$pass = 0; $fail = 0;
function chk($b, $t) { global $pass, $fail; if ($b) { $pass++; echo "  ok   $t\n"; } else { $fail++; echo "  FEHL $t\n"; } }
function info($t) { echo "       $t\n"; }

$core = new QSysCore(4711); $GLOBALS['core'] = $core; $core->Create();
$e = 0; $s = '';
$GLOBALS['sock'] = @stream_socket_client("tcp://$HOST:$PORT", $e, $s, 5.0);
if ($GLOBALS['sock'] === false) { echo "FEHLER: connect -> $s\n"; exit(1); }
stream_set_blocking($GLOBALS['sock'], false);
echo "verbunden mit $HOST:$PORT\n";

function pump($sec) {
    $dl = microtime(true) + $sec;
    while (microtime(true) < $dl) {
        $c = @fread($GLOBALS['sock'], 65536);
        if ($c !== false && $c !== '') {
            $GLOBALS['core']->ReceiveData(json_encode(array(
                'DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}', 'Buffer' => $c)));
        } else { usleep(20000); }
    }
}
pump(1.5);
$core->RefreshOnlineStatus();

// Kindmodule: echte Klassen, an den echten Core gehaengt
$gain = new QSysGain(5001);
$gain->props = array('ComponentName' => 'Gain_Saal', 'GainControl' => 'gain', 'MuteControl' => 'mute',
                     'MinDB' => -100.0, 'MaxDB' => 0.0, 'Ramp' => 0.0);
$ctrl = new QSysControl(5002);
$ctrl->props = array('ComponentName' => 'gain_Garderobe_Direkt', 'ControlName' => 'gain',
                     'ValueType' => 'float', 'Ramp' => 0.0, 'Writable' => true, 'ShowString' => true);
$router = new QSysRouter(5003);
$router->props = array('ComponentName' => 'Router_Saal', 'SelectControl' => 'select.1',
                       'Sources' => json_encode(array(array('Value'=>1,'Label'=>'1'), array('Value'=>2,'Label'=>'2'))));
$trig = new QSysTrigger(5004);
$trig->props = array('Triggers' => json_encode(array(
    array('Label' => 'Log leeren', 'Component' => 'TC_Routing_Saal', 'Control' => 'log.clear'))));

foreach (array($gain, $ctrl, $router, $trig) as $c) { $GLOBALS['children'][] = $c; $c->Create(); }
foreach (array($gain, $ctrl, $router, $trig) as $c) { $c->ApplyChanges(); }
pump(4.0);

// ------------------------------------------------- Ausgangswerte sichern
$orig = array(
    'gain_db'   => $gain->GetValue('Level'),
    'gain_pct'  => $gain->GetValue('LevelPercent'),
    'mute'      => $gain->GetValue('Mute'),
    'ctrl'      => $ctrl->GetValue('Value'),
    'source'    => $router->GetValue('Source'),
);
echo "\n== Ausgangswerte (per Push eingelesen) ==\n";
foreach ($orig as $k => $v) { info(sprintf("%-9s = %s", $k, var_export($v, true))); }

$restored = false;
function restore() {
    global $gain, $ctrl, $router, $orig, $restored;
    if ($restored) { return; }
    $restored = true;
    echo "\n== Zuruecksetzen auf die Ausgangswerte ==\n";
    $gain->props['Ramp'] = 0.0;
    if ($orig['gain_db'] !== null) { $gain->SetLevel((float) $orig['gain_db']); }
    if ($orig['mute'] !== null)    { $gain->SetMute((bool) $orig['mute']); }
    if ($orig['ctrl'] !== null)    { $ctrl->RequestAction('Value', (float) $orig['ctrl']); }
    if ($orig['source'] !== null && (int) $orig['source'] > 0) { $router->SetSource((int) $orig['source']); }
    pump(3.0);
    info("gain_db  jetzt " . var_export($GLOBALS['gain']->GetValue('Level'), true) . " (Soll " . var_export($orig['gain_db'], true) . ")");
    info("mute     jetzt " . var_export($GLOBALS['gain']->GetValue('Mute'), true) . " (Soll " . var_export($orig['mute'], true) . ")");
    info("ctrl     jetzt " . var_export($GLOBALS['ctrl']->GetValue('Value'), true) . " (Soll " . var_export($orig['ctrl'], true) . ")");
    info("source   jetzt " . var_export($GLOBALS['router']->GetValue('Source'), true) . " (Soll " . var_export($orig['source'], true) . ")");
}
register_shutdown_function('restore');

// ------------------------------------------------------------- Schreibtests
echo "\n== 1) QSys Gain: SetLevel (Component.Set Value) ==\n";
$gain->SetLevel(-20.0);
pump(2.5);
chk(abs((float) $gain->GetValue('Level') - (-20.0)) < 0.51, 'Level ist nach SetLevel(-20) bei ' . $gain->GetValue('Level') . ' dB');

echo "\n== 2) QSys Gain: SetMute ==\n";
$want = !((bool) $orig['mute']);
$gain->SetMute($want);
pump(2.0);
chk($gain->GetValue('Mute') === $want, 'Mute ist ' . var_export($gain->GetValue('Mute'), true));
$gain->SetMute((bool) $orig['mute']);
pump(1.5);
chk($gain->GetValue('Mute') === (bool) $orig['mute'], 'Mute wieder auf Ausgangswert');

echo "\n== 3) QSys Gain: SetLevelPercent (Component.Set Position) ==\n";
$gain->SetLevelPercent(50);
pump(2.5);
$p = (int) $gain->GetValue('LevelPercent');
chk(abs($p - 50) <= 2, 'LevelPercent ist ' . $p . ' (Soll ~50)');
info('zugehoeriger dB-Wert: ' . var_export($gain->GetValue('Level'), true));

echo "\n== 4) QSys Gain: Ramp (fluessiger Fade) ==\n";
$gain->SetLevel(-60.0); pump(2.0);
$gain->props['Ramp'] = 2.0;
$gain->SetLevel(-10.0);
pump(0.6);
$mid = (float) $gain->GetValue('Level');
pump(3.0);
$end = (float) $gain->GetValue('Level');
info(sprintf('nach 0.6 s: %.1f dB, nach 3.6 s: %.1f dB', $mid, $end));
chk($mid > -60.0 && $mid < -10.0, 'Wert liegt waehrend der Rampe dazwischen (Ramp wirkt)');
chk(abs($end - (-10.0)) < 0.51, 'Rampe erreicht den Zielwert');
$gain->props['Ramp'] = 0.0;

echo "\n== 5) QSys Control (generisch) auf gain_Garderobe_Direkt/gain ==\n";
if ($orig['ctrl'] === null) {
    info('kein Ausgangswert eingelesen -- uebersprungen');
} else {
    $target = ((float) $orig['ctrl']) - 6.0;
    $ctrl->RequestAction('Value', $target);
    pump(2.5);
    chk(abs((float) $ctrl->GetValue('Value') - $target) < 0.51,
        'Value ist ' . $ctrl->GetValue('Value') . ' (Soll ' . $target . ')');
    info('String vom Core: ' . var_export($ctrl->GetValue('Text'), true));
}

echo "\n== 6) QSys Router: SetSource (select.1) ==\n";
$cur = (int) $orig['source'];
info('aktuelle Quelle: ' . $cur);
$new = ($cur === 1) ? 2 : 1;
$router->SetSource($new);
pump(2.5);
chk((int) $router->GetValue('Source') === $new, 'Source ist ' . $router->GetValue('Source') . ' (Soll ' . $new . ')');

echo "\n== 7) QSys Trigger: Fire (momentanes Control) ==\n";
$before = count($core->logs);
$ok = $trig->Fire(0);
pump(2.0);
chk($ok === true, 'Fire(0) angenommen');
chk(count($core->logs) === $before, 'Core meldet keinen QRC-Fehler auf den Trigger');
if (count($core->logs) > $before) { foreach (array_slice($core->logs, $before) as $l) { info('LOG: ' . $l); } }

restore();

echo "\n== Ergebnis ==\n  bestanden: $pass\n  fehler:    $fail\n";
fclose($GLOBALS['sock']);
exit($fail > 0 ? 1 : 0);
