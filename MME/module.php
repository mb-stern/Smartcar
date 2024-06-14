<?php

class MercedesMe extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
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
            case 'RequestPassword':
                $this->RequestPassword();
                break;
            case 'RequestCode':
                $this->RequestCode();
                break;
            default:
                throw new Exception("Invalid action");
        }
    }

    public function RequestPassword() {
        IPS_LogMessage("MercedesMe", "RequestPassword aufgerufen");
        $email = $this->ReadPropertyString('Email');

        IPS_LogMessage("MercedesMe", "Email: $email");

        if ($email) {
            $response = $this->SendPasswordRequest($email);
            IPS_LogMessage("MercedesMe", "Response: " . print_r($response, true));
            if ($response) {
                echo "Das Passwort wurde an Ihre E-Mail-Adresse gesendet.";
            } else {
                echo "Fehler beim Anfordern des Passworts.";
            }
        } else {
            echo "Bitte geben Sie Ihre E-Mail ein.";
        }
    }

    private function SendPasswordRequest($email) {
        IPS_LogMessage("MercedesMe", "SendPasswordRequest aufgerufen");
        $url = "https://api.mercedes-benz.com/oidc10/authenticate"; // Beispielendpunkt, der angepasst werden muss
        $data = [
            "client_id" => "b21c1221-a3d7-4d79-b3f8-053d648c13e1",
            "email" => $email
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
            echo "Fehler beim Anfordern des Passworts: " . $error['message'];
            return null;
        }
        IPS_LogMessage("MercedesMe", "Result: $result");
        return json_decode($result, true);
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
            "client_id" => "b21c1221-a3d7-4d79-b3f8-053d648c13e1",
            "client_secret" => "b21c1221-a3d7-4d79-b3f8-053d648c13e1",
            "grant_type" => "password",
            "username" => $email,
            "password" => $password
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