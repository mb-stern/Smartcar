<?php

class SmartcarConfigurator extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('Mode', 'simulated');
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeString('RedirectURI', '');
        $this->RegisterAttributeString('CurrentHook', '');

        $this->RegisterMessage($this->InstanceID, IPS_KERNELMESSAGE);


        // Timer für Token-Refresh
        $this->RegisterTimer('TokenRefreshTimer', 0, 'SMCAR_RefreshAccessToken($id);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Webhook einrichten
        $hookPath = $this->RegisterHook();
        $this->WriteAttributeString('CurrentHook', $hookPath);

        $connectAddress = $this->GetConnectURL();
        if ($connectAddress !== false) {
            $this->WriteAttributeString('RedirectURI', $connectAddress . $hookPath);
        }

        // Timer aktivieren, wenn Tokens vorhanden sind
        if ($this->ReadAttributeString('AccessToken') && $this->ReadAttributeString('RefreshToken')) {
            $this->SetTimerInterval('TokenRefreshTimer', 90 * 60 * 1000); // Alle 90 Minuten
        } else {
            $this->SetTimerInterval('TokenRefreshTimer', 0);
        }
    }

    public function RequestAction($ident, $value)
    {
        switch ($ident) {
            case 'CreateVehicleInstance':
                $data = json_decode($value, true);
                if (isset($data['vehicleID'])) {
                    $this->CreateVehicleInstance($this->InstanceID, json_encode($this->ReadAttributeString('Vehicles')), $data['vehicleID']);
                } else {
                    $this->SendDebug('RequestAction', 'Fehler: Keine Fahrzeug-ID angegeben.', 0);
                }
                break;

            default:
                throw new Exception('Invalid ident');
        }
    }


    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Redirect URI hinzufügen
        $form['elements'][] = [
            'type' => 'Label',
            'caption' => 'Redirect URI: ' . $this->ReadAttributeString('RedirectURI')
        ];

        return json_encode($form);
    }

    public function GenerateAuthURL()
    {
        $clientID = $this->ReadPropertyString('ClientID');
        $redirectURI = $this->GetRedirectURI();
        $mode = $this->ReadPropertyString('Mode');

        if (empty($clientID) || empty($redirectURI)) {
            echo "Fehler: Client ID oder Redirect URI ist nicht gesetzt!";
            return;
        }

        $scopes = [
            'read_vehicle_info'
        ];

        $authURL = 'https://connect.smartcar.com/oauth/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientID,
            'redirect_uri' => $redirectURI,
            'scope' => implode(' ', $scopes),
            'state' => bin2hex(random_bytes(8)),
            'mode' => $mode
        ]);

        $this->SendDebug('GenerateAuthURL', "Generierte Authentifizierungs-URL: $authURL", 0);
        echo $authURL;
    }

    public function ProcessHookData()
    {
        if (!isset($_GET['code'])) {
            $this->SendDebug('ProcessHookData', 'Kein Autorisierungscode erhalten.', 0);
            echo "Fehler: Kein Code erhalten!";
            return;
        }

        $authCode = $_GET['code'];
        $this->SendDebug('ProcessHookData', "Autorisierten Code erhalten: $authCode", 0);

        // Tausche den Authorization Code gegen Access Token
        $this->RequestAccessToken($authCode);

        echo "Erfolgreich mit Smartcar verbunden!";
    }

    private function RequestAccessToken(string $authCode)
    {
        $clientID = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $redirectURI = $this->GetRedirectURI();

        $url = "https://auth.smartcar.com/oauth/token";

        $postData = [
            'grant_type' => 'authorization_code',
            'code' => $authCode,
            'redirect_uri' => $redirectURI,
            'client_id' => $clientID,
            'client_secret' => $clientSecret
        ];

        $options = [
            'http' => [
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($postData),
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        $responseData = json_decode($response, true);

        if (isset($responseData['access_token'])) {
            $this->WriteAttributeString('AccessToken', $responseData['access_token']);
            $this->WriteAttributeString('RefreshToken', $responseData['refresh_token']);
            $this->SendDebug('RequestAccessToken', 'Access und Refresh Token erfolgreich erhalten.', 0);
        } else {
            $this->SendDebug('RequestAccessToken', 'Fehler beim Abrufen des Tokens: ' . $response, 0);
        }
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

        $postData = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientID,
            'client_secret' => $clientSecret
        ];

        $options = [
            'http' => [
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($postData),
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

        $webhookInstances = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}'); // WebHook-Control GUID
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
        $connectInstances = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}'); // GUID der Symcon-Connect-Instanz
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

    private function GetRedirectURI(): string
    {
        $hookPath = $this->ReadAttributeString('CurrentHook');
        $connectURL = $this->GetConnectURL();

        if (empty($hookPath)) {
            $this->SendDebug('GetRedirectURI', 'Hook-Pfad fehlt.', 0);
            return '';
        }

        if (empty($connectURL)) {
            $this->SendDebug('GetRedirectURI', 'Connect-URL fehlt.', 0);
            return '';
        }

        $redirectURI = $connectURL . $hookPath;
        $this->SendDebug('GetRedirectURI', "Ermittelte Redirect URI: $redirectURI", 0);
        return $redirectURI;
    }

    public function FetchVehicles()
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        if (empty($accessToken)) {
            $this->SendDebug('FetchVehicles', 'Fehler: Kein Access Token vorhanden!', 0);
            echo "Fehler: Kein Access Token vorhanden!";
            return;
        }

        $url = "https://api.smartcar.com/v2.0/vehicles";
        $options = [
            'http' => [
                'header' => "Authorization: Bearer $accessToken\r\n",
                'method' => 'GET',
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        $data = json_decode($response, true);

        if (!isset($data['vehicles'])) {
            $this->SendDebug('FetchVehicles', 'Fehler: Keine Fahrzeuge gefunden!', 0);
            echo "Fehler: Keine Fahrzeuge gefunden!";
            return;
        }

        $vehicles = [];
        foreach ($data['vehicles'] as $vehicleID) {
            $vehicles[] = [
                'id' => $vehicleID,
            ];
        }

        $this->UpdateFormField('Vehicles', 'values', json_encode($vehicles));
    }

    public function CreateVehicleInstance(int $instanceID, string $vehicleListJSON, string $vehicleID)
    {
        $vehicles = json_decode($vehicleListJSON, true);
    
        if (empty($vehicles) || !isset($vehicleID)) {
            $this->SendDebug('CreateVehicleInstance', 'Fehler: Ungültige Fahrzeugdaten!', 0);
            echo "Fehler: Ungültige Fahrzeugdaten!";
            return;
        }
    
        $selectedVehicle = array_filter($vehicles, fn($v) => $v['id'] === $vehicleID);
        if (empty($selectedVehicle)) {
            $this->SendDebug('CreateVehicleInstance', "Fahrzeug-ID $vehicleID nicht gefunden!", 0);
            echo "Fehler: Fahrzeug-ID $vehicleID nicht gefunden!";
            return;
        }
    
        $newInstanceID = IPS_CreateInstance('{GUID_FUER_SMARTCAR_VEHICLE}');
        IPS_SetName($newInstanceID, "Smartcar Fahrzeug: $vehicleID");
        IPS_SetProperty($newInstanceID, 'VehicleID', $vehicleID);
        IPS_ApplyChanges($newInstanceID);
    
        $this->SendDebug('CreateVehicleInstance', "Instanz für Fahrzeug $vehicleID erstellt (ID: $newInstanceID)", 0);
        echo "Instanz für Fahrzeug $vehicleID erfolgreich erstellt!";
    }
    
private function GetSelectedVehicleID()
{
    $formValues = json_decode($this->GetConfigurationForm(), true);
    $vehicles = $formValues['elements']['Vehicles']['values'] ?? [];

    // Füge hier Logik hinzu, um die ausgewählte Fahrzeug-ID zu ermitteln
    // Beispiel: Rückgabe der ersten ID (muss angepasst werden)
    return $vehicles[0]['id'] ?? null;
}

}
