<?php

class SmartcarConfigurator extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeString('RedirectURI', '');
        $this->RegisterAttributeString('CurrentHook', '');
        $this->RegisterPropertyString('Mode', 'simulated');

        $this->RegisterTimer('TokenRefreshTimer', 90 * 60 * 1000, 'SMCAR_RefreshAccessToken($id);'); // Alle 90 Minuten

    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $hookPath = $this->RegisterHook();
        $this->WriteAttributeString('CurrentHook', $hookPath);
        
        $connectAddress = $this->GetConnectURL();
        if ($connectAddress !== false) {
            $this->WriteAttributeString('RedirectURI', $connectAddress . $hookPath);
        }

        if ($this->ReadAttributeString('AccessToken') && $this->ReadAttributeString('RefreshToken')) {
            $this->SetTimerInterval('TokenRefreshTimer', 90 * 60 * 1000);
        } else {
            $this->SetTimerInterval('TokenRefreshTimer', 0);
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $form['elements'][] = [
            'type' => 'Label',
            'caption' => 'Redirect URI: ' . $this->ReadAttributeString('RedirectURI')
        ];

        return json_encode($form);
    }

    public function CreateVehicleInstance(int $instanceID, string $vehicleListJSON, string $vehicleID)
    {
        $vehicles = json_decode($vehicleListJSON, true);

        if (empty($vehicles)) {
            $this->SendDebug('CreateVehicleInstance', 'Fahrzeugliste ist leer oder ungültig.', 0);
            echo "Fehler: Fahrzeugliste ist leer oder ungültig.";
            return;
        }

        $selectedVehicle = array_filter($vehicles, fn($v) => $v['id'] === $vehicleID);
        if (empty($selectedVehicle)) {
            $this->SendDebug('CreateVehicleInstance', "Fahrzeug-ID $vehicleID nicht gefunden.", 0);
            echo "Fehler: Fahrzeug-ID $vehicleID nicht gefunden.";
            return;
        }

        $newInstanceID = IPS_CreateInstance('{GUID_FUER_SMARTCAR_VEHICLE}');
        IPS_SetName($newInstanceID, "Smartcar Fahrzeug: $vehicleID");
        IPS_SetProperty($newInstanceID, 'VehicleID', $vehicleID);
        IPS_ApplyChanges($newInstanceID);

        $this->SendDebug('CreateVehicleInstance', "Instanz für Fahrzeug $vehicleID erfolgreich erstellt.", 0);
        echo "Instanz für Fahrzeug $vehicleID erfolgreich erstellt!";
    }

    public function SMCAR_RefreshAccessToken()
{
    $this->SendDebug('SMCAR_RefreshAccessToken', 'Timer ausgelöst.', 0);

    $clientID = $this->ReadPropertyString('ClientID');
    $clientSecret = $this->ReadPropertyString('ClientSecret');
    $refreshToken = $this->ReadAttributeString('RefreshToken');

    if (empty($clientID) || empty($clientSecret) || empty($refreshToken)) {
        $this->SendDebug('SMCAR_RefreshAccessToken', 'Fehler: Fehlende Zugangsdaten!', 0);
        return;
    }

    $url = "https://auth.smartcar.com/oauth/token";

    $postData = http_build_query([
        'grant_type'    => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id'     => $clientID,
        'client_secret' => $clientSecret
    ]);

    $options = [
        'http' => [
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method'        => 'POST',
            'content'       => $postData,
            'ignore_errors' => true
        ]
    ];

    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);
    $responseData = json_decode($response, true);

    if (isset($responseData['access_token'], $responseData['refresh_token'])) {
        $this->WriteAttributeString('AccessToken', $responseData['access_token']);
        $this->WriteAttributeString('RefreshToken', $responseData['refresh_token']);
        $this->SendDebug('SMCAR_RefreshAccessToken', 'Token erfolgreich erneuert.', 0);
    } else {
        $this->SendDebug('SMCAR_RefreshAccessToken', 'Token-Erneuerung fehlgeschlagen!', 0);
    }
}

    private function RegisterHook()
    {
        $hookBase = '/hook/smartcar_configurator';
        $hookPath = $hookBase . $this->InstanceID;

        $webhookInstances = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($webhookInstances) === 0) {
            $this->SendDebug('RegisterHook', 'Keine WebHook-Control-Instanz gefunden.', 0);
            return '';
        }

        $webhookID = $webhookInstances[0];
        $hooks = json_decode(IPS_GetProperty($webhookID, 'Hooks'), true);

        if (!is_array($hooks)) {
            $hooks = [];
        }

        foreach ($hooks as $hook) {
            if ($hook['Hook'] === $hookPath) {
                $this->SendDebug('RegisterHook', "Hook '$hookPath' ist bereits registriert.", 0);
                return $hookPath;
            }
        }

        $hooks[] = [
            'Hook' => $hookPath,
            'TargetID' => $this->InstanceID
        ];

        IPS_SetProperty($webhookID, 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($webhookID);
        $this->SendDebug('RegisterHook', "Hook '$hookPath' erfolgreich registriert.", 0);
        return $hookPath;
    }

    private function GetConnectURL(): string
    {
        $connectInstances = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}');
        if (count($connectInstances) === 0) {
            $this->SendDebug('GetConnectURL', 'Keine Symcon-Connect-Instanz gefunden.', 0);
            return '';
        }

        $connectID = $connectInstances[0];
        $connectURL = CC_GetUrl($connectID);

        if ($connectURL === false || empty($connectURL)) {
            $this->SendDebug('GetConnectURL', 'Connect-Adresse konnte nicht ermittelt werden.', 0);
            return '';
        }

        return $connectURL;
    }
}
