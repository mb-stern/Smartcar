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
    
        // Webhook-Pfad in der Form anzeigen
        $this->UpdateFormField("WebhookPath", "caption", "Webhook: " . $hookPath);
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
    
        // Webhook-Pfad dynamisch in das Konfigurationsformular einfügen
        $hookPath = $this->ReadAttributeString("CurrentHook");
        $webhookElement = [
            "type"    => "Label",
            "caption" => "Webhook: " . $hookPath
        ];
    
        // Einfügen an einer bestimmten Position, z. B. ganz oben oder nach einem spezifischen Element
        array_splice($form['elements'], 0, 0, [$webhookElement]); // Fügt es an Position 0 ein
    
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
        $redirectURI = urlencode($this->ReadAttributeString("CurrentHook"));
        $scopes = urlencode('read_vehicle_info read_location');
        $state = bin2hex(random_bytes(8));
    
        $authURL = "https://connect.smartcar.com/oauth/authorize?" .
            "response_type=code" .
            "&client_id=$clientID" .
            "&redirect_uri=$redirectURI" .
            "&scope=$scopes" .
            "&state=$state" .
            "&mode=test"; 
    
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


}
