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

    public function RequestCode() {
        $email = $this->ReadPropertyString('Email');
        $password = $this->ReadPropertyString('Password');

        if ($email && $password) {
            $response = $this->SendAuthCodeRequest($email, $password);
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
        $url = "https://id.mercedes-benz.com/as/token.oauth2";
        $data = [
            "grant_type" => "password",
            "username" => $email,
            "password" => $password,
            "client_id" => "your_client_id",
            "client_secret" => "your_client_secret"
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
        return json_decode($result, true);
    }

    public function RequestData() {
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
        $url = "https://id.mercedes-benz.com/as/token.oauth2";
        $data = [
            "grant_type" => "authorization_code",
            "code" => $authCode,
            "client_id" => "your_client_id",
            "client_secret" => "your_client_secret"
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
        $response = json_decode($result, true);
        return $response['access_token'];
    }

    private function GetMercedesMeData($token) {
        $url = "https://api.mercedes-benz.com/vehicledata/v2/vehicles";
        $options = [
            "http" => [
                "header" => "Authorization: Bearer $token"
            ]
        ];
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        return json_decode($result, true);
    }

    private function ProcessData($data) {
        foreach ($data as $key => $value) {
            $this->MaintainVariable($key, $key, VARIABLETYPE_STRING, '', 0, true);
            $this->SetValue($key, $value);
        }
    }
}

function MercedesMe_RequestCode($instanceID) {
    $module = IPS_GetInstance($instanceID)['ModuleInfo']['ModuleID'];
    $script = "IPSModule::InstanceObject($instanceID)->RequestCode();";
    IPS_RunScriptText($script);
}
?>
