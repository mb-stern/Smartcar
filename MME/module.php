<?php

class MercedesMe extends IPSModule {

    private $clientID = 'b21c1221-a3d7-4d79-b3f8-053d648c13e1';
    private $clientSecret = 'b21c1221-a3d7-4d79-b3f8-053d648c13e1';

    public function Create() {
        // Diese Zeile nicht löschen
        parent::Create();
        // Registriere Eigenschaften
        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('ClientID', $this->clientID);
        $this->RegisterPropertyString('ClientSecret', $this->clientSecret);
        // Registriere Attribute
        $this->RegisterAttributeString('AuthCode', '');
        $this->RegisterAttributeString('AccessToken', '');
    }

    public function ApplyChanges() {
        // Diese Zeile nicht löschen
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
        $clientID = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');

        IPS_LogMessage("MercedesMe", "Email: $email, Password: $password, ClientID: $clientID, ClientSecret: $clientSecret");

        if ($email && $password && $clientID && $clientSecret) {
            $authCode = $this->GenerateAuthCode($email, $password, $clientID, $clientSecret);
            if ($authCode) {
                $accessToken = $this->ExchangeAuthCodeForAccessToken($authCode, $clientID, $clientSecret);
                if ($accessToken) {
                    $this->WriteAttributeString('AccessToken', $accessToken);
                    echo "Der Authentifizierungscode wurde erfolgreich gegen ein Access Token ausgetauscht.";
                } else {
                    echo "Fehler beim Austausch des Authentifizierungscodes gegen ein Access Token.";
                }
            } else {
                echo "Fehler beim Generieren des Authentifizierungscodes.";
            }
        } else {
            echo "Bitte geben Sie Ihre E-Mail, Ihr Passwort, die Client ID und das Client Secret ein.";
        }
    }

    private function GenerateAuthCode($email, $password, $clientID, $clientSecret) {
        // Logik zum
