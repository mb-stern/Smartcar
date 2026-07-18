<?php

class SmartcarVehicle extends IPSModuleStrict
{
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
    $this->RegisterPropertyBoolean('ShowOEMUpdatedAtVariables', false);
    $this->RegisterAttributeString('LastOEMSignalTimes', '{}');
    $this->RegisterAttributeString('CompatibilityCache', '[]');
    $this->RegisterAttributeInteger('CompatibilityCacheAt', 0);

    $lastSignalsExists = (bool)@$this->GetIDForIdent('LastSignalsAt');
    $this->RegisterVariableInteger('LastSignalsAt', 'Letzte Signale', '~UnixTimestamp');

    if (!$lastSignalsExists) {
        $lastSignalsId = @$this->GetIDForIdent('LastSignalsAt');
        if ($lastSignalsId) {
            IPS_SetPosition($lastSignalsId, 10);
        }
    }
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
                '{9F7A4B2C-3D1E-4A6F-8B20-6C5D4E3F2A10}'
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
                    'type' => 'CheckBox',
                    'name' => 'ShowOEMUpdatedAtVariables',
                    'caption' => 'OEM-Aktualisierungszeit je Signal als zusätzliche Variable anzeigen'
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
                        'caption' => '',
                        'name' => 'capabilityKey',
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
                ],
                [
                    'type'    => 'Label',
                    'caption' => ''
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                                'type'   => 'Image',
                                'onClick'=> "echo 'https://paypal.me/mbstern';",
                                'image'=> "data:image/jpeg;base64,/9j/4QAYRXhpZgAASUkqAAgAAAAAAAAAAAAAAP/sABFEdWNreQABAAQAAAA8AAD/7gAOQWRvYmUAZMAAAAAB/9sAhAAGBAQEBQQGBQUGCQYFBgkLCAYGCAsMCgoLCgoMEAwMDAwMDBAMDg8QDw4MExMUFBMTHBsbGxwfHx8fHx8fHx8fAQcHBw0MDRgQEBgaFREVGh8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx//wAARCABLAGQDAREAAhEBAxEB/8QAqwABAAICAwEBAAAAAAAAAAAAAAUGAgcDBAgJAQEBAAIDAQAAAAAAAAAAAAAAAAMEAgUGARAAAQMCAwMEDwMICwAAAAAAAgEDBAAFERIGIRMHMdEUFkFRcSKyk6PDJFSEFTZGZmEyCIGxQlKSIzODkaFigmOz00QlVRgRAAICAQIDBQYFBQAAAAAAAAABAgMREgQhMQVBUWEiE/BxgaGxBpHRQhQVwfEyUiP/2gAMAwEAAhEDEQA/AN+WWywr/CS63VDfkPmeUc5CICJKKCKCqbNlAd/qNpr1YvGHz0A6jaa9WLxh89AOo2mvVi8YfPQDqNpr1YvGHz0A6jaa9WLxh89AOo2mvVi8YfPQDqNpr1YvGHz0A6jaa9WLxh89AOo2mvVi8YfPQDqNpr1YvGHz0A6jaa9WLxh89ARnuVr3/wC4t+97o3PSui51+9jly5vvZezhQEnob4ajd1zw1oCeoBQCgFAeZtWfik1ZbtT3W3W22284MKU7GYceR4nCFk1DMSi4KbVHHYldDT0eEoJtvLRrrN7JSaSIr/1nr3/q7Z+y/wD6tS/wtXfL5GH76Xci4aC/FPFul1j2zVFtC3dKMWmrhGMiZEyXAd6B98Iqv6WZcOzVTc9HcYuUHnHYTVb1N4Zv6tIXhQCgFAV/569g85QGWhvhqN3XPDWgJ6gFAKA4LhLbhwJMxxcG4zRvGq9psVJfzVlGOWkeN4WT53SZJyZD0lxcTfMnTVe2aqS/nru0sLBz74s6XSj7SVD6rJfTR+g+6ZIAjiRKgiiY44rsSitZ44JcT6E6Nv8ADvunok2Kpd6KNPgf3wdbREISw/prkd3t5U2OMjZbHeQ3FanHkTdVi2KAUBX/AJ69g85QGWhvhqN3XPDWgJ6gFAKAp/F+6LbOGOpZaLlLoLrIL/afTcp/W5VrYw1XRXiRXvEGeElElHKAqRLsERTFVVewiJXZS5GjTXNmAWi7GSCEJ9SXYibo+aq2h9xk9zUuco/ii26T0VKalt3C6AjaMrmYjLgpKachHhyYdqrNVLzlmj6l1aMouuvjnm/yPWPBCG8zpJ19xFQZUozax7IiIhin94VrnOuTTuS7om5+2q3Hbtv9UvyRsKtMdEKAUBX/AJ69g85QGWhvhqN3XPDWgJ6gFAKA1F+KK59E4XnGQsCuE2Oxh2xFVeX/ACq2nSIZuz3JlTeSxA8waGY3l9RzDYy0Z4/auAp4VdZHmct1aeKH4tI2xpzTl11Fcfd9uESfQCdJXCyigjgiqq7eyqVjudzCmOqXI5/Z7Ke4nohz5l8snAu6HIA7zMaZjIuJtRlI3CTtZiQRHu7a1F/XYJeRNvxOg232xNyzbJKPhzNwwYMWBDZhxG0ajRwRtpseRBHYlc3ZNzk5Pi2djVXGuKjFYijnrAzFAKAr/wA9ewecoDLQ3w1G7rnhrQE9QCgFAUzidwvtnEC3QoNwmyITcJ5XwWPkXMRAod8hiXIi7Kt7TduhtpJ5IbqVNYZp7UfBCFodyO7ZnZ10dnIYPKbYkLYtqKphuhTaSr2e1XRdO6h6revTHByv3BtmowjBOXF9hduB1knx7hc50qM6wKNAw0roEGZSJSLDMicmVKq9cvjKMYpp8cnv2ztpxnOUk1wxx9vA29XOHXigFAKAUBX/AJ69g85QGWhvhqN3XPDWgNAyeKvFSdB1ZqS36lhQbTY5xsQ7e+wwrj4K4qADSqKqSoOXl5a6JbOhOEHFuUlz4mud02m0+CNl2HjvpKPpawytX3Fm3Xy5xQffiNg4eVCVUF0hBD3YuCmdM3YWtfZ06bnJVrMUyxHcR0rVzJ5njHw3eisTG7yBRJMz3czI3TyNlJyiWTMoYJ3pouK7KgexuTxp44z8CRXw7yQvOvdM2y7rYXZo+/SiuS24IiZkjbYEeYyEVEEwBfvKlY1bWc0pY8ucGN16hFvtSbNadfNfsabjaiO7xXAefVkbcTTe8JBVcSwFEXL3tdB+w27tdWh8Fzyzj/5TdxpVznHjLGnCybGd4kaSiOtxbhPCPOyCUhlEM0aNRRVAiEVRFTkwrSrpt0lmMcx+p0b6xt4NRnLEscefDwIy6a2emah0tGsEpCgXQ3XJJ7vabTRYKnfpmH7h7anq2SjXY7F5o4x737IrX9Sc7qY0vyTznh2L3+5lh1pqVrTGlLpf3W98NuYJ4WVLLnNNgBmwXDMSonJWv29XqTUe83Vk9MWzWjf4jrYPDTrZJgC3dHJbkGNZhexzutoJqSuKCKgI2aES5fs7NbB9Kl62hPy4zkr/ALtaNXaWuBxb04xpOy3vVD7Vll3ljpLFuQjkO5FxUVEQDeEmXBVXLhVaWym5yjDzKPaSq9KKcuGS02DUNk1Da2rrZZjc63vYo2+3jhiK4EioqIqKi8qKlVrKpQlpksMkjJSWUdD569g85UZkcGmSlDolSiBvZQtSFjtoqIpOIpZBxXBExKsoYys8jx8jWHCf8PVhTTrczXdl3uoCkOuE068RCLeKICELR7tccFL8tbje9TlrxVLy4KdO1WPMuJxM6R4h6Y1/q2XbNJRb/Evyf8ZOdeZaajMoK5WVA9uVBwBQRExypguFeu+qyqCc3Fx5rvGicZPCzkgLzojqx+G9+FqdBtt8W5dOhMKQkayVcRsGx3akmJMivIuxO5U1e49Td5hxjpx8P7kcq9NWHweS5aI4d6kj6KvmpLuBzteapj/vd4oi40w5gIspjlQVyd8SdwexUM93X68IrhVBkW5oslt54WbJL6lt0hwv0/CtsCVcbeJXoAE3ycMjQXeX7mZW1y9yot51SyUpKMvJ/T6kHT+iUwhGU4/9O33/AEKzE01re3WO+WIbA1MdnOOGt2J1vExPBO9QlzKX6Q4qmC1fnuaJ2Qs1uOn9OGauGz3VdVlXpqTlnzZXt7iW01o++QdR2WTIiKMS0Wnd5s4LjKczEYIiLjji6u3kqtut5XKqaT805/L2Rc2XT7YX1uS8sK/D/J5z9SF11B4q604XJa5tjbg3i43NtqVEYdBRagNkh70yJxUVVIU2Cv5Kh28qKrtSlmKj8zdWKc4YxxyQnEfgA63EusvS7DlxuF7ksNNxl3bbUCNsKQYKRJmU1aBFXlw2VNtepZaU+CivxfYYW7b/AF7Tk1fw51fbeIQXq2QblcbMlsj26CdlnNQpUbo4CCtkryLi2WVS2duvKN1XKrS3FS1NvUspns6ZKWVnGOw2bwp0m3pjR0eAkJ23OvOuypEJ+QMtxs3S5CeAQElyiOOCcta7eXepZnOfhgsUw0xwd/569g85VUlMtDfDUb7Ccx/bWgJ6gFAdO42a0XJWVuMJiYsY95H6Q0Du7P8AWDOi5V+1KzjZKPJ4PHFPmdysD0UAoBQCgFAKAUBX8U69YY7egcn8ygIeLj0iZuen/wAc83unDo2P879L9bLsoDs+k/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAiv3fvf/db/P8A4nvT+H4nd0B//9k="
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => "Sag danke und unterstütze den Modulentwickler: paypal.me/mbstern"
                         ]
                    ]
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

    private function BuildCapabilityKey(string $type, string $capability, string $code): string
    {
        return strtolower(trim($type) . '|' . trim($capability) . '|' . trim($code));
    }

    private function GetCompatibilityCapabilitiesForForm(): array
    {
        $data = $this->LoadCompatibility(false);
        $values = $this->BuildCapabilitiesListFromCompatibilityItems($data);

        $selected = json_decode($this->ReadPropertyString('SelectedCapabilities'), true);
        if (!is_array($selected)) {
            $selected = [];
        }

        $selectedByCapabilityKey = [];

        foreach ($selected as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $capabilityKey = (string)($entry['capabilityKey'] ?? '');
            if ($capabilityKey === '') {
                continue;
            }

            $selectedByCapabilityKey[$capabilityKey] =
                ($entry['selected'] ?? false) === true ||
                ($entry['selected'] ?? false) === 1 ||
                ($entry['selected'] ?? false) === '1' ||
                strtolower((string)($entry['selected'] ?? '')) === 'true';
        }

        foreach ($values as &$entry) {
            $capabilityKey = (string)($entry['capabilityKey'] ?? '');
            if ($capabilityKey !== '' && isset($selectedByCapabilityKey[$capabilityKey])) {
                $entry['selected'] = $selectedByCapabilityKey[$capabilityKey];
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
        $powertrainType = strtoupper(trim($this->ReadPropertyString('PowertrainType')));

        if ($powertrainType === 'ICE') {
            $powertrainType = '';
        }

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

            $itemPowertrainType = strtoupper((string)($attributes['powertrainType'] ?? ''));
            $wantedPowertrainType = strtoupper($powertrainType);

            $powertrainMatches =
                $wantedPowertrainType === '' ||
                $itemPowertrainType === '' ||
                $itemPowertrainType === $wantedPowertrainType;

            if ($yearMatches && $modelMatches && $powertrainMatches) {
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

        $this->SendDebug('FetchSignals/RAW', (string)$result, 0);

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
            $meta = is_array($signal['meta'] ?? null)
                ? $signal['meta']
                : (is_array($attributes['meta'] ?? null) ? $attributes['meta'] : []);

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

            $this->ApplySignalFromV3($signalCode, $body, $status, $selectedMap[$signalCode], $meta);
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

            $this->ApplySignalFromV3($signalCode, $body, $status, $selectedMap[$signalCode], $meta);
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

    private function ApplySignalFromV3(string $code, array $body, ?array $status, array $definitionMeta, array $signalMeta = []): bool
    {
        if ($status !== null && isset($status['value'])) {
            $statusValue = strtoupper((string)$status['value']);

            if ($statusValue !== 'SUCCESS' && $statusValue !== 'OK') {
                $this->SendDebug(
                    'SignalStatus/' . $code,
                    json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    0
                );
                return false;
            }
        }

        $changed = false;
        $definition = $this->GetSignalDefinition($code, $body);
        $variables = $this->GetVariablesFromDefinition($definition, $body);

        $signalBasePosition = $this->GetSignalBasePosition($code);

        foreach ($variables as $variableIndex => $variable) {
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

            if ($this->TypedVariableValueDiffers($ident, $value, (int)$variable['type'])) {
                $changed = true;
            }

            $this->RegisterOrUpdateTypedVariable(
                $ident,
                (string)($variable['name'] ?? $ident),
                $value,
                (int)$variable['type'],
                (string)($variable['profile'] ?? ''),
                false,
                $signalBasePosition + (int)$variableIndex
            );
        }

        if (empty($variables) && !empty($body)) {
            $ident = (string)($definition['ident'] ?? '');
            if ($ident !== '') {
                $value = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                if ($this->TypedVariableValueDiffers($ident, $value, VARIABLETYPE_STRING)) {
                    $changed = true;
                }

                $this->RegisterOrUpdateTypedVariable(
                    $ident,
                    (string)($definition['name'] ?? $code),
                    $value,
                    VARIABLETYPE_STRING,
                    '',
                    false,
                    $signalBasePosition
                );
            }
        }

        $oemTimestamp = $this->ParseSmartcarTimestamp($signalMeta['oemUpdatedAt'] ?? null);

        if ($oemTimestamp > 0) {
            $stored = json_decode($this->ReadAttributeString('LastOEMSignalTimes'), true);
            if (!is_array($stored)) {
                $stored = [];
            }

            $oldTimestamp = (int)($stored[$code] ?? 0);

            if ($oldTimestamp !== $oemTimestamp) {
                $stored[$code] = $oemTimestamp;
                $this->WriteAttributeString(
                    'LastOEMSignalTimes',
                    json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            if ($this->ReadPropertyBoolean('ShowOEMUpdatedAtVariables')) {
                $oemIdent = $this->BuildOEMTimestampIdent($code);
                $oemName = (string)($definitionMeta['name'] ?? $definition['name'] ?? $code) . ' – OEM-Datenstand';

                $this->RegisterOrUpdateTypedVariable(
                    $oemIdent,
                    $oemName,
                    $oemTimestamp,
                    VARIABLETYPE_INTEGER,
                    '~UnixTimestamp',
                    false,
                    $signalBasePosition + max(1, count($variables))
                );
            }
        }

        if ($changed) {
            $now = time();

            if ((int)$this->GetValue('LastSignalsAt') !== $now) {
                $this->SetValue('LastSignalsAt', $now);
            }
        }

        return $changed;
    }


    private function BuildSelectedSignalPositionMap(array $selected): array
    {
        $signals = [];

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

            $signals[] = [
                'code'  => $signalCode,
                'group' => (string)($entry['group'] ?? ''),
                'name'  => (string)($entry['name'] ?? $signalCode)
            ];
        }

        usort($signals, static function (array $a, array $b): int {
            $groupCompare = strcasecmp($a['group'], $b['group']);
            if ($groupCompare !== 0) {
                return $groupCompare;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        $positions = [];
        $position = 100;

        foreach ($signals as $signal) {
            $positions[$signal['code']] = $position;
            $position += 10;
        }

        return $positions;
    }

    private function GetSignalBasePosition(string $signalCode): int
    {
        $positions = $this->BuildSelectedSignalPositionMap($this->GetSelectedCapabilitiesResolved());
        return $positions[$signalCode] ?? 8000;
    }

    private function BuildOEMTimestampIdent(string $code): string
    {
        return $this->BuildSignalIdent($code) . '_OEMUpdatedAt';
    }

    private function ParseSmartcarTimestamp($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        try {
            if (is_numeric($value)) {
                $timestamp = (int)$value;

                if ($timestamp > 20000000000) {
                    $timestamp = (int)floor($timestamp / 1000);
                }

                return $timestamp;
            }

            return (new DateTime((string)$value))->getTimestamp();
        } catch (Throwable $e) {
            return 0;
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

        foreach ($this->GetCurrentConnectionPermissions() as $existingPermission) {
            if (!in_array($existingPermission, $permissions, true)) {
                $permissions[] = $existingPermission;
            }
        }

        $permissions = array_values(array_unique(array_filter(array_map(
            static fn($permission): string => trim((string)$permission),
            $permissions
        ))));

        $this->SendDebug(
            'Connect/Result',
            'Permissions count=' . count($permissions) . ' values=' . json_encode($permissions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

        if (empty($permissions)) {
            return 'Fehler: Keine aktivierten Signale/Befehle mit Permission ausgewählt. Bitte Debug prüfen: Connect/SelectedCapabilitiesRaw, Connect/Entry und Connect/Summary.';
        }

        $state = 'vehicle_' . $this->ReadPropertyString('VehicleID') . '_' . bin2hex(random_bytes(8));

        $vehicleId = trim($this->ReadPropertyString('VehicleID'));

        if ($vehicleId === '') {
            return 'Fehler: VehicleID fehlt.';
        }

        $request = [
            'DataID'         => '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}',
            'Command'        => 'BuildConnectURL',
            'Mode'           => 'live',
            'State'          => $state,
            'Permissions'    => $permissions,
            'VehicleID'      => $vehicleId,
            'Reauthenticate' => true
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

    private function GetCurrentConnectionPermissions(): array
    {
        if (!$this->HasParentConnection()) {
            return [];
        }

        $vehicleId = trim($this->ReadPropertyString('VehicleID'));
        if ($vehicleId === '') {
            return [];
        }

        $result = $this->SendDataToParent(json_encode([
            'DataID'  => '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}',
            'Command' => 'LoadConnections'
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $connections = json_decode((string)$result, true);
        if (!is_array($connections)) {
            return [];
        }

        foreach ($connections as $connection) {
            if (!is_array($connection)) {
                continue;
            }

            if ((string)($connection['vehicleId'] ?? '') !== $vehicleId) {
                continue;
            }

            $permissions = $connection['permissions'] ?? [];
            if (!is_array($permissions)) {
                return [];
            }

            return array_values(array_unique(array_filter(array_map(
                static fn($permission): string => trim((string)$permission),
                $permissions
            ))));
        }

        return [];
    }

    private function GetSelectedPermissions(): array
    {
        $entries = $this->GetSelectedCapabilitiesResolved();
        $permissions = [];

        foreach ($entries as $entry) {
            $permission = trim((string)($entry['permission'] ?? ''));

            if ($permission === '' && strtolower((string)($entry['type'] ?? '')) === 'command') {
                $permission = $this->GetCommandPermission((string)($entry['code'] ?? ''));
            }

            if ($permission !== '') {
                $permissions[$permission] = true;
            }
        }

        return array_keys($permissions);
    }

    private function CreateSignalVariable(string $signalCode, string $name, int $basePosition): bool
    {
        $definition = $this->GetSignalDefinition($signalCode, []);
        $createdAny = false;

        foreach ($this->GetVariablesFromDefinition($definition, []) as $variableIndex => $variable) {
            $created = $this->RegisterOrUpdateTypedVariable(
                $variable['ident'],
                $variable['name'],
                $this->GetDefaultValueForType($variable['type']),
                $variable['type'],
                $variable['profile'],
                true,
                $basePosition + (int)$variableIndex
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
        $signalMeta = is_array($signal['meta'] ?? null)
            ? $signal['meta']
            : (is_array($attributes['meta'] ?? null) ? $attributes['meta'] : []);

        $definitionMeta = $this->GetSignalDefinition($signalCode, $body);

        $this->ApplySignalFromV3($signalCode, $body, $status, $definitionMeta, $signalMeta);
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
        $signalPositions = $this->BuildSelectedSignalPositionMap($selected);
        $commandPosition = 9000;

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

                    $managedIdents[$this->BuildOEMTimestampIdent($signalCode)] = true;

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

                $oemIdent = $this->BuildOEMTimestampIdent($signalCode);
                $managedIdents[$oemIdent] = true;

                if ($this->ReadPropertyBoolean('ShowOEMUpdatedAtVariables')) {
                    $wantedIdents[$oemIdent] = true;

                    $storedOEMTimes = json_decode($this->ReadAttributeString('LastOEMSignalTimes'), true);
                    if (!is_array($storedOEMTimes)) {
                        $storedOEMTimes = [];
                    }

                    $this->RegisterOrUpdateTypedVariable(
                        $oemIdent,
                        (string)($entry['name'] ?? $signalCode) . ' – OEM-Datenstand',
                        (int)($storedOEMTimes[$signalCode] ?? 0),
                        VARIABLETYPE_INTEGER,
                        '~UnixTimestamp',
                        true,
                        ($signalPositions[$signalCode] ?? 1000) + max(1, count($this->GetVariablesFromDefinition($definition, [])))
                    );
                }

                $name = (string)($entry['name'] ?? $signalCode);
                $created = $this->CreateSignalVariable(
                    $signalCode,
                    $name,
                    $signalPositions[$signalCode] ?? 1000
                );

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
                    true,
                    $commandPosition
                );
                $commandPosition += 10;

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

                if ($permission === '' && strtolower($type) === 'command') {
                    $permission = $this->GetCommandPermission($code);
                }

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
                    'sortKey'       => $sortKey,
                    'capabilityKey' => $this->BuildCapabilityKey($type, $capKey, $code),
                    'selected'      => false,
                    'capability'    => $capKey,
                    'type'          => $type,
                    'name'          => $displayName,
                    'group'         => $group,
                    'code'          => $code,
                    'permission'    => $permission
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

        $fullByCapabilityKey = [];
        foreach ($fullList as $entry) {
            $capabilityKey = (string)($entry['capabilityKey'] ?? '');
            if ($capabilityKey !== '') {
                $fullByCapabilityKey[$capabilityKey] = $entry;
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

            $capabilityKey = (string)($savedEntry['capabilityKey'] ?? '');
            if ($capabilityKey === '') {
                continue;
            }

            if (!isset($fullByCapabilityKey[$capabilityKey])) {
                $this->SendDebug('Selected/ResolveMissing', 'Kein FullEntry für capabilityKey=' . $capabilityKey, 0);
                continue;
            }

            $entry = $fullByCapabilityKey[$capabilityKey];
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

        $result = $this->SendDataToParent(json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

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
            'navigation-set-destination' => [
                'ident' => 'CommandNavigationDestination',
                'name'  => 'Ziel setzen',
                'type'  => VARIABLETYPE_STRING,
                'profile' => '', // kein Standardprofil sinnvoll
                'path'  => '/commands/navigation/set-destination'
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

    private function GetCommandPermission(string $code): string
    {
        return match (strtolower(trim($code))) {
            'charge-start',
            'charge-stop',
            'charge-set-limit' => 'control_charge',

            'security-lock',
            'security-unlock' => 'control_security',

            'navigation-set-destination' => 'control_navigation',

            default => ''
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

        if (!IPS_VariableProfileExists('SMCAR.Pressure')) {
            IPS_CreateVariableProfile('SMCAR.Pressure', VARIABLETYPE_FLOAT);
        }
        IPS_SetVariableProfileText('SMCAR.Pressure', '', ' bar');
        IPS_SetVariableProfileDigits('SMCAR.Pressure', 2);

        if (!IPS_VariableProfileExists('SMCAR.Status')) {
            IPS_CreateVariableProfile('SMCAR.Status', VARIABLETYPE_STRING);
        }
        IPS_SetVariableProfileAssociation('SMCAR.Status', 'OPEN', 'Offen', '', 0x00FF00);
        IPS_SetVariableProfileAssociation('SMCAR.Status', 'CLOSED', 'Geschlossen', '', 0xFF0000);

        if (!IPS_VariableProfileExists('SMCAR.Charge')) {
            IPS_CreateVariableProfile('SMCAR.Charge', VARIABLETYPE_STRING);
        }
    }

    private function RegisterOrUpdateTypedVariable(string $ident, string $name, mixed $value, int $type, string $profile, bool $onlySetValueOnCreate = false, ?int $position = null): bool
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

        $id = @$this->GetIDForIdent($ident);
        if ($created && $id && $position !== null) {
            IPS_SetPosition($id, $position);
        }

        if ($onlySetValueOnCreate && !$created) {
            return false;
        }

        if ($this->TypedVariableValueDiffers($ident, $value, $type)) {
            $this->SetValue($ident, $this->NormalizeTypedValue($value, $type));
        }

        return $created;
    }

    private function TypedVariableValueDiffers(string $ident, mixed $value, int $type): bool
    {
        $id = @$this->GetIDForIdent($ident);
        if (!$id) {
            return true;
        }

        $currentValue = GetValue($id);
        $newValue = $this->NormalizeTypedValue($value, $type);

        if ($type === VARIABLETYPE_FLOAT) {
            return abs((float)$currentValue - (float)$newValue) > 0.000001;
        }

        return $currentValue !== $newValue;
    }

    private function NormalizeTypedValue(mixed $value, int $type): mixed
    {
        return match ($type) {
            VARIABLETYPE_BOOLEAN => (bool)$value,
            VARIABLETYPE_INTEGER => (int)round((float)$value),
            VARIABLETYPE_FLOAT   => (float)$value,
            default              => is_array($value)
                ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : (string)$value
        };
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
                'source' => 'activeLimit'
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
            'wheel-tires' => [
                'special' => 'multiple',
                'variables' => [
                    [
                        'ident' => 'TireFrontLeft',
                        'name' => 'Reifendruck Vorderreifen Links',
                        'type' => VARIABLETYPE_FLOAT,
                        'profile' => 'SMCAR.Pressure',
                        'source' => 'values',
                        'convert' => fn(array $body) => (float)(array_values(array_filter($body['values'] ?? [], fn($v) =>
                            is_array($v) && (int)($v['row'] ?? -1) === 0 && (int)($v['column'] ?? -1) === 0
                        ))[0]['tirePressure'] ?? 0) * 0.01
                    ],
                    [
                        'ident' => 'TireFrontRight',
                        'name' => 'Reifendruck Vorderreifen Rechts',
                        'type' => VARIABLETYPE_FLOAT,
                        'profile' => 'SMCAR.Pressure',
                        'source' => 'values',
                        'convert' => fn(array $body) => (float)(array_values(array_filter($body['values'] ?? [], fn($v) =>
                            is_array($v) && (int)($v['row'] ?? -1) === 0 && (int)($v['column'] ?? -1) === 1
                        ))[0]['tirePressure'] ?? 0) * 0.01
                    ],
                    [
                        'ident' => 'TireBackLeft',
                        'name' => 'Reifendruck Hinterreifen Links',
                        'type' => VARIABLETYPE_FLOAT,
                        'profile' => 'SMCAR.Pressure',
                        'source' => 'values',
                        'convert' => fn(array $body) => (float)(array_values(array_filter($body['values'] ?? [], fn($v) =>
                            is_array($v) && (int)($v['row'] ?? -1) === 1 && (int)($v['column'] ?? -1) === 0
                        ))[0]['tirePressure'] ?? 0) * 0.01
                    ],
                    [
                        'ident' => 'TireBackRight',
                        'name' => 'Reifendruck Hinterreifen Rechts',
                        'type' => VARIABLETYPE_FLOAT,
                        'profile' => 'SMCAR.Pressure',
                        'source' => 'values',
                        'convert' => fn(array $body) => (float)(array_values(array_filter($body['values'] ?? [], fn($v) =>
                            is_array($v) && (int)($v['row'] ?? -1) === 1 && (int)($v['column'] ?? -1) === 1
                        ))[0]['tirePressure'] ?? 0) * 0.01
                    ]
                ]
            ],

            'wheel-style' => [
                'ident' => 'WheelStyle',
                'name' => 'Felgenstil',
                'type' => VARIABLETYPE_STRING,
                'profile' => ''
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

            // ---------- Niedervolt-Batterie ----------
            'lowvoltagebattery-stateofcharge' => [
                'ident' => 'LowVoltageBatteryLevel',
                'name' => '12V-Batterie',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Progress'
            ],

            // ---------- ICE Erweiterungen ----------
            'internalcombustionengine-amountremaining' => [
                'ident' => 'FuelAmountRemaining',
                'name' => 'Kraftstoffmenge',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => ''
            ],

            'lowvoltagebattery-status' => [
                'ident' => 'LowVoltageBatteryStatus',
                'name' => '12V-Batterie Status',
                'type' => VARIABLETYPE_STRING,
                'profile' => ''
            ],

            'internalcombustionengine-range' => [
                'ident' => 'FuelRange',
                'name' => 'Reichweite Kraftstoff',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Odometer',
                'convert' => $milesToKm
            ],

            // ---------- Getriebe ----------
            'transmission-gearstate' => [
                'ident' => 'GearState',
                'name' => 'Gang / Fahrstufe',
                'type' => VARIABLETYPE_STRING,
                'profile' => ''
            ],

            'transmission-drivemode' => [
                    'ident' => 'DriveMode',
                    'name' => 'Fahrmodus',
                    'type' => VARIABLETYPE_STRING,
                    'profile' => ''
                ],

            // ---------- Überwachung ----------
            'surveillance-isenabled' => [
                'ident' => 'SurveillanceEnabled',
                'name' => 'Überwachung aktiv',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch'
            ],

            'surveillance-brand' => [
                'ident' => 'SurveillanceBrand',
                'name' => 'Überwachung Hersteller',
                'type' => VARIABLETYPE_STRING,
                'profile' => ''
            ],

            // ---------- Diagnostics ----------
            'diagnostics-dtccount' => [
                'ident' => 'DTCCount',
                'name' => 'Anzahl Fehlercodes',
                'type' => VARIABLETYPE_INTEGER,
                'profile' => ''
            ],

            'diagnostics-dtclist' => [
                'ident' => 'DTCList',
                'name' => 'Fehlercode-Liste',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'values',
                'convert' => fn(array $body) => json_encode($body['values'] ?? [], JSON_UNESCAPED_UNICODE)
            ],

            'diagnostics-abs' => [
                'ident' => 'DiagnosticsABS',
                'name' => 'ABS-System',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'status'
            ],

            'diagnostics-activesafety' => [
                'ident' => 'DiagnosticsActiveSafety',
                'name' => 'Aktive Sicherheitssysteme',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'status'
            ],

            'diagnostics-airbag' => [
                'ident' => 'DiagnosticsAirbag',
                'name' => 'Airbag-System',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'status'
            ],

            'diagnostics-brakefluid' => [
                'ident' => 'DiagnosticsBrakeFluid',
                'name' => 'Bremsflüssigkeit',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'status'
            ],

            'diagnostics-driverassistance' => [
                'ident' => 'DiagnosticsDriverAssistance',
                'name' => 'Fahrerassistenz',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'status'
            ],

            'diagnostics-emissions' => [
                'ident' => 'DiagnosticsEmissions',
                'name' => 'Abgassystem',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'status'
            ],

            'diagnostics-engine' => [
                'ident' => 'DiagnosticsEngine',
                'name' => 'Motor',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'status'
            ],

            'diagnostics-evbatteryconditioning' => [
                'ident' => 'DiagnosticsEVBatteryConditioning',
                'name' => 'EV Batteriekonditionierung',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'status'
            ],

            'diagnostics-evcharging' => [
                'ident' => 'DiagnosticsEVCharging',
                'name' => 'EV Ladesystem',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'status'
            ],

            'diagnostics-evdriveunit' => [
                'ident' => 'DiagnosticsEVDriveUnit',
                'name' => 'EV Antriebseinheit',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'status'
            ],

            'diagnostics-evhvbattery' => [
                'ident' => 'DiagnosticsEVHVBattery',
                'name' => 'EV Hochvoltbatterie',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'status'
            ],

            'diagnostics-lighting' => [
                'ident' => 'DiagnosticsLighting',
                'name' => 'Beleuchtungssystem',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'status'
            ],

            'diagnostics-mil' => [
                'ident' => 'DiagnosticsMIL',
                'name' => 'Motorkontrollleuchte',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'status'
            ],

            'diagnostics-oillife',
            'internalcombustionengine-oillife' => [
                'ident' => 'OilLife',
                'name' => 'Öl-Lebensdauer',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Progress'
            ],

            'diagnostics-oilpressure',
            'internalcombustionengine-oilpressure' => [
                'ident' => 'OilPressure',
                'name' => 'Öldruck',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => ''
            ],

            'diagnostics-oiltemperature',
            'internalcombustionengine-oiltemperature' => [
                'ident' => 'OilTemperature',
                'name' => 'Öltemperatur',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '~Temperature'
            ],

            'diagnostics-telematics' => [
                'ident' => 'DiagnosticsTelematics',
                'name' => 'Telematiksystem',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'status'
            ],

            'diagnostics-tirepressure' => [
                'ident' => 'DiagnosticsTirePressure',
                'name' => 'Reifendrucksystem',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'status'
            ],

            'diagnostics-tirepressuremonitoring' => [
                'ident' => 'DiagnosticsTPMS',
                'name' => 'Reifendrucküberwachung',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'status'
            ],

            'diagnostics-transmission' => [
                'ident' => 'DiagnosticsTransmission',
                'name' => 'Getriebe',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'status'
            ],

            'diagnostics-washerfluid' => [
                'ident' => 'DiagnosticsWasherFluid',
                'name' => 'Waschwasser',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'status'
            ],

            'diagnostics-waterinfuel',
            'internalcombustionengine-waterinfuel' => [
                'ident' => 'WaterInFuel',
                'name' => 'Wasser im Kraftstoff',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch'
            ],

            // ---------- HVAC ----------
            'hvac-cabintargettemperature' => [
                'ident' => 'CabinTargetTemperature',
                'name' => 'Zieltemperatur Innenraum',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '~Temperature'
            ],

            'hvac-iscabinhvacactive' => [
                'ident' => 'IsCabinHVACActive',
                'name' => 'Innenraum-Klimatisierung aktiv',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch'
            ],

            'hvac-isfrontdefrosteractive' => [
                'ident' => 'IsFrontDefrosterActive',
                'name' => 'Frontscheiben-Defroster aktiv',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch'
            ],

            'hvac-isreardefrosteractive' => [
                'ident' => 'IsRearDefrosterActive',
                'name' => 'Heckscheibenheizung aktiv',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch'
            ],

            'hvac-issteeringheateractive' => [
                'ident' => 'IsSteeringHeaterActive',
                'name' => 'Lenkradheizung aktiv',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch'
            ],

            // ---------- Motion ----------
            'motion-currentspeed' => [
                'ident' => 'CurrentSpeed',
                'name' => 'Aktuelle Geschwindigkeit',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => ''
            ],

            // ---------- Service ----------
            'service-isinservice' => [
                'ident' => 'IsInService',
                'name' => 'In Werkstatt / Service',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch'
            ],

            'service-records' => [
                'ident' => 'ServiceRecords',
                'name' => 'Service-Historie',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'source' => 'values',
                'convert' => fn(array $body) => json_encode($body['values'] ?? [], JSON_UNESCAPED_UNICODE)
            ],

            default => $this->GuessSignalDefinition($code, $body)
        };
    }
}