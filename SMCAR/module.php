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

        $this->RegisterPropertyInteger('FetchInterval', 60); // Standard: 60 Sekunden

        $this->RegisterAttributeString("CurrentHook", "");
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeString('VehicleID', '');

        $this->RegisterTimer('FetchDataTimer', 0, 'SMCAR_FetchAllData(' . $this->InstanceID . ');');
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
            $this->SendDebug('ApplyChanges', 'Token-Erneuerungs-Timer gestartet.', 0);
        } else {
            $this->SetTimerInterval('TokenRefreshTimer', 0); // Timer deaktivieren
            $this->SendDebug('ApplyChanges', 'Token-Erneuerungs-Timer gestoppt.', 0);
        }

        // Fetch-Daten-Timer konfigurieren
        $fetchInterval = $this->ReadPropertyInteger('FetchInterval') * 1000;
        $this->SetTimerInterval('FetchDataTimer', $fetchInterval);
        $this->SendDebug('ApplyChanges', "Datenabfrage-Timer auf {$fetchInterval} ms gesetzt.", 0);

        // Profile erstellen
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

    public function FetchAllData()
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID = $this->ReadAttributeString('VehicleID');

        if (empty($accessToken) || empty($vehicleID)) {
            $this->SendDebug('FetchAllData', 'Access Token oder Fahrzeug-ID fehlt!', 0);
            return;
        }

        $endpoints = [];

        if ($this->ReadPropertyBoolean('ScopeReadVehicleInfo')) {
            $endpoints[] = ["path" => "/vehicles/$vehicleID"];
        }
        if ($this->ReadPropertyBoolean('ScopeReadLocation')) {
            $endpoints[] = ["path" => "/vehicles/$vehicleID/location"];
        }
        if ($this->ReadPropertyBoolean('ScopeReadTires')) {
            $endpoints[] = ["path" => "/vehicles/$vehicleID/tires/pressure"];
        }
        if ($this->ReadPropertyBoolean('ScopeReadOdometer')) {
            $endpoints[] = ["path" => "/vehicles/$vehicleID/odometer"];
        }
        if ($this->ReadPropertyBoolean('ScopeReadBattery')) {
            $endpoints[] = ["path" => "/vehicles/$vehicleID/battery"];
        }

        if (empty($endpoints)) {
            $this->SendDebug('FetchAllData', 'Keine Scopes aktiviert!', 0);
            return;
        }

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
    }
}
