<?php

class MercedesMe extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString('MQTTServerIP', '');
        $this->RegisterPropertyString('MQTTServerPort', '');
        $this->RegisterPropertyString('MQTTUsername', '');
        $this->RegisterPropertyString('MQTTPassword', '');
        $this->RegisterPropertyString('DataPoints', '[]');
        $this->RegisterPropertyString('AvailableTopics', '[]');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
    }

    public function RequestAction($Ident, $Value) {
        switch ($Ident) {
            case 'TestConnection':
                $this->TestConnection();
                break;
            case 'LoadTopics':
                $this->LoadTopics();
                break;
            default:
                throw new Exception("Invalid action");
        }
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

        IPS_LogMessage("MercedesMe", "Verbindung zum MQTT-Server erfolgreich hergestellt.");

        $publish = $this->createMQTTPublishPacket($topic, $message);
        fwrite($socket, $publish);

        IPS_LogMessage("MercedesMe", "Testnachricht gesendet: $message an Topic: $topic.");

        fclose($socket);
        echo "Testnachricht gesendet.";
    }

    private function LoadTopics() {
        $serverIP = $this->ReadPropertyString('MQTTServerIP');
        $serverPort = $this->ReadPropertyString('MQTTServerPort');
        $username = $this->ReadPropertyString('MQTTUsername');
        $password = $this->ReadPropertyString('MQTTPassword');

        if (empty($serverIP) || empty($serverPort)) {
            echo "MQTT Server IP und Port müssen angegeben werden.";
            return;
        }

        $clientID = "SymconMercedesMeLoadTopics";
        $topics = $this->getMQTTTopics($serverIP, $serverPort, $clientID, $username, $password);
        
        if ($topics !== null) {
            $this->UpdateFormField('AvailableTopics', 'options', json_encode($topics));
            echo "Themen erfolgreich geladen.";
        } else {
            echo "Fehler beim Laden der Themen.";
        }
    }

    private function getMQTTTopics($serverIP, $serverPort, $clientID, $username, $password) {
        $topics = [];
        // Code, um die Themen vom MQTT-Server abzurufen und in $topics zu speichern
        // Dies erfordert die Implementierung eines MQTT-Subscribers, der auf die verfügbaren Themen hört
        // Es ist eine Herausforderung, dies ohne eine spezialisierte MQTT-Bibliothek zu erreichen, aber es könnte möglich sein
        return $topics;
    }

    private function createMQTTConnectPacket($clientID, $username, $password) {
        $protocolName = "MQTT";
        $protocolLevel = chr(4);  // MQTT v3.1.1
        $connectFlags = chr(0xC2); // Clean session und User/Pass
        $keepAlive = chr(0) . chr(60); // Keep alive 60 seconds

        $payload = $this->encodeString($clientID);
        if ($username) {
            $payload .= $this->encodeString($username);
            $payload += $this->encodeString($password);
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
}
?>
