<?php

class SmartcarConfigurator extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Keine weiteren Eigenschaften notwendig
        $this->RegisterPropertyString('AccessToken', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Keine speziellen Änderungen bei ApplyChanges erforderlich
    }

    public function GetConfigurationForm()
    {
        $form = [
            'elements' => [],
            'actions'  => []
        ];

        // Prüfe, ob ein Access Token vorhanden ist
        $accessToken = $this->ReadPropertyString('AccessToken');

        if (empty($accessToken)) {
            $form['elements'][] = [
                'type'    => 'Label',
                'caption' => 'Bitte verbinden Sie Ihr Smartcar-Konto, um Fahrzeuge anzuzeigen.'
            ];

            $form['actions'][] = [
                'type'    => 'Button',
                'caption' => 'Smartcar verbinden',
                'onClick' => 'echo SMCAR_GenerateAuthURL($id);'
            ];
            return json_encode($form);
        }

        // Fahrzeuge abrufen
        $vehicles = $this->FetchVehicles($accessToken);

        if (empty($vehicles)) {
            $form['elements'][] = [
                'type'    => 'Label',
                'caption' => 'Keine Fahrzeuge gefunden. Bitte stellen Sie sicher, dass Ihr Konto Fahrzeuge enthält.'
            ];
            return json_encode($form);
        }

        // Fahrzeugliste anzeigen
        foreach ($vehicles as $vehicle) {
            $form['elements'][] = [
                'type'    => 'ExpansionPanel',
                'caption' => $vehicle['make'] . ' ' . $vehicle['model'] . ' (' . $vehicle['year'] . ')',
                'items'   => [
                    [
                        'type'    => 'Label',
                        'caption' => 'Fahrgestellnummer (VIN): ' . $vehicle['vin']
                    ],
                    [
                        'type'    => 'Button',
                        'caption' => 'Instanz erstellen',
                        'onClick' => "SMCARConfigurator_CreateVehicleInstance(\$id, '{$vehicle['id']}', '{$vehicle['vin']}', '{$vehicle['make']}', '{$vehicle['model']}', {$vehicle['year']});"
                    ]
                ]
            ];
        }

        return json_encode($form);
    }

    private function FetchVehicles(string $accessToken): array
    {
        $url = 'https://api.smartcar.com/v2.0/vehicles';
        $options = [
            'http' => [
                'header'        => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method'        => 'GET',
                'ignore_errors' => true
            ]
        ];

        $context  = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            $this->SendDebug('FetchVehicles', 'Fehler: Keine Antwort von der API!', 0);
            return [];
        }

        $data = json_decode($response, true);
        if (isset($data['vehicles']) && is_array($data['vehicles'])) {
            return $data['vehicles'];
        }

        $this->SendDebug('FetchVehicles', 'Unerwartete Antwortstruktur: ' . json_encode($data), 0);
        return [];
    }

    public function CreateVehicleInstance(string $vehicleID, string $vin, string $make, string $model, int $year)
    {
        $instanceID = IPS_CreateInstance('{F0D3899F-F0FF-66C4-CC26-C8F72CC42B1B}'); // Ersetze mit der Modul-ID deines Fahrzeugmoduls

        IPS_SetProperty($instanceID, 'VehicleID', $vehicleID);
        IPS_SetProperty($instanceID, 'VIN', $vin);
        IPS_SetName($instanceID, "$make $model ($year)");

        IPS_ApplyChanges($instanceID);

        $this->SendDebug('CreateVehicleInstance', "Instanz erstellt: ID=$instanceID, Fahrzeug=$make $model ($year)", 0);
    }
}
