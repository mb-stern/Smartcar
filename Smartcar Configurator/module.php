<?php

class SmartcarConfigurator extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Allgemeine Eigenschaften
        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeString('CurrentHook', '');
        $this->RegisterTimer('TokenRefreshTimer', 0, 'SC_Configurator_RefreshAccessToken($id);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Webhook einrichten
        $hookPath = $this->RegisterHook();
        $this->WriteAttributeString('CurrentHook', $hookPath);

        // Token-Erneuerung aktivieren, falls Token vorhanden
        if ($this->ReadAttributeString('AccessToken') && $this->ReadAttributeString('RefreshToken')) {
            $this->SetTimerInterval('TokenRefreshTimer', 90 * 60 * 1000); // Alle 90 Minuten
        } else {
            $this->SetTimerInterval('TokenRefreshTimer', 0);
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Redirect-URI dynamisch einfügen
        $hookPath = $this->ReadAttributeString('CurrentHook');
        $connectAddress = $this->GetConnectURL();
        $form['elements'][] = [
            'type' => 'Label',
            'caption' => 'Redirect URI: ' . $connectAddress . $hookPath
        ];

        return json_encode($form);
    }

    public function GenerateAuthURL()
    {
        $clientID = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $hookPath = $this->ReadAttributeString('CurrentHook');
        $redirectURI = $this->GetConnectURL() . $hookPath;

        if (empty($clientID) || empty($clientSecret)) {
            echo 'Client ID oder Client Secret ist nicht gesetzt!';
            return;
        }

        $scopes = [
            'read_vehicle_info',
            'read_location',
            'read_odometer',
            'read_battery',
            'read_charge',
            'control_charge',
            'control_security'
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

    public function ProcessHookData()
    {
        if (!isset($_GET['code'])) {
            echo 'Fehler: Kein Autorisierungscode erhalten.';
            return;
        }

        $authCode = $_GET['code'];
        $this->RequestAccessToken($authCode);
        echo 'Smartcar erfolgreich verbunden!';
    }

    private function RequestAccessToken($authCode)
    {
        $clientID = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $redirectURI = $this->GetConnectURL() . $this->ReadAttributeString('CurrentHook');

        $url = 'https://auth.smartcar.com/oauth/token';
        $postData = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $authCode,
            'redirect_uri' => $redirectURI,
            'client_id' => $clientID,
            'client_secret' => $clientSecret
        ]);

        $response = $this->SendHTTPRequest($url, 'POST', $postData);

        if (isset($response['access_token']) && isset($response['refresh_token'])) {
            $this->WriteAttributeString('AccessToken', $response['access_token']);
            $this->WriteAttributeString('RefreshToken', $response['refresh_token']);
        }
    }

    public function FetchVehicles()
    {
        $accessToken = $this->ReadPropertyString('AccessToken');
    
        if (empty($accessToken)) {
            $this->SendDebug('FetchVehicles', 'Access Token ist leer!', 0);
            echo "Fehler: Kein Access Token vorhanden!";
            return;
        }
    
        $url = "https://api.smartcar.com/v2.0/vehicles";
    
        $options = [
            'http' => [
                'header'  => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method'  => 'GET',
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
    
        // Fahrzeugdaten aufbereiten
        $vehicleData = [];
        foreach ($data['vehicles'] as $vehicleID) {
            $vehicleData[] = [
                'id'    => $vehicleID,
                'make'  => 'Unbekannt', // Wird später ersetzt
                'model' => 'Unbekannt', // Wird später ersetzt
                'year'  => 0            // Wird später ersetzt
            ];
        }
    
        // Fahrzeugdaten in die Liste schreiben
        $this->UpdateFormField('Vehicles', 'values', json_encode($vehicleData));
        echo "Fahrzeuge erfolgreich abgerufen!";
    }
    

    public function CreateVehicleInstance($vehicleID)
    {
        $instanceID = IPS_CreateInstance('{GUID_FUER_SMARTCAR_VEHICLE}'); // GUID der Vehicle-Klasse
        IPS_SetName($instanceID, "Smartcar Vehicle: $vehicleID");
        IPS_SetProperty($instanceID, 'VehicleID', $vehicleID);
        IPS_ApplyChanges($instanceID);
    }

    private function FetchVehicleDetails($vehicleID)
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        if (!$accessToken) {
            return null;
        }

        $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID";
        $response = $this->SendHTTPRequest($url, 'GET', '', ["Authorization: Bearer $accessToken"]);

        return [
            'id' => $vehicleID,
            'make' => $response['make'] ?? '',
            'model' => $response['model'] ?? '',
            'year' => $response['year'] ?? 0
        ];
    }

    private function SendHTTPRequest($url, $method, $data = '', $headers = [])
    {
        $options = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $data,
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        return json_decode($response, true);
    }

    private function RegisterHook()
    {
        $hookBase = '/hook/smartcar_configurator';
        $hookPath = $hookBase . $this->InstanceID;
    
        // Symcon-WebHook-Instanz finden
        $webhookInstances = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}'); // GUID der WebHook-Control-Instanz
        if (count($webhookInstances) === 0) {
            $this->SendDebug('RegisterHook', 'Keine WebHook-Control-Instanz gefunden.', 0);
            return false;
        }
    
        $webhookID = $webhookInstances[0];
        $hooks = json_decode(IPS_GetProperty($webhookID, 'Hooks'), true);
    
        if (!is_array($hooks)) {
            $hooks = [];
        }
    
        // Prüfen, ob der Hook bereits existiert
        foreach ($hooks as $hook) {
            if ($hook['Hook'] === $hookPath) {
                $this->SendDebug('RegisterHook', "Hook '$hookPath' ist bereits registriert.", 0);
                return $hookPath;
            }
        }
    
        // Neuen Hook hinzufügen
        $hooks[] = [
            'Hook' => $hookPath,
            'TargetID' => $this->InstanceID
        ];
    
        IPS_SetProperty($webhookID, 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($webhookID);
    
        $this->SendDebug('RegisterHook', "Hook '$hookPath' wurde erfolgreich registriert.", 0);
        return $hookPath;
    }
    
    private function GetConnectURL()
    {
        $connectInstances = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}'); // GUID der Symcon-Connect-Instanz
        if (count($connectInstances) === 0) {
            $this->SendDebug('GetConnectURL', 'Keine Symcon-Connect-Instanz gefunden.', 0);
            return false;
        }
    
        $connectID = $connectInstances[0];
        $connectURL = CC_GetUrl($connectID);
    
        if ($connectURL === false || empty($connectURL)) {
            $this->SendDebug('GetConnectURL', 'Connect-Adresse konnte nicht ermittelt werden.', 0);
            return false;
        }
    
        $this->SendDebug('GetConnectURL', "Ermittelte Connect-Adresse: $connectURL", 0);
        return $connectURL;
    }
    
}
