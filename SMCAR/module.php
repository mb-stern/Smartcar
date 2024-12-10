<?php

class SMCAR extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('VIN', '');
        $this->RegisterPropertyString('ConnectAddress', '');
        $this->RegisterPropertyString('Mode', 'simulated');

        $this->RegisterPropertyBoolean('ScopeReadVehicleInfo', false);
        $this->RegisterPropertyBoolean('ScopeReadLocation', false);
        $this->RegisterPropertyBoolean('ScopeReadTires', false);
        $this->RegisterPropertyBoolean('ScopeReadOdometer', false);
        $this->RegisterPropertyBoolean('ScopeReadBattery', false);
        $this->RegisterPropertyBoolean('ScopeControlCharge', false);
        $this->RegisterPropertyBoolean('ScopeControlSecurity', false);

        $this->RegisterAttributeString("CurrentHook", "");
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeString('VehicleID', '');

        $this->RegisterTimer('TokenRefreshTimer', 0, 'SMCAR_RefreshAccessToken(' . $this->InstanceID . ');');  
        $this->RegisterTimer('ScopeFetchTimer', 60 * 1000, 'SMCAR_FetchAllData(' . $this->InstanceID . ');');


    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    
        // Sicherstellen, dass der Hook existiert
        $hookPath = $this->ReadAttributeString("CurrentHook");
    
        // Wenn der Hook-Pfad leer ist, initialisiere ihn
        if ($hookPath === "") {
            $hookPath = $this->RegisterHook();
            $this->SendDebug('ApplyChanges', "Die Initialisierung des Hook-Pfades '$hookPath' gestartet.", 0);
        }

            // Timer nur starten, wenn der Access Token vorhanden ist
            $accessToken = $this->ReadAttributeString('AccessToken');
            $refreshToken = $this->ReadAttributeString('RefreshToken');
        
            if (!empty($accessToken) && !empty($refreshToken)) {
                $this->SetTimerInterval('TokenRefreshTimer', 90 * 60 * 1000); // Alle 90 Minuten
                //$this->SetTimerInterval('TokenRefreshTimer', 60 * 1000); // Alle Minuten
                $this->SendDebug('ApplyChanges', 'Token-Erneuerungs-Timer gestartet.', 0);
            } else {
                $this->SetTimerInterval('TokenRefreshTimer', 0); // Timer deaktivieren
                $this->SendDebug('ApplyChanges', 'Token-Erneuerungs-Timer gestoppt.', 0);
            }
    
        //Profile für erstellen
        $this->CreateProfile();
 
    }
    
    private function RegisterHook()
    {

        $hookBase = '/hook/smartcar_';
        $hookPath = $this->ReadAttributeString("CurrentHook");
    
        // Wenn kein Hook registriert ist, einen neuen erstellen
        if ($hookPath === "") {
            $hookPath = $hookBase . $this->InstanceID;
            $this->WriteAttributeString("CurrentHook", $hookPath);
        }
        
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) === 0) {
            $this->SendDebug('RegisterHook', 'Keine WebHook-Control-Instanz gefunden.', 0);
            return $hookPath;
        }
    
        $hookInstanceID = $ids[0];
        $hooks = json_decode(IPS_GetProperty($hookInstanceID, 'Hooks'), true);
    
        if (!is_array($hooks)) {
            $hooks = [];
        }
    
        // Prüfen, ob der Hook bereits existiert
        foreach ($hooks as $hook) {
            if ($hook['Hook'] === $hookPath && $hook['TargetID'] === $this->InstanceID) {
                $this->SendDebug('RegisterHook', "Hook '$hookPath' ist bereits registriert.", 0);
                return $hookPath;
            }
        }
    
        // Neuen Hook hinzufügen
        $hooks[] = ['Hook' => $hookPath, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($hookInstanceID, 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($hookInstanceID);
        $this->SendDebug('RegisterHook', "Hook '$hookPath' wurde registriert.", 0);
        return $hookPath;
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
    
        // Webhook-Pfad dynamisch einfügen
        $hookPath = $this->ReadAttributeString("CurrentHook");
        $webhookElement = [
            "type"    => "Label",
            "caption" => "Webhook: " . $hookPath
        ];
    
        // Webhook-Pfad an den Anfang des Formulars setzen
        array_splice($form['elements'], 0, 0, [$webhookElement]);
    
        return json_encode($form);
    }

    public function GenerateAuthURL()
    {
        $clientID = $this->ReadPropertyString('ClientID');
        $connectAddress = $this->ReadPropertyString('ConnectAddress');
        $mode = $this->ReadPropertyString('Mode');
    
        if (empty($clientID) || empty($connectAddress)) {
            echo "Fehler: Client ID oder Connect-Adresse nicht gesetzt!";
            return;
        }
    
        // Scopes auslesen
        $scopes = [];
    
        if ($this->ReadPropertyBoolean('ScopeReadVehicleInfo')) {
            $scopes[] = 'read_vehicle_info';
        }
        if ($this->ReadPropertyBoolean('ScopeReadLocation')) {
            $scopes[] = 'read_location';
        }
        if ($this->ReadPropertyBoolean('ScopeReadTires')) {
            $scopes[] = 'read_tires';
        }
        if ($this->ReadPropertyBoolean('ScopeReadOdometer')) {
            $scopes[] = 'read_odometer';
        }
        if ($this->ReadPropertyBoolean('ScopeReadBattery')) {
            $scopes[] = 'read_battery';
        }
        if ($this->ReadPropertyBoolean('ScopeControlCharge')) {
            $scopes[] = 'control_charge';
        }
        if ($this->ReadPropertyBoolean('ScopeControlSecurity')) {
            $scopes[] = 'control_security';
        }
    
        if (empty($scopes)) {
            echo "Fehler: Keine Scopes ausgewählt!";
            return;
        }
    
        // Redirect-URI zusammensetzen
        $redirectURI = rtrim($connectAddress, '/') . $this->ReadAttributeString("CurrentHook");
    
        // URL generieren
        $authURL = "https://connect.smartcar.com/oauth/authorize?" .
            "response_type=code" .
            "&client_id=$clientID" .
            "&redirect_uri=" . urlencode($redirectURI) .
            "&scope=" . urlencode(implode(' ', $scopes)) .
            "&state=" . bin2hex(random_bytes(8)) .
            "&mode=$mode";
    
        $this->SendDebug('GenerateAuthURL', "Erstellte URL: $authURL", 0);
    
        echo "Bitte besuchen Sie die folgende URL, um Ihr Fahrzeug zu verbinden:\n$authURL";
    }
    
    public function ProcessHookData()
    {
        if (!isset($_GET['code'])) {
            $this->SendDebug('ProcessHookData', 'Kein Autorisierungscode erhalten.', 0);
            http_response_code(400);
            echo "Fehler: Kein Code erhalten.";
            return;
        }
    
        $authCode = $_GET['code'];
        $state = $_GET['state'] ?? '';
    
        $this->SendDebug('ProcessHookData', "Autorisierungscode erhalten: $authCode, State: $state", 0);
    
        // Tausche den Code gegen Access Token
        $this->RequestAccessToken($authCode);
    
        echo "Fahrzeug erfolgreich verbunden!";
    }
    
    private function RequestAccessToken(string $authCode)
    {
        $clientID = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $redirectURI = rtrim($this->ReadPropertyString('ConnectAddress'), '/') . $this->ReadAttributeString("CurrentHook");
    
        $url = "https://auth.smartcar.com/oauth/token";
    
        $postData = http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $authCode,
            'redirect_uri'  => $redirectURI,
            'client_id'     => $clientID,
            'client_secret' => $clientSecret
        ]);
    
        $options = [
            'http' => [
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
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
            $this->SendDebug('RequestAccessToken', 'Access und Refresh Token gespeichert!', 0);
    
            // Wende Änderungen an, um den Timer zu starten
            $this->ApplyChanges(); 
        } else {
            $this->SendDebug('RequestAccessToken', 'Token-Austausch fehlgeschlagen!', 0);
            $this->LogMessage('Token-Austausch fehlgeschlagen.', KL_ERROR);
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
            $this->SendDebug('RefreshAccessToken', 'Token erfolgreich erneuert.', 0);
        } else {
            $this->SendDebug('RefreshAccessToken', 'Token-Erneuerung fehlgeschlagen!', 0);
        }
    }
    
    
    public function FetchAllData()
    {
        $this->SendDebug('FetchAllData', 'Timer ausgelöst. Starte Fahrzeugdatenabfrage...', 0);
    
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID = $this->ReadAttributeString('VehicleID');
    
        if (empty($accessToken) || empty($vehicleID)) {
            $this->SendDebug('FetchAllData', 'Access Token oder Fahrzeug-ID fehlt!', 0);
            return;
        }
    
        $endpoints = [
            'read_vehicle_info' => ["https://api.smartcar.com/v2.0/vehicles/$vehicleID", 'HandleVehicleInfo'],
            'read_location'     => ["https://api.smartcar.com/v2.0/vehicles/$vehicleID/location", 'HandleLocation'],
            'read_tires'        => ["https://api.smartcar.com/v2.0/vehicles/$vehicleID/tires/pressure", 'HandleTirePressure'],
            'read_odometer'     => ["https://api.smartcar.com/v2.0/vehicles/$vehicleID/odometer", 'HandleOdometer'],
            'read_battery'      => ["https://api.smartcar.com/v2.0/vehicles/$vehicleID/battery", 'HandleBattery']
        ];
    
        foreach ($endpoints as $scope => [$url, $callback]) {
            if ($this->IsScopeEnabled($scope)) {
                $this->FetchEndpointData($url, $callback);
            }
        }
    }
    
    private function FetchEndpointData(string $url, string $callback)
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
    
        $options = [
            'http' => [
                'header'        => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method'        => 'GET',
                'ignore_errors' => true
            ]
        ];
    
        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
    
        if ($response === false) {
            $this->SendDebug($callback, "Fehler: Keine Antwort von der API für $url!", 0);
            return;
        }
    
        $httpStatus = $http_response_header[0] ?? "Unbekannt";
        $this->SendDebug('FetchEndpointData', "HTTP-Status: $httpStatus", 0);
    
        $data = json_decode($response, true);
        if ($data === null) {
            $this->SendDebug($callback, 'Ungültige JSON-Daten!', 0);
            return;
        }
    
        $this->$callback($data);
    }
    
    private function IsScopeEnabled(string $scope): bool
    {
        $scopeMapping = [
            'read_vehicle_info' => 'ScopeReadVehicleInfo',
            'read_location'     => 'ScopeReadLocation',
            'read_tires'        => 'ScopeReadTires',
            'read_odometer'     => 'ScopeReadOdometer',
            'read_battery'      => 'ScopeReadBattery'
        ];
    
        return $this->ReadPropertyBoolean($scopeMapping[$scope] ?? '');
    }
    

private function CreateProfile()
{
    $profileName = 'SMCAR.Pressure';

    // Profil nur erstellen, wenn es noch nicht existiert
    if (!IPS_VariableProfileExists($profileName)) {
        IPS_CreateVariableProfile($profileName, VARIABLETYPE_FLOAT);
        IPS_SetVariableProfileText($profileName, '', ' bar');
        IPS_SetVariableProfileDigits('SMCAR.Pressure', 2);
        IPS_SetVariableProfileValues($profileName, 0, 10, 0.01);
        $this->SendDebug('CreatePressureProfile', 'Profil erstellt: ' . $profileName, 0);
    } else {
        $this->SendDebug('CreatePressureProfile', 'Profil existiert bereits: ' . $profileName, 0);
    }
}

}
