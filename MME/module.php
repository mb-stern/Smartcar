<?php

class MercedesMe extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('AuthCode', '');
        $this->RegisterAttributeString('AccessToken', '');
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

        IPS_LogMessage("MercedesMe", "Email: $email, Password: $password");

        if ($email && $password) {
            $response = $this->SendAuthCodeRequest($email, $password);
            IPS_LogMessage("MercedesMe", "Response: " . print_r($response, true));
            if ($response) {
                echo "Der Authentifizierungscode wurde an Ihre E-Mail-Adresse gesendet.";
            } else {
                echo "Fehler beim Anfordern des Authentifizierungscodes.";
            }
        } else {
            echo "Bitte geben Sie Ihre E-Mail und Ihr Passwort ein.";
        }
    }

    private function SendAuthCodeRequest($email, $password) {
        IPS_LogMessage("MercedesMe", "SendAuthCodeRequest aufgerufen");
        $url = "https://id.mercedes-benz.com/as/token.oauth2";
        $data = [
            "grant_type" => "password",
            "username" => $email,
            "password" => $password,
            "client_id" => "b21c1221-a3d7-4d79-b3f8-053d648c13e1",
            "client_secret" => "b21c1221-a3d7-4d79-b3f8-053d648c13e1"
        ];
        $options = [
            "http" => [
                "method" => "POST",
                "header" => "Content-Type: application/x-www-form-urlencoded",
                "content" => http_build_query($data)
            ]
        ];
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        IPS_LogMessage("MercedesMe", "Result: $result");
        return json_decode($result, true);
    }

    public function RequestData() {
        IPS_LogMessage("MercedesMe", "RequestData aufgerufen");
        $authCode = $this->ReadPropertyString('AuthCode');

        if ($authCode) {
            $token = $this->GetAccessToken($authCode);
            $this->WriteAttributeString('AccessToken', $token);

            $data = $this->GetMercedesMeData($token);
            $this->ProcessData($data);
        } else {
            echo "Bitte geben Sie den Authentifizierungscode ein.";
        }
    }

    private function GetAccessToken($authCode) {
        IPS_LogMessage("MercedesMe", "GetAccessToken aufgerufen");
        $url = "https://id.mercedes-benz.com/as/token.oauth2";
        $data = [
            "grant_type" => "authorization_code",
            "code" => $authCode,
            "client_id" => "b21c1221-a3d7-4d79-b3f8-053d648c13e1",
            "client_secret" => "b21c1221-a3d7-4d79-b3f8-053d648c13e1"
        ];
        $options = [
            "http" => [
                "method" => "POST",
                "header" => "Content-Type: application/x-www-form-urlencoded",
                "content" => http_build_query($data)
            ]
        ];
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        IPS_LogMessage("MercedesMe", "Result: $result");
        $response = json_decode($result, true);
        return $response['access_token'];
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
        $result = file_get_contents($url, false, $context);
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
}

?>
