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

        $this->RegisterPropertyString('CompatibilityRegion', 'EUROPE');
        $this->RegisterPropertyString('SelectedCapabilities', '[]');

        $this->RegisterAttributeString('CompatibilityCache', '[]');
        $this->RegisterAttributeInteger('CompatibilityCacheAt', 0);
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
        $capabilities = $this->GetCompatibilityCapabilitiesForForm();

        $form = [
            'elements' => [
                ['type' => 'Label', 'caption' => 'Vehicle ID: ' . $this->ReadPropertyString('VehicleID')],
                ['type' => 'Label', 'caption' => 'Connection ID: ' . $this->ReadPropertyString('ConnectionID')],
                ['type' => 'Label', 'caption' => 'Fahrzeug: ' . $this->ReadPropertyString('VehicleCaption')],
                ['type' => 'Label', 'caption' => 'Antrieb: ' . $this->ReadPropertyString('PowertrainType')],

                [
                    'type' => 'Select',
                    'name' => 'CompatibilityRegion',
                    'caption' => 'Compatibility Region',
                    'options' => [
                        ['caption' => 'Europa', 'value' => 'EUROPE'],
                        ['caption' => 'USA', 'value' => 'US'],
                        ['caption' => 'Kanada', 'value' => 'CA']
                    ]
                ],

                [
                    'type' => 'CheckBoxList',
                    'name' => 'SelectedCapabilities',
                    'caption' => 'Kompatible Signale / Befehle',
                    'rowCount' => 20,
                    'values' => $capabilities
                ]
            ],
            'actions' => [
                [
                    'type' => 'Button',
                    'caption' => 'Kompatibilitätsliste neu laden',
                    'onClick' => 'SMCARV_ReloadCompatibility($id);'
                ]
            ]
        ];

        return json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}