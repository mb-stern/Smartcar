<?php

class Smartcar extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        //Webhook registrieren
        $this->RegisterHook('smartcar_' . $this->InstanceID);

        // Allgemeine Eigenschaften
        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('Mode', 'live');

        // Optional: Manuelle Redirect-URI (überschreibt Connect+Hook)
        $this->RegisterPropertyString('ManualRedirectURI', '');

        // Webhook-Optionen
        $this->RegisterPropertyBoolean('EnableWebhook', true);
        $this->RegisterPropertyBoolean('VerifyWebhookSignature', true);
        $this->RegisterPropertyBoolean('TrackLastSignals', false);
        
        // Smartcar "application_management_token" für HMAC (SC-Signature) & VERIFY-Challenge
        $this->RegisterPropertyString('ManagementToken', '');

        // Scopes für API-Endpunkte
        $this->RegisterPropertyBoolean('ScopeReadVehicleInfo', false);
        $this->RegisterPropertyBoolean('ScopeReadLocation', false);
        $this->RegisterPropertyBoolean('ScopeReadOdometer', false);
        $this->RegisterPropertyBoolean('ScopeReadTires', false);
        $this->RegisterPropertyBoolean('ScopeReadBattery', false);
        $this->RegisterPropertyBoolean('ScopeReadBatteryCapacity', false);
        $this->RegisterPropertyBoolean('ScopeReadFuel', false);
        $this->RegisterPropertyBoolean('ScopeReadSecurity', false);
        $this->RegisterPropertyBoolean('ScopeReadChargeLimit', false);
        $this->RegisterPropertyBoolean('ScopeReadChargeStatus', false);
        $this->RegisterPropertyBoolean('ScopeReadVIN', false);
        $this->RegisterPropertyBoolean('ScopeReadOilLife', false);

        // Vorhandene Ansteuerungen (POST-Endpunkte)
        $this->RegisterPropertyBoolean('SetChargeLimit', false);
        $this->RegisterPropertyBoolean('SetChargeStatus', false);
        $this->RegisterPropertyBoolean('SetLockStatus', false);

        // Attribute für interne Nutzung
        $this->RegisterAttributeString('CurrentHook', '');
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeString('VehicleID', '');
        $this->RegisterAttributeString('PendingHttpRetry', '');
        $this->RegisterAttributeString('RedirectURI', '');

        // Timer
        $this->RegisterTimer('TokenRefreshTimer', 0, 'SMCAR_RefreshAccessToken(' . $this->InstanceID . ');');
        $this->RegisterTimer('HttpRetryTimer', 0, 'SMCAR_HandleHttpRetry($_IPS["TARGET"]);');

        // Kernel-Runlevel
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        /// Hook-Adresse (wird zu /hook/<adresse>)
        $hookAddress = 'smartcar_' . $this->InstanceID;
        $hookPath    = '/hook/' . $hookAddress;

        $this->SendDebug('ApplyChanges', "Hook-Pfad aktiv: $hookPath", 0);

        // Redirect-URI
        $manual = trim($this->ReadPropertyString('ManualRedirectURI'));
        if ($manual !== '') {
            $effectiveRedirect = $manual;
        } else {
            $effectiveRedirect = $this->BuildConnectURL($hookPath);
        }
        $this->WriteAttributeString('RedirectURI', $effectiveRedirect);

        $this->CreateProfile();

        if ($this->ReadPropertyBoolean('TrackLastSignals')) {
            if (!@$this->GetIDForIdent('LastSignalsAt')) {
                $this->RegisterVariableInteger('LastSignalsAt', 'Letzte Fahrzeug-Signale', '~UnixTimestamp', -1);
            }
        } else {
            @$this->UnregisterVariable('LastSignalsAt');
        }

        $this->UpdateVariablesBasedOnScopes();

        // Nach Update/ApplyChanges: Tokenrefresh neu anstoßen (egal wie alt), wenn RefreshToken vorhanden
        if ($this->ReadAttributeString('RefreshToken') !== '') {
            $this->SendDebug('Token', 'ApplyChanges -> starte RefreshAccessToken', 0);
            $this->RefreshAccessToken();

            // Timer danach wieder aktivieren (z.B. alle 55 Minuten)
            $this->SetTimerInterval('TokenRefreshTimer', 55 * 60 * 1000);
        } else {
            $this->SetTimerInterval('TokenRefreshTimer', 0);
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            $raw = $Value;
            
            case 'SetChargeLimit':
                // Nur 50–100 % erlauben
                $Value = (float)$Value;
                if ($Value < 50) {
                    $Value = 50;
                } elseif ($Value > 100) {
                    $Value = 100;
                }

                $this->SendDebug('SetChargeLimit/RequestAction', "Raw=$raw, stored=$Value, api=" . ($Value/100.0), 0);

                // Smartcar erwartet 0.5–1.0
                $this->SetChargeLimit($Value / 100.0);
                $this->SetValue($Ident, $Value);
                break;

            case 'SetChargeStatus':
                $bool = (bool)$Value;
                $this->SetChargeStatus($bool);
                $this->SetValue($Ident, $bool);
                break;

            case 'SetLockStatus':
                $bool = (bool)$Value;
                $this->SetLockStatus($bool);
                $this->SetValue($Ident, $bool);
                break;

            default:
                throw new Exception('Invalid ident');
        }
    }

    private function BuildConnectURL(string $hookPath): string
    {
        // Sicherstellen, dass der Pfad wie /hook/... beginnt
        if ($hookPath === '' || strpos($hookPath, '/hook/') !== 0) {
            $hookPath = '/hook/' . ltrim($hookPath, '/');
        }

        // IP-Symcon Connect-Instanz suchen
        $connectAddress = '';
        $ids = @IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}');
        if (!empty($ids)) {
            // CC_GetUrl/CC_GetURL – je nach Version unterschiedlich geschrieben
            if (function_exists('CC_GetUrl')) {
                $connectAddress = @CC_GetUrl($ids[0]);
            } elseif (function_exists('CC_GetURL')) {
                $connectAddress = @CC_GetURL($ids[0]);
            }
        }

        if (is_string($connectAddress) && $connectAddress !== '') {
            return rtrim($connectAddress, '/') . $hookPath;
        }
        return '';
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message !== IPS_KERNELMESSAGE) {
            return;
        }

        $runlevel = $Data[0] ?? -1;
        $this->SendDebug('Kernel', 'IPS_KERNELMESSAGE runlevel=' . $runlevel, 0);

        // Symcon ist vollständig gestartet
        if ($runlevel === KR_READY) {

            // Prüfen ob ein RefreshToken vorhanden ist
            if ($this->ReadAttributeString('RefreshToken') !== '') {

                $this->SendDebug('Token', 'KR_READY -> starte RefreshAccessToken', 0);

                // Token nach Neustart immer neu anfordern (egal wie alt)
                $this->RefreshAccessToken();

                // Timer danach wieder aktivieren (z.B. alle 55 Minuten)
                $this->SetTimerInterval('TokenRefreshTimer', 55 * 60 * 1000);

                $this->SendDebug('Token', 'TokenRefreshTimer neu gesetzt (55 Minuten)', 0);

            } else {

                // Kein RefreshToken → Timer aus
                $this->SetTimerInterval('TokenRefreshTimer', 0);
                $this->SendDebug('Token', 'KR_READY -> kein RefreshToken vorhanden', 0);
            }
        }
    }

    public function GetConfigurationForm(): string
    {
    $effectiveRedirect = $this->ReadAttributeString('RedirectURI');

    $form = [
        'elements' => [
            ['type' => 'Label', 'caption' => 'Redirect-/Callback-URI: ' . $effectiveRedirect],
            [
                'type' => 'ValidationTextBox',
                'name' => 'ManualRedirectURI',
                'caption' => 'Redirect-/Callback-URI überschreiben'
            ],
            ['type' => 'Label', 'caption' => '────────────────────────────────────────'],
            ['type' => 'CheckBox', 'name' => 'EnableWebhook', 'caption' => 'Webhook-Empfang für Signale aktivieren'],
            ['type' => 'CheckBox', 'name' => 'VerifyWebhookSignature', 'caption' => 'Fahrzeug verifizieren (Fahrzeugfilter!)'],
            ['type' => 'CheckBox', 'name' => 'TrackLastSignals', 'caption' => 'Variable für letzte Aktualisierung der Signale'],

            [
                'type'    => 'ValidationTextBox',
                'name'    => 'ManagementToken',
                'caption' => 'Application Management Token (HMAC & VERIFY)'
            ],
            ['type' => 'Label', 'caption' => '────────────────────────────────────────'],

            ['type' => 'ValidationTextBox', 'name' => 'ClientID',     'caption' => 'Client ID'],
            ['type' => 'ValidationTextBox', 'name' => 'ClientSecret', 'caption' => 'Client Secret'],
            [
                'type'    => 'Select',
                'name'    => 'Mode',
                'caption' => 'Verbindungsmodus',
                'options' => [
                    ['caption' => 'Simuliert', 'value' => 'simulated'],
                    ['caption' => 'Live',      'value' => 'live']
                ]
            ],
            [
                'type'    => 'ExpansionPanel',
                'caption' => 'Berechtigungen (Scopes)',
                'items'   => [
                    ['type' => 'Label', 'caption' => 'Zugehörige Variablen werden automatisch erstellt bzw. gelöscht.'],
                    ['type' => 'Label', 'caption' => 'Nach der Auswahl der Scopes ist über den Button (*mit Smartcar verbinden*) die Berchtigung zu setzen .'],

                    // READ (immer sichtbar)
                    ['type'=>'CheckBox','name'=>'ScopeReadVehicleInfo',     'caption'=>'Fahrzeuginformationen lesen (/)'],
                    ['type'=>'CheckBox','name'=>'ScopeReadVIN',             'caption'=>'VIN lesen (/vin)'],
                    ['type'=>'CheckBox','name'=>'ScopeReadLocation',        'caption'=>'Standort lesen (/location)'],
                    ['type'=>'CheckBox','name'=>'ScopeReadTires',           'caption'=>'Reifendruck lesen (/tires/pressure)'],
                    ['type'=>'CheckBox','name'=>'ScopeReadOdometer',        'caption'=>'Kilometerstand lesen (/odometer)'],
                    ['type'=>'CheckBox','name'=>'ScopeReadBattery',         'caption'=>'Batterielevel lesen (/battery)'],
                    ['type'=>'CheckBox','name'=>'ScopeReadBatteryCapacity', 'caption'=>'Batteriekapazität lesen (/battery/nominal_capacity)'],
                    ['type'=>'CheckBox','name'=>'ScopeReadFuel',            'caption'=>'Kraftstoffstand lesen (/fuel)'],
                    ['type'=>'CheckBox','name'=>'ScopeReadSecurity',        'caption'=>'Verriegelungsstatus lesen (/security)'],
                    ['type'=>'CheckBox','name'=>'ScopeReadChargeLimit',     'caption'=>'Ladelimit lesen (/charge/limit)'],
                    ['type'=>'CheckBox','name'=>'ScopeReadChargeStatus',    'caption'=>'Ladestatus lesen (/charge)'],
                    ['type'=>'CheckBox','name'=>'ScopeReadOilLife',         'caption'=>'Motoröl lesen (/engine/oil)'],

                    // CONTROL
                    ['type'=>'CheckBox','name'=>'SetChargeLimit',  'caption'=>'Ladelimit setzen (/charge/limit)'],
                    ['type'=>'CheckBox','name'=>'SetChargeStatus', 'caption'=>'Ladestatus setzen (/charge)'],
                    ['type'=>'CheckBox','name'=>'SetLockStatus',   'caption'=>'Zentralverriegelung setzen (/security)']
                ]
            ],
        ],
        'actions' => [
            ['type' => 'Button', 'caption' => 'Mit Smartcar verbinden', 'onClick' => 'echo SMCAR_GenerateAuthURL($id);'],
            ['type' => 'Button', 'caption' => 'Fahrzeugdaten abrufen', 'onClick' => 'SMCAR_FetchVehicleData($id);'],
            ['type' => 'Label',  'caption' => 'Sag danke und unterstütze den Modulentwickler:'],
            [
                'type'  => 'RowLayout',
                'items' => [
                     [
                        'type'   => 'Image',
                        'onClick'=> "echo 'https://paypal.me/mbstern';",
                        "image"=> "data:image/jpeg;base64,/9j/4QAYRXhpZgAASUkqAAgAAAAAAAAAAAAAAP/sABFEdWNreQABAAQAAAA8AAD/7gAOQWRvYmUAZMAAAAAB/9sAhAAGBAQEBQQGBQUGCQYFBgkLCAYGCAsMCgoLCgoMEAwMDAwMDBAMDg8QDw4MExMUFBMTHBsbGxwfHx8fHx8fHx8fAQcHBw0MDRgQEBgaFREVGh8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx//wAARCABLAGQDAREAAhEBAxEB/8QAqwABAAICAwEBAAAAAAAAAAAAAAUGAgcDBAgJAQEBAAIDAQAAAAAAAAAAAAAAAAMEAgUGARAAAQMCAwMEDwMICwAAAAAAAgEDBAAFERIGIRMHMdEUFkFRcSKyk6PDJFSEFTZGZmEyCIGxQlKSIzODkaFigmOz00QlVRgRAAICAQIDBQYFBQAAAAAAAAABAgMREgQhMQVBUWEiE/BxgaGxBpHRQhQVwfEyUiP/2gAMAwEAAhEDEQA/AN+WWywr/CS63VDfkPmeUc5CICJKKCKCqbNlAd/qNpr1YvGHz0A6jaa9WLxh89AOo2mvVi8YfPQDqNpr1YvGHz0A6jaa9WLxh89AOo2mvVi8YfPQDqNpr1YvGHz0A6jaa9WLxh89AOo2mvVi8YfPQDqNpr1YvGHz0A6jaa9WLxh89ARnuVr3/wC4t+97o3PSui51+9jly5vvZezhQEnob4ajd1zw1oCeoBQCgFAeZtWfik1ZbtT3W3W22284MKU7GYceR4nCFk1DMSi4KbVHHYldDT0eEoJtvLRrrN7JSaSIr/1nr3/q7Z+y/wD6tS/wtXfL5GH76Xci4aC/FPFul1j2zVFtC3dKMWmrhGMiZEyXAd6B98Iqv6WZcOzVTc9HcYuUHnHYTVb1N4Zv6tIXhQCgFAV/569g85QGWhvhqN3XPDWgJ6gFAKA4LhLbhwJMxxcG4zRvGq9psVJfzVlGOWkeN4WT53SZJyZD0lxcTfMnTVe2aqS/nru0sLBz74s6XSj7SVD6rJfTR+g+6ZIAjiRKgiiY44rsSitZ44JcT6E6Nv8ADvunok2Kpd6KNPgf3wdbREISw/prkd3t5U2OMjZbHeQ3FanHkTdVi2KAUBX/AJ69g85QGWhvhqN3XPDWgJ6gFAKAp/F+6LbOGOpZaLlLoLrIL/afTcp/W5VrYw1XRXiRXvEGeElElHKAqRLsERTFVVewiJXZS5GjTXNmAWi7GSCEJ9SXYibo+aq2h9xk9zUuco/ii26T0VKalt3C6AjaMrmYjLgpKachHhyYdqrNVLzlmj6l1aMouuvjnm/yPWPBCG8zpJ19xFQZUozax7IiIhin94VrnOuTTuS7om5+2q3Hbtv9UvyRsKtMdEKAUBX/AJ69g85QGWhvhqN3XPDWgJ6gFAKA1F+KK59E4XnGQsCuE2Oxh2xFVeX/ACq2nSIZuz3JlTeSxA8waGY3l9RzDYy0Z4/auAp4VdZHmct1aeKH4tI2xpzTl11Fcfd9uESfQCdJXCyigjgiqq7eyqVjudzCmOqXI5/Z7Ke4nohz5l8snAu6HIA7zMaZjIuJtRlI3CTtZiQRHu7a1F/XYJeRNvxOg232xNyzbJKPhzNwwYMWBDZhxG0ajRwRtpseRBHYlc3ZNzk5Pi2djVXGuKjFYijnrAzFAKAr/wA9ewecoDLQ3w1G7rnhrQE9QCgFAUzidwvtnEC3QoNwmyITcJ5XwWPkXMRAod8hiXIi7Kt7TduhtpJ5IbqVNYZp7UfBCFodyO7ZnZ10dnIYPKbYkLYtqKphuhTaSr2e1XRdO6h6revTHByv3BtmowjBOXF9hduB1knx7hc50qM6wKNAw0roEGZSJSLDMicmVKq9cvjKMYpp8cnv2ztpxnOUk1wxx9vA29XOHXigFAKAUBX/AJ69g85QGWhvhqN3XPDWgNAyeKvFSdB1ZqS36lhQbTY5xsQ7e+wwrj4K4qADSqKqSoOXl5a6JbOhOEHFuUlz4mud02m0+CNl2HjvpKPpawytX3Fm3Xy5xQffiNg4eVCVUF0hBD3YuCmdM3YWtfZ06bnJVrMUyxHcR0rVzJ5njHw3eisTG7yBRJMz3czI3TyNlJyiWTMoYJ3pouK7KgexuTxp44z8CRXw7yQvOvdM2y7rYXZo+/SiuS24IiZkjbYEeYyEVEEwBfvKlY1bWc0pY8ucGN16hFvtSbNadfNfsabjaiO7xXAefVkbcTTe8JBVcSwFEXL3tdB+w27tdWh8Fzyzj/5TdxpVznHjLGnCybGd4kaSiOtxbhPCPOyCUhlEM0aNRRVAiEVRFTkwrSrpt0lmMcx+p0b6xt4NRnLEscefDwIy6a2emah0tGsEpCgXQ3XJJ7vabTRYKnfpmH7h7anq2SjXY7F5o4x737IrX9Sc7qY0vyTznh2L3+5lh1pqVrTGlLpf3W98NuYJ4WVLLnNNgBmwXDMSonJWv29XqTUe83Vk9MWzWjf4jrYPDTrZJgC3dHJbkGNZhexzutoJqSuKCKgI2aES5fs7NbB9Kl62hPy4zkr/ALtaNXaWuBxb04xpOy3vVD7Vll3ljpLFuQjkO5FxUVEQDeEmXBVXLhVaWym5yjDzKPaSq9KKcuGS02DUNk1Da2rrZZjc63vYo2+3jhiK4EioqIqKi8qKlVrKpQlpksMkjJSWUdD569g85UZkcGmSlDolSiBvZQtSFjtoqIpOIpZBxXBExKsoYys8jx8jWHCf8PVhTTrczXdl3uoCkOuE068RCLeKICELR7tccFL8tbje9TlrxVLy4KdO1WPMuJxM6R4h6Y1/q2XbNJRb/Evyf8ZOdeZaajMoK5WVA9uVBwBQRExypguFeu+qyqCc3Fx5rvGicZPCzkgLzojqx+G9+FqdBtt8W5dOhMKQkayVcRsGx3akmJMivIuxO5U1e49Td5hxjpx8P7kcq9NWHweS5aI4d6kj6KvmpLuBzteapj/vd4oi40w5gIspjlQVyd8SdwexUM93X68IrhVBkW5oslt54WbJL6lt0hwv0/CtsCVcbeJXoAE3ycMjQXeX7mZW1y9yot51SyUpKMvJ/T6kHT+iUwhGU4/9O33/AEKzE01re3WO+WIbA1MdnOOGt2J1vExPBO9QlzKX6Q4qmC1fnuaJ2Qs1uOn9OGauGz3VdVlXpqTlnzZXt7iW01o++QdR2WTIiKMS0Wnd5s4LjKczEYIiLjji6u3kqtut5XKqaT805/L2Rc2XT7YX1uS8sK/D/J5z9SF11B4q604XJa5tjbg3i43NtqVEYdBRagNkh70yJxUVVIU2Cv5Kh28qKrtSlmKj8zdWKc4YxxyQnEfgA63EusvS7DlxuF7ksNNxl3bbUCNsKQYKRJmU1aBFXlw2VNtepZaU+CivxfYYW7b/AF7Tk1fw51fbeIQXq2QblcbMlsj26CdlnNQpUbo4CCtkryLi2WVS2duvKN1XKrS3FS1NvUspns6ZKWVnGOw2bwp0m3pjR0eAkJ23OvOuypEJ+QMtxs3S5CeAQElyiOOCcta7eXepZnOfhgsUw0xwd/569g85VUlMtDfDUb7Ccx/bWgJ6gFAdO42a0XJWVuMJiYsY95H6Q0Du7P8AWDOi5V+1KzjZKPJ4PHFPmdysD0UAoBQCgFAKAUBX8U69YY7egcn8ygIeLj0iZuen/wAc83unDo2P879L9bLsoDs+k/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAiv3fvf/db/P8A4nvT+H4nd0B//9k="
                    ],
                    ['type' => 'Label', 'caption' => '']
                ]
            ]
        ]
    ];

    return json_encode($form);
    }

    private function HandleRetriableHttp(string $kind, array $jobFields, int $statusCode, array $headers, ?int $attempt = null): bool
    {
        return false;
    }

    private function getEnabledScopes(): array
    {
        $maps = $this->GetScopeMaps();

        $scopes = [];
        foreach ($maps['PROP_TO_SCOPE'] as $prop => $scope) {
            if ($this->ReadPropertyBoolean($prop)) {
                $scopes[$scope] = true; // Set wie Map
            }
        }
        return array_keys($scopes);
    }

/** Liefert NUR aktivierte Read-Scopes (ohne control_*). */
    private function getEnabledReadScopes(): array
    {
        return array_values(array_filter(
            $this->getEnabledScopes(),
            fn($s) => str_starts_with($s, 'read_')
        ));
    }

    /** Liefert alle API-Paths, die aus den aktivierten Read-Checkboxen resultieren (für Batch). */
    private function getEnabledReadPaths(): array
    {
        $maps = $this->GetScopeMaps();

        $paths = [];
        foreach ($maps['PROP_TO_PATH'] as $prop => $path) {
            if ($this->ReadPropertyBoolean($prop)) {
                $paths[$this->canonicalizePath($path)] = true;
            }
        }
        return array_keys($paths);
    }

private function canonicalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') return '/';
        $path = '/' . ltrim($path, '/');
        return rtrim($path, '/');
    }

    private function ScheduleHttpRetry(array $job, int $delaySeconds): void
    {
        // Versuchszähler robust erhöhen
        $job['attempt'] = (int)($job['attempt'] ?? 0) + 1;

        if ($job['attempt'] > 3) { // max attempts inline
            $this->SendDebug('Retry', 'Abgebrochen (max attempts erreicht).', 0);
            // Erst jetzt als Error ins Log
            $this->LogMessage('HTTP 429: Max. Wiederholversuche erreicht für '.json_encode($job, JSON_UNESCAPED_SLASHES), KL_ERROR);
            return;
        }

        // optionale Obergrenze, z. B. 1h
        $maxCap = 3600;
        if ($maxCap > 0) {
            $delaySeconds = min($delaySeconds, $maxCap);
        }

        // kleiner Jitter, keine 60s-Kappung
        $delaySeconds = max(1, (int)round($delaySeconds) + random_int(0, 2));

        $job['dueAt'] = time() + $delaySeconds;

        $this->WriteAttributeString('PendingHttpRetry', json_encode($job, JSON_UNESCAPED_SLASHES));
        $this->SetTimerInterval('HttpRetryTimer', $delaySeconds * 1000);

        $this->SendDebug('Retry', sprintf(
            'Job geplant: kind=%s in %ds (Attempt %d)',
            $job['kind'] ?? '?', $delaySeconds, $job['attempt']
        ), 0);
    }

    private function ParseRetryAfter(?string $h): ?int 
    {
        if ($h === null || $h === '') return null;
        if (ctype_digit($h)) return (int)$h;
        $ts = strtotime($h);
        return $ts !== false ? max(1, $ts - time()) : null;
    }

    public function HandleHttpRetry(): void
    {
        $raw = $this->ReadAttributeString('PendingHttpRetry');
        if ($raw === '') { // Nichts zu tun
            $this->SetTimerInterval('HttpRetryTimer', 0);
            return;
        }

        $job = json_decode($raw, true) ?: [];
        $due = (int)($job['dueAt'] ?? 0);
        $now = time();

        // Noch nicht fällig → Timer nachstellen und raus
        if ($due > $now) {
            $this->SetTimerInterval('HttpRetryTimer', max(1, $due - $now) * 1000);
            return;
        }

        // Fällig: Timer stoppen & Job leeren
        $this->SetTimerInterval('HttpRetryTimer', 0);
        $this->WriteAttributeString('PendingHttpRetry', '');

        $attempt = (int)($job['attempt'] ?? 0);

        switch ($job['kind'] ?? '') {
            case 'batch':
                $this->FetchVehicleData();
                break;

            case 'single':
                $path = (string)($job['path'] ?? '/');
                $this->FetchSingleEndpoint($path);
                break;

            case 'getVehicleID':
                // Triggert nur das Caching; Aufrufer liest später aus Attribut
                $token = $this->ReadAttributeString('AccessToken');
                $this->GetVehicleID($token);
                break;

            case 'command':
                switch ($job['action'] ?? '') {
                    case 'SetChargeLimit':
                        $this->SetChargeLimit((float)($job['limit'] ?? 0.8));
                        break;
                    case 'SetChargeStatus':
                        $this->SetChargeStatus((bool)($job['status'] ?? false));
                        break;
                    case 'SetLockStatus':
                        $this->SetLockStatus((bool)($job['status'] ?? true));
                        break;
                }
                break;

            default:
                $this->SendDebug('Retry', 'Unbekannter Job-Typ – kein Retry.', 0);
                break;
        }
    }

    private function BuildAuthURLWithScopes(array $scopes, string $stateTag = ''): string
    {
        $clientID     = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $mode         = $this->ReadPropertyString('Mode');
        $redirectURI  = $this->ReadAttributeString('RedirectURI');

        if ($clientID === '' || $clientSecret === '' || $redirectURI === '') {
            return 'Fehler: Client ID / Client Secret / Redirect-URI fehlt!';
        }

        $state = ($stateTag !== '' ? $stateTag . '_' : '') . bin2hex(random_bytes(8));
        $authURL = "https://connect.smartcar.com/oauth/authorize?"
            . "response_type=code"
            . "&client_id=" . urlencode($clientID)
            . "&redirect_uri=" . urlencode($redirectURI)
            . "&scope=" . urlencode(implode(' ', array_unique($scopes)))
            . "&state=" . $state
            . "&mode=" . urlencode($mode);

        return $authURL;
    }

    public function GenerateAuthURL()
    {
        $scopes = $this->getEnabledScopes();
        if (empty($scopes)) return 'Fehler: Keine Scopes ausgewählt!';
        $url = $this->BuildAuthURLWithScopes($scopes, 'manual');
        $this->SendDebug('GenerateAuthURL', 'Auth-URL (ausgewählte Scopes): ' . $url, 0);
        return $url;
    }

    protected function ProcessHookData(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $_SERVER['REQUEST_URI']     ?? '';
        $qs     = $_SERVER['QUERY_STRING']    ?? '';

        $this->SendDebug('Webhook', "Request: method=$method uri=$uri qs=$qs", 0);

        // --- OAuth Redirect (GET ?code=...) ---
        if ($method === 'GET' && isset($_GET['code'])) {
            $authCode = $_GET['code'];
            $state    = $_GET['state'] ?? '';
            $this->SendDebug('Webhook', "OAuth Redirect: code=<hidden> state=$state", 0);

            $this->RequestAccessToken($authCode);
            $ok = ($this->ReadAttributeString('AccessToken') !== '' && $this->ReadAttributeString('RefreshToken') !== '');

            if (!$ok) {
                http_response_code(500);
                echo 'Token-Austausch fehlgeschlagen – bitte Debug ansehen.';
                return;
            }

            // ✅ Keine Probe/Compat-Logik mehr – immer Erfolg melden
            echo 'Fahrzeug erfolgreich verbunden!';
            return;
        }

        // --- Webhook deaktiviert? ---
        if (!$this->ReadPropertyBoolean('EnableWebhook')) {
            $this->SendDebug('Webhook', 'Empfang deaktiviert → 200/ignored', 0);
            http_response_code(200);
            echo 'ignored';
            return;
        }

        // --- Nur POST für Webhooks ---
        if ($method !== 'POST') {
            $this->SendDebug('Webhook', "Nicht-POST → 200/OK", 0);
            http_response_code(200);
            echo 'OK';
            return;
        }

        // --- Headers / RAW debuggen ---
        $sigHeader = $this->getRequestHeader('SC-Signature') ?? $this->getRequestHeader('X-Smartcar-Signature') ?? '';
        $this->SendDebug('Webhook', 'Header SC-Signature: ' . ($sigHeader !== '' ? $sigHeader : '(leer)'), 0);

        $raw = file_get_contents('php://input') ?: '';

        $this->DebugJsonAntwort('Webhook', $raw, 200);

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $this->SendDebug('Webhook', '❌ Ungültiges JSON.', 0);
            http_response_code(400);
            echo 'Bad Request';
            return;
        }

        $verifyEnabled = $this->ReadPropertyBoolean('VerifyWebhookSignature');
        $mgmtToken     = trim($this->ReadPropertyString('ManagementToken'));

        // --- VERIFY-Challenge ---
        if (($payload['eventType'] ?? '') === 'VERIFY') {
            $challenge = $payload['data']['challenge'] ?? ($payload['challenge'] ?? '');
            if ($challenge === '') {
                $this->SendDebug('Webhook', '❌ VERIFY: challenge fehlt (erwartet data.challenge).', 0);
                http_response_code(400);
                echo 'Bad Request';
                return;
            }

            if (!$verifyEnabled) {
                $this->SendDebug('Webhook', "VERIFY (Testmodus): gebe plain challenge zurück: {$challenge}", 0);
                header('Content-Type: application/json');
                echo json_encode(['challenge' => $challenge]);
                return;
            }

            if ($mgmtToken === '') {
                $this->SendDebug('Webhook', '❌ VERIFY: VerifyWebhookSignature=true aber ManagementToken leer.', 0);
                http_response_code(401);
                echo 'Unauthorized';
                return;
            }

            $hmac = hash_hmac('sha256', $challenge, $mgmtToken);
            $this->SendDebug('Webhook', "✅ VERIFY HMAC gebildet: {$hmac}", 0);
            header('Content-Type: application/json');
            echo json_encode(['challenge' => $hmac]);
            return;
        }

        // --- Signatur prüfen (nur wenn aktiviert) ---
        if ($verifyEnabled) {
            if ($mgmtToken === '') {
                $this->SendDebug('Webhook', '❌ Signaturprüfung aktiv, aber ManagementToken fehlt.', 0);
                http_response_code(401);
                echo 'Unauthorized';
                return;
            }
            if ($sigHeader === '') {
                $this->SendDebug('Webhook', '❌ Signatur-Header fehlt.', 0);
                http_response_code(401);
                echo 'Unauthorized';
                return;
            }
            $calc = hash_hmac('sha256', $raw, $mgmtToken);
            if (!hash_equals($calc, trim($sigHeader))) {
                $this->SendDebug('Webhook', "❌ Signatur ungültig. expected=$calc received=$sigHeader", 0);
                http_response_code(401);
                echo 'Unauthorized';
                return;
            }
            $this->SendDebug('Webhook', '✅ Signatur verifiziert.', 0);
        } else {
            $this->SendDebug('Webhook', 'Signaturprüfung deaktiviert (Testmodus).', 0);
        }

        // --- Fahrzeug-Filter ---
        $incomingVehicle = $payload['data']['vehicle']['id'] ?? '';
        $boundVehicle    = $this->ReadAttributeString('VehicleID');
        $this->SendDebug('Webhook', "VehicleID inbound=$incomingVehicle bound=$boundVehicle", 0);

        if ($boundVehicle !== '' && $incomingVehicle !== '' && $incomingVehicle !== $boundVehicle) {
            $this->SendDebug('Webhook', "Event ignoriert: VehicleID passt nicht.", 0);
            http_response_code(200);
            echo 'ignored';
            return;
        }

        // --- Events ---
        $eventType = $payload['eventType'] ?? '';
        $this->SendDebug('Webhook', "eventType=$eventType", 0);

        switch ($eventType) {
            case 'VEHICLE_STATE':
                // Dein bestehender VEHICLE_STATE-Block bleibt unverändert
                // (hier nichts ändern)
                $signals = $payload['data']['signals'] ?? [];
                if (!is_array($signals)) $signals = [];

                $veh = $payload['data']['vehicle'] ?? [];
                $synthetic = [];
                if (is_array($veh)) {
                    if (array_key_exists('make', $veh))  { $synthetic[] = ['code' => 'vehicleidentification-make',  'body' => ['value' => (string)$veh['make']]]; }
                    if (array_key_exists('model', $veh)) { $synthetic[] = ['code' => 'vehicleidentification-model', 'body' => ['value' => (string)$veh['model']]]; }
                    if (array_key_exists('year', $veh))  { $synthetic[] = ['code' => 'vehicleidentification-year',  'body' => ['value' => (int)$veh['year']]]; }
                }
                if (!empty($synthetic)) {
                    $this->SendDebug('Webhook', 'Synthetische Signals: ' . json_encode($synthetic), 0);
                    $signals = array_merge($synthetic, $signals);
                }

                $created = [];
                $skipped = [
                    'COMPATIBILITY' => [],
                    'PERMISSION'    => [],
                    'UPSTREAM'      => [],
                    'STATUS_ONLY'   => [],
                    'OTHER'         => []
                ];

                // --- NEU: OEM-Zeitpunkte aus Webhook-Signalen (meta.oemUpdatedAt) sammeln ---
                $oemDateWebhook = [];

                foreach ($signals as $sig) {
                    $code = $sig['code'] ?? '';
                    if ($code === '') continue;

                    $meta = is_array($sig['meta'] ?? null) ? $sig['meta'] : [];

                    if (isset($meta['oemUpdatedAt']) && (int)$meta['oemUpdatedAt'] > 0) {
                        $ts = (int)((int)$meta['oemUpdatedAt'] / 1000); // ms -> s
                        $oemDateWebhook[$code] = date('Y-m-d H:i', $ts);
                    } else {
                        $oemDateWebhook[$code] = 'n/a';
                    }
                }

                // --- NEU: 1 Zeile Debug ---
                $this->SendDebug(
                    'OEM-Date(Webhook)',
                    json_encode($oemDateWebhook, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    0
                );

                foreach ($signals as $sig) {
                    $code   = $sig['code']   ?? '';
                    $body   = $sig['body']   ?? [];
                    $status = $sig['status'] ?? null;
                    if ($code === '') continue;

                    $this->ApplySignal(
                        $code,
                        is_array($body) ? $body : [],
                        $status,
                        $created,
                        $skipped
                    );
                }

                if (!empty($created)) {
                    $this->SendDebug('Signals/created', json_encode($created, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);
                }
                $skippedOut = array_filter($skipped, fn($arr) => !empty($arr));
                if (!empty($skippedOut)) {
                    $this->SendDebug('Signals/skipped', json_encode($skippedOut, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);
                }

                if ($this->ReadPropertyBoolean('TrackLastSignals')) {
                    if (@$this->GetIDForIdent('LastSignalsAt')) {
                        $this->SetValue('LastSignalsAt', time());
                    }
                }

                http_response_code(200);
                echo 'ok';
                return;

            case 'VEHICLE_ERROR':
                // ✅ NUR DEBUG, KEINE Variablen anlegen!
                $data = $payload['data'] ?? [];
                if (!is_array($data)) $data = [];

                $vehId = $data['vehicle']['id'] ?? ($data['vehicleId'] ?? '');
                $ts    = $payload['timestamp'] ?? ($data['timestamp'] ?? '');

                // Viele Smartcar-Fehler sind wie API-Errors strukturiert (type/code/description/requestId/resolution)
                $err = $data['error'] ?? ($data['errors'][0] ?? $data);
                if (!is_array($err)) $err = [];

                $type        = $err['type'] ?? '';
                $code        = $err['code'] ?? '';
                $desc        = $err['description'] ?? ($err['message'] ?? '');
                $requestId   = $err['requestId'] ?? ($err['requestID'] ?? '');
                $docURL      = $err['docURL'] ?? ($err['docUrl'] ?? '');
                $resolution  = $err['resolution'] ?? null;

                $this->SendDebug('VEHICLE_ERROR', 'Empfangen: ' . json_encode([
                    'timestamp' => $ts,
                    'vehicleId' => $vehId,
                    'type'      => $type,
                    'code'      => $code,
                    'description' => $desc,
                    'requestId' => $requestId,
                    'docURL'    => $docURL,
                    'resolution'=> $resolution
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);

                // Optional: kompletten data-Block einmal dumpen (hilft beim weiteren Mapping)
                $this->SendDebug('VEHICLE_ERROR/data', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);

                http_response_code(200);
                echo 'ok';
                return;

            default:
                $this->SendDebug('Webhook', "Unbekannter eventType: $eventType", 0);
                http_response_code(200);
                echo 'ok';
                return;
        }
    }

    private function GetStatusCodeFromHeaders(array $headers): int
    {
        foreach ($headers as $h) {
            if (preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $h, $m)) {
                return (int)$m[1];
            }
        }
        return 0;
    }

    private function GetRetryAfterFromHeaders(array $headers): ?string
    {
        foreach ($headers as $h) {
            if (stripos($h, 'Retry-After:') === 0) {
                return trim(substr($h, strlen('Retry-After:')));
            }
        }
        return null;
    }

    private function DebugHttpHeaders(array $headers, ?int $statusCode = null): void 
    {
        if (empty($headers)) {
            return;
        }

        $line = implode(' | ', array_map('trim', $headers));

        // Nur in der Debug-Konsole anzeigen
        $this->SendDebug(
            'HTTP-Headers' . ($statusCode ? " ($statusCode)" : ''),
            $line,
            0
        );
    }

    private function DebugJsonAntwort(string $context, $response, ?int $statusCode = null): void
    {
        $txt = ($response !== false && $response !== null && $response !== '') ? (string)$response : '';
        if ($txt === '') {
            $this->SendDebug($context, 'Antwort: (leer)', 0);
            return;
        }

        $decoded = json_decode($txt, true);
        if (is_array($decoded)) {
            $isError = ($statusCode !== null && $statusCode >= 400)
                || (isset($decoded['statusCode']) && is_numeric($decoded['statusCode']) && (int)$decoded['statusCode'] >= 400);

            $payload = $isError
                ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); // eine Zeile

            $this->SendDebug($context, 'Antwort: ' . $payload, 0);
            return;
        }

        $this->SendDebug($context, 'Antwort: ' . $txt, 0);
    }

    private function LogRateLimitIfAny(int $statusCode, array $headers): void 
    {
        if ($statusCode !== 429) return;
        $retryAfter = $this->GetRetryAfterFromHeaders($headers);
        $txt = 'RateLimit | 429 RATE_LIMIT – Erneuter Versuch in: ' .
            ($retryAfter !== null ? (ctype_digit($retryAfter) ? ($retryAfter . ' sec') : $retryAfter) : '(kein Header)');
        $this->SendDebug('RateLimit', $txt, 0);
    }


    private function getRequestHeader(string $name): ?string
    {
        // Robust alle Varianten: getallheaders & $_SERVER
        $target = strtolower($name);
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strtolower($k) === $target) return $v;
            }
        }
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) return $_SERVER[$key];
        return null;
    }

    private function RequestAccessToken(string $authCode): bool
    {
        $clientID     = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $redirectURI  = $this->ReadAttributeString('RedirectURI'); // manuell ODER Connect+Hook

        if ($clientID === '' || $clientSecret === '' || $redirectURI === '') {
            $this->SendDebug('RequestAccessToken', '❌ Fehlende Client-Daten oder Redirect-URI!', 0);
            return false;
        }

        $url = 'https://auth.smartcar.com/oauth/token';

        // Smartcar akzeptiert Client-Creds im Body (wie bisher) – bleibt so.
        $postData = http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $authCode,
            'redirect_uri'  => $redirectURI,
            'client_id'     => $clientID,
            'client_secret' => $clientSecret
        ]);

        $opts = [
            'http' => [
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'method'        => 'POST',
                'content'       => $postData,
                'ignore_errors' => true
            ]
        ];

        $ctx      = stream_context_create($opts);
        $response = @file_get_contents($url, false, $ctx);
        $data     = json_decode($response ?: '', true);

        if (isset($data['access_token'], $data['refresh_token'])) {
            $this->WriteAttributeString('AccessToken',  (string)$data['access_token']);
            $this->WriteAttributeString('RefreshToken', (string)$data['refresh_token']);

            // VehicleID leeren; neues Konto/Fahrzeug möglich
            $this->WriteAttributeString('VehicleID', '');


            // Token-Refresh-Timer setzen (expires_in abzüglich Sicherheits-Puffer)
            $expiresIn = isset($data['expires_in']) && is_numeric($data['expires_in']) ? (int)$data['expires_in'] : 0;
            $refreshIn = ($expiresIn > 0) ? max(60, $expiresIn - 300) : (55 * 60);
            $this->SetTimerInterval('TokenRefreshTimer', $refreshIn * 1000);
            // Maskiert loggen
            $mask = function(string $t): string {
                $l = strlen($t);
                return $l <= 10 ? '***' : substr($t, 0, 6) . '...' . substr($t, -4);
            };
            $this->SendDebug(
                'RequestAccessToken',
                '✅ Tokens gespeichert (acc=' . $mask($data['access_token']) . ', ref=' . $mask($data['refresh_token']) . ')',
                0
            );

            // Variablen/Timer etc. harmonisieren
            $this->ApplyChanges();
            return true;
        }

        // Fehlerfall transparent ausgeben
        $this->SendDebug('RequestAccessToken', '❌ Token-Austausch fehlgeschlagen. Antwort: ' . ($response ?: '(leer)'), 0);
        $this->LogMessage('RequestAccessToken - Token-Austausch fehlgeschlagen.', KL_ERROR);
        return false;
    }

    private function httpRequest(
    string $ctxName,
    string $method,
    string $url,
    string $accessToken,
    ?array $jsonBody,
    string $retryKind,
    array $retryFields
    ): ?array {

        $doRequest = function(string $token) use ($ctxName, $method, $url, $jsonBody): array {
            $body = ($jsonBody !== null) ? json_encode($jsonBody) : null;

            $options = [
                'http' => [
                    'header'        => "Authorization: Bearer $token\r\nContent-Type: application/json\r\n",
                    'method'        => strtoupper($method),
                    'ignore_errors' => true
                ]
            ];
            if ($body !== null) {
                $options['http']['content'] = $body;
            }

            $this->SendDebug($ctxName, "API-Anfrage: " . json_encode([
                'url'    => $url,
                'method' => $options['http']['method'],
                'header' => $options['http']['header'],
                'body'   => $body
            ], JSON_PRETTY_PRINT), 0);

            $context  = stream_context_create($options);
            $response = @file_get_contents($url, false, $context);

            // WICHTIG: file_get_contents kann false liefern -> wir behandeln das als "keine Antwort"
            if ($response === false) {
                return [
                    'response'   => false,
                    'headers'    => ($http_response_header ?? []),
                    'statusCode' => 0
                ];
            }

            $httpHeaders = $http_response_header ?? [];
            $statusCode  = $this->GetStatusCodeFromHeaders($httpHeaders);

            return [
                'response'   => $response,
                'headers'    => $httpHeaders,
                'statusCode' => $statusCode
            ];
        };

        // 1) Request mit aktuellem Token
        $r1 = $doRequest($accessToken);

        // Keine Antwort (Netz/HTTP stack)
        if ($r1['response'] === false) {
            $this->SendDebug($ctxName, '❌ Keine Antwort von der API!', 0);
            $this->LogMessage("$ctxName - Keine Antwort von der API!", KL_ERROR);
            return null;
        }

        $httpHeaders = $r1['headers'];
        $statusCode  = (int)$r1['statusCode'];
        $response    = (string)$r1['response'];

        $this->DebugJsonAntwort($ctxName, $response, $statusCode);
        $this->LogRateLimitIfAny($statusCode, $httpHeaders);
        if ($statusCode !== 200) {
            $this->DebugHttpHeaders($httpHeaders, $statusCode);
        }

        // 2) 🔥 NEU: Auto-Refresh bei 401 und Request 1x wiederholen
        if ($statusCode === 401) {
            $refreshToken = $this->ReadAttributeString('RefreshToken');

            if ($refreshToken !== '') {
                $this->SendDebug($ctxName, '🔄 401 Unauthorized → versuche AccessToken automatisch zu erneuern…', 0);

                $ok = $this->RefreshAccessToken(); // aktualisiert Attribute + Timer
                if ($ok) {
                    $newToken = $this->ReadAttributeString('AccessToken');

                    if ($newToken !== '' && $newToken !== $accessToken) {
                        $this->SendDebug($ctxName, '🔁 Wiederhole API-Request mit neuem AccessToken…', 0);

                        $r2 = $doRequest($newToken);

                        if ($r2['response'] === false) {
                            $this->SendDebug($ctxName, '❌ Keine Antwort nach Token-Refresh!', 0);
                            $this->LogMessage("$ctxName - Keine Antwort nach Token-Refresh!", KL_ERROR);
                            return null;
                        }

                        $httpHeaders = $r2['headers'];
                        $statusCode  = (int)$r2['statusCode'];
                        $response    = (string)$r2['response'];

                        $this->DebugJsonAntwort($ctxName, $response, $statusCode);
                        $this->LogRateLimitIfAny($statusCode, $httpHeaders);
                        if ($statusCode !== 200) {
                            $this->DebugHttpHeaders($httpHeaders, $statusCode);
                        }
                    } else {
                        $this->SendDebug($ctxName, '⚠️ Token-Refresh meldet OK, aber neuer Token fehlt/gleich geblieben.', 0);
                    }
                } else {
                    $this->SendDebug($ctxName, '❌ Auto-Refresh fehlgeschlagen → vermutlich neu verbinden nötig.', 0);
                }
            } else {
                $this->SendDebug($ctxName, '⚠️ 401, aber kein RefreshToken vorhanden → neu verbinden nötig.', 0);
            }
        }

        // 3) Zentraler Retry (429 + 5xx) – nicht blockierend
        if ($this->HandleRetriableHttp($retryKind, $retryFields, $statusCode, $httpHeaders)) {
            return [
                'retried'    => true,
                'statusCode' => $statusCode,
                'headers'    => $httpHeaders,
                'raw'        => $response,
                'data'       => null
            ];
        }

        $data = json_decode($response, true);

        return [
            'retried'    => false,
            'statusCode' => $statusCode,
            'headers'    => $httpHeaders,
            'raw'        => $response,
            'data'       => is_array($data) ? $data : null
        ];
    }

    public function RefreshAccessToken(): bool
    {
        $this->SendDebug('RefreshAccessToken', 'Token-Erneuerung gestartet!', 0);

        $clientID     = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $refreshToken = $this->ReadAttributeString('RefreshToken');

        if ($clientID === '' || $clientSecret === '' || $refreshToken === '') {
            $this->SendDebug('RefreshAccessToken', '❌ Fehlende Zugangsdaten!', 0);
            $this->LogMessage('RefreshAccessToken - Fehlende Zugangsdaten!', KL_ERROR);
            return false;
        }

        $url = 'https://auth.smartcar.com/oauth/token';
        $postData = http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken
        ]);

        $basic = base64_encode($clientID . ':' . $clientSecret);
        $options = [
            'http' => [
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n"
                                . "Accept: application/json\r\n"
                                . "Authorization: Basic {$basic}\r\n",
                'method'        => 'POST',
                'content'       => $postData,
                'ignore_errors' => true
            ]
        ];

        $context  = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        $data     = json_decode($response ?? '', true);

        // Erfolgsfall: mindestens access_token muss da sein
        if (is_array($data) && isset($data['access_token']) && is_string($data['access_token']) && $data['access_token'] !== '') {
            $oldAcc = $this->ReadAttributeString('AccessToken');

            $this->WriteAttributeString('AccessToken', (string)$data['access_token']);

            // refresh_token ist optional – wenn vorhanden, aktualisieren
            if (isset($data['refresh_token']) && is_string($data['refresh_token']) && $data['refresh_token'] !== '') {
                $this->WriteAttributeString('RefreshToken', (string)$data['refresh_token']);
            }

            // Token-Refresh-Timer setzen (expires_in abzüglich Sicherheits-Puffer)
            $expiresIn = isset($data['expires_in']) && is_numeric($data['expires_in']) ? (int)$data['expires_in'] : 0;
            $refreshIn = ($expiresIn > 0) ? max(60, $expiresIn - 300) : (55 * 60);
            $this->SetTimerInterval('TokenRefreshTimer', $refreshIn * 1000);

            $mask = fn($t) => (strlen((string)$t) <= 10) ? '***' : (substr((string)$t, 0, 6) . '...' . substr((string)$t, -4));
            $this->SendDebug(
                'RefreshAccessToken',
                '✅ Token erneuert (acc=' . $mask((string)$data['access_token'])
                . ', ref=' . (isset($data['refresh_token']) ? $mask((string)$data['refresh_token']) : '(unverändert)')
                . ', refreshIn=' . $refreshIn . 's'
                . ', changed=' . (($oldAcc !== (string)$data['access_token']) ? 'yes' : 'no') . ')',
                0
            );

            return true;
        }

        // Fehlerfall: Debug + optional in 5 Minuten nochmal versuchen (falls RefreshToken vorhanden)
        $this->SendDebug('RefreshAccessToken', '❌ Token-Erneuerung fehlgeschlagen! Antwort: ' . ($response ?: '(leer)'), 0);
        $this->LogMessage('RefreshAccessToken - fehlgeschlagen!', KL_ERROR);

        // sanfter Retry in 5 Minuten (nicht zu aggressiv)
        $this->SetTimerInterval('TokenRefreshTimer', 5 * 60 * 1000);
        return false;
    }

    public function FetchVehicleData()
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID   = $this->GetVehicleID($accessToken);

        if ($accessToken === '' || $vehicleID === null || $vehicleID === '') {
            $this->SendDebug('FetchVehicleData', '❌ Access Token oder Fahrzeug-ID fehlt!', 0);
            $this->LogMessage('FetchVehicleData - Access Token oder Fahrzeug-ID fehlt!', KL_ERROR);
            return false;
        }

        $paths = $this->getEnabledReadPaths();
        if (empty($paths)) {
            $this->SendDebug('FetchVehicleData', 'Keine Read-Scopes aktiviert!', 0);
            $this->LogMessage('FetchVehicleData - Keine Read-Scopes aktiviert!', KL_WARNING);
            return false;
        }

        $endpoints = array_map(fn($p) => ['path' => $p], $paths);
        $url       = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/batch";

        $res = $this->httpRequest(
            'FetchVehicleData',
            'POST',
            $url,
            $accessToken,
            ['requests' => $endpoints],
            'batch',
            []
        );

        if ($res === null) return false;
        if (!empty($res['retried'])) return false; // Timer übernimmt

        $statusCode = (int)$res['statusCode'];
        $data       = $res['data'] ?? [];

        if ($statusCode !== 200) {
            $fullMsg = $this->GetHttpErrorDetails($statusCode, is_array($data) ? $data : []);
            $this->SendDebug('FetchVehicleData', "❌ Fehler: $fullMsg", 0);
            $this->LogMessage("FetchVehicleData - $fullMsg", KL_ERROR);
            return false;
        }

        if (!isset($data['responses']) || !is_array($data['responses'])) {
            $this->SendDebug('FetchVehicleData', '❌ Unerwartete Antwortstruktur.', 0);
            $this->LogMessage('FetchVehicleData - Unerwartete Antwortstruktur', KL_ERROR);
            return false;
        }

        $hasError = false;

        $oemDateApi = [];

        foreach ($data['responses'] as $resp) {

            $path    = (string)($resp['path'] ?? '');
            $scCode  = (int)($resp['code'] ?? 0);
            $headers = is_array($resp['headers'] ?? null) ? $resp['headers'] : [];

            // --- NEU: OEM-Zeitpunkt (sc-data-age) als lokales Datum speichern ---
            $scDataAge = $headers['sc-data-age'] ?? $headers['SC-DATA-AGE'] ?? null;
            if ($scDataAge !== null && $scDataAge !== '') {
                $ts = strtotime((string)$scDataAge); // ISO-Z -> UTC -> Timestamp
                $oemDateApi[$path] = $ts ? date('Y-m-d H:i', $ts) : 'parse_err';
            } else {
                // kein Header (z.B. 429 oder Endpoint ohne Age)
                if (!isset($oemDateApi[$path])) $oemDateApi[$path] = 'n/a';
            }
            
            if ($scCode === 200 && isset($resp['body'])) {
                $this->ProcessResponse($path, (array)$resp['body']);
                continue;
            }

            // -----------------------------
            // 🔥 NEU: 429 Retry-After Debug
            // -----------------------------
            if ($scCode === 429) {
                // Smartcar liefert im Batch die Header als Map, z.B. {"Retry-After":252}
                $retryAfter = $headers['Retry-After'] ?? $headers['retry-after'] ?? null;

                if ($retryAfter !== null && $retryAfter !== '') {
                    $this->SendDebug('FetchVehicleData', "⏳ RATE_LIMIT für {$path} → Retry-After: {$retryAfter} Sekunden", 0);
                } else {
                    $this->SendDebug('FetchVehicleData', "⏳ RATE_LIMIT für {$path} → Retry-After: (nicht vorhanden)", 0);
                }
            }

            // bisheriges Fehler-Handling beibehalten
            $hasError = true;
            $fullMsg  = $this->GetHttpErrorDetails($scCode, (array)($resp['body'] ?? $resp));
            $this->SendDebug('FetchVehicleData', "⚠️ Teilfehler für {$path}: $fullMsg", 0);
            $this->LogMessage("FetchVehicleData - Teilfehler für {$path}: $fullMsg", KL_ERROR);
        }

        // --- NEU: 1 Zeile Debug mit OEM-Stand pro Path ---
        if (!empty($oemDateApi)) {
            $this->SendDebug('OEM-Date(API)', json_encode($oemDateApi, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
        }

        $this->SendDebug('FetchVehicleData', $hasError ? '⚠️ Teilweise erfolgreich.' : '✅ Alle Endpunkte erfolgreich.', 0);
        return true;
    }

    private function GetVehicleID(string $accessToken, int $retryCount = 0): ?string
    {
        $cached = $this->ReadAttributeString('VehicleID');
        if ($cached !== '') return $cached;

        if ($accessToken === '') {
            $this->SendDebug('GetVehicleID', '❌ AccessToken leer.', 0);
            return null;
        }

        $url = 'https://api.smartcar.com/v2.0/vehicles';

        $res = $this->httpRequest(
            'GetVehicleID',
            'GET',
            $url,
            $accessToken,
            null,
            'getVehicleID',
            []
        );

        if ($res === null) return null;
        if (!empty($res['retried'])) return null; // Timer übernimmt

        $statusCode = (int)$res['statusCode'];
        $data       = $res['data'] ?? [];

        if ($statusCode !== 200) {
            $msg = $this->GetHttpErrorDetails($statusCode, is_array($data) ? $data : []);
            $this->SendDebug('GetVehicleID', "❌ Fehler: $msg", 0);
            return null;
        }

        if (is_array($data) && isset($data['vehicles'][0])) {
            $vehicleId = (string)$data['vehicles'][0];
            $this->WriteAttributeString('VehicleID', $vehicleId);
            return $vehicleId;
        }

        $this->SendDebug('GetVehicleID', 'Keine Fahrzeug-ID gefunden! HTTP=' . $statusCode . ' Body=' . json_encode($data), 0);
        return null;
    }

    private function ProcessResponse(string $path, array $body)
    {
        switch ($path) {
            case '/':
                $this->SetValue('VehicleMake',  $body['make']  ?? '');
                $this->SetValue('VehicleModel', $body['model'] ?? '');
                $this->SetValue('VehicleYear',  $body['year']  ?? 0);
                break;

            case '/vin':
                $this->SetValue('VIN', $body['vin'] ?? '');
                break;

            case '/location':
                $this->SetValue('Latitude',  $body['latitude']  ?? 0.0);
                $this->SetValue('Longitude', $body['longitude'] ?? 0.0);
                break;

            case '/tires/pressure':
                $this->SetValue('TireFrontLeft',  ($body['frontLeft']  ?? 0) * 0.01);
                $this->SetValue('TireFrontRight', ($body['frontRight'] ?? 0) * 0.01);
                $this->SetValue('TireBackLeft',   ($body['backLeft']   ?? 0) * 0.01);
                $this->SetValue('TireBackRight',  ($body['backRight']  ?? 0) * 0.01);
                break;

            case '/odometer':
                $this->SetValue('Odometer', $body['distance'] ?? 0);
                break;

            case '/battery':
                $this->SetValue('BatteryRange', $body['range'] ?? 0);
                $this->SetValue('BatteryLevel', ($body['percentRemaining'] ?? 0) * 100);
                break;

            case '/battery/nominal_capacity':
                $nominal = null;

                if (isset($body['capacity']) && is_array($body['capacity']) && isset($body['capacity']['nominal']) && is_numeric($body['capacity']['nominal'])) {
                    $nominal = (float)$body['capacity']['nominal'];
                } elseif (isset($body['capacity']) && is_numeric($body['capacity'])) {
                    $nominal = (float)$body['capacity'];
                } elseif (isset($body['availableCapacities'][0]['capacity']) && is_numeric($body['availableCapacities'][0]['capacity'])) {
                    $nominal = (float)$body['availableCapacities'][0]['capacity'];
                } elseif (isset($body['nominal_capacity']) && is_numeric($body['nominal_capacity'])) {
                    $nominal = (float)$body['nominal_capacity'];
                }

                if ($nominal !== null) {
                    $this->SetValue('BatteryCapacity', $nominal);
                }
                break;

            case '/fuel':
                $this->SetValue('FuelLevel', ($body['percentRemaining'] ?? 0) * 100);
                $this->SetValue('FuelRange', $body['range'] ?? 0);
                break;

            case '/security':
                $this->SetValue('DoorsLocked', $body['isLocked'] ?? false);

                // Türen
                if (isset($body['doors']) && is_array($body['doors'])) {
                    foreach ($body['doors'] as $door) {
                        $type = $door['type'] ?? '';
                        $status = $door['status'] ?? 'UNKNOWN';
                        if ($type !== '') {
                            $ident = ucfirst($type) . 'Door';
                            $this->SetValue($ident, $status);
                        }
                    }
                }

                // Fenster
                if (isset($body['windows']) && is_array($body['windows'])) {
                    foreach ($body['windows'] as $window) {
                        $type = $window['type'] ?? '';
                        $status = $window['status'] ?? 'UNKNOWN';
                        if ($type !== '') {
                            $ident = ucfirst($type) . 'Window';
                            $this->SetValue($ident, $status);
                        }
                    }
                }

                // Optionale Felder mit Indexen sicher prüfen
                if (isset($body['sunroof'][0]['status'])) {
                    $this->SetValue('Sunroof', $body['sunroof'][0]['status']);
                } else {
                    $this->SetValue('Sunroof', 'UNKNOWN');
                }

                if (isset($body['storage'][0]['status'])) {
                    $this->SetValue('RearStorage', $body['storage'][0]['status']);
                } else {
                    $this->SetValue('RearStorage', 'UNKNOWN');
                }

                if (isset($body['storage'][1]['status'])) {
                    $this->SetValue('FrontStorage', $body['storage'][1]['status']);
                } else {
                    $this->SetValue('FrontStorage', 'UNKNOWN');
                }

                if (isset($body['chargingPort'][0]['status'])) {
                    $this->SetValue('ChargingPort', $body['chargingPort'][0]['status']);
                } else {
                    $this->SetValue('ChargingPort', 'UNKNOWN');
                }
                break;

            case '/charge/limit':
                $this->SetValue('ChargeLimit', ($body['limit'] ?? 0) * 100);
                break;

            case '/charge':
                $this->SetValue('ChargeStatus', $body['state'] ?? 'UNKNOWN');
                $this->SetValue('PluggedIn',    $body['isPluggedIn'] ?? false);
                break;

            case '/engine/oil':
                if (isset($body['lifeRemaining']) && is_numeric($body['lifeRemaining'])) {
                    $this->SetValue('OilLife', floatval($body['lifeRemaining']) * 100);
                } elseif (isset($body['remainingLifePercent']) && is_numeric($body['remainingLifePercent'])) {
                    $this->SetValue('OilLife', floatval($body['remainingLifePercent']));
                } elseif (isset($body['percentRemaining']) && is_numeric($body['percentRemaining'])) {
                    $this->SetValue('OilLife', floatval($body['percentRemaining']) * 100);
                } elseif (isset($body['value']) && is_numeric($body['value'])) {
                    $v = floatval($body['value']);
                    $this->SetValue('OilLife', ($v <= 1.0) ? $v * 100 : $v);
                }
                break;

            default:
                $this->SendDebug('ProcessResponse', "Unbekannter Scope: $path", 0);
        }
    }

    private function prettyName(string $ident): string {
        return preg_replace('/([a-z])([A-Z])/', '$1 $2', $ident);
    }

    private function setSafe(string $ident, int $varType, $value, string $profile = '', int $pos = 0, bool $createIfMissing = true): void
    {
        $id = @($this->GetIDForIdent($ident));
        if (!$id) {
            if (!$createIfMissing) return; // nichts anlegen, nur zuweisen wenn vorhanden
            switch ($varType) {
                case VARIABLETYPE_BOOLEAN: $this->RegisterVariableBoolean($ident, $this->prettyName($ident), $profile, $pos); break;
                case VARIABLETYPE_INTEGER: $this->RegisterVariableInteger($ident, $this->prettyName($ident), $profile, $pos); break;
                case VARIABLETYPE_FLOAT:   $this->RegisterVariableFloat($ident,   $this->prettyName($ident), $profile, $pos); break;
                case VARIABLETYPE_STRING:  $this->RegisterVariableString($ident,  $this->prettyName($ident), $profile, $pos); break;
            }
        }
        $this->SetValue($ident, $value);
    }
    
    private function ApplySignal(string $code, array $body, ?array $status, array &$created, array &$skipped): void
    {
        $mi2km = 1.609344;

        // erkennt "echten" Payload vs. reine Statusmeldungen
        $hasPayload = static function(array $b): bool {
            foreach ([
                'value','values','capacity','latitude','longitude','heading',
                'direction','locationType','limit','isOpen'
            ] as $k) {
                if (array_key_exists($k, $b)) return true;
            }
            return false;
        };

        // Status extrahieren (zum Gruppieren der Skips)
        $statusValue = null;  // "ERROR" | "UNAVAILABLE" | "OK" | ...
        $statusErr   = null;  // "COMPATIBILITY" | "PERMISSION" | "UPSTREAM" | ...
        if ($status !== null && is_array($status)) {
            $statusValue = $status['value']         ?? null;
            $statusErr   = $status['error']['type'] ?? null;
        }

        // Gruppierungsschlüssel für $skipped
        $group = function() use ($statusValue, $statusErr): string {
            $g = strtoupper((string)($statusErr ?: $statusValue ?: 'OTHER'));
            if (in_array($g, ['COMPATIBILITY','PERMISSION','UPSTREAM'], true)) return $g;
            if ($g === 'ERROR' || $g === '') return 'OTHER';
            return $g; // z.B. UNAVAILABLE, KNOWN_ISSUE, ...
        };

        // Variablen-Setter, der Neu-Anlagen in $created sammelt
        $setSafe = function (string $ident, int $type, string $caption, string $profile, $value) use (&$created) {
            $id = @$this->GetIDForIdent($ident);
            $wasCreated = false;
            if (!$id) {
                switch ($type) {
                    case VARIABLETYPE_BOOLEAN: $this->RegisterVariableBoolean($ident, $caption, $profile, 0); break;
                    case VARIABLETYPE_INTEGER: $this->RegisterVariableInteger($ident, $caption, $profile, 0); break;
                    case VARIABLETYPE_FLOAT:   $this->RegisterVariableFloat($ident,   $caption, $profile, 0); break;
                    default:                   $this->RegisterVariableString($ident,  $caption, $profile, 0); break;
                }
                $id = $this->GetIDForIdent($ident);
                $wasCreated = true;
            }
            $this->SetValue($ident, $value);

            if ($wasCreated) {
                if (is_object($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                if (is_array($value))  $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                $created[$ident] = $value;
            }
        };

        $asUpper = fn(?string $v): string => strtoupper(trim((string)$v));

        $asDiagIdent = function (string $diagCode): string {
            // "diagnostics-tirepressuremonitoring" -> "Diag_TirePressureMonitoring"
            $suffix = preg_replace('~^diagnostics-~i', '', $diagCode);
            $suffix = preg_replace('~[^A-Za-z0-9]+~', ' ', $suffix);
            $suffix = str_replace(' ', '', ucwords(strtolower($suffix)));
            return 'Diag_' . $suffix;
        };

        // Status-only → KEINE Variable, nur gesammelt fürs Debug
        if (!$hasPayload($body) && ($statusValue !== null || $statusErr !== null)) {
            $skipped[$group()][] = $code;
            return;
        }

        // ====== ab hier nur Pfade mit "echten" Daten ======
        switch (strtolower($code)) {
            // ---------- Batterie (HV/EV) ----------
            case 'tractionbattery-stateofcharge':
                if (isset($body['value'])) {
                    $setSafe('BatteryLevel', VARIABLETYPE_FLOAT, 'Batterieladestand (SOC)', 'SMCAR.Progress', floatval($body['value']));
                }
                break;

            case 'tractionbattery-range':
                if (isset($body['value'])) {
                    $val  = floatval($body['value']);
                    $unit = strtolower($body['unit'] ?? 'km');
                    $km   = ($unit === 'miles') ? $val * $mi2km : $val;
                    $setSafe('BatteryRange', VARIABLETYPE_FLOAT, 'Reichweite Batterie', 'SMCAR.Odometer', $km);
                }
                break;

            case 'tractionbattery-nominalcapacity':
                if (isset($body['capacity'])) {
                    $setSafe('BatteryCapacity', VARIABLETYPE_FLOAT, 'Batteriekapazität', '~Electricity', floatval($body['capacity']));
                }
                break;

            case 'tractionbattery-chargecompletiontime':
                if (isset($body['value']) && is_numeric($body['value'])) {

                    $decimal = (float)$body['value'];

                    $hours   = (int)floor($decimal);
                    $minutes = (int)round(($decimal - $hours) * 60);

                    if ($minutes >= 60) {
                        $minutes -= 60;
                        $hours++;
                    }
                    $hours = $hours % 24;

                    $timeStr = sprintf('%02d:%02d', $hours, $minutes);

                    $setSafe(
                        'ChargeEndTime',
                        VARIABLETYPE_STRING,
                        'Fertig geladen',
                        '',
                        $timeStr
                    );
                }
                break;
            
            case 'tractionbattery-maxrangechargecounter':
                if (isset($body['value'])) $setSafe('MaxRangeChargeCounter', VARIABLETYPE_FLOAT, 'Max-Range-Ladezyklen', '', floatval($body['value']));
                break;

            case 'tractionbattery-nominalcapacities':
                if (isset($body['values'])) $setSafe('BatteryNominalCapacities', VARIABLETYPE_STRING, 'Nominalkapazitäten', '', json_encode($body['values'], JSON_UNESCAPED_UNICODE));
                break;

            // ---------- Laden ----------
            case 'charge-detailedchargingstatus':
                if (isset($body['value'])) {
                    $setSafe('ChargeStatus', VARIABLETYPE_STRING, 'Ladestatus', 'SMCAR.Charge', $asUpper($body['value']));
                }
                break;

            case 'charge-ischarging':
                if (isset($body['value'])) {
                    $is = (bool)$body['value'];
                    $setSafe('IsCharging',  VARIABLETYPE_BOOLEAN, 'Lädt', '~Switch', $is);
                    $setSafe('ChargeStatus', VARIABLETYPE_STRING,  'Ladestatus', 'SMCAR.Charge', $is ? 'CHARGING' : 'NOT_CHARGING');
                }
                break;

            case 'charge-ischargingcableconnected':
                if (isset($body['value'])) {
                    $setSafe('PluggedIn', VARIABLETYPE_BOOLEAN, 'Ladekabel eingesteckt', '~Switch', (bool)$body['value']);
                }
                break;

            case 'charge-chargelimits':
                if (isset($body['values']) && is_array($body['values'])) {
                    foreach ($body['values'] as $cfg) {
                        if (($cfg['type'] ?? '') === 'global' && isset($cfg['limit'])) {
                            $setSafe('ChargeLimit', VARIABLETYPE_FLOAT, 'Aktuelles Ladelimit', 'SMCAR.Progress', floatval($cfg['limit']) * 100);
                            break;
                        }
                    }
                }
                break;

            case 'charge-amperage':
                if (isset($body['value'])) $setSafe('ChargeAmperage', VARIABLETYPE_FLOAT, 'Ladestrom (A)', '', floatval($body['value']));
                break;

            case 'charge-amperagemax':
            case 'charge-maximumamperage':
                if (isset($body['value'])) $setSafe('ChargeAmperageMax', VARIABLETYPE_FLOAT, 'Max. Ladestrom (A)', '', floatval($body['value']));
                break;

            case 'charge-amperagerequested':
                if (isset($body['value'])) $setSafe('ChargeAmperageRequested', VARIABLETYPE_FLOAT, 'Angeforderter Ladestrom (A)', '', floatval($body['value']));
                break;

            case 'charge-chargerate':
                // Einheit je nach OEM (km/h, mi/h, kW…). Wir speichern Rohwert.
                if (isset($body['value'])) $setSafe('ChargeRate', VARIABLETYPE_FLOAT, 'Laderate', '', floatval($body['value']));
                break;

            case 'charge-voltage':
                if (isset($body['value'])) $setSafe('ChargeVoltage', VARIABLETYPE_FLOAT, 'Ladespannung (V)', '', floatval($body['value']));
                break;

            case 'charge-wattage':
            case 'charge-power':
                if (isset($body['value'])) $setSafe('ChargeWattage', VARIABLETYPE_FLOAT, 'Ladeleistung', '~Power', floatval($body['value']));
                break;

            case 'charge-energyadded':
                // meist kWh – falls Unit beiliegt, kannst du optional prüfen
                if (isset($body['value'])) $setSafe('ChargeEnergyAdded', VARIABLETYPE_FLOAT, 'Energie hinzugefügt', '~Electricity', floatval($body['value']));
                break;

            case 'charge-timetocomplete':
                //Hier werden unterschiedliche Fälle der 'Fertig geladen Zeit' behandelt. Einige kommen als Dezimalzeit und andere als Minuten
                if (isset($body['value'])) {

                    $raw = (string)$body['value'];
                    $raw = str_replace(',', '.', $raw);

                    if (strpos($raw, '.') !== false) {
                        // Dezimal-Uhrzeit (Stunden seit Mitternacht), z.B. 22.1166 -> 22:07
                        $v = (float)$raw;
                        $h = (int)floor($v);
                        $m = (int)round(($v - $h) * 60);

                        if ($m >= 60) { $m -= 60; $h++; }
                        $h = $h % 24;

                        $timeStr = sprintf('%02d:%02d', $h, $m);
                    } else {
                        // Minuten seit Mitternacht, z.B. 1335 -> 22:15
                        $mins = (int)$raw;
                        $h = intdiv($mins, 60) % 24;
                        $m = $mins % 60;

                        $timeStr = sprintf('%02d:%02d', $h, $m);
                    }

                    $setSafe('ChargeTimeToComplete', VARIABLETYPE_STRING, 'Fertiggeladen', '', $timeStr . ' Uhr');
                }
                break;

            case 'charge-fastchargertype':
                if (isset($body['value'])) $setSafe('FastChargerType', VARIABLETYPE_STRING, 'Schnelllader-Typ', '', (string)$body['value']);
                break;

            case 'charge-isfastchargerpresent':
                if (isset($body['value'])) $setSafe('IsFastChargerPresent', VARIABLETYPE_BOOLEAN, 'Schnelllader erkannt', '~Switch', (bool)$body['value']);
                break;

            case 'charge-chargingconnectortype':
                if (isset($body['value'])) $setSafe('ChargingConnectorType', VARIABLETYPE_STRING, 'Steckertyp', '', (string)$body['value']);
                break;

            case 'charge-chargerphases':
                // je nach OEM: Zahl/Enum – Rohwert speichern
                if (isset($body['value'])) $setSafe('ChargerPhases', VARIABLETYPE_FLOAT, 'Phasen', '', floatval($body['value']));
                break;

            case 'charge-chargetimers':
                // typischerweise Liste → als JSON ablegen
                if (isset($body['values'])) $setSafe('ChargeTimers', VARIABLETYPE_STRING, 'Lade-Timer', '', json_encode($body['values'], JSON_UNESCAPED_UNICODE));
                break;

            case 'charge-records':
                if (isset($body['values'])) $setSafe('ChargeRecords', VARIABLETYPE_STRING, 'Lade-Records', '', json_encode($body['values'], JSON_UNESCAPED_UNICODE));
                break;

            case 'charge-ischargingcablelatched':
                if (isset($body['value'])) $setSafe('IsChargingCableLatched', VARIABLETYPE_BOOLEAN, 'Ladekabel verriegelt', '~Switch', (bool)$body['value']);
                break;

            case 'charge-ischargingportflapopen':
                if (array_key_exists('isOpen', $body)) {
                    $setSafe('ChargingPortFlap', VARIABLETYPE_STRING, 'Ladeport-Klappe', 'SMCAR.Status', $body['isOpen'] ? 'OPEN' : 'CLOSED');
                }
                break;     
                
            case 'charge-chargeportstatuscolor':
            case 'closure-chargeportstatuscolor':
                if (isset($body['value'])) $setSafe('ChargingPortStatusColor', VARIABLETYPE_STRING, 'Ladeport-Statusfarbe', '', (string)$body['value']);
                break;    

            // ---------- Standort ----------
            case 'location-preciselocation':
                if (isset($body['latitude']))     $setSafe('Latitude',     VARIABLETYPE_FLOAT,  'Breitengrad', '', floatval($body['latitude']));
                if (isset($body['longitude']))    $setSafe('Longitude',    VARIABLETYPE_FLOAT,  'Längengrad',  '', floatval($body['longitude']));
                if (isset($body['heading']))      $setSafe('Heading',      VARIABLETYPE_FLOAT,  'Fahrtrichtung (°)', '', floatval($body['heading']));
                if (isset($body['direction']))    $setSafe('Direction',    VARIABLETYPE_STRING, 'Himmelsrichtung',    '', $asUpper($body['direction']));
                if (isset($body['locationType'])) $setSafe('LocationType', VARIABLETYPE_STRING, 'Standort-Typ',        '', $asUpper($body['locationType']));
                break;

            // ---------- Kilometerstand ----------
            case 'odometer-traveleddistance':
                if (isset($body['value'])) {
                    $val  = floatval($body['value']);
                    $unit = strtolower($body['unit'] ?? 'km');
                    $km   = ($unit === 'miles') ? $val * $mi2km : $val;
                    $setSafe('Odometer', VARIABLETYPE_FLOAT, 'Kilometerstand', 'SMCAR.Odometer', $km);
                }
                break;

            // ---------- Security ----------
            case 'closure-islocked':
                if (isset($body['value'])) {
                    $setSafe('DoorsLocked', VARIABLETYPE_BOOLEAN, 'Fahrzeug verriegelt', '~Lock', (bool)$body['value']);
                }
                break;

            case 'closure-doors':
                $this->mapGridToVehicleSides($body, 'Door', 'FrontLeftDoor', 'FrontRightDoor', 'BackLeftDoor', 'BackRightDoor');
                break;

            case 'closure-windows':
                $this->mapGridToVehicleSides($body, 'Window', 'FrontLeftWindow', 'FrontRightWindow', 'BackLeftWindow', 'BackRightWindow');
                break;

            case 'closure-sunroof':
                if (array_key_exists('isOpen', $body)) {
                    $setSafe('Sunroof', VARIABLETYPE_STRING, 'Schiebedach', 'SMCAR.Status', $body['isOpen'] ? 'OPEN' : 'CLOSED');
                }
                break;

            // ---------- Connectivity ----------
            case 'connectivitystatus-isonline':
                if (isset($body['value'])) {
                    $setSafe('IsOnline', VARIABLETYPE_BOOLEAN, 'Online', '~Switch', (bool)$body['value']);
                }
                break;

            case 'connectivitystatus-isasleep':
                if (isset($body['value'])) {
                    $setSafe('IsAsleep', VARIABLETYPE_BOOLEAN, 'Schlafmodus', '~Switch', (bool)$body['value']);
                }
                break;

            case 'connectivitystatus-isdigitalkeypaired':
                if (isset($body['value'])) {
                    $setSafe('IsDigitalKeyPaired', VARIABLETYPE_BOOLEAN, 'Digitalschlüssel gekoppelt', '~Switch', (bool)$body['value']);
                }
                break;

            case 'connectivitysoftware-currentfirmwareversion':
                if (isset($body['value'])) {
                    $setSafe('FirmwareVersion', VARIABLETYPE_STRING, 'Firmware-Version', '', (string)$body['value']);
                }
                break;

            // ---------- ICE ----------
            case 'internalcombustionengine-fuellevel':
                if (isset($body['value'])) {
                    $setSafe('FuelLevel', VARIABLETYPE_FLOAT, 'Tankfüllstand', 'SMCAR.Progress', floatval($body['value']));
                }
                break;

            case 'internalcombustionengine-oillife':
                if (isset($body['value'])) {
                    $setSafe('OilLife', VARIABLETYPE_FLOAT, 'Öl-Lebensdauer', 'SMCAR.Progress', floatval($body['value']));
                }
                break;

            case 'internalcombustionengine-oilpressure':
                if (isset($body['value'])) $setSafe('OilPressure', VARIABLETYPE_FLOAT, 'Öldruck', '', floatval($body['value']));
                break;

            case 'internalcombustionengine-oiltemperature':
                if (isset($body['value'])) $setSafe('OilTemperature', VARIABLETYPE_FLOAT, 'Öltemperatur', '', floatval($body['value']));
                break;

            case 'internalcombustionengine-waterinfuel':
                if (isset($body['value'])) $setSafe('WaterInFuel', VARIABLETYPE_BOOLEAN, 'Wasser im Kraftstoff', '~Switch', (bool)$body['value']);
                break;

            // --- HVAC / Komfort ---
            case 'climatecontrol-isheateractive':
                if (isset($body['value'])) $setSafe('IsHeaterActive', VARIABLETYPE_BOOLEAN, 'Heizung aktiv', '~Switch', (bool)$body['value']);
                break;

            // --- Tires ---
            case 'tires-pressure':
                // mögliche Struktur: frontLeft/frontRight/backLeft/backRight ODER Grid/values
                if (isset($body['frontLeft']))  $setSafe('TireFrontLeft',  VARIABLETYPE_FLOAT, 'Reifendruck Vorderreifen Links',  'SMCAR.Pressure', floatval($body['frontLeft'])  * 0.01);
                if (isset($body['frontRight'])) $setSafe('TireFrontRight', VARIABLETYPE_FLOAT, 'Reifendruck Vorderreifen Rechts', 'SMCAR.Pressure', floatval($body['frontRight']) * 0.01);
                if (isset($body['backLeft']))   $setSafe('TireBackLeft',   VARIABLETYPE_FLOAT, 'Reifendruck Hinterreifen Links', 'SMCAR.Pressure', floatval($body['backLeft'])   * 0.01);
                if (isset($body['backRight']))  $setSafe('TireBackRight',  VARIABLETYPE_FLOAT, 'Reifendruck Hinterreifen Rechts','SMCAR.Pressure', floatval($body['backRight'])  * 0.01);
                break;

            // ---------- Vehicle Identification ----------
            case 'vehicleidentification-vin':
                if (isset($body['value'])) {
                    $setSafe('VIN', VARIABLETYPE_STRING, 'Fahrgestellnummer (VIN)', '', (string)$body['value']);
                }
                break;

            case 'vehicleidentification-trim':
                if (isset($body['value'])) {
                    $setSafe('Trim', VARIABLETYPE_STRING, 'Ausstattungslinie (Trim)', '', (string)$body['value']);
                }
                break;

            case 'vehicleidentification-exteriorcolor':
                if (isset($body['value'])) {
                    $setSafe('ExteriorColor', VARIABLETYPE_STRING, 'Außenfarbe', '', (string)$body['value']);
                }
                break;

            case 'vehicleidentification-packages':
                if (isset($body['values']) && is_array($body['values'])) {
                    $setSafe('Packages', VARIABLETYPE_STRING, 'Pakete', '', implode(', ', array_map('strval', $body['values'])));
                }
                break;

            case 'vehicleidentification-nickname':
                if (isset($body['value'])) {
                    $setSafe('Nickname', VARIABLETYPE_STRING, 'Fahrzeug-Spitzname', '', (string)$body['value']);
                }
                break;

            // Synthetische vehicle-Felder (Top-Level) → gleiche Variablen wie Scopes
            case 'vehicleidentification-make':
                if (isset($body['value'])) {
                    $setSafe('VehicleMake', VARIABLETYPE_STRING, 'Fahrzeug Hersteller', '', (string)$body['value']);
                }
                break;

            case 'vehicleidentification-model':
                if (isset($body['value'])) {
                    $setSafe('VehicleModel', VARIABLETYPE_STRING, 'Fahrzeug Modell', '', (string)$body['value']);
                }
                break;

            case 'vehicleidentification-year':
                if (isset($body['value'])) {
                    $setSafe('VehicleYear', VARIABLETYPE_INTEGER, 'Fahrzeug Baujahr', '', (int)$body['value']);
                }
                break;

            // ---------- Diagnostics & Fallback ----------
            default:
                if (str_starts_with(strtolower($code), 'diagnostics-')) {
                    $ident = $asDiagIdent($code);
                    if (isset($body['status']) && $body['status'] !== '') {
                        $setSafe($ident, VARIABLETYPE_STRING, 'Diagnose ' . $ident, 'SMCAR.Health', $asUpper($body['status']));
                    }
                    if (isset($body['description']) && $body['description'] !== '') {
                        $setSafe($ident . '_Desc', VARIABLETYPE_STRING, 'Diagnose Beschreibung ' . $ident, '', (string)$body['description']);
                    }
                    $low = strtolower($code);
                    if (str_ends_with($low, 'dtccount') && isset($body['value'])) {
                        $setSafe('Diag_DTCCount', VARIABLETYPE_INTEGER, 'Diagnose DTC Count', '', intval($body['value']));
                    }
                    if (str_ends_with($low, 'dtclist') && isset($body['values'])) {
                        $setSafe('Diag_DTCList', VARIABLETYPE_STRING, 'Diagnose DTC Liste', '', json_encode($body['values'], JSON_UNESCAPED_UNICODE));
                    }
                    break;
                }

                // Generischer Fallback für "value"-Signale
                if (array_key_exists('value', $body)) {
                    $ident = 'Sig_' . strtoupper(preg_replace('~[^A-Za-z0-9]+~', '_', $code));
                    $val   = $body['value'];
                    if (is_bool($val)) {
                        $setSafe($ident, VARIABLETYPE_BOOLEAN, $code, '~Switch', $val);
                    } elseif (is_numeric($val)) {
                        $setSafe($ident, VARIABLETYPE_FLOAT, $code, '', floatval($val));
                    } else {
                        $setSafe($ident, VARIABLETYPE_STRING, $code, '', (string)$val);
                    }
                } else {
                    // exotischer Payload ohne verwertbare Felder → als STATUS_ONLY gruppieren
                    $skipped['STATUS_ONLY'][] = $code;
                }
                break;
        }
    }

    private function mapGridToVehicleSides(array $body, string $kind, string $fl, string $fr, string $bl, string $br): void
    {
        // Erwartet: values[ {row:0/1, column:0/1, isOpen:true/false}, ... ]
        if (!isset($body['values']) || !is_array($body['values'])) return;

        foreach ($body['values'] as $item) {
            $row = $item['row'] ?? null;
            $col = $item['column'] ?? null;
            $isOpen = $item['isOpen'] ?? null;
            if ($row === null || $col === null || $isOpen === null) continue;

            $status = $isOpen ? 'OPEN' : 'CLOSED';
            if ($row === 0 && $col === 0) $this->SetValue($fl, $status);
            if ($row === 0 && $col === 1) $this->SetValue($fr, $status);
            if ($row === 1 && $col === 0) $this->SetValue($bl, $status);
            if ($row === 1 && $col === 1) $this->SetValue($br, $status);
        }
    }

    // -------- Commands --------

    public function SetChargeLimit(float $limit)
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID   = $this->GetVehicleID($accessToken);

        if ($limit < 0.5 || $limit > 1.0) {
            $this->SendDebug('SetChargeLimit', 'Ungültiges Limit (0.5–1.0).', 0);
            $this->LogMessage('SetChargeLimit - Ungültiges Limit!', KL_ERROR);
            return;
        }
        if ($accessToken === '' || $vehicleID === null || $vehicleID === '') {
            $this->SendDebug('SetChargeLimit', 'Access Token oder VehicleID fehlt.', 0);
            return;
        }

        $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/charge/limit";

        $res = $this->httpRequest(
            'SetChargeLimit',
            'POST',
            $url,
            $accessToken,
            ['limit' => $limit],
            'command',
            ['action' => 'SetChargeLimit', 'limit' => $limit]
        );

        if ($res === null) return;
        if (!empty($res['retried'])) return;

        $statusCode = (int)$res['statusCode'];
        $data       = $res['data'] ?? [];

        if ($statusCode !== 200) {
            $msg = $this->GetHttpErrorDetails($statusCode, is_array($data) ? $data : []);
            $this->SendDebug('SetChargeLimit', "❌ Fehler: $msg", 0);
            $this->LogMessage("SetChargeLimit - $msg", KL_ERROR);
            return;
        }

        if (is_array($data) && isset($data['statusCode']) && (int)$data['statusCode'] !== 200) {
            $this->SendDebug('SetChargeLimit', "Fehler: " . json_encode($data), 0);
            return;
        }

        $this->SendDebug('SetChargeLimit', '✅ Ladelimit gesetzt.', 0);
    }

    public function SetChargeStatus(bool $status)
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID   = $this->GetVehicleID($accessToken);

        if ($accessToken === '' || $vehicleID === null || $vehicleID === '') {
            $this->SendDebug('SetChargeStatus', 'Access Token oder VehicleID fehlt.', 0);
            return;
        }

        $action = $status ? 'START' : 'STOP';
        $url    = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/charge";

        $res = $this->httpRequest(
            'SetChargeStatus',
            'POST',
            $url,
            $accessToken,
            ['action' => $action],
            'command',
            ['action' => 'SetChargeStatus', 'status' => $status]
        );

        if ($res === null) {
            $this->SendDebug('SetChargeStatus', 'Keine Antwort.', 0);
            return;
        }
        if (!empty($res['retried'])) {
            return; // Retry wurde geplant
        }

        $statusCode = (int)$res['statusCode'];
        $data       = $res['data'] ?? [];

        if ($statusCode !== 200) {
            $msg = $this->GetHttpErrorDetails($statusCode, is_array($data) ? $data : []);
            $this->SendDebug('SetChargeStatus', "❌ Fehler: $msg", 0);
            $this->LogMessage("SetChargeStatus - $msg", KL_ERROR);
            return;
        }

        // Smartcar liefert manchmal statusCode im JSON
        if (is_array($data) && isset($data['statusCode']) && (int)$data['statusCode'] !== 200) {
            $this->SendDebug('SetChargeStatus', "Fehler: " . json_encode($data), 0);
            return;
        }

        $this->SendDebug('SetChargeStatus', '✅ Ladestatus gesetzt.', 0);
    }

    public function SetLockStatus(bool $status)
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID   = $this->GetVehicleID($accessToken);

        if ($accessToken === '' || $vehicleID === null || $vehicleID === '') {
            $this->SendDebug('SetLockStatus', 'Access Token oder VehicleID fehlt.', 0);
            return;
        }

        $action = $status ? 'LOCK' : 'UNLOCK';
        $url    = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/security";

        $res = $this->httpRequest(
            'SetLockStatus',
            'POST',
            $url,
            $accessToken,
            ['action' => $action],
            'command',
            ['action' => 'SetLockStatus', 'status' => $status]
        );

        if ($res === null) {
            $this->SendDebug('SetLockStatus', 'Keine Antwort.', 0);
            return;
        }
        if (!empty($res['retried'])) {
            return; // Retry wurde geplant
        }

        $statusCode = (int)$res['statusCode'];
        $data       = $res['data'] ?? [];

        if ($statusCode !== 200) {
            $msg = $this->GetHttpErrorDetails($statusCode, is_array($data) ? $data : []);
            $this->SendDebug('SetLockStatus', "❌ Fehler: $msg", 0);
            $this->LogMessage("SetLockStatus - $msg", KL_ERROR);
            return;
        }

        if (is_array($data) && isset($data['statusCode']) && (int)$data['statusCode'] !== 200) {
            $this->SendDebug('SetLockStatus', "Fehler: " . json_encode($data), 0);
            return;
        }

        $this->SendDebug('SetLockStatus', '✅ Zentralverriegelung gesetzt.', 0);
    }

    public function FetchVehicleInfo()  { $this->FetchSingleEndpoint('/'); }
    public function FetchVIN()          { $this->FetchSingleEndpoint('/vin'); }
    public function FetchLocation()     { $this->FetchSingleEndpoint('/location'); }
    public function FetchTires()        { $this->FetchSingleEndpoint('/tires/pressure'); }
    public function FetchOdometer()     { $this->FetchSingleEndpoint('/odometer'); }
    public function FetchBatteryLevel() { $this->FetchSingleEndpoint('/battery'); }
    public function FetchBatteryCapacity(){ $this->FetchSingleEndpoint('/battery/nominal_capacity'); }
    public function FetchEngineOil()    { $this->FetchSingleEndpoint('/engine/oil'); }
    public function FetchFuel()         { $this->FetchSingleEndpoint('/fuel'); }
    public function FetchSecurity()     { $this->FetchSingleEndpoint('/security'); }
    public function FetchChargeLimit()  { $this->FetchSingleEndpoint('/charge/limit'); }
    public function FetchChargeStatus() { $this->FetchSingleEndpoint('/charge'); }

    private function FetchSingleEndpoint(string $path)
    {
        $accessToken = $this->ReadAttributeString('AccessToken');

        // Cache bevorzugen
        $vehicleID = $this->ReadAttributeString('VehicleID');
        if ($vehicleID === '') {
            $vehicleID = $this->GetVehicleID($accessToken);
        }

        if ($accessToken === '' || $vehicleID === null || $vehicleID === '') {
            $this->SendDebug('FetchSingleEndpoint', '❌ Access Token oder VehicleID fehlt!', 0);
            return;
        }

        $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID$path";

        $res = $this->httpRequest(
            'FetchSingleEndpoint',
            'GET',
            $url,
            $accessToken,
            null,
            'single',
            ['path' => $path]
        );

        if ($res === null) {
            $this->SendDebug('FetchSingleEndpoint', '❌ Keine Antwort.', 0);
            return;
        }
        if (!empty($res['retried'])) {
            return; // Retry wurde geplant
        }

        $statusCode = (int)$res['statusCode'];
        $data       = $res['data'] ?? [];

        if ($statusCode !== 200) {
            $msg = $this->GetHttpErrorDetails($statusCode, is_array($data) ? $data : []);
            $this->SendDebug('FetchSingleEndpoint', "❌ Fehler: $msg", 0);
            $this->LogMessage("FetchSingleEndpoint - $msg", KL_ERROR);
            return;
        }

        if (!empty($data) && is_array($data)) {
            $this->ProcessResponse($path, $data);
        } else {
            $this->SendDebug('FetchSingleEndpoint', '❌ Unerwartete Antwortstruktur.', 0);
            $this->LogMessage('FetchSingleEndpoint - Unerwartete Antwortstruktur', KL_ERROR);
        }
    }

    private function GetHttpErrorDetails(int $statusCode, array $data): string
    {
        $errorText = match ($statusCode) {
            400 => 'Ungültige Anfrage an die Smartcar API.',
            401 => 'Ungültiges Access Token (401). Auto-Refresh wurde versucht – falls weiterhin 401: bitte neu verbinden.',
            403 => 'Keine Berechtigung für diesen API-Endpunkt.',
            404 => 'Fahrzeug oder Ressource nicht gefunden.',
            408 => 'Zeitüberschreitung bei der API-Anfrage.',
            429 => 'Zu viele Anfragen – Rate-Limit erreicht.',
            500, 502, 503, 504 => 'Smartcar API-Serverfehler.',
            default => "Unbekannter HTTP-Fehler ($statusCode)."
        };

        $apiCode = $data['code'] ?? ($data['body']['code'] ?? '');
        $apiDesc = $data['description'] ?? ($data['body']['description'] ?? '');
        if ($apiCode !== '') {
            $errorText .= " | Code: $statusCode | Smartcar-Code: $apiCode - $apiDesc";
        } else {
            $errorText .= " | Code: $statusCode";
        }
        return $errorText;
    }

    private function CreateProfile()
    {
        if (!IPS_VariableProfileExists('SMCAR.Pressure')) {
            IPS_CreateVariableProfile('SMCAR.Pressure', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('SMCAR.Pressure', '', ' bar');
            IPS_SetVariableProfileDigits('SMCAR.Pressure', 1);
            IPS_SetVariableProfileValues('SMCAR.Pressure', 0, 5, 0.1);
        }

        if (!IPS_VariableProfileExists('SMCAR.Odometer')) {
            IPS_CreateVariableProfile('SMCAR.Odometer', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('SMCAR.Odometer', '', ' km');
            IPS_SetVariableProfileDigits('SMCAR.Odometer', 0);
            IPS_SetVariableProfileValues('SMCAR.Odometer', 0, 0, 1);
        }

        if (!IPS_VariableProfileExists('SMCAR.Progress')) {
            IPS_CreateVariableProfile('SMCAR.Progress', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('SMCAR.Progress', '', ' %');
            IPS_SetVariableProfileDigits('SMCAR.Progress', 0);
            IPS_SetVariableProfileValues('SMCAR.Progress', 0, 100, 1);
        }

        if (!IPS_VariableProfileExists('SMCAR.Status')) {
            IPS_CreateVariableProfile('SMCAR.Status', VARIABLETYPE_STRING);
            IPS_SetVariableProfileAssociation('SMCAR.Status', 'OPEN', 'Offen', '', -1);
            IPS_SetVariableProfileAssociation('SMCAR.Status', 'CLOSED', 'Geschlossen', '', -1);
            IPS_SetVariableProfileAssociation('SMCAR.Status', 'UNKNOWN', 'Unbekannt', '', -1);
        }

        if (!IPS_VariableProfileExists('SMCAR.Charge')) {
            IPS_CreateVariableProfile('SMCAR.Charge', VARIABLETYPE_STRING);
            IPS_SetVariableProfileAssociation('SMCAR.Charge', 'CHARGING', 'Laden', '', -1);
            IPS_SetVariableProfileAssociation('SMCAR.Charge', 'FULLY_CHARGED', 'Voll geladen', '', -1);
            IPS_SetVariableProfileAssociation('SMCAR.Charge', 'NOT_CHARGING', 'Lädt nicht', '', -1);
            IPS_SetVariableProfileAssociation('SMCAR.Charge', 'UNKNOWN', 'Unbekannt', '', -1);
        }
        
        if (!IPS_VariableProfileExists('SMCAR.Health')) {
            IPS_CreateVariableProfile('SMCAR.Health', VARIABLETYPE_STRING);
            IPS_SetVariableProfileAssociation('SMCAR.Health', 'OK',      'OK',        '', -1);
            IPS_SetVariableProfileAssociation('SMCAR.Health', 'WARN',    'Warnung',   '', -1);
            IPS_SetVariableProfileAssociation('SMCAR.Health', 'ERROR',   'Fehler',    '', -1);
            IPS_SetVariableProfileAssociation('SMCAR.Health', 'UNKNOWN', 'Unbekannt', '', -1);
        }

        if (!IPS_VariableProfileExists('SMCAR.ChargeLimitSet')) {
            IPS_CreateVariableProfile('SMCAR.ChargeLimitSet', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('SMCAR.ChargeLimitSet', '', ' %');
            IPS_SetVariableProfileDigits('SMCAR.ChargeLimitSet', 0);
            IPS_SetVariableProfileValues('SMCAR.ChargeLimitSet', 50, 100, 5);
        }
    }

    // -------- Variablen je nach Scopes registrieren --------
    private function UpdateVariablesBasedOnScopes()
    {
        // Fahrzeugdetails
        if ($this->ReadPropertyBoolean('ScopeReadVehicleInfo')) {
            $this->RegisterVariableString('VehicleMake', 'Fahrzeug Hersteller', '', 1);
            $this->RegisterVariableString('VehicleModel', 'Fahrzeug Modell', '', 2);
            $this->RegisterVariableInteger('VehicleYear', 'Fahrzeug Baujahr', '', 3);
        } else {
            @$this->UnregisterVariable('VehicleMake');
            @$this->UnregisterVariable('VehicleModel');
            @$this->UnregisterVariable('VehicleYear');
        }

        // VIN
        if ($this->ReadPropertyBoolean('ScopeReadVIN')) {
            $this->RegisterVariableString('VIN', 'Fahrgestellnummer (VIN)', '', 4);
        } else {
            @$this->UnregisterVariable('VIN');
        }

        // Standort
        if ($this->ReadPropertyBoolean('ScopeReadLocation')) {
            $this->RegisterVariableFloat('Latitude', 'Breitengrad', '', 10);
            $this->RegisterVariableFloat('Longitude', 'Längengrad', '', 11);
        } else {
            @$this->UnregisterVariable('Latitude');
            @$this->UnregisterVariable('Longitude');
        }

        // Kilometerstand
        if ($this->ReadPropertyBoolean('ScopeReadOdometer')) {
            $this->RegisterVariableFloat('Odometer', 'Kilometerstand', 'SMCAR.Odometer', 20);
        } else {
            @$this->UnregisterVariable('Odometer');
        }

        // Reifendruck
        if ($this->ReadPropertyBoolean('ScopeReadTires')) {
            $this->RegisterVariableFloat('TireFrontLeft', 'Reifendruck Vorderreifen Links', 'SMCAR.Pressure', 30);
            $this->RegisterVariableFloat('TireFrontRight', 'Reifendruck Vorderreifen Rechts', 'SMCAR.Pressure', 31);
            $this->RegisterVariableFloat('TireBackLeft', 'Reifendruck Hinterreifen Links', 'SMCAR.Pressure', 32);
            $this->RegisterVariableFloat('TireBackRight', 'Reifendruck Hinterreifen Rechts', 'SMCAR.Pressure', 33);
        } else {
            @$this->UnregisterVariable('TireFrontLeft');
            @$this->UnregisterVariable('TireFrontRight');
            @$this->UnregisterVariable('TireBackLeft');
            @$this->UnregisterVariable('TireBackRight');
        }

        // Batterie
        if ($this->ReadPropertyBoolean('ScopeReadBattery')) {
            $this->RegisterVariableFloat('BatteryLevel', 'Batterieladestand (SOC)', 'SMCAR.Progress', 40);
            $this->RegisterVariableFloat('BatteryRange', 'Reichweite Batterie', 'SMCAR.Odometer', 41);
        } else {
            @$this->UnregisterVariable('BatteryRange');
            @$this->UnregisterVariable('BatteryLevel');
        }

        if ($this->ReadPropertyBoolean('ScopeReadBatteryCapacity')) {
            $this->RegisterVariableFloat('BatteryCapacity', 'Batteriekapazität', '~Electricity', 50);
        } else {
            @$this->UnregisterVariable('BatteryCapacity');
        }

        // Tank
        if ($this->ReadPropertyBoolean('ScopeReadFuel')) {
            $this->RegisterVariableFloat('FuelLevel', 'Tankfüllstand', 'SMCAR.Progress', 60);
            $this->RegisterVariableFloat('FuelRange', 'Reichweite Tank', 'SMCAR.Odometer', 61);
        } else {
            @$this->UnregisterVariable('FuelLevel');
            @$this->UnregisterVariable('FuelRange');
        }

        // Security
        if ($this->ReadPropertyBoolean('ScopeReadSecurity')) {
            $this->RegisterVariableBoolean('DoorsLocked', 'Fahrzeug verriegelt', '~Lock', 70);
            $this->RegisterVariableString('FrontLeftDoor',  'Vordertür links',  'SMCAR.Status', 71);
            $this->RegisterVariableString('FrontRightDoor', 'Vordertür rechts', 'SMCAR.Status', 72);
            $this->RegisterVariableString('BackLeftDoor',   'Hintentür links',  'SMCAR.Status', 73);
            $this->RegisterVariableString('BackRightDoor',  'Hintentür rechts', 'SMCAR.Status', 74);

            $this->RegisterVariableString('FrontLeftWindow',  'Vorderfenster links',  'SMCAR.Status', 75);
            $this->RegisterVariableString('FrontRightWindow', 'Vorderfenster rechts', 'SMCAR.Status', 76);
            $this->RegisterVariableString('BackLeftWindow',   'Hinterfenster links',  'SMCAR.Status', 77);
            $this->RegisterVariableString('BackRightWindow',  'Hinterfenster rechts', 'SMCAR.Status', 78);

            $this->RegisterVariableString('Sunroof',       'Schiebedach', 'SMCAR.Status', 79);
            $this->RegisterVariableString('RearStorage',   'Stauraum hinten', 'SMCAR.Status', 80);
            $this->RegisterVariableString('FrontStorage',  'Stauraum vorne',  'SMCAR.Status', 81);
            $this->RegisterVariableString('ChargingPort',  'Ladeanschluss',   'SMCAR.Status', 82);
        } else {
            @$this->UnregisterVariable('DoorsLocked');
            @$this->UnregisterVariable('FrontLeftDoor');
            @$this->UnregisterVariable('FrontRightDoor');
            @$this->UnregisterVariable('BackLeftDoor');
            @$this->UnregisterVariable('BackRightDoor');
            @$this->UnregisterVariable('FrontLeftWindow');
            @$this->UnregisterVariable('FrontRightWindow');
            @$this->UnregisterVariable('BackLeftWindow');
            @$this->UnregisterVariable('BackRightWindow');
            @$this->UnregisterVariable('Sunroof');
            @$this->UnregisterVariable('RearStorage');
            @$this->UnregisterVariable('FrontStorage');
            @$this->UnregisterVariable('ChargingPort');
        }

        // Ladeinformationen
        if ($this->ReadPropertyBoolean('ScopeReadChargeLimit')) {
            $this->RegisterVariableFloat('ChargeLimit', 'Aktuelles Ladelimit', 'SMCAR.Progress', 90);
        } else {
            @$this->UnregisterVariable('ChargeLimit');
        }

        if ($this->ReadPropertyBoolean('ScopeReadChargeStatus')) {
            $this->RegisterVariableString('ChargeStatus', 'Ladestatus', 'SMCAR.Charge', 91);
            $this->RegisterVariableBoolean('PluggedIn', 'Ladekabel eingesteckt', '~Switch', 92);
        } else {
            @$this->UnregisterVariable('ChargeStatus');
            @$this->UnregisterVariable('PluggedIn');
        }

        // Ölstatus
        if ($this->ReadPropertyBoolean('ScopeReadOilLife')) {
            $this->RegisterVariableFloat('OilLife', 'Verbleibende Öl-Lebensdauer', 'SMCAR.Progress', 100);
        } else {
            @$this->UnregisterVariable('OilLife');
        }

        // Commands
        if ($this->ReadPropertyBoolean('SetChargeLimit')) {
        $this->RegisterVariableFloat('SetChargeLimit', 'Ladelimit setzen', 'SMCAR.ChargeLimitSet', 110);
            $this->EnableAction('SetChargeLimit');
        } else {
            @$this->UnregisterVariable('SetChargeLimit');
        }

        if ($this->ReadPropertyBoolean('SetChargeStatus')) {
            $this->RegisterVariableBoolean('SetChargeStatus', 'Ladestatus setzen', '~Switch', 120);
            $this->EnableAction('SetChargeStatus');
        } else {
            @$this->UnregisterVariable('SetChargeStatus');
        }

        if ($this->ReadPropertyBoolean('SetLockStatus')) {
            $this->RegisterVariableBoolean('SetLockStatus', 'Zentralverriegelung', '~Lock', 130);
            $this->EnableAction('SetLockStatus');
        } else {
            @$this->UnregisterVariable('SetLockStatus');
        }
    }

    private function GetScopeMaps(): array
    {
        static $maps;
        if ($maps !== null) {
            return $maps;
        }

        // Zentrale Definition (eine Quelle)
        $defs = [
            // prop                    => [scope, path]
            'ScopeReadVehicleInfo'     => ['read_vehicle_info', '/'],
            'ScopeReadVIN'             => ['read_vin', '/vin'],
            'ScopeReadLocation'        => ['read_location', '/location'],
            'ScopeReadTires'           => ['read_tires', '/tires/pressure'],
            'ScopeReadOdometer'        => ['read_odometer', '/odometer'],
            'ScopeReadBattery'         => ['read_battery', '/battery'],
            'ScopeReadBatteryCapacity' => ['read_battery', '/battery/nominal_capacity'],
            'ScopeReadFuel'            => ['read_fuel', '/fuel'],
            'ScopeReadSecurity'        => ['read_security', '/security'],
            'ScopeReadChargeStatus'    => ['read_charge', '/charge'],
            'ScopeReadChargeLimit'     => ['read_charge', '/charge/limit'],
            'ScopeReadOilLife'         => ['read_engine_oil', '/engine/oil'],

            // CONTROL
            'SetChargeLimit'           => ['control_charge', '/charge/limit'],
            'SetChargeStatus'          => ['control_charge', '/charge'],
            'SetLockStatus'            => ['control_security', '/security'],
        ];

        $maps = [
            'READ_SCOPES'     => [],
            'PROP_TO_PATH'    => [],
            'PROP_TO_SCOPE'   => [],
            'SCOPE_TO_PATHS'  => [],
        ];

        foreach ($defs as $prop => [$scope, $path]) {
            $maps['PROP_TO_PATH'][$prop]  = $path;
            $maps['PROP_TO_SCOPE'][$prop] = $scope;

            if (str_starts_with($scope, 'read_')) {
                $maps['READ_SCOPES'][$scope] = true;
            }

            $maps['SCOPE_TO_PATHS'][$scope][] = $path;
        }

        // READ_SCOPES als sauberes Array
        $maps['READ_SCOPES'] = array_keys($maps['READ_SCOPES']);

        return $maps;
    }
}