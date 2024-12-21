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

        $this->RegisterTimer('TokenRefreshTimer', 0, 'SC_RefreshAccessToken($id);');
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

        if ($this->ReadAttributeString('AccessToken') && $this->ReadAttributeString('RefreshToken')) {
            $this->SetTimerInterval('TokenRefreshTimer', 90 * 60 * 1000); // Alle 90 Minuten
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
    

    public function RefreshAccessToken()
    {
        $this->SendDebug('RefreshAccessToken', 'Timer ausgelöst.', 0);

        $clientID = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $refreshToken = $this->ReadAttributeString('RefreshToken');

        if (empty($clientID) || empty($clientSecret) || empty($refreshToken)) {
            $this->SendDebug('RefreshAccessToken', 'Fehler: Fehlende Zugangsdaten!', 0);
            return;
        }

        $url = "https://auth.smartcar.com/oauth/token";

        $postData = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientID,
            'client_secret' => $clientSecret
        ]);

        $options = [
            'http' => [
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => $postData,
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        $responseData = json_decode($response, true);

        if (isset($responseData['access_token'], $responseData['refresh_token'])) {
            $this->WriteAttributeString('AccessToken', $responseData['access_token']);
            $this->WriteAttributeString('RefreshToken', $responseData['refresh_token']);
            $this->SendDebug('RefreshAccessToken', 'Token erfolgreich erneuert.', 0);
        } else {
            $this->SendDebug('RefreshAccessToken', 'Token-Erneuerung fehlgeschlagen!', 0);
        }
    }

    private function RegisterHook()
    {
        $hookBase = '/hook/smartcar_configurator';
        $hookPath = $hookBase . $this->InstanceID;

        $webhookInstances = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($webhookInstances) === 0) {
            $this->SendDebug('RegisterHook', 'Keine WebHook-Control-Instanz gefunden.', 0);
            return false;
        }

        $webhookID = $webhookInstances[0];
        $hooks = json_decode(IPS_GetProperty($webhookID, 'Hooks'), true);

        if (!is_array($hooks)) {
            $hooks = [];
        }

        foreach ($hooks as $hook) {
            if ($hook['Hook'] === $hookPath) {
                return $hookPath;
            }
        }

        $hooks[] = [
            'Hook' => $hookPath,
            'TargetID' => $this->InstanceID
        ];

        IPS_SetProperty($webhookID, 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($webhookID);

        return $hookPath;
    }

    public function GenerateAuthURL()
    {
        $clientID = $this->ReadPropertyString('ClientID');
        $redirectURI = $this->GetRedirectURI();
    
        if (empty($clientID) || empty($redirectURI)) {
            echo 'Fehler: Client ID oder Redirect URI ist nicht gesetzt!';
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
            'mode' => 'live'
        ]);
    
        echo $authURL;
    }
    
    private function GetConnectURL()
    {
        $connectInstances = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}');
        if (count($connectInstances) === 0) {
            return false;
        }

        $connectID = $connectInstances[0];
        return CC_GetUrl($connectID);
    }

    public function FetchVehicles()
{
    $accessToken = $this->ReadAttributeString('AccessToken');
    
    if (empty($accessToken)) {
        $this->SendDebug('FetchVehicles', 'Access Token ist leer!', 0);
        echo "Fehler: Kein Access Token vorhanden!";
        return;
    }
    
    $url = "https://api.smartcar.com/v2.0/vehicles";

    $options = [
        'http' => [
            'header' => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
            'method' => 'GET',
            'ignore_errors' => true
        ]
    ];

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        $this->SendDebug('FetchVehicles', 'Fehler: Keine Antwort von der API!', 0);
        echo "Fehler: Keine Antwort von der API!";
        return;
    }

    $data = json_decode($response, true);
    $this->SendDebug('FetchVehicles', 'API-Antwort: ' . json_encode($data), 0);

    if (!isset($data['vehicles']) || !is_array($data['vehicles'])) {
        $this->SendDebug('FetchVehicles', 'Fehler: Keine Fahrzeuge gefunden!', 0);
        echo "Fehler: Keine Fahrzeuge gefunden!";
        return;
    }

    $vehicles = [];
    foreach ($data['vehicles'] as $vehicleID) {
        $vehicles[] = [
            'id'    => $vehicleID,
            'make'  => 'Unbekannt',
            'model' => 'Unbekannt',
            'year'  => 0
        ];
    }

    $this->UpdateFormField('Vehicles', 'values', json_encode($vehicles));
    echo "Fahrzeuge erfolgreich abgerufen!";
}

private function GetRedirectURI(): string
{
    $hookPath = $this->ReadAttributeString('CurrentHook');
    $connectURL = $this->GetConnectURL();

    if (empty($hookPath) || empty($connectURL)) {
        $this->SendDebug('GetRedirectURI', 'Fehler: Hook oder Connect-Adresse ist nicht verfügbar.', 0);
        return '';
    }

    return $connectURL . $hookPath;
}


}
