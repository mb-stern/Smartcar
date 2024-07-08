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

    private function TestConnection() {
        $serverIP = $this->ReadPropertyString('MQTTServerIP');
        $serverPort = $this->ReadPropertyString('MQTTServerPort');
        $username = $this->ReadPropertyString('MQTTUsername');
        $password = $this->ReadPropertyString('MQTTPassword');

        if (empty($serverIP) || empty($serverPort)) {
            IPS_LogMessage("MercedesMe", "MQTT Server IP und Port müssen angegeben werden.");
            return;
        }

        $clientID = "SymconMercedesMeTest";
        $topic = "test/topic";
        $message = "Test";

        $socket = @fsockopen($serverIP, intval($serverPort), $errno, $errstr, 60);
        if (!$socket) {
            IPS_LogMessage("MercedesMe", "Fehler beim Verbinden mit dem MQTT-Server: $errstr ($errno)");
            return;
        }

        try {
            $connect = $this->createMQTTConnectPacket($clientID, $username, $password);
            fwrite($socket, $connect);

            $connack = fread($socket, 4);
            if (ord($connack[0]) >> 4 != 2 || ord($connack[3]) != 0) {
                throw new Exception("Verbindung zum MQTT-Server fehlgeschlagen.");
            }

            $publish = $this->createMQTTPublishPacket($topic, $message);
            fwrite($socket, $publish);

            IPS_LogMessage("MercedesMe", "Testnachricht gesendet.");
        } catch (Exception $e) {
            IPS_LogMessage("MercedesMe", $e->getMessage());
        } finally {
            fclose($socket);
        }
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
