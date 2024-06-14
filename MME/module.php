<?php

class MME extends IPSModule {
    
    public function Create() {
        parent::Create();
        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('AuthCode', '');
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
        // Beispielcode für die Authentifizierungsanforderung
        $auth = base64_encode("$email:$password");
        $options = [
            "http" => [
                "method" => "POST",
                "header" => "Authorization: Basic $auth\r\nContent-Type: application/json",
                "content" => json_encode(["email" => $email])
            ]
        ];
        $context = stream_context_create($options);
        $result = file_get_contents("https://api.mercedes-benz.com/request_code", false, $context);
        return $result !== false;
    }

    public function RequestData() {
        $email = $this->ReadPropertyString('Email');
        $password = $this->ReadPropertyString('Password');
        $authCode = $this->ReadPropertyString('AuthCode');
        
        if ($email && $password && $authCode) {
            $data = $this->GetMercedesMeData($email, $password, $authCode);
            $this->ProcessData($data);
        } else {
            echo "Bitte geben Sie Ihre E-Mail, Ihr Passwort und den Authentifizierungscode ein.";
        }
    }

    private function GetMercedesMeData($email, $password, $authCode) {
        $auth = base64_encode("$email:$password:$authCode");
        $options = [
            "http" => [
                "header" => "Authorization: Basic $auth"
            ]
        ];
        $context = stream_context_create($options);
        $result = file_get_contents("https://api.mercedes-benz.com/data", false, $context);
        return json_decode($result, true);
    }

    private function ProcessData($data) {
        foreach ($data as $key => $value) {
            $this->MaintainVariable($key, $key, VARIABLETYPE_STRING, '', 0, true);
            $this->SetValue($key, $value);
        }
    }
}
?>
