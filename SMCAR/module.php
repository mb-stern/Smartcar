<?php

class Smartcar extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // === Basis ===
        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('Mode', 'live');

        // Manuelle Redirect-URI (optional). Wenn leer, nutzen wir ipmagic + Hook
        $this->RegisterPropertyString('ManualRedirectURI', '');

        // Webhook-Sicherheit
        $this->RegisterPropertyString('ManagementToken', '');           // NEU: HMAC-Key für VERIFY & Payload-Signatur
        $this->RegisterPropertyBoolean('VerifyPayloadSignature', true);  // NEU: Payload-Verify via SC-Signature

        // === Scopes ===
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

        // === Commands ===
        $this->RegisterPropertyBoolean('SetChargeLimit', false);
        $this->RegisterPropertyBoolean('SetChargeStatus', false);
        $this->RegisterPropertyBoolean('SetLockStatus', false);

        // === Attribute ===
        $this->RegisterAttributeString("CurrentHook", "");
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeString('VehicleID', '');
        $this->RegisterAttributeString('RedirectURI', '');        // Effektive Redirect-URI (manuell ODER ipmagic+Hook)
        $this->RegisterAttributeString('WebhookCallbackURI', ''); // Gleich der Redirect-URI (gleiches Ziel/Endpoint)

        // Timer
        $this->RegisterTimer('TokenRefreshTimer', 0, 'SMCAR_RefreshAccessToken(' . $this->InstanceID . ');');

        // Kernel Ready
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Hook sicherstellen (/hook/smartcar_<Instanz>)
        $hookPath = $this->RegisterHook();
        $this->SendDebug('ApplyChanges', "Hook aktiv: $hookPath", 0);

        // Token-Refresh alle 90 Minuten
        $this->SetTimerInterval('TokenRefreshTimer', 90 * 60 * 1000);

        if (IPS_GetKernelRunlevel() === KR_READY && $this->ReadAttributeString('RefreshToken') !== '') {
            $this->RefreshAccessToken();
        }

        // Redirect-URI bestimmen
        $manual = trim($this->ReadPropertyString('ManualRedirectURI'));
        if ($manual !== '') {
            // Vollständig vom User vorgegeben (muss auf diesen Hook zeigen!)
            $effectiveRedirect = $manual;
            $this->SendDebug('ApplyChanges', 'Manuelle Redirect/Webhook-URI aktiv.', 0);
        } else {
            // ipmagic + Hook
            $effectiveRedirect = $this->BuildConnectURL($hookPath);
            if ($effectiveRedirect === '') {
                $this->SendDebug('ApplyChanges', 'Connect-Adresse nicht verfügbar. Redirect/Webhook bleibt leer.', 0);
                $this->LogMessage('ApplyChanges - Connect-Adresse konnte nicht ermittelt werden.', KL_ERROR);
            } else {
                $this->SendDebug('ApplyChanges', "Redirect/Webhook automatisch: $effectiveRedirect", 0);
            }
        }

        // Redirect & Webhook-Callback auf denselben Endpoint legen
        $this->WriteAttributeString('RedirectURI', $effectiveRedirect);
        $this->WriteAttributeString('WebhookCallbackURI', $effectiveRedirect);

        // Profiles & Variablen für aktive Scopes
        $this->CreateProfile();
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

        // Aufräumen: alte/kaputte Einträge dieser Instanz entfernen
        $clean = [];
        foreach ($hooks as $h) {
            $hHook = $h['Hook'] ?? '';
            $hTarget = $h['TargetID'] ?? 0;
            if ($hTarget === $this->InstanceID) continue; // wir setzen frisch
            if (preg_match('~^/hook/https?://~i', $hHook)) continue; // kaputt
            $clean[] = $h;
        }

        // Wunschpfad hinzufügen
        $clean[] = ['Hook' => $desired, 'TargetID' => $this->InstanceID];

        IPS_SetProperty($hookInstanceID, 'Hooks', json_encode($clean));
        IPS_ApplyChanges($hookInstanceID);

        $this->WriteAttributeString('CurrentHook', $desired);
        $this->SendDebug('RegisterHook', "Hook registriert: $desired", 0);
        return $desired;
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $hookPath   = $this->ReadAttributeString('CurrentHook');
        $redirect   = $this->ReadAttributeString('RedirectURI');
        $webhookURI = $this->ReadAttributeString('WebhookCallbackURI');

        $inject = [
            ['type' => 'Label', 'caption' => 'Hook-Pfad: ' . $hookPath],
            ['type' => 'Label', 'caption' => 'Aktuelle Redirect-URI (Connect):'],
            ['type' => 'Label', 'caption' => $redirect],
            ['type' => 'Label', 'caption' => 'Aktuelle Webhook-Callback-URI (Dashboard eintragen):'],
            ['type' => 'Label', 'caption' => $webhookURI],
            [
                'type'    => 'ValidationTextBox',
                'name'    => 'ManualRedirectURI',
                'caption' => 'Manuelle Redirect/Webhook-URI (volle HTTPS-URL, optional)'
            ],
            [
                'type'    => 'ValidationTextBox',
                'name'    => 'ManagementToken',
                'caption' => 'Application Management Token (für VERIFY & SC-Signature)'
            ],
            [
                'type'    => 'CheckBox',
                'name'    => 'VerifyPayloadSignature',
                'caption' => 'Webhook-Payload-Signaturen (SC-Signature) prüfen'
            ],
            ['type' => 'Label', 'caption' => '────────────────────────────────────────'],
            ['type' => 'Label', 'caption' => 'Hinweis: Wenn die manuelle URI gesetzt ist, wird sie sowohl als Redirect- als auch als Webhook-Callback-URI verwendet.'],
        ];

        array_splice($form['elements'], 0, 0, $inject);
        return json_encode($form);
    }

    public function GenerateAuthURL()
    {
        $clientID     = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $mode         = $this->ReadPropertyString('Mode');
        $redirectURI  = $this->ReadAttributeString('RedirectURI');

        if (empty($clientID) || empty($clientSecret)) {
            $this->SendDebug('GenerateAuthURL', 'Fehler: Client ID oder Client Secret ist nicht gesetzt!', 0);
            return "Fehler: Client ID oder Client Secret ist nicht gesetzt!";
        }
        if ($redirectURI === '') {
            $this->SendDebug('GenerateAuthURL', 'Fehler: Redirect-URI ist leer!', 0);
            return "Fehler: Redirect-URI ist leer!";
        }

        $scopes = [];
        if ($this->ReadPropertyBoolean('ScopeReadVehicleInfo')) $scopes[] = 'read_vehicle_info';
        if ($this->ReadPropertyBoolean('ScopeReadLocation'))    $scopes[] = 'read_location';
        if ($this->ReadPropertyBoolean('ScopeReadOdometer'))    $scopes[] = 'read_odometer';
        if ($this->ReadPropertyBoolean('ScopeReadTires'))       $scopes[] = 'read_tires';
        if ($this->ReadPropertyBoolean('ScopeReadBattery') || $this->ReadPropertyBoolean('ScopeReadBatteryCapacity')) $scopes[] = 'read_battery';
        if ($this->ReadPropertyBoolean('ScopeReadFuel'))        $scopes[] = 'read_fuel';
        if ($this->ReadPropertyBoolean('ScopeReadSecurity'))    $scopes[] = 'read_security';
        if ($this->ReadPropertyBoolean('ScopeReadChargeLimit') || $this->ReadPropertyBoolean('ScopeReadChargeStatus')) $scopes[] = 'read_charge';
        if ($this->ReadPropertyBoolean('ScopeReadVIN'))         $scopes[] = 'read_vin';
        if ($this->ReadPropertyBoolean('ScopeReadOilLife'))     $scopes[] = 'read_engine_oil';
        if ($this->ReadPropertyBoolean('SetChargeLimit') || $this->ReadPropertyBoolean('SetChargeStatus')) $scopes[] = 'control_charge';
        if ($this->ReadPropertyBoolean('SetLockStatus'))        $scopes[] = 'control_security';

        if (empty($scopes)) {
            $this->SendDebug('GenerateAuthURL', 'Fehler: Keine Scopes ausgewählt!', 0);
            return "Fehler: Keine Scopes ausgewählt!";
        }

        $authURL = "https://connect.smartcar.com/oauth/authorize?" .
            "response_type=code" .
            "&client_id=" . urlencode($clientID) .
            "&redirect_uri=" . urlencode($redirectURI) .
            "&scope=" . urlencode(implode(' ', $scopes)) .
            "&state=" . bin2hex(random_bytes(8)) .
            "&mode=" . urlencode($mode);

        $this->SendDebug('GenerateAuthURL', "Generierte Authentifizierungs-URL: $authURL", 0);
        return $authURL;
    }

        public function ProcessHookData()
    {
        // Gemeinsamer Entry-Point für Redirect (GET) und Webhook (POST)
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'GET' && isset($_GET['code'])) {
            // === OAuth Redirect ===
            $authCode = $_GET['code'];
            $state    = $_GET['state'] ?? '';
            $this->SendDebug('ProcessHookData', "OAuth Redirect: code=$authCode state=$state", 0);
            $this->RequestAccessToken($authCode);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Fahrzeug erfolgreich verbunden!";
            return;
        }

        // === Webhook (POST) ===
        $raw = file_get_contents('php://input') ?: '';
        $headers = $this->getAllHeadersLower();
        $this->SendDebug('Webhook', 'RAW Body: ' . $raw, 0);
        $this->SendDebug('Webhook', 'Headers: ' . json_encode($headers), 0);

        $payload = json_decode($raw, true) ?: [];
        $eventType = $this->val($payload, ['eventType'], 'UNKNOWN');
        $version   = $this->val($payload, ['meta','version'], 'unknown');

        // 1) VERIFY (einmalig beim Einrichten im Dashboard)
        if (strtoupper($eventType) === 'VERIFY') {
            $challenge = $this->val($payload, ['data','challenge'], '');
            $mgmtToken = trim($this->ReadPropertyString('ManagementToken'));

            if ($challenge === '') {
                $this->SendDebug('Webhook', '❌ VERIFY ohne challenge!', 0);
                http_response_code(400);
                echo 'missing challenge';
                return;
            }
            if ($mgmtToken === '') {
                $this->SendDebug('Webhook', '❌ VERIFY: ManagementToken nicht gesetzt!', 0);
                http_response_code(400);
                echo 'management token missing';
                return;
            }

            // v4.0: HMAC-SHA256(challenge, ManagementToken), hex lower
            $hmac = hash_hmac('sha256', $challenge, $mgmtToken);
            $this->SendDebug('Webhook', "VERIFY v=$version challenge=$challenge hmac=$hmac", 0);

            header('Content-Type: application/json');
            echo json_encode(['challenge' => $hmac]);
            return;
        }

        // 2) Optional: Payload Signatur prüfen (SC-Signature)
        if ($this->ReadPropertyBoolean('VerifyPayloadSignature')) {
            $provided = $headers['sc-signature'] ?? '';
            $mgmtToken = trim($this->ReadPropertyString('ManagementToken'));
            if ($mgmtToken === '') {
                $this->SendDebug('Webhook', '⚠️ VerifyPayloadSignature aktiv, aber ManagementToken leer.', 0);
            } else {
                $expected = hash_hmac('sha256', $raw, $mgmtToken);
                $ok = $this->hashEquals($expected, $provided);
                $this->SendDebug('Webhook', "Signaturprüfung: expected=$expected provided=$provided ok=" . ($ok ? 'true' : 'false'), 0);
                if (!$ok) {
                    $this->SendDebug('Webhook', '❌ Ungültige SC-Signature – Payload verworfen.', 0);
                    http_response_code(401);
                    echo 'invalid signature';
                    return;
                }
            }
        }

        // 3) Fahrzeuge extrahieren & auf unsere VehicleID filtern
        $boundVehicle = $this->ReadAttributeString('VehicleID');
        $vehicles = $payload['vehicles'] ?? ($payload['data']['vehicles'] ?? []);
        if (!is_array($vehicles)) $vehicles = [];

        if ($boundVehicle !== '') {
            $vehicles = array_values(array_filter($vehicles, function($v) use ($boundVehicle) {
                $vid = $v['vehicleId'] ?? ($v['id'] ?? '');
                return $vid === $boundVehicle;
            }));
        }
        $this->SendDebug('Webhook', 'Gefilterte Fahrzeuge: ' . json_encode(array_map(function($v){ return $v['vehicleId'] ?? ($v['id'] ?? ''); }, $vehicles)), 0);

        if (empty($vehicles)) {
            $this->SendDebug('Webhook', 'Nichts zu tun (keine passenden Fahrzeuge).', 0);
            http_response_code(200);
            echo 'ok';
            return;
        }

        // 4) Signals je Fahrzeug verarbeiten
        foreach ($vehicles as $veh) {
            $vid = $veh['vehicleId'] ?? ($veh['id'] ?? 'unknown');
            $signals = $veh['signals'] ?? ($veh['data'] ?? $veh);

            // alles flatten, dann bekannte Keys mappen
            $flat = $this->flatten($signals);
            $this->SendDebug('Webhook', "Vehicle $vid flat-signals: " . json_encode($flat), 0);

            $this->ApplyWebhookSignals($flat);
        }

        http_response_code(200);
        echo 'ok';
    }

    // ===== Helpers =====

    private function getAllHeadersLower(): array
    {
        // getallheaders() ist nicht überall verfügbar -> fallback
        if (function_exists('getallheaders')) {
            $h = getallheaders();
        } else {
            $h = [];
            foreach ($_SERVER as $name => $value) {
                if (substr($name, 0, 5) == 'HTTP_') {
                    $key = str_replace(' ', '-', strtolower(str_replace('_', ' ', substr($name, 5))));
                    $h[$key] = $value;
                }
            }
        }
        // keys lowercased
        $out = [];
        foreach ($h as $k => $v) $out[strtolower($k)] = $v;
        return $out;
    }

    private function hashEquals(string $a, string $b): bool
    {
        // Case-insensitive Vergleich, beide hex
        $a = strtolower($a);
        $b = strtolower($b);
        if (function_exists('hash_equals')) return hash_equals($a, $b);
        if (strlen($a) !== strlen($b)) return false;
        $res = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $res |= ord($a[$i]) ^ ord($b[$i]);
        }
        return $res === 0;
    }

    private function val(array $arr, array $path, $default=null) {
        $cur = $arr;
        foreach ($path as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) return $default;
            $cur = $cur[$p];
        }
        return $cur;
    }

    private function flatten($data, string $prefix=''): array
    {
        $out = [];
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $key = ($prefix === '') ? $k : $prefix . '.' . $k;
                if (is_array($v)) {
                    $out += $this->flatten($v, $key);
                } else {
                    $out[$key] = $v;
                }
            }
        }
        return $out;
    }

    private function ApplyWebhookSignals(array $f)
    {
        // Fahrzeugdetails
        if (isset($f['vehicle.make']))  $this->SetValueSafe('VehicleMake',  (string)$f['vehicle.make']);
        if (isset($f['vehicle.model'])) $this->SetValueSafe('VehicleModel', (string)$f['vehicle.model']);
        if (isset($f['vehicle.year']))  $this->SetValueSafe('VehicleYear',  (int)$f['vehicle.year']);
        if (isset($f['vin']) || isset($f['vehicle.vin'])) {
            $this->SetValueSafe('VIN', (string)($f['vin'] ?? $f['vehicle.vin']));
        }

        // Location
        $lat = $f['location.latitude']  ?? $f['latitude']  ?? null;
        $lon = $f['location.longitude'] ?? $f['longitude'] ?? null;
        if ($lat !== null) $this->SetValueSafe('Latitude',  (float)$lat);
        if ($lon !== null) $this->SetValueSafe('Longitude', (float)$lon);

        // Odometer
        $odo = $f['odometer.distance'] ?? $f['odometer'] ?? null;
        if ($odo !== null) $this->SetValueSafe('Odometer', (float)$odo);

        // Tires (bar). Häufig in kPa -> einige Integrationen liefern bar direkt, sonst 0.01 * kPa ist nicht korrekt.
        // Wir gehen hier von bar aus. Wenn du kPa bekommst (z.B. ~240), mappe unten auf *0.01* oder /100 -> Logging hilft.
        foreach (['frontLeft','frontRight','backLeft','backRight'] as $pos) {
            $v = $f["tires.pressure.$pos"] ?? $f["tires.pressures.$pos"] ?? null;
            if ($v !== null) {
                $ident = ucfirst($pos === 'backLeft' ? 'TireBackLeft' : ($pos === 'backRight' ? 'TireBackRight' : ($pos === 'frontLeft' ? 'TireFrontLeft' : 'TireFrontRight')));
                $this->SetValueSafe($ident, (float)$v);
            }
        }

        // Battery
        $batPct = $f['battery.percentRemaining'] ?? $f['battery.level'] ?? null;
        if ($batPct !== null) $this->SetValueSafe('BatteryLevel', round((float)$batPct * ( $batPct <= 1 ? 100 : 1 ), 0));
        $batRange = $f['battery.range'] ?? $f['battery.range.distance'] ?? null;
        if ($batRange !== null) $this->SetValueSafe('BatteryRange', (float)$batRange);
        $batCap = $f['battery.capacity'] ?? null;
        if ($batCap !== null) $this->SetValueSafe('BatteryCapacity', (float)$batCap);

        // Fuel
        $fuelPct = $f['fuel.percentRemaining'] ?? $f['fuel.level'] ?? null;
        if ($fuelPct !== null) $this->SetValueSafe('FuelLevel', round((float)$fuelPct * ( $fuelPct <= 1 ? 100 : 1 ), 0));
        $fuelRange = $f['fuel.range'] ?? $f['fuel.range.distance'] ?? null;
        if ($fuelRange !== null) $this->SetValueSafe('FuelRange', (float)$fuelRange);

        // Charge
        if (isset($f['charge.state']))      $this->SetValueSafe('ChargeStatus', (string)$f['charge.state']);
        if (isset($f['charge.isPluggedIn']))$this->SetValueSafe('PluggedIn', (bool)$f['charge.isPluggedIn']);
        if (isset($f['charge.limit']))      $this->SetValueSafe('ChargeLimit', round((float)$f['charge.limit'] * ( $f['charge.limit'] <= 1 ? 100 : 1 ), 0));

        // Security / Locks (bool/string; je nach OEM kommen detailierte Türen/Fenster)
        if (isset($f['security.isLocked'])) $this->SetValueSafe('DoorsLocked', (bool)$f['security.isLocked']);

        // Wenn spezielle String-Status geliefert werden:
        foreach ([
            'FrontLeftDoor'   => 'security.doors.frontLeft',
            'FrontRightDoor'  => 'security.doors.frontRight',
            'BackLeftDoor'    => 'security.doors.backLeft',
            'BackRightDoor'   => 'security.doors.backRight',
            'FrontLeftWindow' => 'security.windows.frontLeft',
            'FrontRightWindow'=> 'security.windows.frontRight',
            'BackLeftWindow'  => 'security.windows.backLeft',
            'BackRightWindow' => 'security.windows.backRight',
            'Sunroof'         => 'security.sunroof',
            'RearStorage'     => 'security.storage.rear',
            'FrontStorage'    => 'security.storage.front',
            'ChargingPort'    => 'security.chargingPort'
        ] as $ident => $dot) {
            if (isset($f[$dot])) $this->SetValueSafe($ident, (string)$f[$dot]);
        }
    }

    private function SetValueSafe(string $ident, $value): void
    {
        if (@$this->GetIDForIdent($ident)) {
            $this->SetValue($ident, $value);
        }
    }

    private function RequestAccessToken(string $authCode)
    {
        $clientID     = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $redirectURI  = $this->ReadAttributeString('RedirectURI');

        $url = "https://auth.smartcar.com/oauth/token";
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
        $response = file_get_contents($url, false, $context);
        $data     = json_decode($response, true);

        if (isset($data['access_token'], $data['refresh_token'])) {
            $this->WriteAttributeString('AccessToken',  $data['access_token']);
            $this->WriteAttributeString('RefreshToken', $data['refresh_token']);
            $this->SendDebug('RequestAccessToken', 'Tokens gespeichert.', 0);
            $this->ApplyChanges(); // Timer etc.
        } else {
            $this->SendDebug('RequestAccessToken', 'Token-Austausch fehlgeschlagen! Antwort: ' . $response, 0);
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
            $this->SendDebug('RefreshAccessToken', 'Fehlende Zugangsdaten!', 0);
            return;
        }

        $url = "https://auth.smartcar.com/oauth/token";
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
        $response = file_get_contents($url, false, $context);
        $data     = json_decode($response, true);

        if (isset($data['access_token'], $data['refresh_token'])) {
            $this->WriteAttributeString('AccessToken',  $data['access_token']);
            $this->WriteAttributeString('RefreshToken', $data['refresh_token']);
            $this->SendDebug('RefreshAccessToken', 'Token erfolgreich erneuert.', 0);
        } else {
            $this->SendDebug('RefreshAccessToken', 'Token-Erneuerung fehlgeschlagen!', 0);
        }
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
