<?php

class MercedesMe extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString('MQTTServerIP', '');
        $this->RegisterPropertyString('MQTTServerPort', '');
        $this->RegisterPropertyString('MQTTUsername', '');
        $this->RegisterPropertyString('MQTTPassword', '');
        $this->RegisterPropertyString('DataPoints', '[]');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        // Überprüfe die MQTT-Einstellungen
        $this->ValidateProperties();
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

    private function LoadMQTTTopics() {
        // Hier wird die Verbindung zum MQTT-Server hergestellt und die Topics abgerufen.
        // Dies ist ein Beispiel mit Dummy-Topics.
        $topics = [
            'home/temperature',
            'home/humidity',
            'home/door'
        ];

        // In einer echten Implementierung sollte hier der Code zum Abrufen der Topics vom MQTT-Server stehen.
        // Die Topics können z.B. durch ein Abonnement eines Wildcard-Topics ("#") abgerufen werden.

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

        return json_encode($form);
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
}
?>
