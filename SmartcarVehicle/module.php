<?php
// Build 2026-08-16: kompakte Webhook-Auswahl ohne Statusspalte/ExpansionPanel.
// Build: 2026-08-16 Webhook checkbox list v3

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
    $this->RegisterPropertyString('Permissions', '[]');
    $this->RegisterPropertyBoolean('ShowOEMUpdatedAtVariables', false);
    $this->RegisterPropertyString('VariableSelection', '[]');
    $this->RegisterAttributeString('DiscoveredVariables', '{}');
    $this->RegisterAttributeString('LastSuccessfulSignalPayloads', '{}');
    $this->RegisterAttributeString('LastOEMSignalTimes', '{}');
    $this->RegisterAttributeString('ModuleVariableNames', '{}');

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
        $this->EnsureLastSignalsAtVariable();
        $this->ApplyOEMTimestampVisibility();

        if ($this->ReadPropertyString('VehicleID') === '') {
            $this->SetStatus(201);
            return;
        }

        $this->SetStatus(102);

        // Bereits vorhandene Signalvariablen (z. B. aus einer älteren Modulversion)
        // sofort in die Auswahlliste übernehmen. Dabei werden weder Namen noch Werte
        // verändert.
        $this->RememberExistingSignalVariables();

        $this->ApplyVariableSelection();
        $this->ApplyVehicleAccessCommands();
        $this->RestoreSelectedSignalsFromCache();

        // Kein API-/Parent-Aufruf in ApplyChanges():
        // Bei Modul-Updates kann IP-Symcon die Instanz bereits anwenden,
        // bevor das Parent-Interface des Splitters wieder vollständig bereitsteht.
        // Die Signale werden bewusst nur über den Button bzw. nach einem
        // abgeschlossenen Connect-/Reauth-Flow synchronisiert.
    }


    private function EnsureLastSignalsAtVariable(): void
    {
        $existingId = @$this->GetIDForIdent('LastSignalsAt');

        if (!$existingId) {
            $this->RegisterVariableInteger(
                'LastSignalsAt',
                'Letzte Signale',
                '~UnixTimestamp'
            );

            $existingId = @$this->GetIDForIdent('LastSignalsAt');
        }

        if ($existingId) {
            IPS_SetPosition($existingId, 10);
        }
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
        if (trim($this->ReadPropertyString('VehicleID')) === '') {
            return json_encode([
                'elements' => [
                    ['type' => 'Label', 'caption' => 'Diese Smartcar Fahrzeug-Instanz darf nicht manuell erstellt werden.'],
                    ['type' => 'Label', 'caption' => 'Bitte löschen Sie diese Instanz wieder und erstellen Sie das Fahrzeug ausschließlich über den Smartcar-Konfigurator.']
                ],
                'actions' => []
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $webhookOverview = $this->GetWebhookOverviewFromParent();

        $form = [
            'elements' => [
                [
                    'type' => 'RowLayout',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Fahrzeug: ' . $this->ReadPropertyString('VehicleCaption')],
                        ['type' => 'Label', 'caption' => 'Antrieb: ' . $this->ReadPropertyString('PowertrainType')]
                    ]
                ],
                [
                    'type' => 'RowLayout',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Vehicle ID: ' . $this->ReadPropertyString('VehicleID')],
                        ['type' => 'Label', 'caption' => 'Connection ID: ' . $this->ReadPropertyString('ConnectionID')],
                        ['type' => 'Label', 'caption' => 'User ID: ' . $this->ReadPropertyString('UserID')]
                    ]
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'ShowOEMUpdatedAtVariables',
                    'caption' => 'OEM-Aktualisierungszeit je Signal als zusätzliche Variable anzeigen'
                ],
                [
                    'type' => 'Label',
                    'caption' => 'Die Signale und Berechtigungen werden ausschließlich über Smartcar → Configuration → Vehicle Access festgelegt. Erkannte Variablen sind standardmäßig aktiv und können hier gezielt abgewählt werden.'
                ],
                [
                    'type' => 'List',
                    'name' => 'VariableSelection',
                    'caption' => 'Variablen',
                    'rowCount' => 14,
                    'add' => false,
                    'delete' => false,
                    'columns' => [
                        ['caption' => 'Aktiv', 'name' => 'Active', 'width' => '70px', 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Name', 'name' => 'Name', 'width' => '260px'],
                        ['caption' => 'Typ', 'name' => 'Kind', 'width' => '110px'],
                        ['caption' => 'Smartcar', 'name' => 'Source', 'width' => 'auto'],
                        ['caption' => 'Ident', 'name' => 'Ident', 'width' => '0px', 'save' => true]
                    ],
                    'values' => $this->BuildVariableSelectionRows()
                ]
            ],
            'actions' => [
                [
                    'type' => 'Button',
                    'caption' => 'Vehicle Access synchronisieren',
                    'onClick' => 'echo SMCARV_GenerateConnectURL($id);'
                ],
                [
                    'type' => 'Button',
                    'caption' => 'Signale aus Vehicle Access abrufen',
                    'onClick' => 'SMCARV_SyncVehicleAccessSignals($id);'
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
                        ],
                    ],
                ]
            ]
        ];

        $webhookPanelItems = [];

        if (empty($webhookOverview['success'])) {
            $webhookPanelItems[] = [
                'type' => 'Label',
                'caption' => 'Webhooks konnten nicht geladen werden: ' . (string)($webhookOverview['error'] ?? 'Unbekannter Fehler')
            ];
        } else {
            $webhooks = is_array($webhookOverview['webhooks'] ?? null) ? $webhookOverview['webhooks'] : [];
            $webhookRows = [];

            foreach ($webhooks as $webhook) {
                if (!is_array($webhook)) {
                    continue;
                }

                $webhookId = trim((string)($webhook['id'] ?? ''));
                if ($webhookId === '') {
                    continue;
                }

                $subscribed = (bool)($webhook['subscribed'] ?? false);

                $webhookRows[] = [
                    'Active' => $subscribed,
                    'Name' => (string)($webhook['name'] ?? $webhookId),
                    'WebhookID' => $webhookId
                ];
            }

            if (empty($webhookRows)) {
                $webhookPanelItems[] = [
                    'type' => 'Label',
                    'caption' => 'Für diese Smartcar-Anwendung wurden keine Webhooks gefunden.'
                ];
            } else {
                $webhookPanelItems[] = [
                    'type' => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'List',
                            'name' => 'WebhookSelection',
                            'caption' => 'Webhooks',
                            'rowCount' => 3,
                            'add' => false,
                            'delete' => false,
                            'columns' => [
                                ['caption' => 'Aktiv', 'name' => 'Active', 'width' => '60px', 'edit' => ['type' => 'CheckBox']],
                                ['caption' => 'Webhook', 'name' => 'Name', 'width' => '180px'],
                                ['caption' => 'WebhookID', 'name' => 'WebhookID', 'width' => '0px', 'save' => true]
                            ],
                            'values' => $webhookRows
                        ],
                        [
                            'type' => 'Button',
                            'caption' => 'Übernehmen / neu laden',
                            'onClick' => '$rows = []; foreach ($WebhookSelection as $row) { $rows[] = $row; } echo SMCARV_ApplyWebhookSelection($id, json_encode($rows));'
                        ]
                    ]
                ];
            }
        }

        foreach ($webhookPanelItems as $webhookItem) {
            $form['actions'][] = $webhookItem;
        }

        return json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function GetWebhookOverviewFromParent(): array
    {
        if (!$this->HasParentConnection()) {
            return ['success' => false, 'error' => 'Kein Splitter/Parent verbunden.', 'webhooks' => []];
        }

        $vehicleId = trim($this->ReadPropertyString('VehicleID'));
        if ($vehicleId === '') {
            return ['success' => false, 'error' => 'VehicleID fehlt.', 'webhooks' => []];
        }

        $result = $this->SendDataToParent(json_encode([
            'DataID' => '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}',
            'Command' => 'GetWebhookOverview',
            'VehicleID' => $vehicleId
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $decoded = json_decode((string)$result, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'error' => 'Ungültige Antwort vom Splitter.', 'webhooks' => []];
        }

        return $decoded;
    }

    public function ApplyWebhookSelection(string $selectionJson): string
    {
        if (!$this->HasParentConnection()) {
            return 'Fehler: Kein Smartcar Splitter verbunden.';
        }

        $rows = json_decode($selectionJson, true);
        if (!is_array($rows)) {
            return 'Fehler: Webhook-Auswahl ist ungültig.';
        }

        $desired = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $webhookId = trim((string)($row['WebhookID'] ?? ''));
            if ($webhookId !== '' && (bool)($row['Active'] ?? false)) {
                $desired[$webhookId] = true;
            }
        }

        $overview = $this->GetWebhookOverviewFromParent();
        if (empty($overview['success'])) {
            return 'Fehler: ' . (string)($overview['error'] ?? 'Webhook-Zuordnungen konnten nicht geladen werden.');
        }

        $webhooks = is_array($overview['webhooks'] ?? null) ? $overview['webhooks'] : [];
        $current = [];
        $known = [];

        foreach ($webhooks as $webhook) {
            if (!is_array($webhook)) {
                continue;
            }

            $webhookId = trim((string)($webhook['id'] ?? ''));
            if ($webhookId === '') {
                continue;
            }

            $known[$webhookId] = true;
            if ((bool)($webhook['subscribed'] ?? false)) {
                $current[$webhookId] = true;
            }
        }

        // Nur Webhooks berücksichtigen, die die Management API aktuell tatsächlich liefert.
        $desired = array_intersect_key($desired, $known);

        $toSubscribe = array_diff_key($desired, $current);
        $toUnsubscribe = array_diff_key($current, $desired);
        $hasChanges = !empty($toSubscribe) || !empty($toUnsubscribe);

        $messages = [];

        if ($hasChanges) {
            foreach (array_keys($toUnsubscribe) as $webhookId) {
                $result = $this->ExecuteWebhookManagementCommandRaw('UnsubscribeWebhook', $webhookId);
                if (empty($result['success'])) {
                    return 'Fehler beim Entfernen von Webhook ' . $webhookId . ': ' . (string)($result['error'] ?? 'Unbekannter Fehler');
                }
                $messages[] = 'entfernt';
            }

            foreach (array_keys($toSubscribe) as $webhookId) {
                $result = $this->ExecuteWebhookManagementCommandRaw('SubscribeWebhook', $webhookId);
                if (empty($result['success'])) {
                    return 'Fehler beim Zuordnen von Webhook ' . $webhookId . ': ' . (string)($result['error'] ?? 'Unbekannter Fehler');
                }
                $messages[] = 'zugeordnet';
            }
        } else {
            // Keine Änderung an den Häkchen: die weiterhin gewählten Webhooks neu abonnieren.
            // Bei nur einem gewählten Webhook entspricht dies dem gewünschten "Neu laden".
            foreach (array_keys($desired) as $webhookId) {
                $result = $this->ExecuteWebhookManagementCommandRaw('ReloadWebhook', $webhookId);
                if (empty($result['success'])) {
                    return 'Fehler beim Neu laden von Webhook ' . $webhookId . ': ' . (string)($result['error'] ?? 'Unbekannter Fehler');
                }
                $messages[] = 'neu geladen';
            }

            if (empty($desired)) {
                return 'Keine Webhook-Zuordnung ausgewählt.';
            }
        }

        // Formular hier bewusst NICHT sofort neu laden. Nach Remove/Create kann die
        // Smartcar Management API die neue Subscription für einen kurzen Moment noch
        // nicht in der Übersicht liefern. Ein sofortiges ReloadForm() würde dadurch
        // den gerade gesetzten Haken wieder auf false zurücksetzen.

        if ($hasChanges) {
            return 'Webhook-Zuordnung wurde übernommen.';
        }

        return 'Webhook wurde neu geladen.';
    }

    private function ExecuteWebhookManagementCommandRaw(string $command, string $webhookId): array
    {
        $webhookId = trim($webhookId);
        $vehicleId = trim($this->ReadPropertyString('VehicleID'));
        $userId = trim($this->ReadPropertyString('UserID'));

        if ($webhookId === '' || $vehicleId === '' || $userId === '') {
            return ['success' => false, 'error' => 'WebhookID, VehicleID oder UserID fehlt.'];
        }

        $result = $this->SendDataToParent(json_encode([
            'DataID' => '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}',
            'Command' => $command,
            'WebhookID' => $webhookId,
            'VehicleID' => $vehicleId,
            'UserID' => $userId
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->SendDebug('WebhookManagement/' . $command, (string)$result, 0);

        $decoded = json_decode((string)$result, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'error' => (string)$result];
        }

        return $decoded;
    }

    public function SubscribeWebhook(string $webhookId): string
    {
        return $this->ExecuteWebhookManagementCommand('SubscribeWebhook', $webhookId, 'Webhook-Zuordnung wird angelegt.');
    }

    public function UnsubscribeWebhook(string $webhookId): string
    {
        return $this->ExecuteWebhookManagementCommand('UnsubscribeWebhook', $webhookId, 'Webhook-Zuordnung wurde entfernt.');
    }

    public function ReloadWebhook(string $webhookId): string
    {
        return $this->ExecuteWebhookManagementCommand('ReloadWebhook', $webhookId, 'Webhook wurde neu zugeordnet; Smartcar startet die neue Subscription.');
    }

    public function ReloadWebhookForm(): void
    {
        $this->ReloadForm();
    }

    private function ExecuteWebhookManagementCommand(string $command, string $webhookId, string $successText): string
    {
        if (!$this->HasParentConnection()) {
            return 'Fehler: Kein Smartcar Splitter verbunden.';
        }

        $webhookId = trim($webhookId);
        $vehicleId = trim($this->ReadPropertyString('VehicleID'));
        $userId = trim($this->ReadPropertyString('UserID'));

        if ($webhookId === '' || $vehicleId === '' || $userId === '') {
            return 'Fehler: WebhookID, VehicleID oder UserID fehlt.';
        }

        $result = $this->SendDataToParent(json_encode([
            'DataID' => '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}',
            'Command' => $command,
            'WebhookID' => $webhookId,
            'VehicleID' => $vehicleId,
            'UserID' => $userId
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $decoded = json_decode((string)$result, true);
        $this->SendDebug('WebhookManagement/' . $command, (string)$result, 0);

        if (!is_array($decoded) || empty($decoded['success'])) {
            $error = is_array($decoded) ? (string)($decoded['error'] ?? '') : '';
            return 'Fehler: ' . ($error !== '' ? $error : (string)$result);
        }

        // Create Subscription liefert 202 und wird von Smartcar asynchron verarbeitet.
        // Formular neu laden, damit der aktuelle Management-API-Stand sichtbar wird.
        $this->ReloadForm();

        return $successText;
    }

    public function SyncVehicleAccessSignals(): void
    {
        $selectionRowsBefore = $this->BuildVariableSelectionRows();

        if (!$this->HasParentConnection()) {
            $this->SendDebug('VehicleAccess/Error', 'Kein Splitter/Parent verbunden.', 0);
            return;
        }

        $vehicleId = trim($this->ReadPropertyString('VehicleID'));
        $userId = trim($this->ReadPropertyString('UserID'));
        if ($vehicleId === '' || $userId === '') {
            $this->SendDebug('VehicleAccess/Error', 'VehicleID oder UserID fehlt.', 0);
            return;
        }

        $result = $this->SendDataToParent(json_encode([
            'DataID' => '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}',
            'Command' => 'GetSignals',
            'VehicleID' => $vehicleId,
            'UserID' => $userId
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $decoded = json_decode((string)$result, true);
        if (!is_array($decoded) || empty($decoded['success'])) {
            $this->SendDebug('VehicleAccess/Error', 'GetSignals fehlgeschlagen: ' . (string)$result, 0);
            return;
        }

        $signals = $decoded['body']['data'] ?? $decoded['body']['signals'] ?? [];
        if (is_array($signals) && isset($signals['signals']) && is_array($signals['signals'])) {
            $signals = $signals['signals'];
        }
        if (!is_array($signals)) {
            $this->SendDebug('VehicleAccess/Error', 'Keine Signalliste in der V3-Antwort gefunden.', 0);
            return;
        }

        $successful = [];
        $unsuccessful = [];

        foreach ($signals as $signal) {
            if (!is_array($signal)) {
                continue;
            }

            $attributes = is_array($signal['attributes'] ?? null) ? $signal['attributes'] : $signal;
            $signalCode = trim((string)($attributes['code'] ?? $signal['code'] ?? $signal['id'] ?? ''));
            if ($signalCode === '') {
                continue;
            }

            $body = is_array($attributes['body'] ?? null) ? $attributes['body'] : [];
            $status = is_array($attributes['status'] ?? null) ? $attributes['status'] : null;
            $meta = is_array($signal['meta'] ?? null)
                ? $signal['meta']
                : (is_array($attributes['meta'] ?? null) ? $attributes['meta'] : []);

            $statusValue = strtoupper((string)($status['value'] ?? ''));
            $verified = $status === null
                || $statusValue === ''
                || $statusValue === 'SUCCESS'
                || $statusValue === 'OK';

            if ($verified && $this->HasMeaningfulSignalData($body)) {
                $successful[$signalCode] = $body;
            } else {
                $error = is_array($status['error'] ?? null) ? $status['error'] : [];
                $unsuccessful[$signalCode] = [
                    'status' => $statusValue !== '' ? $statusValue : 'NO_DATA',
                    'code' => (string)($error['code'] ?? ''),
                    'detail' => (string)($error['detail'] ?? '')
                ];
            }

            if ($verified && $this->HasMeaningfulSignalData($body)) {
                $this->RememberSuccessfulSignal($signalCode, $body, $status, $meta);
            }

            $this->ApplySignalFromV3(
                $signalCode,
                $body,
                $status,
                $this->GetSignalDefinition($signalCode, $body),
                $meta,
                false
            );
        }

        $this->TouchLastSignalsAt();

        $this->SendDebug(
            'VehicleAccess/Erfolgreiche Signale',
            json_encode($successful, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

        $this->SendDebug(
            'VehicleAccess/Nicht erfolgreiche Signale',
            json_encode($unsuccessful, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

        // Beim bewussten Abruf auch die aktuell autorisierten Steuerungen abgleichen.
        $this->ApplyVehicleAccessCommands();
        $selectionRowsAfter = $this->BuildVariableSelectionRows();
        if ($selectionRowsAfter !== $selectionRowsBefore) {
            $this->RefreshVariableSelectionForm();
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

        $payloadVehicleId = (string)(
            $payload['vehicleId']
            ?? $payload['vehicle']['id']
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

        $successful = [];
        $unsuccessful = [];

        foreach ($signals as $signal) {
            if (!is_array($signal)) {
                continue;
            }

            $signalCode = (string)($signal['code'] ?? '');
            if ($signalCode === '') {
                continue;
            }

            $meta = is_array($signal['meta'] ?? null) ? $signal['meta'] : [];
            $body = is_array($signal['body'] ?? null) ? $signal['body'] : [];
            $status = is_array($signal['status'] ?? null) ? $signal['status'] : null;

            $statusValue = strtoupper((string)($status['value'] ?? ''));
            $verified = $status === null
                || $statusValue === ''
                || $statusValue === 'SUCCESS'
                || $statusValue === 'OK';

            if ($verified && $this->HasMeaningfulSignalData($body)) {
                $successful[$signalCode] = $body;
            } else {
                $error = is_array($status['error'] ?? null) ? $status['error'] : [];
                $unsuccessful[$signalCode] = [
                    'status' => $statusValue !== '' ? $statusValue : 'NO_DATA',
                    'code' => (string)($error['code'] ?? ''),
                    'detail' => (string)($error['detail'] ?? '')
                ];
            }

            if ($verified && $this->HasMeaningfulSignalData($body)) {
                $this->RememberSuccessfulSignal($signalCode, $body, $status, $meta);
            }

            $this->ApplySignalFromV3(
                $signalCode,
                $body,
                $status,
                $this->GetSignalDefinition($signalCode, $body),
                $meta,
                false
            );
        }

        $this->TouchLastSignalsAt();

        $this->SendDebug(
            'WebhookVehicle/Erfolgreiche Signale',
            json_encode($successful, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

        $this->SendDebug(
            'WebhookVehicle/Nicht erfolgreiche Signale',
            json_encode($unsuccessful, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

    }


    private function HasMeaningfulSignalData(array $body): bool
    {
        foreach ($body as $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                if ($this->HasMeaningfulSignalData($value)) {
                    return true;
                }
                continue;
            }

            // false, 0 und "0" sind gültige Nutzwerte.
            if (is_bool($value) || is_int($value) || is_float($value)) {
                return true;
            }

            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function ApplySignalFromV3(
        string $code,
        array $body,
        ?array $status,
        array $definitionMeta,
        array $signalMeta = [],
        bool $logStatus = true
    ): bool
    {
        $statusValue = strtoupper((string)($status['value'] ?? ''));
        $verified = $status === null || $statusValue === '' || $statusValue === 'SUCCESS' || $statusValue === 'OK';

        $definition = $this->GetSignalDefinition($code, $body);
        $variables = $this->GetVariablesFromDefinition($definition, $body);

        if (!$verified) {
            // Ein Fehler darf keine neue Variable erzeugen. Bereits vorhandene,
            // noch vom Modul benannte Variablen werden lediglich markiert.
            $this->SetExistingSignalVerificationState($code, $variables, false);
            if ($logStatus) {
                $this->SendDebug(
                    'SignalStatus/' . $code,
                    json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    0
                );
            }
            return false;
        }

        if (!$this->HasMeaningfulSignalData($body)) {
            if ($logStatus) {
                $this->SendDebug(
                    'SignalData/' . $code,
                    'Erfolgreicher Status, aber keine nutzbaren Daten im body. Keine Variable angelegt.',
                    0
                );
            }
            return false;
        }

        // Nur Untervariablen berücksichtigen, für die Smartcar tatsächlich
        // einen Wert geliefert hat. Dadurch entstehen keine leeren Platzhalter.
        $variablesWithData = [];
        foreach ($variables as $variable) {
            $source = (string)($variable['source'] ?? 'value');
            if (array_key_exists($source, $body)) {
                $variablesWithData[] = $variable;
            }
        }

        // Nur vom Benutzer aktivierte Variablen anlegen/aktualisieren.
        $variablesWithData = array_values(array_filter(
            $variablesWithData,
            fn(array $variable): bool => $this->IsVariableEnabled((string)($variable['ident'] ?? ''))
        ));

        if (!empty($variablesWithData)) {
            $this->EnsureSignalVariables($code, $variablesWithData, true);
        }

        $changed = false;
        $signalBasePosition = $this->GetSignalBasePosition($code);

        foreach ($variablesWithData as $variableIndex => $variable) {
            $ident = (string)($variable['ident'] ?? '');
            if ($ident === '') {
                continue;
            }

            $source = (string)($variable['source'] ?? 'value');
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

        // Unbekanntes Signal mit echtem Inhalt: den kompletten body als JSON
        // anlegen. Auch hier nur, wenn tatsächlich Daten gekommen sind.
        if (empty($variablesWithData) && !empty($body)) {
            $ident = (string)($definition['ident'] ?? '');
            if ($ident !== '' && $this->IsVariableEnabled($ident)) {
                $value = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                $this->RegisterOrUpdateTypedVariable(
                    $ident,
                    (string)($definition['name'] ?? $code),
                    $value,
                    VARIABLETYPE_STRING,
                    '',
                    false,
                    $signalBasePosition
                );
                $this->UpdateModuleManagedVariableName(
                    $ident,
                    (string)($definition['name'] ?? $code),
                    (string)($definition['name'] ?? $code)
                );
                $changed = true;
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

            if ($this->ReadPropertyBoolean('ShowOEMUpdatedAtVariables') && $this->IsSignalEnabled($code, $body)) {
                $oemIdent = $this->BuildOEMTimestampIdent($code);
                $oemName = (string)($definitionMeta['name'] ?? $definition['name'] ?? $code) . ' – OEM-Datenstand';

                $this->RegisterOrUpdateTypedVariable(
                    $oemIdent,
                    $oemName,
                    $oemTimestamp,
                    VARIABLETYPE_INTEGER,
                    '~UnixTimestamp',
                    false,
                    $signalBasePosition + max(1, count($variablesWithData))
                );

                $oemId = @$this->GetIDForIdent($oemIdent);
                if ($oemId) {
                    IPS_SetHidden($oemId, false);
                }
            }
        }

        return $changed;
    }

    private function TouchLastSignalsAt(): void
    {
        $this->EnsureLastSignalsAtVariable();

        if (@$this->GetIDForIdent('LastSignalsAt')) {
            $this->SetValue('LastSignalsAt', time());
        }
    }

    private function ApplyOEMTimestampVisibility(): void
    {
        $show = $this->ReadPropertyBoolean('ShowOEMUpdatedAtVariables');

        if (!$show) {
            foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
                $object = @IPS_GetObject($childId);
                if (!is_array($object)) {
                    continue;
                }

                $ident = (string)($object['ObjectIdent'] ?? '');
                if (str_ends_with($ident, '_OEMUpdatedAt') && (($object['ObjectType'] ?? -1) === 2)) {
                    $this->UnregisterVariable($ident);
                }
            }
            return;
        }

        // Beim Einschalten sofort aus den zuletzt empfangenen OEM-Zeitstempeln
        // wiederherstellen. Dafür ist keine neue API-/Webhook-Abfrage nötig.
        $stored = json_decode($this->ReadAttributeString('LastOEMSignalTimes'), true);
        if (!is_array($stored)) {
            return;
        }

        foreach ($stored as $code => $timestamp) {
            $code = (string)$code;
            $timestamp = (int)$timestamp;

            if ($code === '' || $timestamp <= 0) {
                continue;
            }

            $definition = $this->GetSignalDefinition($code);
            $oemIdent = $this->BuildOEMTimestampIdent($code);
            $oemName = (string)($definition['name'] ?? $code) . ' – OEM-Datenstand';

            $this->RegisterOrUpdateTypedVariable(
                $oemIdent,
                $oemName,
                $timestamp,
                VARIABLETYPE_INTEGER,
                '~UnixTimestamp',
                false,
                $this->GetSignalBasePosition($code) + 99
            );

            $oemId = @$this->GetIDForIdent($oemIdent);
            if ($oemId) {
                IPS_SetHidden($oemId, false);
            }
        }
    }

    private function SetExistingSignalVerificationState(string $signalCode, array $variables, bool $verified): void
    {
        foreach ($variables as $variable) {
            $ident = (string)($variable['ident'] ?? '');
            if ($ident === '' || !@$this->GetIDForIdent($ident)) {
                continue;
            }

            $baseName = (string)($variable['name'] ?? $ident);
            $moduleName = $verified ? $baseName : $baseName;
            $this->UpdateModuleManagedVariableName($ident, $baseName, $moduleName);
        }
    }


    private function GetFixedSignalPosition(string $signalCode): int
    {
        $signalCode = strtolower(trim($signalCode));

        // Feste Position pro Signal. Die Position hängt nicht davon ab,
        // wann oder in welcher Reihenfolge ein Signal aktiviert wurde.
        $positions = [
            'tractionbattery-stateofcharge' => 100,
            'tractionbattery-range' => 200,
            'tractionbattery-nominalcapacity' => 300,
            'tractionbattery-chargecompletiontime' => 400,
            'tractionbattery-maxrangechargecounter' => 500,
            'tractionbattery-nominalcapacities' => 600,
            'charge-detailedchargingstatus' => 700,
            'charge-ischarging' => 800,
            'charge-ischargingcableconnected' => 900,
            'charge-chargelimits' => 1000,
            'charge-amperage' => 1100,
            'charge-maximumamperage' => 1200,
            'charge-amperagerequested' => 1300,
            'charge-chargerate' => 1400,
            'charge-voltage' => 1500,
            'charge-power' => 1600,
            'charge-energyadded' => 1700,
            'charge-timetocomplete' => 1800,
            'charge-fastchargertype' => 1900,
            'charge-isfastchargerpresent' => 2000,
            'charge-chargingconnectortype' => 2100,
            'charge-chargerphases' => 2200,
            'charge-chargetimers' => 2300,
            'charge-chargerecords' => 2400,
            'charge-ischargingcablelatched' => 2500,
            'charge-ischargingportflapopen' => 2600,
            'closure-chargeportstatuscolor' => 2700,
            'location-preciselocation' => 2800,
            'odometer-traveleddistance' => 2900,
            'closure-islocked' => 3000,
            'closure-doors' => 3100,
            'closure-windows' => 3200,
            'closure-sunroof' => 3300,
            'closure-enginecover' => 3400,
            'closure-fronttrunk' => 3500,
            'closure-reartrunk' => 3600,
            'closure-tailgate' => 3700,
            'connectivitystatus-isonline' => 3800,
            'connectivitystatus-isasleep' => 3900,
            'connectivitystatus-isdigitalkeypaired' => 4000,
            'connectivitysoftware-currentfirmwareversion' => 4100,
            'internalcombustionengine-fuellevel' => 4200,
            'internalcombustionengine-oillife' => 4300,
            'internalcombustionengine-oilpressure' => 4400,
            'internalcombustionengine-oiltemperature' => 4500,
            'internalcombustionengine-waterinfuel' => 4600,
            'tractionbattery-isheateractive' => 4700,
            'climate-externaltemperature' => 4800,
            'climate-internaltemperature' => 4900,
            'wheel-tires' => 5000,
            'wheel-style' => 5100,
            'vehicleidentification-vin' => 5200,
            'vehicleidentification-trim' => 5300,
            'vehicleidentification-exteriorcolor' => 5400,
            'vehicleidentification-packages' => 5500,
            'vehicleidentification-nickname' => 5600,
            'vehicleidentification-make' => 5700,
            'vehicleidentification-model' => 5800,
            'vehicleidentification-year' => 5900,
            'vehicleuseraccount-permissions' => 6000,
            'vehicleuseraccount-role' => 6100,
            'lowvoltagebattery-stateofcharge' => 6200,
            'internalcombustionengine-amountremaining' => 6300,
            'lowvoltagebattery-status' => 6400,
            'internalcombustionengine-range' => 6500,
            'transmission-gearstate' => 6600,
            'transmission-drivemode' => 6700,
            'surveillance-isenabled' => 6800,
            'surveillance-brand' => 6900,
            'diagnostics-dtccount' => 7000,
            'diagnostics-dtclist' => 7100,
            'diagnostics-abs' => 7200,
            'diagnostics-activesafety' => 7300,
            'diagnostics-airbag' => 7400,
            'diagnostics-brakefluid' => 7500,
            'diagnostics-driverassistance' => 7600,
            'diagnostics-emissions' => 7700,
            'diagnostics-engine' => 7800,
            'diagnostics-evbatteryconditioning' => 7900,
            'diagnostics-evcharging' => 8000,
            'diagnostics-evdriveunit' => 8100,
            'diagnostics-evhvbattery' => 8200,
            'diagnostics-lighting' => 8300,
            'diagnostics-mil' => 8400,
            'diagnostics-telematics' => 8500,
            'diagnostics-tirepressure' => 8600,
            'diagnostics-tirepressuremonitoring' => 8700,
            'diagnostics-transmission' => 8800,
            'diagnostics-washerfluid' => 8900,
            'hvac-cabintargettemperature' => 9000,
            'hvac-iscabinhvacactive' => 9100,
            'hvac-isfrontdefrosteractive' => 9200,
            'hvac-isreardefrosteractive' => 9300,
            'hvac-issteeringheateractive' => 9400,
            'motion-currentspeed' => 9500,
            'service-isinservice' => 9600,
            'service-records' => 9700,
        ];

        if (isset($positions[$signalCode])) {
            return $positions[$signalCode];
        }

        // Fallback für künftig unbekannte Signale:
        // stabil aus dem Signalcode abgeleitet und außerhalb des festen Bereichs.
        return 20000 + ((int)sprintf('%u', crc32($signalCode)) % 2000) * 10;
    }

    private function BuildSelectedSignalPositionMap(array $selected): array
    {
        $positions = [];

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

            $positions[$signalCode] = $this->GetFixedSignalPosition($signalCode);
        }

        return $positions;
    }

    private function GetSignalBasePosition(string $signalCode): int
    {
        return $this->GetFixedSignalPosition($signalCode);
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
        $this->SendDebug('Connect/Start', 'Vehicle Access synchronisieren gestartet (Phase 1: Connect).', 0);

        if (!$this->HasParentConnection()) {
            return 'Fehler: Kein Smartcar Splitter verbunden.';
        }

        $vehicleId = trim($this->ReadPropertyString('VehicleID'));

        if ($vehicleId === '') {
            return 'Fehler: VehicleID fehlt.';
        }

        $state = 'vehicle_access_sync_' . $vehicleId . '_' . bin2hex(random_bytes(8));

        $request = [
            'DataID'  => '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}',
            'Command' => 'BuildConnectURL',
            'Mode'    => 'live',
            'State'   => $state
        ];

        $this->SendDebug(
            'Connect/RequestToSplitter',
            json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

        $result = $this->SendDataToParent(
            json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $this->SendDebug('Connect/SplitterResponse', (string)$result, 0);

        $decoded = json_decode((string)$result, true);

        if (!is_array($decoded) || empty($decoded['success'])) {
            return 'Fehler beim Erzeugen der Connect URL: ' . (string)$result;
        }

        return (string)($decoded['url'] ?? '');
    }

    private function EnsureSignalVariables(string $signalCode, array $variables, bool $verified): void
    {
        $basePosition = $this->GetSignalBasePosition($signalCode);
        foreach ($variables as $index => $variable) {
            $ident = (string)($variable['ident'] ?? '');
            if ($ident === '') {
                continue;
            }
            $baseName = (string)($variable['name'] ?? $ident);
            $moduleName = $verified ? $baseName : $baseName;
            $this->RegisterOrUpdateTypedVariable(
                $ident,
                $moduleName,
                $this->GetDefaultValueForType((int)($variable['type'] ?? VARIABLETYPE_STRING)),
                (int)($variable['type'] ?? VARIABLETYPE_STRING),
                (string)($variable['profile'] ?? ''),
                true,
                $basePosition + (int)$index
            );
            $this->UpdateModuleManagedVariableName($ident, $baseName, $moduleName);
        }
    }

    private function UpdateModuleManagedVariableName(string $ident, string $baseName, string $newModuleName): void
    {
        $id = @$this->GetIDForIdent($ident);
        if (!$id) {
            return;
        }
        $names = json_decode($this->ReadAttributeString('ModuleVariableNames'), true);
        if (!is_array($names)) {
            $names = [];
        }
        $currentName = (string)(IPS_GetObject($id)['ObjectName'] ?? '');
        $previousModuleName = (string)($names[$ident] ?? '');

        // Bestehende Installationen ohne Tracking nur übernehmen, wenn der Name noch
        // eindeutig dem Modulstandard entspricht. Benutzerdefinierte Namen bleiben tabu.
        if ($previousModuleName === '') {
            if ($currentName !== $baseName && $currentName !== $baseName && $currentName !== $newModuleName) {
                return;
            }
            $previousModuleName = $currentName;
        }

        if ($currentName === $previousModuleName && $currentName !== $newModuleName) {
            IPS_SetName($id, $newModuleName);
        }
        $names[$ident] = $newModuleName;
        $this->WriteAttributeString('ModuleVariableNames', json_encode($names, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function GetVariablesFromDefinition(array $definition, array $body): array
    {
        if (($definition['special'] ?? '') === 'multiple') {
            return $definition['variables'] ?? [];
        }
        return [[
            'ident' => $definition['ident'] ?? '',
            'name' => $definition['name'] ?? '',
            'type' => $definition['type'] ?? VARIABLETYPE_STRING,
            'profile' => $definition['profile'] ?? '',
            'source' => $definition['source'] ?? 'value',
            'convert' => $definition['convert'] ?? null
        ]];
    }

    private function GetDefaultValueForType(int $type): mixed
    {
        return match ($type) {
            VARIABLETYPE_BOOLEAN => false,
            VARIABLETYPE_INTEGER => 0,
            VARIABLETYPE_FLOAT => 0.0,
            default => ''
        };
    }



    private function RememberExistingSignalVariables(): void
    {
        $discovered = $this->ReadDiscoveredVariables();
        $changed = false;

        $commandIdents = [
            'CommandChargeStart',
            'CommandChargeStop',
            'CommandChargeLimit',
            'CommandSecurityLock',
            'CommandSecurityUnlock',
            'CommandNavigationDestination'
        ];

        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            if (!IPS_VariableExists($childId)) {
                continue;
            }

            $object = @IPS_GetObject($childId);
            if (!is_array($object)) {
                continue;
            }

            $ident = trim((string)($object['ObjectIdent'] ?? ''));
            if ($ident === ''
                || $ident === 'LastSignalsAt'
                || in_array($ident, $commandIdents, true)
                || str_ends_with($ident, '_OEMUpdatedAt')) {
                continue;
            }

            // Bereits bekannte Einträge nicht überschreiben; insbesondere bleiben
            // der echte Smartcar-Signalcode und der Modul-Standardname erhalten.
            if (isset($discovered[$ident]) && is_array($discovered[$ident])) {
                continue;
            }

            $discovered[$ident] = [
                'ident' => $ident,
                'name' => (string)($object['ObjectName'] ?? $ident),
                'kind' => 'Signal',
                'source' => 'Bestehende Variable'
            ];
            $changed = true;
        }

        if ($changed) {
            $this->WriteDiscoveredVariables($discovered);
        }
    }

    private function RefreshVariableSelectionForm(): void
    {
        try {
            $this->UpdateFormField(
                'VariableSelection',
                'values',
                json_encode($this->BuildVariableSelectionRows(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        } catch (Throwable $e) {
            // Das Konfigurationsformular ist nicht zwingend geöffnet.
        }
    }

    private function ReadDiscoveredVariables(): array
    {
        $data = json_decode($this->ReadAttributeString('DiscoveredVariables'), true);
        return is_array($data) ? $data : [];
    }

    private function WriteDiscoveredVariables(array $data): void
    {
        ksort($data);
        $this->WriteAttributeString('DiscoveredVariables', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function GetVariableSelectionMap(): array
    {
        $rows = json_decode($this->ReadPropertyString('VariableSelection'), true);
        if (!is_array($rows)) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $ident = trim((string)($row['Ident'] ?? $row['ident'] ?? ''));
            if ($ident === '') {
                continue;
            }

            $map[$ident] = (bool)($row['Active'] ?? $row['active'] ?? true);
        }

        return $map;
    }

    private function IsVariableEnabled(string $ident): bool
    {
        if ($ident === '') {
            return false;
        }
        $selection = $this->GetVariableSelectionMap();
        return !array_key_exists($ident, $selection) || $selection[$ident];
    }

    private function BuildVariableSelectionRows(): array
    {
        $selection = $this->GetVariableSelectionMap();
        $discovered = $this->ReadDiscoveredVariables();
        $rows = [];

        uasort($discovered, static function (array $a, array $b): int {
            $kindCompare = strcmp((string)($a['kind'] ?? ''), (string)($b['kind'] ?? ''));
            return $kindCompare !== 0 ? $kindCompare : strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });

        foreach ($discovered as $ident => $entry) {
            $rows[] = [
                'Active' => $selection[$ident] ?? true,
                'Name' => (string)($entry['name'] ?? $ident),
                'Kind' => (string)($entry['kind'] ?? 'Signal'),
                'Source' => (string)($entry['source'] ?? ''),
                'Ident' => $ident
            ];
        }
        return $rows;
    }

    private function RememberSuccessfulSignal(string $code, array $body, ?array $status, array $meta): void
    {
        $definition = $this->GetSignalDefinition($code, $body);
        $variables = $this->GetVariablesFromDefinition($definition, $body);
        $discovered = $this->ReadDiscoveredVariables();
        $found = false;

        foreach ($variables as $variable) {
            $source = (string)($variable['source'] ?? 'value');
            $ident = (string)($variable['ident'] ?? '');
            if ($ident === '' || !array_key_exists($source, $body)) {
                continue;
            }
            $found = true;
            $discovered[$ident] = [
                'ident' => $ident,
                'name' => (string)($variable['name'] ?? $ident),
                'kind' => 'Signal',
                'source' => $code . ($source !== 'value' ? ' / ' . $source : ''),
                'signalCode' => $code
            ];
        }

        if (!$found) {
            $ident = (string)($definition['ident'] ?? '');
            if ($ident !== '') {
                $discovered[$ident] = [
                    'ident' => $ident,
                    'name' => (string)($definition['name'] ?? $code),
                    'kind' => 'Signal',
                    'source' => $code,
                    'signalCode' => $code
                ];
            }
        }
        $this->WriteDiscoveredVariables($discovered);

        $cache = json_decode($this->ReadAttributeString('LastSuccessfulSignalPayloads'), true);
        if (!is_array($cache)) {
            $cache = [];
        }
        $cache[$code] = ['body' => $body, 'status' => $status, 'meta' => $meta];
        $this->WriteAttributeString('LastSuccessfulSignalPayloads', json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function ApplyVariableSelection(): void
    {
        foreach ($this->GetVariableSelectionMap() as $ident => $enabled) {
            if ($enabled) {
                continue;
            }
            $id = @$this->GetIDForIdent($ident);
            if ($id && IPS_VariableExists($id)) {
                $this->UnregisterVariable($ident);
            }
        }
    }

    private function RestoreSelectedSignalsFromCache(): void
    {
        $cache = json_decode($this->ReadAttributeString('LastSuccessfulSignalPayloads'), true);
        if (!is_array($cache)) {
            return;
        }
        foreach ($cache as $code => $payload) {
            if (!is_array($payload)) {
                continue;
            }
            $body = is_array($payload['body'] ?? null) ? $payload['body'] : [];
            if (!$this->HasMeaningfulSignalData($body)) {
                continue;
            }
            $status = is_array($payload['status'] ?? null) ? $payload['status'] : null;
            $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
            $this->ApplySignalFromV3((string)$code, $body, $status, $this->GetSignalDefinition((string)$code, $body), $meta, false);
        }
    }

    private function IsSignalEnabled(string $code, array $body): bool
    {
        $definition = $this->GetSignalDefinition($code, $body);
        foreach ($this->GetVariablesFromDefinition($definition, $body) as $variable) {
            $source = (string)($variable['source'] ?? 'value');
            $ident = (string)($variable['ident'] ?? '');
            if ($ident !== '' && array_key_exists($source, $body) && $this->IsVariableEnabled($ident)) {
                return true;
            }
        }
        $ident = (string)($definition['ident'] ?? '');
        return $ident !== '' && $this->IsVariableEnabled($ident);
    }

    private function ApplyVehicleAccessCommands(): void
    {
        $permissions = json_decode($this->ReadPropertyString('Permissions'), true);
        if (!is_array($permissions)) {
            $permissions = [];
        }
        $permissions = array_values(array_unique(array_filter(
            array_map(static fn($permission): string => strtolower(trim((string)$permission)), $permissions),
            static fn(string $permission): bool => $permission !== ''
        )));

        $this->SendDebug('VehicleAccess/Permissions', json_encode($permissions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
        $definitions = $this->GetAvailableCommandDefinitions($permissions);
        $this->RememberAvailableCommands($definitions);

        foreach (['CommandChargeStart','CommandChargeStop','CommandChargeLimit','CommandSecurityLock','CommandSecurityUnlock','CommandNavigationDestination'] as $ident) {
            $definition = $definitions[$ident] ?? null;
            $existingId = @$this->GetIDForIdent($ident);

            if ($definition === null || !$this->IsVariableEnabled($ident)) {
                if ($existingId && IPS_VariableExists($existingId)) {
                    $this->UnregisterVariable($ident);
                }
                continue;
            }

            if (!$existingId) {
                $this->RegisterOrUpdateTypedVariable(
                    $ident,
                    (string)$definition['name'],
                    $definition['type'] === VARIABLETYPE_STRING ? '' : 0,
                    (int)$definition['type'],
                    (string)$definition['profile'],
                    true,
                    (int)$definition['position']
                );
                $existingId = @$this->GetIDForIdent($ident);
            }
            if ($existingId && IPS_VariableExists($existingId)) {
                // Steuerungen gehören immer ans Ende des Objektbaums. Auch bereits
                // vorhandene Variablen aus älteren Zwischenversionen zurücksetzen.
                IPS_SetPosition($existingId, (int)$definition['position']);
                $this->EnableAction($ident);
            }
        }
    }

    private function GetAvailableCommandDefinitions(array $permissions): array
    {
        $definitions = [];
        if (in_array('control_charge', $permissions, true)) {
            $definitions['CommandChargeStart'] = ['name'=>'Laden starten','type'=>VARIABLETYPE_BOOLEAN,'profile'=>'~Switch','position'=>50000,'source'=>'control_charge'];
            $definitions['CommandChargeStop'] = ['name'=>'Laden stoppen','type'=>VARIABLETYPE_BOOLEAN,'profile'=>'~Switch','position'=>50010,'source'=>'control_charge'];
            $definitions['CommandChargeLimit'] = ['name'=>'Ladelimit','type'=>VARIABLETYPE_INTEGER,'profile'=>'~Intensity.100','position'=>50020,'source'=>'control_charge'];
        }
        if (in_array('control_security', $permissions, true)) {
            $definitions['CommandSecurityLock'] = ['name'=>'Fahrzeug verriegeln','type'=>VARIABLETYPE_BOOLEAN,'profile'=>'~Switch','position'=>50030,'source'=>'control_security'];
            $definitions['CommandSecurityUnlock'] = ['name'=>'Fahrzeug entriegeln','type'=>VARIABLETYPE_BOOLEAN,'profile'=>'~Switch','position'=>50040,'source'=>'control_security'];
        }
        if (in_array('control_navigation', $permissions, true)) {
            $definitions['CommandNavigationDestination'] = ['name'=>'Ziel setzen','type'=>VARIABLETYPE_STRING,'profile'=>'','position'=>50050,'source'=>'control_navigation'];
        }
        return $definitions;
    }

    private function RememberAvailableCommands(array $definitions): void
    {
        $discovered = $this->ReadDiscoveredVariables();
        foreach ($discovered as $ident => $entry) {
            if (($entry['kind'] ?? '') === 'Steuerung' && !isset($definitions[$ident])) {
                unset($discovered[$ident]);
            }
        }
        foreach ($definitions as $ident => $definition) {
            $discovered[$ident] = ['ident'=>$ident,'name'=>(string)$definition['name'],'kind'=>'Steuerung','source'=>(string)$definition['source']];
        }
        $this->WriteDiscoveredVariables($discovered);
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
        $ident = $this->BuildSignalIdent($code);
        $type = VARIABLETYPE_STRING;
        $profile = '';

        // Wurde ein unbekanntes Signal bereits ohne Nutzdaten als String angelegt,
        // bleibt sein Typ stabil. Dadurch können spätere echte Werte nicht an einem
        // Typwechsel scheitern. Benutzerarchive bleiben ebenfalls erhalten.
        $existingId = @$this->GetIDForIdent($ident);
        if ($existingId) {
            $existingVariable = IPS_GetVariable($existingId);
            $type = (int)($existingVariable['VariableType'] ?? VARIABLETYPE_STRING);
            $profile = (string)($existingVariable['VariableCustomProfile'] ?? '');
            if ($profile === '') {
                $profile = (string)($existingVariable['VariableProfile'] ?? '');
            }
        } elseif (array_key_exists('value', $body)) {
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
            'ident'   => $ident,
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

        $this->ApplyVehicleAccessCommands();

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