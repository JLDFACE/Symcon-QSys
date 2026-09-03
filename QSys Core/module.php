<?php

/*
 * QSys Core - IP-Symcon Device-Modul (Typ 2)
 *
 * Spricht mit einem Q-SYS Core ueber QRC (Q-SYS Remote Control):
 * JSON-RPC 2.0 ueber TCP 1710, jede Nachricht \0-terminiert.
 *
 * Aufgaben:
 *  - Framing (\0 + JSON) in beide Richtungen
 *  - optionaler Logon, StatusGet, NoOp-Keepalive (Core trennt nach 60 s Stille)
 *  - ChangeGroup-Verwaltung: Kinder abonnieren Controls, der Core pusht
 *    Aenderungen ueber ChangeGroup.AutoPoll -> Fan-out an die Kinder
 *  - stabile TCP-/Reconnect-Mechanik (aus dem Bose-Device uebernommen)
 *
 * SymBox-sicher: kein declare(strict_types), keine PHP8-only-Konstrukte.
 */

class QSysCore extends IPSModule
{
    // Datenfluss-GUIDs
    const IF_SOCKET_TX = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}'; // Core -> ClientSocket
    const IF_SOCKET_RX = '{018EF6B5-AB94-40C6-AA53-46943E824ACF}'; // ClientSocket -> Core
    const IF_TO_CHILD  = '{A322AA34-4023-435D-B023-1BD80BAB9E22}'; // Core -> Kinder (Fan-out)
    const IF_FROM_CHILD = '{747545EE-CA0F-490F-8F42-6D240F6CAEB4}'; // Kinder -> Core (Forward)

    const CHANGEGROUP_ID = 'symcon';

    // ---------------------------------------------------------------------
    // Lifecycle
    // ---------------------------------------------------------------------

    public function Create()
    {
        parent::Create();

        $this->RequireParent('{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}'); // Client Socket

        if (!IPS_VariableProfileExists('QSysOnline')) {
            IPS_CreateVariableProfile('QSysOnline', 0);
        }
        IPS_SetVariableProfileAssociation('QSysOnline', false, 'Offline', '', 0xff0000);
        IPS_SetVariableProfileAssociation('QSysOnline', true, 'Online', '', 0x00ff00);

        // Properties
        $this->RegisterPropertyString('User', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('PollRate', 1);        // AutoPoll-Rate in Sekunden
        $this->RegisterPropertyBoolean('ShowCoreStatus', true);

        // Variablen
        $this->RegisterVariableBoolean('OnlineStatus', 'Status', 'QSysOnline', 0);
        $this->RegisterVariableString('DesignName', 'Design', '', 1);
        $this->RegisterVariableString('EngineStatus', 'Engine-Status', '', 2);
        $this->RegisterVariableInteger('LastOnline', 'Zuletzt online', '~UnixTimestamp', 3);

        // Timer
        $this->RegisterTimer('NoOp', 30000, 'QSYS_NoOp(' . $this->InstanceID . ');');
        $this->RegisterTimer('PollStatus', 30000, 'QSYS_RequestStatus(' . $this->InstanceID . ');');
        $this->RegisterTimer('CheckOnlineStatus', 30000, 'QSYS_RefreshOnlineStatus(' . $this->InstanceID . ');');
        $this->RegisterTimer('FlushPending', 1000, 'QSYS_FlushPending(' . $this->InstanceID . ');');

        // Buffer
        $this->SetBuffer('incomingData', '');
        $this->SetBuffer('Subs', '[]');
        $this->SetBuffer('CGApplied', '0');
        $this->SetBuffer('pingTimeouts', '0');
        $this->SetBuffer('LastDeviceResponse', '0');
        $this->SetBuffer('ReconnectBackoffUntil', '0');
        $this->SetBuffer('ClosedByPing', '0');
        $this->SetBuffer('LastConnectionID', '0');
        $this->SetBuffer('PortInitialized', '0');
        $this->SetBuffer('RpcId', '0');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Port-Default 1710 an der ClientSocket setzen (nur beim ersten Mal / neuer Verbindung)
        $connId = $this->GetConnectionID();
        if ($connId > 0) {
            $lastConnId = (int) $this->GetBuffer('LastConnectionID');
            if ($lastConnId !== $connId) {
                $this->SetBuffer('LastConnectionID', (string) $connId);
                $this->SetBuffer('PortInitialized', '0');
            }
            if ((int) $this->GetBuffer('PortInitialized') === 0) {
                $port = @IPS_GetProperty($connId, 'Port');
                if (empty($port) || (int) $port === 0) {
                    IPS_SetProperty($connId, 'Port', 1710);
                    IPS_ApplyChanges($connId);
                }
                $this->SetBuffer('PortInitialized', '1');
            }
        }

        $this->SetBuffer('CGApplied', '0');
        $this->SetBuffer('incomingData', '');

        $this->SetTimerInterval('NoOp', 30000);
        $this->SetTimerInterval('PollStatus', 30000);
        $this->SetTimerInterval('CheckOnlineStatus', 30000);
        $this->SetTimerInterval('FlushPending', 1000);
    }

    public function GetConnectionID()
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        if (!is_array($inst)) {
            return 0;
        }
        return (int) $inst['ConnectionID'];
    }

    // ---------------------------------------------------------------------
    // kleine Helfer
    // ---------------------------------------------------------------------

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

    private function SocketState()
    {
        $connId = $this->GetConnectionID();
        if ($connId <= 0) {
            return 0;
        }
        $inst = @IPS_GetInstance($connId);
        if (!is_array($inst)) {
            return 0;
        }
        return (int) $inst['InstanceStatus'];
    }

    private function NextId()
    {
        $id = (int) $this->GetBuffer('RpcId') + 1;
        if ($id > 1000000) {
            $id = 1;
        }
        $this->SetBuffer('RpcId', (string) $id);
        return $id;
    }

    // ---------------------------------------------------------------------
    // Senden
    // ---------------------------------------------------------------------

    // Baut eine JSON-RPC-Nachricht und schickt sie \0-terminiert an den Socket.
    public function SendRPC($method, $params, $withId = true)
    {
        $msg = array('jsonrpc' => '2.0', 'method' => $method);
        if ($withId) {
            $msg['id'] = $this->NextId();
        }
        // params darf auch 0 (StatusGet) oder ein leeres Objekt sein
        $msg['params'] = $params;

        return $this->SendRaw(json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    // Sendet eine fertige JSON-Zeichenkette \0-terminiert; Verbindungszustand wie beim Bose-Device.
    private function SendRaw($json)
    {
        $connId = $this->GetConnectionID();
        if ($connId <= 0) {
            return false;
        }
        $state = $this->SocketState();

        if ($state != 102) {
            // Nicht verbunden: Reconnect anstossen (mit Backoff), aber nichts senden.
            if ($state == 104) {
                $backoffUntil = (int) $this->GetBuffer('ReconnectBackoffUntil');
                if (time() >= $backoffUntil) {
                    IPS_SetProperty($connId, 'Open', true);
                    IPS_ApplyChanges($connId);
                    $this->SetBuffer('ReconnectBackoffUntil', time() + 2);
                }
            }
            return false;
        }

        if (!IPS_SemaphoreEnter('QSYS_Send_' . $this->InstanceID, 2000)) {
            $this->LogMessage('SendRaw semaphore timeout.', KL_WARNING);
            return false;
        }
        try {
            $this->SendDataToParent(json_encode(array(
                'DataID' => self::IF_SOCKET_TX,
                'Buffer' => $json . "\0"
            )));
        } finally {
            IPS_SemaphoreLeave('QSYS_Send_' . $this->InstanceID);
        }
        return true;
    }

    // Timer-Targets ----------------------------------------------------------

    public function NoOp()
    {
        if ($this->SocketState() == 102) {
            $this->SendRPC('NoOp', new stdClass(), false);
        }
    }

    public function RequestStatus()
    {
        if ($this->SocketState() == 102) {
            $this->SendRPC('StatusGet', 0);
        }
    }

    // ---------------------------------------------------------------------
    // ChangeGroup / Abos
    // ---------------------------------------------------------------------

    private function GetSubs()
    {
        $raw = (string) $this->GetBuffer('Subs');
        $list = json_decode($raw, true);
        return is_array($list) ? $list : array();
    }

    private function SaveSubs($list)
    {
        $this->SetBuffer('Subs', json_encode(array_values($list)));
    }

    private function SubKey($component, $control)
    {
        return ((string) $component) . "\x1f" . ((string) $control);
    }

    private function AddSub($component, $control)
    {
        $list = $this->GetSubs();
        $key = $this->SubKey($component, $control);
        foreach ($list as $row) {
            if ($this->SubKey($row['Component'], $row['Control']) === $key) {
                return; // schon vorhanden
            }
        }
        $list[] = array('Component' => (string) $component, 'Control' => (string) $control);
        $this->SaveSubs($list);
        $this->SetBuffer('CGApplied', '0'); // neu anwenden
    }

    private function RemoveSub($component, $control)
    {
        $list = $this->GetSubs();
        $key = $this->SubKey($component, $control);
        $new = array();
        foreach ($list as $row) {
            if ($this->SubKey($row['Component'], $row['Control']) !== $key) {
                $new[] = $row;
            }
        }
        if (count($new) !== count($list)) {
            $this->SaveSubs($new);
        }
    }

    // Baut die ChangeGroup am Core neu auf und aktiviert AutoPoll.
    private function ApplyChangeGroup()
    {
        if ($this->SocketState() != 102) {
            return;
        }

        // Optionaler Logon zuerst
        $user = (string) $this->ReadPropertyString('User');
        if ($user !== '') {
            $this->SendRPC('Logon', array(
                'User' => $user,
                'Password' => (string) $this->ReadPropertyString('Password')
            ));
        }

        // Change Group neu aufsetzen
        $this->SendRPC('ChangeGroup.Destroy', array('Id' => self::CHANGEGROUP_ID), false);

        // Abos nach Komponente gruppieren
        $byComponent = array();
        $named = array();
        foreach ($this->GetSubs() as $row) {
            $c = (string) $row['Component'];
            $ctrl = (string) $row['Control'];
            if ($c === '') {
                $named[] = $ctrl;
            } else {
                if (!isset($byComponent[$c])) {
                    $byComponent[$c] = array();
                }
                $byComponent[$c][] = array('Name' => $ctrl);
            }
        }

        foreach ($byComponent as $c => $controls) {
            $this->SendRPC('ChangeGroup.AddComponentControl', array(
                'Id' => self::CHANGEGROUP_ID,
                'Component' => array('Name' => $c, 'Controls' => $controls)
            ));
        }
        if (count($named) > 0) {
            $this->SendRPC('ChangeGroup.AddControl', array(
                'Id' => self::CHANGEGROUP_ID,
                'Controls' => $named
            ));
        }

        // AutoPoll aktivieren (Core pusht Aenderungen) + einmal sofort pollen (Erst-Sync).
        // Nur wenn mindestens ein Abo besteht: die ChangeGroup entsteht am Core erst
        // durch AddComponentControl/AddControl. Ohne Abo wuerde der Core AutoPoll und
        // Poll mit "Change group does not exist" (Code 6) beantworten.
        if (count($byComponent) > 0 || count($named) > 0) {
            $rate = (int) $this->ReadPropertyInteger('PollRate');
            if ($rate < 1) {
                $rate = 1;
            }
            $this->SendRPC('ChangeGroup.AutoPoll', array('Id' => self::CHANGEGROUP_ID, 'Rate' => $rate));
            $this->SendRPC('ChangeGroup.Poll', array('Id' => self::CHANGEGROUP_ID));
        }

        $this->SendRPC('StatusGet', 0);

        $this->SetBuffer('CGApplied', '1');
    }

    public function FlushPending()
    {
        if ($this->SocketState() == 102) {
            if ((int) $this->GetBuffer('CGApplied') === 0) {
                $this->ApplyChangeGroup();
            }
        } else {
            $this->SetBuffer('CGApplied', '0');
        }
    }

    // ---------------------------------------------------------------------
    // Empfang vom Socket
    // ---------------------------------------------------------------------

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!isset($data['DataID']) || $data['DataID'] !== self::IF_SOCKET_RX) {
            return;
        }

        $this->SetBuffer('LastDeviceResponse', (string) time());

        $temp = $this->GetBuffer('incomingData') . $data['Buffer'];

        // an \0 splitten; letztes (unvollstaendiges) Fragment bleibt im Puffer
        $parts = explode("\0", $temp);
        $rest = array_pop($parts);
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $this->DispatchMessage($part);
        }
        $this->SetBuffer('incomingData', $rest);

        // Nach Empfang ggf. ChangeGroup nachziehen
        $this->FlushPending();
    }

    private function DispatchMessage($json)
    {
        $msg = json_decode($json, true);
        if (!is_array($msg)) {
            return;
        }

        // Push-Notification (kein id-Feld noetig)
        if (isset($msg['method'])) {
            $method = $msg['method'];
            $params = isset($msg['params']) ? $msg['params'] : array();

            if ($method === 'ChangeGroup.Poll') {
                if (isset($params['Changes']) && is_array($params['Changes'])) {
                    $this->FanOutChanges($params['Changes']);
                }
            } elseif ($method === 'EngineStatus') {
                $this->ApplyCoreStatus($params);
            }
            return;
        }

        // Antwort auf einen Request
        if (array_key_exists('result', $msg)) {
            $result = $msg['result'];

            if (is_array($result)) {
                // StatusGet-Antwort erkennen
                if (isset($result['DesignName']) || isset($result['Platform']) || isset($result['State'])) {
                    $this->ApplyCoreStatus($result);
                    return;
                }
                // Antwort auf ein explizites ChangeGroup.Poll -> {Id, Changes}
                // Traegt den Erst-Sync nach (Neu)Aufbau der ChangeGroup, z. B. nach
                // einem Reconnect. Ohne diesen Zweig blieben die Kinder dort auf
                // alten Werten stehen, bis sich am Core zufaellig etwas aendert.
                if (isset($result['Changes']) && is_array($result['Changes'])) {
                    $this->FanOutChanges($result['Changes']);
                    return;
                }
                // Component.Get-Antwort erkennen -> als Changes fan-out
                if (isset($result['Name']) && isset($result['Controls']) && is_array($result['Controls'])) {
                    $changes = array();
                    foreach ($result['Controls'] as $ctrl) {
                        if (!isset($ctrl['Name'])) {
                            continue;
                        }
                        $ctrl['Component'] = $result['Name'];
                        $changes[] = $ctrl;
                    }
                    if (count($changes) > 0) {
                        $this->FanOutChanges($changes);
                    }
                    return;
                }
            }
            return;
        }

        if (isset($msg['error'])) {
            $err = $msg['error'];
            $txt = is_array($err) ? json_encode($err) : (string) $err;
            $this->LogMessage('QRC error: ' . $txt, KL_WARNING);
        }
    }

    private function ApplyCoreStatus($p)
    {
        if (!is_array($p)) {
            return;
        }
        if (isset($p['DesignName'])) {
            $this->SetValueIfChanged('DesignName', (string) $p['DesignName']);
        }
        $statusText = '';
        if (isset($p['Status'])) {
            if (is_array($p['Status']) && isset($p['Status']['String'])) {
                $statusText = (string) $p['Status']['String'];
            } elseif (!is_array($p['Status'])) {
                $statusText = (string) $p['Status'];
            }
        }
        if ($statusText === '' && isset($p['State'])) {
            $statusText = (string) $p['State'];
        }
        if ($statusText !== '') {
            $this->SetValueIfChanged('EngineStatus', $statusText);
        }
    }

    // Normalisiert Changes und schickt sie an alle Kinder.
    private function FanOutChanges($changes)
    {
        $out = array();
        foreach ($changes as $c) {
            if (!is_array($c) || !isset($c['Name'])) {
                continue;
            }
            $out[] = array(
                'Component' => isset($c['Component']) ? (string) $c['Component'] : '',
                'Name' => (string) $c['Name'],
                'Value' => isset($c['Value']) ? $c['Value'] : null,
                'String' => isset($c['String']) ? (string) $c['String'] : '',
                'Position' => isset($c['Position']) ? $c['Position'] : null
            );
        }
        if (count($out) === 0) {
            return;
        }
        $this->SendDataToChildren(json_encode(array(
            'DataID' => self::IF_TO_CHILD,
            'Buffer' => array('Changes' => $out)
        )));
    }

    // ---------------------------------------------------------------------
    // Empfang von Kindern (Forward)
    // ---------------------------------------------------------------------

    public function ForwardData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!isset($data['DataID']) || $data['DataID'] !== self::IF_FROM_CHILD) {
            return '';
        }

        $req = isset($data['Buffer']) ? $data['Buffer'] : null;
        if (is_string($req)) {
            $req = json_decode($req, true);
        }
        if (!is_array($req) || !isset($req['Type'])) {
            return '';
        }

        switch ($req['Type']) {
            case 'sub':
                $this->AddSub(
                    isset($req['Component']) ? $req['Component'] : '',
                    isset($req['Control']) ? $req['Control'] : ''
                );
                $this->FlushPending();
                // Erst-Sync fuer dieses Kind sofort holen
                $this->RequestGet(
                    isset($req['Component']) ? $req['Component'] : '',
                    array(isset($req['Control']) ? $req['Control'] : '')
                );
                break;

            case 'unsub':
                $this->RemoveSub(
                    isset($req['Component']) ? $req['Component'] : '',
                    isset($req['Control']) ? $req['Control'] : ''
                );
                break;

            case 'get':
                $this->RequestGet(
                    isset($req['Component']) ? $req['Component'] : '',
                    isset($req['Controls']) ? $req['Controls'] : array()
                );
                break;

            case 'rpc':
                if (isset($req['Method'])) {
                    $this->SendRPC($req['Method'], isset($req['Params']) ? $req['Params'] : array());
                }
                break;
        }
        return '';
    }

    // Holt den aktuellen Wert (Erst-Sync) fuer Component+Controls bzw. Named Controls.
    private function RequestGet($component, $controls)
    {
        if ($this->SocketState() != 102) {
            return;
        }
        $component = (string) $component;
        $ctrlNames = array();
        foreach ((array) $controls as $c) {
            if ((string) $c !== '') {
                $ctrlNames[] = (string) $c;
            }
        }
        if (count($ctrlNames) === 0) {
            return;
        }
        if ($component === '') {
            $this->SendRPC('Control.Get', $ctrlNames);
        } else {
            $list = array();
            foreach ($ctrlNames as $n) {
                $list[] = array('Name' => $n);
            }
            $this->SendRPC('Component.Get', array('Name' => $component, 'Controls' => $list));
        }
    }

    // ---------------------------------------------------------------------
    // Online-Status / Reconnect (aus Bose-Device uebernommen, gekuerzt)
    // ---------------------------------------------------------------------

    public function RefreshOnlineStatus()
    {
        $pingTimeouts = (int) $this->GetBuffer('pingTimeouts');
        $connId = $this->GetConnectionID();
        $host = ($connId > 0) ? (string) @IPS_GetProperty($connId, 'Host') : '';
        $state = $this->SocketState();
        $pingOk = false;

        if (strlen($host) > 0) {
            $pingOk = Sys_Ping($host, 1000);
            $pingTimeouts = $pingOk ? 0 : ($pingTimeouts + 1);
        } else {
            $pingTimeouts = 4;
        }

        $lastResponse = (int) $this->GetBuffer('LastDeviceResponse');
        $recentResponse = ($lastResponse > 0 && $lastResponse >= time() - 60);
        $isOnline = $pingOk || $state == 102 || $recentResponse;

        $this->SetValueIfChanged('OnlineStatus', $isOnline);
        if ($isOnline) {
            $this->SetValueIfChanged('LastOnline', time());
        }
        $this->SetBuffer('pingTimeouts', (string) $pingTimeouts);

        // Ping ok, aber Socket inaktiv -> Reconnect
        if ($pingOk && $state == 104) {
            $backoffUntil = (int) $this->GetBuffer('ReconnectBackoffUntil');
            if (time() >= $backoffUntil) {
                IPS_SetProperty($connId, 'Open', true);
                IPS_ApplyChanges($connId);
                $this->SetBuffer('ReconnectBackoffUntil', time() + 10);
            }
        }

        // dauerhaft offline -> Socket genau einmal schliessen
        if (!$isOnline && $pingTimeouts >= 4) {
            if ((int) $this->GetBuffer('ClosedByPing') === 0 && $connId > 0) {
                IPS_SetProperty($connId, 'Open', false);
                IPS_ApplyChanges($connId);
                $this->SetBuffer('ClosedByPing', '1');
                $this->SetBuffer('ReconnectBackoffUntil', time() + 15);
            }
        } else {
            $this->SetBuffer('ClosedByPing', '0');
        }
    }

    // ---------------------------------------------------------------------
    // Oeffentliche Debug-Hilfe (Konsole)
    // ---------------------------------------------------------------------

    // Sendet einen beliebigen QRC-Aufruf; params als JSON-Text (z.B. '0' oder '{"Id":"symcon"}').
    public function RawRequest(string $method, string $paramsJson)
    {
        $params = json_decode($paramsJson, true);
        if ($params === null && trim($paramsJson) !== 'null') {
            $params = $paramsJson; // roher String als Parameter
        }
        return $this->SendRPC($method, $params);
    }
}
