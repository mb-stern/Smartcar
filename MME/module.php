<?php

class MercedesMe extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString('MQTTServerIP', '');
        $this->RegisterPropertyString('MQTTServerPort', '');
        $this->RegisterPropertyString('MQTTUsername', '');
        $this->RegisterPropertyString('MQTTPassword', '');
        $this->RegisterPropertyString('DataPoints', '[]');
        $this->RegisterPropertyString('TopicFilter', ''); // Suchfilter für Topics
        $this->RegisterTimer('UpdateMQTTData', 0, 'MME_UpdateData($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        // Überprüfe die MQTT-Einstellungen
        $this->ValidateProperties();

        // Initialisieren der Variablen für die Datenpunkte
        $this->InitializeDataPoints();

        // Setze den Timer auf 60 Sekunden
        $this->SetTimerInterval('UpdateMQTTData', 60 * 1000);
    }

    public function RequestAction($Ident, $Value) {
        switch ($Ident) {
            case 'TestConnection':
                $this->TestConnection();
                break;
            default:
                throw new Exception("Invalid action");
        }
    }

    public function UpdateData() {
        $this->LoadMQTTTopics();
    }

    private function LoadMQTTTopics() {
        $serverIP = $this->ReadPropertyString('MQTTServerIP');
        $serverPort = $this->ReadPropertyString('MQTTServerPort');
        $username = $this->ReadPropertyString('MQTTUsername');
        $password = $this->ReadPropertyString('MQTTPassword');
        $topicFilter = $this->ReadPropertyString('TopicFilter');

        if (empty($serverIP) || empty($serverPort)) {
            IPS_LogMessage("MercedesMe", "MQTT Server IP und Port müssen angegeben werden.");
            return [];
        }

        $clientID = "SymconMercedesMeLoadTopics";
        $socket = @fsockopen($serverIP, intval($serverPort), $errno, $errstr, 60);
        if (!$socket) {
            IPS_LogMessage("MercedesMe", "Fehler beim Verbinden mit dem MQTT-Server: $errstr ($errno)");
            return [];
        }

        $connect = $this->createMQTTConnectPacket($clientID, $username, $password);
        fwrite($socket, $connect);

        $connack = fread($socket, 4);
        if (ord($connack[0]) >> 4 != 2 || ord($connack[3]) != 0) {
            fclose($socket);
            IPS_LogMessage("MercedesMe", "Verbindung zum MQTT-Server fehlgeschlagen.");
            return [];
        }

        // Abonnieren eines Wildcard-Topics, um alle Topics zu erhalten
        $subscribe = $this->createMQTTSubscribePacket('#');
        fwrite($socket, $subscribe);

        // Lese die abonnierten Topics
        $topics = [];
        stream_set_timeout($socket, 5);
        while (!feof($socket)) {
            $data = fread($socket, 8192);
            if ($data === false) {
                break;
            }
            // Extrahiere die Topics aus den empfangenen Daten
            $topics = array_merge($topics, $this->extractTopicsFromData($data));
        }

        fclose($socket);
        $topics = array_unique($topics); // Doppelte Topics entfernen

        // Filtere und sortiere die Topics
        if (!empty($topicFilter)) {
            $topics = array_filter($topics, function($topic) use ($topicFilter) {
                return stripos($topic, $topicFilter) !== false;
            });
        }

        sort($topics); // Alphabetisch sortieren

        return $topics;
    }

    private function extractTopicsFromData($data) {
        $topics = [];

        // MQTT PUBLISH Packet Identifier is 0x30
        while (strlen($data) > 0) {
            if ((ord($data[0]) >> 4) == 0x03) {
                $data = substr($data, 1);
                $length = ord($data[0]);
                $data = substr($data, 1);
                $topicLength = ord($data[0]) << 8 | ord($data[1]);
                $topic = substr($data, 2, $topicLength);
                $topics[] = $topic;
                $data = substr($data, 2 + $topicLength + $length - $topicLength - 2);
            } else {
                break;
            }
        }

        return $topics;
    }

    public function GetConfigurationForm() {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Dynamisch Topics hinzufügen
        $topics = $this->LoadMQTTTopics();
        $options = [];
        foreach ($topics as $topic) {
            $options[] = ['caption' => $topic, 'value' => $topic];
        }

        // Füge die Options zu einem Select-Feld im DataPoints-Element hinzu
        foreach ($form['elements'] as &$element) {
            if ($element['name'] == 'DataPoints') {
                foreach ($element['columns'] as &$column) {
                    if ($column['name'] == 'Topic') {
                        $column['edit'] = [
                            'type' => 'Select',
                            'options' => $options
                        ];
                    }
                }
            }
        }

        // Suchfeld für Topics hinzufügen
        $form['elements'][] = [
            'type' => 'ValidationTextBox',
            'name' => 'TopicFilter',
            'caption' => 'Topic Filter',
            'onChange' => 'IPS_RequestAction($id, "ApplyFilter", $value);'
        ];

        return json_encode($form);
    }

    public function ApplyFilter($value) {
        $this->UpdateFormField('TopicFilter', 'value', $value);
        $this->LoadMQTTTopics();
    }

    private function TestConnection() {
        $serverIP = $this->ReadPropertyString('MQTTServerIP');
        $serverPort = $this->ReadPropertyString('MQTTServerPort');
        $username = $this->ReadPropertyString('MQTTUsername');
        $password = $this->ReadPropertyString('MQTTPassword');

        if (empty($serverIP) || empty($serverPort)) {
            echo "MQTT Server IP und Port müssen angegeben werden.";
            return;
        }

        $clientID = "SymconMercedesMeTest";
        $topic = "test/topic";
        $message = "Test";

        $socket = fsockopen($serverIP, intval($serverPort), $errno, $errstr, 60);
        if (!$socket) {
            echo "Fehler beim Verbinden mit dem MQTT-Server: $errstr ($errno)";
            return;
        }

        $connect = $this->createMQTTConnectPacket($clientID, $username, $password);
        fwrite($socket, $connect);

        $connack = fread($socket, 4);
        if (ord($connack[0]) >> 4 != 2 || ord($connack[3]) != 0) {
            fclose($socket);
            echo "Verbindung zum MQTT-Server fehlgeschlagen.";
            return;
        }

        $publish = $this->createMQTTPublishPacket($topic, $message);
        fwrite($socket, $publish);

        fclose($socket);
        echo "Testnachricht gesendet.";
    }

    private function createMQTTConnectPacket($clientID, $username, $password) {
        $protocolName = "MQTT";
        $protocolLevel = chr(4);  // MQTT v3.1.1
        $connectFlags = chr(0xC2); // Clean session und User/Pass
        $keepAlive = chr(0) . chr(60); // Keep alive 60 seconds

        $payload = $this->encodeString($clientID);
        if ($username) {
            $payload .= $this->encodeString($username);
            $payload .= $this->encodeString($password);
        }

        $variableHeader = $this->encodeString($protocolName) . $protocolLevel . $connectFlags . $keepAlive;
        $remainingLength = $this->encodeRemainingLength(strlen($variableHeader) + strlen($payload));

        return chr(0x10) . $remainingLength . $variableHeader . $payload;
    }

    private function createMQTTPublishPacket($topic, $message) {
        $fixedHeader = chr(0x30);
        $topicEncoded = $this->encodeString($topic);
        $messageLength = strlen($message);
        $remainingLength = $this->encodeRemainingLength(strlen($topicEncoded) + $messageLength);

        return $fixedHeader . $remainingLength . $topicEncoded . $message;
    }

    private function createMQTTSubscribePacket($topic) {
        $fixedHeader = chr(0x82); // Subscribe packet type
        $messageID = chr(0) . chr(1); // Message ID 1
        $topicEncoded = $this->encodeString($topic);
        $qos = chr(0); // QoS 0
        $remainingLength = $this->encodeRemainingLength(strlen($messageID) + strlen($topicEncoded) + strlen($qos));

        return $fixedHeader . $remainingLength . $messageID . $topicEncoded . $qos;
    }

    private function encodeString($string) {
        return chr(strlen($string) >> 8) . chr(strlen($string) & 0xFF) . $string;
    }

    private function encodeRemainingLength($length) {
        $string = "";
        do {
            $digit = $length % 128;
            $length = $length >> 7;
            if ($length > 0) {
                $digit = $digit | 0x80;
            }
            $string .= chr($digit);
        } while ($length > 0);
        return $string;
    }

    private function ValidateProperties() {
        $serverIP = $this->ReadPropertyString('MQTTServerIP');
        $serverPort = $this->ReadPropertyString('MQTTServerPort');

        if (empty($serverIP) || empty($serverPort)) {
            $this->SetStatus(104); // 104 = Configuration invalid
        } else {
            $this->SetStatus(102); // 102 = Configuration valid
        }
    }

    private function InitializeDataPoints() {
        $dataPoints = json_decode($this->ReadPropertyString('DataPoints'), true);
        $existingVariables = array_map(function($variableID) {
            return IPS_GetObject($variableID)['ObjectIdent'];
        }, IPS_GetChildrenIDs($this->InstanceID));

        foreach ($dataPoints as $dataPoint) {
            $this->RegisterVariable($dataPoint['VariableName'], $dataPoint['VariableType']);
        }

        // Variablen löschen, die nicht mehr in den Datenpunkten vorhanden sind
        $dataPointNames = array_column($dataPoints, 'VariableName');
        $variablesToDelete = array_diff($existingVariables, $dataPointNames);
        foreach ($variablesToDelete as $variableName) {
            $this->UnregisterVariable($variableName);
        }

        $this->SetBuffer('DataPoints', json_encode($dataPoints));
    }

    private function RegisterVariable($name, $type) {
        $id = @$this->GetIDForIdent($name);
        if ($id === false) {
            switch ($type) {
                case 0:
                    $this->RegisterVariableBoolean($name, $name);
                    break;
                case 1:
                    $this->RegisterVariableInteger($name, $name);
                    break;
                case 2:
                    $this->RegisterVariableFloat($name, $name);
                    break;
                case 3:
                    $this->RegisterVariableString($name, $name);
                    break;
            }
        }
    }

    private function UnregisterVariable($name) {
        $id = @$this->GetIDForIdent($name);
        if ($id !== false) {
            IPS_DeleteVariable($id);
        }
    }

    public function ReceiveData($JSONString) {
        $data = json_decode($JSONString, true);
        $topic = $data['Topic'];
        $message = $data['Payload'];

        $dataPoints = json_decode($this->GetBuffer('DataPoints'), true);
        foreach ($dataPoints as $dataPoint) {
            if ($dataPoint['Topic'] == $topic) {
                $this->UpdateVariable($dataPoint['VariableName'], $dataPoint['VariableType'], $message);
            }
        }
    }

    private function UpdateVariable($name, $type, $value) {
        $id = $this->GetIDForIdent($name);
        if ($id !== false) {
            switch ($type) {
                case 0:
                    SetValueBoolean($id, filter_var($value, FILTER_VALIDATE_BOOLEAN));
                    break;
                case 1:
                    SetValueInteger($id, intval($value));
                    break;
                case 2:
                    SetValueFloat($id, floatval($value));
                    break;
                case 3:
                    SetValueString($id, $value);
                    break;
            }
        }
    }
}
?>

