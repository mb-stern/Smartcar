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

        $this->RegisterTimer('UpdateTimer', 0, 'RefreshAccessToken(' . $this->InstanceID . ');');  

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
                //$this->SetTimerInterval('TokenRefreshTimer', 90 * 60 * 1000); // Alle 90 Minuten
                $this->SetTimerInterval('TokenRefreshTimer', 60 * 1000); // Alle Minuten
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
        if (!$this->ReadPropertyBoolean('ScopeReadVehicleInfo')) {
            $this->SendDebug('FetchVehicleData', 'Scope "read_vehicle_info" nicht aktiviert.', 0);
            return;
        }
    
        $accessToken = $this->ReadAttributeString('AccessToken');
    
        if (empty($accessToken)) {
            $this->SendDebug('FetchVehicleData', 'Kein Access Token vorhanden.', 0);
            $this->LogMessage('Fahrzeugdaten konnten nicht abgerufen werden.', KL_ERROR);
            return;
        }
    
        $url = "https://api.smartcar.com/v2.0/vehicles";
    
        $options = [
            'http' => [
                'header' => [
                    "Authorization: Bearer $accessToken",
                    "Content-Type: application/json"
                ],
                'method' => 'GET',
                'ignore_errors' => true
            ]
        ];
    
        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
    
        $httpResponseHeader = $http_response_header ?? [];
        $httpStatus = isset($httpResponseHeader[0]) ? $httpResponseHeader[0] : "Unbekannt";
        $this->SendDebug('FetchVehicleData', "HTTP-Status: $httpStatus", 0);
    
        if ($response === false) {
            $this->SendDebug('FetchVehicleData', 'Fehler: Keine Antwort von der API!', 0);
            $this->LogMessage('Fahrzeugdaten konnten nicht abgerufen werden.', KL_ERROR);
            return;
        }
    
        $data = json_decode($response, true);
        $this->SendDebug('FetchVehicleData', 'Antwort: ' . json_encode($data), 0);
    
        if (isset($data['vehicles'][0])) {
            $vehicleID = $data['vehicles'][0];
            $this->SendDebug('FetchVehicleData', "Fahrzeug-ID erhalten: $vehicleID", 0);
            $this->FetchVehicleDetails($vehicleID);
        } else {
            $this->SendDebug('FetchVehicleData', 'Keine Fahrzeugdetails gefunden!', 0);
        }
    }
    
    private function FetchVehicleDetails(string $vehicleID)
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
    
        if (empty($accessToken) || empty($vehicleID)) {
            $this->SendDebug('FetchVehicleDetails', 'Access Token oder Fahrzeug-ID fehlt!', 0);
            $this->LogMessage('Fahrzeugdetails konnten nicht abgerufen werden.', KL_ERROR);
            return;
        }
    
        // Fahrzeugdetails abrufen
        $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID";
    
        $options = [
            'http' => [
                'header' => [
                    "Authorization: Bearer $accessToken",
                    "Content-Type: application/json"
                ],
                'method' => 'GET',
                'ignore_errors' => true
            ]
        ];
    
        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
    
        $httpResponseHeader = $http_response_header ?? [];
        $httpStatus = isset($httpResponseHeader[0]) ? $httpResponseHeader[0] : "Unbekannt";
        $this->SendDebug('FetchVehicleDetails', "HTTP-Status: $httpStatus", 0);
    
        if ($response === false) {
            $this->SendDebug('FetchVehicleDetails', 'Fehler: Keine Antwort von der API!', 0);
            $this->LogMessage('Fahrzeugdetails konnten nicht abgerufen werden.', KL_ERROR);
            return;
        }
    
        $data = json_decode($response, true);
        $this->SendDebug('FetchVehicleDetails', 'Fahrzeugdetails: ' . json_encode($data), 0);
    
        if (isset($data['make'], $data['model'], $data['year'], $data['id'])) {
            // Fahrzeugdetails-Variablen erstellen
            $this->MaintainVariable('VehicleID', 'Fahrzeug-ID', VARIABLETYPE_STRING, '', 1, true);
            $this->SetValue('VehicleID', $data['id']);
    
            $this->MaintainVariable('Make', 'Hersteller', VARIABLETYPE_STRING, '', 2, true);
            $this->SetValue('Make', $data['make']);
    
            $this->MaintainVariable('Model', 'Modell', VARIABLETYPE_STRING, '', 3, true);
            $this->SetValue('Model', $data['model']);
    
            $this->MaintainVariable('Year', 'Baujahr', VARIABLETYPE_INTEGER, '', 4, true);
            $this->SetValue('Year', intval($data['year']));
        } else {
            $this->SendDebug('FetchVehicleDetails', 'Fahrzeugdetails nicht gefunden!', 0);
        }
    
        // Scopes überprüfen und Daten abrufen
        if ($this->ReadPropertyBoolean('ScopeReadTires')) {
            $this->FetchTirePressure($vehicleID);
        }
    
        if ($this->ReadPropertyBoolean('ScopeReadOdometer')) {
            $this->FetchOdometer($vehicleID);
        }
    
        if ($this->ReadPropertyBoolean('ScopeReadBattery')) {
            $this->FetchBattery($vehicleID);
        }
    
        if ($this->ReadPropertyBoolean('ScopeReadLocation')) {
            $this->FetchLocation($vehicleID);
        }
    }    

private function FetchTirePressure(string $vehicleID)
{
    if (!$this->ReadPropertyBoolean('ScopeReadTires')) {
        $this->SendDebug('FetchTirePressure', 'Scope "read_tires" nicht aktiviert.', 0);
        return;
    }

    $accessToken = $this->ReadAttributeString('AccessToken');

    if (empty($accessToken) || empty($vehicleID)) {
        $this->SendDebug('FetchTirePressure', 'Access Token oder Fahrzeug-ID fehlt!', 0);
        $this->LogMessage('Reifendruck konnte nicht abgerufen werden.', KL_ERROR);
        return;
    }

    $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/tires/pressure";

    $options = [
        'http' => [
            'header' => [
                "Authorization: Bearer $accessToken",
                "Content-Type: application/json"
            ],
            'method' => 'GET',
            'ignore_errors' => true
        ]
    ];

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    $httpResponseHeader = $http_response_header ?? [];
    $httpStatus = isset($httpResponseHeader[0]) ? $httpResponseHeader[0] : "Unbekannt";
    $this->SendDebug('FetchTirePressure', "HTTP-Status: $httpStatus", 0);

    if ($response === false) {
        $this->SendDebug('FetchTirePressure', 'Fehler: Keine Antwort von der API!', 0);
        $this->LogMessage('Reifendruck konnte nicht abgerufen werden.', KL_ERROR);
        return;
    }

    $data = json_decode($response, true);
    $this->SendDebug('FetchTirePressure', 'Reifendruck: ' . json_encode($data), 0);

    if (isset($data['frontLeft'], $data['frontRight'], $data['backLeft'], $data['backRight'])) {
        // Reifendruck-Variablen erstellen
        $this->MaintainVariable('TireFrontLeft', 'Reifendruck Vorderreifen Links', VARIABLETYPE_FLOAT, 'SMCAR.Pressure', 10, true);
        $this->SetValue('TireFrontLeft', $data['frontLeft'] / 100);
    
        $this->MaintainVariable('TireFrontRight', 'Reifendruck Vorderreifen Rechts', VARIABLETYPE_FLOAT, 'SMCAR.Pressure', 11, true);
        $this->SetValue('TireFrontRight', $data['frontRight'] / 100);
    
        $this->MaintainVariable('TireBackLeft', 'Reifendruck Hinterreifen Links', VARIABLETYPE_FLOAT, 'SMCAR.Pressure', 12, true);
        $this->SetValue('TireBackLeft', $data['backLeft'] / 100);
    
        $this->MaintainVariable('TireBackRight', 'Reifendruck Hinterreifen Rechts', VARIABLETYPE_FLOAT, 'SMCAR.Pressure', 13, true);
        $this->SetValue('TireBackRight', $data['backRight'] / 100);
    } else {
        $this->SendDebug('FetchTirePressure', 'Reifendruckdaten nicht gefunden!', 0);
    }
}

private function FetchOdometer(string $vehicleID)
{
    if (!$this->ReadPropertyBoolean('ScopeReadOdometer')) {
        $this->SendDebug('FetchOdometer', 'Scope "read_odometer" nicht aktiviert.', 0);
        return;
    }

    $accessToken = $this->ReadAttributeString('AccessToken');

    if (empty($accessToken) || empty($vehicleID)) {
        $this->SendDebug('FetchOdometer', 'Access Token oder Fahrzeug-ID fehlt!', 0);
        $this->LogMessage('Kilometerstand konnte nicht abgerufen werden.', KL_ERROR);
        return;
    }

    $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/odometer";

    $options = [
        'http' => [
            'header' => [
                "Authorization: Bearer $accessToken",
                "Content-Type: application/json"
            ],
            'method' => 'GET',
            'ignore_errors' => true
        ]
    ];

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    $httpResponseHeader = $http_response_header ?? [];
    $httpStatus = isset($httpResponseHeader[0]) ? $httpResponseHeader[0] : "Unbekannt";
    $this->SendDebug('FetchOdometer', "HTTP-Status: $httpStatus", 0);

    if ($response === false) {
        $this->SendDebug('FetchOdometer', 'Fehler: Keine Antwort von der API!', 0);
        $this->LogMessage('Kilometerstand konnte nicht abgerufen werden.', KL_ERROR);
        return;
    }

    $data = json_decode($response, true);
    $this->SendDebug('FetchOdometer', 'Kilometerstand: ' . json_encode($data), 0);

    if (isset($data['distance'])) {
        $this->MaintainVariable('Odometer', 'Kilometerstand', VARIABLETYPE_FLOAT, '', 20, true);
        $this->SetValue('Odometer', $data['distance']);
    } else {
        $this->SendDebug('FetchOdometer', 'Kilometerstand nicht gefunden!', 0);
    }
}

private function FetchBattery(string $vehicleID)
{
    if (!$this->ReadPropertyBoolean('ScopeReadBattery')) {
        $this->SendDebug('FetchBattery', 'Scope "read_battery" nicht aktiviert.', 0);
        return;
    }

    $accessToken = $this->ReadAttributeString('AccessToken');

    if (empty($accessToken) || empty($vehicleID)) {
        $this->SendDebug('FetchBattery', 'Access Token oder Fahrzeug-ID fehlt!', 0);
        $this->LogMessage('Batteriestatus konnte nicht abgerufen werden.', KL_ERROR);
        return;
    }

    $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/battery";

    $options = [
        'http' => [
            'header' => [
                "Authorization: Bearer $accessToken",
                "Content-Type: application/json"
            ],
            'method' => 'GET',
            'ignore_errors' => true
        ]
    ];

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    $httpResponseHeader = $http_response_header ?? [];
    $httpStatus = isset($httpResponseHeader[0]) ? $httpResponseHeader[0] : "Unbekannt";
    $this->SendDebug('FetchBattery', "HTTP-Status: $httpStatus", 0);

    if ($response === false) {
        $this->SendDebug('FetchBattery', 'Fehler: Keine Antwort von der API!', 0);
        $this->LogMessage('Batteriestatus konnte nicht abgerufen werden.', KL_ERROR);
        return;
    }

    $data = json_decode($response, true);
    $this->SendDebug('FetchBattery', 'Batteriestatus: ' . json_encode($data), 0);

    if (isset($data['range'], $data['percent'])) {
        $this->MaintainVariable('BatteryRange', 'Reichweite (km)', VARIABLETYPE_FLOAT, '', 21, true);
        $this->SetValue('BatteryRange', $data['range']);

        $this->MaintainVariable('BatteryLevel', 'Batterieladestand (%)', VARIABLETYPE_FLOAT, '', 22, true);
        $this->SetValue('BatteryLevel', $data['percent']);
    } else {
        $this->SendDebug('FetchBattery', 'Batteriedaten nicht gefunden!', 0);
    }
}

private function FetchLocation(string $vehicleID)
{
    if (!$this->ReadPropertyBoolean('ScopeReadLocation')) {
        $this->SendDebug('FetchLocation', 'Scope "read_location" nicht aktiviert.', 0);
        return;
    }

    $accessToken = $this->ReadAttributeString('AccessToken');

    if (empty($accessToken) || empty($vehicleID)) {
        $this->SendDebug('FetchLocation', 'Access Token oder Fahrzeug-ID fehlt!', 0);
        $this->LogMessage('Standort konnte nicht abgerufen werden.', KL_ERROR);
        return;
    }

    $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/location";

    $options = [
        'http' => [
            'header' => [
                "Authorization: Bearer $accessToken",
                "Content-Type: application/json"
            ],
            'method' => 'GET',
            'ignore_errors' => true
        ]
    ];

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    $httpResponseHeader = $http_response_header ?? [];
    $httpStatus = isset($httpResponseHeader[0]) ? $httpResponseHeader[0] : "Unbekannt";
    $this->SendDebug('FetchLocation', "HTTP-Status: $httpStatus", 0);

    if ($response === false) {
        $this->SendDebug('FetchLocation', 'Fehler: Keine Antwort von der API!', 0);
        $this->LogMessage('Standort konnte nicht abgerufen werden.', KL_ERROR);
        return;
    }

    $data = json_decode($response, true);
    $this->SendDebug('FetchLocation', 'Standort: ' . json_encode($data), 0);

    if (isset($data['latitude'], $data['longitude'])) {
        $this->MaintainVariable('Latitude', 'Breitengrad', VARIABLETYPE_FLOAT, '', 23, true);
        $this->SetValue('Latitude', $data['latitude']);

        $this->MaintainVariable('Longitude', 'Längengrad', VARIABLETYPE_FLOAT, '', 24, true);
        $this->SetValue('Longitude', $data['longitude']);
    } else {
        $this->SendDebug('FetchLocation', 'Standortdaten nicht gefunden!', 0);
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
