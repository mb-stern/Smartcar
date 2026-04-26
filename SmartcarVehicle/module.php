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
                'type' => 'List',
                'name' => 'SelectedCapabilities',
                'caption' => 'Kompatible Signale / Befehle',
                'rowCount' => 20,
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
                        'visible' => false
                    ],
                    [
                        'caption' => 'Aktiv',
                        'name' => 'selected',
                        'width' => '80px',
                        'edit' => [
                            'type' => 'CheckBox'
                        ]
                    ],
                    [
                        'caption' => 'Typ',
                        'name' => 'type',
                        'width' => '90px'
                    ],
                    [
                        'caption' => 'Gruppe',
                        'name' => 'group',
                        'width' => '160px'
                    ],
                    [
                        'caption' => 'Name',
                        'name' => 'name',
                        'width' => 'auto'
                    ],
                    [
                        'caption' => 'Capability',
                        'name' => 'capability',
                        'width' => '220px'
                    ],
                    [
                        'caption' => 'Code',
                        'name' => 'code',
                        'width' => '220px'
                    ],
                    [
                        'caption' => 'Permission',
                        'name' => 'permission',
                        'width' => '180px'
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

        $this->SendDebug('Compatibility/Form', 'Modelle im Cache/Response: ' . count($data), 0);

        $selectedRaw = $this->ReadPropertyString('SelectedCapabilities');
        $selected = json_decode($selectedRaw, true);
        if (!is_array($selected)) {
            $selected = [];
        }

        $selectedMap = [];
        foreach ($selected as $entry) {
            if (is_array($entry) && isset($entry['capability'])) {
                $selectedMap[(string)$entry['capability']] = (bool)($entry['selected'] ?? false);
            }
        }

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
                    $temp[$uniqueKey] = [
                        'selected'   => $selectedMap[$capKey] ?? false,
                        'capability' => $capKey,
                        'type'       => $type,
                        'name'       => $name !== '' ? $name : $capKey,
                        'group'      => $group,
                        'code'       => $code,
                        'permission' => $permission
                    ];
                    continue;
                }

                if ($temp[$uniqueKey]['permission'] === '' && $permission !== '') {
                    $temp[$uniqueKey]['permission'] = $permission;
                }

                if ($temp[$uniqueKey]['name'] === '' && $name !== '') {
                    $temp[$uniqueKey]['name'] = $name;
                }
            }
        }

        $values = array_values($temp);

        usort($values, function ($a, $b) {
            return
                strcasecmp((string)$a['type'], (string)$b['type']) ?:
                strcasecmp((string)$a['group'], (string)$b['group']) ?:
                strcasecmp((string)$a['name'], (string)$b['name']) ?:
                strcasecmp((string)$a['code'], (string)$b['code']);
        });

        $this->SendDebug('Compatibility/Form', 'Capabilities für Liste nach Dedupe: ' . count($values), 0);

        return $values;

        $this->SendDebug('Compatibility/Form', 'Capabilities für Liste: ' . count($values), 0);

        return $values;
    }

    private function LoadCompatibility(bool $forceReload): array
    {
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
}