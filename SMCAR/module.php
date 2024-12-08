<?php

class SMCAR extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Eigenschaften definieren
        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('AccessToken', '');
        $this->RegisterPropertyString('VIN', '');

        // Webhook registrieren
        $this->RegisterAttributeString("CurrentHook", "");
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

    public function ConnectVehicle()
    {
        $this->LogMessage('Fahrzeug wird verbunden...', KL_NOTIFY);
        // Logik für die Fahrzeugverbindung
    }

    public function GenerateAuthURL()
    {
        $clientID = $this->ReadPropertyString('ClientID');
        if (empty($clientID)) {
            echo "Fehler: Client ID nicht gesetzt!";
            return;
        }
    
        // Instanz von Symcon Connect direkt abrufen
        $connectInstanceID = @IPS_GetInstanceIDByName("Symcon Connect", 0);
        if ($connectInstanceID === false) {
            echo "Fehler: Symcon Connect-Instanz nicht gefunden!";
            return;
        }
    
        // Symcon Connect-Adresse abrufen
        $connectURL = IPS_GetProperty($connectInstanceID, 'ServerAddress');
        if (empty($connectURL)) {
            echo "Fehler: Symcon Connect-Adresse nicht konfiguriert!";
            return;
        }
    
        // Redirect URI korrekt zusammensetzen
        $redirectURI = $connectURL . $this->ReadAttributeString("CurrentHook");
    
        $scopes = urlencode('read_vehicle_info read_location');
        $state = bin2hex(random_bytes(8));
    
        $authURL = "https://connect.smartcar.com/oauth/authorize?" .
            "response_type=code" .
            "&client_id=$clientID" .
            "&redirect_uri=" . urlencode($redirectURI) .
            "&scope=$scopes" .
            "&state=$state" .
            "&mode=test";
    
        $this->SendDebug('GenerateAuthURL', "Erstellte URL: $authURL", 0);
        echo "Bitte besuchen Sie die folgende URL, um Ihr Fahrzeug zu verbinden:\n" . $authURL;
    }
    
    
    public function ProcessHookData()
    {
        if (!isset($_GET['code']) || !isset($_GET['state'])) {
            $this->SendDebug('ProcessHookData', 'Autorisierungscode oder Status fehlt!', 0);
            http_response_code(400);
            echo "Fehler: Autorisierungscode fehlt!";
            return;
        }
    
        $authCode = $_GET['code'];
        $state = $_GET['state'];
    
        $this->SendDebug('ProcessHookData', "Autorisierungscode erhalten: $authCode", 0);
    
        // Tausche den Code gegen Access Token
        $this->ExchangeAuthorizationCode($authCode);
        echo "Fahrzeug erfolgreich verbunden!";
    }
    

private function ExchangeAuthorizationCode(string $authCode)
{
    $clientID = $this->ReadPropertyString('ClientID');
    $clientSecret = $this->ReadPropertyString('ClientSecret');
    $redirectURI = $this->ReadAttributeString("CurrentHook");

    $url = "https://auth.smartcar.com/oauth/token";

    $data = [
        'grant_type' => 'authorization_code',
        'code' => $authCode,
        'redirect_uri' => $redirectURI,
        'client_id' => $clientID,
        'client_secret' => $clientSecret
    ];

    $options = [
        'http' => [
            'header' => "Content-Type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($data)
        ]
    ];

    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);

    if ($response === false) {
        $this->SendDebug('ExchangeAuthorizationCode', 'Fehler beim Abruf des Tokens!', 0);
        $this->LogMessage('Token-Abruf fehlgeschlagen.', KL_ERROR);
        return;
    }

    $responseData = json_decode($response, true);

    if (isset($responseData['access_token'])) {
        $this->WritePropertyString('AccessToken', $responseData['access_token']);
        $this->SendDebug('ExchangeAuthorizationCode', 'Access Token erhalten!', 0);
    } else {
        $this->SendDebug('ExchangeAuthorizationCode', 'Token-Austausch fehlgeschlagen!', 0);
        $this->LogMessage('Fehler beim Token-Austausch.', KL_ERROR);
    }
}

private function FetchVIN(string $vehicleID)
{
    $accessToken = $this->ReadPropertyString('AccessToken');

    $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID";

    $options = [
        'http' => [
            'header' => [
                "Authorization: Bearer $accessToken",
                "Content-Type: application/json"
            ],
            'method' => 'GET'
        ]
    ];

    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);

    if ($response === false) {
        $this->SendDebug('FetchVIN', 'Fehler beim Abrufen der Fahrzeugdetails!', 0);
        return;
    }

    $data = json_decode($response, true);

    if (isset($data['vin'])) {
        $this->WritePropertyString('VIN', $data['vin']);
        $this->SendDebug('FetchVIN', 'Fahrgestellnummer gespeichert: ' . $data['vin'], 0);
        $this->LogMessage('Fahrgestellnummer erfolgreich gespeichert.', KL_NOTIFY);
    } else {
        $this->SendDebug('FetchVIN', 'VIN nicht gefunden!', 0);
    }
}

public function FetchVehicleData()
{
    $accessToken = $this->ReadPropertyString('AccessToken');
    if (empty($accessToken)) {
        $this->SendDebug('FetchVehicleData', 'Kein Access Token vorhanden.', 0);
        return;
    }

    $url = "https://api.smartcar.com/v2.0/vehicles";

    $options = [
        'http' => [
            'header' => [
                "Authorization: Bearer $accessToken",
                "Content-Type: application/json"
            ],
            'method' => 'GET'
        ]
    ];

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        $this->SendDebug('FetchVehicleData', 'HTTP-Fehler: Keine Antwort erhalten!', 0);
        $this->LogMessage('Fahrzeugdaten konnten nicht abgerufen werden.', KL_ERROR);
        return;
    }

    $data = json_decode($response, true);

    if (!isset($data['vehicles'][0])) {
        $this->SendDebug('FetchVehicleData', 'Keine Fahrzeuge gefunden!', 0);
        return;
    }

    $vehicleID = $data['vehicles'][0];
    $this->FetchVIN($vehicleID);
}

}
