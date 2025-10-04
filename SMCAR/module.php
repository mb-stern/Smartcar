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
        $this->RegisterPropertyString('ManagementToken', '');           // HMAC-Key für VERIFY & Payload-Signatur
        $this->RegisterPropertyBoolean('VerifyPayloadSignature', true);  // SC-Signature prüfen (optional)

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
        $this->RegisterAttributeString('CurrentHook', '');
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeString('VehicleID', '');
        $this->RegisterAttributeString('RedirectURI', '');        // Effektive Redirect/Webhook-URI

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

        // Redirect/Webhook-URI bestimmen (identisch)
        $manual = trim($this->ReadPropertyString('ManualRedirectURI'));
        if ($manual !== '') {
            $effectiveRedirect = $manual; // vom Benutzer vorgegeben (muss auf diesen Hook zeigen)
            $this->SendDebug('ApplyChanges', "Manuelle Redirect-URI aktiv: $effectiveRedirect", 0);
        } else {
            $effectiveRedirect = $this->BuildConnectURL($hookPath); // ipmagic + Hook
            if ($effectiveRedirect === '') {
                $this->SendDebug('ApplyChanges', 'Connect-Adresse nicht verfügbar. Redirect-URI bleibt leer.', 0);
                $this->LogMessage('ApplyChanges - Connect-Adresse konnte nicht ermittelt werden.', KL_ERROR);
            } else {
                $this->SendDebug('ApplyChanges', "Redirect-URI automatisch: $effectiveRedirect", 0);
            }
        }

        $this->WriteAttributeString('RedirectURI', $effectiveRedirect);

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
        $ids = @IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}'); // Connect Control
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

        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}'); // WebHook-Control
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

        $hookPath = $this->ReadAttributeString('CurrentHook');
        $redirect = $this->ReadAttributeString('RedirectURI');

        $inject = [
            ['type' => 'Label', 'caption' => 'Hook-Pfad: ' . $hookPath],
            ['type' => 'Label', 'caption' => 'Aktuelle Redirect-URI (wird an Smartcar gesendet):'],
            ['type' => 'Label', 'caption' => $redirect],
            [
                'type'    => 'ValidationTextBox',
                'name'    => 'ManualRedirectURI',
                'caption' => 'Manuelle Redirect-URI (volle HTTPS-URL; optional)'
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
            ['type' => 'Label', 'caption' => 'Hinweis: Wenn die manuelle URI gesetzt ist, wird sie als Redirect- und Webhook-URI verwendet.'],
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

        if ($clientID === '' || $clientSecret === '') {
            $this->SendDebug('GenerateAuthURL', 'Fehler: Client ID oder Client Secret ist nicht gesetzt!', 0);
            return "Fehler: Client ID oder Client Secret ist nicht gesetzt!";
        }
        if ($redirectURI === '') {
            $this->SendDebug('GenerateAuthURL', 'Fehler: Redirect-URI ist leer!', 0);
            return "Fehler: Redirect-URI ist leer!";
        }

        $scopes = [];
        if ($this->ReadPropertyBoolean('ScopeReadVehicleInfo'))      $scopes[] = 'read_vehicle_info';
        if ($this->ReadPropertyBoolean('ScopeReadLocation'))         $scopes[] = 'read_location';
        if ($this->ReadPropertyBoolean('ScopeReadOdometer'))         $scopes[] = 'read_odometer';
        if ($this->ReadPropertyBoolean('ScopeReadTires'))            $scopes[] = 'read_tires';
        if ($this->ReadPropertyBoolean('ScopeReadBattery') || $this->ReadPropertyBoolean('ScopeReadBatteryCapacity')) $scopes[] = 'read_battery';
        if ($this->ReadPropertyBoolean('ScopeReadFuel'))             $scopes[] = 'read_fuel';
        if ($this->ReadPropertyBoolean('ScopeReadSecurity'))         $scopes[] = 'read_security';
        if ($this->ReadPropertyBoolean('ScopeReadChargeLimit') || $this->ReadPropertyBoolean('ScopeReadChargeStatus')) $scopes[] = 'read_charge';
        if ($this->ReadPropertyBoolean('ScopeReadVIN'))              $scopes[] = 'read_vin';
        if ($this->ReadPropertyBoolean('ScopeReadOilLife'))          $scopes[] = 'read_engine_oil';
        if ($this->ReadPropertyBoolean('SetChargeLimit') || $this->ReadPropertyBoolean('SetChargeStatus')) $scopes[] = 'control_charge';
        if ($this->ReadPropertyBoolean('SetLockStatus'))             $scopes[] = 'control_security';

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

    // Gemeinsamer Entry-Point für Redirect (GET) und Webhook (POST)
    public function ProcessHookData()
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // === OAuth Redirect ===
        if ($method === 'GET' && isset($_GET['code'])) {
            $authCode = $_GET['code'];
            $state    = $_GET['state'] ?? '';
            $this->SendDebug('ProcessHookData', "OAuth Redirect: code=$authCode state=$state", 0);
            $this->RequestAccessToken($authCode);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Fahrzeug erfolgreich verbunden!";
            return;
        }

        // === Webhook ===
        $raw = file_get_contents('php://input') ?: '';
        $headers = $this->getAllHeadersLower();
        $this->SendDebug('Webhook', 'RAW Body: ' . $raw, 0);
        $this->SendDebug('Webhook', 'Headers: ' . json_encode($headers), 0);

        $payload   = json_decode($raw, true) ?: [];
        $eventType = strtoupper($this->val($payload, ['eventType'], 'UNKNOWN'));
        $version   = $this->val($payload, ['meta','version'], 'unknown');

        // 1) VERIFY
        if ($eventType === 'VERIFY') {
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

            // v4: HMAC-SHA256(challenge, ManagementToken) → hex lower
            $hmac = hash_hmac('sha256', $challenge, $mgmtToken);
            $this->SendDebug('Webhook', "VERIFY v=$version challenge=$challenge hmac=$hmac", 0);

            header('Content-Type: application/json');
            echo json_encode(['challenge' => $hmac]);
            return;
        }

        // 2) Optional: Payload-Signatur prüfen (SC-Signature)
        if ($this->ReadPropertyBoolean('VerifyPayloadSignature')) {
            $provided  = $headers['sc-signature'] ?? '';
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

        // 3) Fahrzeug(e) extrahieren & auf unsere VehicleID filtern
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

            // flatten & log
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
        $out = [];
        foreach ($h as $k => $v) $out[strtolower($k)] = $v;
        return $out;
    }

    private function hashEquals(string $a, string $b): bool
    {
        $a = strtolower($a);
        $b = strtolower($b);
        if (function_exists('hash_equals')) return hash_equals($a, $b);
        if (strlen($a) !== strlen($b)) return false;
        $res = 0;
        for ($i = 0; $i < strlen($a); $i++) $res |= ord($a[$i]) ^ ord($b[$i]);
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

    private function SetValueSafe(string $ident, $value): void
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid) {
            $this->SetValue($ident, $value);
        }
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

        // Tires (Annahme: bar; Logging zeigt ggf. andere Einheit)
        $mapTire = [
            'frontLeft'  => 'TireFrontLeft',
            'frontRight' => 'TireFrontRight',
            'backLeft'   => 'TireBackLeft',
            'backRight'  => 'TireBackRight'
        ];
        foreach ($mapTire as $pos => $ident) {
            $v = $f["tires.pressure.$pos"] ?? $f["tires.pressures.$pos"] ?? null;
            if ($v !== null) $this->SetValueSafe($ident, (float)$v);
        }

        // Battery (% oder 0..1)
        $batPct = $f['battery.percentRemaining'] ?? $f['battery.level'] ?? null;
        if ($batPct !== null) {
            $v = (float)$batPct;
            if ($v <= 1.0) $v *= 100.0;
            $this->SetValueSafe('BatteryLevel', round($v, 0));
        }
        $batRange = $f['battery.range'] ?? $f['battery.range.distance'] ?? null;
        if ($batRange !== null) $this->SetValueSafe('BatteryRange', (float)$batRange);
        $batCap = $f['battery.capacity'] ?? null;
        if ($batCap !== null) $this->SetValueSafe('BatteryCapacity', (float)$batCap);

        // Fuel
        $fuelPct = $f['fuel.percentRemaining'] ?? $f['fuel.level'] ?? null;
        if ($fuelPct !== null) {
            $v = (float)$fuelPct;
            if ($v <= 1.0) $v *= 100.0;
            $this->SetValueSafe('FuelLevel', round($v, 0));
        }
        $fuelRange = $f['fuel.range'] ?? $f['fuel.range.distance'] ?? null;
        if ($fuelRange !== null) $this->SetValueSafe('FuelRange', (float)$fuelRange);

        // Charge
        if (isset($f['charge.state']))       $this->SetValueSafe('ChargeStatus', (string)$f['charge.state']);
        if (isset($f['charge.isPluggedIn'])) $this->SetValueSafe('PluggedIn', (bool)$f['charge.isPluggedIn']);
        if (isset($f['charge.limit'])) {
            $v = (float)$f['charge.limit'];
            if ($v <= 1.0) $v *= 100.0;
            $this->SetValueSafe('ChargeLimit', round($v, 0));
        }

        // Security
        if (isset($f['security.isLocked'])) $this->SetValueSafe('DoorsLocked', (bool)$f['security.isLocked']);

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

        // Oil
        $oil = $f['engine.oil.life'] ?? $f['oil.life'] ?? null;
        if ($oil !== null) {
            $v = (float)$oil;
            if ($v <= 1.0) $v *= 100.0;
            $this->SetValueSafe('OilLife', round($v, 0));
        }
    }

    // ------------------------------------------------------------------
    // FETCH WRAPPERS (Einzelendpunkte bequem abfragen)
    // ------------------------------------------------------------------

    public function FetchVehicleInfo()     { $this->FetchSingleEndpoint('/'); }
    public function FetchVIN()             { $this->FetchSingleEndpoint('/vin'); }
    public function FetchLocation()        { $this->FetchSingleEndpoint('/location'); }
    public function FetchTires()           { $this->FetchSingleEndpoint('/tires/pressure'); }
    public function FetchOdometer()        { $this->FetchSingleEndpoint('/odometer'); }
    public function FetchBatteryLevel()    { $this->FetchSingleEndpoint('/battery'); }
    public function FetchBatteryCapacity() { $this->FetchSingleEndpoint('/battery/capacity'); }
    public function FetchEngineOil()       { $this->FetchSingleEndpoint('/engine/oil'); } // <— korrigiert
    public function FetchFuel()            { $this->FetchSingleEndpoint('/fuel'); }
    public function FetchSecurity()        { $this->FetchSingleEndpoint('/security'); }
    public function FetchChargeLimit()     { $this->FetchSingleEndpoint('/charge/limit'); }
    public function FetchChargeStatus()    { $this->FetchSingleEndpoint('/charge'); }     // <— konsistent

    // ------------------------------------------------------------------
    // BATCH: Holt alle aktivierten Scopes in 1 Request
    // -> sorgt auch dafür, dass SMCAR_FetchVehicleData(...) existiert
    // ------------------------------------------------------------------
    public function FetchVehicleData()
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID   = $this->GetVehicleID($accessToken);

        if ($accessToken === '' || $vehicleID === null) {
            $this->SendDebug('FetchVehicleData', '❌ Access Token oder Fahrzeug-ID fehlt!', 0);
            return false;
        }

        $endpoints = [];
        if ($this->ReadPropertyBoolean('ScopeReadVehicleInfo'))      $endpoints[] = ["path" => "/"];
        if ($this->ReadPropertyBoolean('ScopeReadVIN'))              $endpoints[] = ["path" => "/vin"];
        if ($this->ReadPropertyBoolean('ScopeReadLocation'))         $endpoints[] = ["path" => "/location"];
        if ($this->ReadPropertyBoolean('ScopeReadTires'))            $endpoints[] = ["path" => "/tires/pressure"];
        if ($this->ReadPropertyBoolean('ScopeReadOdometer'))         $endpoints[] = ["path" => "/odometer"];
        if ($this->ReadPropertyBoolean('ScopeReadBattery'))          $endpoints[] = ["path" => "/battery"];
        if ($this->ReadPropertyBoolean('ScopeReadBatteryCapacity'))  $endpoints[] = ["path" => "/battery/capacity"];
        if ($this->ReadPropertyBoolean('ScopeReadFuel'))             $endpoints[] = ["path" => "/fuel"];
        if ($this->ReadPropertyBoolean('ScopeReadSecurity'))         $endpoints[] = ["path" => "/security"];
        if ($this->ReadPropertyBoolean('ScopeReadChargeLimit'))      $endpoints[] = ["path" => "/charge/limit"];
        if ($this->ReadPropertyBoolean('ScopeReadChargeStatus'))     $endpoints[] = ["path" => "/charge"];
        if ($this->ReadPropertyBoolean('ScopeReadOilLife'))          $endpoints[] = ["path" => "/engine/oil"];

        if (empty($endpoints)) {
            $this->SendDebug('FetchVehicleData', 'Keine Scopes aktiviert!', 0);
            return false;
        }

        $url      = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/batch";
        $postData = json_encode(["requests" => $endpoints]);

        $opts = [
            'http' => [
                'header'        => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method'        => 'POST',
                'content'       => $postData,
                'ignore_errors' => true
            ]
        ];

        $this->SendDebug('FetchVehicleData', "Request: $url\nBody: $postData", 0);

        $context  = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $this->SendDebug('FetchVehicleData', '❌ Keine Antwort von der API!', 0);
            return false;
        }

        // HTTP-Statuscode
        $statusCode = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $h, $m)) { $statusCode = (int)$m[1]; break; }
        }

        $data = json_decode($response, true);
        $this->SendDebug('FetchVehicleData', 'Response: ' . json_encode($data, JSON_PRETTY_PRINT), 0);

        if ($statusCode !== 200) {
            $this->SendDebug('FetchVehicleData', '❌ ' . $this->GetHttpErrorDetails($statusCode, $data), 0);
            return false;
        }

        if (!isset($data['responses']) || !is_array($data['responses'])) {
            $this->SendDebug('FetchVehicleData', '❌ Unerwartete Antwortstruktur!', 0);
            return false;
        }

        $hasError = false;
        foreach ($data['responses'] as $resp) {
            $code = $resp['code'] ?? 0;
            if ($code === 200 && isset($resp['body'])) {
                $this->ProcessResponse($resp['path'], $resp['body']);
            } else {
                $hasError = true;
                $this->SendDebug('FetchVehicleData', "Teilfehler {$resp['path']}: " . $this->GetHttpErrorDetails($code, $resp['body'] ?? $resp), 0);
            }
        }

        return !$hasError;
    }

    // ------------------------------------------------------------------
    // EINZEL-REQUEST: Hilfsfunktion
    // ------------------------------------------------------------------
    private function FetchSingleEndpoint(string $path)
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID   = $this->GetVehicleID($accessToken);
        if ($accessToken === '' || $vehicleID === null) {
            $this->SendDebug('FetchSingleEndpoint', '❌ Access Token oder Fahrzeug-ID fehlt!', 0);
            return;
        }

        $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID$path";
        $opts = [
            'http' => [
                'header'        => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method'        => 'GET',
                'ignore_errors' => true
            ]
        ];

        $this->SendDebug('FetchSingleEndpoint', "Request: $url", 0);

        $ctx      = stream_context_create($opts);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            $this->SendDebug('FetchSingleEndpoint', '❌ Keine Antwort von der API!', 0);
            return;
        }

        $statusCode = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $h, $m)) { $statusCode = (int)$m[1]; break; }
        }

        $data = json_decode($response, true);
        $this->SendDebug('FetchSingleEndpoint', 'Response: ' . json_encode($data, JSON_PRETTY_PRINT), 0);

        if ($statusCode !== 200) {
            $this->SendDebug('FetchSingleEndpoint', '❌ ' . $this->GetHttpErrorDetails($statusCode, $data), 0);
            return;
        }

        $this->ProcessResponse($path, $data);
    }

    // ------------------------------------------------------------------
    // VehicleID ermitteln & an Instanz binden (für Webhook-Filter)
    // ------------------------------------------------------------------
    private function GetVehicleID(string $accessToken, int $retry = 0): ?string
    {
        if ($accessToken === '') return null;

        $url = "https://api.smartcar.com/v2.0/vehicles";
        $opts = [
            'http' => [
                'header'        => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method'        => 'GET',
                'ignore_errors' => true
            ]
        ];
        $ctx = stream_context_create($opts);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            $this->SendDebug('GetVehicleID', '❌ Keine Antwort von der API!', 0);
            return null;
        }

        $data = json_decode($response, true);
        $this->SendDebug('GetVehicleID', 'Response: ' . json_encode($data), 0);

        if (isset($data['statusCode']) && $data['statusCode'] === 401 && $retry < 1) {
            $this->SendDebug('GetVehicleID', '401 → RefreshAccessToken()', 0);
            $this->RefreshAccessToken();
            $newToken = $this->ReadAttributeString('AccessToken');
            return ($newToken !== '' && $newToken !== $accessToken) ? $this->GetVehicleID($newToken, $retry + 1) : null;
        }

        if (isset($data['vehicles'][0])) {
            $vid = $data['vehicles'][0];
            $this->WriteAttributeString('VehicleID', $vid); // wichtig für Webhook-Filter
            return $vid;
        }

        return null;
    }

    // ------------------------------------------------------------------
    // API-Response → Variablen
    // ------------------------------------------------------------------
    private function ProcessResponse(string $path, array $body)
    {
        switch ($path) {
            case '/':
                $this->SetValueSafe('VehicleMake',  $body['make']  ?? '');
                $this->SetValueSafe('VehicleModel', $body['model'] ?? '');
                $this->SetValueSafe('VehicleYear',  (int)($body['year'] ?? 0));
                break;

            case '/vin':
                $this->SetValueSafe('VIN', $body['vin'] ?? '');
                break;

            case '/location':
                $this->SetValueSafe('Latitude',  (float)($body['latitude']  ?? 0.0));
                $this->SetValueSafe('Longitude', (float)($body['longitude'] ?? 0.0));
                break;

            case '/tires/pressure':
                // Annahme: Werte kommen wie gehabt in bar*100 → Faktor 0.01
                $this->SetValueSafe('TireFrontLeft',  ($body['frontLeft']  ?? 0) * 0.01);
                $this->SetValueSafe('TireFrontRight', ($body['frontRight'] ?? 0) * 0.01);
                $this->SetValueSafe('TireBackLeft',   ($body['backLeft']   ?? 0) * 0.01);
                $this->SetValueSafe('TireBackRight',  ($body['backRight']  ?? 0) * 0.01);
                break;

            case '/odometer':
                $this->SetValueSafe('Odometer', (float)($body['distance'] ?? 0));
                break;

            case '/battery':
                $this->SetValueSafe('BatteryRange', (float)($body['range'] ?? 0));
                $pct = $body['percentRemaining'] ?? null;
                if ($pct !== null) {
                    // API liefert 0..1 → in %
                    $this->SetValueSafe('BatteryLevel', round(((float)$pct) * 100));
                }
                break;

            case '/battery/capacity':
                $this->SetValueSafe('BatteryCapacity', (float)($body['capacity'] ?? 0));
                break;

            case '/fuel':
                $pct = $body['percentRemaining'] ?? null;
                if ($pct !== null) $this->SetValueSafe('FuelLevel', round(((float)$pct) * 100));
                $this->SetValueSafe('FuelRange', (float)($body['range'] ?? 0));
                break;

            case '/security':
                $this->SetValueSafe('DoorsLocked', (bool)($body['isLocked'] ?? false));

                foreach ($body['doors'] ?? [] as $door) {
                    $ident = ucfirst($door['type'] ?? '') . 'Door';     // FrontLeftDoor usw.
                    if ($ident !== 'Door') $this->SetValueSafe($ident, (string)($door['status'] ?? 'UNKNOWN'));
                }
                foreach ($body['windows'] ?? [] as $window) {
                    $ident = ucfirst($window['type'] ?? '') . 'Window'; // FrontLeftWindow usw.
                    if ($ident !== 'Window') $this->SetValueSafe($ident, (string)($window['status'] ?? 'UNKNOWN'));
                }

                if (isset($body['sunroof'][0]['status'])) {
                    $this->SetValueSafe('Sunroof', (string)$body['sunroof'][0]['status']);
                }
                foreach ($body['storage'] ?? [] as $storage) {
                    $type  = ucfirst($storage['type'] ?? '');
                    $ident = ($type !== '') ? $type . 'Storage' : '';
                    if ($ident !== '') $this->SetValueSafe($ident, (string)($storage['status'] ?? 'UNKNOWN'));
                }
                if (isset($body['chargingPort'][0]['status'])) {
                    $this->SetValueSafe('ChargingPort', (string)$body['chargingPort'][0]['status']);
                }
                break;

            case '/charge/limit':
                $lim = $body['limit'] ?? null; // 0..1
                if ($lim !== null) $this->SetValueSafe('ChargeLimit', round(((float)$lim) * 100));
                break;

            case '/charge':
                $this->SetValueSafe('ChargeStatus', (string)($body['state'] ?? 'UNKNOWN'));
                $this->SetValueSafe('PluggedIn',    (bool)($body['isPluggedIn'] ?? false));
                break;

            case '/engine/oil':
                // Manche OEMs liefern Öl-Lebensdauer in 0..1
                $life = $body['life'] ?? $body['percentRemaining'] ?? null;
                if ($life !== null) $this->SetValueSafe('OilLife', round(((float)$life) * 100));
                break;

            default:
                $this->SendDebug('ProcessResponse', "Unbekannter Pfad: $path", 0);
        }
    }

    // ------------------------------------------------------------------
    // COMMANDS (POST)
    // ------------------------------------------------------------------

    public function SetChargeLimit(float $limit)
    {
        // erwartete API: 0.5 .. 1.0 (50%..100%)
        if ($limit > 1) $limit = $limit / 100.0;
        if ($limit < 0.5 || $limit > 1.0) {
            $this->SendDebug('SetChargeLimit', 'Ungültiges Limit (0.5..1.0 erwartet).', 0);
            return;
        }

        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID   = $this->GetVehicleID($accessToken);
        if ($accessToken === '' || $vehicleID === null) {
            $this->SendDebug('SetChargeLimit', 'Access Token oder Fahrzeug-ID fehlt!', 0);
            return;
        }

        $url  = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/charge/limit";
        $body = json_encode(["limit" => $limit]);
        $opts = [
            'http' => [
                'header'        => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method'        => 'POST',
                'content'       => $body,
                'ignore_errors' => true
            ]
        ];
        $ctx      = stream_context_create($opts);
        $response = @file_get_contents($url, false, $ctx);
        $data     = json_decode($response, true);
        $this->SendDebug('SetChargeLimit', 'Response: ' . $response, 0);

        if (isset($data['statusCode']) && $data['statusCode'] !== 200) {
            $this->SendDebug('SetChargeLimit', '❌ ' . ($data['description'] ?? 'Fehler beim Setzen.'), 0);
        } else {
            $this->SendDebug('SetChargeLimit', 'OK', 0);
        }
    }

    public function SetChargeStatus(bool $status)
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID   = $this->GetVehicleID($accessToken);
        if ($accessToken === '' || $vehicleID === null) {
            $this->SendDebug('SetChargeStatus', 'Access Token oder Fahrzeug-ID fehlt!', 0);
            return;
        }

        $action = $status ? 'START' : 'STOP';
        $url    = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/charge";
        $body   = json_encode(["action" => $action]);

        $opts = [
            'http' => [
                'header'        => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method'        => 'POST',
                'content'       => $body,
                'ignore_errors' => true
            ]
        ];
        $ctx      = stream_context_create($opts);
        $response = @file_get_contents($url, false, $ctx);
        $data     = json_decode($response, true);
        $this->SendDebug('SetChargeStatus', 'Response: ' . $response, 0);

        if (isset($data['statusCode']) && $data['statusCode'] !== 200) {
            $this->SendDebug('SetChargeStatus', '❌ ' . ($data['description'] ?? 'Fehler beim Setzen.'), 0);
        } else {
            $this->SendDebug('SetChargeStatus', 'OK', 0);
        }
    }

    public function SetLockStatus(bool $status)
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID   = $this->GetVehicleID($accessToken);
        if ($accessToken === '' || $vehicleID === null) {
            $this->SendDebug('SetLockStatus', 'Access Token oder Fahrzeug-ID fehlt!', 0);
            return;
        }

        $action = $status ? 'LOCK' : 'UNLOCK';
        $url    = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/security";
        $body   = json_encode(["action" => $action]);

        $opts = [
            'http' => [
                'header'        => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method'        => 'POST',
                'content'       => $body,
                'ignore_errors' => true
            ]
        ];
        $ctx      = stream_context_create($opts);
        $response = @file_get_contents($url, false, $ctx);
        $data     = json_decode($response, true);
        $this->SendDebug('SetLockStatus', 'Response: ' . $response, 0);

        if (isset($data['statusCode']) && $data['statusCode'] !== 200) {
            $this->SendDebug('SetLockStatus', '❌ ' . ($data['description'] ?? 'Fehler beim Setzen.'), 0);
        } else {
            $this->SendDebug('SetLockStatus', 'OK', 0);
        }
    }

    // ------------------------------------------------------------------
    // RequestAction (für Variablen-Steuerung)
    // ------------------------------------------------------------------
    public function RequestAction($ident, $value)
    {
        switch ($ident) {
            case 'SetChargeLimit':
                $this->SetChargeLimit((float)$value / 100.0);
                $this->SetValue($ident, (float)$value);
                break;

            case 'SetChargeStatus':
                $this->SetChargeStatus((bool)$value);
                $this->SetValue($ident, (bool)$value);
                break;

            case 'SetLockStatus':
                $this->SetLockStatus((bool)$value);
                $this->SetValue($ident, (bool)$value);
                break;

            default:
                throw new Exception("Invalid ident: $ident");
        }
    }

    // ------------------------------------------------------------------
    // Fehlertexte hübsch
    // ------------------------------------------------------------------
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
}