<?php

class Smartcar extends IPSModule
{
    public function Create()
    {
        parent::Create();
    
        // Allgemeine Eigenschaften
        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('Mode', 'simulated');
    
        // Scopes für API-Endpunkte
        $this->RegisterPropertyBoolean('ScopeReadVehicleInfo', false);
        $this->RegisterPropertyBoolean('ScopeReadLocation', false);
        $this->RegisterPropertyBoolean('ScopeReadOdometer', false);
        $this->RegisterPropertyBoolean('ScopeReadTires', false);
        $this->RegisterPropertyBoolean('ScopeReadBattery', false);
        $this->RegisterPropertyBoolean('ScopeReadBatteryCapacity', false);
        $this->RegisterPropertyBoolean('ScopeReadFuel', false);
        $this->RegisterPropertyBoolean('ScopeReadSecurity', false);
        $this->RegisterPropertyBoolean('ScopeReadChargeLimit', false);
        $this->RegisterPropertyBoolean('ScopeReadChargeStatus', false);
        $this->RegisterPropertyBoolean('ScopeReadVIN', false);
        $this->RegisterPropertyBoolean('ScopeReadOilLife', false);
    
        // Vorhandene Ansteuerungen (POST-Endpunkte)
        $this->RegisterPropertyBoolean('SetChargeLimit', false);
        $this->RegisterPropertyBoolean('SetChargeStatus', false);
        $this->RegisterPropertyBoolean('SetLockStatus', false);
    
        // Attribute für interne Nutzung
        $this->RegisterAttributeString("CurrentHook", "");
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeString('VehicleID', '');
        $this->RegisterAttributeString('RedirectURI', '');

        $this->RegisterTimer('TokenRefreshTimer', 0, 'SMCAR_RefreshAccessToken(' . $this->InstanceID . ');'); 
    }
    
    public function ApplyChanges()
    {
        parent::ApplyChanges();
    
        // Hook initialisieren
        $hookPath = $this->ReadAttributeString("CurrentHook");
        if ($hookPath === "") {
            $hookPath = $this->RegisterHook();
            $this->SendDebug('ApplyChanges', "Die Initialisierung des Hook-Pfades '$hookPath' gestartet.", 0);
        }

        $accessToken = $this->ReadAttributeString('AccessToken');
        $refreshToken = $this->ReadAttributeString('RefreshToken');
    
        // Timer für Token-Erneuerung (dynamisch)
        if (!empty($accessToken) && !empty($refreshToken)) {
            $this->SetTimerInterval('TokenRefreshTimer', 90 * 60 * 1000); // Alle 90 Minuten
            $this->SendDebug('ApplyChanges', 'Token-Erneuerungs-Timer auf 90 Minuten eingestellt.', 0);
        } else {
            $this->SetTimerInterval('TokenRefreshTimer', 10 * 60 * 1000); // Alle 10 Minuten versuchen, Tokens zu holen
            $this->SendDebug('ApplyChanges', 'Token-Erneuerung-Timer auf 10 Minuten gesetzt (weil Access- oder Refresh-Token fehlt).', 0);
        }

        // Connect-Adresse ermitteln
        $ipsymconconnectid = IPS_GetInstanceListByModuleID("{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}")[0];
        $connectAddress = CC_GetUrl($ipsymconconnectid);
        if ($connectAddress === false || empty($connectAddress)) {
            $connectAddress = "Connect-Adresse konnte nicht ermittelt werden.";
            $this->SendDebug('ApplyChanges', 'Connect-Adresse konnte nicht ermittelt werden.', 0);
        } else {
            $hookPath = $this->ReadAttributeString("CurrentHook");
            $redirectURI = $connectAddress . $hookPath;
            $this->WriteAttributeString('RedirectURI', $redirectURI);
            $this->SendDebug('ApplyChanges', 'Redirect-URI gespeichert.', 0);
        }
    
        // Profile erstellen
        $this->CreateProfile();
        
        // Variablenregistrierung basierend auf aktivierten Scopes
        $this->UpdateVariablesBasedOnScopes();
    }

    public function RequestAction($ident, $value)
    {
        switch ($ident) {
            case 'SetChargeLimit':
                $this->SetChargeLimit($value / 100);
                $this->SetValue($ident, $value);
                break;

            case 'SetChargeStatus':
                $this->SetChargeStatus($value);
                $this->SetValue($ident, $value);
                break;
                
            case 'SetLockStatus':
                $this->SetLockStatus($value);
                $this->SetValue($ident, $value);
                break;

            default:
                throw new Exception("Invalid ident");
        }
    }

    private function UpdateVariablesBasedOnScopes()
    {
        // Fahrzeugdetails
        if ($this->ReadPropertyBoolean('ScopeReadVehicleInfo')) {
            $this->RegisterVariableString('VehicleMake', 'Fahrzeug Hersteller', '', 1);
            $this->RegisterVariableString('VehicleModel', 'Fahrzeug Modell', '', 2);
            $this->RegisterVariableInteger('VehicleYear', 'Fahrzeug Baujahr', '', 3);
        } else {
            $this->UnregisterVariable('VehicleMake');
            $this->UnregisterVariable('VehicleModel');
            $this->UnregisterVariable('VehicleYear');
        }

        // VIN
        if ($this->ReadPropertyBoolean('ScopeReadVIN')) {
            $this->RegisterVariableString('VIN', 'Fahrgestellnummer (VIN)', '', 4);
        } else {
            $this->UnregisterVariable('VIN');
        }

        // Standort
        if ($this->ReadPropertyBoolean('ScopeReadLocation')) {
            $this->RegisterVariableFloat('Latitude', 'Breitengrad', '', 10);
            $this->RegisterVariableFloat('Longitude', 'Längengrad', '', 11);
        } else {
            $this->UnregisterVariable('Latitude');
            $this->UnregisterVariable('Longitude');
        }

        // Kilometerstand
        if ($this->ReadPropertyBoolean('ScopeReadOdometer')) {
            $this->RegisterVariableFloat('Odometer', 'Kilometerstand', 'SMCAR.Odometer', 20);
        } else {
            $this->UnregisterVariable('Odometer');
        }

        // Reifendruck
        if ($this->ReadPropertyBoolean('ScopeReadTires')) {
            $this->RegisterVariableFloat('TireFrontLeft', 'Reifendruck Vorderreifen Links', 'SMCAR.Pressure', 30);
            $this->RegisterVariableFloat('TireFrontRight', 'Reifendruck Vorderreifen Rechts', 'SMCAR.Pressure', 31);
            $this->RegisterVariableFloat('TireBackLeft', 'Reifendruck Hinterreifen Links', 'SMCAR.Pressure', 32);
            $this->RegisterVariableFloat('TireBackRight', 'Reifendruck Hinterreifen Rechts', 'SMCAR.Pressure', 33);
        } else {
            $this->UnregisterVariable('TireFrontLeft');
            $this->UnregisterVariable('TireFrontRight');
            $this->UnregisterVariable('TireBackLeft');
            $this->UnregisterVariable('TireBackRight');
        }

        // Batterie
        if ($this->ReadPropertyBoolean('ScopeReadBattery')) {
            $this->RegisterVariableFloat('BatteryLevel', 'Batterieladestand (SOC)', 'SMCAR.Progress', 40);
            $this->RegisterVariableFloat('BatteryRange', 'Reichweite Batterie', 'SMCAR.Odometer', 41);
        } else {
            $this->UnregisterVariable('BatteryRange');
            $this->UnregisterVariable('BatteryLevel');
        }

        if ($this->ReadPropertyBoolean('ScopeReadBatteryCapacity')) {
            $this->RegisterVariableFloat('BatteryCapacity', 'Batteriekapazität', '~Electricity', 50);
        } else {
            $this->UnregisterVariable('BatteryCapacity');
        }

        // Tank
        if ($this->ReadPropertyBoolean('ScopeReadFuel')) {
            $this->RegisterVariableFloat('FuelLevel', 'Tankfüllstand', 'SMCAR.Progress', 60);
            $this->RegisterVariableFloat('FuelRange', 'Reichweite Tank', 'SMCAR.Odometer', 61);
        } else {
            $this->UnregisterVariable('FuelLevel');
            $this->UnregisterVariable('FuelRange');
        }

        // Security
        if ($this->ReadPropertyBoolean('ScopeReadSecurity')) {
            $this->RegisterVariableBoolean('DoorsLocked', 'Fahrzeug verriegelt', '~Lock', 70);
            
            // Türen
            $this->RegisterVariableString('FrontLeftDoor', 'Vordertür links', 'SMCAR.Status', 71);
            $this->RegisterVariableString('FrontRightDoor', 'Vordertür rechts', 'SMCAR.Status', 72);
            $this->RegisterVariableString('BackLeftDoor', 'Hintentür links', 'SMCAR.Status', 73);
            $this->RegisterVariableString('BackRightDoor', 'Hintentür rechts', 'SMCAR.Status', 74);
        
            // Fenster
            $this->RegisterVariableString('FrontLeftWindow', 'Vorderfenster links', 'SMCAR.Status', 75);
            $this->RegisterVariableString('FrontRightWindow', 'Vorderfenster rechts', 'SMCAR.Status', 76);
            $this->RegisterVariableString('BackLeftWindow', 'Hinterfenster links', 'SMCAR.Status', 77);
            $this->RegisterVariableString('BackRightWindow', 'Hinterfenster rechts', 'SMCAR.Status', 78);
        
            // Schiebedach
            $this->RegisterVariableString('Sunroof', 'Schiebedach', 'SMCAR.Status', 79);
        
            // Stauraum
            $this->RegisterVariableString('RearStorage', 'Stauraum hinten', 'SMCAR.Status', 80);
            $this->RegisterVariableString('FrontStorage', 'Stauraum vorne', 'SMCAR.Status', 81);
        
            // Ladeanschluss
            $this->RegisterVariableString('ChargingPort', 'Ladeanschluss', 'SMCAR.Status', 82);        
        } else {
            $this->UnregisterVariable('DoorsLocked');
            $this->UnregisterVariable('FrontLeftDoor');
            $this->UnregisterVariable('FrontRightDoor');
            $this->UnregisterVariable('BackLeftDoor');
            $this->UnregisterVariable('BackRightDoor');
            $this->UnregisterVariable('FrontLeftWindow');
            $this->UnregisterVariable('FrontRightWindow');
            $this->UnregisterVariable('BackLeftWindow');
            $this->UnregisterVariable('BackRightWindow');
            $this->UnregisterVariable('Sunroof');
            $this->UnregisterVariable('RearStorage');
            $this->UnregisterVariable('FrontStorage');
            $this->UnregisterVariable('ChargingPort');
        }
        
        // Ladeinformationen
        if ($this->ReadPropertyBoolean('ScopeReadChargeLimit')) {
            $this->RegisterVariableFloat('ChargeLimit', 'Aktuelles Ladelimit', 'SMCAR.Progress', 90);
        } else {
            $this->UnregisterVariable('ChargeLimit');
        }

        if ($this->ReadPropertyBoolean('ScopeReadChargeStatus')) {
            $this->RegisterVariableString('ChargeStatus', 'Ladestatus', 'SMCAR.Charge', 91);
            $this->RegisterVariableBoolean('PluggedIn', 'Ladekabel eingesteckt', '~Switch', 92);
        } else {
            $this->UnregisterVariable('ChargeStatus');
            $this->UnregisterVariable('PluggedIn');
        }

        // Ölstatus
        if ($this->ReadPropertyBoolean('ScopeReadOilLife')) {
            $this->RegisterVariableFloat('OilLife', 'Verbleibende Öl-Lebensdauer', 'SMCAR.Progress', 100);
        } else {
            $this->UnregisterVariable('OilLife');
        }

        // Ladelimit setzen
        if ($this->ReadPropertyBoolean('SetChargeLimit')) {
            $this->RegisterVariableFloat('SetChargeLimit', 'Ladelimit setzen', 'SMCAR.Progress', 110);
            $this->EnableAction('SetChargeLimit');
        } else {
            $this->UnregisterVariable('SetChargeLimit');
        }

        // Ladestatus setzen
        if ($this->ReadPropertyBoolean('SetChargeStatus')) {
            $this->RegisterVariableBoolean('SetChargeStatus', 'Ladestatus setzen', '~Switch', 120);
            $this->EnableAction('SetChargeStatus');
        } else {
            $this->UnregisterVariable('SetChargeStatus');
        }

        // Zentralverriegelung setzen
        if ($this->ReadPropertyBoolean('SetLockStatus')) {
            $this->RegisterVariableBoolean('SetLockStatus', 'Zentralverriegelung', '~Lock', 130);
            $this->EnableAction('SetLockStatus');
        } else {
            $this->UnregisterVariable('SetLockStatus');
        }
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
        $connectAddress = $this->ReadAttributeString('RedirectURI');
    
        // Webhook-Pfad dynamisch einfügen
        $webhookElements = [
            [
                "type"    => "Label",
                "caption" => "Redirect-URI: " .$connectAddress
            ],
            [
                "type"    => "Label",
                "caption" => "Diese URI gehört in die Smartcar-Konfiguration."
            ]
        ];
    
        // Webhook-Pfad an den Anfang des Formulars setzen
        array_splice($form['elements'], 0, 0, $webhookElements);
    
        return json_encode($form);
    }
    
    public function GenerateAuthURL()
    {
        $clientID = $this->ReadPropertyString('ClientID');
        $mode = $this->ReadPropertyString('Mode');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $redirectURI = $this->ReadAttributeString('RedirectURI');
    
        if (empty($clientID) || empty($clientSecret)) {
            $this->SendDebug('GenerateAuthURL', 'Fehler: Client ID oder Client Secret ist nicht gesetzt!', 0);
            return "Fehler: Client ID oder Client Secret ist nicht gesetzt!";
        }
    
        // Scopes dynamisch basierend auf aktivierten Endpunkten zusammenstellen
        $scopes = [];
        if ($this->ReadPropertyBoolean('ScopeReadVehicleInfo')) {
            $scopes[] = 'read_vehicle_info';
        }
        if ($this->ReadPropertyBoolean('ScopeReadLocation')) {
            $scopes[] = 'read_location';
        }
        if ($this->ReadPropertyBoolean('ScopeReadOdometer')) {
            $scopes[] = 'read_odometer';
        }
        if ($this->ReadPropertyBoolean('ScopeReadTires')) {
            $scopes[] = 'read_tires';
        }
        if ($this->ReadPropertyBoolean('ScopeReadBattery') || $this->ReadPropertyBoolean('ScopeReadBatteryCapacity')) {
            $scopes[] = 'read_battery';
        }
        if ($this->ReadPropertyBoolean('ScopeReadFuel')) {
            $scopes[] = 'read_fuel';
        }
        if ($this->ReadPropertyBoolean('ScopeReadSecurity')) {
            $scopes[] = 'read_security';
        }
        if ($this->ReadPropertyBoolean('ScopeReadChargeLimit') || $this->ReadPropertyBoolean('ScopeReadChargeStatus')) {
            $scopes[] = 'read_charge';
        }
        if ($this->ReadPropertyBoolean('ScopeReadVIN')) {
            $scopes[] = 'read_vin';
        }
        if ($this->ReadPropertyBoolean('ScopeReadOilLife')) {
            $scopes[] = 'read_engine_oil';
        }
        if ($this->ReadPropertyBoolean('SetChargeLimit') || $this->ReadPropertyBoolean('SetChargeStatus')) {
            $scopes[] = 'control_charge';
        }
        if ($this->ReadPropertyBoolean('SetLockStatus')) {
            $scopes[] = 'control_security';
        }

        if (empty($scopes)) {
            $this->SendDebug('GenerateAuthURL', 'Fehler: Keine Scopes ausgewählt!', 0);
            return "Fehler: Keine Scopes ausgewählt!";
        }
    
        // Generiere die Authentifizierungs-URL
        $authURL = "https://connect.smartcar.com/oauth/authorize?" .
            "response_type=code" .
            "&client_id=" . urlencode($clientID) .
            "&redirect_uri=" . urlencode($redirectURI) .
            "&scope=" . urlencode(implode(' ', $scopes)) .
            "&state=" . bin2hex(random_bytes(8)) .
            "&mode=" . urlencode($mode);
    
        $this->SendDebug('GenerateAuthURL', "Generierte Authentifizierungs-URL: $authURL", 0);
    
        return $authURL;
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
        $redirectURI = $this->ReadAttributeString('RedirectURI');
    
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
        $vehicleID = $this->GetVehicleID($accessToken);
    
        if (empty($accessToken) || empty($vehicleID)) {
            $this->SendDebug('FetchVehicleData', 'Access Token oder Fahrzeug-ID fehlt!', 0);
            return false; // Fehlerstatus zurückgeben
        }
    
        // Sammle die aktivierten Endpunkte
        $endpoints = [];
        if ($this->ReadPropertyBoolean('ScopeReadVehicleInfo')) {
            $endpoints[] = ["path" => "/"];
        }
        if ($this->ReadPropertyBoolean('ScopeReadVIN')) {
            $endpoints[] = ["path" => "/vin"];
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
        if ($this->ReadPropertyBoolean('ScopeReadBatteryCapacity')) {
            $endpoints[] = ["path" => "/battery/capacity"];
        }
        if ($this->ReadPropertyBoolean('ScopeReadFuel')) {
            $endpoints[] = ["path" => "/fuel"];
        }
        if ($this->ReadPropertyBoolean('ScopeReadSecurity')) {
            $endpoints[] = ["path" => "/security"];
        }
        if ($this->ReadPropertyBoolean('ScopeReadChargeLimit')) {
            $endpoints[] = ["path" => "/charge/limit"];
        }
        if ($this->ReadPropertyBoolean('ScopeReadChargeStatus')) {
            $endpoints[] = ["path" => "/charge"];
        }
        if ($this->ReadPropertyBoolean('ScopeReadOilLife')) {
            $endpoints[] = ["path" => "/engine/oil"];
        }
    
        // Filtere leere Einträge
        $endpoints = array_filter($endpoints, fn($endpoint) => !empty($endpoint['path']));
    
        if (empty($endpoints)) {
            $this->SendDebug('FetchVehicleData', 'Keine Scopes aktiviert!', 0);
            return false;
        }
    
        // Erstelle den Batch-Request
        $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/batch";
        $postData = json_encode(["requests" => $endpoints]);
    
        $options = [
            'http' => [
                'header' => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method' => 'POST',
                'content' => $postData,
                'ignore_errors' => true
            ]
        ];
    
        // Debug-Ausgabe für die API-Anfrage
        $this->SendDebug('FetchVehicleData', 'API-Anfrage: ' . json_encode([
            'url'    => $url,
            'method' => $options['http']['method'],
            'header' => $options['http']['header'],
            'body'   => $options['http']['content']
        ], JSON_PRETTY_PRINT), 0);
    
        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
    
        if ($response === false) {
            $this->SendDebug('FetchVehicleData', 'Fehler: Keine Antwort von der API!', 0);
            return false;
        }
    
        // HTTP-Statuscode prüfen
        $httpResponseHeader = $http_response_header ?? [];
        $statusCode = 0;
        foreach ($httpResponseHeader as $header) {
            if (preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $header, $matches)) {
                $statusCode = (int)$matches[1];
                break;
            }
        }
    
        if ($statusCode !== 200) {
            $this->SendDebug('FetchVehicleData', "HTTP-Fehler: $statusCode", 0);
            return false;
        }
    
        $data = json_decode($response, true);
        $this->SendDebug('FetchVehicleData', 'Antwort: ' . json_encode($data), 0);
    
        // Verarbeite die Antwort
        if (isset($data['responses']) && is_array($data['responses'])) {
            foreach ($data['responses'] as $response) {
                if ($response['code'] === 200 && isset($response['body'])) {
                    // Verarbeitung der erfolgreichen Antwort
                    $this->ProcessResponse($response['path'], $response['body']);
                } else {
                    $this->SendDebug('FetchVehicleData', "Fehlerhafte Antwort: " . json_encode($response), 0);
                }
            }
            return true; // Erfolg
        } else {
            $this->SendDebug('FetchVehicleData', 'Unerwartete Antwortstruktur: ' . json_encode($data), 0);
            return false;
        }
    }
    
    private function GetVehicleID(string $accessToken): ?string
    {
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
            $this->SendDebug('GetVehicleID', 'Fehler: Keine Antwort von der API!', 0);
            return null;
        }
    
        $data = json_decode($response, true);
        $this->SendDebug('GetVehicleID', 'Antwort: ' . json_encode($data), 0);
    
        if (isset($data['vehicles'][0])) {
            return $data['vehicles'][0];
        }
    
        $this->SendDebug('GetVehicleID', 'Keine Fahrzeug-ID gefunden!', 0);
        return null;
    }
    
    private function ProcessResponse(string $path, array $body)
    {
        switch ($path) {
            case '/':
                $this->SetValue('VehicleMake', $body['make'] ?? '');
                $this->SetValue('VehicleModel', $body['model'] ?? '');
                $this->SetValue('VehicleYear', $body['year'] ?? 0);
                break;
    
            case '/vin':
                $this->SetValue('VIN', $body['vin'] ?? '');
                break;
    
            case '/location':
                $this->SetValue('Latitude', $body['latitude'] ?? 0.0);
                $this->SetValue('Longitude', $body['longitude'] ?? 0.0);
                break;
    
            case '/tires/pressure':
                $this->SetValue('TireFrontLeft', ($body['frontLeft'] ?? 0) * 0.01);
                $this->SetValue('TireFrontRight', ($body['frontRight'] ?? 0) * 0.01);
                $this->SetValue('TireBackLeft', ($body['backLeft'] ?? 0) * 0.01);
                $this->SetValue('TireBackRight', ($body['backRight'] ?? 0) * 0.01);
                break;
    
            case '/odometer':
                $this->SetValue('Odometer', $body['distance'] ?? 0);
                break;
    
            case '/battery':
                $this->SetValue('BatteryRange', $body['range'] ?? 0);
                $this->SetValue('BatteryLevel', ($body['percentRemaining'] ?? 0) * 100);
                break;
    
            case '/battery/capacity':
                $this->SetValue('BatteryCapacity', $body['capacity'] ?? 0);
                break;
    
            case '/fuel':
                $this->SetValue('FuelLevel', ($body['percentRemaining'] ?? 0) * 100);
                $this->SetValue('FuelRange', $body['range'] ?? 0);
                break;
    
            case '/security':
                $this->SetValue('DoorsLocked', $body['isLocked'] ?? false);
    
                // Türen
                foreach ($body['doors'] as $door) {
                    $ident = ucfirst($door['type']) . 'Door'; // z.B. FrontLeftDoor
                    $this->SetValue($ident, $door['status'] ?? 'UNKNOWN');
                }
    
                // Fenster
                foreach ($body['windows'] as $window) {
                    $ident = ucfirst($window['type']) . 'Window'; // z.B. FrontLeftWindow
                    $this->SetValue($ident, $window['status'] ?? 'UNKNOWN');
                }
    
                // Schiebedach
                $this->SetValue('Sunroof', $body['sunroof'][0]['status'] ?? 'UNKNOWN');
    
                // Stauraum
                foreach ($body['storage'] as $storage) {
                    $ident = ucfirst($storage['type']) . 'Storage'; // z.B. FrontStorage
                    $this->SetValue($ident, $storage['status'] ?? 'UNKNOWN');
                }
    
                // Ladeanschluss
                $this->SetValue('ChargingPort', $body['chargingPort'][0]['status'] ?? 'UNKNOWN');
                break;
    
            case '/charge/limit':
                $this->SetValue('ChargeLimit', ($body['limit'] ?? 0) * 100);
                break;
    
            case '/charge':
                $this->SetValue('ChargeStatus', $body['state'] ?? 'UNKNOWN');
                $this->SetValue('PluggedIn', $body['isPluggedIn'] ?? false);
                break;
    
            default:
                $this->SendDebug('ProcessResponse', "Unbekannter Pfad: $path", 0);
        }
    }

    public function SetChargeLimit(float $limit)
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID = $this->GetVehicleID($accessToken);
        
        if ($limit < 0.5 || $limit > 1.0) {
            $this->SendDebug('SetChargeLimit', 'Ungültiges Limit. Es muss zwischen 0.5 und 1.0 liegen.', 0);
            return;
        }
    
        if (empty($accessToken) || empty($vehicleID)) {
            $this->SendDebug('SetChargeLimit', 'Access Token oder Fahrzeug-ID fehlt!', 0);
            return;
        }
    
        $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/charge/limit";
        $postData = json_encode(["limit" => $limit]);
    
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
            $this->SendDebug('SetChargeLimit', 'Fehler: Keine Antwort von der API!', 0);
            return;
        }
    
        $data = json_decode($response, true);
        $this->SendDebug('SetChargeLimit', 'Antwort: ' . json_encode($data), 0);
    
        if (isset($data['statusCode']) && $data['statusCode'] !== 200) {
            $this->SendDebug('SetChargeLimit', "Fehler beim Setzen des Ladelimits: " . json_encode($data), 0);
        } else {
            $this->SendDebug('SetChargeLimit', 'Ladelimit erfolgreich gesetzt.', 0);
        }
    }    

    public function SetChargeStatus(bool $status)
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID = $this->GetVehicleID($accessToken);
    
        if (empty($accessToken) || empty($vehicleID)) {
            $this->SendDebug('SetChargeStatus', 'Access Token oder Fahrzeug-ID fehlt!', 0);
            return;
        }
    
        // Konvertiere den Status in die erwarteten Werte
        $action = $status ? "START" : "STOP";
    
        $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/charge";
        $postData = json_encode(["action" => $action]);
    
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
            $this->SendDebug('SetChargeStatus', 'Fehler: Keine Antwort von der API!', 0);
            return;
        }
    
        $data = json_decode($response, true);
        $this->SendDebug('SetChargeStatus', 'Antwort: ' . json_encode($data), 0);
    
        if (isset($data['statusCode']) && $data['statusCode'] !== 200) {
            $this->SendDebug('SetChargeStatus', "Fehler beim Setzen des Ladestatus: " . json_encode($data), 0);
            $this->LogMessage("Fehler beim Setzen des Ladestatus: " . ($data['description'] ?? 'Unbekannter Fehler'), KL_ERROR);
        } else {
            $this->SendDebug('SetChargeStatus', 'Ladestatus erfolgreich gesetzt.', 0);
        }
    }

    public function SetLockStatus(bool $status)
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID = $this->GetVehicleID($accessToken);
    
        if (empty($accessToken) || empty($vehicleID)) {
            $this->SendDebug('SetLockStatus', 'Access Token oder Fahrzeug-ID fehlt!', 0);
            return;
        }
    
        // Konvertiere den Status in die erwarteten Werte
        $action = $status ? "LOCK" : "UNLOCK";
    
        $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/security";
        $postData = json_encode(["action" => $action]);
    
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
            $this->SendDebug('SetLockStatus', 'Fehler: Keine Antwort von der API!', 0);
            return;
        }
    
        $data = json_decode($response, true);
        $this->SendDebug('SetLockStatus', 'Antwort: ' . json_encode($data), 0);
    
        if (isset($data['statusCode']) && $data['statusCode'] !== 200) {
            $this->SendDebug('SetLockStatus', "Fehler beim Setzen der Zentralverriegelung: " . json_encode($data), 0);
            $this->LogMessage("Fehler beim Setzen der Zentralverriegelung: " . ($data['description'] ?? 'Unbekannter Fehler'), KL_ERROR);
        } else {
            $this->SendDebug('SetLockStatus', 'Zentralverriegelung erfolgreich gesetzt.', 0);
        }
    }

    public function FetchVehicleInfo()
    {
        $this->FetchSingleEndpoint('/'); // Fahrzeugdetails
    }
    
    public function FetchVIN()
    {
        $this->FetchSingleEndpoint('/vin'); // Fahrzeug-Identifikationsnummer (VIN)
    }
    
    public function FetchLocation()
    {
        $this->FetchSingleEndpoint('/location'); // Standort
    }
    
    public function FetchTires()
    {
        $this->FetchSingleEndpoint('/tires/pressure'); // Reifendruck
    }
    
    public function FetchOdometer()
    {
        $this->FetchSingleEndpoint('/odometer'); // Kilometerstand
    }
    
    public function FetchBatteryLevel()
    {
        $this->FetchSingleEndpoint('/battery'); // Batterielevel und Reichweite
    }
    
    public function FetchBatteryCapacity()
    {
        $this->FetchSingleEndpoint('/battery/capacity'); // Batterieskapazität
    }
    
    public function FetchEngineOil()
    {
        $this->FetchSingleEndpoint('/oil'); // Motorölstatus
    }
    
    public function FetchFuel()
    {
        $this->FetchSingleEndpoint('/fuel'); // Tankfüllstand und Reichweite
    }
    
    public function FetchSecurity()
    {
        $this->FetchSingleEndpoint('/security'); // Sicherheitsstatus (z. B. verriegelt)
    }
    
    public function FetchChargeLimit()
    {
        $this->FetchSingleEndpoint('/charge/limit'); // Aktuelles Ladeziel
    }
    
    public function FetchChargeStatus()
    {
        $this->FetchSingleEndpoint('/charge/status'); // Ladestatus
    }    

    private function FetchSingleEndpoint(string $path)
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID = $this->GetVehicleID($accessToken);
    
        if (empty($accessToken) || empty($vehicleID)) {
            $this->SendDebug('FetchSingleEndpoint', 'Access Token oder Fahrzeug-ID fehlt!', 0);
            return;
        }
    
        $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID" . $path;
    
        $options = [
            'http' => [
                'header' => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method' => 'GET',
                'ignore_errors' => true
            ]
        ];
    
        //Debug-Ausgabe für die API-Anfrage
        $this->SendDebug('FetchSingleEndpoint', 'API-Anfrage: ' . json_encode([
            'url' => $url,
            'method' => $options['http']['method'],
            'header' => $options['http']['header']
        ], JSON_PRETTY_PRINT), 0);
    
        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
    
        if ($response === false) {
            $this->SendDebug('FetchSingleEndpoint', 'Fehler: Keine Antwort von der API!', 0);
            return;
        }
    
        // HTTP-Statuscode aus den Headern extrahieren
        $httpResponseHeader = $http_response_header ?? [];
        $statusCode = 0;
        foreach ($httpResponseHeader as $header) {
            if (preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $header, $matches)) {
                $statusCode = (int)$matches[1];
                break;
            }
        }
    
        $this->SendDebug('FetchSingleEndpoint', "HTTP-Statuscode: $statusCode", 0);
    
        if ($statusCode !== 200) {
            $this->SendDebug('FetchSingleEndpoint', "Fehlerhafte HTTP-Antwort ($statusCode): " . $response, 0);
            return;
        }
    
        $data = json_decode($response, true);
        if (isset($data['statusCode']) && $data['statusCode'] !== 200) {
            $this->SendDebug('FetchSingleEndpoint', "API-Fehler: " . json_encode($data), 0);
            return;
        }
    
        if (isset($data) && !empty($data)) {
            $this->SendDebug('FetchSingleEndpoint', "Erfolgreiche Antwort für $path: " . json_encode($data, JSON_PRETTY_PRINT), 0);
            $this->ProcessResponse($path, $data);
        } else {
            $this->SendDebug('FetchSingleEndpoint', 'Keine gültige Antwortstruktur.', 0);
        }
    }
    
    private function CreateProfile()
    {

        if (!IPS_VariableProfileExists('SMCAR.Pressure')) {
            IPS_CreateVariableProfile('SMCAR.Pressure', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('SMCAR.Pressure', '', ' bar');
            IPS_SetVariableProfileDigits('SMCAR.Pressure', 1);
            IPS_SetVariableProfileValues('SMCAR.Pressure', 0, 5, 0.1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: SMCAR.Pressure', 0);
        } 

        if (!IPS_VariableProfileExists('SMCAR.Odometer')) {
            IPS_CreateVariableProfile('SMCAR.Odometer', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('SMCAR.Odometer', '', ' km');
            IPS_SetVariableProfileDigits('SMCAR.Odometer', 0);
            IPS_SetVariableProfileValues('SMCAR.Odometer', 0, 0, 1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: SMCAR.Odometer', 0);
        } 
        if (!IPS_VariableProfileExists('SMCAR.Progress')) {
            IPS_CreateVariableProfile('SMCAR.Progress', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('SMCAR.Progress', '', ' %');
            IPS_SetVariableProfileDigits('SMCAR.Progress', 0);
            IPS_SetVariableProfileValues('SMCAR.Progress', 0, 100, 1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: SMCAR.Progress', 0);
        } 
        if (!IPS_VariableProfileExists('SMCAR.Status')) {
            IPS_CreateVariableProfile('SMCAR.Status', VARIABLETYPE_STRING);
            IPS_SetVariableProfileAssociation('SMCAR.Status', 'OPEN', 'Offen', '', -1);
            IPS_SetVariableProfileAssociation('SMCAR.Status', 'CLOSED', 'Geschlossen', '', -1);
            IPS_SetVariableProfileAssociation('SMCAR.Status', 'UNKNOWN', 'Unbekannt', '', -1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: SMCAR.Status', 0);
        }
        if (!IPS_VariableProfileExists('SMCAR.Charge')) {
            IPS_CreateVariableProfile('SMCAR.Charge', VARIABLETYPE_STRING);
            IPS_SetVariableProfileAssociation('SMCAR.Charge', 'CHARGING', 'Laden', '', -1);
            IPS_SetVariableProfileAssociation('SMCAR.Charge', 'FULLY_CHARGED', 'Voll geladen', '', -1);
            IPS_SetVariableProfileAssociation('SMCAR.Charge', 'NOT_CHARGING', 'Lädt nicht', '', -1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: SMCAR.Charge', 0);
        }
    }
}