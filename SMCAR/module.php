<?php

class Smartcar extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // ==========================================
        // Allgemeine Eigenschaften
        // ==========================================
        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('Mode', 'live');

        // OAuth Redirect URI (GET ?code=...)
        $this->RegisterPropertyBoolean('UseCustomOAuthRedirectURI', false);
        $this->RegisterPropertyString('CustomOAuthRedirectURI', ''); // komplette https-URL für OAuth

        // Webhook Callback URI (POST + VERIFY)
        $this->RegisterPropertyBoolean('UseCustomWebhookCallbackURI', false);
        $this->RegisterPropertyString('CustomWebhookCallbackURI', ''); // komplette https-URL für Webhook

        // Optional eigener Hook-Name (statt smartcar_<InstanceID>)
        $this->RegisterPropertyString('CustomHookName', '');

        // Webhook-Settings
        $this->RegisterPropertyBoolean('EnableWebhook', true);
        $this->RegisterPropertyString('MgmtToken', ''); // application_management_token (HMAC Key)

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
        $this->RegisterAttributeString('RedirectURI', '');        // effektive OAuth Redirect URI
        $this->RegisterAttributeString('WebhookCallbackURI', ''); // effektive Webhook Callback URI

        $this->RegisterTimer('TokenRefreshTimer', 0, 'SMCAR_RefreshAccessToken(' . $this->InstanceID . ');');
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Hook registrieren/aktualisieren (reagiert auf geänderten CustomHookName)
        $hookPath = $this->EnsureHookUpToDate();

        // Timer für Token-Erneuerung (alle 90 Minuten)
        $this->SetTimerInterval('TokenRefreshTimer', 90 * 60 * 1000);
        $this->SendDebug('ApplyChanges', 'Token-Erneuerungs-Timer auf 90 min gestellt.', 0);

        // Wenn Kernel bereits bereit ist, sofort erneuern
        if (IPS_GetKernelRunlevel() === KR_READY && $this->ReadAttributeString('RefreshToken') !== '') {
            $this->RefreshAccessToken();
        }

        // ==========================================
        // Effektive URIs berechnen
        // ==========================================
        // 1) OAuth Redirect URI
        $oauth = '';
        if ($this->ReadPropertyBoolean('UseCustomOAuthRedirectURI')) {
            $custom = trim($this->ReadPropertyString('CustomOAuthRedirectURI'));
            if ($custom !== '') {
                $oauth = $custom;
                $this->SendDebug('ApplyChanges', 'OAuth: Custom Redirect-URI verwendet.', 0);
            }
        }
        if ($oauth === '') {
            $oauth = $this->BuildConnectURL($hookPath);
        }
        if ($oauth === '') {
            $oauth = 'OAuth Redirect-URI konnte nicht ermittelt werden.';
            $this->SendDebug('ApplyChanges', 'OAuth Redirect-URI konnte nicht ermittelt werden.', 0);
            $this->LogMessage('ApplyChanges - OAuth Redirect-URI konnte nicht ermittelt werden.', KL_ERROR);
        }
        $this->WriteAttributeString('RedirectURI', $oauth);

        // 2) Webhook Callback URI
        $wh = '';
        if ($this->ReadPropertyBoolean('UseCustomWebhookCallbackURI')) {
            $cwh = trim($this->ReadPropertyString('CustomWebhookCallbackURI'));
            if ($cwh !== '') {
                $wh = $cwh;
                $this->SendDebug('ApplyChanges', 'Webhook: Custom Callback-URI verwendet.', 0);
            }
        }
        if ($wh === '') {
            // Fallback: gleiche Basis wie OAuth (ipmagic/Connect), aber identischer Hook-Pfad
            // So kannst du wahlweise alles über Connect fahren.
            $wh = $this->BuildConnectURL($hookPath);
        }
        if ($wh === '') {
            $wh = 'Webhook Callback-URI konnte nicht ermittelt werden.';
            $this->SendDebug('ApplyChanges', 'Webhook Callback-URI konnte nicht ermittelt werden.', 0);
            $this->LogMessage('ApplyChanges - Webhook Callback-URI konnte nicht ermittelt werden.', KL_ERROR);
        }
        $this->WriteAttributeString('WebhookCallbackURI', $wh);

        // Profile erstellen
        $this->CreateProfile();

        // Variablenregistrierung basierend auf aktivierten Scopes
        $this->UpdateVariablesBasedOnScopes();
    }

    private function BuildConnectURL(string $hookPath): string
    {
        $url = '';
        try {
            $list = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}');
            if (!empty($list)) {
                $ipsymconconnectid = $list[0];
                $connectAddress = @CC_GetUrl($ipsymconconnectid);
                if ($connectAddress && is_string($connectAddress) && $connectAddress !== '') {
                    $url = rtrim($connectAddress, '/') . $hookPath;
                }
            }
        } catch (Throwable $e) {
            $this->SendDebug('BuildConnectURL', 'Exception: ' . $e->getMessage(), 0);
        }
        return $url;
    }

    private function EnsureHookUpToDate(): string
    {
        $desired = (function () {
            $base = '/hook/';
            $custom = trim($this->ReadPropertyString('CustomHookName'));
            if ($custom === '') {
                return $base . 'smartcar_' . $this->InstanceID;
            }
            $custom = ltrim($custom, '/');
            return $base . $custom;
        })();

        $current = $this->ReadAttributeString('CurrentHook');
        if ($current === $desired && $current !== '') {
            // sicherstellen, dass im WebHook-Control ein Eintrag existiert
            $this->RegisterHookMapping($desired, $this->InstanceID);
            return $current;
        }

        // Falls ein alter Hook existiert, löschen wir dessen Mapping
        if ($current !== '' && $current !== $desired) {
            $this->RemoveHookMapping($current, $this->InstanceID);
        }

        // neues Mapping setzen
        $this->RegisterHookMapping($desired, $this->InstanceID);
        $this->WriteAttributeString('CurrentHook', $desired);
        $this->SendDebug('EnsureHookUpToDate', "Hook aktualisiert auf '$desired'", 0);
        return $desired;
    }

    private function RegisterHookMapping(string $hookPath, int $targetId): void
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) === 0) {
            $this->SendDebug('RegisterHookMapping', 'Keine WebHook-Control-Instanz gefunden.', 0);
            $this->LogMessage('RegisterHookMapping - Keine WebHook-Control-Instanz gefunden.', KL_ERROR);
            return;
        }
        $wh = $ids[0];
        $hooks = json_decode(IPS_GetProperty($wh, 'Hooks'), true) ?: [];
        foreach ($hooks as $h) {
            if ($h['Hook'] === $hookPath && $h['TargetID'] === $targetId) {
                // bereits vorhanden
                return;
            }
        }
        $hooks[] = ['Hook' => $hookPath, 'TargetID' => $targetId];
        IPS_SetProperty($wh, 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($wh);
    }

    private function RemoveHookMapping(string $hookPath, int $targetId): void
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) === 0) {
            return;
        }
        $wh = $ids[0];
        $hooks = json_decode(IPS_GetProperty($wh, 'Hooks'), true) ?: [];
        $changed = false;
        $filtered = [];
        foreach ($hooks as $h) {
            if (!($h['Hook'] === $hookPath && $h['TargetID'] === $targetId)) {
                $filtered[] = $h;
            } else {
                $changed = true;
            }
        }
        if ($changed) {
            IPS_SetProperty($wh, 'Hooks', json_encode($filtered));
            IPS_ApplyChanges($wh);
        }
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

    private function UpdateVariablesBasedOnScopes()
    {
        // Fahrzeugdetails
        if ($this->ReadPropertyBoolean('ScopeReadVehicleInfo')) {
            $this->RegisterVariableString('VehicleMake', 'Fahrzeug Hersteller', '', 1);
            $this->RegisterVariableString('VehicleModel', 'Fahrzeug Modell', '', 2);
            $this->RegisterVariableInteger('VehicleYear', 'Fahrzeug Baujahr', '', 3);
        } else {
            $this->UnregisterVariable('VehicleMake');
            $this->UnregisterVariable('VehicleModel');
            $this->UnregisterVariable('VehicleYear');
        }

        // VIN
        if ($this->ReadPropertyBoolean('ScopeReadVIN')) {
            $this->RegisterVariableString('VIN', 'Fahrgestellnummer (VIN)', '', 4);
        } else {
            $this->UnregisterVariable('VIN');
        }

        // Standort
        if ($this->ReadPropertyBoolean('ScopeReadLocation')) {
            $this->RegisterVariableFloat('Latitude', 'Breitengrad', '', 10);
            $this->RegisterVariableFloat('Longitude', 'Längengrad', '', 11);
        } else {
            $this->UnregisterVariable('Latitude');
            $this->UnregisterVariable('Longitude');
        }

        // Kilometerstand
        if ($this->ReadPropertyBoolean('ScopeReadOdometer')) {
            $this->RegisterVariableFloat('Odometer', 'Kilometerstand', 'SMCAR.Odometer', 20);
        } else {
            $this->UnregisterVariable('Odometer');
        }

        // Reifendruck
        if ($this->ReadPropertyBoolean('ScopeReadTires')) {
            $this->RegisterVariableFloat('TireFrontLeft', 'Reifendruck Vorderreifen Links', 'SMCAR.Pressure', 30);
            $this->RegisterVariableFloat('TireFrontRight', 'Reifendruck Vorderreifen Rechts', 'SMCAR.Pressure', 31);
            $this->RegisterVariableFloat('TireBackLeft', 'Reifendruck Hinterreifen Links', 'SMCAR.Pressure', 32);
            $this->RegisterVariableFloat('TireBackRight', 'Reifendruck Hinterreifen Rechts', 'SMCAR.Pressure', 33);
        } else {
            $this->UnregisterVariable('TireFrontLeft');
            $this->UnregisterVariable('TireFrontRight');
            $this->UnregisterVariable('TireBackLeft');
            $this->UnregisterVariable('TireBackRight');
        }

        // Batterie
        if ($this->ReadPropertyBoolean('ScopeReadBattery')) {
            $this->RegisterVariableFloat('BatteryLevel', 'Batterieladestand (SOC)', 'SMCAR.Progress', 40);
            $this->RegisterVariableFloat('BatteryRange', 'Reichweite Batterie', 'SMCAR.Odometer', 41);
        } else {
            $this->UnregisterVariable('BatteryRange');
            $this->UnregisterVariable('BatteryLevel');
        }

        if ($this->ReadPropertyBoolean('ScopeReadBatteryCapacity')) {
            $this->RegisterVariableFloat('BatteryCapacity', 'Batteriekapazität', '~Electricity', 50);
        } else {
            $this->UnregisterVariable('BatteryCapacity');
        }

        // Tank
        if ($this->ReadPropertyBoolean('ScopeReadFuel')) {
            $this->RegisterVariableFloat('FuelLevel', 'Tankfüllstand', 'SMCAR.Progress', 60);
            $this->RegisterVariableFloat('FuelRange', 'Reichweite Tank', 'SMCAR.Odometer', 61);
        } else {
            $this->UnregisterVariable('FuelLevel');
            $this->UnregisterVariable('FuelRange');
        }

        // Security
        if ($this->ReadPropertyBoolean('ScopeReadSecurity')) {
            $this->RegisterVariableBoolean('DoorsLocked', 'Fahrzeug verriegelt', '~Lock', 70);

            // Türen
            $this->RegisterVariableString('FrontLeftDoor', 'Vordertür links', 'SMCAR.Status', 71);
            $this->RegisterVariableString('FrontRightDoor', 'Vordertür rechts', 'SMCAR.Status', 72);
            $this->RegisterVariableString('BackLeftDoor', 'Hintentür links', 'SMCAR.Status', 73);
            $this->RegisterVariableString('BackRightDoor', 'Hintentür rechts', 'SMCAR.Status', 74);

            // Fenster
            $this->RegisterVariableString('FrontLeftWindow', 'Vorderfenster links', 'SMCAR.Status', 75);
            $this->RegisterVariableString('FrontRightWindow', 'Vorderfenster rechts', 'SMCAR.Status', 76);
            $this->RegisterVariableString('BackLeftWindow', 'Hinterfenster links', 'SMCAR.Status', 77);
            $this->RegisterVariableString('BackRightWindow', 'Hinterfenster rechts', 'SMCAR.Status', 78);

            // Schiebedach
            $this->RegisterVariableString('Sunroof', 'Schiebedach', 'SMCAR.Status', 79);

            // Stauraum
            $this->RegisterVariableString('RearStorage', 'Stauraum hinten', 'SMCAR.Status', 80);
            $this->RegisterVariableString('FrontStorage', 'Stauraum vorne', 'SMCAR.Status', 81);

            // Ladeanschluss
            $this->RegisterVariableString('ChargingPort', 'Ladeanschluss', 'SMCAR.Status', 82);
        } else {
            $this->UnregisterVariable('DoorsLocked');
            $this->UnregisterVariable('FrontLeftDoor');
            $this->UnregisterVariable('FrontRightDoor');
            $this->UnregisterVariable('BackLeftDoor');
            $this->UnregisterVariable('BackRightDoor');
            $this->UnregisterVariable('FrontLeftWindow');
            $this->UnregisterVariable('FrontRightWindow');
            $this->UnregisterVariable('BackLeftWindow');
            $this->UnregisterVariable('BackRightWindow');
            $this->UnregisterVariable('Sunroof');
            $this->UnregisterVariable('RearStorage');
            $this->UnregisterVariable('FrontStorage');
            $this->UnregisterVariable('ChargingPort');
        }

        // Ladeinformationen
        if ($this->ReadPropertyBoolean('ScopeReadChargeLimit')) {
            $this->RegisterVariableFloat('ChargeLimit', 'Aktuelles Ladelimit', 'SMCAR.Progress', 90);
        } else {
            $this->UnregisterVariable('ChargeLimit');
        }

        if ($this->ReadPropertyBoolean('ScopeReadChargeStatus')) {
            $this->RegisterVariableString('ChargeStatus', 'Ladestatus', 'SMCAR.Charge', 91);
            $this->RegisterVariableBoolean('PluggedIn', 'Ladekabel eingesteckt', '~Switch', 92);
        } else {
            $this->UnregisterVariable('ChargeStatus');
            $this->UnregisterVariable('PluggedIn');
        }

        // Ölstatus
        if ($this->ReadPropertyBoolean('ScopeReadOilLife')) {
            $this->RegisterVariableFloat('OilLife', 'Verbleibende Öl-Lebensdauer', 'SMCAR.Progress', 100);
        } else {
            $this->UnregisterVariable('OilLife');
        }

        // Ladelimit setzen
        if ($this->ReadPropertyBoolean('SetChargeLimit')) {
            $this->RegisterVariableFloat('SetChargeLimit', 'Ladelimit setzen', 'SMCAR.Progress', 110);
            $this->EnableAction('SetChargeLimit');
        } else {
            $this->UnregisterVariable('SetChargeLimit');
        }

        // Ladestatus setzen
        if ($this->ReadPropertyBoolean('SetChargeStatus')) {
            $this->RegisterVariableBoolean('SetChargeStatus', 'Ladestatus setzen', '~Switch', 120);
            $this->EnableAction('SetChargeStatus');
        } else {
            $this->UnregisterVariable('SetChargeStatus');
        }

        // Zentralverriegelung setzen
        if ($this->ReadPropertyBoolean('SetLockStatus')) {
            $this->RegisterVariableBoolean('SetLockStatus', 'Zentralverriegelung', '~Lock', 130);
            $this->EnableAction('SetLockStatus');
        } else {
            $this->UnregisterVariable('SetLockStatus');
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $effectiveOAuth = $this->ReadAttributeString('RedirectURI');
        $effectiveWH    = $this->ReadAttributeString('WebhookCallbackURI');
        $hookPath       = $this->ReadAttributeString('CurrentHook');

        $useOAuth   = $this->ReadPropertyBoolean('UseCustomOAuthRedirectURI');
        $useWebhook = $this->ReadPropertyBoolean('UseCustomWebhookCallbackURI');

        $inject = [
            [ 'type' => 'Label', 'caption' => 'Hook-Pfad: ' . $hookPath ],
            [ 'type' => 'Label', 'caption' => 'Aktuelle OAuth Redirect-URI: ' . $effectiveOAuth ],
            [ 'type' => 'CheckBox', 'name' => 'UseCustomOAuthRedirectURI', 'caption' => 'Eigene OAuth Redirect-URI verwenden' ],
            [ 'type' => 'ValidationTextBox', 'name' => 'CustomOAuthRedirectURI', 'caption' => 'Eigene OAuth Redirect-URI (z. B. https://example.tld/hook/smartcar)', 'visible' => $useOAuth ],

            [ 'type' => 'Label', 'caption' => '────────────────────────────────────────' ],

            [ 'type' => 'Label', 'caption' => 'Aktuelle Webhook Callback-URI: ' . $effectiveWH ],
            [ 'type' => 'CheckBox', 'name' => 'UseCustomWebhookCallbackURI', 'caption' => 'Eigene Webhook Callback-URI verwenden' ],
            [ 'type' => 'ValidationTextBox', 'name' => 'CustomWebhookCallbackURI', 'caption' => 'Eigene Webhook Callback-URI (z. B. https://example.tld/hook/smartcar)', 'visible' => $useWebhook ],

            [ 'type' => 'ValidationTextBox', 'name' => 'CustomHookName', 'caption' => 'Eigener Hook-Name (optional, ohne /hook/ Präfix)' ],
            [ 'type' => 'CheckBox', 'name' => 'EnableWebhook', 'caption' => 'Webhook aktivieren' ],
            [ 'type' => 'ValidationTextBox', 'name' => 'MgmtToken', 'caption' => 'Management Token (HMAC-Key) für Webhook-Verify & Signaturprüfung' ],

            [ 'type' => 'Label', 'caption' => 'Hinweis: OAuth Redirect-URI wird im Smartcar-Connect Client eingetragen. Webhook Callback-URI verwendest du für das Webhook-Objekt in Smartcar.' ],
        ];

        array_splice($form['elements'], 0, 0, $inject);

        return json_encode($form);
    }

    public function GenerateAuthURL()
    {
        $clientID = $this->ReadPropertyString('ClientID');
        $mode = $this->ReadPropertyString('Mode');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $redirectURI = $this->ReadAttributeString('RedirectURI'); // NUR OAuth!

        if (empty($clientID) || empty($clientSecret)) {
            $this->SendDebug('GenerateAuthURL', 'Fehler: Client ID oder Client Secret ist nicht gesetzt!', 0);
            return 'Fehler: Client ID oder Client Secret ist nicht gesetzt!';
        }

        // Scopes dynamisch basierend auf aktivierten Endpunkten zusammenstellen
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

        $authURL = 'https://connect.smartcar.com/oauth/authorize?' .
            'response_type=code' .
            '&client_id=' . urlencode($clientID) .
            '&redirect_uri=' . urlencode($redirectURI) .
            '&scope=' . urlencode(implode(' ', $scopes)) .
            '&state=' . bin2hex(random_bytes(8)) .
            '&mode=' . urlencode($mode);

        $this->SendDebug('GenerateAuthURL', "Generierte Auth-URL: $authURL", 0);
        return $authURL;
    }

    public function ProcessHookData()
    {
        // 1) OAuth Redirect (GET ?code=...)
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['code'])) {
            $authCode = $_GET['code'];
            $state = $_GET['state'] ?? '';

            $this->SendDebug('ProcessHookData', "OAuth Code erhalten: $authCode, State: $state", 0);

            $this->RequestAccessToken($authCode);
            echo 'Fahrzeug erfolgreich verbunden!';
            return;
        }

        // 2) Webhook (POST JSON)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->ReadPropertyBoolean('EnableWebhook')) {
                http_response_code(503);
                echo 'Webhook disabled';
                return;
            }

            $raw = file_get_contents('php://input') ?: '';
            $payload = json_decode($raw, true);

            if (!is_array($payload)) {
                http_response_code(400);
                echo 'Bad Request';
                return;
            }

            $eventType = $payload['eventType'] ?? '';
            $this->SendDebug('Webhook', 'Eingang: ' . json_encode($payload, JSON_PRETTY_PRINT), 0);

            // a) VERIFY: Challenge beantworten (ohne Signaturpflicht)
            if ($eventType === 'VERIFY') {
                $this->VerifyWebhookChallenge($payload);
                return;
            }

            // b) alle weiteren Events: Signatur prüfen
            if (!$this->VerifyPayloadSignature($raw)) {
                $this->SendDebug('Webhook', '❌ Signaturprüfung fehlgeschlagen', 0);
                http_response_code(401);
                echo 'Unauthorized';
                return;
            }

            // c) pro Fahrzeug filtern
            if (!$this->ShouldHandleVehicle($payload)) {
                $this->SendDebug('Webhook', 'Event für anderes Fahrzeug ignoriert.', 0);
                http_response_code(200);
                echo 'OK';
                return;
            }

            // d) vorerst nur Quittierung (Signals-Mapping kommt in Etappe 3)
            $this->SendDebug('Webhook', 'POST verarbeitet (ohne Mapping – folgt in Etappe 3).', 0);
            http_response_code(200);
            echo 'OK';
            return;
        }

        // Fallback
        http_response_code(405);
        echo 'Method Not Allowed';
    }

    private function VerifyWebhookChallenge(array $payload): void
    {
        $mgmtToken = $this->ReadPropertyString('MgmtToken');
        $challenge = $payload['data']['challenge'] ?? null;

        if (empty($mgmtToken) || $challenge === null) {
            http_response_code(400);
            echo 'Bad Request';
            return;
        }

        $hmac = hash_hmac('sha256', $challenge, $mgmtToken);
        header('Content-Type: application/json');
        http_response_code(200);
        echo json_encode(['challenge' => $hmac]);
    }

    private function VerifyPayloadSignature(string $rawBody): bool
    {
        $sigHeader = $_SERVER['HTTP_SC_SIGNATURE'] ?? ''; // Header: SC-Signature
        $mgmtToken = $this->ReadPropertyString('MgmtToken');
        if ($sigHeader === '' || $mgmtToken === '') {
            return false;
        }
        $calc = hash_hmac('sha256', $rawBody, $mgmtToken);
        return hash_equals($calc, $sigHeader);
    }

    private function ShouldHandleVehicle(array $payload): bool
    {
        $target = $this->ReadAttributeString('VehicleID'); // diese Instanz
        $vehId  = $payload['data']['vehicle']['id'] ?? '';
        if ($target === '' || $vehId === '') {
            // solange kein Fahrzeug gebunden ist, verarbeiten wir nicht
            return false;
        }
        return hash_equals($target, $vehId);
    }

    private function RequestAccessToken(string $authCode)
    {
        $clientID = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $redirectURI = $this->ReadAttributeString('RedirectURI'); // OAuth!

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

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        $responseData = json_decode($response, true);

        if (isset($responseData['access_token'], $responseData['refresh_token'])) {
            $this->WriteAttributeString('AccessToken', $responseData['access_token']);
            $this->WriteAttributeString('RefreshToken', $responseData['refresh_token']);
            $this->SendDebug('RequestAccessToken', 'Access und Refresh Token gespeichert!', 0);

            // Wende Änderungen an, um den Timer zu starten
            $this->ApplyChanges();
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

        $clientID = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $refreshToken = $this->ReadAttributeString('RefreshToken');

        if (empty($clientID) || empty($clientSecret) || empty($refreshToken)) {
            $this->SendDebug('RefreshAccessToken', 'Fehler: Fehlende Zugangsdaten!', 0);
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

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        $responseData = json_decode($response, true);

        if (isset($responseData['access_token'], $responseData['refresh_token'])) {
            $this->WriteAttributeString('AccessToken', $responseData['access_token']);
            $this->WriteAttributeString('RefreshToken', $responseData['refresh_token']);
            $this->SendDebug('RefreshAccessToken', 'Token erfolgreich erneuert.', 0);
        } else {
            $this->SendDebug('RefreshAccessToken', 'Token-Erneuerung fehlgeschlagen! Antwort: ' . $response, 0);
            $this->LogMessage('FetchVehicleData - Token-Erneuerung fehlgeschlagen!', KL_ERROR);
        }
    }

    public function FetchVehicleData()
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID = $this->GetVehicleID($accessToken);

        if (empty($accessToken) || empty($vehicleID)) {
            $this->SendDebug('FetchVehicleData', '❌ Access Token oder Fahrzeug-ID fehlt!', 0);
            $this->LogMessage('FetchVehicleData - Access Token oder Fahrzeug-ID fehlt!', KL_ERROR);
            return false;
        }

        // Sammle die aktivierten Endpunkte
        $endpoints = [];
        if ($this->ReadPropertyBoolean('ScopeReadVehicleInfo')) $endpoints[] = ['path' => '/'];
        if ($this->ReadPropertyBoolean('ScopeReadVIN')) $endpoints[] = ['path' => '/vin'];
        if ($this->ReadPropertyBoolean('ScopeReadLocation')) $endpoints[] = ['path' => '/location'];
        if ($this->ReadPropertyBoolean('ScopeReadTires')) $endpoints[] = ['path' => '/tires/pressure'];
        if ($this->ReadPropertyBoolean('ScopeReadOdometer')) $endpoints[] = ['path' => '/odometer'];
        if ($this->ReadPropertyBoolean('ScopeReadBattery')) $endpoints[] = ['path' => '/battery'];
        if ($this->ReadPropertyBoolean('ScopeReadBatteryCapacity')) $endpoints[] = ['path' => '/battery/capacity'];
        if ($this->ReadPropertyBoolean('ScopeReadFuel')) $endpoints[] = ['path' => '/fuel'];
        if ($this->ReadPropertyBoolean('ScopeReadSecurity')) $endpoints[] = ['path' => '/security'];
        if ($this->ReadPropertyBoolean('ScopeReadChargeLimit')) $endpoints[] = ['path' => '/charge/limit'];
        if ($this->ReadPropertyBoolean('ScopeReadChargeStatus')) $endpoints[] = ['path' => '/charge'];
        if ($this->ReadPropertyBoolean('ScopeReadOilLife')) $endpoints[] = ['path' => '/engine/oil'];

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

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->SendDebug('FetchVehicleData', '❌ Fehler: Keine Antwort von der API!', 0);
            $this->LogMessage('FetchVehicleData - Keine Antwort von der API!', KL_ERROR);
            return false;
        }

        // HTTP-Statuscode prüfen
        $httpResponseHeader = $http_response_header ?? [];
        $statusCode = 0;
        foreach ($httpResponseHeader as $header) {
            if (preg_match('#HTTP/\d+\.\d+\s+(\d+)#', $header, $matches)) {
                $statusCode = (int)$matches[1];
                break;
            }
        }

        $data = json_decode($response, true);

        // Vollständige JSON-Antwort ins Debug
        $this->SendDebug('FetchVehicleData', 'Antwort: ' . json_encode($data, JSON_PRETTY_PRINT), 0);

        if ($statusCode !== 200) {
            $fullMsg = $this->GetHttpErrorDetails($statusCode, $data);
            $this->SendDebug('FetchVehicleData', "❌ Fehler: $fullMsg", 0);
            $this->LogMessage("FetchVehicleData - $fullMsg", KL_ERROR);
            return false;
        }

        if (!isset($data['responses']) || !is_array($data['responses'])) {
            $this->SendDebug('FetchVehicleData', '❌ Unerwartete Antwortstruktur: ' . json_encode($data), 0);
            $this->LogMessage('FetchVehicleData - Unerwartete Antwortstruktur', KL_ERROR);
            return false;
        }

        $hasError = false;

        foreach ($data['responses'] as $resp) {
            $scCode = $resp['code'] ?? 0;

            if ($scCode === 200 && isset($resp['body'])) {
                // Erfolgreiche Antwort
                $this->ProcessResponse($resp['path'], $resp['body']);
                $this->SendDebug('FetchVehicleData', "✅ Erfolgreiche Teilantwort für {$resp['path']}", 0);
            } else {
                $hasError = true;
                $fullMsg = $this->GetHttpErrorDetails($scCode, $resp['body'] ?? $resp);

                $this->SendDebug('FetchVehicleData', "⚠️ Fehlerhafte Teilantwort für {$resp['path']}: $fullMsg", 0);
                $this->LogMessage("FetchVehicleData - Fehlerhafte Teilantwort für {$resp['path']}: $fullMsg", KL_ERROR);
            }
        }

        if ($hasError) {
            $this->SendDebug('FetchVehicleData', '⚠️ Ergebnis: Teilweise erfolgreich – einige Endpunkte fehlerhaft.', 0);
        } else {
            $this->SendDebug('FetchVehicleData', '✅ Ergebnis: Alle Endpunkte erfolgreich.', 0);
        }

        return true;
    }

    private function GetVehicleID(string $accessToken, int $retryCount = 0): ?string
    {
        $maxRetries = 2;

        if ($retryCount > $maxRetries) {
            $this->SendDebug('GetVehicleID', 'Maximale Anzahl von Wiederholungen erreicht. Anfrage abgebrochen.', 0);
            $this->LogMessage('GetVehicleID - Maximale Anzahl von Wiederholungen erreicht. Anfrage abgebrochen.', KL_ERROR);
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

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->SendDebug('GetVehicleID', 'Fehler: Keine Antwort von der API!', 0);
            $this->LogMessage('GetVehicleID - Keine Antwort von der API!', KL_ERROR);
            return null;
        }

        $data = json_decode($response, true);
        $this->SendDebug('GetVehicleID', 'Antwort: ' . json_encode($data), 0);

        // 401 -> Token erneuern und retry
        if (isset($data['statusCode']) && $data['statusCode'] === 401) {
            $this->SendDebug('GetVehicleID', '401: Token ungültig/fehlt. Erneuere Token...', 0);
            $this->RefreshAccessToken();
            $AccessToken = $this->ReadAttributeString('AccessToken');
            if (!empty($AccessToken)) {
                return $this->GetVehicleID($AccessToken, $retryCount + 1);
            }
            $this->SendDebug('GetVehicleID', 'Fehler: Token konnte nicht erneuert werden!', 0);
            $this->LogMessage('GetVehicleID - Token konnte nicht erneuert werden!', KL_ERROR);
            return null;
        }

        if (isset($data['vehicles'][0])) {
            return $data['vehicles'][0];
        }

        $this->SendDebug('GetVehicleID', 'Keine Fahrzeug-ID gefunden!', 0);
        $this->LogMessage('GetVehicleID - Keine Fahrzeug-ID gefunden!', KL_ERROR);
        return null;
    }

    private function ProcessResponse(string $path, array $body)
    {
        switch ($path) {
            case '/':
                $this->SetValue('VehicleMake', $body['make'] ?? '');
                $this->SetValue('VehicleModel', $body['model'] ?? '');
                $this->SetValue('VehicleYear', $body['year'] ?? 0);
                break;

            case '/vin':
                $this->SetValue('VIN', $body['vin'] ?? '');
                break;

            case '/location':
                $this->SetValue('Latitude', $body['latitude'] ?? 0.0);
                $this->SetValue('Longitude', $body['longitude'] ?? 0.0);
                break;

            case '/tires/pressure':
                $this->SetValue('TireFrontLeft', ($body['frontLeft'] ?? 0) * 0.01);
                $this->SetValue('TireFrontRight', ($body['frontRight'] ?? 0) * 0.01);
                $this->SetValue('TireBackLeft', ($body['backLeft'] ?? 0) * 0.01);
                $this->SetValue('TireBackRight', ($body['backRight'] ?? 0) * 0.01);
                break;

            case '/odometer':
                $this->SetValue('Odometer', $body['distance'] ?? 0);
                break;

            case '/battery':
                $this->SetValue('BatteryRange', $body['range'] ?? 0);
                $this->SetValue('BatteryLevel', ($body['percentRemaining'] ?? 0) * 100);
                break;

            case '/battery/capacity':
                $this->SetValue('BatteryCapacity', $body['capacity'] ?? 0);
                break;

            case '/fuel':
                $this->SetValue('FuelLevel', ($body['percentRemaining'] ?? 0) * 100);
                $this->SetValue('FuelRange', $body['range'] ?? 0);
                break;

            case '/security':
                $this->SetValue('DoorsLocked', $body['isLocked'] ?? false);

                foreach (($body['doors'] ?? []) as $door) {
                    $ident = ucfirst($door['type']) . 'Door';
                    $this->SetValue($ident, $door['status'] ?? 'UNKNOWN');
                }
                foreach (($body['windows'] ?? []) as $window) {
                    $ident = ucfirst($window['type']) . 'Window';
                    $this->SetValue($ident, $window['status'] ?? 'UNKNOWN');
                }
                $this->SetValue('Sunroof', $body['sunroof'][0]['status'] ?? 'UNKNOWN');

                foreach (($body['storage'] ?? []) as $storage) {
                    $ident = ucfirst($storage['type']) . 'Storage';
                    $this->SetValue($ident, $storage['status'] ?? 'UNKNOWN');
                }
                $this->SetValue('ChargingPort', $body['chargingPort'][0]['status'] ?? 'UNKNOWN');
                break;

            case '/charge/limit':
                $this->SetValue('ChargeLimit', ($body['limit'] ?? 0) * 100);
                break;

            case '/charge':
                $this->SetValue('ChargeStatus', $body['state'] ?? 'UNKNOWN');
                $this->SetValue('PluggedIn', $body['isPluggedIn'] ?? false);
                break;

            default:
                $this->SendDebug('ProcessResponse', "Unbekannter Scope: $path", 0);
                $this->LogMessage('ProcessResponse - Unbekannter Scope!', KL_ERROR);
        }
    }

    public function SetChargeLimit(float $limit)
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID = $this->GetVehicleID($accessToken);

        if ($limit < 0.5 || $limit > 1.0) {
            $this->SendDebug('SetChargeLimit', 'Ungültiges Limit. Es muss zwischen 0.5 und 1.0 liegen.', 0);
            $this->LogMessage('SetChargeLimit - Ungültiges Limit. Es muss zwischen 0.5 und 1.0 liegen!', KL_ERROR);
            return;
        }

        if (empty($accessToken) || empty($vehicleID)) {
            $this->SendDebug('SetChargeLimit', 'Access Token oder Fahrzeug-ID fehlt!', 0);
            $this->LogMessage('SetChargeLimit - Access Token oder Fahrzeug-ID fehlt!', KL_ERROR);
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

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->SendDebug('SetChargeLimit', 'Fehler: Keine Antwort von der API!', 0);
            $this->LogMessage('SetChargeLimit - Keine Antwort von der API!', KL_ERROR);
            return;
        }

        $data = json_decode($response, true);
        $this->SendDebug('SetChargeLimit', 'Antwort: ' . json_encode($data), 0);

        if (isset($data['statusCode']) && $data['statusCode'] !== 200) {
            $this->SendDebug('SetChargeLimit', 'Fehler beim Setzen des Ladelimits: ' . json_encode($data), 0);
            $this->LogMessage('Fehler beim Setzen des Ladelimits: ' . ($data['description'] ?? 'Unbekannter Fehler'), KL_ERROR);
        } else {
            $this->SendDebug('SetChargeLimit', 'Ladelimit erfolgreich gesetzt.', 0);
        }
    }

    public function SetChargeStatus(bool $status)
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID = $this->GetVehicleID($accessToken);

        if (empty($accessToken) || empty($vehicleID)) {
            $this->SendDebug('SetChargeStatus', 'Access Token oder Fahrzeug-ID fehlt!', 0);
            $this->LogMessage('SetChargeStatus - Access Token oder Fahrzeug-ID fehlt!', KL_ERROR);
            return;
        }

        $action = $status ? 'START' : 'STOP';

        $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/charge";
        $postData = json_encode(['action' => $action]);

        $options = [
            'http' => [
                'header'        => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method'        => 'POST',
                'content'       => $postData,
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->SendDebug('SetChargeStatus', 'Fehler: Keine Antwort von der API!', 0);
            $this->LogMessage('SetChargeStatus - Keine Antwort von der API!', KL_ERROR);
            return;
        }

        $data = json_decode($response, true);
        $this->SendDebug('SetChargeStatus', 'Antwort: ' . json_encode($data), 0);

        if (isset($data['statusCode']) && $data['statusCode'] !== 200) {
            $this->SendDebug('SetChargeStatus', 'Fehler beim Setzen des Ladestatus: ' . json_encode($data), 0);
            $this->LogMessage('Fehler beim Setzen des Ladestatus: ' . ($data['description'] ?? 'Unbekannter Fehler'), KL_ERROR);
        } else {
            $this->SendDebug('SetChargeStatus', 'Ladestatus erfolgreich gesetzt.', 0);
        }
    }

    public function SetLockStatus(bool $status)
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID = $this->GetVehicleID($accessToken);

        if (empty($accessToken) || empty($vehicleID)) {
            $this->SendDebug('SetLockStatus', 'Access Token oder Fahrzeug-ID fehlt!', 0);
            $this->LogMessage('SetLockStatus - Access Token oder Fahrzeug-ID fehlt!', KL_ERROR);
            return;
        }

        $action = $status ? 'LOCK' : 'UNLOCK';

        $url = "https://api.smartcar.com/v2.0/vehicles/$vehicleID/security";
        $postData = json_encode(['action' => $action]);

        $options = [
            'http' => [
                'header'        => "Authorization: Bearer $accessToken\r\nContent-Type: application/json\r\n",
                'method'        => 'POST',
                'content'       => $postData,
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->SendDebug('SetLockStatus', 'Fehler: Keine Antwort von der API!', 0);
            $this->LogMessage('SetLockStatus - Keine Antwort von der API!', KL_ERROR);
            return;
        }

        $data = json_decode($response, true);
        $this->SendDebug('SetLockStatus', 'Antwort: ' . json_encode($data), 0);

        if (isset($data['statusCode']) && $data['statusCode'] !== 200) {
            $this->SendDebug('SetLockStatus', 'Fehler beim Setzen der Zentralverriegelung: ' . json_encode($data), 0);
            $this->LogMessage('Fehler beim Setzen der Zentralverriegelung: ' . ($data['description'] ?? 'Unbekannter Fehler'), KL_ERROR);
        } else {
            $this->SendDebug('SetLockStatus', 'Zentralverriegelung erfolgreich gesetzt.', 0);
        }
    }

    public function FetchVehicleInfo() { $this->FetchSingleEndpoint('/'); }
    public function FetchVIN()        { $this->FetchSingleEndpoint('/vin'); }
    public function FetchLocation()   { $this->FetchSingleEndpoint('/location'); }
    public function FetchTires()      { $this->FetchSingleEndpoint('/tires/pressure'); }
    public function FetchOdometer()   { $this->FetchSingleEndpoint('/odometer'); }
    public function FetchBatteryLevel(){ $this->FetchSingleEndpoint('/battery'); }
    public function FetchBatteryCapacity(){ $this->FetchSingleEndpoint('/battery/capacity'); }
    public function FetchEngineOil()  { $this->FetchSingleEndpoint('/oil'); }
    public function FetchFuel()       { $this->FetchSingleEndpoint('/fuel'); }
    public function FetchSecurity()   { $this->FetchSingleEndpoint('/security'); }
    public function FetchChargeLimit(){ $this->FetchSingleEndpoint('/charge/limit'); }
    public function FetchChargeStatus(){ $this->FetchSingleEndpoint('/charge/status'); }

    private function FetchSingleEndpoint(string $path)
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        $vehicleID = $this->GetVehicleID($accessToken);

        if (empty($accessToken) || empty($vehicleID)) {
            $this->SendDebug('FetchSingleEndpoint', '❌ Access Token oder Fahrzeug-ID fehlt!', 0);
            $this->LogMessage('FetchSingleEndpoint - Access Token oder Fahrzeug-ID fehlt!', KL_ERROR);
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

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->SendDebug('FetchSingleEndpoint', '❌ Fehler: Keine Antwort von der API!', 0);
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
        $this->SendDebug('FetchSingleEndpoint', 'Antwort: ' . json_encode($data, JSON_PRETTY_PRINT), 0);

        if ($statusCode !== 200) {
            $msg = $this->GetHttpErrorDetails($statusCode, $data);
            $this->SendDebug('FetchSingleEndpoint', "❌ Fehler: $msg", 0);
            $this->LogMessage("FetchSingleEndpoint - $msg", KL_ERROR);
            return;
        }

        if (!empty($data)) {
            $this->ProcessResponse($path, $data);
        } else {
            $this->SendDebug('FetchSingleEndpoint', '❌ Unerwartete Antwortstruktur: ' . json_encode($data), 0);
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
            $this->SendDebug('CreateProfile', 'Profil erstellt: SMCAR.Pressure', 0);
        }

        if (!IPS_VariableProfileExists('SMCAR.Odometer')) {
            IPS_CreateVariableProfile('SMCAR.Odometer', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('SMCAR.Odometer', '', ' km');
            IPS_SetVariableProfileDigits('SMCAR.Odometer', 0);
            IPS_SetVariableProfileValues('SMCAR.Odometer', 0, 0, 1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: SMCAR.Odometer', 0);
        }

        if (!IPS_VariableProfileExists('SMCAR.Progress')) {
            IPS_CreateVariableProfile('SMCAR.Progress', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('SMCAR.Progress', '', ' %');
            IPS_SetVariableProfileDigits('SMCAR.Progress', 0);
            IPS_SetVariableProfileValues('SMCAR.Progress', 0, 100, 1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: SMCAR.Progress', 0);
        }

        if (!IPS_VariableProfileExists('SMCAR.Status')) {
            IPS_CreateVariableProfile('SMCAR.Status', VARIABLETYPE_STRING);
            IPS_SetVariableProfileAssociation('SMCAR.Status', 'OPEN', 'Offen', '', -1);
            IPS_SetVariableProfileAssociation('SMCAR.Status', 'CLOSED', 'Geschlossen', '', -1);
            IPS_SetVariableProfileAssociation('SMCAR.Status', 'UNKNOWN', 'Unbekannt', '', -1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: SMCAR.Status', 0);
        }

        if (!IPS_VariableProfileExists('SMCAR.Charge')) {
            IPS_CreateVariableProfile('SMCAR.Charge', VARIABLETYPE_STRING);
            IPS_SetVariableProfileAssociation('SMCAR.Charge', 'CHARGING', 'Laden', '', -1);
            IPS_SetVariableProfileAssociation('SMCAR.Charge', 'FULLY_CHARGED', 'Voll geladen', '', -1);
            IPS_SetVariableProfileAssociation('SMCAR.Charge', 'NOT_CHARGING', 'Lädt nicht', '', -1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: SMCAR.Charge', 0);
        }
    }
}
