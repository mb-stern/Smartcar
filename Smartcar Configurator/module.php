<?php

class SmartcarConfigurator extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Eigenschaften für API-Zugang
        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('AccessToken', '');
        $this->RegisterAttributeString('FetchedVehicles', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        
        // Fahrzeuge aus Attribut laden und zur Liste hinzufügen
        $vehicles = json_decode($this->ReadAttributeString('FetchedVehicles'), true);
        foreach ($vehicles as $vehicle) {
            $form['elements'][4]['values'][] = [
                'id' => $vehicle['id'],
                'make' => $vehicle['make'],
                'model' => $vehicle['model'],
                'year' => $vehicle['year']
            ];
        }

        return json_encode($form);
    }

    public function FetchVehicles()
    {
        $clientID = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $accessToken = $this->ReadPropertyString('AccessToken');

        if (empty($clientID) || empty($clientSecret) || empty($accessToken)) {
            $this->LogMessage('Client ID, Client Secret oder Access Token fehlt.', KL_ERROR);
            return;
        }

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
            $this->SendDebug('FetchVehicles', 'Fehler: Keine Antwort von der API!', 0);
            $this->LogMessage('Fehler beim Abrufen der Fahrzeuge.', KL_ERROR);
            return;
        }

        $data = json_decode($response, true);

        if (!isset($data['vehicles']) || empty($data['vehicles'])) {
            $this->SendDebug('FetchVehicles', 'Keine Fahrzeuge gefunden.', 0);
            $this->LogMessage('Keine Fahrzeuge gefunden.', KL_WARNING);
            return;
        }

        // Fahrzeuge speichern
        $this->WriteAttributeString('FetchedVehicles', json_encode($data['vehicles']));
        $this->SendDebug('FetchVehicles', 'Gefundene Fahrzeuge: ' . json_encode($data['vehicles']), 0);

        // Formular aktualisieren
        $this->ReloadForm();
    }

    public function CreateVehicleInstance()
    {
        $vehicles = json_decode($this->ReadAttributeString('FetchedVehicles'), true);
        $selectedVehicle = $this->GetSelectedVehicle();

        if ($selectedVehicle === null) {
            $this->LogMessage('Kein Fahrzeug ausgewählt.', KL_WARNING);
            return;
        }

        // Instanz erstellen
        $instanceID = IPS_CreateInstance('{GUID_SMARTCAR_VEHICLE}');
        IPS_SetName($instanceID, $selectedVehicle['make'] . ' ' . $selectedVehicle['model']);
        IPS_SetProperty($instanceID, 'VehicleID', $selectedVehicle['id']);
        IPS_ApplyChanges($instanceID);

        $this->LogMessage('Fahrzeug-Instanz erstellt: ' . $selectedVehicle['make'] . ' ' . $selectedVehicle['model'], KL_NOTIFY);
    }

    private function GetSelectedVehicle()
    {
        $vehicles = json_decode($this->ReadAttributeString('FetchedVehicles'), true);

        // Fahrzeug aus der Liste ermitteln (falls ein spezifischer Mechanismus zur Auswahl existiert)
        foreach ($vehicles as $vehicle) {
            if ($vehicle['selected'] ?? false) {
                return $vehicle;
            }
        }

        return null;
    }

    private function ReloadForm()
    {
        IPS_SetProperty($this->InstanceID, 'Form', $this->GetConfigurationForm());
        IPS_ApplyChanges($this->InstanceID);
    }
}
