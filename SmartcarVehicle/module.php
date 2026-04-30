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
    $this->RegisterPropertyString('SelectedCapabilities', '[]');
    $this->RegisterAttributeString('CompatibilityCache', '[]');
    $this->RegisterAttributeInteger('CompatibilityCacheAt', 0);
    $this->RegisterPropertyBoolean('IsSimulated', false);
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

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'CommandChargeStart':
                if ((bool)$Value) {
                    $this->ExecuteVehicleCommand('charge-start');
                    $this->SetValue($Ident, false);
                }
                return;

            case 'CommandChargeStop':
                if ((bool)$Value) {
                    $this->ExecuteVehicleCommand('charge-stop');
                    $this->SetValue($Ident, false);
                }
                return;

            case 'CommandSecurityLock':
                if ((bool)$Value) {
                    $this->ExecuteVehicleCommand('security-lock');
                    $this->SetValue($Ident, false);
                }
                return;

            case 'CommandSecurityUnlock':
                if ((bool)$Value) {
                    $this->ExecuteVehicleCommand('security-unlock');
                    $this->SetValue($Ident, false);
                }
                return;

            case 'CommandChargeLimit':
                $this->ExecuteVehicleCommand('charge-set-limit', [
                    'percent' => max(0, min(100, (int)$Value))
                ]);
                $this->SetValue($Ident, max(0, min(100, (int)$Value)));
                return;
        }

        throw new Exception('Invalid Ident');
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

        if ($this->ReadPropertyBoolean('IsSimulated')) {
            $capabilities = $this->GetSimulatedCapabilitiesForForm();
        } elseif (
            $this->HasParentConnection() &&
            $this->ReadPropertyString('VehicleID') !== '' &&
            $this->ReadPropertyString('Make') !== ''
        ) {
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
                    'caption' => 'Gewählte Signale bei Smartcar registrieren',
                    'onClick' => 'echo SMCARV_GenerateConnectURL($id);'
                ],
                [
                    'type' => 'Button',
                    'caption' => 'Aktivierte Signale abrufen',
                    'onClick' => 'SMCARV_FetchSelectedSignals($id, []);'
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

    if ($this->ReadPropertyString('VehicleID') === '' || $this->ReadPropertyString('Make') === '') {
        $this->SendDebug('Compatibility/Skip', 'VehicleID oder Make fehlt. Compatibility wird nicht geladen.', 0);
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
        $region = 'EUROPE';

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
                //$this->SendDebug('FetchSignals/SkipNotSelected', $signalCode, 0);
                $skipped++;
                continue;
            }

            if (!empty($onlyMap) && !isset($onlyMap[$signalCode])) {
                $this->SendDebug('FetchSignals/SkipNotNew', $signalCode, 0);
                $skipped++;
                continue;
            }

            $attributes = is_array($signal['attributes'] ?? null) ? $signal['attributes'] : $signal;

            $body = is_array($attributes['body'] ?? null) ? $attributes['body'] : [];
            $status = is_array($attributes['status'] ?? null) ? $attributes['status'] : null;
            $meta = is_array($signal['meta'] ?? null) ? $signal['meta'] : [];

            $this->SendDebug(
                'FetchSignals/RAW/' . $signalCode,
                json_encode([
                    'body' => $body,
                    'status' => $status
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                0
            );

            $this->SendDebug('FetchSignals/Meta/' . $signalCode, json_encode([
                'retrievedAt'  => $this->FormatSmartcarTimestamp($meta['retrievedAt'] ?? null),
                'oemUpdatedAt' => $this->FormatSmartcarTimestamp($meta['oemUpdatedAt'] ?? null)
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

             $this->SendDebug(
                'FetchSignals/Apply',
                (!empty($onlyMap) ? '[NEU] ' : '') . $signalCode,
                0
            );

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

            if (isset($variable['convert']) && is_callable($variable['convert'])) {
                $value = $variable['convert']($body);
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

        if (empty($permissions) && $this->ReadPropertyBoolean('IsSimulated')) {
            $permissions = $this->GetDefaultSimulatedPermissions();
        }

        if (empty($permissions)) {
            return 'Fehler: Keine aktivierten Signale/Befehle mit Permission ausgewählt.';
        }

        $state = 'vehicle_' . $this->ReadPropertyString('VehicleID') . '_' . bin2hex(random_bytes(8));

        $request = [
            'DataID'      => '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}',
            'Command'     => 'BuildConnectURL',
            'Mode'        => $this->ReadPropertyBoolean('IsSimulated') ? 'simulated' : 'live',
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

    private function GetSimulatedCapabilitiesForForm(): array
    {
        $values = $this->GetSimulatedCapabilitiesBaseList();

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

    private function GetSimulatedCapabilitiesBaseList(): array
    {
        return [
            [
                'sortKey' => '0|BATTERY|Batterieladestand|TRACTIONBATTERY-STATEOFCHARGE',
                'selected' => false,
                'type' => 'signal',
                'group' => 'Battery',
                'name' => 'Batterieladestand',
                'capability' => 'tractionbattery-stateofcharge',
                'code' => 'tractionbattery-stateofcharge',
                'permission' => 'read_battery'
            ],
            [
                'sortKey' => '0|BATTERY|Reichweite Batterie|TRACTIONBATTERY-RANGE',
                'selected' => false,
                'type' => 'signal',
                'group' => 'Battery',
                'name' => 'Reichweite Batterie',
                'capability' => 'tractionbattery-range',
                'code' => 'tractionbattery-range',
                'permission' => 'read_battery'
            ],
            [
                'sortKey' => '0|CHARGE|Ladestatus|CHARGE-DETAILEDCHARGINGSTATUS',
                'selected' => false,
                'type' => 'signal',
                'group' => 'Charge',
                'name' => 'Ladestatus',
                'capability' => 'charge-detailedchargingstatus',
                'code' => 'charge-detailedchargingstatus',
                'permission' => 'read_charge'
            ],
            [
                'sortKey' => '0|CHARGE|Lädt|CHARGE-ISCHARGING',
                'selected' => false,
                'type' => 'signal',
                'group' => 'Charge',
                'name' => 'Lädt',
                'capability' => 'charge-ischarging',
                'code' => 'charge-ischarging',
                'permission' => 'read_charge'
            ],
            [
                'sortKey' => '0|LOCATION|Standort|LOCATION-PRECISELOCATION',
                'selected' => false,
                'type' => 'signal',
                'group' => 'Location',
                'name' => 'Standort',
                'capability' => 'location-preciselocation',
                'code' => 'location-preciselocation',
                'permission' => 'read_location'
            ],
            [
                'sortKey' => '0|ODOMETER|Kilometerstand|ODOMETER-TRAVELEDDISTANCE',
                'selected' => false,
                'type' => 'signal',
                'group' => 'Odometer',
                'name' => 'Kilometerstand',
                'capability' => 'odometer-traveleddistance',
                'code' => 'odometer-traveleddistance',
                'permission' => 'read_odometer'
            ],
            [
                'sortKey' => '0|SECURITY|Verriegelt|CLOSURE-ISLOCKED',
                'selected' => false,
                'type' => 'signal',
                'group' => 'Security',
                'name' => 'Verriegelt',
                'capability' => 'closure-islocked',
                'code' => 'closure-islocked',
                'permission' => 'read_security'
            ],
            [
                'sortKey' => '1|CHARGE|Laden starten|CHARGE-START',
                'selected' => false,
                'type' => 'command',
                'group' => 'Charge',
                'name' => 'Laden starten',
                'capability' => 'charge-start',
                'code' => 'charge-start',
                'permission' => 'control_charge'
            ],
            [
                'sortKey' => '1|CHARGE|Laden stoppen|CHARGE-STOP',
                'selected' => false,
                'type' => 'command',
                'group' => 'Charge',
                'name' => 'Laden stoppen',
                'capability' => 'charge-stop',
                'code' => 'charge-stop',
                'permission' => 'control_charge'
            ],
            [
                'sortKey' => '1|CHARGE|Ladelimit setzen|CHARGE-SET-LIMIT',
                'selected' => false,
                'type' => 'command',
                'group' => 'Charge',
                'name' => 'Ladelimit setzen',
                'capability' => 'charge-set-limit',
                'code' => 'charge-set-limit',
                'permission' => 'control_charge'
            ],
            [
                'sortKey' => '1|SECURITY|Verriegeln|SECURITY-LOCK',
                'selected' => false,
                'type' => 'command',
                'group' => 'Security',
                'name' => 'Verriegeln',
                'capability' => 'security-lock',
                'code' => 'security-lock',
                'permission' => 'control_security'
            ],
            [
                'sortKey' => '1|SECURITY|Entriegeln|SECURITY-UNLOCK',
                'selected' => false,
                'type' => 'command',
                'group' => 'Security',
                'name' => 'Entriegeln',
                'capability' => 'security-unlock',
                'code' => 'security-unlock',
                'permission' => 'control_security'
            ]
        ];
    }

    private function GetDefaultSimulatedPermissions(): array
    {
        return [
            'read_vehicle_info',
            'read_vin',
            'read_odometer',
            'read_location',
            'read_battery',
            'read_charge',
            'read_security',
            'read_tires',
            'read_engine_oil',
            'read_fuel',
            'control_charge',
            'control_security'
        ];
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

    private function SendDataToParentSafe(array $request, string $debugContext): ?string
    {
        if (!$this->HasParentConnection()) {
            $this->SendDebug($debugContext . '/Error', 'Kein gültiger Splitter verbunden.', 0);
            return null;
        }

        try {
            return $this->SendDataToParent(json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            $this->SendDebug($debugContext . '/Exception', $e->getMessage(), 0);
            return null;
        }
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
            'ident'   => $definition['ident'] ?? '',
            'name'    => $definition['name'] ?? '',
            'type'    => $definition['type'] ?? VARIABLETYPE_STRING,
            'profile' => $definition['profile'] ?? '',
            'source'  => $definition['source'] ?? 'value',
            'convert' => $definition['convert'] ?? null
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
        $managedIdents = [];
        $newSignalCodes = [];

        // Alle möglichen Signal- und Command-Variablen aus der Compatibility-Liste sammeln
        $cache = json_decode($this->ReadAttributeString('CompatibilityCache'), true);
        if (is_array($cache)) {
            $allCapabilities = $this->BuildCapabilitiesListFromCompatibilityItems($cache);

            foreach ($allCapabilities as $entry) {
                $type = strtolower((string)($entry['type'] ?? ''));

                if ($type === 'signal') {
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
                            $managedIdents[$ident] = true;
                        }
                    }

                    continue;
                }

                if ($type === 'command') {
                    $commandKey = $this->GetCommandKeyFromCapability($entry);
                    $definition = $this->GetCommandDefinition($commandKey);

                    if (!empty($definition)) {
                        $ident = (string)($definition['ident'] ?? '');
                        if ($ident !== '') {
                            $managedIdents[$ident] = true;
                        }
                    }

                    continue;
                }
            }
        }

        // Ausgewählte Signale und Commands erstellen und als gewünscht markieren
        foreach ($selected as $entry) {
            $type = strtolower((string)($entry['type'] ?? ''));

            if ($type === 'signal') {
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
                        $managedIdents[$ident] = true;
                    }
                }

                $name = (string)($entry['name'] ?? $signalCode);
                $created = $this->CreateSignalVariable($signalCode, $name);

                if ($created) {
                    $newSignalCodes[$signalCode] = true;
                }

                continue;
            }

            if ($type === 'command') {
                $commandKey = $this->GetCommandKeyFromCapability($entry);
                $definition = $this->GetCommandDefinition($commandKey);

                if (empty($definition)) {
                    $this->SendDebug(
                        'Commands/Unknown',
                        json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        0
                    );
                    continue;
                }

                $ident = (string)($definition['ident'] ?? '');
                if ($ident === '') {
                    continue;
                }

                $wantedIdents[$ident] = true;
                $managedIdents[$ident] = true;

                $this->RegisterOrUpdateTypedVariable(
                    $ident,
                    (string)($definition['name'] ?? $ident),
                    $this->GetDefaultValueForType((int)($definition['type'] ?? VARIABLETYPE_STRING)),
                    (int)($definition['type'] ?? VARIABLETYPE_STRING),
                    (string)($definition['profile'] ?? ''),
                    true
                );

                $this->EnableAction($ident);

                continue;
            }
        }

        // Nicht mehr gewünschte, aber vom Modul verwaltete Variablen löschen
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            $object = IPS_GetObject($childId);
            $ident = (string)($object['ObjectIdent'] ?? '');

            if ($ident === '') {
                continue;
            }

            if (!isset($managedIdents[$ident])) {
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

        if ($this->ReadPropertyBoolean('IsSimulated')) {
            $fullList = $this->GetSimulatedCapabilitiesBaseList();
        } else {
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
        }

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

        $connectionId = (int)($instance['ConnectionID'] ?? 0);
        if ($connectionId <= 0 || !@IPS_InstanceExists($connectionId)) {
            return false;
        }

        $parent = @IPS_GetInstance($connectionId);
        if (!is_array($parent)) {
            return false;
        }

        return (string)($parent['ModuleInfo']['ModuleID'] ?? '') === self::SPLITTER_MODULE_ID;
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

    private function ExecuteVehicleCommand(string $command, array $params = []): bool
    {
        if (!$this->HasParentConnection()) {
            $this->SendDebug('Command/Error', 'Kein Splitter/Parent verbunden.', 0);
            return false;
        }

        $vehicleId = $this->ReadPropertyString('VehicleID');
        $userId    = $this->ReadPropertyString('UserID');

        if ($vehicleId === '' || $userId === '') {
            $this->SendDebug('Command/Error', 'VehicleID oder UserID fehlt.', 0);
            return false;
        }

        $definition = $this->GetCommandDefinition($command);
        if (empty($definition)) {
            $this->SendDebug('Command/Error', 'Unbekannter Command: ' . $command, 0);
            return false;
        }

        $body = null;

        if ($command === 'charge-set-limit') {
            $body = [
                'data' => [
                    'attributes' => [
                        'percent' => (int)($params['percent'] ?? 80)
                    ]
                ]
            ];
        }

        $request = [
            'DataID'    => '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}',
            'Command'   => 'Command',
            'VehicleID' => $vehicleId,
            'UserID'    => $userId,
            'Method'    => 'POST',
            'Path'      => $definition['path']
        ];

        if ($body !== null) {
            $request['Body'] = $body;
        }

        $this->SendDebug('Command/Request/' . $command, json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        $result = $this->SendDataToParentSafe($request, 'Compatibility');

        if ($result === null) {
            return [];
        }

        $this->SendDebug('Command/Response/' . $command, (string)$result, 0);

        $decoded = json_decode((string)$result, true);
        if (!is_array($decoded) || empty($decoded['success'])) {
            return false;
        }

        return true;
    }

    private function GetCommandDefinition(string $command): array
    {
        return match (strtolower($command)) {
            'charge-start' => [
                'ident' => 'CommandChargeStart',
                'name'  => 'Laden starten',
                'type'  => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch',
                'path'  => '/commands/charge/start'
            ],
            'charge-stop' => [
                'ident' => 'CommandChargeStop',
                'name'  => 'Laden stoppen',
                'type'  => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch',
                'path'  => '/commands/charge/stop'
            ],
            'charge-set-limit' => [
                'ident' => 'CommandChargeLimit',
                'name'  => 'Ladelimit setzen',
                'type'  => VARIABLETYPE_INTEGER,
                'profile' => 'SMCAR.Progress',
                'path'  => '/commands/charge/set-limit'
            ],
            'security-lock' => [
                'ident' => 'CommandSecurityLock',
                'name'  => 'Fahrzeug verriegeln',
                'type'  => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch',
                'path'  => '/commands/security/lock'
            ],
            'security-unlock' => [
                'ident' => 'CommandSecurityUnlock',
                'name'  => 'Fahrzeug entriegeln',
                'type'  => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch',
                'path'  => '/commands/security/unlock'
            ],
            default => []
        };
    }

    private function GetCommandKeyFromCapability(array $entry): string
    {
        $capability = strtolower((string)($entry['capability'] ?? ''));
        $code       = strtolower((string)($entry['code'] ?? ''));

        $key = $capability !== '' ? $capability : $code;

        return match ($key) {
            'charge-start', 'charge-startcharging', 'start-charge', 'start-charging' => 'charge-start',
            'charge-stop', 'charge-stopcharging', 'stop-charge', 'stop-charging' => 'charge-stop',
            'charge-set-limit', 'charge-chargelimit', 'set-charge-limit' => 'charge-set-limit',
            'security-lock', 'closure-lock', 'lock', 'lock-doors' => 'security-lock',
            'security-unlock', 'closure-unlock', 'unlock', 'unlock-doors' => 'security-unlock',
            default => $key
        };
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
        IPS_SetVariableProfileText('SMCAR.Power', '', ' kW');
        IPS_SetVariableProfileDigits('SMCAR.Power', 1);
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

        $milesToKm = function (array $body): float {
            $val  = (float)($body['value'] ?? 0);
            $unit = strtolower((string)($body['unit'] ?? 'km'));
            return $unit === 'miles' ? $val * 1.609344 : $val;
        };

        $timeToComplete = function (array $body): string {
            $raw = str_replace(',', '.', (string)($body['value'] ?? ''));

            if (strpos($raw, '.') !== false) {
                $v = (float)$raw;
                $h = (int)floor($v);
                $m = (int)round(($v - $h) * 60);

                if ($m >= 60) {
                    $m -= 60;
                    $h++;
                }

                return sprintf('%02d:%02d Uhr', $h % 24, $m);
            }

            $mins = (int)$raw;
            return sprintf('%02d:%02d Uhr', intdiv($mins, 60) % 24, $mins % 60);
        };

        $openClosed = function (array $body): string {
            return !empty($body['isOpen']) ? 'OPEN' : 'CLOSED';
        };

        return match ($code) {
            // ---------- Batterie ----------
            'tractionbattery-stateofcharge' => [
                'ident' => 'BatteryLevel',
                'name' => 'Batterieladestand (SOC)',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Progress'
            ],

            'tractionbattery-range' => [
                'ident' => 'BatteryRange',
                'name' => 'Reichweite Batterie',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Odometer',
                'convert' => $milesToKm
            ],

            'tractionbattery-nominalcapacity' => [
                'ident' => 'BatteryCapacity',
                'name' => 'Batteriekapazität',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Energy',
                'source' => 'capacity'
            ],

            'tractionbattery-chargecompletiontime' => [
                'ident' => 'ChargeEndTime',
                'name' => 'Fertig geladen',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'convert' => $timeToComplete
            ],

            'tractionbattery-maxrangechargecounter' => [
                'ident' => 'MaxRangeChargeCounter',
                'name' => 'Max-Range-Ladezyklen',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => ''
            ],

            'tractionbattery-nominalcapacities' => [
                'ident' => 'BatteryNominalCapacities',
                'name' => 'Nominalkapazitäten',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'values',
                'convert' => fn(array $body) => json_encode($body['values'] ?? [], JSON_UNESCAPED_UNICODE)
            ],

            // ---------- Laden ----------
            'charge-detailedchargingstatus' => [
                'ident' => 'ChargeStatus',
                'name' => 'Ladestatus',
                'type' => VARIABLETYPE_STRING,
                'profile' => 'SMCAR.Charge',
                'convert' => fn(array $body) => strtoupper((string)($body['value'] ?? ''))
            ],

            'charge-ischarging' => [
                'ident' => 'IsCharging',
                'name' => 'Lädt',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch'
            ],

            'charge-ischargingcableconnected' => [
                'ident' => 'PluggedIn',
                'name' => 'Ladekabel eingesteckt',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch'
            ],

            'charge-chargelimits' => [
                'ident' => 'ChargeLimit',
                'name' => 'Aktuelles Ladelimit',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Progress',
                'source' => 'values',
                'convert' => function (array $body) {
                    foreach (($body['values'] ?? []) as $cfg) {
                        if (($cfg['type'] ?? '') === 'global' && isset($cfg['limit'])) {
                            return (float)$cfg['limit'] * 100;
                        }
                    }
                    return 0.0;
                }
            ],

            'charge-amperage' => [
                'ident' => 'ChargeAmperage',
                'name' => 'Ladestrom (A)',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => ''
            ],

            'charge-amperagemax',
            'charge-maximumamperage' => [
                'ident' => 'ChargeAmperageMax',
                'name' => 'Max. Ladestrom (A)',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => ''
            ],

            'charge-amperagerequested' => [
                'ident' => 'ChargeAmperageRequested',
                'name' => 'Angeforderter Ladestrom (A)',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => ''
            ],

            'charge-chargerate' => [
                'ident' => 'ChargeRate',
                'name' => 'Laderate',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => ''
            ],

            'charge-voltage' => [
                'ident' => 'ChargeVoltage',
                'name' => 'Ladespannung (V)',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => ''
            ],

            'charge-wattage',
            'charge-power' => [
                'ident' => 'ChargeWattage',
                'name' => 'Ladeleistung',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Power'
            ],

            'charge-energyadded' => [
                'ident' => 'ChargeEnergyAdded',
                'name' => 'Energie hinzugefügt',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Energy'
            ],

            'charge-timetocomplete' => [
                'ident' => 'ChargeTimeToComplete',
                'name' => 'Fertiggeladen',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'convert' => $timeToComplete
            ],

            'charge-fastchargertype' => [
                'ident' => 'FastChargerType',
                'name' => 'Schnelllader-Typ',
                'type' => VARIABLETYPE_STRING,
                'profile' => ''
            ],

            'charge-isfastchargerpresent' => [
                'ident' => 'IsFastChargerPresent',
                'name' => 'Schnelllader erkannt',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch'
            ],

            'charge-chargingconnectortype' => [
                'ident' => 'ChargingConnectorType',
                'name' => 'Steckertyp',
                'type' => VARIABLETYPE_STRING,
                'profile' => ''
            ],

            'charge-chargerphases' => [
                'ident' => 'ChargerPhases',
                'name' => 'Phasen',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => ''
            ],

            'charge-chargetimers' => [
                'ident' => 'ChargeTimers',
                'name' => 'Lade-Timer',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'values',
                'convert' => fn(array $body) => json_encode($body['values'] ?? [], JSON_UNESCAPED_UNICODE)
            ],

            'charge-records',
            'charge-chargerecords' => [
                'ident' => 'ChargeRecords',
                'name' => 'Lade-Records',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'values',
                'convert' => fn(array $body) => json_encode($body['values'] ?? [], JSON_UNESCAPED_UNICODE)
            ],

            'charge-ischargingcablelatched' => [
                'ident' => 'IsChargingCableLatched',
                'name' => 'Ladekabel verriegelt',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch'
            ],

            'charge-ischargingportflapopen' => [
                'ident' => 'ChargingPortFlap',
                'name' => 'Ladeport-Klappe',
                'type' => VARIABLETYPE_STRING,
                'profile' => 'SMCAR.Status',
                'source' => 'isOpen',
                'convert' => $openClosed
            ],

            'charge-chargeportstatuscolor',
            'closure-chargeportstatuscolor' => [
                'ident' => 'ChargingPortStatusColor',
                'name' => 'Ladeport-Statusfarbe',
                'type' => VARIABLETYPE_STRING,
                'profile' => ''
            ],

            // ---------- Standort ----------
            'location-preciselocation' => [
                'special' => 'multiple',
                'variables' => [
                    [
                        'ident' => 'Latitude',
                        'name' => 'Breitengrad',
                        'type' => VARIABLETYPE_FLOAT,
                        'profile' => 'SMCAR.LatLon',
                        'source' => 'latitude'
                    ],
                    [
                        'ident' => 'Longitude',
                        'name' => 'Längengrad',
                        'type' => VARIABLETYPE_FLOAT,
                        'profile' => 'SMCAR.LatLon',
                        'source' => 'longitude'
                    ]
                ]
            ],

            // ---------- Kilometerstand ----------
            'odometer-traveleddistance' => [
                'ident' => 'Odometer',
                'name' => 'Kilometerstand',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Odometer',
                'convert' => $milesToKm
            ],

            // ---------- Security / Closure ----------
            'closure-islocked' => [
                'ident' => 'DoorsLocked',
                'name' => 'Fahrzeug verriegelt',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Lock'
            ],

            'closure-doors' => [
                'ident' => 'Doors',
                'name' => 'Türen',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'convert' => fn(array $body) => json_encode($body, JSON_UNESCAPED_UNICODE)
            ],

            'closure-windows' => [
                'ident' => 'Windows',
                'name' => 'Fenster',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'convert' => fn(array $body) => json_encode($body, JSON_UNESCAPED_UNICODE)
            ],

            'closure-sunroof' => [
                'ident' => 'Sunroof',
                'name' => 'Schiebedach',
                'type' => VARIABLETYPE_STRING,
                'profile' => 'SMCAR.Status',
                'source' => 'isOpen',
                'convert' => $openClosed
            ],

            'closure-enginecover' => [
                'ident' => 'EngineCover',
                'name' => 'Motorhaube',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'convert' => fn(array $body) => json_encode($body, JSON_UNESCAPED_UNICODE)
            ],

            'closure-fronttrunk' => [
                'ident' => 'FrontTrunk',
                'name' => 'Front-Kofferraum',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'convert' => fn(array $body) => json_encode($body, JSON_UNESCAPED_UNICODE)
            ],

            'closure-reartrunk' => [
                'ident' => 'RearTrunk',
                'name' => 'Heck-Kofferraum',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'convert' => fn(array $body) => json_encode($body, JSON_UNESCAPED_UNICODE)
            ],

            'closure-tailgate' => [
                'ident' => 'Tailgate',
                'name' => 'Heckklappe',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'convert' => fn(array $body) => json_encode($body, JSON_UNESCAPED_UNICODE)
            ],

            // ---------- Connectivity ----------
            'connectivitystatus-isonline' => [
                'ident' => 'IsOnline',
                'name' => 'Online',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch'
            ],

            'connectivitystatus-isasleep' => [
                'ident' => 'IsAsleep',
                'name' => 'Schlafmodus',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch'
            ],

            'connectivitystatus-isdigitalkeypaired' => [
                'ident' => 'IsDigitalKeyPaired',
                'name' => 'Digitalschlüssel gekoppelt',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch'
            ],

            'connectivitysoftware-currentfirmwareversion' => [
                'ident' => 'FirmwareVersion',
                'name' => 'Firmware-Version',
                'type' => VARIABLETYPE_STRING,
                'profile' => ''
            ],

            // ---------- ICE ----------
            'internalcombustionengine-fuellevel' => [
                'ident' => 'FuelLevel',
                'name' => 'Tankfüllstand',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Progress'
            ],

            'internalcombustionengine-oillife' => [
                'ident' => 'OilLife',
                'name' => 'Öl-Lebensdauer',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Progress'
            ],

            'internalcombustionengine-oilpressure' => [
                'ident' => 'OilPressure',
                'name' => 'Öldruck',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => ''
            ],

            'internalcombustionengine-oiltemperature' => [
                'ident' => 'OilTemperature',
                'name' => 'Öltemperatur',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => ''
            ],

            'internalcombustionengine-waterinfuel' => [
                'ident' => 'WaterInFuel',
                'name' => 'Wasser im Kraftstoff',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch'
            ],

            // ---------- Klima ----------
            'climatecontrol-isheateractive',
            'tractionbattery-isheateractive' => [
                'ident' => 'IsHeaterActive',
                'name' => 'Heizung aktiv',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch'
            ],

            'climate-externaltemperature' => [
                'ident' => 'ExternalTemperature',
                'name' => 'Außentemperatur',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '~Temperature'
            ],

            'climate-internaltemperature' => [
                'ident' => 'InternalTemperature',
                'name' => 'Innentemperatur',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '~Temperature'
            ],

            // ---------- Reifen ----------
            'tires-pressure' => [
                'special' => 'multiple',
                'variables' => [
                    [
                        'ident' => 'TireFrontLeft',
                        'name' => 'Reifendruck Vorderreifen Links',
                        'type' => VARIABLETYPE_FLOAT,
                        'profile' => 'SMCAR.Pressure',
                        'source' => 'frontLeft',
                        'convert' => fn(array $body) => (float)($body['frontLeft'] ?? 0) * 0.01
                    ],
                    [
                        'ident' => 'TireFrontRight',
                        'name' => 'Reifendruck Vorderreifen Rechts',
                        'type' => VARIABLETYPE_FLOAT,
                        'profile' => 'SMCAR.Pressure',
                        'source' => 'frontRight',
                        'convert' => fn(array $body) => (float)($body['frontRight'] ?? 0) * 0.01
                    ],
                    [
                        'ident' => 'TireBackLeft',
                        'name' => 'Reifendruck Hinterreifen Links',
                        'type' => VARIABLETYPE_FLOAT,
                        'profile' => 'SMCAR.Pressure',
                        'source' => 'backLeft',
                        'convert' => fn(array $body) => (float)($body['backLeft'] ?? 0) * 0.01
                    ],
                    [
                        'ident' => 'TireBackRight',
                        'name' => 'Reifendruck Hinterreifen Rechts',
                        'type' => VARIABLETYPE_FLOAT,
                        'profile' => 'SMCAR.Pressure',
                        'source' => 'backRight',
                        'convert' => fn(array $body) => (float)($body['backRight'] ?? 0) * 0.01
                    ]
                ]
            ],

            // ---------- Vehicle Identification ----------
            'vehicleidentification-vin' => [
                'ident' => 'VIN',
                'name' => 'Fahrgestellnummer (VIN)',
                'type' => VARIABLETYPE_STRING,
                'profile' => ''
            ],

            'vehicleidentification-trim' => [
                'ident' => 'Trim',
                'name' => 'Ausstattungslinie (Trim)',
                'type' => VARIABLETYPE_STRING,
                'profile' => ''
            ],

            'vehicleidentification-exteriorcolor' => [
                'ident' => 'ExteriorColor',
                'name' => 'Außenfarbe',
                'type' => VARIABLETYPE_STRING,
                'profile' => ''
            ],

            'vehicleidentification-packages' => [
                'ident' => 'Packages',
                'name' => 'Pakete',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'values',
                'convert' => fn(array $body) => implode(', ', array_map('strval', $body['values'] ?? []))
            ],

            'vehicleidentification-nickname' => [
                'ident' => 'Nickname',
                'name' => 'Fahrzeug-Spitzname',
                'type' => VARIABLETYPE_STRING,
                'profile' => ''
            ],

            'vehicleidentification-make' => [
                'ident' => 'VehicleMake',
                'name' => 'Fahrzeug Hersteller',
                'type' => VARIABLETYPE_STRING,
                'profile' => ''
            ],

            'vehicleidentification-model' => [
                'ident' => 'VehicleModel',
                'name' => 'Fahrzeug Modell',
                'type' => VARIABLETYPE_STRING,
                'profile' => ''
            ],

            'vehicleidentification-year' => [
                'ident' => 'VehicleYear',
                'name' => 'Fahrzeug Baujahr',
                'type' => VARIABLETYPE_INTEGER,
                'profile' => ''
            ],

            // ---------- Vehicle User Account ----------
            'vehicleuseraccount-permissions' => [
                'ident' => 'VehicleUserPermissions',
                'name' => 'Benutzer-Berechtigungen',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'convert' => fn(array $body) => json_encode($body, JSON_UNESCAPED_UNICODE)
            ],

            'vehicleuseraccount-role' => [
                'ident' => 'VehicleUserRole',
                'name' => 'Benutzer-Rolle',
                'type' => VARIABLETYPE_STRING,
                'profile' => ''
            ],

            default => $this->GuessSignalDefinition($code, $body)
        };
    }
}