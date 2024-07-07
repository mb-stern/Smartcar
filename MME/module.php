<?php

class MercedesMe extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyInteger('MQTTServer', 0);
        $this->RegisterPropertyString('DataPoints', '[]');
        $this->ConnectParent('{12345678-ABCD-1234-EFAB-567890ABCDEF}'); // Ersetzen Sie diese GUID durch die korrekte GUID des MQTT-Server-Moduls
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

    private function RegisterHook($hook) {
        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
            return;
        }

        $ids = IPS_GetInstanceListByModuleID("{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}");
        if (count($ids) > 0) {
            $id = $ids[0];
            $data = IPS_GetProperty($id, "Hooks");
            $data = json_decode($data, true);

            if (!is_array($data)) {
                $data = [];
            }

            $found = false;
            foreach ($data as $index => $entry) {
                if ($entry['Hook'] == $hook) {
                    if ($entry['TargetID'] == $this->InstanceID) {
                        return;
                    } else {
                        $data[$index]['TargetID'] = $this->InstanceID;
                        $found = true;
                    }
                }
            }

            if (!$found) {
                $data[] = [
                    "Hook" => $hook,
                    "TargetID" => $this->InstanceID
                ];
            }

            IPS_SetProperty($id, "Hooks", json_encode($data));
            IPS_ApplyChanges($id);
            IPS_LogMessage("MercedesMe", "Hook erfolgreich registriert: $hook");
        } else {
            IPS_LogMessage("MercedesMe", "Keine passenden Instanzen gefunden für die Registrierung des Hooks: $hook");
        }
    }

    protected function ProcessHookData() {
        $hook = explode('/', $_SERVER['REQUEST_URI']);
        $hook = end($hook);
        if ($hook == "MercedesMeWebHook") {
            IPS_LogMessage("MercedesMe", "WebHook empfangen");
            $code = $_GET['code'] ?? '';
            if ($code) {
                $this->WriteAttributeString('AuthCode', $code);
                IPS_ApplyChanges($this->InstanceID);
                IPS_LogMessage("MercedesMe", "Authentifizierungscode erhalten: $code");
                echo "Authentifizierungscode erhalten.";
            } else {
                IPS_LogMessage("MercedesMe", "Kein Authentifizierungscode erhalten");
                echo "Kein Authentifizierungscode erhalten.";
            }
        }
    }
}
?>
