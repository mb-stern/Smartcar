<?php

class MercedesMe extends IPSModule {

    private $clientID = 'b21c1221-a3d7-4d79-b3f8-053d648c13e1';
    private $clientSecret = 'b21c1221-a3d7-4d79-b3f8-053d648c13e1';
    private $hookName = "MercedesMeWebHook";

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

        // WebHook registrieren
        $this->RegisterHook($this->hookName);
    }

    public function ApplyChanges() {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();

        // Überprüfe, ob ein AuthCode vorhanden ist und tausche ihn gegen ein Access Token ein
        $authCode = $this->ReadAttributeString('AuthCode');
        if ($authCode) {
            $this->ExchangeAuthCodeForAccessToken($authCode);
        }
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
        $clientID = $this->ReadPropert
