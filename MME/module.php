<?php

class MercedesMe extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyInteger('MQTTServer', 0);
        $this->RegisterPropertyString('DataPoints', '[]');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        $mqttServerID = $this->ReadPropertyInteger('MQTTServer');
        if ($mqttServerID > 0) {
            $this->ConnectParent($mqttServerID);
        }

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
        $mqttServerID = $this->ReadPropertyInteger('MQTTServer');
        if ($mqttServerID == 0) {
            echo "MQTT Server nicht ausgewählt.";
            return;
        }

        $data = [
            "DataID" => "{018EF6B5-AB94-40C6-AA53-46943E824ACF}",
            "PacketType" => 3,
            "Buffer" => json_encode([
                "Topic" => "test/topic",
                "Payload" => "Test",
                "QualityOfService" => 0,
                "Retain" => false
            ])
        ];

        $this->SendDataToParent(json_encode($data));
        echo "Testnachricht gesendet.";
    }
}
?>
