<?php

class MercedesMe extends IPSModule
{
    // Erstellen der Instanz
    public function Create()
    {
        // Diese Zeile nicht löschen
        parent::Create();

        // Eigenschaften registrieren
        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('UpdateInterval', 60);

        // Timer für regelmäßige Updates
        $this->RegisterTimer('UpdateData', 0, 'MercedesMe_UpdateData($_IPS[\'TARGET\']);');
    }

    // Anwenden von Änderungen
    public function ApplyChanges()
    {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();

        // Variablen für Fahrzeugdaten registrieren
        $this->MaintainVariable('FuelLevel', 'Kraftstoffstand', VARIABLETYPE_INTEGER, '~Battery.100', 0, true);
        $this->MaintainVariable('Mileage', 'Kilometerstand', VARIABLETYPE_FLOAT, '', 1, true);

        // Timer-Intervall basierend auf dem Update-Intervall setzen
        $interval = $this->ReadPropertyInteger('UpdateInterval') * 1000;
        $this->SetTimerInterval('UpdateData', $interval);

        // Authentifizierung durchführen
        $this->Authenticate();
    }

    // Authentifizierungsmethode
    private function Authenticate()
    {
        $email = $this->ReadPropertyString('Email');
        $password = $this->ReadPropertyString('Password');

        if (empty($email) || empty($password)) {
            $this->LogMessage('E-Mail oder Passwort nicht gesetzt.', KL_WARNING);
            return;
        }

        // Authentifizierungslogik hier implementieren
        // Beispiel: Token abrufen und speichern
        $token = $this->GetAuthToken($email, $password);
        if ($token) {
            $this->SetBuffer('AuthToken', $token);
        } else {
            $this->LogMessage('Authentifizierung fehlgeschlagen.', KL_ERROR);
        }
    }

    // Methode zum Abrufen des Authentifizierungstokens
    private function GetAuthToken($email, $password)
    {
        // HTTP-Anfrage an den Authentifizierungsendpunkt von Mercedes me
        $url = 'https://api.mercedes-benz.com/v1/auth/token';
        $postData = [
            'email' => $email,
            'password' => $password
        ];

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
            $this->LogMessage('Fehler beim Abrufen des Authentifizierungstokens.', KL_ERROR);
            return false;
        }

        $response = json_decode($result, true);
        return $response['access_token'] ?? false;
    }

    // Methode zum Aktualisieren der Fahrzeugdaten
    public function UpdateData()
    {
        $token = $this->GetBuffer('AuthToken');
        if (!$token) {
            $this->LogMessage('Kein Authentifizierungstoken vorhanden.', KL_WARNING);
            return;
        }

        // Fahrzeugdaten abrufen
        $vehicleData = $this->FetchVehicleData($token);
        if ($vehicleData) {
            $this->SetValue('FuelLevel', $vehicleData['fuelLevel']);
            $this->SetValue('Mileage', $vehicleData['mileage']);
        } else {
            $this->LogMessage('Fehler beim Abrufen der Fahrzeugdaten.', KL_ERROR);
        }
    }

    // Methode zum Abrufen der Fahrzeugdaten von der API
    private function FetchVehicleData($token)
    {
        $url = 'https://api.mercedes-benz.com/v1/vehicles';
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
            $this->LogMessage('Fehler beim Abrufen der Fahrzeugdaten.', KL_ERROR);
            return false;
        }

        $response = json_decode($result, true);
        // Beispielhafte Datenextraktion
        return [
            'fuelLevel' => $response['fuelLevel'] ?? 0,
            'mileage' => $response['mileage'] ?? 0.0
        ];
    }
}

?>
