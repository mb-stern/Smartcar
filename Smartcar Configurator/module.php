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
        $this->RegisterAttributeString('Vehicles', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Webhook einrichten
        $hookPath = $this->RegisterHook();
        $this->WriteAttributeString('CurrentHook', $hookPath);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Redirect-URI hinzufügen
        $hookPath = $this->ReadAttributeString('CurrentHook');
        $connectAddress = $this->GetConnectURL();
        $form['elements'][] = [
            'type' => 'Label',
            'caption' => 'Redirect URI: ' . $connectAddress . $hookPath
        ];

        // Fahrzeuge aus Attribut abrufen
        $vehicles = json_decode($this->ReadAttributeString('Vehicles'), true);
        $form['elements'][4]['values'] = $vehicles;

        return json_encode($form);
    }

    public function GenerateAuthURL()
    {
        $clientID = $this->ReadPropertyString('ClientID');
        $hookPath = $this->ReadAttributeString('CurrentHook');
        $redirectURI = $this->GetConnectURL() . $hookPath;

        if (empty($clientID)) {
            echo 'Client ID ist nicht gesetzt!';
            return;
        }

        $authURL = 'https://connect.smartcar.com/oauth/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientID,
            'redirect_uri' => $redirectURI,
            'scope' => '',
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

        if (isset($response['access_token'], $response['refresh_token'])) {
            $this->WriteAttributeString('AccessToken', $response['access_token']);
            $this->WriteAttributeString('RefreshToken', $response['refresh_token']);
        }
    }

    public function FetchVehicles()
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
    
        if (empty($accessToken)) {
            echo "Fehler: Kein Access Token vorhanden!";
            return;
        }
    
        $url = "https://api.smartcar.com/v2.0/vehicles";
        $response = $this->SendHTTPRequest($url, 'GET', '', ["Authorization: Bearer $accessToken"]);
    
        if (!isset($response['vehicles']) || !is_array($response['vehicles'])) {
            echo "Fehler: Keine Fahrzeuge gefunden!";
            return;
        }
    
        $vehicleData = [];
        foreach ($response['vehicles'] as $vehicleID) {
            $vehicleDetails = $this->FetchVehicleDetails($vehicleID);
            if ($vehicleDetails) {
                $vehicleData[] = $vehicleDetails;
            }
        }
    
        $this->WriteAttributeString('Vehicles', json_encode($vehicleData));
        $this->ReloadForm();
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

    public function CreateVehicleInstance($vehicleID)
    {
        $instanceID = IPS_CreateInstance('{GUID_FUER_SMARTCAR_VEHICLE}'); // GUID der Vehicle-Klasse
        IPS_SetName($instanceID, "Smartcar Vehicle: $vehicleID");
        IPS_SetProperty($instanceID, 'VehicleID', $vehicleID);
        IPS_ApplyChanges($instanceID);
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

        $webhookInstances = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($webhookInstances) === 0) {
            echo 'Keine WebHook-Control-Instanz gefunden!';
            return false;
        }

        $webhookID = $webhookInstances[0];
        $hooks = json_decode(IPS_GetProperty($webhookID, 'Hooks'), true);

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

    private function GetConnectURL()
    {
        $connectInstances = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}');
        if (count($connectInstances) === 0) {
            return false;
        }

        return CC_GetUrl($connectInstances[0]);
    }
}
