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
    const GUID_EQ         = '{2819CDAC-AE34-4923-B2A5-B535A190A509}';

    // Obergrenze fuer Control-Zeilen im Formular. Symcon bricht die Ausgabe bei
    // 1 MB ab; ~4400 Zeilen reissen das sicher, 800 bleiben deutlich darunter.
    const MAX_CONTROL_ROWS = 800;

    public function Create()
    {
        parent::Create();
        $this->ConnectParent(self::CORE_GUID);
        $this->RegisterPropertyInteger('TimeoutMs', 2000);
        $this->RegisterPropertyString('ControlFilter', '');
        $this->RegisterPropertyBoolean('ShowAllControls', false);
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
                'type' => 'Label',
                'caption' => 'Komponenten werden immer gelistet. Einzelne Controls erscheinen erst, '
                    . 'wenn unten ein Filter gesetzt ist — ein grosses Design hat mehrere tausend '
                    . 'Controls und wuerde das Formular sprengen. Nach dem Aendern eines Feldes '
                    . '"Aenderungen uebernehmen" druecken.'
            ),
            array(
                'type' => 'ValidationTextBox',
                'name' => 'ControlFilter',
                'caption' => 'Controls anzeigen fuer Komponenten mit (Namensteil)'
            ),
            array(
                'type' => 'CheckBox',
                'name' => 'ShowAllControls',
                'caption' => 'Controls aller Komponenten anzeigen (nur bei kleinen Designs)'
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

        // Ein reales Design sprengt sonst das Formular: SKUZ_FACE_190826 hat 142
        // Komponenten mit zusammen 4259 Controls ("Matrix Saal" allein 693). Alle
        // als Zeilen ausgegeben, laeuft Symcon in "Output-Buffer exceeds Limit
        // (1048576 bytes)" und der Configurator zeigt gar nichts mehr.
        // Deshalb: Komponenten immer, Controls nur gefiltert und mit Obergrenze.
        $filter = trim((string) $this->ReadPropertyString('ControlFilter'));
        $showAll = (bool) $this->ReadPropertyBoolean('ShowAllControls');

        $values = array();
        $ctrlRows = 0;
        $limitHit = false;
        $skipped = 0;

        foreach ($components as $comp) {
            if (!isset($comp['Name'])) {
                continue;
            }
            $cName = (string) $comp['Name'];
            $cType = isset($comp['Type']) ? (string) $comp['Type'] : '';
            $compRowId = crc32('C:' . $cName) & 0x7fffffff;

            // Controls immer holen: der Core meldet dort ValueMin/ValueMax und die
            // label.N eines Selectors -- daraus werden Gain-Bereich und Quellenliste
            // vorbelegt. Die Abfrage ist billig, nur die Ausgabe muss begrenzt werden.
            $ctrls = $this->QrcRequest($client, 'Component.GetControls', array('Name' => $cName));
            $list = (is_array($ctrls) && isset($ctrls['Controls']) && is_array($ctrls['Controls'])) ? $ctrls['Controls'] : array();

            $values[] = $this->ComponentRow($compRowId, $cName, $cType, $list);

            // Die Obergrenze darf nur die Controls kappen -- die Komponentenliste
            // muss vollstaendig bleiben, sonst fehlen anlegbare Komponenten.
            $wanted = $showAll || ($filter !== '' && stripos($cName, $filter) !== false);
            if (!$wanted || $limitHit) {
                $skipped += count($list);
                continue;
            }

            foreach ($list as $ctrl) {
                if (!isset($ctrl['Name'])) {
                    continue;
                }
                if ($ctrlRows >= self::MAX_CONTROL_ROWS) {
                    $limitHit = true;
                    break;
                }
                $values[] = $this->ControlRow($compRowId, $cName, $ctrl);
                $ctrlRows++;
            }
        }
        @fclose($client);

        if (count($values) === 0) {
            return array($this->InfoRow('Es wurden keine Named Components gefunden.'));
        }

        if ($limitHit) {
            array_unshift($values, $this->InfoRow(
                'Anzeige bei ' . self::MAX_CONTROL_ROWS . ' Controls abgeschnitten. '
                . 'Filter enger setzen, um gezielt an die restlichen Controls zu kommen.'));
        } elseif ($ctrlRows === 0 && $skipped > 0) {
            array_unshift($values, $this->InfoRow(
                $skipped . ' Controls ausgeblendet. Komponenten lassen sich direkt anlegen; '
                . 'fuer einzelne Controls oben einen Komponenten-Filter eintragen '
                . 'und Aenderungen uebernehmen.'));
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
    private function ComponentRow($rowId, $name, $type, $controls = array())
    {
        $create = array();
        $lt = strtolower($type . ' ' . $name);

        if (strpos($lt, 'gain') !== false) {
            // dB-Bereich aus dem Design uebernehmen statt zu raten: der Core meldet
            // ValueMin/ValueMax am gain-Control (z. B. -100..0 statt der Annahme -100..+20).
            $cfg = array('ComponentName' => $name);
            foreach ($controls as $c) {
                if (isset($c['Name']) && $c['Name'] === 'gain') {
                    if (isset($c['ValueMin'])) {
                        $cfg['MinDB'] = (float) $c['ValueMin'];
                    }
                    if (isset($c['ValueMax'])) {
                        $cfg['MaxDB'] = (float) $c['ValueMax'];
                    }
                    break;
                }
            }
            $create[] = array('moduleID' => self::GUID_GAIN, 'name' => $name, 'configuration' => $cfg);
        } elseif (strpos($lt, 'equalizer') !== false) {
            // Bandzahl aus den frequency.N-Controls ableiten, damit das EQ-Modul
            // sie nicht erst selbst beim Core erfragen muss.
            $baender = 0;
            foreach ($controls as $c) {
                if (isset($c['Name']) && preg_match('/^frequency\.(\d+)$/', (string) $c['Name'], $mm)) {
                    $baender = max($baender, (int) $mm[1]);
                }
            }
            $create[] = array(
                'moduleID' => self::GUID_EQ,
                'name' => $name,
                'configuration' => array('ComponentName' => $name, 'BandCount' => $baender)
            );
        } elseif (strpos($lt, 'selector') !== false) {
            // Selector-Komponente: der aktive Eintrag und die komplette Optionsliste
            // stecken im Control "selector". Das Modul liest die Quellen selbst aus
            // dessen Choices, hier werden sie nur als Startwert vorbelegt.
            $create[] = array(
                'moduleID' => self::GUID_ROUTER,
                'name' => $name,
                'configuration' => array(
                    'Mode' => 'selector',
                    'ComponentName' => $name,
                    'AutoSources' => true,
                    'Sources' => json_encode($this->SelectorSources($controls))
                )
            );
        } elseif (strpos($lt, 'router') !== false) {
            $create[] = array(
                'moduleID' => self::GUID_ROUTER,
                'name' => $name,
                'configuration' => array('Mode' => 'router', 'ComponentName' => $name)
            );
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

    // Quellenliste einer Selector-Komponente als [{Value,Label}] (Value 1-basiert,
    // passend zum Router-Modul). Bevorzugt die "label.N"-Controls, die
    // Component.GetControls sicher liefert; ersatzweise die Choices des
    // "selector"-Controls, falls das Design keine Labels fuehrt.
    private function SelectorSources($controls)
    {
        $rows = array();
        foreach ($controls as $c) {
            if (!isset($c['Name'])) {
                continue;
            }
            if (preg_match('/^label\.(\d+)$/', (string) $c['Name'], $m)) {
                $idx = (int) $m[1];
                $txt = isset($c['String']) ? (string) $c['String'] : '';
                $rows[$idx] = ($txt !== '') ? $txt : ('Quelle ' . ($idx + 1));
            }
        }

        if (count($rows) === 0) {
            foreach ($controls as $c) {
                if (!isset($c['Name']) || (string) $c['Name'] !== 'selector') {
                    continue;
                }
                if (!isset($c['Choices']) || !is_array($c['Choices'])) {
                    continue;
                }
                foreach ($c['Choices'] as $entry) {
                    $d = json_decode((string) $entry, true);
                    if (!is_array($d) || !isset($d['Index'])) {
                        continue;
                    }
                    $idx = (int) $d['Index'];
                    $txt = isset($d['Text']) ? (string) $d['Text'] : '';
                    $rows[$idx] = ($txt !== '') ? $txt : ('Quelle ' . ($idx + 1));
                }
            }
        }

        ksort($rows);
        $out = array();
        foreach ($rows as $idx => $label) {
            $out[] = array('Value' => $idx + 1, 'Label' => $label);
        }
        return $out;
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
