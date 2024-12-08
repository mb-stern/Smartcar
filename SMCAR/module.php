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

        $this->RegisterAttributeString("CurrentHook", "");
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('VIN', '');

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
    
        /*
        // Webhook-Pfad in der Form anzeigen
        $this->UpdateFormField("WebhookPath", "caption", "Webhook: " . $hookPath);

        $vin = $this->ReadPropertyString('VIN');
        $this->UpdateFormField("VIN", "caption", "Fahrgestellnummer (VIN): " . $vin);
        */
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
    
        if (empty($clientID) || empty($connectAddress)) {
            echo "Fehler: Client ID oder Connect-Adresse nicht gesetzt!";
            return;
        }
    
        $redirectURI = rtrim($connectAddress, '/') . $this->ReadAttributeString("CurrentHook");
        $scopes = urlencode('read_vehicle_info read_location');
        $state = bin2hex(random_bytes(8));
    
        $authURL = "https://connect.smartcar.com/oauth/authorize?" .
            "response_type=code" .
            "&client_id=$clientID" .
            "&redirect_uri=" . urlencode($redirectURI) .
            "&scope=$scopes" .
            "&state=$state" .
            "&mode=simulated"; 
    
        $this->SendDebug('GenerateAuthURL', "Erstellte URL: $authURL", 0);
        echo "Bitte besuchen Sie die folgende URL, um Ihr Fahrzeug zu verbinden:\n" . $authURL;
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
    
        $this->SendDebug('RequestAccessToken', 'POST-Daten: ' . $postData, 0);
    
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
    
        // HTTP-Status prüfen
        $httpResponseHeader = $http_response_header ?? [];
        $httpStatus = isset($httpResponseHeader[0]) ? $httpResponseHeader[0] : "Unbekannt";
        $this->SendDebug('RequestAccessToken', "HTTP-Status: $httpStatus", 0);
    
        if ($response === false) {
            $this->SendDebug('RequestAccessToken', 'Fehler: Keine Antwort von Smartcar API.', 0);
            $this->LogMessage('Token-Abruf fehlgeschlagen.', KL_ERROR);
            return;
        }
    
        $responseData = json_decode($response, true);
    
        $this->SendDebug('RequestAccessToken', 'Antwort: ' . json_encode($responseData), 0);
    
        if (isset($responseData['access_token'])) {
            // Verwenden Sie WriteAttributeString anstelle von WritePropertyString
            $this->WriteAttributeString('AccessToken', $responseData['access_token']);
            $this->SendDebug('RequestAccessToken', 'Access Token erfolgreich gespeichert!', 0);
            $this->LogMessage('Access Token erfolgreich gespeichert.', KL_NOTIFY);
        } else {
            $this->SendDebug('RequestAccessToken', 'Fehler: Token-Austausch fehlgeschlagen!', 0);
            $this->LogMessage('Token-Austausch fehlgeschlagen.', KL_ERROR);
        }
    }

public function FetchVehicleData()
{
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

        // Fahrzeugdetails abrufen
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

    // Reifendruck abrufen
    $this->FetchTirePressure($vehicleID);
}

private function FetchTirePressure(string $vehicleID)
{
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
        $this->MaintainVariable('TireFrontLeft', 'Reifendruck vorne links', VARIABLETYPE_FLOAT, '~Pressure', 5, true);
        $this->SetValue('TireFrontLeft', $data['frontLeft']);

        $this->MaintainVariable('TireFrontRight', 'Reifendruck vorne rechts', VARIABLETYPE_FLOAT, '~Pressure', 6, true);
        $this->SetValue('TireFrontRight', $data['frontRight']);

        $this->MaintainVariable('TireBackLeft', 'Reifendruck hinten links', VARIABLETYPE_FLOAT, '~Pressure', 7, true);
        $this->SetValue('TireBackLeft', $data['backLeft']);

        $this->MaintainVariable('TireBackRight', 'Reifendruck hinten rechts', VARIABLETYPE_FLOAT, '~Pressure', 8, true);
        $this->SetValue('TireBackRight', $data['backRight']);
    } else {
        $this->SendDebug('FetchTirePressure', 'Reifendruckdaten nicht gefunden!', 0);
    }
}


}
