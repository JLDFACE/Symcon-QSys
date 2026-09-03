<?php
/*
 * Lokaler Test-Harness fuer QSys Core (kein Symcon noetig, nur `php`).
 *
 *   php tests/qrc_test.php
 *
 * Stubbt die von QSysCore genutzte Symcon-API, laedt die echte Modulklasse und
 * treibt sie mit realen, \0-terminierten QRC-Frames. Geprueft werden:
 *   - Framing/Buffering (Teilframes, mehrere Frames pro Chunk)
 *   - Dispatch: ChangeGroup.Poll -> Fan-out, StatusGet-Result -> Core-Variablen,
 *     Component.Get-Result -> Fan-out
 *   - Normalisierung der Changes (Component/Name/Value/String/Position)
 *   - Abo -> ChangeGroup.AddComponentControl + AutoPoll auf dem Socket
 *   - Forward "rpc" -> korrekter, \0-terminierter Socket-Write
 */

error_reporting(E_ALL & ~E_DEPRECATED);

// ---- Symcon-Konstanten ----
define('VARIABLETYPE_BOOLEAN', 0);
define('VARIABLETYPE_INTEGER', 1);
define('VARIABLETYPE_FLOAT', 2);
define('VARIABLETYPE_STRING', 3);
define('KL_MESSAGE', 10102);
define('KL_WARNING', 10202);
define('KL_ERROR', 10302);
define('VM_UPDATE', 10603);

// ---- globaler Zustand der Stubs ----
$GLOBALS['__vars'] = array();       // vid => value
$GLOBALS['__socketState'] = 102;    // InstanceStatus des Sockets
$GLOBALS['__profiles'] = array();
$GLOBALS['__vidSeq'] = 1000;

const CORE_INSTANCE_ID = 42;
const SOCKET_INSTANCE_ID = 7;

// ---- Symcon-Funktions-Stubs ----
function IPS_GetInstance($id)
{
    if ($id == CORE_INSTANCE_ID) {
        return array('ConnectionID' => SOCKET_INSTANCE_ID, 'InstanceStatus' => 102);
    }
    if ($id == SOCKET_INSTANCE_ID) {
        return array('ConnectionID' => 0, 'InstanceStatus' => $GLOBALS['__socketState']);
    }
    return array('ConnectionID' => 0, 'InstanceStatus' => 0);
}
function IPS_GetProperty($id, $name)
{
    if ($name === 'Host') return '192.0.2.10';
    if ($name === 'Port') return 1710;
    return '';
}
function IPS_SetProperty($id, $name, $value) { return true; }
function IPS_ApplyChanges($id) { return true; }
function IPS_VariableProfileExists($n) { return isset($GLOBALS['__profiles'][$n]); }
function IPS_CreateVariableProfile($n, $t) { $GLOBALS['__profiles'][$n] = array('Associations' => array()); }
function IPS_GetVariableProfile($n)
{
    if (!isset($GLOBALS['__profiles'][$n])) { return array('Associations' => array()); }
    $out = array();
    foreach ($GLOBALS['__profiles'][$n]['Associations'] as $v => $name) {
        $out[] = array('Value' => $v, 'Name' => $name);
    }
    return array('Associations' => $out);
}
// Symcon entfernt eine Assoziation, wenn der Name leer ist -- der Router nutzt das zum Leeren.
function IPS_SetVariableProfileAssociation($n, $v, $a, $b, $c)
{
    if (!isset($GLOBALS['__profiles'][$n])) { $GLOBALS['__profiles'][$n] = array('Associations' => array()); }
    if ($a === '') { unset($GLOBALS['__profiles'][$n]['Associations'][(int)$v]); }
    else { $GLOBALS['__profiles'][$n]['Associations'][(int)$v] = $a; }
}
function IPS_SetVariableProfileText($n, $a, $b) {}
function IPS_SetVariableProfileValues($n, $a, $b, $c) {}
function IPS_SetVariableProfileDigits($n, $a) {}
function IPS_SetVariableProfileIcon($n, $a) {}
function IPS_SemaphoreEnter($n, $t) { return true; }
function IPS_SemaphoreLeave($n) { return true; }
function IPS_VariableExists($id) { return isset($GLOBALS['__vars'][$id]); }
function IPS_GetName($id) { return ''; }
function IPS_SetName($id, $n) {}
function Sys_Ping($host, $t) { return true; }
function GetValue($vid) { return isset($GLOBALS['__vars'][$vid]) ? $GLOBALS['__vars'][$vid] : null; }
function SetValue($vid, $value) { $GLOBALS['__vars'][$vid] = $value; }

// ---- IPSModule-Basisklasse (Stub) ----
class IPSModule
{
    public $InstanceID;
    protected $props = array();
    protected $buffers = array();
    protected $identToVid = array();
    public $sentToParent = array();
    public $sentToChildren = array();

    public function __construct($id) { $this->InstanceID = $id; }
    public function Create() {}
    public function ApplyChanges() {}
    public function Destroy() {}
    public function RequireParent($g) {}
    public function ConnectParent($g) {}
    public function GetConnectionID() { $i = IPS_GetInstance($this->InstanceID); return (int)$i['ConnectionID']; }

    public function RegisterPropertyString($n, $v) { $this->props[$n] = $v; }
    public function RegisterPropertyInteger($n, $v) { $this->props[$n] = $v; }
    public function RegisterPropertyFloat($n, $v) { $this->props[$n] = $v; }
    public function RegisterPropertyBoolean($n, $v) { $this->props[$n] = $v; }
    public function ReadPropertyString($n) { return isset($this->props[$n]) ? (string)$this->props[$n] : ''; }
    public function ReadPropertyInteger($n) { return isset($this->props[$n]) ? (int)$this->props[$n] : 0; }
    public function ReadPropertyFloat($n) { return isset($this->props[$n]) ? (float)$this->props[$n] : 0.0; }
    public function ReadPropertyBoolean($n) { return isset($this->props[$n]) ? (bool)$this->props[$n] : false; }
    public function SetProperty($n, $v) { $this->props[$n] = $v; } // Test-Helfer

    private function ensureVar($ident, $type)
    {
        if (!isset($this->identToVid[$ident])) {
            $vid = ++$GLOBALS['__vidSeq'];
            $this->identToVid[$ident] = $vid;
            $default = ($type === VARIABLETYPE_STRING) ? '' : (($type === VARIABLETYPE_BOOLEAN) ? false : (($type === VARIABLETYPE_FLOAT) ? 0.0 : 0));
            $GLOBALS['__vars'][$vid] = $default;
        }
    }
    public function RegisterVariableBoolean($ident, $n, $p, $pos) { $this->ensureVar($ident, VARIABLETYPE_BOOLEAN); }
    public function RegisterVariableInteger($ident, $n, $p, $pos) { $this->ensureVar($ident, VARIABLETYPE_INTEGER); }
    public function RegisterVariableFloat($ident, $n, $p, $pos) { $this->ensureVar($ident, VARIABLETYPE_FLOAT); }
    public function RegisterVariableString($ident, $n, $p, $pos) { $this->ensureVar($ident, VARIABLETYPE_STRING); }
    public function MaintainVariable($ident, $n, $t, $p, $pos, $keep)
    {
        if ($keep) { $this->ensureVar($ident, $t); }
        elseif (isset($this->identToVid[$ident])) { unset($GLOBALS['__vars'][$this->identToVid[$ident]]); unset($this->identToVid[$ident]); }
    }
    public function EnableAction($ident) {}
    public function GetIDForIdent($ident) { return isset($this->identToVid[$ident]) ? $this->identToVid[$ident] : false; }
    public function SetValue($ident, $value) { if (isset($this->identToVid[$ident])) { $GLOBALS['__vars'][$this->identToVid[$ident]] = $value; } }
    public function GetValue($ident) { return isset($this->identToVid[$ident]) ? $GLOBALS['__vars'][$this->identToVid[$ident]] : null; }

    public function RegisterTimer($n, $i, $s) {}
    public function SetTimerInterval($n, $i) {}
    public function RegisterMessage($a, $b) {}
    public function UnregisterMessage($a, $b) {}
    public function GetMessageList() { return array(); }

    public function SetBuffer($n, $v) { $this->buffers[$n] = (string)$v; }
    public function GetBuffer($n) { return isset($this->buffers[$n]) ? $this->buffers[$n] : ''; }

    public function SendDataToParent($json) { $this->sentToParent[] = json_decode($json, true); return ''; }
    public function SendDataToChildren($json) { $this->sentToChildren[] = json_decode($json, true); return ''; }
    public function LogMessage($m, $l) {}

    // Test-Helfer
    public function TestGetVar($ident) { return $this->GetValue($ident); }
}

require __DIR__ . '/../QSys Core/module.php';
require __DIR__ . '/../QSys Router/module.php';

// ---- winziges Test-Framework ----
$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
function check($cond, $msg)
{
    if ($cond) { $GLOBALS['__pass']++; echo "  ok   $msg\n"; }
    else { $GLOBALS['__fail']++; echo "  FAIL $msg\n"; }
}
function frame($obj) { return json_encode($obj) . "\0"; }
function rx(QSysCore $core, $buffer)
{
    $core->ReceiveData(json_encode(array('DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}', 'Buffer' => $buffer)));
}

function newCore()
{
    $core = new QSysCore(CORE_INSTANCE_ID);
    $core->Create();
    $core->ApplyChanges();
    $core->sentToParent = array();
    $core->sentToChildren = array();
    return $core;
}

echo "== QSys Core ==\n";

// --- Test 1: Abo -> AutoPoll-Aufbau auf dem Socket ---
$core = newCore();
$core->ForwardData(json_encode(array(
    'DataID' => '{747545EE-CA0F-490F-8F42-6D240F6CAEB4}',
    'Buffer' => array('Type' => 'sub', 'Component' => 'MyGain', 'Control' => 'gain')
)));
$methods = array();
foreach ($core->sentToParent as $p) {
    $m = json_decode(rtrim($p['Buffer'], "\0"), true);
    if (isset($m['method'])) { $methods[] = $m['method']; }
    // jede Socket-Nachricht muss \0-terminiert sein
    check(substr($p['Buffer'], -1) === "\0", 'Socket-Write ist \\0-terminiert (' . (isset($m['method']) ? $m['method'] : '?') . ')');
}
check(in_array('ChangeGroup.AddComponentControl', $methods), 'Abo erzeugt ChangeGroup.AddComponentControl');
check(in_array('ChangeGroup.AutoPoll', $methods), 'Abo aktiviert ChangeGroup.AutoPoll');

// --- Test 2: ChangeGroup.Poll -> Fan-out an Kinder, mit Teilframe-Buffering ---
$core = newCore();
$poll = frame(array(
    'jsonrpc' => '2.0',
    'method' => 'ChangeGroup.Poll',
    'params' => array('Id' => 'symcon', 'Changes' => array(
        array('Component' => 'MyGain', 'Name' => 'gain', 'Value' => -6.0, 'String' => '-6.0dB', 'Position' => 0.5),
        array('Name' => 'namedControl1', 'Value' => 1.0, 'String' => 'on')
    ))
));
// in zwei Chunks aufteilen (Teilframe muss gepuffert werden)
$cut = intdiv(strlen($poll), 2);
rx($core, substr($poll, 0, $cut));
check(count($core->sentToChildren) === 0, 'Teilframe erzeugt noch keinen Fan-out');
rx($core, substr($poll, $cut));
check(count($core->sentToChildren) === 1, 'Vollstaendiger Frame erzeugt genau einen Fan-out');

$changes = $core->sentToChildren[0]['Buffer']['Changes'];
check(count($changes) === 2, 'Zwei Changes weitergereicht');
check($changes[0]['Component'] === 'MyGain' && $changes[0]['Name'] === 'gain', 'Change 0: Component+Name korrekt');
check(abs($changes[0]['Value'] - (-6.0)) < 1e-9 && abs($changes[0]['Position'] - 0.5) < 1e-9, 'Change 0: Value+Position korrekt');
check($changes[1]['Component'] === '' && $changes[1]['Name'] === 'namedControl1', 'Change 1: Named Control ohne Component normalisiert');

// --- Test 3: StatusGet-Result -> Core-Variablen ---
$core = newCore();
$status = frame(array(
    'jsonrpc' => '2.0',
    'id' => 1,
    'result' => array(
        'Platform' => 'Core 110f',
        'State' => 'Active',
        'DesignName' => 'MeinDesign',
        'DesignCode' => 'abc123',
        'Status' => array('Code' => 0, 'String' => 'OK')
    )
));
rx($core, $status);
check($core->TestGetVar('DesignName') === 'MeinDesign', 'StatusGet setzt DesignName');
check($core->TestGetVar('EngineStatus') === 'OK', 'StatusGet setzt EngineStatus aus Status.String');

// --- Test 4: Component.Get-Result -> Fan-out ---
$core = newCore();
$cget = frame(array(
    'jsonrpc' => '2.0',
    'id' => 2,
    'result' => array(
        'Name' => 'MyGain',
        'Controls' => array(
            array('Name' => 'gain', 'Value' => -3.0, 'String' => '-3.0dB', 'Position' => 0.7),
            array('Name' => 'mute', 'Value' => 0.0, 'String' => 'false')
        )
    )
));
rx($core, $cget);
check(count($core->sentToChildren) === 1, 'Component.Get erzeugt Fan-out');
$cg = $core->sentToChildren[0]['Buffer']['Changes'];
check(count($cg) === 2 && $cg[0]['Component'] === 'MyGain', 'Component.Get: Controls mit Component-Namen versehen');

// --- Test 5: zwei Frames in einem Chunk ---
$core = newCore();
rx($core, $poll . $status);
check(count($core->sentToChildren) === 1 && $core->TestGetVar('DesignName') === 'MeinDesign', 'Zwei Frames in einem Chunk werden beide verarbeitet');

// --- Test 6: Forward "rpc" -> Socket-Write mit \0 ---
$core = newCore();
$core->ForwardData(json_encode(array(
    'DataID' => '{747545EE-CA0F-490F-8F42-6D240F6CAEB4}',
    'Buffer' => array('Type' => 'rpc', 'Method' => 'Component.Set', 'Params' => array(
        'Name' => 'MyGain', 'Controls' => array(array('Name' => 'gain', 'Value' => -12, 'Ramp' => 2.0))
    ))
)));
$found = false;
foreach ($core->sentToParent as $p) {
    $m = json_decode(rtrim($p['Buffer'], "\0"), true);
    if (isset($m['method']) && $m['method'] === 'Component.Set') {
        $found = true;
        check(substr($p['Buffer'], -1) === "\0", 'Component.Set ist \\0-terminiert');
        check(isset($m['params']['Controls'][0]['Ramp']) && abs((float)$m['params']['Controls'][0]['Ramp'] - 2.0) < 1e-9, 'Ramp wird durchgereicht');
    }
}
check($found, 'Forward "rpc" sendet Component.Set an den Socket');

// --- Test 7: ohne Abo kein AutoPoll/Poll (Core wuerde mit Code 6 antworten) ---
// Am Core entsteht die ChangeGroup erst durch AddComponentControl/AddControl.
// Verifiziert am echten Core 24f: AutoPoll auf eine leere Gruppe -> Fehler
// {"code":6,"message":"Change group 'symcon' does not exist"}.
$core = newCore();
$core->SetBuffer('CGApplied', '0');   // erzwingt den (Neu-)Aufbau
$core->FlushPending();
$methods = array();
foreach ($core->sentToParent as $p) {
    $m = json_decode(rtrim($p['Buffer'], "\0"), true);
    if (isset($m['method'])) { $methods[] = $m['method']; }
}
check(!in_array('ChangeGroup.AutoPoll', $methods), 'Ohne Abo kein ChangeGroup.AutoPoll');
check(!in_array('ChangeGroup.Poll', $methods), 'Ohne Abo kein ChangeGroup.Poll');

// --- Test 8: Antwort auf ChangeGroup.Poll ({Id,Changes}) erzeugt Fan-out ---
// Das ist der Erst-Sync nach (Neu-)Aufbau der ChangeGroup, z. B. nach einem
// Reconnect. Format 1:1 vom echten Core uebernommen.
$core = newCore();
rx($core, frame(array(
    'jsonrpc' => '2.0',
    'id' => 6,
    'result' => array('Id' => 'symcon', 'Changes' => array(
        array('Component' => 'Gain_Saal', 'Name' => 'gain', 'String' => '0dB', 'Value' => 0.0, 'Position' => 1.0)
    ))
)));
check(count($core->sentToChildren) === 1, 'ChangeGroup.Poll-Antwort erzeugt Fan-out');
if (count($core->sentToChildren) === 1) {
    $ch = $core->sentToChildren[0]['Buffer']['Changes'];
    check(count($ch) === 1 && $ch[0]['Component'] === 'Gain_Saal' && $ch[0]['Name'] === 'gain',
        'Erst-Sync-Change korrekt normalisiert');
}


echo "\n== QSys Router ==\n";

// Hilfsfunktionen: Router-Instanz bauen, Fan-out an sie schicken
function newRouter($props)
{
    $r = new QSysRouter(77);
    $r->Create();
    foreach ($props as $k => $v) { $r->SetProperty($k, $v); }
    $r->ApplyChanges();
    $r->sentToParent = array();
    return $r;
}
function fanout(QSysRouter $r, $changes)
{
    $r->ReceiveData(json_encode(array(
        'DataID' => '{A322AA34-4023-435D-B023-1BD80BAB9E22}',
        'Buffer' => array('Changes' => $changes)
    )));
}
function lastSet(QSysRouter $r)
{
    for ($i = count($r->sentToParent) - 1; $i >= 0; $i--) {
        $b = $r->sentToParent[$i]['Buffer'];
        if (isset($b['Type']) && $b['Type'] === 'rpc') { return $b; }
    }
    return null;
}

// --- Betriebsart Router: select.N traegt den Integer direkt ---
$r = newRouter(array('Mode' => 'router', 'ComponentName' => 'Router_Saal', 'SelectControl' => 'select.1'));
$subs = array();
foreach ($r->sentToParent as $p) { if (isset($p['Buffer']['Type'])) { $subs[] = $p['Buffer']; } }
$r2 = new QSysRouter(78); $r2->Create();
$r2->SetProperty('Mode', 'router'); $r2->SetProperty('ComponentName', 'Router_Saal');
$r2->SetProperty('SelectControl', 'select.1'); $r2->ApplyChanges();
$subbed = null;
foreach ($r2->sentToParent as $p) { if (isset($p['Buffer']['Type']) && $p['Buffer']['Type'] === 'sub') { $subbed = $p['Buffer']['Control']; } }
check($subbed === 'select.1', 'Router-Modus abonniert select.1');

fanout($r, array(array('Component' => 'Router_Saal', 'Name' => 'select.1', 'Value' => 3, 'String' => '3', 'Position' => 0)));
check($r->TestGetVar('Source') === 3, 'Router-Modus: Push setzt Quelle auf 3');

$r->SetSource(2);
$m = lastSet($r);
check($m !== null && $m['Method'] === 'Component.Set'
      && $m['Params']['Controls'][0]['Name'] === 'select.1'
      && (int) $m['Params']['Controls'][0]['Value'] === 2,
      'Router-Modus: SetSource(2) schreibt select.1 = 2');

// --- Betriebsart Selector: Index 0-basiert im JSON, Quelle 1-basiert ---
$choices = array();
foreach (array('Bluetooth', 'Ipad', '-', 'Folge Saal', 'Folge Teestube', '5ch Surround in') as $i => $t) {
    $choices[] = json_encode(array('Text' => $t, 'TextColor' => '', 'Icon' => '', 'IconColor' => '', 'Data' => '', 'Index' => $i));
}
$sel = newRouter(array('Mode' => 'selector', 'ComponentName' => 'Selector_Saal', 'AutoSources' => true));

$s2 = new QSysRouter(79); $s2->Create();
$s2->SetProperty('Mode', 'selector'); $s2->SetProperty('ComponentName', 'Selector_Saal');
$s2->ApplyChanges();
$subbed = null;
foreach ($s2->sentToParent as $p) { if (isset($p['Buffer']['Type']) && $p['Buffer']['Type'] === 'sub') { $subbed = $p['Buffer']['Control']; } }
check($subbed === 'selector', 'Selector-Modus abonniert das Sammel-Control "selector"');

fanout($sel, array(array(
    'Component' => 'Selector_Saal', 'Name' => 'selector',
    'Value' => 0, 'Position' => 0,
    'String' => json_encode(array('Text' => 'Ipad', 'Index' => 1)),
    'Choices' => $choices
)));
check($sel->TestGetVar('Source') === 2, 'Selector-Modus: Index 1 wird zu Quelle 2 (1-basiert)');

$assoc = IPS_GetVariableProfile('QSysRouter77')['Associations'];
$byVal = array();
foreach ($assoc as $a) { $byVal[$a['Value']] = $a['Name']; }
check(count($assoc) === 6, 'Selector-Modus: Profil aus Choices hat 6 Eintraege');
check(isset($byVal[1]) && $byVal[1] === 'Bluetooth', 'Selector-Modus: Quelle 1 heisst Bluetooth');
check(isset($byVal[6]) && $byVal[6] === '5ch Surround in', 'Selector-Modus: Quelle 6 heisst 5ch Surround in');

$sel->SetSource(1);
$m = lastSet($sel);
check($m !== null && $m['Method'] === 'Component.Set'
      && $m['Params']['Controls'][0]['Name'] === 'selector.0'
      && (int) $m['Params']['Controls'][0]['Value'] === 1,
      'Selector-Modus: SetSource(1) schreibt selector.0 = 1 (ein Schreibvorgang, exklusiv)');

// --- Core reicht Choices durch den Fan-out weiter ---
$core = newCore();
$pollSel = frame(array('jsonrpc' => '2.0', 'method' => 'ChangeGroup.Poll', 'params' => array(
    'Id' => 'symcon',
    'Changes' => array(array(
        'Component' => 'Selector_Saal', 'Name' => 'selector',
        'String' => json_encode(array('Text' => 'Ipad', 'Index' => 1)),
        'Value' => 0, 'Position' => 0, 'Choices' => $choices
    ))
)));
rx($core, $pollSel);
$ch = $core->sentToChildren[0]['Buffer']['Changes'][0];
check(isset($ch['Choices']) && count($ch['Choices']) === 6, 'Core reicht Choices im Fan-out weiter');

// --- ohne Choices bleibt der Change schlank ---
$core = newCore();
rx($core, $poll);
$ch = $core->sentToChildren[0]['Buffer']['Changes'][0];
check(!array_key_exists('Choices', $ch), 'Ohne Auswahlliste kein Choices-Feld im Change');

// ---- Ergebnis ----
echo "\n== Ergebnis ==\n";
echo "  bestanden: {$GLOBALS['__pass']}\n";
echo "  fehler:    {$GLOBALS['__fail']}\n";
exit($GLOBALS['__fail'] > 0 ? 1 : 0);
