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
        $this->RegisterTimer('TokenRefreshTimer', 0, 'SMCAR_RefreshAccessToken($id);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Webhook einrichten
        $hookPath = $this->RegisterHook();
        $this->WriteAttributeString('CurrentHook', $hookPath);

        // Token-Erneuerung aktivieren, falls Token vorhanden
        if (!empty($this->ReadAttributeString('AccessToken')) && !empty($this->ReadAttributeString('RefreshToken'))) {
            $this->SetTimerInterval('TokenRefreshTimer', 90 * 60 * 1000); // Alle 90 Minuten
        } else {
            $this->SetTimerInterval('TokenRefreshTimer', 0);
        }
    }

    public function FetchVehicles()
    {
        $accessToken = $this->GetAccessToken();
        if (!$accessToken) {
            echo 'Fehler: Kein gültiges Access Token verfügbar!';
            return;
        }

        $url = "https://api.smartcar.com/v2.0/vehicles";
        $response = $this->SendHTTPRequest($url, 'GET', '', ["Authorization: Bearer $accessToken"]);

        if (empty($response['vehicles'])) {
            echo 'Fehler: Keine Fahrzeuge gefunden!';
            return;
        }

        $vehicleData = [];
        foreach ($response['vehicles'] as $vehicleID) {
            $details = $this->FetchVehicleDetails($vehicleID);
            if ($details) {
                $vehicleData[] = $details;
            }
        }

        $this->WriteAttributeString('Vehicles', json_encode($vehicleData));
        $this->ReloadForm();
        echo 'Fahrzeuge erfolgreich abgerufen!';
    }

    private function FetchVehicleDetails($vehicleID)
    {
        $accessToken = $this->GetAccessToken();
        if (!$accessToken) {
            return null;
        }

        $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID";
        $response = $this->SendHTTPRequest($url, 'GET', '', ["Authorization: Bearer $accessToken"]);

        return [
            'id' => $vehicleID,
            'make' => $response['make'] ?? 'Unbekannt',
            'model' => $response['model'] ?? 'Unbekannt',
            'year' => $response['year'] ?? 0
        ];
    }

    public function CreateVehicleInstance($vehicleID)
    {
        $instanceID = IPS_CreateInstance('{GUID_FUER_SMARTCAR_VEHICLE}'); // GUID der Fahrzeug-Klasse
        IPS_SetName($instanceID, "Smartcar Vehicle: $vehicleID");
        IPS_SetProperty($instanceID, 'VehicleID', $vehicleID);

        // Access Token an Fahrzeuginstanz übergeben
        IPS_SetProperty($instanceID, 'AccessToken', $this->ReadAttributeString('AccessToken'));
        IPS_ApplyChanges($instanceID);
    }

    private function GetAccessToken()
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        if (empty($accessToken)) {
            // Versuche, das Token mit dem Refresh Token zu erneuern
            $this->RefreshAccessToken();
            $accessToken = $this->ReadAttributeString('AccessToken');
        }
        return $accessToken;
    }

    private function RefreshAccessToken()
    {
        $refreshToken = $this->ReadAttributeString('RefreshToken');
        if (!$refreshToken) {
            return;
        }

        $clientID = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');

        $url = "https://auth.smartcar.com/oauth/token";
        $postData = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientID,
            'client_secret' => $clientSecret
        ]);

        $response = $this->SendHTTPRequest($url, 'POST', $postData);

        if (!empty($response['access_token']) && !empty($response['refresh_token'])) {
            $this->WriteAttributeString('AccessToken', $response['access_token']);
            $this->WriteAttributeString('RefreshToken', $response['refresh_token']);
        }
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
