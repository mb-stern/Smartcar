<?php

class Smartcar extends IPSModule
{
    public function Create()
    {
        parent::Create();

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
        
        // Effektive OAuth-Redirect-URI (manuell ODER Connect+Hook)
        $this->RegisterAttributeString('RedirectURI', '');
        
        // Nur Info/Anzeige: unter dieser URL ist dein Webhook erreichbar (Connect + Hook)
        $this->RegisterAttributeString('WebhookCallbackURI', '');
        
        //Kompatiple Scopes
        $this->RegisterAttributeString('CompatScopes', ''); // JSON: {"read_battery":true,...}
        $this->RegisterAttributeBoolean('PendingAutoCompat', false);

        // Timer
        $this->RegisterTimer('TokenRefreshTimer', 0, 'SMCAR_RefreshAccessToken(' . $this->InstanceID . ');');

        // Kernel-Runlevel
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Hook (WebHook-Control) setzen/aufräumen → /hook/smartcar_<InstanceID>
        $hookPath = $this->RegisterHook();
        $this->SendDebug('ApplyChanges', "Hook-Pfad aktiv: $hookPath", 0);

        // Token-Refresh alle 90 Minuten
        $this->SetTimerInterval('TokenRefreshTimer', 90 * 60 * 1000);
        $this->SendDebug('ApplyChanges', 'Token-Erneuerungs-Timer auf 90 min gestellt.', 0);

        if (IPS_GetKernelRunlevel() === KR_READY && $this->ReadAttributeString('RefreshToken') !== '') {
            $this->RefreshAccessToken();
        }

        // Effektive Redirect-URI festlegen (manuell oder ipmagic-Connect + Hook)
        $manual = trim($this->ReadPropertyString('ManualRedirectURI'));
        if ($manual !== '') {
            if (!preg_match('~^https://~i', $manual)) {
                $this->SendDebug('ApplyChanges', 'Warnung: Manuelle Redirect-URI ohne https:// – wird trotzdem verwendet.', 0);
            }
            $effectiveRedirect = $manual;
            $this->SendDebug('ApplyChanges', 'Manuelle Redirect-URI aktiv.', 0);
        } else {
            $effectiveRedirect = $this->BuildConnectURL($hookPath);
            if ($effectiveRedirect === '') {
                $this->SendDebug('ApplyChanges', 'Connect-Adresse nicht verfügbar. Redirect-URI bleibt leer.', 0);
                $this->LogMessage('ApplyChanges - Connect-Adresse konnte nicht ermittelt werden.', KL_ERROR);
            } else {
                $this->SendDebug('ApplyChanges', 'Redirect-URI automatisch (Connect+Hook).', 0);
            }
        }

        $this->WriteAttributeString('RedirectURI', $effectiveRedirect);
        $this->WriteAttributeString('WebhookCallbackURI', $effectiveRedirect);

        $this->CreateProfile();

        if ($this->ReadPropertyBoolean('TrackLastSignals')) {
            if (!@$this->GetIDForIdent('LastSignalsAt')) {
                $this->RegisterVariableInteger('LastSignalsAt', 'Letzte Fahrzeug-Signale', '~UnixTimestamp', 5);
            }
        } else {
            @$this->UnregisterVariable('LastSignalsAt');
        }

        $this->UpdateVariablesBasedOnScopes();
    }

    private function BuildConnectURL(string $hookPath): string
    {
        if ($hookPath === '' || strpos($hookPath, '/hook/') !== 0) {
            $hookPath = '/hook/' . ltrim($hookPath, '/');
        }

        $connectAddress = '';
        $ids = @IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}');
        if (!empty($ids)) {
            $connectAddress = @CC_GetUrl($ids[0]);
        }
        if (is_string($connectAddress) && $connectAddress !== '') {
            return rtrim($connectAddress, '/') . $hookPath;
        }
        return '';
    }

    public function RequestAction($ident, $value)
    {
        switch ($ident) {
            case 'SetChargeLimit':
                $this->SetChargeLimit($value / 100);
                $this->SetValue($ident, $value);
                break;

            case 'SetChargeStatus':
                $this->SetChargeStatus($value);
                $this->SetValue($ident, $value);
                break;

            case 'SetLockStatus':
                $this->SetLockStatus($value);
                $this->SetValue($ident, $value);
                break;

            default:
                throw new Exception('Invalid ident');
        }
    }

    private function RegisterHook()
    {
        $desired = '/hook/smartcar_' . $this->InstanceID;

        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) === 0) {
            $this->WriteAttributeString('CurrentHook', $desired);
            $this->SendDebug('RegisterHook', 'Keine WebHook-Control-Instanz gefunden.', 0);
            return $desired;
        }

        $hookInstanceID = $ids[0];
        $hooks = json_decode(IPS_GetProperty($hookInstanceID, 'Hooks'), true);
        if (!is_array($hooks)) $hooks = [];

        // alte/kaputte Mappings entfernen
        $clean = [];
        foreach ($hooks as $h) {
            $hHook = $h['Hook'] ?? '';
            $hTarget = $h['TargetID'] ?? 0;
            if ($hTarget === $this->InstanceID) continue; // von dieser Instanz → entfernen
            if (preg_match('~^/hook/https?://~i', $hHook)) continue; // kaputte Einträge
            $clean[] = $h;
        }

        // unser gewünschtes Mapping hinzufügen
        $clean[] = ['Hook' => $desired, 'TargetID' => $this->InstanceID];

        IPS_SetProperty($hookInstanceID, 'Hooks', json_encode($clean));
        IPS_ApplyChanges($hookInstanceID);
        $this->SendDebug('RegisterHook', "Hook neu registriert: $desired", 0);

        $this->WriteAttributeString('CurrentHook', $desired);
        return $desired;
    }

public function GetConfigurationForm()
{
    $effectiveRedirect = $this->ReadAttributeString('RedirectURI');

    // Kompatibilitäts-Cache laden (permission => true/false), null bedeutet: kein Filter aktiv
    $compatRaw = $this->ReadAttributeString('CompatScopes');
    $compat    = $compatRaw !== '' ? json_decode($compatRaw, true) : null;
    $hasCompat = is_array($compat) && !empty($compat);

    // Sichtbarkeitslogik pro Permission
    $permVisible = function (string $permission) use ($compat): bool {
        // Kein Cache → alles anzeigen
        if (!is_array($compat)) return true;
        // Wenn vorhanden: nur anzeigen, wenn true; unbekannt → anzeigen
        return !array_key_exists($permission, $compat) || (bool)$compat[$permission] === true;
    };

    $form = [
        'elements' => [
            // Redirect/Webhook Info + Einstellungen
            ['type' => 'Label', 'caption' => 'Redirect- & Webhook-URI: ' . $effectiveRedirect],
            [
                'type'    => 'ValidationTextBox',
                'name'    => 'ManualRedirectURI',
                'caption' => 'Manuelle Redirect-URI überschreibt Connect-URL'
            ],
            ['type' => 'Label', 'caption' => '────────────────────────────────────────'],
            ['type' => 'CheckBox', 'name' => 'EnableWebhook', 'caption' => 'Webhook-Empfang für Signale aktivieren'],
            ['type' => 'CheckBox', 'name' => 'VerifyWebhookSignature', 'caption' => 'Fahrzeug verifizieren (Fahrzeugfilter!)'],
            ['type' => 'CheckBox', 'name' => 'TrackLastSignals', 'caption' => 'Letze Aktualisierung der Signale anzeigen'],

            [
                'type'    => 'ValidationTextBox',
                'name'    => 'ManagementToken',
                'caption' => 'Application Management Token (HMAC & VERIFY)'
            ],
            ['type' => 'Label', 'caption' => '────────────────────────────────────────'],

            // Verbindungsdaten
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

            // Hinweis zur Scope-Filterung
            ['type' => 'Label', 'caption' => $hasCompat
                ? 'Scope-Filter aktiv (Ergebnis der automatischen Prüfung wird angewendet).'
                : 'Noch keine automatische Prüfung – alle Scopes werden gelistet. Kompatible Scopes mit Button prüfen und auf OK warten. Danach Formular schliessen und wieder öffnen.'],
            ['type' => 'Label',  'caption' => $hasCompat ? ('Gefundene kompatible Scopes: ' . implode(', ', array_keys(array_filter($compat ?? [])))) : ''],
            
            // Scopes (sichtbar je nach Kompatibilität)
            [
                'type'    => 'ExpansionPanel',
                'caption' => 'Berechtigungen (Scopes)',
                'items'   => [
                    ['type' => 'Label', 'caption' => 'Zugehörige Variablen werden automatisch erstellt bzw. gelöscht.'],
                    // READ
                    ['type'=>'CheckBox','name'=>'ScopeReadVehicleInfo',     'caption'=>'Fahrzeuginformationen lesen (/)','visible'=>$permVisible('read_vehicle_info')],
                    ['type'=>'CheckBox','name'=>'ScopeReadVIN',             'caption'=>'VIN lesen (/vin)','visible'=>$permVisible('read_vin')],
                    ['type'=>'CheckBox','name'=>'ScopeReadLocation',        'caption'=>'Standort lesen (/location)','visible'=>$permVisible('read_location')],
                    ['type'=>'CheckBox','name'=>'ScopeReadTires',           'caption'=>'Reifendruck lesen (/tires/pressure)','visible'=>$permVisible('read_tires')],
                    ['type'=>'CheckBox','name'=>'ScopeReadOdometer',        'caption'=>'Kilometerstand lesen (/odometer)','visible'=>$permVisible('read_odometer')],
                    ['type'=>'CheckBox','name'=>'ScopeReadBattery',         'caption'=>'Batterielevel lesen (/battery)','visible'=>$permVisible('read_battery')],
                    ['type'=>'CheckBox','name'=>'ScopeReadBatteryCapacity', 'caption'=>'Batteriekapazität lesen (/battery/nominal_capacity)','visible'=>$permVisible('read_battery')],
                    ['type'=>'CheckBox','name'=>'ScopeReadFuel',            'caption'=>'Kraftstoffstand lesen (/fuel)','visible'=>$permVisible('read_fuel')],
                    ['type'=>'CheckBox','name'=>'ScopeReadSecurity',        'caption'=>'Verriegelungsstatus lesen (/security)','visible'=>$permVisible('read_security')],
                    ['type'=>'CheckBox','name'=>'ScopeReadChargeLimit',     'caption'=>'Ladelimit lesen (/charge/limit)','visible'=>$permVisible('read_charge')],
                    ['type'=>'CheckBox','name'=>'ScopeReadChargeStatus',    'caption'=>'Ladestatus lesen (/charge)','visible'=>$permVisible('read_charge')],
                    ['type'=>'CheckBox','name'=>'ScopeReadOilLife',         'caption'=>'Motoröl lesen (/engine/oil)','visible'=>$permVisible('read_engine_oil')],

                    // Commands (nicht aktiv probiert; optional sichtbar lassen)
                    ['type'=>'CheckBox','name'=>'SetChargeLimit',  'caption'=>'Ladelimit setzen (/charge/limit) – (Kompatibilität kann nicht geprüft werden)'],
                    ['type'=>'CheckBox','name'=>'SetChargeStatus', 'caption'=>'Ladestatus setzen (/charge) – (Kompatibilität kann nicht geprüft werden)'],
                    ['type'=>'CheckBox','name'=>'SetLockStatus',   'caption'=>'Zentralverriegelung setzen (/security) – (Kompatibilität kann nicht geprüft werden)']
                ]
            ],
        ],
        'actions' => [
            ['type' => 'Button', 'caption' => 'Smartcar verbinden',     'onClick' => 'echo SMCAR_GenerateAuthURL($id);'],
            
            ['type' => 'Button', 'caption' => 'Kompatible Scopes automatisch einrichten', 'onClick' => 'echo SMCAR_AutoCompat($id);'],

            ['type' => 'Button', 'caption' => 'Fahrzeugdaten abrufen',  'onClick' => 'SMCAR_FetchVehicleData($id);'],

            ['type' => 'Label',  'caption' => 'Sag danke und unterstütze den Modulentwickler:'],
            [
                'type'  => 'RowLayout',
                'items' => [
                        [
                            'type'   => 'Image',
                            'onClick'=> "echo 'https://paypal.me/mbstern';",
                            "image"=> "data:image/jpeg;base64,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"
                        ],
                        ['type' => 'Label', 'caption' => '']
                    ]
                ]
            ]
        ];

        return json_encode($form);
    }

    private function PathToPermission(string $path): ?string
    {
        return match ($path) {
            '/'                   => 'read_vehicle_info',
            '/vin'                => 'read_vin',
            '/location'           => 'read_location',
            '/tires/pressure'     => 'read_tires',
            '/odometer'           => 'read_odometer',
            '/battery', '/battery/nominal_capacity'
                                => 'read_battery',
            '/fuel'               => 'read_fuel',
            '/security'           => 'read_security',
            '/charge/limit', '/charge'
                                => 'read_charge',
            '/engine/oil'         => 'read_engine_oil',
            default               => null
        };
    }

    private function AllReadPaths(): array
    {
        return [
            '/', '/vin', '/location', '/tires/pressure', '/odometer',
            '/battery', '/battery/nominal_capacity', '/fuel', '/security',
            '/charge/limit', '/charge', '/engine/oil'
        ];
    }

    private function SetAllReadScopes(bool $state): void 
    {
    IPS_SetProperty($this->InstanceID,'ScopeReadVehicleInfo',      $state);
    IPS_SetProperty($this->InstanceID,'ScopeReadVIN',              $state);
    IPS_SetProperty($this->InstanceID,'ScopeReadLocation',         $state);
    IPS_SetProperty($this->InstanceID,'ScopeReadTires',            $state);
    IPS_SetProperty($this->InstanceID,'ScopeReadOdometer',         $state);
    IPS_SetProperty($this->InstanceID,'ScopeReadBattery',          $state);
    IPS_SetProperty($this->InstanceID,'ScopeReadBatteryCapacity',  $state);
    IPS_SetProperty($this->InstanceID,'ScopeReadFuel',             $state);
    IPS_SetProperty($this->InstanceID,'ScopeReadSecurity',         $state);
    IPS_SetProperty($this->InstanceID,'ScopeReadChargeLimit',      $state);
    IPS_SetProperty($this->InstanceID,'ScopeReadChargeStatus',     $state);
    IPS_SetProperty($this->InstanceID,'ScopeReadOilLife',          $state);
    }

    private function ApplyCompatToProperties(array $compat): void 
    {
    $set = function(string $prop, string $perm) use ($compat) {
        $ok = isset($compat[$perm]) ? (bool)$compat[$perm] : false;
        IPS_SetProperty($this->InstanceID, $prop, $ok);
    };
    $set('ScopeReadVehicleInfo',     'read_vehicle_info');
    $set('ScopeReadVIN',             'read_vin');
    $set('ScopeReadLocation',        'read_location');
    $set('ScopeReadTires',           'read_tires');
    $set('ScopeReadOdometer',        'read_odometer');
    $set('ScopeReadBattery',         'read_battery');
    $set('ScopeReadBatteryCapacity', 'read_battery');   // gleicher Scope
    $set('ScopeReadFuel',            'read_fuel');
    $set('ScopeReadSecurity',        'read_security');
    $set('ScopeReadChargeLimit',     'read_charge');
    $set('ScopeReadChargeStatus',    'read_charge');    // gleicher Scope
    $set('ScopeReadOilLife',         'read_engine_oil');
    }

    public function ProbeScopes(): bool
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID   = $this->GetVehicleID($accessToken);
        if ($accessToken === '' || !$vehicleID) {
            $this->SendDebug('ProbeScopes', '❌ AccessToken/VehicleID fehlt.', 0);
            return false;
        }

        // Batch mit ALLEN Read-Pfaden (ohne Side-Effects)
        $paths = $this->AllReadPaths();
        $reqs  = array_map(fn($p) => ['path' => $p], $paths);

        $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/batch";
        $postData = json_encode(['requests' => $reqs]);

        $ctx = stream_context_create([
            'http' => [
                'header'        => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method'        => 'POST',
                'content'       => $postData,
                'ignore_errors' => true
            ]
        ]);

        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) {
            $this->SendDebug('ProbeScopes', '❌ Keine Antwort.', 0);
            return false;
        }
        $data = json_decode($res, true);
        $this->SendDebug('ProbeScopes', "Antwort: ".json_encode($data, JSON_PRETTY_PRINT), 0);

        if (!is_array($data) || !isset($data['responses']) || !is_array($data['responses'])) {
            $this->SendDebug('ProbeScopes', '❌ Unerwartete Struktur.', 0);
            return false;
        }

        // permission => true/false aufbauen
        $map = [];
        foreach ($data['responses'] as $r) {
            $path = $r['path'] ?? '';
            $code = $r['code'] ?? 0;
            $perm = $this->PathToPermission($path);
            if (!$perm) continue;

            // Erfolgskriterium: 200
            $ok = ($code === 200);
            // Bei mehrfachen Pfaden pro Permission (battery, charge) reicht ein true
            $map[$perm] = ($map[$perm] ?? false) || $ok;
        }

        $this->WriteAttributeString('CompatScopes', json_encode($map, JSON_UNESCAPED_SLASHES));
        $this->SendDebug('ProbeScopes', 'CompatScopes=' . json_encode($map), 0);
        return true;
    }

    public function GenerateAuthURL()
    {
        $clientID     = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $mode         = $this->ReadPropertyString('Mode');
        $redirectURI  = $this->ReadAttributeString('RedirectURI'); // nutzt ggf. manuelle URI

        if (empty($clientID) || empty($clientSecret) || empty($redirectURI)) {
            $this->SendDebug('GenerateAuthURL', 'Fehler: ClientID/ClientSecret/RedirectURI fehlt!', 0);
            return 'Fehler: Client ID / Client Secret / Redirect-URI fehlt!';
        }

        // Scopes dynamisch
        $scopes = [];
        if ($this->ReadPropertyBoolean('ScopeReadVehicleInfo')) $scopes[] = 'read_vehicle_info';
        if ($this->ReadPropertyBoolean('ScopeReadLocation')) $scopes[] = 'read_location';
        if ($this->ReadPropertyBoolean('ScopeReadOdometer')) $scopes[] = 'read_odometer';
        if ($this->ReadPropertyBoolean('ScopeReadTires')) $scopes[] = 'read_tires';
        if ($this->ReadPropertyBoolean('ScopeReadBattery') || $this->ReadPropertyBoolean('ScopeReadBatteryCapacity')) $scopes[] = 'read_battery';
        if ($this->ReadPropertyBoolean('ScopeReadFuel')) $scopes[] = 'read_fuel';
        if ($this->ReadPropertyBoolean('ScopeReadSecurity')) $scopes[] = 'read_security';
        if ($this->ReadPropertyBoolean('ScopeReadChargeLimit') || $this->ReadPropertyBoolean('ScopeReadChargeStatus')) $scopes[] = 'read_charge';
        if ($this->ReadPropertyBoolean('ScopeReadVIN')) $scopes[] = 'read_vin';
        if ($this->ReadPropertyBoolean('ScopeReadOilLife')) $scopes[] = 'read_engine_oil';
        if ($this->ReadPropertyBoolean('SetChargeLimit') || $this->ReadPropertyBoolean('SetChargeStatus')) $scopes[] = 'control_charge';
        if ($this->ReadPropertyBoolean('SetLockStatus')) $scopes[] = 'control_security';

        if (empty($scopes)) {
            $this->SendDebug('GenerateAuthURL', 'Fehler: Keine Scopes ausgewählt!', 0);
            return 'Fehler: Keine Scopes ausgewählt!';
        }

        $authURL = "https://connect.smartcar.com/oauth/authorize?" .
            "response_type=code" .
            "&client_id=" . urlencode($clientID) .
            "&redirect_uri=" . urlencode($redirectURI) .
            "&scope=" . urlencode(implode(' ', $scopes)) .
            "&state=" . bin2hex(random_bytes(8)) .
            "&mode=" . urlencode($mode);

        $this->SendDebug('GenerateAuthURL', "Generierte Auth-URL: $authURL", 0);
        return $authURL;
    }

    public function ProcessHookData()
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $_SERVER['REQUEST_URI']     ?? '';
        $qs     = $_SERVER['QUERY_STRING']    ?? '';

        $this->SendDebug('Webhook', "Request: method=$method uri=$uri qs=$qs", 0);

        if ($method === 'GET' && isset($_GET['code'])) {
            $authCode = $_GET['code'];
            $state    = $_GET['state'] ?? '';
            $this->SendDebug('Webhook', "OAuth Redirect: code=$authCode state=$state", 0);
            $this->RequestAccessToken($authCode);

            if ($this->ReadAttributeBoolean('PendingAutoCompat')) {
                // direkt im Anschluss kompatible Scopes testen
                $ok = $this->ProbeScopes();
                $this->WriteAttributeBoolean('PendingAutoCompat', false);

                if ($ok) {
                    $raw = $this->ReadAttributeString('CompatScopes');
                    $compat = $raw !== '' ? json_decode($raw, true) : [];
                    if (is_array($compat)) {
                        $this->ApplyCompatToProperties($compat);
                        IPS_ApplyChanges($this->InstanceID); // Variablen anlegen/löschen
                    }
                    echo 'Kompatible Scopes ermittelt & angewendet. Formular neu öffnen.';
                    return;
                }
                echo 'Tokens gespeichert, aber Kompatibilitätsprüfung fehlgeschlagen.';
                return;
            }

            echo 'Fahrzeug erfolgreich verbunden!';
            return;
        }

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
        $this->SendDebug('Webhook', 'RAW Body: ' . $raw, 0);

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $this->SendDebug('Webhook', '❌ Ungültiges JSON.', 0);
            http_response_code(400);
            echo 'Bad Request';
            return;
        }
        $this->SendDebug('Webhook', 'JSON: ' . json_encode($payload, JSON_PRETTY_PRINT), 0);

        $verifyEnabled = $this->ReadPropertyBoolean('VerifyWebhookSignature');
        $mgmtToken     = trim($this->ReadPropertyString('ManagementToken'));

        // --- VERIFY-Challenge ---
        if (($payload['eventType'] ?? '') === 'VERIFY') {
            // Wichtig: challenge steckt in data.challenge (Fallback: top-level, falls Smartcar das mal ändert)
            $challenge = $payload['data']['challenge'] ?? ($payload['challenge'] ?? '');
            if ($challenge === '') {
                $this->SendDebug('Webhook', '❌ VERIFY: challenge fehlt (erwartet data.challenge).', 0);
                http_response_code(400);
                echo 'Bad Request';
                return;
            }

            $verifyEnabled = $this->ReadPropertyBoolean('VerifyWebhookSignature');
            $mgmtToken     = trim($this->ReadPropertyString('ManagementToken'));

            // Testmodus: Verifizierung AUS → plain challenge zurückgeben
            if (!$verifyEnabled) {
                $this->SendDebug('Webhook', "VERIFY (Testmodus): gebe plain challenge zurück: {$challenge}", 0);
                header('Content-Type: application/json');
                echo json_encode(['challenge' => $challenge]);
                return;
            }

            // Verifizierung AN → HMAC über die challenge mit ManagementToken bilden
            if ($mgmtToken === '') {
                $this->SendDebug('Webhook', '❌ VERIFY: VerifyWebhookSignature=true aber ManagementToken leer.', 0);
                http_response_code(401);
                echo 'Unauthorized';
                return;
            }

            // Smartcar erwartet HMAC-SHA256 (hex) über die challenge mit dem Application Management Token
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
            // Original-Signals
            $signals = $payload['data']['signals'] ?? [];
            if (!is_array($signals)) $signals = [];

            // Top-Level vehicle → als synthetische Signals hinzufügen,
            // damit make/model/year über ApplySignal() laufen
            $veh = $payload['data']['vehicle'] ?? [];
            $synthetic = [];
            if (is_array($veh)) {
                if (array_key_exists('make', $veh)) {
                    $synthetic[] = ['code' => 'vehicleidentification-make',  'body' => ['value' => (string)$veh['make']]];
                }
                if (array_key_exists('model', $veh)) {
                    $synthetic[] = ['code' => 'vehicleidentification-model', 'body' => ['value' => (string)$veh['model']]];
                }
                if (array_key_exists('year', $veh)) {
                    $synthetic[] = ['code' => 'vehicleidentification-year',  'body' => ['value' => (int)$veh['year']]];
                }
            }
            if (!empty($synthetic)) {
                $this->SendDebug('Webhook', 'Synthetische Signals: ' . json_encode($synthetic), 0);
                $signals = array_merge($synthetic, $signals);
            }

            // Sammelstrukturen für ein aufgeräumtes Debug
            $created = []; // neu angelegte Variablen: ident => value
            $skipped = [
                'COMPATIBILITY' => [],
                'PERMISSION'    => [],
                'UPSTREAM'      => [],
                'STATUS_ONLY'   => [],
                'OTHER'         => []
            ];

            // Alle Signals einheitlich über ApplySignal()
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

            // genau EIN Eintrag für alle neu angelegten Variablen
            if (!empty($created)) {
                $this->SendDebug('Signals/created', json_encode($created, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);
            }
            // genau EIN Eintrag für alle übersprungenen (nur Status/keine Daten)
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
        }
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

    private function RequestAccessToken(string $authCode)
    {
        $clientID     = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $redirectURI  = $this->ReadAttributeString('RedirectURI'); // manuell ODER Connect+Hook

        $url = 'https://auth.smartcar.com/oauth/token';

        $postData = http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $authCode,
            'redirect_uri'  => $redirectURI,
            'client_id'     => $clientID,
            'client_secret' => $clientSecret
        ]);

        $options = [
            'http' => [
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method'        => 'POST',
                'content'       => $postData,
                'ignore_errors' => true
            ]
        ];

        $context  = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        $responseData = json_decode($response, true);

        if (isset($responseData['access_token'], $responseData['refresh_token'])) {
            $this->WriteAttributeString('AccessToken',  $responseData['access_token']);
            $this->WriteAttributeString('RefreshToken', $responseData['refresh_token']);
            $this->SendDebug('RequestAccessToken', 'Access & Refresh Token gespeichert.', 0);
            $this->ApplyChanges();
        } else {
            $this->SendDebug('RequestAccessToken', '❌ Token-Austausch fehlgeschlagen! Antwort: ' . $response, 0);
            $this->LogMessage('RequestAccessToken - Token-Austausch fehlgeschlagen.', KL_ERROR);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE) {
            $runlevel = $Data[0] ?? -1;
            if ($runlevel === KR_READY) {
                if ($this->ReadAttributeString('RefreshToken') !== '') {
                    $this->RefreshAccessToken();
                }
            }
        }
    }

    public function RefreshAccessToken()
    {
        $this->SendDebug('RefreshAccessToken', 'Token-Erneuerung gestartet!', 0);

        $clientID     = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $refreshToken = $this->ReadAttributeString('RefreshToken');

        if ($clientID === '' || $clientSecret === '' || $refreshToken === '') {
            $this->SendDebug('RefreshAccessToken', '❌ Fehlende Zugangsdaten!', 0);
            $this->LogMessage('RefreshAccessToken - Fehlende Zugangsdaten!', KL_ERROR);
            return;
        }

        $url = 'https://auth.smartcar.com/oauth/token';
        $postData = http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $clientID,
            'client_secret' => $clientSecret
        ]);

        $options = [
            'http' => [
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method'        => 'POST',
                'content'       => $postData,
                'ignore_errors' => true
            ]
        ];

        $context  = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        $data     = json_decode($response, true);

        if (isset($data['access_token'], $data['refresh_token'])) {
            $this->WriteAttributeString('AccessToken',  $data['access_token']);
            $this->WriteAttributeString('RefreshToken', $data['refresh_token']);
            $this->SendDebug('RefreshAccessToken', '✅ Token erfolgreich erneuert.', 0);
        } else {
            $this->SendDebug('RefreshAccessToken', '❌ Token-Erneuerung fehlgeschlagen!', 0);
            $this->LogMessage('RefreshAccessToken - fehlgeschlagen!', KL_ERROR);
        }
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

        $endpoints = [];
        if ($this->ReadPropertyBoolean('ScopeReadVehicleInfo'))     $endpoints[] = ['path' => '/'];
        if ($this->ReadPropertyBoolean('ScopeReadVIN'))             $endpoints[] = ['path' => '/vin'];
        if ($this->ReadPropertyBoolean('ScopeReadLocation'))        $endpoints[] = ['path' => '/location'];
        if ($this->ReadPropertyBoolean('ScopeReadTires'))           $endpoints[] = ['path' => '/tires/pressure'];
        if ($this->ReadPropertyBoolean('ScopeReadOdometer'))        $endpoints[] = ['path' => '/odometer'];
        if ($this->ReadPropertyBoolean('ScopeReadBattery'))         $endpoints[] = ['path' => '/battery'];
        if ($this->ReadPropertyBoolean('ScopeReadBatteryCapacity')) $endpoints[] = ['path' => '/battery/nominal_capacity'];
        if ($this->ReadPropertyBoolean('ScopeReadFuel'))            $endpoints[] = ['path' => '/fuel'];
        if ($this->ReadPropertyBoolean('ScopeReadSecurity'))        $endpoints[] = ['path' => '/security'];
        if ($this->ReadPropertyBoolean('ScopeReadChargeLimit'))     $endpoints[] = ['path' => '/charge/limit'];
        if ($this->ReadPropertyBoolean('ScopeReadChargeStatus'))    $endpoints[] = ['path' => '/charge'];
        if ($this->ReadPropertyBoolean('ScopeReadOilLife'))         $endpoints[] = ['path' => '/engine/oil'];

        if (empty($endpoints)) {
            $this->SendDebug('FetchVehicleData', 'Keine Scopes aktiviert!', 0);
            $this->LogMessage('FetchVehicleData - Keine Scopes aktiviert!', KL_WARNING);
            return false;
        }

        $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/batch";
        $postData = json_encode(['requests' => $endpoints]);

        $options = [
            'http' => [
                'header'        => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method'        => 'POST',
                'content'       => $postData,
                'ignore_errors' => true
            ]
        ];

        $this->SendDebug('FetchVehicleData', "API-Anfrage: " . json_encode([
            'url'    => $url,
            'method' => $options['http']['method'],
            'header' => $options['http']['header'],
            'body'   => $postData
        ], JSON_PRETTY_PRINT), 0);

        $context  = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $this->SendDebug('FetchVehicleData', '❌ Keine Antwort von der API!', 0);
            $this->LogMessage('FetchVehicleData - Keine Antwort von der API!', KL_ERROR);
            return false;
        }

        $httpResponseHeader = $http_response_header ?? [];
        $statusCode = 0;
        foreach ($httpResponseHeader as $header) {
            if (preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $header, $matches)) {
                $statusCode = (int)$matches[1];
                break;
            }
        }

        $data = json_decode($response, true);
        $this->SendDebug('FetchVehicleData', "Antwort: " . json_encode($data, JSON_PRETTY_PRINT), 0);

        if ($statusCode !== 200) {
            $fullMsg = $this->GetHttpErrorDetails($statusCode, $data);
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
        foreach ($data['responses'] as $resp) {
            $scCode = $resp['code'] ?? 0;
            if ($scCode === 200 && isset($resp['body'])) {
                $this->ProcessResponse($resp['path'], $resp['body']);
            } else {
                $hasError = true;
                $fullMsg = $this->GetHttpErrorDetails($scCode, $resp['body'] ?? $resp);
                $this->SendDebug('FetchVehicleData', "⚠️ Teilfehler für {$resp['path']}: $fullMsg", 0);
                $this->LogMessage("FetchVehicleData - Teilfehler für {$resp['path']}: $fullMsg", KL_ERROR);
            }
        }

        $this->SendDebug('FetchVehicleData', $hasError ? '⚠️ Teilweise erfolgreich.' : '✅ Alle Endpunkte erfolgreich.', 0);
        return true;
    }

    private function GetVehicleID(string $accessToken, int $retryCount = 0): ?string
    {
        // 1) Cache zuerst nutzen
        $cached = $this->ReadAttributeString('VehicleID');
        if ($cached !== '') {
            return $cached;
        }

        // 2) Erst jetzt Smartcar fragen
        $maxRetries = 1; // kleiner halten
        if ($retryCount > $maxRetries) {
            $this->SendDebug('GetVehicleID', 'Max. Wiederholungen erreicht.', 0);
            return null;
        }

        $url = 'https://api.smartcar.com/v2.0/vehicles';
        $options = [
            'http' => [
                'header'        => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method'        => 'GET',
                'ignore_errors' => true
            ]
        ];
        $res = @file_get_contents($url, false, stream_context_create($options));
        if ($res === false) {
            $this->SendDebug('GetVehicleID', 'Keine Antwort von der API!', 0);
            return null;
        }

        $data = json_decode($res, true);
        if (isset($data['statusCode']) && $data['statusCode'] === 401) {
            // Token erneuern und nochmal versuchen
            $this->RefreshAccessToken();
            $newToken = $this->ReadAttributeString('AccessToken');
            if ($newToken !== '') {
                return $this->GetVehicleID($newToken, $retryCount + 1);
            }
            return null;
        }

        if (isset($data['vehicles'][0])) {
            $vehicleId = $data['vehicles'][0];
            $this->WriteAttributeString('VehicleID', $vehicleId);
            return $vehicleId;
        }

        $this->SendDebug('GetVehicleID', 'Keine Fahrzeug-ID gefunden!', 0);
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

                foreach ($body['doors'] ?? [] as $door) {
                    $ident = ucfirst($door['type']) . 'Door';
                    $this->SetValue($ident, $door['status'] ?? 'UNKNOWN');
                }
                foreach ($body['windows'] ?? [] as $window) {
                    $ident = ucfirst($window['type']) . 'Window';
                    $this->SetValue($ident, $window['status'] ?? 'UNKNOWN');
                }
                $this->SetValue('Sunroof',      $body['sunroof'][0]['status']      ?? 'UNKNOWN');
                $this->SetValue('RearStorage',  $body['storage'][0]['status']      ?? 'UNKNOWN');
                $this->SetValue('FrontStorage', $body['storage'][1]['status']      ?? 'UNKNOWN');
                $this->SetValue('ChargingPort', $body['chargingPort'][0]['status'] ?? 'UNKNOWN');
                break;

            case '/charge/limit':
                $this->SetValue('ChargeLimit', ($body['limit'] ?? 0) * 100);
                break;

            case '/charge':
                $this->SetValue('ChargeStatus', $body['state'] ?? 'UNKNOWN');
                $this->SetValue('PluggedIn',    $body['isPluggedIn'] ?? false);
                break;

            default:
                $this->SendDebug('ProcessResponse', "Unbekannter Scope: $path", 0);
        }
    }

    // -------------------------
    // Webhook Signal → Variablen
    // -------------------------
    private function prettyName(string $ident): string {
        return preg_replace('/([a-z])([A-Z])/', '$1 $2', $ident);
    }

    // Legt Variablen bei Bedarf an und schreibt den Wert – nur wenn erlaubt.
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
            if ($profile !== '') {
                @IPS_SetVariableCustomProfile($id, $profile);
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
                            $setSafe('ChargeLimit', VARIABLETYPE_FLOAT, 'Aktuelles Ladelimit', 'SMCAR.Progress', floatval($cfg['limit']));
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
                // je nach OEM als Minuten/Sekunden – wir speichern den Rohwert
                if (isset($body['value'])) $setSafe('ChargeTimeToComplete', VARIABLETYPE_FLOAT, 'Restladezeit', '', floatval($body['value']));
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
        $postData = json_encode(['limit' => $limit]);

        $options = [
            'http' => [
                'header'        => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method'        => 'POST',
                'content'       => $postData,
                'ignore_errors' => true
            ]
        ];

        $context  = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $this->SendDebug('SetChargeLimit', 'Keine Antwort.', 0);
            return;
        }

        $data = json_decode($response, true);
        if (isset($data['statusCode']) && $data['statusCode'] !== 200) {
            $this->SendDebug('SetChargeLimit', "Fehler: " . json_encode($data), 0);
        } else {
            $this->SendDebug('SetChargeLimit', '✅ Ladelimit gesetzt.', 0);
        }
    }

    public function SetChargeStatus(bool $status)
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID   = $this->GetVehicleID($accessToken);

        if ($accessToken === '' || $vehicleID === null || $vehicleID === '') {
            $this->SendDebug('SetChargeStatus', 'Access Token oder VehicleID fehlt.', 0);
            return;
        }

        $action   = $status ? 'START' : 'STOP';
        $url      = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/charge";
        $postData = json_encode(['action' => $action]);

        $options = [
            'http' => [
                'header'        => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method'        => 'POST',
                'content'       => $postData,
                'ignore_errors' => true
            ]
        ];

        $context  = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        $data     = json_decode($response, true);
        if (isset($data['statusCode']) && $data['statusCode'] !== 200) {
            $this->SendDebug('SetChargeStatus', "Fehler: " . json_encode($data), 0);
        } else {
            $this->SendDebug('SetChargeStatus', '✅ Ladestatus gesetzt.', 0);
        }
    }

    public function SetLockStatus(bool $status)
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID   = $this->GetVehicleID($accessToken);

        if ($accessToken === '' || $vehicleID === null || $vehicleID === '') {
            $this->SendDebug('SetLockStatus', 'Access Token oder VehicleID fehlt.', 0);
            return;
        }

        $action   = $status ? 'LOCK' : 'UNLOCK';
        $url      = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/security";
        $postData = json_encode(['action' => $action]);

        $options = [
            'http' => [
                'header'        => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method'        => 'POST',
                'content'       => $postData,
                'ignore_errors' => true
            ]
        ];

        $context  = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        $data     = json_decode($response, true);
        if (isset($data['statusCode']) && $data['statusCode'] !== 200) {
            $this->SendDebug('SetLockStatus', "Fehler: " . json_encode($data), 0);
        } else {
            $this->SendDebug('SetLockStatus', '✅ Zentralverriegelung gesetzt.', 0);
        }
    }

    // -------- Einzel-Reads (bestehende Helfer) --------

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
        $options = [
            'http' => [
                'header'        => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method'        => 'GET',
                'ignore_errors' => true
            ]
        ];

        $this->SendDebug('FetchSingleEndpoint', "API-Anfrage: " . json_encode([
            'url'    => $url,
            'method' => $options['http']['method'],
            'header' => $options['http']['header'],
            'body'   => null
        ], JSON_PRETTY_PRINT), 0);

        $context  = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $this->SendDebug('FetchSingleEndpoint', '❌ Keine Antwort von der API!', 0);
            $this->LogMessage('FetchSingleEndpoint - Keine Antwort von der API!', KL_ERROR);
            return;
        }

        $statusCode = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $header, $m)) {
                $statusCode = (int)$m[1];
                break;
            }
        }

        $data = json_decode($response, true);
        $this->SendDebug('FetchSingleEndpoint', "Antwort: " . json_encode($data, JSON_PRETTY_PRINT), 0);

        if ($statusCode !== 200) {
            $msg = $this->GetHttpErrorDetails($statusCode, $data);
            $this->SendDebug('FetchSingleEndpoint', "❌ Fehler: $msg", 0);
            $this->LogMessage("FetchSingleEndpoint - $msg", KL_ERROR);
            return;
        }

        if (!empty($data)) {
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
            401 => 'Ungültiges Access Token – bitte neu verbinden.',
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
        }
        if (!IPS_VariableProfileExists('SMCAR.Health')) {
        IPS_CreateVariableProfile('SMCAR.Health', VARIABLETYPE_STRING);
        IPS_SetVariableProfileAssociation('SMCAR.Health', 'OK',      'OK',        '', -1);
        IPS_SetVariableProfileAssociation('SMCAR.Health', 'WARN',    'Warnung',   '', -1);
        IPS_SetVariableProfileAssociation('SMCAR.Health', 'ERROR',   'Fehler',    '', -1);
        IPS_SetVariableProfileAssociation('SMCAR.Health', 'UNKNOWN', 'Unbekannt', '', -1);
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
            $this->RegisterVariableFloat('SetChargeLimit', 'Ladelimit setzen', 'SMCAR.Progress', 110);
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
}
