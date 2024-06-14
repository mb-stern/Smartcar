<?php

class MercedesMe extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('WebhookURL', '');
        $this->RegisterAttributeString('AuthCode', '');
        $this->RegisterAttributeString('AccessToken', '');
        
        // Webhook für das Modul registrieren
        $this->RegisterHook("/hook/MercedesMe");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm() {
        return file_get_contents(__DIR__ . '/form.json');
    }

    public function RequestAction($Ident, $Value) {
        switch ($Ident) {
            case 'RequestCode':
                $this->RequestCode();
                break;
            default:
                throw new Exception("Invalid action");
        }
    }

    public function RequestCode() {
        IPS_LogMessage("MercedesMe", "RequestCode aufgerufen");
        $email = $this->ReadPropertyString('Email');
        $password = $this->ReadPropertyString('Password');
        $webhookURL = $this->ReadPropertyString('WebhookURL');

        IPS_LogMessage("MercedesMe", "Email: $email, Password: $password, WebhookURL: $webhookURL");

        if ($email && $password && $webhookURL) {
            $response = $this->SendAuthCodeRequest($email, $password, $webhookURL);
            IPS_LogMessage("MercedesMe", "Response: " . print_r($response, true));
            if ($response) {
                echo "Der Authentifizierungscode wurde an Ihre E-Mail-Adresse gesendet.";
            } else {
                echo "Fehler beim Anfordern des Authentifizierungscodes.";
            }
        } else {
            echo "Bitte geben Sie Ihre E-Mail, Ihr Passwort und die Webhook-URL ein.";
        }
    }

    private function SendAuthCodeRequest($email, $password, $webhookURL) {
        IPS_LogMessage("MercedesMe", "SendAuthCodeRequest aufgerufen");
        $url = "https://id.mercedes-benz.com/as/token.oauth2";
        $data = [
            "client_id" => "b21c1221-a3d7-4d79-b3f8-053d648c13e1",
            "client_secret" => "b21c1221-a3d7-4d79-b3f8-053d648c13e1",
            "grant_type" => "password",
            "username" => $email,
            "password" => $password,
            "redirect_uri" => $webhookURL
        ];
        $options = [
            "http" => [
                "method" => "POST",
                "header" => "Content-Type: application/x-www-form-urlencoded",
                "content" => http_build_query($data)
            ]
        ];
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        if ($result === FALSE) {
            $error = error_get_last();
            IPS_LogMessage("MercedesMe", "HTTP request failed: " . $error['message']);
            echo "Fehler beim Anfordern des Authentifizierungscodes: " . $error['message'];
            return null;
        }
        IPS_LogMessage("MercedesMe", "Result: $result");
        return json_decode($result, true);
    }

    public function HandleWebhook() {
        $data = file_get_contents('php://input');
        IPS_LogMessage("MercedesMe", "Webhook received: " . $data);
        $decodedData = json_decode($data, true);
        if (isset($decodedData['access_token'])) {
            $this->WriteAttributeString('AccessToken', $decodedData['access_token']);
            echo "Access Token erhalten und gespeichert.";
        } else {
            echo "Fehler beim Empfangen des Access Tokens.";
        }
    }

    public function RequestData() {
        IPS_LogMessage("MercedesMe", "RequestData aufgerufen");
        $token = $this->ReadAttributeString('AccessToken');

        if ($token) {
            $data = $this->GetMercedesMeData($token);
            $this->ProcessData($data);
        } else {
            echo "Bitte geben Sie den Access Token ein.";
        }
    }

    private function GetMercedesMeData($token) {
        IPS_LogMessage("MercedesMe", "GetMercedesMeData aufgerufen");
        $url = "https://api.mercedes-benz.com/vehicledata/v2/vehicles";
        $options = [
            "http" => [
                "header" => "Authorization: Bearer $token"
            ]
        ];
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        if ($result === FALSE) {
            $error = error_get_last();
            IPS_LogMessage("MercedesMe", "HTTP request failed: " . $error['message']);
            return null;
        }
        IPS_LogMessage("MercedesMe", "Result: $result");
        return json_decode($result, true);
    }

    private function ProcessData($data) {
        IPS_LogMessage("MercedesMe", "ProcessData aufgerufen");
        foreach ($data as $key => $value) {
            $this->MaintainVariable($key, $key, VARIABLETYPE_STRING, '', 0, true);
            $this->SetValue($key, $value);
        }
    }

    private function RegisterHook($Hook) {
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }
        $ids = IPS_GetInstanceListByModuleID("{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}");
        if (count($ids) > 0) {
            $hookInstanceID = $ids[0];
            $hooks = json_decode(IPS_GetProperty($hookInstanceID, "Hooks"), true);
            $found = false;
            foreach ($hooks as $index => $hook) {
                if ($hook['Hook'] == $Hook) {
                    if ($hook['TargetID'] == $this->InstanceID) {
                        return;
                    }
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }
            if (!$found) {
                $hooks[] = ["Hook" => $Hook, "TargetID" => $this->InstanceID];
            }
            IPS_SetProperty($hookInstanceID, "Hooks", json_encode($hooks));
            IPS_ApplyChanges($hookInstanceID);
        }
    }
}

?>
