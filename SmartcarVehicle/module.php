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

    public function FetchSelectedSignals(): void
    {
        $this->SendDebug('FetchSignals/Start', 'Aktiver Signalabruf gestartet.', 0);

        if (!$this->HasParentConnection()) {
            $this->SendDebug('FetchSignals/Error', 'Kein Splitter/Parent verbunden.', 0);
            return;
        }

        $vehicleId = $this->ReadPropertyString('VehicleID');
        $userId    = $this->ReadPropertyString('UserID');

        if ($vehicleId === '') {
            $this->SendDebug('FetchSignals/Error', 'VehicleID fehlt.', 0);
            return;
        }

        if ($userId === '') {
            $this->SendDebug('FetchSignals/Error', 'UserID fehlt.', 0);
            return;
        }

        $entries = $this->GetSelectedCapabilitiesResolved();

        $fetched = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            if (strtolower((string)($entry['type'] ?? '')) !== 'signal') {
                $skipped++;
                continue;
            }

            $signalCode = (string)($entry['capability'] ?? '');
            if ($signalCode === '') {
                $signalCode = (string)($entry['code'] ?? '');
            }

            if ($signalCode === '') {
                $skipped++;
                continue;
            }

            $result = $this->SendDataToParent(json_encode([
                'DataID'     => '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}',
                'Command'    => 'GetSignal',
                'VehicleID'  => $vehicleId,
                'UserID'     => $userId,
                'SignalCode' => $signalCode
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $this->SendDebug('FetchSignals/Response/' . $signalCode, (string)$result, 0);

            $decoded = json_decode((string)$result, true);
            if (!is_array($decoded) || empty($decoded['success'])) {
                $this->SendDebug('FetchSignals/Error', 'Fehler bei ' . $signalCode . ': ' . (string)$result, 0);
                continue;
            }

            // 👉 NEU: Meta extrahieren
            $meta = $decoded['body']['data']['meta'] ?? [];

            // 👉 NEU: Zeitstempel loggen
            if (is_array($meta) && !empty($meta)) {
                $this->SendDebug('FetchSignals/Meta/' . $signalCode, json_encode([
                    'ingestedAt'   => $this->FormatSmartcarTime($meta['ingestedAt'] ?? null),
                    'retrievedAt'  => $this->FormatSmartcarTime($meta['retrievedAt'] ?? null),
                    'oemUpdatedAt' => $this->FormatSmartcarTime($meta['oemUpdatedAt'] ?? null)
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
            }

            $attributes = $decoded['body']['data']['attributes']
                ?? $decoded['body']['attributes']
                ?? [];

            $body = is_array($attributes['body'] ?? null) ? $attributes['body'] : [];
            $status = is_array($attributes['status'] ?? null) ? $attributes['status'] : null;

            $this->ApplySignalFromV3($signalCode, $body, $status, $entry);
            $fetched++;
        }

        $this->SendDebug('FetchSignals/Done', 'Fertig. fetched=' . $fetched . ' skipped=' . $skipped, 0);
    }

    private function FormatSmartcarTime(?string $ts): ?string
    {
        if ($ts === null || $ts === '') {
            return null;
        }

        try {
            $dt = new DateTime($ts);
            $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
            return $dt->format('d.m.Y H:i:s');
        } catch (Exception $e) {
            return $ts;
        }
    }

    public function ProcessWebhookSignals(string $payloadJson): void
    {
        $this->SendDebug('WebhookVehicle/Start', 'Payload empfangen.', 0);

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            $this->SendDebug('WebhookVehicle/Error', 'Payload ist kein JSON: ' . $payloadJson, 0);
            return;
        }

        $signals = [];

        if (isset($payload['data']['signals']) && is_array($payload['data']['signals'])) {
            $signals = $payload['data']['signals'];
        } elseif (isset($payload['data']['triggers']) && is_array($payload['data']['triggers'])) {
            $signals = $payload['data']['triggers'];
        } elseif (isset($payload['triggers']) && is_array($payload['triggers'])) {
            $signals = $payload['triggers'];
        }

        if (empty($signals)) {
            $this->SendDebug('WebhookVehicle/Error', 'Keine signals/triggers im Payload: ' . $payloadJson, 0);
            return;
        }

        $selectedMap = $this->GetSelectedSignalMap();

        $this->SendDebug('WebhookVehicle/Info', json_encode([
            'signalsReceived' => count($signals),
            'selectedSignals' => array_keys($selectedMap)
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        foreach ($signals as $signal) {
            if (!is_array($signal)) {
                continue;
            }

            $signalCode = (string)($signal['code'] ?? '');
            if ($signalCode === '') {
                continue;
            }

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
        $ident = $this->BuildSignalIdent($code);
        $name = (string)($meta['name'] ?? $code);

        if ($status !== null && isset($status['value']) && strtoupper((string)$status['value']) !== 'OK') {
            $this->SendDebug('SignalStatus/' . $code, json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
        }

        if (array_key_exists('value', $body)) {
            $value = $body['value'];

            if (is_bool($value)) {
                $this->RegisterOrUpdateBoolean($ident, $name, $value);
            } elseif (is_int($value)) {
                $this->RegisterOrUpdateInteger($ident, $name, $value);
            } elseif (is_float($value) || is_numeric($value)) {
                $this->RegisterOrUpdateFloat($ident, $name, (float)$value);
            } else {
                $this->RegisterOrUpdateString($ident, $name, (string)$value);
            }

            return;
        }

        if (!empty($body)) {
            $this->RegisterOrUpdateString($ident, $name, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    private function BuildSignalIdent(string $code): string
    {
        return 'Sig_' . preg_replace('/[^A-Za-z0-9_]/', '_', $code);
    }

    private function RegisterOrUpdateBoolean(string $ident, string $name, bool $value): void
    {
        if (!@$this->GetIDForIdent($ident)) {
            $this->RegisterVariableBoolean($ident, $name !== '' ? $name : $ident, '~Switch', 0);
        }

        $this->SetValue($ident, $value);
    }

    private function RegisterOrUpdateInteger(string $ident, string $name, int $value): void
    {
        if (!@$this->GetIDForIdent($ident)) {
            $this->RegisterVariableInteger($ident, $name !== '' ? $name : $ident, '', 0);
        }

        $this->SetValue($ident, $value);
    }

    private function RegisterOrUpdateFloat(string $ident, string $name, float $value): void
    {
        if (!@$this->GetIDForIdent($ident)) {
            $this->RegisterVariableFloat($ident, $name !== '' ? $name : $ident, '', 0);
        }

        $this->SetValue($ident, $value);
    }

    private function RegisterOrUpdateString(string $ident, string $name, string $value): void
    {
        if (!@$this->GetIDForIdent($ident)) {
            $this->RegisterVariableString($ident, $name !== '' ? $name : $ident, '', 0);
        }

        $this->SetValue($ident, $value);
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

    private function CreateSignalVariable(string $signalCode, string $name): void
    {
        $ident = $this->BuildSignalIdent($signalCode);

        if (@$this->GetIDForIdent($ident)) {
            return;
        }

        // Erst als String anlegen. Beim ersten echten Wert kann später gezielt typisiert werden.
        $this->RegisterVariableString($ident, $name !== '' ? $name : $signalCode, '', 0);

        $this->SendDebug('Variables/Create', 'Variable erstellt: ' . $ident . ' (' . $name . ')', 0);
    }

    public function ApplySelectedCapabilities(): void
    {
        $selected = $this->GetSelectedCapabilitiesResolved();

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

            $name = (string)($entry['name'] ?? $signalCode);
            $this->CreateSignalVariable($signalCode, $name);
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
}