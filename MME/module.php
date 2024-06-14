<?php

class MercedesMe extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString('Email', '');
        $this->RegisterAttributeString('AuthCode', '');
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

        IPS_LogMessage("MercedesMe", "Email: $email");

        if ($email) {
            $response = $this->SendAuthCodeRequest($email);
            IPS_LogMessage("MercedesMe", "Response: " . print_r($response, true));
            if ($response) {
                echo "Der Authentifizierungscode wurde an Ihre E-Mail-Adresse gesendet.";
            } else {
                echo "Fehler beim Anfordern des Authentifizierungscodes.";
            }
        } else {
            echo "Bitte geben Sie Ihre E-Mail ein.";
        }
    }

    private function SendAuthCodeRequest($email) {
        IPS_LogMessage("MercedesMe", "SendAuthCodeRequest aufgerufen");
        $url = "https://id.mercedes-benz.com/as/token.oauth2";
        $data = [
            "client_id" => "b21c1221-a3d7-4d79-b3f8-053d648c13e1",
            "client_secret" => "b21c1221-a3d7-4d79-b3f8-053d648c13e1",
            "grant_type" => "password",
            "username" => $email,
            "password" => "DeinPasswort"  // Passwortfeld hinzufügen, falls erforderlich
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
}

?>
