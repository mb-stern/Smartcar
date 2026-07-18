<?php

class SmartcarConfigurator extends IPSModuleStrict
{
    private const SPLITTER_MODULE_ID = '{9F7A4B2C-3D1E-4A6F-8B20-6C5D4E3F2A10}';
    private const VEHICLE_MODULE_ID  = '{1E1B7C9A-2D4F-4E8A-9C3B-7F6D5A4E2B10}';
    private const DATA_ID            = '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}';

    public function Create(): void
    {
        parent::Create();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetStatus($this->HasParentConnection() ? 102 : 201);
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
        if (!$this->HasParentConnection()) {
            return json_encode([
                'elements' => [
                    [
                        'type' => 'Label',
                        'caption' => 'Der Smartcar-Konfigurator ist mit keinem Smartcar Splitter verbunden.'
                    ]
                ],
                'actions' => []
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $connections = $this->LoadConnectionsFromSplitter();
        $values = [];

        foreach ($connections as $connection) {
            if (!is_array($connection)) {
                continue;
            }

            $vehicleId = trim((string)($connection['vehicleId'] ?? ''));
            if ($vehicleId === '') {
                continue;
            }

            $connectionId = (string)($connection['connectionId'] ?? '');
            $userId = (string)($connection['userId'] ?? '');
            $caption = trim((string)($connection['caption'] ?? ''));

            if ($caption === '') {
                $caption = $vehicleId;
            }

            $make = (string)($connection['make'] ?? '');
            $model = (string)($connection['model'] ?? '');
            $year = (int)($connection['year'] ?? 0);
            $mode = (string)($connection['mode'] ?? '');
            $powertrainType = (string)($connection['powertrainType'] ?? '');

            $instanceId = $this->FindVehicleInstanceByVehicleId($vehicleId);

            $values[] = [
                'instanceID' => $instanceId,
                'name' => $caption,
                'address' => $vehicleId,
                'vehicleId' => $vehicleId,
                'connectionId' => $connectionId,
                'mode' => $mode,
                'powertrainType' => $powertrainType,
                'create' => [
                    'moduleID' => self::VEHICLE_MODULE_ID,
                    'name' => $caption,
                    'configuration' => [
                        'VehicleID' => $vehicleId,
                        'ConnectionID' => $connectionId,
                        'UserID' => $userId,
                        'VehicleCaption' => $caption,
                        'Make' => $make,
                        'Model' => $model,
                        'Year' => $year,
                        'PowertrainType' => $powertrainType
                    ]
                ]
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

    private function LoadConnectionsFromSplitter(): array
    {
        if (!$this->HasParentConnection()) {
            return [];
        }

        $result = $this->SendDataToParent(json_encode([
            'DataID' => self::DATA_ID,
            'Command' => 'LoadConnections'
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $decoded = json_decode((string)$result, true);

        if (!is_array($decoded)) {
            $this->SendDebug(
                'Connections',
                'Ungültige Antwort vom Splitter: ' . (string)$result,
                0
            );
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
            if ((string)@IPS_GetProperty($instanceId, 'VehicleID') === $vehicleId) {
                return (int)$instanceId;
            }
        }

        return 0;
    }

    private function HasParentConnection(): bool
    {
        $instance = @IPS_GetInstance($this->InstanceID);
        if (!is_array($instance)) {
            return false;
        }

        return ((int)($instance['ConnectionID'] ?? 0)) > 0;
    }

    public function GenerateConnectURL(string $mode): string
    {
        if (!$this->HasParentConnection()) {
            return 'Fehler: Der Smartcar-Konfigurator ist mit keinem Splitter verbunden.';
        }

        $mode = strtolower(trim($mode));
        if ($mode !== 'simulated') {
            $mode = 'live';
        }

        // Keine expliziten OAuth-Scopes mitsenden.
        // Smartcar verwendet dadurch die im Dashboard veröffentlichte
        // Vehicle-Access-Konfiguration der Application.
        $permissions = [];

        $state = 'configurator_' . $mode . '_' . bin2hex(random_bytes(8));

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
}
