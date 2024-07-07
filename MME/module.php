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

        $dataPoints = json_decode($this->ReadPropertyString('DataPoints'), true);
        foreach ($dataPoints as $dataPoint) {
            $this->MaintainVariable($dataPoint['VariableName'], $dataPoint['VariableName'], $dataPoint['VariableType'], '', 0, true);
        }

        $this->SetReceiveDataFilter('.*');
    }

    public function ReceiveData($JSONString) {
        $data = json_decode($JSONString);
        $dataPoints = json_decode($this->ReadPropertyString('DataPoints'), true);

        foreach ($dataPoints as $dataPoint) {
            if (fnmatch($dataPoint['Topic'], $data->Topic)) {
                $this->SetValue($dataPoint['VariableName'], $data->Payload);
            }
        }
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
            echo "MQTT Server IP und Port müssen angegeben werden.";
            return;
        }

        $connectionParams = [
            "ClientID" => "SymconMercedesMeTest",
            "CleanSession" => true,
            "ProtocolVersion" => 4,
            "Host" => $serverIP,
            "Port" => intval($serverPort),
            "KeepAlive" => 60,
            "Username" => $username,
            "Password" => $password
        ];

        $data = [
            "DataID" => "{018EF6B5-AB94-40C6-AA53-46943E824ACF}",
            "PacketType" => 12,
            "QualityOfService" => 0,
            "Retain" => false,
            "Topic" => "test/topic",
            "Payload" => "Test",
            "Buffer" => json_encode($connectionParams)
        ];

        $this->SendDataToParent(json_encode($data));
        echo "Testnachricht gesendet.";
    }
}
?>
