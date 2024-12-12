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

                // Ladelimit-Variable erstellen, wenn aktiviert
                if ($this->ReadPropertyBoolean('SetChargeLimit')) {
                    $this->MaintainVariable('ChargeLimit', 'Ladelimit (%)', VARIABLETYPE_FLOAT, 'SMCAR.Progress', 50, true);
                    $this->EnableAction('ChargeLimit');
                } else {
                    $this->MaintainVariable('ChargeLimit', 'Ladelimit (%)', VARIABLETYPE_FLOAT, '', 0, false);
                    $this->DisableAction('ChargeLimit');
                }
    
        //Profile für erstellen
        $this->CreateProfile();
 
    }
 
    public function RequestAction($ident, $value)
    {
        switch ($ident) {
            case 'ChargeLimit':
                $this->SetChargeLimit($value / 100); // Konvertiere zu einem Wert zwischen 0.5 und 1.0
                $this->SetValue($ident, $value);
                break;

            default:
                throw new Exception("Invalid ident");
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

    
    //Hier kommen die Scopes rein für welche die Freigabe beantragt werden
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
        $vehicleID = $this->GetVehicleID($accessToken);
        $this->WriteAttributeString('VehicleID', $vehicleID);

        if (empty($accessToken) || empty($vehicleID)) {
            $this->SendDebug('FetchVehicleData', 'Access Token oder Fahrzeug-ID fehlt!', 0);
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
            $this->SendDebug('FetchVehicleData', 'Keine Scopes aktiviert!', 0);
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
            $this->SendDebug('FetchVehicleData', 'Fehler: Keine Antwort von der API!', 0);
            return;
        }
    
        $data = json_decode($response, true);
        $this->SendDebug('FetchVehicleData', 'Antwort: ' . json_encode($data), 0);
    
        // Verarbeite die Antwort
        if (isset($data['responses']) && is_array($data['responses'])) {
            foreach ($data['responses'] as $response) {
                if ($response['code'] === 200 && isset($response['body'])) {
                    $this->ProcessResponse($response['path'], $response['body']);
                } else {
                    $this->SendDebug('FetchVehicleData', "Fehlerhafte Antwort: " . json_encode($response), 0);
                }
            }
        } else {
            $this->SendDebug('FetchVehicleData', 'Unerwartete Antwortstruktur: ' . json_encode($data), 0);
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
    
    
    // Verarbeiten der Antworten
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
                $this->SetValue('TireFrontLeft', ($body['frontLeft'] ?? 0) * 0.01);
                $this->SetValue('TireFrontRight', ($body['frontRight'] ?? 0) * 0.01);
                $this->SetValue('TireBackLeft', ($body['backLeft'] ?? 0) * 0.01);
                $this->SetValue('TireBackRight', ($body['backRight'] ?? 0) * 0.01);
                break;
    
            case '/odometer':
                $this->MaintainVariable('Odometer', 'Kilometerstand', VARIABLETYPE_FLOAT, 'SMCAR.Odometer', 30, true);
                $this->SetValue('Odometer', $body['distance'] ?? 0);
                break;
    
            case '/battery':
                $this->MaintainVariable('BatteryRange', 'Reichweite', VARIABLETYPE_FLOAT, 'SMCAR.Odometer', 40, true);
                $this->MaintainVariable('BatteryLevel', 'Batterieladestand', VARIABLETYPE_FLOAT, 'SMCAR.Progress', 41, true);
                $this->SetValue('BatteryRange', $body['range'] ?? 0);
                $this->SetValue('BatteryLevel', ($body['percentRemaining'] ?? 0) * 100);
                break;
    
            default:
                $this->SendDebug('ProcessResponse', "Unbekannter Pfad: $path", 0);
        }
    }

    public function SetChargeLimit(float $limit)
    {
        if ($limit < 0.5 || $limit > 1.0) {
            $this->SendDebug('SetChargeLimit', 'Ungültiges Limit. Es muss zwischen 0.5 und 1.0 liegen.', 0);
            return;
        }
    
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID = $this->ReadAttributeString('VehicleID');
    
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

}

}
