<?php

class SmartcarConfigurator extends IPSModuleStrict
{

    public function Create(): void
    {
        parent::Create();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetStatus(102);
    }

    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type' => 'connect',
            'moduleIDs' => [
                '{9F7A4B2C-3D1E-4A6F-8B20-6C5D4E3F2A10}'
            ]
        ]);
    }

    public function GetConfigurationForm(): string
    {
        $connections = $this->LoadConnectionsFromSplitter();
        $values = [];

        foreach ($connections as $connection) {
            $vehicleId = (string)($connection['vehicleId'] ?? '');
            if ($vehicleId === '') {
                continue;
            }

            $instanceId = $this->FindVehicleInstanceByVehicleId($vehicleId);

            $values[] = [
                'instanceID'     => $instanceId,
                'connectionId'   => (string)($connection['connectionId'] ?? ''),
                'vehicleId'      => $vehicleId,
                'userId'         => (string)($connection['userId'] ?? ''),
                'caption'        => (string)($connection['caption'] ?? $vehicleId),
                'make'           => (string)($connection['make'] ?? ''),
                'model'          => (string)($connection['model'] ?? ''),
                'year'           => (string)($connection['year'] ?? ''),
                'mode'           => (string)($connection['mode'] ?? ''),
                'powertrainType' => (string)($connection['powertrainType'] ?? ''),
                'status'         => $instanceId > 0 ? 'vorhanden' : 'nicht erstellt'
            ];
        }

        $form = [
            'actions' => [
               [
                    'type' => 'Button',
                    'caption' => 'Neues Live-Fahrzeug verbinden',
                    'onClick' => 'echo SMCARCFG_GenerateConnectURL($id, "live");'
                ],
                [
                    'type' => 'Button',
                    'caption' => 'Liste aktualisieren',
                    'onClick' => 'SMCARCFG_ReloadConfiguratorForm($id);'
                ],
                [
                    'type' => 'Button',
                    'caption' => 'Alle fehlenden Fahrzeug-Instanzen erstellen',
                    'onClick' => 'SMCARCFG_CreateMissingVehicleInstances($id);'
                ],
                [
                    'type' => 'List',
                    'name' => 'Vehicles',
                    'caption' => 'Smartcar Fahrzeuge',
                    'rowCount' => 10,
                    'add' => false,
                    'delete' => false,
                    'sort' => [
                        'column' => 'caption',
                        'direction' => 'ascending'
                    ],
                    'columns' => [
                        [
                            'caption' => 'Instanz',
                            'name' => 'instanceID',
                            'width' => '90px'
                        ],
                        [
                            'caption' => 'Status',
                            'name' => 'status',
                            'width' => '110px'
                        ],
                        [
                            'caption' => 'Fahrzeug',
                            'name' => 'caption',
                            'width' => 'auto'
                        ],
                        [
                            'caption' => 'Vehicle ID',
                            'name' => 'vehicleId',
                            'width' => '250px'
                        ],
                        [
                            'caption' => 'Connection ID',
                            'name' => 'connectionId',
                            'width' => '250px',
                            'visible' => false
                        ],
                        [
                            'caption' => 'Modus',
                            'name' => 'mode',
                            'width' => '100px'
                        ],
                        [
                            'caption' => 'Powertrain',
                            'name' => 'powertrainType',
                            'width' => '120px'
                        ]
                    ],
                    'values' => $values
                ]
            ]
        ];

        return json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function CreateMissingVehicleInstances(): void
    {
        $connections = $this->LoadConnectionsFromSplitter();

        foreach ($connections as $connection) {
            $vehicleId = (string)($connection['vehicleId'] ?? '');
            if ($vehicleId === '') {
                continue;
            }

            $instanceId = $this->FindVehicleInstanceByVehicleId($vehicleId);

            if ($instanceId > 0) {
                $splitterId = $this->GetSplitterInstanceID();
                if ($splitterId > 0) {
                    @IPS_ConnectInstance($instanceId, $splitterId);
                }

                $this->UpdateVehicleInstance($instanceId, $connection);
                continue;
            }

            $instanceId = IPS_CreateInstance('{1E1B7C9A-2D4F-4E8A-9C3B-7F6D5A4E2B10}');

            $targetParentId = IPS_GetParent($this->InstanceID);
            $currentParentId = IPS_GetParent($instanceId);

            if ($targetParentId > 0 && $currentParentId !== $targetParentId) {
                @IPS_SetParent($instanceId, $targetParentId);
            }

            $splitterId = $this->GetSplitterInstanceID();
            if ($splitterId > 0) {
                @IPS_ConnectInstance($instanceId, $splitterId);
            }

            $this->UpdateVehicleInstance($instanceId, $connection);
        }

        $this->ReloadForm();
    }

    private function LoadConnectionsFromSplitter(): array
    {
        $result = $this->SendDataToParent(json_encode([
            'DataID' => '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}',
            'Command' => 'LoadConnections'
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $decoded = json_decode((string)$result, true);

        if (!is_array($decoded)) {
            $this->SendDebug('Connections', 'Ungültige Antwort vom Splitter: ' . (string)$result, 0);
            return [];
        }

        return $decoded;
    }

    private function UpdateVehicleInstance(int $instanceId, array $connection): void
    {
        $vehicleId = (string)($connection['vehicleId'] ?? '');

        $caption = (string)($connection['caption'] ?? '');
        if ($caption === '') {
            $caption = $vehicleId;
        }

        IPS_SetName($instanceId, $caption);

        IPS_SetProperty($instanceId, 'VehicleID', $vehicleId);
        IPS_SetProperty($instanceId, 'ConnectionID', (string)($connection['connectionId'] ?? ''));
        IPS_SetProperty($instanceId, 'UserID', (string)($connection['userId'] ?? ''));
        IPS_SetProperty($instanceId, 'VehicleCaption', $caption);
        IPS_SetProperty($instanceId, 'Make', (string)($connection['make'] ?? ''));
        IPS_SetProperty($instanceId, 'Model', (string)($connection['model'] ?? ''));
        IPS_SetProperty($instanceId, 'Year', (int)($connection['year'] ?? 0));
        IPS_SetProperty($instanceId, 'PowertrainType', (string)($connection['powertrainType'] ?? ''));

        IPS_ApplyChanges($instanceId);
    }

    private function FindVehicleInstanceByVehicleId(string $vehicleId): int
    {
        $instanceIds = @IPS_GetInstanceListByModuleID('{1E1B7C9A-2D4F-4E8A-9C3B-7F6D5A4E2B10}');
        if (!is_array($instanceIds)) {
            return 0;
        }

        foreach ($instanceIds as $instanceId) {
            $currentVehicleId = @IPS_GetProperty($instanceId, 'VehicleID');
            if ((string)$currentVehicleId === $vehicleId) {
                return (int)$instanceId;
            }
        }

        return 0;
    }

    private function GetSplitterInstanceID(): int
    {
        $instance = @IPS_GetInstance($this->InstanceID);
        if (!is_array($instance)) {
            return 0;
        }

        return (int)($instance['ConnectionID'] ?? 0);
    }

    public function GenerateConnectURL(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if ($mode !== 'simulated') {
            $mode = 'live';
        }

        // Initial-Connect wie im ursprünglichen, funktionierenden Flow:
        // explizite Basis-Permissions statt der Dashboard-Standardauswahl.
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

        $state = 'configurator_' . $mode . '_' . bin2hex(random_bytes(8));

        $result = $this->SendDataToParent(json_encode([
            'DataID'      => '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}',
            'Command'     => 'BuildConnectURL',
            'Mode'        => $mode,
            'State'       => $state,
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
}