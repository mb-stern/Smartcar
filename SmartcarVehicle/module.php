<?php

class SmartcarVehicle extends IPSModuleStrict
{
    private const SPLITTER_MODULE_ID = '{9F7A4B2C-3D1E-4A6F-8B20-6C5D4E3F2A10}';

    public function Create(): void
{
    parent::Create();

    $this->RegisterPropertyString('VehicleID', '');
    $this->RegisterPropertyString('ConnectionID', '');
    $this->RegisterPropertyString('UserID', '');
    $this->RegisterPropertyString('VehicleCaption', '');

    $this->RegisterPropertyString('Make', '');
    $this->RegisterPropertyString('Model', '');
    $this->RegisterPropertyInteger('Year', 0);
    $this->RegisterPropertyString('PowertrainType', '');

    $this->RegisterPropertyString('CompatibilityRegion', 'EUROPE');
    $this->RegisterPropertyString('SelectedCapabilities', '[]');

    $this->RegisterPropertyString('ConnectMode', 'live');

    $this->RegisterAttributeString('CompatibilityCache', '[]');
    $this->RegisterAttributeInteger('CompatibilityCacheAt', 0);
}

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->CreateProfile();

        if ($this->ReadPropertyString('VehicleID') === '') {
            $this->SetStatus(201);
            return;
        }

        $this->SetStatus(102);

        $this->ApplySelectedCapabilities();
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
        $capabilities = [];

        if ($this->HasParentConnection()) {
            $capabilities = $this->GetCompatibilityCapabilitiesForForm();
        }

        $form = [
            'elements' => [
                ['type' => 'Label', 'caption' => 'Vehicle ID: ' . $this->ReadPropertyString('VehicleID')],
                ['type' => 'Label', 'caption' => 'Connection ID: ' . $this->ReadPropertyString('ConnectionID')],
                ['type' => 'Label', 'caption' => 'User ID: ' . $this->ReadPropertyString('UserID')],
                ['type' => 'Label', 'caption' => 'Fahrzeug: ' . $this->ReadPropertyString('VehicleCaption')],
                ['type' => 'Label', 'caption' => 'Antrieb: ' . $this->ReadPropertyString('PowertrainType')],

                [
                    'type' => 'Select',
                    'name' => 'ConnectMode',
                    'caption' => 'Connect Modus',
                    'options' => [
                        ['caption' => 'Live', 'value' => 'live'],
                        ['caption' => 'Simuliert', 'value' => 'simulated']
                    ]
                ],
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
                'type' => 'List',
                'name' => 'SelectedCapabilities',
                'caption' => 'Kompatible Signale / Befehle',
                'rowCount' => 10,
                'add' => false,
                'delete' => false,
                'sort' => [
                    'column' => 'sortKey',
                    'direction' => 'ascending'
                ],
                'columns' => [
                    [
                        'caption' => '',
                        'name' => 'sortKey',
                        'width' => '0px',
                        'visible' => false,
                        'edit' => ['type' => 'ValidationTextBox']
                    ],
                    [
                        'caption' => 'Aktiv',
                        'name' => 'selected',
                        'width' => '80px',
                        'edit' => ['type' => 'CheckBox']
                    ],
                    [
                        'caption' => 'Typ',
                        'name' => 'type',
                        'width' => '90px',
                    ],
                    [
                        'caption' => 'Gruppe',
                        'name' => 'group',
                        'width' => '160px',
                    ],
                    [
                        'caption' => 'Name',
                        'name' => 'name',
                        'width' => 'auto',
                    ],
                    [
                        'caption' => 'Capability',
                        'name' => 'capability',
                        'width' => '220px',
                    ],
                    [
                        'caption' => 'Code',
                        'name' => 'code',
                        'width' => '220px',
                    ],
                    [
                        'caption' => 'Permission',
                        'name' => 'permission',
                        'width' => '180px',
                    ]
                ],
                'values' => $capabilities
            ]
            ],
            'actions' => [
                [
                    'type' => 'Button',
                    'caption' => 'Kompatibilitätsliste neu laden',
                    'onClick' => 'SMCARV_ReloadCompatibility($id);'
                ],
                [
                    'type' => 'Button',
                    'caption' => 'Mit SMartcar verbinden',
                    'onClick' => 'echo SMCARV_GenerateConnectURL($id);'
                ],
                [
                    'type' => 'Button',
                    'caption' => 'Aktivierte Signale abrufen',
                    'onClick' => 'SMCARV_FetchSelectedSignals($id);'
                ]
            ]
        ];

        return json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function ReloadCompatibility(): void
    {
        $this->LoadCompatibility(true);
        $this->ReloadForm();
    }

    private function GetCompatibilityCapabilitiesForForm(): array
    {
        $data = $this->LoadCompatibility(false);
        $values = $this->BuildCapabilitiesListFromCompatibilityItems($data);

        $selected = json_decode($this->ReadPropertyString('SelectedCapabilities'), true);
        if (!is_array($selected)) {
            $selected = [];
        }

        $selectedBySortKey = [];

        foreach ($selected as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $sortKey = (string)($entry['sortKey'] ?? '');
            if ($sortKey === '') {
                continue;
            }

            $selectedBySortKey[$sortKey] =
                ($entry['selected'] ?? false) === true ||
                ($entry['selected'] ?? false) === 1 ||
                ($entry['selected'] ?? false) === '1' ||
                strtolower((string)($entry['selected'] ?? '')) === 'true';
        }

        foreach ($values as &$entry) {
            $sortKey = (string)($entry['sortKey'] ?? '');
            if ($sortKey !== '' && isset($selectedBySortKey[$sortKey])) {
                $entry['selected'] = $selectedBySortKey[$sortKey];
            }
        }
        unset($entry);

        return $values;
    }

    private function LoadCompatibility(bool $forceReload): array
    {

    if (!$this->HasParentConnection()) {
        $this->SendDebug('Compatibility/Error', 'Kein Splitter/Parent verbunden.', 0);
        return [];
    }
        $cacheAt = $this->ReadAttributeInteger('CompatibilityCacheAt');
        $cacheRaw = $this->ReadAttributeString('CompatibilityCache');

        if (!$forceReload && $cacheRaw !== '' && $cacheAt > (time() - 86400)) {
            $cached = json_decode($cacheRaw, true);
            if (is_array($cached)) {
                $this->SendDebug('Compatibility/Cache', 'Cache verwendet. Einträge: ' . count($cached), 0);
                return $cached;
            }
        }

        $make = $this->NormalizeCompatibilityMake($this->ReadPropertyString('Make'));
        $model = $this->ReadPropertyString('Model');
        $year = $this->ReadPropertyInteger('Year');
        $powertrainType = $this->ReadPropertyString('PowertrainType');
        $region = $this->ReadPropertyString('CompatibilityRegion');

        $request = [
            'DataID' => '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}',
            'Command' => 'GetCompatibleVehicles',
            'Make' => $make,
            'PowertrainType' => $powertrainType,
            'Region' => $region
        ];

        $this->SendDebug('Compatibility/Request', json_encode([
            'make' => $make,
            'model' => $model,
            'year' => $year,
            'powertrainType' => $powertrainType,
            'region' => $region
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        $result = $this->SendDataToParent(json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->SendDebug('Compatibility/RAW', (string)$result, 0);

        $decoded = json_decode((string)$result, true);

        if (!is_array($decoded)) {
            $this->SendDebug('Compatibility/Error', 'Antwort ist kein JSON.', 0);
            return [];
        }

        if (empty($decoded['success'])) {
            $this->SendDebug('Compatibility/Error', 'success=false: ' . json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
            return [];
        }

        $body = is_array($decoded['body'] ?? null) ? $decoded['body'] : [];

        $this->SendDebug('Compatibility/BodyKeys', implode(', ', array_keys($body)), 0);

        $items = is_array($body['data'] ?? null) ? $body['data'] : [];

        $this->SendDebug('Compatibility/DataCount', 'Ungefilterte Einträge: ' . count($items), 0);

        $filtered = [];
        $normalizedVehicleModel = $this->NormalizeText($model);

        foreach ($items as $item) {
            $attributes = is_array($item['attributes'] ?? null) ? $item['attributes'] : [];

            $itemModel = $this->NormalizeText((string)($attributes['model'] ?? ''));
            $years = is_array($attributes['years'] ?? null) ? $attributes['years'] : [];

            $startYear = (int)($years['start'] ?? 0);
            $endYear = (int)($years['end'] ?? 9999);

            $yearMatches = ($year <= 0 || ($year >= $startYear && $year <= $endYear));

            $modelMatches =
                $normalizedVehicleModel === '' ||
                $itemModel === '' ||
                str_contains($normalizedVehicleModel, $itemModel) ||
                str_contains($itemModel, $normalizedVehicleModel) ||
                str_contains($normalizedVehicleModel, explode(' ', $itemModel)[0] ?? $itemModel);

            if ($yearMatches && $modelMatches) {
                $filtered[] = $item;
            }
        }

        $this->SendDebug('Compatibility/FilteredCount', 'Gefilterte Einträge: ' . count($filtered), 0);

        if (empty($filtered)) {
            $this->SendDebug('Compatibility/Fallback', 'Keine exakte Modell/Jahr-Übereinstimmung. Verwende ungefilterte Einträge.', 0);
            $filtered = $items;
        }

        if (!empty($filtered)) {
            $this->WriteAttributeString(
                'CompatibilityCache',
                json_encode($filtered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
            $this->WriteAttributeInteger('CompatibilityCacheAt', time());
        } else {
            $this->WriteAttributeString('CompatibilityCache', '[]');
            $this->WriteAttributeInteger('CompatibilityCacheAt', 0);
            $this->SendDebug('Compatibility/Cache', 'Leeres Ergebnis wird nicht gecacht.', 0);
        }

        return $filtered;
    }

    private function NormalizeCompatibilityMake(string $make): string
    {
        return match (strtoupper(trim($make))) {
            'MERCEDES_BENZ', 'MERCEDES-BENZ' => 'MERCEDES-BENZ',
            default => trim($make)
        };
    }
    private function NormalizeText(string $text): string
    {
        $text = strtoupper(trim($text));
        $text = str_replace(['_', '-'], ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return $text;
    }

    public function FetchSelectedSignals(array $onlySignalCodes = []): void
    {
        if (!empty($onlySignalCodes)) {
            $this->SendDebug(
                'FetchSignals/Start',
                'Teilabruf für neue Signale: ' . implode(', ', $onlySignalCodes),
                0
            );
        } else {
            $this->SendDebug('FetchSignals/Start', 'Sammelabruf aller Signale gestartet.', 0);
        }

        if (!$this->HasParentConnection()) {
            $this->SendDebug('FetchSignals/Error', 'Kein Splitter/Parent verbunden.', 0);
            return;
        }

        $vehicleId = $this->ReadPropertyString('VehicleID');
        $userId    = $this->ReadPropertyString('UserID');

        if ($vehicleId === '' || $userId === '') {
            $this->SendDebug('FetchSignals/Error', 'VehicleID oder UserID fehlt.', 0);
            return;
        }

        $result = $this->SendDataToParent(json_encode([
            'DataID'    => '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}',
            'Command'   => 'GetSignals',
            'VehicleID' => $vehicleId,
            'UserID'    => $userId
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        //$this->SendDebug('FetchSignals/RAW', (string)$result, 0);

        $decoded = json_decode((string)$result, true);
        if (!is_array($decoded) || empty($decoded['success'])) {
            $this->SendDebug('FetchSignals/Error', 'GetSignals fehlgeschlagen: ' . (string)$result, 0);
            return;
        }

        $signals = $decoded['body']['data']['signals']
            ?? $decoded['body']['data']
            ?? $decoded['body']['signals']
            ?? [];

        if (!is_array($signals)) {
            $this->SendDebug('FetchSignals/Error', 'Keine Signals im Response gefunden.', 0);
            return;
        }

        $selectedMap = $this->GetSelectedSignalMap();

        $onlyMap = [];
        foreach ($onlySignalCodes as $code) {
            $onlyMap[(string)$code] = true;
        }

        $applied = 0;
        $skipped = 0;

        foreach ($signals as $signal) {
            if (!is_array($signal)) {
                continue;
            }

            $signalCode = (string)($signal['code'] ?? $signal['id'] ?? '');
            if ($signalCode === '') {
                $skipped++;
                continue;
            }

            if (!empty($onlyMap) && !isset($onlyMap[$signalCode])) {
                $skipped++;
                continue;
            }

            
            if (!isset($selectedMap[$signalCode])) {
                $this->SendDebug('FetchSignals/SkipNotSelected', $signalCode, 0);
                $skipped++;
                continue;
            }

            if (!empty($onlyMap) && !isset($onlyMap[$signalCode])) {
                $this->SendDebug('FetchSignals/SkipNotNew', $signalCode, 0);
                $skipped++;
                continue;
            }

            $this->ApplySignalFromV3($signalCode, $body, $status, $selectedMap[$signalCode]);

            $attributes = is_array($signal['attributes'] ?? null) ? $signal['attributes'] : $signal;

            $body = is_array($attributes['body'] ?? null) ? $attributes['body'] : [];
            $status = is_array($attributes['status'] ?? null) ? $attributes['status'] : null;
            $meta = is_array($signal['meta'] ?? null) ? $signal['meta'] : [];

            $this->SendDebug('FetchSignals/Meta/' . $signalCode, json_encode([
                'retrievedAt'  => $this->FormatSmartcarTimestamp($meta['retrievedAt'] ?? null),
                'oemUpdatedAt' => $this->FormatSmartcarTimestamp($meta['oemUpdatedAt'] ?? null)
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

            $this->ApplySignalFromV3($signalCode, $body, $status, $selectedMap[$signalCode]);
            $applied++;
        }

        $this->SendDebug(
            'FetchSignals/Done',
            json_encode([
                'mode'    => empty($onlySignalCodes) ? 'full' : 'partial',
                'requested' => count($onlySignalCodes),
                'applied' => $applied,
                'skipped' => $skipped
            ]),
            0
        );
    }

    public function ProcessWebhookSignals(string $payloadJson): void
    {
        $this->SendDebug('WebhookVehicle/Start', 'Payload empfangen.', 0);

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            $this->SendDebug('WebhookVehicle/Error', 'Payload ist kein JSON: ' . $payloadJson, 0);
            return;
        }

        $payloadVehicleId = (string)(
            $payload['vehicleId']
            ?? $payload['data']['vehicle']['id']
            ?? $payload['data']['vehicleId']
            ?? ''
        );

        $myVehicleId = $this->ReadPropertyString('VehicleID');

        if ($payloadVehicleId !== '' && $myVehicleId !== '' && $payloadVehicleId !== $myVehicleId) {
            $this->SendDebug('WebhookVehicle/Skip', 'VehicleID passt nicht. payload=' . $payloadVehicleId . ' instance=' . $myVehicleId, 0);
            return;
        }

        $signals = [];

        if (isset($payload['data']['signals']) && is_array($payload['data']['signals'])) {
            $signals = $payload['data']['signals'];
        } elseif (isset($payload['signals']) && is_array($payload['signals'])) {
            $signals = $payload['signals'];
        }

        if (empty($signals)) {
            $this->SendDebug('WebhookVehicle/Error', 'Keine signals[] im Payload: ' . $payloadJson, 0);
            return;
        }

        $triggers = [];
        if (isset($payload['data']['triggers']) && is_array($payload['data']['triggers'])) {
            $triggers = $payload['data']['triggers'];
        } elseif (isset($payload['triggers']) && is_array($payload['triggers'])) {
            $triggers = $payload['triggers'];
        }

        $this->SendDebug('WebhookVehicle/Info', json_encode([
            'eventId'         => $payload['eventId'] ?? '',
            'vehicleId'       => $payloadVehicleId,
            'signalsReceived' => count($signals),
            'triggers'        => $triggers,
            'meta'            => $payload['meta'] ?? []
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        $selectedMap = $this->GetSelectedSignalMap();

        $oemDates = [];

        foreach ($signals as $signal) {
            if (!is_array($signal)) {
                continue;
            }

            $signalCode = (string)($signal['code'] ?? '');
            if ($signalCode === '') {
                continue;
            }

            $meta = is_array($signal['meta'] ?? null) ? $signal['meta'] : [];

            $oemDates[$signalCode] = [
                'ingestedAt'   => $this->FormatSmartcarTimestamp($meta['ingestedAt'] ?? null),
                'retrievedAt'  => $this->FormatSmartcarTimestamp($meta['retrievedAt'] ?? null),
                'oemUpdatedAt' => $this->FormatSmartcarTimestamp($meta['oemUpdatedAt'] ?? null)
            ];

            if (!isset($selectedMap[$signalCode])) {
                $this->SendDebug('WebhookVehicle/Skip', 'Signal nicht aktiviert: ' . $signalCode, 0);
                continue;
            }

            $body   = is_array($signal['body'] ?? null) ? $signal['body'] : [];
            $status = is_array($signal['status'] ?? null) ? $signal['status'] : null;

            $this->SendDebug('WebhookVehicle/Apply/' . $signalCode, json_encode([
                'body'   => $body,
                'status' => $status
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

            $this->ApplySignalFromV3($signalCode, $body, $status, $selectedMap[$signalCode]);
        }

        if (!empty($oemDates)) {
            $this->SendDebug('WebhookVehicle/OEM-Date', json_encode($oemDates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
        }
    }

    private function GetSelectedSignalMap(): array
    {
        $entries = $this->GetSelectedCapabilitiesResolved();
        $map = [];

        foreach ($entries as $entry) {
            if (strtolower((string)($entry['type'] ?? '')) !== 'signal') {
                continue;
            }

            $signalCode = (string)($entry['capability'] ?? '');
            if ($signalCode === '') {
                $signalCode = (string)($entry['code'] ?? '');
            }

            if ($signalCode !== '') {
                $map[$signalCode] = $entry;
            }
        }

        return $map;
    }

    private function ApplySignalFromV3(string $code, array $body, ?array $status, array $meta): void
    {
        if ($status !== null && isset($status['value'])) {
            $statusValue = strtoupper((string)$status['value']);

            if ($statusValue !== 'SUCCESS' && $statusValue !== 'OK') {
                $this->SendDebug(
                    'SignalStatus/' . $code,
                    json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    0
                );
                return;
            }
        }

        $definition = $this->GetSignalDefinition($code, $body);
        $variables = $this->GetVariablesFromDefinition($definition, $body);

        foreach ($variables as $variable) {
            $ident = (string)($variable['ident'] ?? '');
            if ($ident === '') {
                continue;
            }

            $source = (string)($variable['source'] ?? 'value');

            if (!array_key_exists($source, $body)) {
                continue;
            }

            $value = $body[$source];

            if (isset($variable['factor']) && is_numeric($value)) {
                $value = (float)$value * (float)$variable['factor'];
            }

            if (isset($variable['offset']) && is_numeric($value)) {
                $value = (float)$value + (float)$variable['offset'];
            }

            $this->RegisterOrUpdateTypedVariable(
                $ident,
                (string)($variable['name'] ?? $ident),
                $value,
                (int)$variable['type'],
                (string)($variable['profile'] ?? '')
            );
        }

        if (empty($variables) && !empty($body)) {
            $ident = (string)($definition['ident'] ?? '');
            if ($ident === '') {
                return;
            }

            $this->RegisterOrUpdateTypedVariable(
                $ident,
                (string)($definition['name'] ?? $code),
                json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                VARIABLETYPE_STRING,
                ''
            );
        }
    }

    private function BuildSignalIdent(string $code): string
    {
        return 'Sig_' . preg_replace('/[^A-Za-z0-9_]/', '_', $code);
    }

    public function GenerateConnectURL(): string
    {
        $this->SendDebug('Connect/Start', 'GenerateConnectURL gestartet.', 0);

        $permissions = $this->GetSelectedPermissions();

        $this->SendDebug(
            'Connect/Result',
            'Permissions count=' . count($permissions) . ' values=' . json_encode($permissions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

        if (empty($permissions)) {
            return 'Fehler: Keine aktivierten Signale/Befehle mit Permission ausgewählt. Bitte Debug prüfen: Connect/SelectedCapabilitiesRaw, Connect/Entry und Connect/Summary.';
        }

        $state = 'vehicle_' . $this->ReadPropertyString('VehicleID') . '_' . bin2hex(random_bytes(8));

        $request = [
            'DataID'      => '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}',
            'Command'     => 'BuildConnectURL',
            'Mode'        => $this->ReadPropertyString('ConnectMode'),
            'State'       => $state,
            'Permissions' => $permissions
        ];

        $this->SendDebug('Connect/RequestToSplitter', json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        $result = $this->SendDataToParent(json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->SendDebug('Connect/SplitterResponse', (string)$result, 0);

        $decoded = json_decode((string)$result, true);
        if (!is_array($decoded) || empty($decoded['success'])) {
            return 'Fehler beim Erzeugen der Connect URL: ' . (string)$result;
        }

        return (string)($decoded['url'] ?? '');
    }

    private function GetSelectedPermissions(): array
    {
        $entries = $this->GetSelectedCapabilitiesResolved();
        $permissions = [];

        foreach ($entries as $entry) {
            $permission = trim((string)($entry['permission'] ?? ''));

            if ($permission !== '') {
                $permissions[$permission] = true;
            }
        }

        return array_keys($permissions);
    }

    private function CreateSignalVariable(string $signalCode, string $name): bool
    {
        $definition = $this->GetSignalDefinition($signalCode, []);
        $createdAny = false;

        foreach ($this->GetVariablesFromDefinition($definition, []) as $variable) {
            $created = $this->RegisterOrUpdateTypedVariable(
                $variable['ident'],
                $variable['name'],
                $this->GetDefaultValueForType($variable['type']),
                $variable['type'],
                $variable['profile'],
                true
            );

            if ($created) {
                $createdAny = true;
            }
        }

        return $createdAny;
    }

    private function FetchSingleSelectedSignal(string $signalCode): void
    {
        if (!$this->HasParentConnection()) {
            return;
        }

        $vehicleId = $this->ReadPropertyString('VehicleID');
        $userId = $this->ReadPropertyString('UserID');

        if ($vehicleId === '' || $userId === '') {
            return;
        }

        $result = $this->SendDataToParent(json_encode([
            'DataID'     => '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}',
            'Command'    => 'GetSignal',
            'VehicleID'  => $vehicleId,
            'UserID'     => $userId,
            'SignalCode' => $signalCode
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $decoded = json_decode((string)$result, true);
        if (!is_array($decoded) || empty($decoded['success'])) {
            $this->SendDebug('FetchSignal/Error/' . $signalCode, (string)$result, 0);
            return;
        }

        $signal = $decoded['body']['data']
            ?? $decoded['body']
            ?? [];

        if (!is_array($signal)) {
            return;
        }

        $attributes = is_array($signal['attributes'] ?? null) ? $signal['attributes'] : $signal;

        $body = is_array($attributes['body'] ?? null) ? $attributes['body'] : [];
        $status = is_array($attributes['status'] ?? null) ? $attributes['status'] : null;

        $definitionMeta = $this->GetSignalDefinition($signalCode, $body);

        $this->ApplySignalFromV3($signalCode, $body, $status, $definitionMeta);
    }

    private function GetVariablesFromDefinition(array $definition, array $body): array
    {
        if (($definition['special'] ?? '') === 'multiple') {
            return $definition['variables'] ?? [];
        }

        return [[
            'ident'   => $definition['ident'],
            'name'    => $definition['name'],
            'type'    => $definition['type'],
            'profile' => $definition['profile'],
            'source'  => 'value'
        ]];
    }

    private function GetDefaultValueForType(int $type): mixed
    {
        return match ($type) {
            VARIABLETYPE_BOOLEAN => false,
            VARIABLETYPE_INTEGER => 0,
            VARIABLETYPE_FLOAT   => 0.0,
            default              => ''
        };
    }

    public function ApplySelectedCapabilities(): void
    {
        $selected = $this->GetSelectedCapabilitiesResolved();
        $wantedIdents = [];

        foreach ($selected as $entry) {
            if (strtolower((string)($entry['type'] ?? '')) !== 'signal') {
                continue;
            }

            $signalCode = (string)($entry['capability'] ?? '');
            if ($signalCode === '') {
                $signalCode = (string)($entry['code'] ?? '');
            }

            if ($signalCode === '') {
                continue;
            }

            $definition = $this->GetSignalDefinition($signalCode, []);

            foreach ($this->GetVariablesFromDefinition($definition, []) as $variable) {
                $ident = (string)($variable['ident'] ?? '');
                if ($ident !== '') {
                    $wantedIdents[$ident] = true;
                }
            }

            $name = (string)($entry['name'] ?? $signalCode);
            $created = $this->CreateSignalVariable($signalCode, $name);

            if ($created) {
                $newSignalCodes[$signalCode] = true;
            }
        }

        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            $object = IPS_GetObject($childId);
            $ident = (string)($object['ObjectIdent'] ?? '');

            if ($ident === '') {
                continue;
            }

            $isManagedVariable =
                strpos($ident, 'Sig_') === 0 ||
                $ident === 'Latitude' ||
                $ident === 'Longitude';

            if (!$isManagedVariable) {
                continue;
            }

            if (!isset($wantedIdents[$ident])) {
                $this->SendDebug('Variables/Delete', 'Lösche nicht mehr ausgewählte Variable: ' . $ident, 0);
                $this->UnregisterVariable($ident);
            }
        }

        if (!empty($newSignalCodes)) {
            $this->FetchSelectedSignals(array_keys($newSignalCodes));
        }
    }

    private function BuildCapabilitiesListFromCompatibilityItems(array $data): array
    {
        $temp = [];

        foreach ($data as $item) {
            $attributes = is_array($item['attributes'] ?? null) ? $item['attributes'] : [];
            $capabilities = is_array($attributes['capabilities'] ?? null) ? $attributes['capabilities'] : [];

            foreach ($capabilities as $capability) {
                if (!is_array($capability)) {
                    continue;
                }

                $type       = (string)($capability['type'] ?? '');
                $group      = (string)($capability['group'] ?? '');
                $name       = (string)($capability['name'] ?? '');
                $code       = (string)($capability['code'] ?? '');
                $capKey     = (string)($capability['capability'] ?? '');
                $permission = (string)($capability['permission'] ?? '');

                if ($code === '' && $capKey === '') {
                    continue;
                }

                $uniqueKey = strtolower($type . '|' . $group . '|' . $code . '|' . $capKey);

                if (!isset($temp[$uniqueKey])) {
                    $displayName = $name !== '' ? $name : $capKey;

                    $typeOrder = match (strtolower($type)) {
                        'signal'  => '0',
                        'command' => '1',
                        default   => '9'
                    };

                    $sortKey = $typeOrder
                        . '|' . strtoupper($group)
                        . '|' . strtoupper($displayName)
                        . '|' . strtoupper($code);

                    $temp[$uniqueKey] = [
                        'sortKey'    => $sortKey,
                        'selected'   => false,
                        'capability' => $capKey,
                        'type'       => $type,
                        'name'       => $displayName,
                        'group'      => $group,
                        'code'       => $code,
                        'permission' => $permission
                    ];
                    continue;
                }

                if ($temp[$uniqueKey]['permission'] === '' && $permission !== '') {
                    $temp[$uniqueKey]['permission'] = $permission;
                }
            }
        }

        $values = array_values($temp);

        usort($values, function ($a, $b) {
            return strcasecmp((string)$a['sortKey'], (string)$b['sortKey']);
        });

        return $values;
    }

    private function GetSelectedCapabilitiesResolved(): array
    {
        $saved = json_decode($this->ReadPropertyString('SelectedCapabilities'), true);
        if (!is_array($saved)) {
            $this->SendDebug('Selected/Resolve', 'SelectedCapabilities ist kein Array.', 0);
            return [];
        }

        $cache = json_decode($this->ReadAttributeString('CompatibilityCache'), true);

        if (!is_array($cache) || empty($cache)) {
            $this->SendDebug('Selected/Resolve', 'Cache leer, lade Compatibility neu.', 0);
            $cache = $this->LoadCompatibility(false);
        }

        if (!is_array($cache) || empty($cache)) {
            $this->SendDebug('Selected/Resolve', 'Keine Compatibility-Daten vorhanden.', 0);
            return [];
        }

        $fullList = $this->BuildCapabilitiesListFromCompatibilityItems($cache);

        $fullBySortKey = [];
        foreach ($fullList as $entry) {
            $sortKey = (string)($entry['sortKey'] ?? '');
            if ($sortKey !== '') {
                $fullBySortKey[$sortKey] = $entry;
            }
        }

        $result = [];

        foreach ($saved as $savedEntry) {
            if (!is_array($savedEntry)) {
                continue;
            }

            $selected =
                ($savedEntry['selected'] ?? false) === true ||
                ($savedEntry['selected'] ?? false) === 1 ||
                ($savedEntry['selected'] ?? false) === '1' ||
                strtolower((string)($savedEntry['selected'] ?? '')) === 'true';

            if (!$selected) {
                continue;
            }

            $sortKey = (string)($savedEntry['sortKey'] ?? '');
            if ($sortKey === '') {
                continue;
            }

            if (!isset($fullBySortKey[$sortKey])) {
                $this->SendDebug('Selected/ResolveMissing', 'Kein FullEntry für sortKey=' . $sortKey, 0);
                continue;
            }

            $entry = $fullBySortKey[$sortKey];
            $entry['selected'] = true;

            $result[] = $entry;
        }

        $this->SendDebug('Selected/Resolve', 'Ausgewählte Einträge: ' . count($result), 0);

        return $result;
    }

    private function HasParentConnection(): bool
    {
        $instance = @IPS_GetInstance($this->InstanceID);
        if (!is_array($instance)) {
            return false;
        }

        return ((int)($instance['ConnectionID'] ?? 0)) > 0;
    }

    private function FormatSmartcarTimestamp($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                $ts = (int)$value;

                // Smartcar Webhook-Zeitstempel können ms sein
                if ($ts > 20000000000) {
                    $ts = (int)floor($ts / 1000);
                }

                return date('d.m.Y H:i:s', $ts);
            }

            $dt = new DateTime((string)$value);
            $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));

            return $dt->format('d.m.Y H:i:s');
        } catch (Throwable $e) {
            return (string)$value;
        }
    }

    private function GuessSignalDefinition(string $code, array $body): array
    {
        $type = VARIABLETYPE_STRING;
        $profile = '';

        if (array_key_exists('value', $body)) {
            $value = $body['value'];

            if (is_bool($value)) {
                $type = VARIABLETYPE_BOOLEAN;
                $profile = '~Switch';
            } elseif (is_int($value)) {
                $type = VARIABLETYPE_INTEGER;
            } elseif (is_float($value) || is_numeric($value)) {
                $type = VARIABLETYPE_FLOAT;
            }
        }

        return [
            'ident'   => $this->BuildSignalIdent($code),
            'name'    => $code,
            'type'    => $type,
            'profile' => $profile,
            'factor'  => 1
        ];
    }

    private function CreateProfile(): void
    {
        if (!IPS_VariableProfileExists('SMCAR.Progress')) {
            IPS_CreateVariableProfile('SMCAR.Progress', VARIABLETYPE_FLOAT);
        }
        IPS_SetVariableProfileText('SMCAR.Progress', '', ' %');
        IPS_SetVariableProfileDigits('SMCAR.Progress', 0);
        IPS_SetVariableProfileValues('SMCAR.Progress', 0, 100, 1);

        if (!IPS_VariableProfileExists('SMCAR.Odometer')) {
            IPS_CreateVariableProfile('SMCAR.Odometer', VARIABLETYPE_FLOAT);
        }
        IPS_SetVariableProfileText('SMCAR.Odometer', '', ' km');
        IPS_SetVariableProfileDigits('SMCAR.Odometer', 0);
        IPS_SetVariableProfileValues('SMCAR.Odometer', 0, 0, 1);

        if (!IPS_VariableProfileExists('SMCAR.Power')) {
            IPS_CreateVariableProfile('SMCAR.Power', VARIABLETYPE_FLOAT);
        }
        IPS_SetVariableProfileText('SMCAR.Power', '', ' W');
        IPS_SetVariableProfileDigits('SMCAR.Power', 0);
        IPS_SetVariableProfileValues('SMCAR.Power', 0, 0, 1);

        if (!IPS_VariableProfileExists('SMCAR.TimeMinutes')) {
            IPS_CreateVariableProfile('SMCAR.TimeMinutes', VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileText('SMCAR.TimeMinutes', '', ' min');
        IPS_SetVariableProfileValues('SMCAR.TimeMinutes', 0, 0, 1);

        if (!IPS_VariableProfileExists('SMCAR.Energy')) {
            IPS_CreateVariableProfile('SMCAR.Energy', VARIABLETYPE_FLOAT);
        }
        IPS_SetVariableProfileText('SMCAR.Energy', '', ' kWh');
        IPS_SetVariableProfileDigits('SMCAR.Energy', 1);
        IPS_SetVariableProfileValues('SMCAR.Energy', 0, 0, 0.1);

        if (!IPS_VariableProfileExists('SMCAR.LatLon')) {
            IPS_CreateVariableProfile('SMCAR.LatLon', VARIABLETYPE_FLOAT);
        }
        IPS_SetVariableProfileDigits('SMCAR.LatLon', 6);
        IPS_SetVariableProfileText('SMCAR.LatLon', '', '°');
    }

    private function RegisterOrUpdateTypedVariable(string $ident, string $name, mixed $value, int $type, string $profile, bool $onlySetValueOnCreate = false): bool
    {
        $id = @$this->GetIDForIdent($ident);
        $created = false;

        if ($id) {
            $var = IPS_GetVariable($id);

            if ((int)$var['VariableType'] !== $type) {
                $this->SendDebug('Variables/TypeMismatch', 'Variable existiert mit falschem Typ und wird NICHT ersetzt: ' . $ident, 0);
                return false;
            }
        }

        if (!$id) {
            $created = true;

            switch ($type) {
                case VARIABLETYPE_BOOLEAN:
                    $this->RegisterVariableBoolean($ident, $name, $profile, 0);
                    break;
                case VARIABLETYPE_INTEGER:
                    $this->RegisterVariableInteger($ident, $name, $profile, 0);
                    break;
                case VARIABLETYPE_FLOAT:
                    $this->RegisterVariableFloat($ident, $name, $profile, 0);
                    break;
                default:
                    $this->RegisterVariableString($ident, $name, $profile, 0);
                    break;
            }
        }

        if ($onlySetValueOnCreate && !$created) {
            return false;
        }

        switch ($type) {
            case VARIABLETYPE_BOOLEAN:
                $this->SetValue($ident, (bool)$value);
                break;
            case VARIABLETYPE_INTEGER:
                $this->SetValue($ident, (int)round((float)$value));
                break;
            case VARIABLETYPE_FLOAT:
                $this->SetValue($ident, (float)$value);
                break;
            default:
                $this->SetValue($ident, is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string)$value);
                break;
        }

        return $created;
    }

    private function GetSignalDefinition(string $code, array $body = []): array
    {
        $code = strtolower($code);

        return match ($code) {

            'tractionbattery-stateofcharge' => [
                'ident'   => $this->BuildSignalIdent($code),
                'name'    => 'State of charge',
                'type'    => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Progress',
                'factor'  => 1
            ],

            'tractionbattery-range' => [
                'ident'   => $this->BuildSignalIdent($code),
                'name'    => 'Range',
                'type'    => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Odometer',
                'factor'  => 1
            ],

            'odometer-traveleddistance' => [
                'ident'   => $this->BuildSignalIdent($code),
                'name'    => 'Odometer',
                'type'    => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Odometer',
                'factor'  => 1
            ],

            'charge-wattage' => [
                'ident'   => $this->BuildSignalIdent($code),
                'name'    => 'Charge power',
                'type'    => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Power',
                'factor'  => 1
            ],

            'charge-timetocomplete' => [
                'ident'   => $this->BuildSignalIdent($code),
                'name'    => 'Time to complete',
                'type'    => VARIABLETYPE_INTEGER,
                'profile' => 'SMCAR.TimeMinutes',
                'factor'  => 1
            ],

            'tractionbattery-nominalcapacity' => [
                'ident'   => $this->BuildSignalIdent($code),
                'name'    => 'Nominal battery capacity',
                'type'    => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Energy',
                'factor'  => 1
            ],

            'charge-ischarging' => [
                'ident'   => $this->BuildSignalIdent($code),
                'name'    => 'Charging',
                'type'    => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch',
                'factor'  => 1
            ],

            'charge-ischargingcableconnected' => [
                'ident'   => $this->BuildSignalIdent($code),
                'name'    => 'Charging cable connected',
                'type'    => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch',
                'factor'  => 1
            ],

            'charge-detailedchargingstatus' => [
                'ident'   => $this->BuildSignalIdent($code),
                'name'    => 'Detailed charging status',
                'type'    => VARIABLETYPE_STRING,
                'profile' => '',
                'factor'  => 1
            ],

            'location-preciselocation' => [
                'special' => 'multiple',
                'variables' => [
                    [
                        'ident'   => 'Latitude',
                        'name'    => 'Latitude',
                        'type'    => VARIABLETYPE_FLOAT,
                        'profile' => 'SMCAR.LatLon',
                        'source'  => 'latitude'
                    ],
                    [
                        'ident'   => 'Longitude',
                        'name'    => 'Longitude',
                        'type'    => VARIABLETYPE_FLOAT,
                        'profile' => 'SMCAR.LatLon',
                        'source'  => 'longitude'
                    ]
                ]
            ],

            default => $this->GuessSignalDefinition($code, $body)
        };
    }
}