<?php

/*
 * QSys Configurator - liest das laufende Q-SYS-Design aus (Typ 4)
 *
 * Als Kind eines QSys Core angelegt. Liest Host/Port aus der Client-Socket des
 * Cores und oeffnet in GetConfigurationForm einen eigenen, kurzlebigen,
 * SYNCHRONEN QRC-Socket (stoert die Live-Verbindung des Cores nicht):
 *   - Component.GetComponents  -> Named Components
 *   - Component.GetControls    -> deren Controls
 * und bietet pro Zeile das Anlegen der passenden Instanz an.
 *
 * SymBox-sicher: kein declare(strict_types), keine PHP8-only-Konstrukte.
 */

class QSysConfigurator extends IPSModule
{
    const CORE_GUID       = '{2D09E300-AB56-4A99-8734-580E05BDC5E0}';
    const GUID_CONTROL    = '{67300491-374F-431D-BBC1-4F8DF4456401}';
    const GUID_GAIN       = '{D7F2AAA3-0102-4A08-834C-91E7758F8744}';
    const GUID_ROUTER     = '{206CCE6E-A80E-4032-AB12-7C060823FD2D}';
    const GUID_SNAPSHOT   = '{BD1426DA-330A-46B7-BA7D-F6CE5C7E627F}';

    public function Create()
    {
        parent::Create();
        $this->ConnectParent(self::CORE_GUID);
        $this->RegisterPropertyInteger('TimeoutMs', 2000);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm()
    {
        $core = $this->GetCoreInstanceID();
        $elements = array(
            array(
                'type' => 'Label',
                'caption' => 'Liest die Named Components des verbundenen Q-SYS Core aus. Voraussetzung: die Komponenten sind im Designer auf "Script Access = All/External" gesetzt.'
            ),
            array(
                'type' => 'NumberSpinner',
                'name' => 'TimeoutMs',
                'caption' => 'Zeitlimit je Abfrage (ms)',
                'minimum' => 500,
                'maximum' => 10000
            )
        );

        $values = $this->DiscoverValues();

        $elements[] = array(
            'type' => 'Configurator',
            'name' => 'Components',
            'caption' => 'Q-SYS Komponenten & Controls',
            'rowCount' => 18,
            'add' => false,
            'delete' => false,
            'columns' => array(
                array('caption' => 'Name', 'name' => 'name', 'width' => 'auto'),
                array('caption' => 'Typ', 'name' => 'ctype', 'width' => '200px'),
                array('caption' => 'Wert', 'name' => 'valuestr', 'width' => '160px')
            ),
            'values' => $values
        );

        $form = array(
            'elements' => $elements,
            'actions' => array(),
            'status' => array()
        );

        return json_encode($form);
    }

    // -----------------------------------------------------------------
    // Discovery
    // -----------------------------------------------------------------

    private function GetCoreInstanceID()
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        if (!is_array($inst)) {
            return 0;
        }
        return (int) $inst['ConnectionID'];
    }

    private function ResolveEndpoint()
    {
        $coreId = $this->GetCoreInstanceID();
        if ($coreId <= 0) {
            return null;
        }
        $coreInst = @IPS_GetInstance($coreId);
        if (!is_array($coreInst)) {
            return null;
        }
        $socketId = (int) $coreInst['ConnectionID'];
        if ($socketId <= 0) {
            return null;
        }
        $host = (string) @IPS_GetProperty($socketId, 'Host');
        $port = (int) @IPS_GetProperty($socketId, 'Port');
        if ($host === '') {
            return null;
        }
        if ($port <= 0) {
            $port = 1710;
        }
        return array(
            'host' => $host,
            'port' => $port,
            'user' => (string) @IPS_GetProperty($coreId, 'User'),
            'pass' => (string) @IPS_GetProperty($coreId, 'Password')
        );
    }

    private function DiscoverValues()
    {
        $ep = $this->ResolveEndpoint();
        if ($ep === null) {
            return array($this->InfoRow('Configurator zuerst unter einen QSys Core hängen und dort Host/Port setzen.'));
        }

        $client = $this->QrcConnect($ep);
        if ($client === false) {
            return array($this->InfoRow('Keine Verbindung zu ' . $ep['host'] . ':' . $ep['port'] . ' (Core erreichbar? Port 1710 offen?).'));
        }

        $components = $this->QrcRequest($client, 'Component.GetComponents', '');
        if (!is_array($components)) {
            @fclose($client);
            return array($this->InfoRow('Keine Komponentenliste erhalten. Sind Named Components mit Script Access angelegt?'));
        }

        $values = array();
        foreach ($components as $comp) {
            if (!isset($comp['Name'])) {
                continue;
            }
            $cName = (string) $comp['Name'];
            $cType = isset($comp['Type']) ? (string) $comp['Type'] : '';
            $compRowId = crc32('C:' . $cName) & 0x7fffffff;

            $values[] = $this->ComponentRow($compRowId, $cName, $cType);

            // Controls dieser Komponente holen
            $ctrls = $this->QrcRequest($client, 'Component.GetControls', array('Name' => $cName));
            $list = (is_array($ctrls) && isset($ctrls['Controls']) && is_array($ctrls['Controls'])) ? $ctrls['Controls'] : array();
            foreach ($list as $ctrl) {
                if (!isset($ctrl['Name'])) {
                    continue;
                }
                $values[] = $this->ControlRow($compRowId, $cName, $ctrl);
            }
        }
        @fclose($client);

        if (count($values) === 0) {
            return array($this->InfoRow('Es wurden keine Named Components gefunden.'));
        }
        return $values;
    }

    private function InfoRow($text)
    {
        return array(
            'id' => 1,
            'parent' => 0,
            'name' => $text,
            'ctype' => '',
            'valuestr' => '',
            'instanceID' => 0
        );
    }

    // Zeile fuer eine Komponente inkl. passendem "create" (Gain/Router/Snapshot/generisch)
    private function ComponentRow($rowId, $name, $type)
    {
        $create = array();
        $lt = strtolower($type . ' ' . $name);

        if (strpos($lt, 'gain') !== false) {
            $create[] = array('moduleID' => self::GUID_GAIN, 'name' => $name, 'configuration' => array('ComponentName' => $name));
        } elseif (strpos($lt, 'router') !== false) {
            $create[] = array('moduleID' => self::GUID_ROUTER, 'name' => $name, 'configuration' => array('ComponentName' => $name));
        } elseif (strpos($lt, 'snapshot') !== false) {
            $create[] = array('moduleID' => self::GUID_SNAPSHOT, 'name' => $name, 'configuration' => array('BankName' => $name));
        }

        $row = array(
            'id' => $rowId,
            'parent' => 0,
            'name' => $name,
            'ctype' => $type,
            'valuestr' => '',
            'expanded' => false,
            'instanceID' => $this->FindComponentInstance($name)
        );
        if (count($create) > 0) {
            $row['create'] = $create;
        }
        return $row;
    }

    // Zeile fuer ein Control -> immer als generisches QSys Control anlegbar
    private function ControlRow($parentId, $componentName, $ctrl)
    {
        $ctrlName = (string) $ctrl['Name'];
        $ctrlType = isset($ctrl['Type']) ? (string) $ctrl['Type'] : '';
        $valueStr = '';
        if (isset($ctrl['String']) && $ctrl['String'] !== '') {
            $valueStr = (string) $ctrl['String'];
        } elseif (isset($ctrl['Value'])) {
            $valueStr = (string) $ctrl['Value'];
        }

        $valueType = $this->GuessValueType($ctrlType, isset($ctrl['Value']) ? $ctrl['Value'] : null);
        $rowId = crc32('X:' . $componentName . '/' . $ctrlName) & 0x7fffffff;

        return array(
            'id' => $rowId,
            'parent' => $parentId,
            'name' => $ctrlName,
            'ctype' => $ctrlType,
            'valuestr' => $valueStr,
            'instanceID' => $this->FindControlInstance($componentName, $ctrlName),
            'create' => array(
                array(
                    'moduleID' => self::GUID_CONTROL,
                    'name' => $componentName . ' – ' . $ctrlName,
                    'configuration' => array(
                        'ComponentName' => $componentName,
                        'ControlName' => $ctrlName,
                        'ValueType' => $valueType
                    )
                )
            )
        );
    }

    private function GuessValueType($ctrlType, $value)
    {
        $t = strtolower($ctrlType);
        if (strpos($t, 'bool') !== false || strpos($t, 'trigger') !== false || strpos($t, 'toggle') !== false) {
            return 'bool';
        }
        if (strpos($t, 'text') !== false || strpos($t, 'string') !== false) {
            return 'string';
        }
        if (is_string($value) && !is_numeric($value)) {
            return 'string';
        }
        return 'float';
    }

    private function FindComponentInstance($componentName)
    {
        foreach (array(self::GUID_GAIN, self::GUID_ROUTER, self::GUID_SNAPSHOT) as $guid) {
            $ids = @IPS_GetInstanceListByModuleID($guid);
            if (!is_array($ids)) {
                continue;
            }
            foreach ($ids as $id) {
                $prop = ($guid === self::GUID_SNAPSHOT) ? 'BankName' : 'ComponentName';
                if ((string) @IPS_GetProperty($id, $prop) === (string) $componentName) {
                    return (int) $id;
                }
            }
        }
        return 0;
    }

    private function FindControlInstance($componentName, $controlName)
    {
        $ids = @IPS_GetInstanceListByModuleID(self::GUID_CONTROL);
        if (!is_array($ids)) {
            return 0;
        }
        foreach ($ids as $id) {
            if ((string) @IPS_GetProperty($id, 'ComponentName') === (string) $componentName
                && (string) @IPS_GetProperty($id, 'ControlName') === (string) $controlName) {
                return (int) $id;
            }
        }
        return 0;
    }

    // -----------------------------------------------------------------
    // Minimaler synchroner QRC-Client (nur fuer die Discovery)
    // -----------------------------------------------------------------

    private function QrcConnect($ep)
    {
        $timeout = (int) $this->ReadPropertyInteger('TimeoutMs');
        if ($timeout < 500) {
            $timeout = 2000;
        }
        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client(
            'tcp://' . $ep['host'] . ':' . $ep['port'],
            $errno,
            $errstr,
            $timeout / 1000.0
        );
        if ($client === false) {
            return false;
        }
        stream_set_timeout($client, 0, $timeout * 1000);

        // Optionaler Logon
        if ((string) $ep['user'] !== '') {
            $this->QrcRequest($client, 'Logon', array('User' => $ep['user'], 'Password' => $ep['pass']));
        }
        return $client;
    }

    // Sendet einen Request und liest bis zur passenden id-Antwort. Rueckgabe: result oder null.
    private function QrcRequest($client, $method, $params)
    {
        static $idCounter = 0;
        $idCounter++;
        $id = $idCounter;

        $msg = array('jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params);
        $payload = json_encode($msg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\0";
        if (@fwrite($client, $payload) === false) {
            return null;
        }

        $buffer = '';
        $deadline = microtime(true) + (max(500, (int) $this->ReadPropertyInteger('TimeoutMs')) / 1000.0);

        while (microtime(true) < $deadline) {
            $chunk = @fread($client, 8192);
            if ($chunk === false || $chunk === '') {
                $meta = @stream_get_meta_data($client);
                if (isset($meta['timed_out']) && $meta['timed_out']) {
                    break;
                }
                usleep(10000);
                continue;
            }
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\0")) !== false) {
                $frame = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                if ($frame === '') {
                    continue;
                }
                $resp = json_decode($frame, true);
                if (!is_array($resp)) {
                    continue;
                }
                // Push-Notifications (EngineStatus etc.) ueberspringen
                if (!isset($resp['id'])) {
                    continue;
                }
                if ((int) $resp['id'] === $id && array_key_exists('result', $resp)) {
                    return $resp['result'];
                }
                if ((int) $resp['id'] === $id && isset($resp['error'])) {
                    return null;
                }
            }
        }
        return null;
    }
}
