<?php

class SmartcarVehicle extends IPSModuleStrict
{
    private const SPLITTER_MODULE_ID = '{9F7A4B2C-3D1E-4A6F-8B20-6C5D4E3F2A10}';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('VehicleID', '');
        $this->RegisterPropertyString('ConnectionID', '');
        $this->RegisterPropertyString('VehicleCaption', '');

        $this->RegisterPropertyString('Make', '');
        $this->RegisterPropertyString('Model', '');
        $this->RegisterPropertyInteger('Year', 0);
        $this->RegisterPropertyString('PowertrainType', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if ($this->ReadPropertyString('VehicleID') === '') {
            $this->SetStatus(201);
            return;
        }

        $this->SetStatus(102);
    }

    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type' => 'connect',
            'moduleIDs' => [
                self::SPLITTER_MODULE_ID
            ]
        ]);
    }

    public function GetConfigurationForm(): string
    {
        $form = [
            'elements' => [
                [
                    'type' => 'Label',
                    'caption' => 'Vehicle ID: ' . $this->ReadPropertyString('VehicleID')
                ],
                [
                    'type' => 'Label',
                    'caption' => 'Connection ID: ' . $this->ReadPropertyString('ConnectionID')
                ],
                [
                    'type' => 'Label',
                    'caption' => 'Fahrzeug: ' . $this->ReadPropertyString('VehicleCaption')
                ],
                [
                    'type' => 'Label',
                    'caption' => 'Hersteller: ' . $this->ReadPropertyString('Make')
                ],
                [
                    'type' => 'Label',
                    'caption' => 'Modell: ' . $this->ReadPropertyString('Model')
                ],
                [
                    'type' => 'Label',
                    'caption' => 'Baujahr: ' . $this->ReadPropertyInteger('Year')
                ],
                [
                    'type' => 'Label',
                    'caption' => 'Antrieb: ' . $this->ReadPropertyString('PowertrainType')
                ]
            ],
            'actions' => []
        ];

        return json_encode($form);
    }
}