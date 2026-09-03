<?php
/**
 * Live-Integrationstest gegen einen echten Q-SYS Core -- NUR LESEND.
 *
 *   php tests/live_core_test.php [host] [port]     (Default: core-demo 1710)
 *
 * Faehrt die ECHTE QSysCore-Klasse: SendDataToParent geht auf einen echten
 * TCP-Socket, eingehende Bytes gehen unveraendert in ReceiveData. Es wird
 * kein Control geschrieben -- kein Component.Set / Control.Set / Snapshot.Load.
 *
 * Laeuft rund 80 s (Teil D wartet 65 s auf das Keepalive-Verhalten).
 */
define('KL_MESSAGE', 10); define('KL_ERROR', 30); define('KL_WARNING', 20); define('KL_NOTIFY', 40);
define('VARIABLETYPE_BOOLEAN', 0); define('VARIABLETYPE_INTEGER', 1);
define('VARIABLETYPE_FLOAT', 2); define('VARIABLETYPE_STRING', 3);
define('IS_ACTIVE', 102); define('IS_INACTIVE', 104);

$HOST = isset($argv[1]) ? $argv[1] : 'core-demo';
$PORT = isset($argv[2]) ? (int) $argv[2] : 1710;
$GLOBALS['sock'] = null;
$GLOBALS['fanout'] = array();

function IPS_VariableProfileExists($n) { return true; }
function IPS_CreateVariableProfile($n, $t) {}
function IPS_SetVariableProfileAssociation() {}
function IPS_SetVariableProfileText() {} function IPS_SetVariableProfileValues() {}
function IPS_SetVariableProfileDigits() {} function IPS_SetVariableProfileIcon() {}
function IPS_GetInstance($id) {
    // Core 4711 haengt an ClientSocket 9999; die Socket-Instanz ist aktiv (102)
    if ($id == 4711) { return array('ConnectionID' => 9999, 'InstanceStatus' => IS_ACTIVE); }
    if ($id == 9999) { return array('ConnectionID' => 0,    'InstanceStatus' => IS_ACTIVE); }
    return array('ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE);
}
function IPS_GetProperty($id, $p) { if ($p === 'Host') return $GLOBALS['HOST']; if ($p === 'Port') return 1710; if ($p === 'Open') return true; return ''; }
function IPS_SetProperty($id, $p, $v) {} function IPS_ApplyChanges($id) {}
function IPS_InstanceExists($id) { return true; }
function IPS_HasChanges($id) { return false; }
function IPS_SemaphoreEnter($n, $ms) { return true; }
function IPS_SemaphoreLeave($n) { return true; }
function IPS_LogMessage($s, $m) {}
function GetValue($id) { return null; } function SetValue($id, $v) {}
function IPS_Sleep($ms) { usleep($ms * 1000); }
function Sys_Ping($host, $timeout) { return true; }

abstract class IPSModule
{
    public $InstanceID = 12345;
    public $props = array(); public $buffers = array(); public $vars = array();
    public $timers = array(); public $status = 0; public $logs = array();

    public function __construct($id) { $this->InstanceID = $id; }
    public function Create() {} public function ApplyChanges() {} public function Destroy() {}
    public function RequireParent($g) {} public function ConnectParent($g) {}
    public function RegisterPropertyString($n, $v) { if (!isset($this->props[$n])) $this->props[$n] = $v; }
    public function RegisterPropertyInteger($n, $v) { if (!isset($this->props[$n])) $this->props[$n] = $v; }
    public function RegisterPropertyBoolean($n, $v) { if (!isset($this->props[$n])) $this->props[$n] = $v; }
    public function RegisterPropertyFloat($n, $v) { if (!isset($this->props[$n])) $this->props[$n] = $v; }
    public function ReadPropertyString($n) { return isset($this->props[$n]) ? $this->props[$n] : ''; }
    public function ReadPropertyInteger($n) { return isset($this->props[$n]) ? (int) $this->props[$n] : 0; }
    public function ReadPropertyBoolean($n) { return isset($this->props[$n]) ? (bool) $this->props[$n] : false; }
    public function ReadPropertyFloat($n) { return isset($this->props[$n]) ? (float) $this->props[$n] : 0.0; }
    public function SetBuffer($n, $v) { $this->buffers[$n] = $v; }
    public function GetBuffer($n) { return isset($this->buffers[$n]) ? $this->buffers[$n] : ''; }
    public function RegisterVariableBoolean($i, $n, $p = '', $pos = 0) { $this->vars[$i] = false; return 1; }
    public function RegisterVariableInteger($i, $n, $p = '', $pos = 0) { $this->vars[$i] = 0; return 1; }
    public function RegisterVariableFloat($i, $n, $p = '', $pos = 0) { $this->vars[$i] = 0.0; return 1; }
    public function RegisterVariableString($i, $n, $p = '', $pos = 0) { $this->vars[$i] = ''; return 1; }
    public function MaintainVariable($i, $n, $t, $p, $pos, $keep) { if ($keep && !isset($this->vars[$i])) $this->vars[$i] = null; }
    public function GetIDForIdent($i) { if (!array_key_exists($i, $this->vars)) throw new Exception("no ident $i"); return crc32($i); }
    public function SetValue($i, $v) { $this->vars[$i] = $v; }
    public function GetValue($i) { return isset($this->vars[$i]) ? $this->vars[$i] : null; }
    public function EnableAction($i) {} public function DisableAction($i) {}
    public function RegisterTimer($n, $ms, $s) { $this->timers[$n] = $ms; }
    public function SetTimerInterval($n, $ms) { $this->timers[$n] = $ms; }
    public function SetStatus($s) { $this->status = $s; }
    public function GetStatus() { return $this->status; }
    public function LogMessage($m, $t) { $this->logs[] = $m; }
    public function SendDebug($n, $d, $f) {}
    public function RegisterMessage($id, $m) {} public function UnregisterMessage($id, $m) {}
    public function HasActiveParent() { return true; }
    public function GetConfigurationForm() { return '{}'; }

    // --- an den echten Socket ---
    public function SendDataToParent($json)
    {
        $d = json_decode($json, true);
        if (isset($d['Buffer']) && $GLOBALS['sock']) {
            @fwrite($GLOBALS['sock'], $d['Buffer']);
        }
        return '';
    }
    public function SendDataToChildren($json)
    {
        $d = json_decode($json, true);
        if (isset($d['Buffer']['Changes'])) { foreach ($d['Buffer']['Changes'] as $c) { $GLOBALS['fanout'][] = $c; } }
        return '';
    }
}

require __DIR__ . '/../QSys Core/module.php';

// ---------------------------------------------------------------- Testlauf
function ok($b, $t) { echo ($b ? "  ok   " : "  FEHL ") . $t . "\n"; return $b ? 1 : 0; }
$pass = 0; $fail = 0;
function chk($b, $t) { global $pass, $fail; if (ok($b, $t)) $pass++; else $fail++; }

$core = new QSysCore(4711);
$core->Create();

$e = 0; $s = '';
$GLOBALS['sock'] = @stream_socket_client("tcp://$HOST:$PORT", $e, $s, 5.0);
if ($GLOBALS['sock'] === false) { echo "FEHLER: connect -> $s\n"; exit(1); }
stream_set_blocking($GLOBALS['sock'], false);
echo "verbunden mit $HOST:$PORT\n\n";

// Eingehende Bytes 1:1 in ReceiveData
function feed($core, $seconds)
{
    $dl = microtime(true) + $seconds;
    while (microtime(true) < $dl) {
        $c = @fread($GLOBALS['sock'], 65536);
        if ($c !== false && $c !== '') {
            $core->ReceiveData(json_encode(array(
                'DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}',
                'Buffer' => $c
            )));
        } else { usleep(20000); }
    }
}

echo "== A) Begruessung (EngineStatus) durch die echte Klasse ==\n";
feed($core, 2.0);
$design = $core->GetValue('DesignName');
chk($design !== '', "DesignName aus EngineStatus: '" . $design . "'");
chk($core->GetValue('EngineStatus') !== '', "EngineStatus gesetzt: '" . $core->GetValue('EngineStatus') . "'");
$core->RefreshOnlineStatus();  // in Symcon: CheckOnlineStatus-Timer alle 30 s
chk($core->GetValue('OnlineStatus') === true, "OnlineStatus true");

echo "\n== B) StatusGet ueber die echte SendRPC ==\n";
$core->SetValue('DesignName', '');
$core->RequestStatus();
feed($core, 3.0);
chk($core->GetValue('DesignName') === $design, "DesignName nach StatusGet erneut gesetzt");

echo "\n== C) Abo eines echten Controls -> ChangeGroup + Fan-out ==\n";
$GLOBALS['fanout'] = array();
$core->ForwardData(json_encode(array(
    'DataID' => '{747545EE-CA0F-490F-8F42-6D240F6CAEB4}',
    'Buffer' => array('Type' => 'sub', 'Component' => 'Gain_Saal', 'Control' => 'gain')
)));
$core->ForwardData(json_encode(array(
    'DataID' => '{747545EE-CA0F-490F-8F42-6D240F6CAEB4}',
    'Buffer' => array('Type' => 'sub', 'Component' => 'Gain_Saal', 'Control' => 'mute')
)));
feed($core, 6.0);
$got = array();
foreach ($GLOBALS['fanout'] as $c) {
    $k = (isset($c['Component']) ? $c['Component'] : '') . '/' . (isset($c['Name']) ? $c['Name'] : '');
    $got[$k] = $c;
}
chk(count($GLOBALS['fanout']) > 0, "Fan-out erhalten: " . count($GLOBALS['fanout']) . " Changes");
chk(isset($got['Gain_Saal/gain']), "Change fuer Gain_Saal/gain angekommen");
chk(isset($got['Gain_Saal/mute']), "Change fuer Gain_Saal/mute angekommen");
if (isset($got['Gain_Saal/gain'])) {
    $g = $got['Gain_Saal/gain'];
    echo "    gain -> Value=" . json_encode($g['Value']) . " String=\"" . $g['String'] . "\" Position=" . $g['Position'] . "\n";
    chk(isset($g['Value']) && isset($g['Position']), "gain-Change hat Value und Position");
}

echo "\n== D) Keepalive: NoOp und 65 s Stille ==\n";
$core->NoOp();
feed($core, 1.0);
chk(!feof($GLOBALS['sock']), "Socket direkt nach NoOp offen");
echo "  warte 65 s mit NoOp alle 30 s (Core trennt sonst nach 60 s) ...\n";
$t0 = microtime(true); $next = 30;
while (microtime(true) - $t0 < 65) {
    feed($core, 1.0);
    if (microtime(true) - $t0 >= $next) { $core->NoOp(); echo "    NoOp bei " . round(microtime(true) - $t0) . " s\n"; $next += 30; }
    if (feof($GLOBALS['sock'])) break;
}
chk(!feof($GLOBALS['sock']), "Socket nach 65 s mit NoOp noch offen");
$core->RefreshOnlineStatus();
chk($core->GetValue('OnlineStatus') === true, "OnlineStatus weiterhin true");

echo "\n== E) Abbau ==\n";
$core->ForwardData(json_encode(array(
    'DataID' => '{747545EE-CA0F-490F-8F42-6D240F6CAEB4}',
    'Buffer' => array('Type' => 'unsub', 'Component' => 'Gain_Saal', 'Control' => 'gain')
)));
feed($core, 2.0);
chk(!feof($GLOBALS['sock']), "Socket nach unsub offen");

fclose($GLOBALS['sock']);
echo "\n== Ergebnis ==\n  bestanden: $pass\n  fehler:    $fail\n";
exit($fail > 0 ? 1 : 0);
