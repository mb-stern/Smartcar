<?php

class MercedesMe extends IPSModule {

    private $MQTTTopics = [];

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString('MQTTServerIP', '');
        $this->RegisterPropertyString('MQTTServerPort', '');
        $this->RegisterPropertyString('MQTTUsername', '');
        $this->RegisterPropertyString('MQTTPassword', '');
        $this->RegisterPropertyString('DataPoints', '[]');
        $this->RegisterPropertyString('SelectedTopics', '[]');
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
        // Hier wird die Verbindung zum MQTT-Server hergestellt und die Topics abgerufen
        // Dies ist ein Beispiel; die Implementierung muss an deinen MQTT-Server angepasst werden

        $serverIP = $this->ReadPropertyString('MQTTServerIP');
        $serverPort = $this->ReadPropertyString('MQTTServerPort');
        $username = $this->ReadPropertyString('MQTTUsername');
        $password = $this->ReadPropertyString('MQTTPassword');

        // Verbindung zum MQTT-Server herstellen und Topics abfragen
        // Die abgerufenen Topics in der Klasse speichern
        // Beispiel-Topics
        $this->MQTTTopics = [
            ['Topic' => 'home/temperature'],
            ['Topic' => 'home/humidity'],
            ['Topic' => 'home/door']
        ];
    }

    public function GetConfigurationForm() {
        $this->LoadMQTTTopics();

        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Dynamisch Topics hinzufügen
        $topics = [];
        $selectedTopics = json_decode($this->ReadPropertyString('SelectedTopics'), true);
        foreach ($this->MQTTTopics as $topic) {
            $isSelected = in_array($topic['Topic'], $selectedTopics);
            $topics[] = [
                'Topic' => $topic['Topic'],
                'Selected' => $isSelected
            ];
        }

        $form['elements'][] = [
            'type' => 'List',
            'name' => 'MQTTTopics',
            'caption' => 'Verfügbare MQTT Topics',
            'rowCount' => 10,
            'add' => false,
            'delete' => false,
            'columns' => [
                [
                    'caption' => 'Topic',
                    'name' => 'Topic',
                    'width' => '300px',
                    'edit' => [
                        'type' => 'ValidationTextBox'
                    ]
                ],
                [
                    'caption' => 'Selected',
                    'name' => 'Selected',
                    'width' => '75px',
                    'edit' => [
                        'type' => 'CheckBox'
                    ]
                ]
            ],
            'values' => $topics
        ];

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
