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
    
    
    public function FetchVehicleData()
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID = $this->ReadAttributeString('VehicleID');
    
        if (empty($accessToken) || empty($vehicleID)) {
            $this->SendDebug('FetchAllData', 'Access Token oder Fahrzeug-ID fehlt!', 0);
            return;
        }
    
        // Sammle die aktivierten Endpunkte
        $endpoints = [];
        if ($this->ReadPropertyBoolean('ScopeReadVehicleInfo')) {
            $endpoints[] = ["path" => "/"];
        }
        if ($this->ReadPropertyBoolean('ScopeReadLocation')) {
            $endpoints[] = ["path" => "/location"];
        }
        if ($this->ReadPropertyBoolean('ScopeReadTires')) {
            $endpoints[] = ["path" => "/tires/pressure"];
        }
        if ($this->ReadPropertyBoolean('ScopeReadOdometer')) {
            $endpoints[] = ["path" => "/odometer"];
        }
        if ($this->ReadPropertyBoolean('ScopeReadBattery')) {
            $endpoints[] = ["path" => "/battery"];
        }
    
        if (empty($endpoints)) {
            $this->SendDebug('FetchAllData', 'Keine Scopes aktiviert!', 0);
            return;
        }
    
        // Erstelle den Batch-Request
        $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/batch";
        $postData = json_encode(["requests" => $endpoints]);
    
        $options = [
            'http' => [
                'header'  => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method'  => 'POST',
                'content' => $postData,
                'ignore_errors' => true
            ]
        ];
    
        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
    
        if ($response === false) {
            $this->SendDebug('FetchAllData', 'Fehler: Keine Antwort von der API!', 0);
            return;
        }
    
        $data = json_decode($response, true);
        $this->SendDebug('FetchAllData', 'Antwort: ' . json_encode($data), 0);
    
        // Verarbeite die Antwort
        if (isset($data['responses']) && is_array($data['responses'])) {
            foreach ($data['responses'] as $response) {
                if ($response['code'] === 200 && isset($response['body'])) {
                    $this->ProcessResponse($response['path'], $response['body']);
                } else {
                    $this->SendDebug('FetchAllData', "Fehlerhafte Antwort: " . json_encode($response), 0);
                }
            }
        } else {
            $this->SendDebug('FetchAllData', 'Unerwartete Antwortstruktur: ' . json_encode($data), 0);
        }
    }
    
    // Neue Funktion zum Verarbeiten der Antworten
    private function ProcessResponse(string $path, array $body)
    {
        switch ($path) {
            case '/':
                $this->MaintainVariable('VehicleMake', 'Fahrzeug Hersteller', VARIABLETYPE_STRING, '', 1, true);
                $this->MaintainVariable('VehicleModel', 'Fahrzeug Modell', VARIABLETYPE_STRING, '', 2, true);
                $this->MaintainVariable('VehicleYear', 'Fahrzeug Baujahr', VARIABLETYPE_INTEGER, '', 3, true);
                $this->SetValue('VehicleMake', $body['make'] ?? '');
                $this->SetValue('VehicleModel', $body['model'] ?? '');
                $this->SetValue('VehicleYear', $body['year'] ?? 0);
                break;
    
            case '/location':
                $this->MaintainVariable('Latitude', 'Breitengrad', VARIABLETYPE_FLOAT, '', 10, true);
                $this->MaintainVariable('Longitude', 'Längengrad', VARIABLETYPE_FLOAT, '', 11, true);
                $this->SetValue('Latitude', $body['latitude'] ?? 0.0);
                $this->SetValue('Longitude', $body['longitude'] ?? 0.0);
                break;
    
            case '/tires/pressure':
                $this->MaintainVariable('TireFrontLeft', 'Reifendruck Vorderreifen Links', VARIABLETYPE_FLOAT, 'SMCAR.Pressure', 20, true);
                $this->MaintainVariable('TireFrontRight', 'Reifendruck Vorderreifen Rechts', VARIABLETYPE_FLOAT, 'SMCAR.Pressure', 21, true);
                $this->MaintainVariable('TireBackLeft', 'Reifendruck Hinterreifen Links', VARIABLETYPE_FLOAT, 'SMCAR.Pressure', 22, true);
                $this->MaintainVariable('TireBackRight', 'Reifendruck Hinterreifen Rechts', VARIABLETYPE_FLOAT, 'SMCAR.Pressure', 23, true);
                $this->SetValue('TireFrontLeft', $body['frontLeft'] ?? 0);
                $this->SetValue('TireFrontRight', $body['frontRight'] ?? 0);
                $this->SetValue('TireBackLeft', $body['backLeft'] ?? 0);
                $this->SetValue('TireBackRight', $body['backRight'] ?? 0);
                break;
    
            case '/odometer':
                $this->MaintainVariable('Odometer', 'Kilometerstand', VARIABLETYPE_FLOAT, '', 30, true);
                $this->SetValue('Odometer', $body['distance'] ?? 0);
                break;
    
            case '/battery':
                $this->MaintainVariable('BatteryRange', 'Reichweite', VARIABLETYPE_FLOAT, '', 40, true);
                $this->MaintainVariable('BatteryLevel', 'Batterieladestand', VARIABLETYPE_FLOAT, '', 41, true);
                $this->SetValue('BatteryRange', $body['range'] ?? 0);
                $this->SetValue('BatteryLevel', $body['percent'] ?? 0);
                break;
    
            default:
                $this->SendDebug('ProcessResponse', "Unbekannter Pfad: $path", 0);
        }
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
