<?php

class MercedesMe extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Register properties for configuration
        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('UpdateInterval', 60);

        // Timer for regular updates
        $this->RegisterTimer('UpdateData', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateData", 0);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Register variables for vehicle data
        $this->MaintainVariable('FuelLevel', 'Kraftstoffstand', VARIABLETYPE_INTEGER, '~Battery.100', 0, true);
        $this->MaintainVariable('Mileage', 'Kilometerstand', VARIABLETYPE_FLOAT, '', 1, true);

        // Set timer interval based on user-defined update frequency
        $interval = $this->ReadPropertyInteger('UpdateInterval') * 1000;
        $this->SetTimerInterval('UpdateData', $interval);

        // Authenticate on startup
        $this->Authenticate();
    }

    private function Authenticate()
    {
        $email = $this->ReadPropertyString('Email');
        $password = $this->ReadPropertyString('Password');

        if (empty($email) || empty($password)) {
            $this->SendDebug("Authenticate", "E-Mail oder Passwort nicht gesetzt.", 0);
            return;
        }

        $this->SendDebug("Authenticate", "Starte Authentifizierung für $email.", 0);

        // Retrieve the authentication token
        $token = $this->GetAuthToken($email, $password);
        if ($token) {
            $this->SetBuffer('AuthToken', $token);
            $this->SendDebug("Authenticate", "Token erfolgreich empfangen.", 0);
        } else {
            $this->SendDebug("Authenticate", "Authentifizierung fehlgeschlagen.", 0);
        }
    }

    private function GetAuthToken($email, $password)
    {
        $url = 'https://api.mercedes-benz.com/v1/auth/token';
        $postData = [
            'email' => $email,
            'password' => $password
        ];

        $this->SendDebug("GetAuthToken", "URL: $url", 0);
        $this->SendDebug("GetAuthToken", "Post-Daten: " . json_encode($postData), 0);

        $options = [
            'http' => [
                'header'  => "Content-Type: application/json\r\n",
                'method'  => 'POST',
                'content' => json_encode($postData),
                'timeout' => 10
            ]
        ];

        $context  = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result === FALSE) {
            $this->SendDebug("GetAuthToken", "Fehler beim Abrufen des Authentifizierungstokens.", 0);
            return false;
        }

        $response = json_decode($result, true);
        $this->SendDebug("GetAuthToken", "Antwort: " . json_encode($response), 0);

        return $response['access_token'] ?? false;
    }

    public function UpdateData()
    {
        $token = $this->GetBuffer('AuthToken');
        if (!$token) {
            $this->SendDebug("UpdateData", "Kein Authentifizierungstoken vorhanden.", 0);
            return;
        }

        // Fetch vehicle data
        $vehicleData = $this->FetchVehicleData($token);
        if ($vehicleData) {
            $this->SetValue('FuelLevel', $vehicleData['fuelLevel']);
            $this->SetValue('Mileage', $vehicleData['mileage']);
            $this->SendDebug("UpdateData", "Fahrzeugdaten erfolgreich aktualisiert.", 0);
        } else {
            $this->SendDebug("UpdateData", "Fehler beim Abrufen der Fahrzeugdaten.", 0);
        }
    }

    private function FetchVehicleData($token)
    {
        $url = 'https://api.mercedes-benz.com/v1/vehicles';
        $this->SendDebug("FetchVehicleData", "Abruf-URL: $url", 0);

        $options = [
            'http' => [
                'header'  => "Authorization: Bearer $token\r\n",
                'method'  => 'GET',
                'timeout' => 10
            ]
        ];

        $context  = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        if ($result === FALSE) {
            $this->SendDebug("FetchVehicleData", "Fehler beim Abrufen der Fahrzeugdaten.", 0);
            return false;
        }

        $response = json_decode($result, true);
        $this->SendDebug("FetchVehicleData", "Antwort: " . json_encode($response), 0);

        return [
            'fuelLevel' => $response['fuelLevel'] ?? 0,
            'mileage' => $response['mileage'] ?? 0.0
        ];
    }
}

?>
