<?php

class MercedesMeMQTT extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyInteger('MQTTServer', 0);
        $this->RegisterPropertyString('DataPoints', '[]');
        $this->ConnectParent('{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}');
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
        $mqttServerID = $this->ReadPropertyInteger('MQTTServer');
        if ($mqttServerID == 0) {
            echo "MQTT Server nicht ausgewählt.";
            return;
        }

        $data = [
            "DataID" => "{018EF6B5-AB94-40C6-AA53-46943E824ACF}",
            "PacketType" => 12,
            "QualityOfService" => 0,
            "Retain" => false,
            "Topic" => "test/topic",
            "Payload" => "Test"
        ];

        $this->SendDataToParent(json_encode($data));
        echo "Testnachricht gesendet.";
    }
}
?>
