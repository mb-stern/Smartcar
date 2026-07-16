<?php

class SmartcarConfigurator extends IPSModuleStrict
{
    private const SPLITTER_MODULE_ID = '{9F7A4B2C-3D1E-4A6F-8B20-6C5D4E3F2A10}';
    private const VEHICLE_MODULE_ID = '{1E1B7C9A-2D4F-4E8A-9C3B-7F6D5A4E2B10}';
    private const DATA_ID = '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}';

    public function Create(): void
    {
        parent::Create();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetStatus($this->HasActiveParent() ? 102 : 104);
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
        $values = [];

        if ($this->HasActiveParent()) {
            $connections = $this->LoadConnectionsFromSplitter();
            $location = $this->GetConfiguratorLocation();

            foreach ($connections as $connection) {
                if (!is_array($connection)) {
                    continue;
                }

                $vehicleId = trim((string)($connection['vehicleId'] ?? ''));
                if ($vehicleId === '') {
                    continue;
                }

                $caption = trim((string)($connection['caption'] ?? ''));
                if ($caption === '') {
                    $caption = $vehicleId;
                }

                $instanceId = $this->FindVehicleInstanceByVehicleId($vehicleId);

                $values[] = [
                    'instanceID' => $instanceId,
                    'name' => $caption,
                    'vehicleId' => $vehicleId,
                    'make' => (string)($connection['make'] ?? ''),
                    'model' => (string)($connection['model'] ?? ''),
                    'year' => (string)($connection['year'] ?? ''),
                    'mode' => (string)($connection['mode'] ?? ''),
                    'powertrainType' => (string)($connection['powertrainType'] ?? ''),
                    'create' => [
                        'moduleID' => self::VEHICLE_MODULE_ID,
                        'name' => $caption,
                        'configuration' => [
                            'VehicleID' => $vehicleId,
                            'ConnectionID' => (string)($connection['connectionId'] ?? ''),
                            'UserID' => (string)($connection['userId'] ?? ''),
                            'VehicleCaption' => $caption,
                            'Make' => (string)($connection['make'] ?? ''),
                            'Model' => (string)($connection['model'] ?? ''),
                            'Year' => (int)($connection['year'] ?? 0),
                            'PowertrainType' => (string)($connection['powertrainType'] ?? '')
                        ],
                        'location' => $location
                    ]
                ];
            }
        }

        $form = [
            'elements' => [
                [
                    'type' => 'Configurator',
                    'name' => 'Vehicles',
                    'caption' => 'Smartcar Fahrzeuge',
                    'rowCount' => 10,
                    'delete' => true,
                    'sort' => [
                        'column' => 'name',
                        'direction' => 'ascending'
                    ],
                    'columns' => [
                        [
                            'caption' => 'Fahrzeug',
                            'name' => 'name',
                            'width' => 'auto'
                        ],
                        [
                            'caption' => 'Hersteller',
                            'name' => 'make',
                            'width' => '130px'
                        ],
                        [
                            'caption' => 'Modell',
                            'name' => 'model',
                            'width' => '160px'
                        ],
                        [
                            'caption' => 'Jahr',
                            'name' => 'year',
                            'width' => '70px'
                        ],
                        [
                            'caption' => 'Modus',
                            'name' => 'mode',
                            'width' => '90px'
                        ],
                        [
                            'caption' => 'Antrieb',
                            'name' => 'powertrainType',
                            'width' => '110px'
                        ],
                        [
                            'caption' => 'Vehicle ID',
                            'name' => 'vehicleId',
                            'width' => '250px',
                            'visible' => false
                        ]
                    ],
                    'values' => $values
                ]
            ],
            'actions' => [
                [
                    'type' => 'Button',
                    'caption' => 'Neues Live-Fahrzeug verbinden',
                    'onClick' => 'echo SMCARCFG_GenerateConnectURL($id, "live");'
                ],
                [
                    'type' => 'Button',
                    'caption' => 'Fahrzeugliste aktualisieren',
                    'onClick' => 'SMCARCFG_ReloadConfiguratorForm($id);'
                ]
            ]
        ];

        if (!$this->HasActiveParent()) {
            array_unshift($form['elements'], [
                'type' => 'Label',
                'caption' => 'Kein aktiver Smartcar Splitter verbunden.'
            ]);
        }

        return json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function GenerateConnectURL(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if ($mode !== 'simulated') {
            $mode = 'live';
        }

        $permissions = [
            'read_vin',
            'read_vehicle_info',
            'read_odometer',
            'read_location',
            'read_battery',
            'read_charge',
            'read_tires',
            'read_security'
        ];

        try {
            $state = 'configurator_' . $mode . '_' . bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            $state = 'configurator_' . $mode . '_' . uniqid('', true);
        }

        $result = $this->SendDataToParent(json_encode([
            'DataID' => self::DATA_ID,
            'Command' => 'BuildConnectURL',
            'Mode' => $mode,
            'State' => $state,
            'Permissions' => $permissions
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $decoded = json_decode((string)$result, true);

        if (!is_array($decoded) || empty($decoded['success'])) {
            return 'Fehler beim Erzeugen der Connect URL: ' . (string)$result;
        }

        return (string)($decoded['url'] ?? '');
    }

    public function ReloadConfiguratorForm(): void
    {
        $this->ReloadForm();
    }

    private function LoadConnectionsFromSplitter(): array
    {
        if (!$this->HasActiveParent()) {
            return [];
        }

        $result = $this->SendDataToParent(json_encode([
            'DataID' => self::DATA_ID,
            'Command' => 'LoadConnections'
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $decoded = json_decode((string)$result, true);

        if (!is_array($decoded)) {
            $this->SendDebug('Connections', 'Ungültige Antwort vom Splitter: ' . (string)$result, 0);
            return [];
        }

        return $decoded;
    }

    private function FindVehicleInstanceByVehicleId(string $vehicleId): int
    {
        $instanceIds = @IPS_GetInstanceListByModuleID(self::VEHICLE_MODULE_ID);
        if (!is_array($instanceIds)) {
            return 0;
        }

        foreach ($instanceIds as $instanceId) {
            if (!IPS_InstanceExists((int)$instanceId)) {
                continue;
            }

            $currentVehicleId = @IPS_GetProperty((int)$instanceId, 'VehicleID');
            if ((string)$currentVehicleId === $vehicleId) {
                return (int)$instanceId;
            }
        }

        return 0;
    }

    private function GetConfiguratorLocation(): array
    {
        $location = [];
        $objectId = IPS_GetParent($this->InstanceID);

        while ($objectId > 0 && IPS_ObjectExists($objectId)) {
            $object = IPS_GetObject($objectId);

            // Nur Kategorien eignen sich als Zielpfad für "location".
            if ((int)($object['ObjectType'] ?? -1) === 0) {
                $name = trim((string)($object['ObjectName'] ?? ''));
                if ($name !== '') {
                    array_unshift($location, $name);
                }
            }

            $objectId = (int)($object['ParentID'] ?? 0);
        }

        return $location;
    }
}
