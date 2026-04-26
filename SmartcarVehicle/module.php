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

    public function ReloadCompatibility(): void
    {
        $this->LoadCompatibility(true);
        $this->ReloadForm();
    }

    private function GetCompatibilityCapabilitiesForForm(): array
    {
        $data = $this->LoadCompatibility(false);

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

        $values = [];

        foreach ($data as $item) {
            $attributes = is_array($item['attributes'] ?? null) ? $item['attributes'] : [];
            $capabilities = is_array($attributes['capabilities'] ?? null) ? $attributes['capabilities'] : [];

            foreach ($capabilities as $capability) {
                if (!is_array($capability)) {
                    continue;
                }

                $key = (string)($capability['capability'] ?? '');
                if ($key === '') {
                    continue;
                }

                $type = (string)($capability['type'] ?? '');
                $name = (string)($capability['name'] ?? $key);
                $group = (string)($capability['group'] ?? '');
                $permission = (string)($capability['permission'] ?? '');

                $values[] = [
                    'caption' => trim(strtoupper($type) . ' · ' . $group . ' · ' . $name . ' · ' . $permission),
                    'value' => [
                        'selected' => $selectedMap[$key] ?? false,
                        'capability' => $key,
                        'type' => $type,
                        'name' => $name,
                        'group' => $group,
                        'code' => (string)($capability['code'] ?? ''),
                        'permission' => $permission
                    ]
                ];
            }
        }

        usort($values, fn($a, $b) => strcmp((string)$a['caption'], (string)$b['caption']));

        return $values;
    }

    private function LoadCompatibility(bool $forceReload): array
    {
        $cacheAt = $this->ReadAttributeInteger('CompatibilityCacheAt');
        $cacheRaw = $this->ReadAttributeString('CompatibilityCache');

        if (!$forceReload && $cacheRaw !== '' && $cacheAt > (time() - 86400)) {
            $cached = json_decode($cacheRaw, true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->SendDataToParent(json_encode([
            'DataID' => '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}',
            'Command' => 'GetCompatibleVehicles',
            'Make' => $this->NormalizeCompatibilityMake($this->ReadPropertyString('Make')),
            'PowertrainType' => $this->ReadPropertyString('PowertrainType'),
            'Region' => $this->ReadPropertyString('CompatibilityRegion')
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $decoded = json_decode((string)$result, true);

        if (!is_array($decoded) || empty($decoded['success'])) {
            $this->SendDebug('Compatibility', 'Fehler beim Laden: ' . (string)$result, 0);
            return [];
        }

        $body = is_array($decoded['body'] ?? null) ? $decoded['body'] : [];
        $items = is_array($body['data'] ?? null) ? $body['data'] : [];

        $this->WriteAttributeString(
            'CompatibilityCache',
            json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        $this->WriteAttributeInteger('CompatibilityCacheAt', time());

        return $items;
    }

    private function NormalizeCompatibilityMake(string $make): string
    {
        return match (strtoupper(trim($make))) {
            'MERCEDES_BENZ', 'MERCEDES-BENZ' => 'MERCEDES-BENZ',
            default => trim($make)
        };
    }
}