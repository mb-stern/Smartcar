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
        $this->RegisterAttributeString('CurrentHook', '');
        $this->RegisterTimer('TokenRefreshTimer', 0, 'SC_Configurator_RefreshAccessToken($id);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $hookPath = $this->RegisterHook();
        $this->WriteAttributeString('CurrentHook', $hookPath);

        if ($this->ReadAttributeString('AccessToken') && $this->ReadAttributeString('RefreshToken')) {
            $this->SetTimerInterval('TokenRefreshTimer', 90 * 60 * 1000);
        } else {
            $this->SetTimerInterval('TokenRefreshTimer', 0);
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $hookPath = $this->ReadAttributeString('CurrentHook');
        $connectAddress = $this->GetConnectURL();

        $form['elements'][] = [
            'type' => 'Label',
            'caption' => 'Redirect URI: ' . $connectAddress . $hookPath
        ];

        return json_encode($form);
    }

    public function FetchVehicles()
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        if (empty($accessToken)) {
            echo "Fehler: Kein gültiges Access Token verfügbar!";
            $this->SendDebug('FetchVehicles', 'Access Token ist nicht vorhanden!', 0);
            return;
        }

        $url = "https://api.smartcar.com/v2.0/vehicles";
        $response = $this->SendHTTPRequest($url, 'GET', '', ["Authorization: Bearer $accessToken"]);

        if (!isset($response['vehicles']) || !is_array($response['vehicles'])) {
            $this->SendDebug('FetchVehicles', 'Fehlerhafte API-Antwort: ' . json_encode($response), 0);
            echo "Fehler: Keine Fahrzeuge gefunden!";
            return;
        }

        $vehicles = [];
        foreach ($response['vehicles'] as $vehicleID) {
            $vehicles[] = [
                'id'    => $vehicleID,
                'make'  => 'Unbekannt',
                'model' => 'Unbekannt',
                'year'  => 'Unbekannt'
            ];
        }

        $this->UpdateFormField('Vehicles', 'values', json_encode($vehicles));
        echo "Fahrzeug-IDs erfolgreich abgerufen!";
    }

    public function CreateVehicleInstance($vehicleID)
    {
        $instanceID = IPS_CreateInstance('{GUID_FUER_SMARTCAR_VEHICLE}');
        IPS_SetName($instanceID, "Smartcar Vehicle: $vehicleID");
        IPS_SetProperty($instanceID, 'VehicleID', $vehicleID);
        IPS_ApplyChanges($instanceID);
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

        $this->SendDebug('RegisterHook', "Hook '$hookPath' wurde erfolgreich registriert.", 0);
        return $hookPath;
    }

    private function GetConnectURL()
    {
        $connectInstances = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}');
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
        $response = @file_get_contents($url, false, $context);

        return json_decode($response, true);
    }
}
