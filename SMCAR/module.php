<?php

class Smartcar extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterHook('smartcar_' . $this->InstanceID);

        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('Mode', 'live');
        $this->RegisterPropertyString('ManualRedirectURI', '');

        $this->RegisterPropertyBoolean('EnableWebhook', true);
        $this->RegisterPropertyBoolean('VerifyWebhookSignature', true);
        $this->RegisterPropertyString('ManagementToken', '');

        $this->RegisterPropertyString('CompatibilityRegion', 'EUROPE');
        $this->RegisterPropertyString('SelectedSignals', '[]');

        $this->RegisterAttributeString('RedirectURI', '');
        $this->RegisterAttributeString('ApplicationAccessToken', '');
        $this->RegisterAttributeInteger('ApplicationAccessTokenExpiresAt', 0);

        $this->RegisterAttributeString('VehicleID', '');
        $this->RegisterAttributeString('VehicleUserID', '');

        $this->RegisterAttributeString('CompatibilityCache', '');
        $this->RegisterAttributeInteger('CompatibilityCacheAt', 0);

        $this->RegisterAttributeString('VehicleMake', '');
        $this->RegisterAttributeString('VehicleModel', '');
        $this->RegisterAttributeInteger('VehicleYear', 0);
        $this->RegisterAttributeString('VehiclePowertrainType', '');

        $this->RegisterTimer(
            'TokenRefreshTimer',
            0,
            'SMCAR_RequestApplicationAccessToken(' . $this->InstanceID . ');'
        );

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $hookAddress = 'smartcar_' . $this->InstanceID;
        $hookPath    = '/hook/' . $hookAddress;

        $manual = trim($this->ReadPropertyString('ManualRedirectURI'));
        $redirectURI = ($manual !== '') ? $manual : $this->BuildConnectURL($hookPath);

        $this->WriteAttributeString('RedirectURI', $redirectURI);

        $this->CreateProfiles();

        $this->SendDebug('ApplyChanges', 'RedirectURI=' . $redirectURI, 0);

        $token = $this->ReadAttributeString('ApplicationAccessToken');
        $expiresAt = $this->ReadAttributeInteger('ApplicationAccessTokenExpiresAt');

        if ($token !== '' && $expiresAt > time()) {
            $refreshIn = max(60, $expiresAt - time() - 300);
            $this->SetTimerInterval('TokenRefreshTimer', $refreshIn * 1000);
        } else {
            $this->SetTimerInterval('TokenRefreshTimer', 0);
        }
    }

    public function RequestApplicationAccessToken(): bool
    {
        $clientID     = trim($this->ReadPropertyString('ClientID'));
        $clientSecret = trim($this->ReadPropertyString('ClientSecret'));

        if ($clientID === '' || $clientSecret === '') {
            $this->SendDebug('AppToken', 'ClientID oder ClientSecret fehlt.', 0);
            return false;
        }

        $url = 'https://iam.smartcar.com/oauth2/token';

        $postData = http_build_query([
            'grant_type'    => 'client_credentials',
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

        $response = @file_get_contents($url, false, stream_context_create($opts));
        $headers  = $http_response_header ?? [];
        $code     = $this->GetStatusCodeFromHeaders($headers);
        $data     = json_decode($response ?: '', true);

        $this->SendDebug('AppToken', 'HTTP=' . $code . ' Antwort=' . ($response ?: '(leer)'), 0);

        if ($code !== 200 || !is_array($data) || empty($data['access_token'])) {
            $this->SetTimerInterval('TokenRefreshTimer', 5 * 60 * 1000);
            return false;
        }

        $expiresIn = isset($data['expires_in']) ? (int)$data['expires_in'] : 3600;
        $refreshIn = max(60, $expiresIn - 300);

        $this->WriteAttributeString('ApplicationAccessToken', (string)$data['access_token']);
        $this->WriteAttributeInteger('ApplicationAccessTokenExpiresAt', time() + $expiresIn);

        $this->SetTimerInterval('TokenRefreshTimer', $refreshIn * 1000);

        $this->SendDebug('AppToken', 'Token gespeichert, refreshIn=' . $refreshIn . 's', 0);
        return true;
    }

    private function GetApplicationAccessToken(): string
    {
        $token = $this->ReadAttributeString('ApplicationAccessToken');
        $expiresAt = $this->ReadAttributeInteger('ApplicationAccessTokenExpiresAt');

        if ($token === '' || $expiresAt <= time() + 120) {
            $this->SendDebug('AppToken', 'Token fehlt/läuft ab -> neu holen.', 0);
            $this->RequestApplicationAccessToken();
            $token = $this->ReadAttributeString('ApplicationAccessToken');
        }

        return $token;
    }

    public function GetConfigurationForm(): string
    {
        $hookAddress = 'smartcar_' . $this->InstanceID;
        $hookPath    = '/hook/' . $hookAddress;

        $manual = trim($this->ReadPropertyString('ManualRedirectURI'));
        $effectiveRedirect = ($manual !== '') ? $manual : $this->BuildConnectURL($hookPath);

        if ($effectiveRedirect === '') {
            $effectiveRedirect = $this->ReadAttributeString('RedirectURI');
        }

        $webhookText = $effectiveRedirect !== ''
            ? $effectiveRedirect
            : 'nicht verfügbar – IP-Symcon Connect prüfen oder manuelle Redirect-URI setzen';

        $tokenExpiresAt = $this->ReadAttributeInteger('ApplicationAccessTokenExpiresAt');
        $cacheAt = $this->ReadAttributeInteger('CompatibilityCacheAt');

        $form = [
            'elements' => [
                ['type' => 'Label', 'caption' => 'Webhook-URL: ' . $webhookText],
                ['type' => 'Label', 'caption' => 'Smartcar API V3 – nur Signals, keine Legacy/V2-Endpunkte'],
                ['type' => 'Label', 'caption' => 'Redirect-/Callback-URI: ' . $effectiveRedirect],

                [
                    'type' => 'ValidationTextBox',
                    'name' => 'ManualRedirectURI',
                    'caption' => 'Redirect-/Callback-URI überschreiben'
                ],

                ['type' => 'Label', 'caption' => '────────────────────────────────────────'],

                ['type' => 'ValidationTextBox', 'name' => 'ClientID', 'caption' => 'Client ID'],
                ['type' => 'ValidationTextBox', 'name' => 'ClientSecret', 'caption' => 'Client Secret'],

                [
                    'type' => 'Select',
                    'name' => 'Mode',
                    'caption' => 'Verbindungsmodus',
                    'options' => [
                        ['caption' => 'Simuliert', 'value' => 'simulated'],
                        ['caption' => 'Live', 'value' => 'live']
                    ]
                ],

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

                ['type' => 'Label', 'caption' => 'Token gültig bis: ' . ($tokenExpiresAt > 0 ? date('Y-m-d H:i:s', $tokenExpiresAt) : 'kein Token')],
                ['type' => 'Label', 'caption' => 'Compatibility Cache: ' . ($cacheAt > 0 ? date('Y-m-d H:i:s', $cacheAt) : 'leer')],

                [
                    'type' => 'List',
                    'name' => 'SelectedSignals',
                    'caption' => 'Kompatible V3-Signale auswählen',
                    'rowCount' => 20,
                    'columns' => [
                        ['caption' => '', 'name' => 'capability', 'width' => '0px', 'visible' => false],
                        ['caption' => 'Auswählen', 'name' => 'selected', 'width' => '120px', 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Gruppe', 'name' => 'group', 'width' => '180px'],
                        ['caption' => 'Name', 'name' => 'name', 'width' => '220px'],
                        ['caption' => 'Signal', 'name' => 'signal', 'width' => 'auto'],
                        ['caption' => 'Permission', 'name' => 'permission', 'width' => '160px']
                    ],
                    'values' => $this->GetSignalCheckboxValues()
                ],

                ['type' => 'Label', 'caption' => '────────────────────────────────────────'],

                ['type' => 'CheckBox', 'name' => 'EnableWebhook', 'caption' => 'Webhook-Empfang aktivieren'],
                ['type' => 'CheckBox', 'name' => 'VerifyWebhookSignature', 'caption' => 'Webhook-Signatur prüfen'],
                ['type' => 'ValidationTextBox', 'name' => 'ManagementToken', 'caption' => 'Application Management Token']
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'App-Token abrufen', 'onClick' => 'SMCAR_RequestApplicationAccessToken($id);'],
                ['type' => 'Button', 'caption' => 'Connections laden', 'onClick' => 'SMCAR_LoadConnectionV3($id);'],
                ['type' => 'Button', 'caption' => 'Compatibility aktualisieren', 'onClick' => 'SMCAR_RefreshCompatibility($id);'],
                ['type' => 'Button', 'caption' => 'Ausgewählte Signale abrufen', 'onClick' => 'SMCAR_FetchSelectedSignals($id);']
            ]
        ];

        return json_encode($form);
    }
        private function BuildConnectURL(string $hookPath): string
    {
        if ($hookPath === '' || strpos($hookPath, '/hook/') !== 0) {
            $hookPath = '/hook/' . ltrim($hookPath, '/');
        }

        $connectAddress = '';
        $ids = @IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}');

        if (!empty($ids)) {
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

    public function LoadConnectionV3(): bool
    {
        $token = $this->GetApplicationAccessToken();

        if ($token === '') {
            $this->SendDebug('ConnectionsV3', 'Kein ApplicationAccessToken vorhanden.', 0);
            return false;
        }

        $mode = $this->ReadPropertyString('Mode');

        $url = 'https://vehicle.api.smartcar.com/v3/connections'
             . '?filter[vehicle.mode]=' . urlencode($mode)
             . '&page[size]=10';

        $opts = [
            'http' => [
                'header'        => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n",
                'method'        => 'GET',
                'ignore_errors' => true
            ]
        ];

        $response = @file_get_contents($url, false, stream_context_create($opts));
        $headers  = $http_response_header ?? [];
        $code     = $this->GetStatusCodeFromHeaders($headers);
        $data     = json_decode($response ?: '', true);

        $this->SendDebug('ConnectionsV3', 'HTTP=' . $code . ' Antwort=' . ($response ?: '(leer)'), 0);

        if ($code !== 200 || !is_array($data) || empty($data['data'][0])) {
            $this->SendDebug('ConnectionsV3', 'Keine gültige Connection gefunden.', 0);
            return false;
        }

        $connection = $data['data'][0];

        $vehicleID = (string)($connection['relationships']['vehicle']['data']['id'] ?? '');
        $userID    = (string)($connection['relationships']['user']['data']['id'] ?? '');

        if ($vehicleID === '' || $userID === '') {
            $this->SendDebug(
                'ConnectionsV3',
                'VehicleID oder UserID fehlt: ' . json_encode($connection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                0
            );
            return false;
        }

        $this->WriteAttributeString('VehicleID', $vehicleID);
        $this->WriteAttributeString('VehicleUserID', $userID);

        $vehicle = $connection['attributes']['vehicle'] ?? [];

        if (is_array($vehicle)) {
            $make  = (string)($vehicle['make'] ?? '');
            $model = (string)($vehicle['model'] ?? '');
            $year  = (int)($vehicle['year'] ?? 0);
            $powertrain = (string)($vehicle['powertrainType'] ?? '');

            if ($make !== '') {
                $this->WriteAttributeString('VehicleMake', $make);
                $this->setSafe('VehicleMake', VARIABLETYPE_STRING, $make, '', 1, true, 'Fahrzeug Hersteller');
            }

            if ($model !== '') {
                $this->WriteAttributeString('VehicleModel', $model);
                $this->setSafe('VehicleModel', VARIABLETYPE_STRING, $model, '', 2, true, 'Fahrzeug Modell');
            }

            if ($year > 0) {
                $this->WriteAttributeInteger('VehicleYear', $year);
                $this->setSafe('VehicleYear', VARIABLETYPE_INTEGER, $year, '', 3, true, 'Fahrzeug Baujahr');
            }

            if ($powertrain !== '') {
                $this->WriteAttributeString('VehiclePowertrainType', $powertrain);
                $this->setSafe('PowertrainType', VARIABLETYPE_STRING, $powertrain, '', 4, true, 'Antriebsart');
            }

            $this->SendDebug(
                'ConnectionsV3/vehicle',
                json_encode([
                    'make' => $make,
                    'model' => $model,
                    'year' => $year,
                    'powertrainType' => $powertrain
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                0
            );
        }

        $this->SendDebug(
            'ConnectionsV3',
            'Connection gespeichert: vehicleID=' . $vehicleID . ', userID=' . $userID,
            0
        );

        return true;
    }

    public function RefreshCompatibility(): bool
    {
        $region = $this->ReadPropertyString('CompatibilityRegion');

        $url = 'https://compatibility.api.smartcar.com/v3/compatible-vehicles'
             . '?filter[region]=' . urlencode($region);

        $opts = [
            'http' => [
                'header'        => "Accept: application/json\r\n",
                'method'        => 'GET',
                'ignore_errors' => true
            ]
        ];

        $response = @file_get_contents($url, false, stream_context_create($opts));
        $headers  = $http_response_header ?? [];
        $code     = $this->GetStatusCodeFromHeaders($headers);
        $data     = json_decode($response ?: '', true);

        $this->SendDebug('Compatibility', 'HTTP=' . $code . ' Antwort=' . substr($response ?: '(leer)', 0, 4000), 0);

        if ($code !== 200 || !is_array($data) || !isset($data['data'])) {
            $this->SendDebug('Compatibility', 'Compatibility konnte nicht geladen werden.', 0);
            return false;
        }

        $this->WriteAttributeString(
            'CompatibilityCache',
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        $this->WriteAttributeInteger('CompatibilityCacheAt', time());

        $this->SendDebug('Compatibility', 'Compatibility Cache gespeichert.', 0);
        return true;
    }

    private function GetSignalCheckboxValues(): array
    {
        $rawCache = $this->ReadAttributeString('CompatibilityCache');
        $cache = json_decode($rawCache, true);

        $selectedRows = json_decode($this->ReadPropertyString('SelectedSignals'), true);
        if (!is_array($selectedRows)) {
            $selectedRows = [];
        }

        $selectedMap = [];
        foreach ($selectedRows as $row) {
            if (is_array($row)) {
                $cap = (string)($row['capability'] ?? $row['signal'] ?? '');
                if ($cap !== '' && (bool)($row['selected'] ?? false)) {
                    $selectedMap[$cap] = true;
                }
            } elseif (is_string($row) && $row !== '') {
                $selectedMap[$row] = true;
            }
        }

        $make       = strtolower(trim($this->ReadAttributeString('VehicleMake')));
        $model      = strtolower(trim($this->ReadAttributeString('VehicleModel')));
        $year       = $this->ReadAttributeInteger('VehicleYear');
        $powertrain = strtolower(trim($this->ReadAttributeString('VehiclePowertrainType')));

        $signals = [];
        $matchedVehicles = 0;

        if (is_array($cache['data'] ?? null)) {
            foreach ($cache['data'] as $vehicle) {
                $attributes = $vehicle['attributes'] ?? [];
                if (!is_array($attributes)) {
                    continue;
                }

                $itemMake  = strtolower(trim((string)($attributes['make'] ?? '')));
                $itemModel = strtolower(trim((string)($attributes['model'] ?? '')));
                $itemPowertrain = strtolower(trim((string)($attributes['powertrainType'] ?? '')));

                $years = $attributes['years'] ?? [];
                $yearMatches = true;

                if ($year > 0 && is_array($years) && !empty($years)) {
                    $yearMatches = in_array($year, array_map('intval', $years), true);
                }

                $makeMatches = ($make === '' || $itemMake === '' || $make === $itemMake);
                $modelMatches = ($model === '' || $itemModel === '' || $model === $itemModel);
                $powertrainMatches = ($powertrain === '' || $itemPowertrain === '' || $powertrain === $itemPowertrain);

                if (!$makeMatches || !$modelMatches || !$yearMatches || !$powertrainMatches) {
                    continue;
                }

                $matchedVehicles++;

                $capabilities = $attributes['capabilities'] ?? [];
                if (!is_array($capabilities)) {
                    continue;
                }

                foreach ($capabilities as $cap) {
                    if (($cap['type'] ?? '') !== 'signal') {
                        continue;
                    }

                    $signalCode = (string)($cap['capability'] ?? '');
                    if ($signalCode === '') {
                        continue;
                    }

                    $signals[$signalCode] = [
                        'capability' => $signalCode,
                        'selected'   => $selectedMap[$signalCode] ?? false,
                        'group'      => (string)($cap['group'] ?? ''),
                        'name'       => (string)($cap['name'] ?? $signalCode),
                        'signal'     => $signalCode,
                        'permission' => (string)($cap['permission'] ?? '')
                    ];
                }
            }
        }

        $this->SendDebug(
            'Compatibility/filter',
            'make=' . $make . ', model=' . $model . ', year=' . $year . ', powertrain=' . $powertrain .
            ', matchedVehicles=' . $matchedVehicles . ', signals=' . count($signals),
            0
        );

        uasort($signals, function ($a, $b) {
            $g = strcmp($a['group'], $b['group']);
            return $g !== 0 ? $g : strcmp($a['signal'], $b['signal']);
        });

        return array_values($signals);
    }

    public function FetchSelectedSignals(): bool
    {
        $token = $this->GetApplicationAccessToken();

        if ($token === '') {
            $this->SendDebug('SignalsV3', 'Kein ApplicationAccessToken vorhanden.', 0);
            return false;
        }

        $vehicleID = $this->ReadAttributeString('VehicleID');
        $userID    = $this->ReadAttributeString('VehicleUserID');

        if ($vehicleID === '' || $userID === '') {
            $this->SendDebug('SignalsV3', 'VehicleID/UserID fehlt -> lade Connection.', 0);

            if (!$this->LoadConnectionV3()) {
                return false;
            }

            $vehicleID = $this->ReadAttributeString('VehicleID');
            $userID    = $this->ReadAttributeString('VehicleUserID');
        }

        $signals = $this->GetSelectedSignalCodes();

        if (empty($signals)) {
            $this->SendDebug('SignalsV3', 'Keine Signale ausgewählt.', 0);
            return false;
        }

        foreach ($signals as $signalCode) {
            $this->FetchSingleSignalV3($vehicleID, $userID, $signalCode);
        }

        $this->SendDebug('SignalsV3', 'Abruf abgeschlossen. Anzahl=' . count($signals), 0);
        return true;
    }
        private function GetSelectedSignalCodes(): array
    {
        $rows = json_decode($this->ReadPropertyString('SelectedSignals'), true);

        if (!is_array($rows)) {
            return [];
        }

        $signals = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $selected = (bool)($row['selected'] ?? false);
                $code = (string)($row['capability'] ?? $row['signal'] ?? '');

                if ($selected && $code !== '') {
                    $signals[$code] = true;
                }
            } elseif (is_string($row) && $row !== '') {
                $signals[$row] = true;
            }
        }

        return array_keys($signals);
    }

    private function FetchSingleSignalV3(string $vehicleID, string $userID, string $signalCode): bool
    {
        $token = $this->GetApplicationAccessToken();

        if ($token === '') {
            $this->SendDebug('SignalV3', 'Kein ApplicationAccessToken vorhanden.', 0);
            return false;
        }

        if ($vehicleID === '' || $userID === '' || $signalCode === '') {
            $this->SendDebug(
                'SignalV3',
                'Fehlende Parameter: vehicleID=' . $vehicleID . ', userID=' . $userID . ', signal=' . $signalCode,
                0
            );
            return false;
        }

        $url = 'https://vehicle.api.smartcar.com/v3/vehicles/'
             . rawurlencode($vehicleID)
             . '/signals/'
             . rawurlencode($signalCode);

        $opts = [
            'http' => [
                'header' =>
                    "Authorization: Bearer {$token}\r\n" .
                    "sc-user-id: {$userID}\r\n" .
                    "Accept: application/json\r\n",
                'method'        => 'GET',
                'ignore_errors' => true
            ]
        ];

        $response = @file_get_contents($url, false, stream_context_create($opts));
        $headers  = $http_response_header ?? [];
        $code     = $this->GetStatusCodeFromHeaders($headers);
        $data     = json_decode($response ?: '', true);

        $this->SendDebug(
            'SignalV3',
            'Signal=' . $signalCode . ' HTTP=' . $code . ' Antwort=' . ($response ?: '(leer)'),
            0
        );

        if ($code !== 200 || !is_array($data)) {
            $this->SendDebug('SignalV3', 'Fehler bei Signal ' . $signalCode . ': HTTP=' . $code, 0);
            return false;
        }

        return $this->ProcessSignalResponseV3($data);
    }

    private function ProcessSignalResponseV3(array $data): bool
    {
        $node = $data['data'] ?? null;

        if (!is_array($node)) {
            $this->SendDebug('SignalV3', 'Antwort ohne data-Knoten.', 0);
            return false;
        }

        $attributes = $node['attributes'] ?? [];

        if (!is_array($attributes)) {
            $this->SendDebug('SignalV3', 'Antwort ohne attributes-Knoten.', 0);
            return false;
        }

        $code   = (string)($attributes['code'] ?? $node['id'] ?? '');
        $body   = $attributes['body'] ?? [];
        $status = $attributes['status'] ?? null;
        $meta   = $node['meta'] ?? [];

        if ($code === '') {
            $this->SendDebug('SignalV3', 'Signal-Code fehlt.', 0);
            return false;
        }

        if (!is_array($body)) {
            $body = [];
        }

        if ($status !== null && !is_array($status)) {
            $status = null;
        }

        $this->DebugSignalMeta($code, is_array($meta) ? $meta : []);

        $created = [];
        $skipped = [
            'COMPATIBILITY' => [],
            'PERMISSION'    => [],
            'UPSTREAM'      => [],
            'STATUS_ONLY'   => [],
            'OTHER'         => []
        ];

        $this->ApplySignal($code, $body, $status, $created, $skipped);

        if (!empty($created)) {
            $this->SendDebug(
                'SignalV3/created',
                json_encode($created, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                0
            );
        }

        $skippedOut = array_filter($skipped, fn($arr) => !empty($arr));

        if (!empty($skippedOut)) {
            $this->SendDebug(
                'SignalV3/skipped',
                json_encode($skippedOut, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                0
            );
        }

        return true;
    }

    protected function ProcessHookData(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $_SERVER['REQUEST_URI'] ?? '';
        $qs     = $_SERVER['QUERY_STRING'] ?? '';

        $this->SendDebug('Webhook', 'Request method=' . $method . ' uri=' . $uri . ' qs=' . $qs, 0);

        if (!$this->ReadPropertyBoolean('EnableWebhook')) {
            http_response_code(200);
            echo 'ignored';
            return;
        }

        if ($method !== 'POST') {
            http_response_code(200);
            echo 'OK';
            return;
        }

        $raw = file_get_contents('php://input') ?: '';
        $this->SendDebug('Webhook/raw', $raw !== '' ? $raw : '(leer)', 0);

        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            $this->SendDebug('Webhook', 'Ungültiges JSON.', 0);
            http_response_code(400);
            echo 'Bad Request';
            return;
        }

        if (!$this->VerifyWebhookPayload($raw, $payload)) {
            http_response_code(401);
            echo 'Unauthorized';
            return;
        }

        $eventType = (string)($payload['eventType'] ?? $payload['type'] ?? '');

        $this->SendDebug('Webhook', 'eventType=' . $eventType, 0);

        if ($eventType === 'VERIFY') {
            $challenge = (string)($payload['data']['challenge'] ?? $payload['challenge'] ?? '');

            if ($challenge === '') {
                http_response_code(400);
                echo 'Bad Request';
                return;
            }

            $managementToken = trim($this->ReadPropertyString('ManagementToken'));

            if ($managementToken !== '') {
                $challenge = hash_hmac('sha256', $challenge, $managementToken);
            }

            header('Content-Type: application/json');
            echo json_encode(['challenge' => $challenge]);
            return;
        }

        if ($this->HandleVehicleSignalsPayload($payload)) {
            http_response_code(200);
            echo 'ok';
            return;
        }

        $this->SendDebug('Webhook', 'Payload nicht als Signal-Event erkannt.', 0);
        http_response_code(200);
        echo 'ok';
    }

    private function HandleVehicleSignalsPayload(array $payload): bool
    {
        $signals = [];

        if (isset($payload['data']['signals']) && is_array($payload['data']['signals'])) {
            $signals = $payload['data']['signals'];
        } elseif (isset($payload['data']['signal']) && is_array($payload['data']['signal'])) {
            $signals = [$payload['data']['signal']];
        } elseif (($payload['data']['type'] ?? '') === 'signal' && isset($payload['data']['attributes'])) {
            $signals = [$payload['data']];
        }

        if (empty($signals)) {
            return false;
        }

        $selected = array_flip($this->GetSelectedSignalCodes());

        foreach ($signals as $signal) {
            $code = '';
            $body = [];
            $status = null;
            $meta = [];

            if (isset($signal['attributes']) && is_array($signal['attributes'])) {
                $attr = $signal['attributes'];
                $code = (string)($attr['code'] ?? $signal['id'] ?? '');
                $body = is_array($attr['body'] ?? null) ? $attr['body'] : [];
                $status = is_array($attr['status'] ?? null) ? $attr['status'] : null;
                $meta = is_array($signal['meta'] ?? null) ? $signal['meta'] : [];
            } else {
                $code = (string)($signal['code'] ?? '');
                $body = is_array($signal['body'] ?? null) ? $signal['body'] : [];
                $status = is_array($signal['status'] ?? null) ? $signal['status'] : null;
                $meta = is_array($signal['meta'] ?? null) ? $signal['meta'] : [];
            }

            if ($code === '') {
                continue;
            }

            if (!empty($selected) && !isset($selected[$code])) {
                $this->SendDebug('Webhook/signal', 'Ignoriert, nicht ausgewählt: ' . $code, 0);
                continue;
            }

            $this->DebugSignalMeta($code, $meta);

            $created = [];
            $skipped = [
                'COMPATIBILITY' => [],
                'PERMISSION'    => [],
                'UPSTREAM'      => [],
                'STATUS_ONLY'   => [],
                'OTHER'         => []
            ];

            $this->ApplySignal($code, $body, $status, $created, $skipped);
        }

        return true;
    }
        private function VerifyWebhookPayload(string $raw, array $payload): bool
    {
        if (!$this->ReadPropertyBoolean('VerifyWebhookSignature')) {
            $this->SendDebug('Webhook', 'Signaturprüfung deaktiviert.', 0);
            return true;
        }

        $managementToken = trim($this->ReadPropertyString('ManagementToken'));

        if ($managementToken === '') {
            $this->SendDebug('Webhook', 'Signaturprüfung aktiv, aber ManagementToken fehlt.', 0);
            return false;
        }

        if (($payload['eventType'] ?? '') === 'VERIFY') {
            return true;
        }

        $sigHeader =
            $this->getRequestHeader('SC-Signature')
            ?? $this->getRequestHeader('X-Smartcar-Signature')
            ?? '';

        if ($sigHeader === '') {
            $this->SendDebug('Webhook', 'Signatur-Header fehlt.', 0);
            return false;
        }

        $calc = hash_hmac('sha256', $raw, $managementToken);

        if (!hash_equals($calc, trim($sigHeader))) {
            $this->SendDebug('Webhook', 'Signatur ungültig. expected=' . $calc . ' received=' . $sigHeader, 0);
            return false;
        }

        $this->SendDebug('Webhook', 'Signatur OK.', 0);
        return true;
    }

    private function getRequestHeader(string $name): ?string
    {
        $target = strtolower($name);

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strtolower($k) === $target) {
                    return $v;
                }
            }
        }

        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }

        return null;
    }

    private function DebugSignalMeta(string $code, array $meta): void
    {
        $out = [];

        foreach (['requestId', 'unit', 'oemUpdatedAt', 'smartcarUpdatedAt'] as $key) {
            if (array_key_exists($key, $meta)) {
                $out[$key] = $meta[$key];
            }
        }

        foreach (['oemUpdatedAt', 'smartcarUpdatedAt'] as $key) {
            if (isset($out[$key]) && is_numeric($out[$key])) {
                $ts = (int)((int)$out[$key] / 1000);
                $out[$key . '_local'] = date('Y-m-d H:i:s', $ts);
            }
        }

        if (!empty($out)) {
            $this->SendDebug(
                'SignalMeta',
                $code . ' ' . json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                0
            );
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

    private function CreateProfiles(): void
    {
        if (!IPS_VariableProfileExists('SMCAR.Pressure')) {
            IPS_CreateVariableProfile('SMCAR.Pressure', VARIABLETYPE_FLOAT);
        }
        IPS_SetVariableProfileText('SMCAR.Pressure', '', ' bar');
        IPS_SetVariableProfileDigits('SMCAR.Pressure', 1);
        IPS_SetVariableProfileValues('SMCAR.Pressure', 0, 5, 0.1);

        if (!IPS_VariableProfileExists('SMCAR.Odometer')) {
            IPS_CreateVariableProfile('SMCAR.Odometer', VARIABLETYPE_FLOAT);
        }
        IPS_SetVariableProfileText('SMCAR.Odometer', '', ' km');
        IPS_SetVariableProfileDigits('SMCAR.Odometer', 0);
        IPS_SetVariableProfileValues('SMCAR.Odometer', 0, 0, 1);

        if (!IPS_VariableProfileExists('SMCAR.Progress')) {
            IPS_CreateVariableProfile('SMCAR.Progress', VARIABLETYPE_FLOAT);
        }
        IPS_SetVariableProfileText('SMCAR.Progress', '', ' %');
        IPS_SetVariableProfileDigits('SMCAR.Progress', 0);
        IPS_SetVariableProfileValues('SMCAR.Progress', 0, 100, 1);

        if (!IPS_VariableProfileExists('SMCAR.Status')) {
            IPS_CreateVariableProfile('SMCAR.Status', VARIABLETYPE_STRING);
        }

        $vp = IPS_GetVariableProfile('SMCAR.Status');
        foreach ($vp['Associations'] as $assoc) {
            IPS_SetVariableProfileAssociation('SMCAR.Status', $assoc['Value'], '', '', -1);
        }
        IPS_SetVariableProfileAssociation('SMCAR.Status', 'OPEN', 'Offen', '', -1);
        IPS_SetVariableProfileAssociation('SMCAR.Status', 'CLOSED', 'Geschlossen', '', -1);
        IPS_SetVariableProfileAssociation('SMCAR.Status', 'UNKNOWN', 'Unbekannt', '', -1);

        if (!IPS_VariableProfileExists('SMCAR.Charge')) {
            IPS_CreateVariableProfile('SMCAR.Charge', VARIABLETYPE_STRING);
        }

        $vp = IPS_GetVariableProfile('SMCAR.Charge');
        foreach ($vp['Associations'] as $assoc) {
            IPS_SetVariableProfileAssociation('SMCAR.Charge', $assoc['Value'], '', '', -1);
        }
        IPS_SetVariableProfileAssociation('SMCAR.Charge', 'CHARGING', 'Laden', '', -1);
        IPS_SetVariableProfileAssociation('SMCAR.Charge', 'FULLY_CHARGED', 'Voll geladen', '', -1);
        IPS_SetVariableProfileAssociation('SMCAR.Charge', 'NOT_CHARGING', 'Lädt nicht', '', -1);
        IPS_SetVariableProfileAssociation('SMCAR.Charge', 'UNKNOWN', 'Unbekannt', '', -1);

        if (!IPS_VariableProfileExists('SMCAR.Health')) {
            IPS_CreateVariableProfile('SMCAR.Health', VARIABLETYPE_STRING);
        }

        $vp = IPS_GetVariableProfile('SMCAR.Health');
        foreach ($vp['Associations'] as $assoc) {
            IPS_SetVariableProfileAssociation('SMCAR.Health', $assoc['Value'], '', '', -1);
        }
        IPS_SetVariableProfileAssociation('SMCAR.Health', 'OK', 'OK', '', -1);
        IPS_SetVariableProfileAssociation('SMCAR.Health', 'WARN', 'Warnung', '', -1);
        IPS_SetVariableProfileAssociation('SMCAR.Health', 'ERROR', 'Fehler', '', -1);
        IPS_SetVariableProfileAssociation('SMCAR.Health', 'UNKNOWN', 'Unbekannt', '', -1);
    }
        private function prettyName(string $ident): string
    {
        return trim(preg_replace('/([a-z])([A-Z])/', '$1 $2', $ident));
    }

    private function setSafe(
        string $ident,
        int $varType,
        mixed $value,
        string $profile = '',
        int $pos = 0,
        bool $createIfMissing = true,
        ?string $caption = null
    ): void {
        $id = @($this->GetIDForIdent($ident));

        if (!$id) {
            if (!$createIfMissing) {
                return;
            }

            $caption = $caption ?? $this->prettyName($ident);

            switch ($varType) {
                case VARIABLETYPE_BOOLEAN:
                    $this->RegisterVariableBoolean($ident, $caption, $profile, $pos);
                    break;

                case VARIABLETYPE_INTEGER:
                    $this->RegisterVariableInteger($ident, $caption, $profile, $pos);
                    break;

                case VARIABLETYPE_FLOAT:
                    $this->RegisterVariableFloat($ident, $caption, $profile, $pos);
                    break;

                case VARIABLETYPE_STRING:
                default:
                    $this->RegisterVariableString($ident, $caption, $profile, $pos);
                    break;
            }
        }

        $this->SetValue($ident, $value);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message !== IPS_KERNELMESSAGE) {
            return;
        }

        $runlevel = $Data[0] ?? -1;

        $this->SendDebug('Kernel', 'Runlevel=' . $runlevel, 0);

        if ($runlevel === KR_READY) {
            $token = $this->ReadAttributeString('ApplicationAccessToken');
            $expiresAt = $this->ReadAttributeInteger('ApplicationAccessTokenExpiresAt');

            if ($token === '' || $expiresAt <= time() + 120) {
                $this->SendDebug('Kernel', 'KR_READY -> App-Token neu holen.', 0);
                $this->RequestApplicationAccessToken();
            } else {
                $refreshIn = max(60, $expiresAt - time() - 300);
                $this->SetTimerInterval('TokenRefreshTimer', $refreshIn * 1000);
                $this->SendDebug('Kernel', 'KR_READY -> Token noch gültig, Timer=' . $refreshIn . 's', 0);
            }
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            default:
                throw new Exception('Invalid ident: ' . $Ident);
        }
    }

    private function ApplySignal(string $code, array $body, ?array $status, array &$created, array &$skipped): void
    {
        $defs = $this->GetSignalDefinitions();
        $key  = strtolower($code);

        if (empty($body)) {
            $skipped['STATUS_ONLY'][] = $code;
            return;
        }

        if (isset($defs[$key])) {
            $def = $defs[$key];

            if (($def['handler'] ?? '') === 'special') {
                switch ($key) {
                    case 'charge-ischarging':
                        if (isset($body['value'])) {
                            $is = (bool)$body['value'];
                            $this->setSafe('IsCharging', VARIABLETYPE_BOOLEAN, $is, '~Switch', 92, true, 'Lädt');
                            $this->setSafe('ChargeStatus', VARIABLETYPE_STRING, $is ? 'CHARGING' : 'NOT_CHARGING', 'SMCAR.Charge', 91, true, 'Ladestatus');
                        }
                        return;

                    case 'charge-timetocomplete':
                    case 'tractionbattery-chargecompletiontime':
                        if (isset($body['value'])) {
                            $this->setSafe('ChargeTimeToComplete', VARIABLETYPE_STRING, $this->FormatChargeTime($body['value']), '', 218, true, 'Fertiggeladen');
                        }
                        return;

                    case 'charge-ischargingportflapopen':
                        if (array_key_exists('isOpen', $body)) {
                            $this->setSafe('ChargingPortFlap', VARIABLETYPE_STRING, $body['isOpen'] ? 'OPEN' : 'CLOSED', 'SMCAR.Status', 216, true, 'Ladeport-Klappe');
                        }
                        return;

                    case 'location-preciselocation':
                        if (isset($body['latitude'])) {
                            $this->setSafe('Latitude', VARIABLETYPE_FLOAT, (float)$body['latitude'], '', 901, true, 'Breitengrad');
                        }
                        if (isset($body['longitude'])) {
                            $this->setSafe('Longitude', VARIABLETYPE_FLOAT, (float)$body['longitude'], '', 902, true, 'Längengrad');
                        }
                        if (isset($body['heading'])) {
                            $this->setSafe('Heading', VARIABLETYPE_FLOAT, (float)$body['heading'], '', 900, true, 'Fahrtrichtung');
                        }
                        if (isset($body['direction'])) {
                            $this->setSafe('Direction', VARIABLETYPE_STRING, strtoupper((string)$body['direction']), '', 903, true, 'Himmelsrichtung');
                        }
                        if (isset($body['locationType'])) {
                            $this->setSafe('LocationType', VARIABLETYPE_STRING, strtoupper((string)$body['locationType']), '', 904, true, 'Standort-Typ');
                        }
                        return;

                    case 'closure-doors':
                        $this->mapGridToVehicleSides($body, 'Door', 'FrontLeftDoor', 'FrontRightDoor', 'BackLeftDoor', 'BackRightDoor');
                        return;

                    case 'closure-windows':
                        $this->mapGridToVehicleSides($body, 'Window', 'FrontLeftWindow', 'FrontRightWindow', 'BackLeftWindow', 'BackRightWindow');
                        return;

                    case 'closure-sunroof':
                        if (array_key_exists('isOpen', $body)) {
                            $this->setSafe('Sunroof', VARIABLETYPE_STRING, $body['isOpen'] ? 'OPEN' : 'CLOSED', 'SMCAR.Status', 407, true, 'Schiebedach');
                        }
                        return;

                    case 'closure-enginecover':
                        if (array_key_exists('isOpen', $body)) {
                            $this->setSafe('EngineCover', VARIABLETYPE_STRING, $body['isOpen'] ? 'OPEN' : 'CLOSED', 'SMCAR.Status', 408, true, 'Motorhaube');
                        }
                        return;

                    case 'closure-fronttrunk':
                        if (array_key_exists('isOpen', $body)) {
                            $this->setSafe('FrontStorage', VARIABLETYPE_STRING, $body['isOpen'] ? 'OPEN' : 'CLOSED', 'SMCAR.Status', 409, true, 'Stauraum vorne');
                        }
                        return;

                    case 'closure-reartrunk':
                        if (array_key_exists('isOpen', $body)) {
                            $this->setSafe('RearStorage', VARIABLETYPE_STRING, $body['isOpen'] ? 'OPEN' : 'CLOSED', 'SMCAR.Status', 410, true, 'Stauraum hinten');
                        }
                        return;

                    case 'tires-pressure':
                        foreach ([
                            'frontLeft'  => ['TireFrontLeft',  'Reifendruck Vorderreifen Links',  1201],
                            'frontRight' => ['TireFrontRight', 'Reifendruck Vorderreifen Rechts', 1202],
                            'backLeft'   => ['TireBackLeft',   'Reifendruck Hinterreifen Links',  1203],
                            'backRight'  => ['TireBackRight',  'Reifendruck Hinterreifen Rechts', 1204],
                        ] as $field => [$ident, $caption, $pos]) {
                            if (isset($body[$field])) {
                                $this->setSafe($ident, VARIABLETYPE_FLOAT, (float)$body[$field] * 0.01, 'SMCAR.Pressure', $pos, true, $caption);
                            }
                        }
                        return;
                }
            }

            $field = $def['field'] ?? 'value';
            if (!array_key_exists($field, $body)) {
                $skipped['STATUS_ONLY'][] = $code;
                return;
            }

            $value = $this->ConvertValue($body[$field], $def, $body);

            $this->setSafe(
                $def['ident'],
                $def['type'],
                $value,
                $def['profile'] ?? '',
                $def['pos'] ?? 0,
                true,
                $def['caption'] ?? $def['ident']
            );

            return;
        }

        if (isset($body['value'])) {
            $value = $body['value'];
            $ident = 'Sig_' . strtoupper(preg_replace('~[^A-Za-z0-9]+~', '_', $code));

            if (is_bool($value)) {
                $this->setSafe($ident, VARIABLETYPE_BOOLEAN, $value, '~Switch');
            } elseif (is_numeric($value)) {
                $this->setSafe($ident, VARIABLETYPE_FLOAT, (float)$value);
            } else {
                $this->setSafe($ident, VARIABLETYPE_STRING, (string)$value);
            }
            return;
        }

        if (isset($body['values'])) {
            $ident = 'Sig_' . strtoupper(preg_replace('~[^A-Za-z0-9]+~', '_', $code));
            $this->setSafe($ident, VARIABLETYPE_STRING, json_encode($body['values'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return;
        }

        $skipped['STATUS_ONLY'][] = $code;
    }

    private function ConvertValue(mixed $value, array $def, array $body): mixed
    {
        switch ($def['converter'] ?? '') {
            case 'distance':
                $unit = strtolower((string)($body['unit'] ?? 'km'));
                return ($unit === 'miles') ? round((float)$value * 1.609344, 2) : (float)$value;

            case 'json':
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            case 'percent01':
                return (float)$value * 100;

            case 'upper':
                return strtoupper((string)$value);

            case 'bool':
                return (bool)$value;

            case 'int':
                return (int)$value;

            case 'float':
                return (float)$value;

            case 'string':
                return (string)$value;

            default:
                return $value;
        }
    }

    private function FormatChargeTime(mixed $value): string
    {
        $raw = str_replace(',', '.', (string)$value);

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
    }
    
    private function mapGridToVehicleSides(
        array $body,
        string $kind,
        string $fl,
        string $fr,
        string $bl,
        string $br
    ): void {
        if (!isset($body['values']) || !is_array($body['values'])) {
            return;
        }

        foreach ($body['values'] as $item) {
            $row = $item['row'] ?? null;
            $col = $item['column'] ?? null;
            $isOpen = $item['isOpen'] ?? null;

            if ($row === null || $col === null || $isOpen === null) {
                continue;
            }

            $status = $isOpen ? 'OPEN' : 'CLOSED';

            if ($row === 0 && $col === 0) {
                $this->setSafe($fl, VARIABLETYPE_STRING, $status, 'SMCAR.Status', 71);
            }

            if ($row === 0 && $col === 1) {
                $this->setSafe($fr, VARIABLETYPE_STRING, $status, 'SMCAR.Status', 72);
            }

            if ($row === 1 && $col === 0) {
                $this->setSafe($bl, VARIABLETYPE_STRING, $status, 'SMCAR.Status', 73);
            }

            if ($row === 1 && $col === 1) {
                $this->setSafe($br, VARIABLETYPE_STRING, $status, 'SMCAR.Status', 74);
            }
        }
    }

    private function DebugJsonAntwort(string $context, mixed $response, ?int $statusCode = null): void
    {
        $txt = ($response !== false && $response !== null && $response !== '') ? (string)$response : '';

        if ($txt === '') {
            $this->SendDebug($context, 'Antwort: (leer)', 0);
            return;
        }

        $decoded = json_decode($txt, true);

        if (is_array($decoded)) {
            $isError = ($statusCode !== null && $statusCode >= 400)
                || (
                    isset($decoded['statusCode'])
                    && is_numeric($decoded['statusCode'])
                    && (int)$decoded['statusCode'] >= 400
                );

            $payload = $isError
                ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $this->SendDebug($context, 'Antwort: ' . $payload, 0);
            return;
        }

        $this->SendDebug($context, 'Antwort: ' . $txt, 0);
    }

    private function GetHttpErrorDetails(int $statusCode, array $data): string
    {
        $errorText = match ($statusCode) {
            400 => 'Ungültige Anfrage an die Smartcar API.',
            401 => 'Ungültiger oder abgelaufener Application Access Token.',
            403 => 'Keine Berechtigung für diesen Signal- oder API-Endpunkt.',
            404 => 'Fahrzeug, User oder Signal nicht gefunden.',
            408 => 'Zeitüberschreitung bei der API-Anfrage.',
            429 => 'Zu viele Anfragen – Rate-Limit erreicht.',
            500, 502, 503, 504 => 'Smartcar API-Serverfehler.',
            default => 'Unbekannter HTTP-Fehler (' . $statusCode . ').'
        };

        $apiCode =
            $data['errors'][0]['code']
            ?? $data['code']
            ?? $data['body']['code']
            ?? '';

        $apiDesc =
            $data['errors'][0]['detail']
            ?? $data['errors'][0]['description']
            ?? $data['description']
            ?? $data['body']['description']
            ?? '';

        if ($apiCode !== '') {
            $errorText .= ' | Smartcar-Code: ' . $apiCode;
        }

        if ($apiDesc !== '') {
            $errorText .= ' - ' . $apiDesc;
        }

        return $errorText;
    }

    public function FetchAll(): bool
    {
        $okConnection = true;

        if (
            $this->ReadAttributeString('VehicleID') === ''
            || $this->ReadAttributeString('VehicleUserID') === ''
        ) {
            $okConnection = $this->LoadConnectionV3();
        }

        if (!$okConnection) {
            $this->SendDebug('FetchAll', 'Abbruch: Connection konnte nicht geladen werden.', 0);
            return false;
        }

        return $this->FetchSelectedSignals();
    }

    private function GetSignalDefinitions(): array
    {
        return [
            'charge-amperage' => [
                'ident' => 'ChargeAmperage',
                'caption' => 'Ladestrom',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '',
                'pos' => 200,
                'field' => 'value'
            ],
            'charge-amperagemax' => [
                'ident' => 'ChargeAmperageMax',
                'caption' => 'Max. Ladestrom',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '',
                'pos' => 201,
                'field' => 'value'
            ],
            'charge-amperagerequested' => [
                'ident' => 'ChargeAmperageRequested',
                'caption' => 'Angeforderter Ladestrom',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '',
                'pos' => 202,
                'field' => 'value'
            ],
            'charge-chargelimits' => [
                'ident' => 'ChargeLimits',
                'caption' => 'Ladelimits',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 203,
                'field' => 'values',
                'converter' => 'json'
            ],
            'charge-chargeportstatuscolor' => [
                'ident' => 'ChargingPortStatusColor',
                'caption' => 'Ladeport-Statusfarbe',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 204,
                'field' => 'value'
            ],
            'charge-chargerate' => [
                'ident' => 'ChargeRate',
                'caption' => 'Laderate',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '',
                'pos' => 205,
                'field' => 'value'
            ],
            'charge-chargerecords' => [
                'ident' => 'ChargeRecords',
                'caption' => 'Lade-Records',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 206,
                'field' => 'values',
                'converter' => 'json'
            ],
            'charge-chargerphases' => [
                'ident' => 'ChargerPhases',
                'caption' => 'Ladephasen',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '',
                'pos' => 207,
                'field' => 'value'
            ],
            'charge-chargetimers' => [
                'ident' => 'ChargeTimers',
                'caption' => 'Lade-Timer',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 208,
                'field' => 'values',
                'converter' => 'json'
            ],
            'charge-chargingconnectortype' => [
                'ident' => 'ChargingConnectorType',
                'caption' => 'Steckertyp',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 209,
                'field' => 'value'
            ],
            'charge-detailedchargingstatus' => [
                'ident' => 'ChargeStatus',
                'caption' => 'Ladestatus',
                'type' => VARIABLETYPE_STRING,
                'profile' => 'SMCAR.Charge',
                'pos' => 210,
                'field' => 'value'
            ],
            'charge-energyadded' => [
                'ident' => 'ChargeEnergyAdded',
                'caption' => 'Energie hinzugefügt',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '~Electricity',
                'pos' => 211,
                'field' => 'value'
            ],
            'charge-fastchargertype' => [
                'ident' => 'FastChargerType',
                'caption' => 'Schnelllader-Typ',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 212,
                'field' => 'value'
            ],
            'charge-ischarging' => [
                'handler' => 'special'
            ],
            'charge-ischargingcableconnected' => [
                'ident' => 'PluggedIn',
                'caption' => 'Ladekabel eingesteckt',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch',
                'pos' => 214,
                'field' => 'value'
            ],
            'charge-ischargingcablelatched' => [
                'ident' => 'IsChargingCableLatched',
                'caption' => 'Ladekabel verriegelt',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch',
                'pos' => 215,
                'field' => 'value'
            ],
            'charge-ischargingportflapopen' => [
                'handler' => 'special'
            ],
            'charge-isfastchargerpresent' => [
                'ident' => 'IsFastChargerPresent',
                'caption' => 'Schnelllader erkannt',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch',
                'pos' => 217,
                'field' => 'value'
            ],
            'charge-timetocomplete' => [
                'handler' => 'special'
            ],
            'charge-voltage' => [
                'ident' => 'ChargeVoltage',
                'caption' => 'Ladespannung',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '',
                'pos' => 219,
                'field' => 'value'
            ],
            'charge-wattage' => [
                'ident' => 'ChargeWattage',
                'caption' => 'Ladeleistung',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '~Power',
                'pos' => 220,
                'field' => 'value'
            ],

            'climate-externaltemperature' => [
                'ident' => 'ExternalTemperature',
                'caption' => 'Außentemperatur',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '~Temperature',
                'pos' => 300,
                'field' => 'value'
            ],
            'climate-internaltemperature' => [
                'ident' => 'InternalTemperature',
                'caption' => 'Innentemperatur',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '~Temperature',
                'pos' => 301,
                'field' => 'value'
            ],

            'closure-doors' => [
                'handler' => 'special'
            ],
            'closure-enginecover' => [
                'handler' => 'special'
            ],
            'closure-fronttrunk' => [
                'handler' => 'special'
            ],
            'closure-islocked' => [
                'ident' => 'DoorsLocked',
                'caption' => 'Fahrzeug verriegelt',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Lock',
                'pos' => 400,
                'field' => 'value'
            ],
            'closure-reartrunk' => [
                'handler' => 'special'
            ],
            'closure-sunroof' => [
                'handler' => 'special'
            ],
            'closure-windows' => [
                'handler' => 'special'
            ],

            'connectivitysoftware-currentfirmwareversion' => [
                'ident' => 'FirmwareVersion',
                'caption' => 'Firmware-Version',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 500,
                'field' => 'value'
            ],
            'connectivitystatus-isasleep' => [
                'ident' => 'IsAsleep',
                'caption' => 'Schlafmodus',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch',
                'pos' => 501,
                'field' => 'value'
            ],
            'connectivitystatus-isdigitalkeypaired' => [
                'ident' => 'IsDigitalKeyPaired',
                'caption' => 'Digitalschlüssel gekoppelt',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch',
                'pos' => 502,
                'field' => 'value'
            ],
            'connectivitystatus-isonline' => [
                'ident' => 'IsOnline',
                'caption' => 'Online',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch',
                'pos' => 503,
                'field' => 'value'
            ],
            'diagnostics-abs' => [
                'handler' => 'special'
            ],
            'diagnostics-brakefluid' => [
                'handler' => 'special'
            ],
            'diagnostics-checkenginelight' => [
                'handler' => 'special'
            ],
            'diagnostics-dtccount' => [
                'ident' => 'Diag_DTCCount',
                'caption' => 'Diagnose DTC Count',
                'type' => VARIABLETYPE_INTEGER,
                'profile' => '',
                'pos' => 600,
                'field' => 'value'
            ],
            'diagnostics-dtclist' => [
                'ident' => 'Diag_DTCList',
                'caption' => 'Diagnose DTC Liste',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 601,
                'field' => 'values',
                'converter' => 'json'
            ],
            'diagnostics-enginecoolanttemperature' => [
                'ident' => 'EngineCoolantTemperature',
                'caption' => 'Kühlmitteltemperatur',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '~Temperature',
                'pos' => 602,
                'field' => 'value'
            ],
            'diagnostics-engineoilpressure' => [
                'ident' => 'EngineOilPressure',
                'caption' => 'Motoröldruck',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '',
                'pos' => 603,
                'field' => 'value'
            ],
            'diagnostics-engineoiltemperature' => [
                'ident' => 'EngineOilTemperature',
                'caption' => 'Motoröltemperatur',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '~Temperature',
                'pos' => 604,
                'field' => 'value'
            ],
            'diagnostics-tirepressuremonitoring' => [
                'handler' => 'special'
            ],

            'engine-oillife' => [
                'ident' => 'OilLife',
                'caption' => 'Öl-Lebensdauer',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Progress',
                'pos' => 700,
                'field' => 'value'
            ],

            'fuel-amountremaining' => [
                'ident' => 'FuelAmountRemaining',
                'caption' => 'Kraftstoff verbleibend',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '',
                'pos' => 800,
                'field' => 'value'
            ],
            'fuel-percentremaining' => [
                'ident' => 'FuelLevel',
                'caption' => 'Tankfüllstand',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Progress',
                'pos' => 801,
                'field' => 'value'
            ],
            'fuel-range' => [
                'ident' => 'FuelRange',
                'caption' => 'Reichweite Tank',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Odometer',
                'pos' => 802,
                'field' => 'value',
                'converter' => 'distance'
            ],

            'location-heading' => [
                'ident' => 'Heading',
                'caption' => 'Fahrtrichtung',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '',
                'pos' => 900,
                'field' => 'value'
            ],
            'location-latitude' => [
                'ident' => 'Latitude',
                'caption' => 'Breitengrad',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '',
                'pos' => 901,
                'field' => 'value'
            ],
            'location-longitude' => [
                'ident' => 'Longitude',
                'caption' => 'Längengrad',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '',
                'pos' => 902,
                'field' => 'value'
            ],
            'location-preciselocation' => [
                'handler' => 'special'
            ],

            'odometer-traveleddistance' => [
                'ident' => 'Odometer',
                'caption' => 'Kilometerstand',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Odometer',
                'pos' => 1000,
                'field' => 'value',
                'converter' => 'distance'
            ],

            'tractionbattery-capacity' => [
                'ident' => 'BatteryCapacity',
                'caption' => 'Batteriekapazität',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '~Electricity',
                'pos' => 1100,
                'field' => 'value'
            ],
            'tractionbattery-chargecompletiontime' => [
                'handler' => 'special'
            ],
            'tractionbattery-distanceuntilchargerequired' => [
                'ident' => 'DistanceUntilChargeRequired',
                'caption' => 'Distanz bis Laden nötig',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Odometer',
                'pos' => 1102,
                'field' => 'value',
                'converter' => 'distance'
            ],
            'tractionbattery-ispluggedin' => [
                'ident' => 'PluggedIn',
                'caption' => 'Ladekabel eingesteckt',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch',
                'pos' => 1103,
                'field' => 'value'
            ],
            'tractionbattery-nominalcapacity' => [
                'ident' => 'BatteryCapacity',
                'caption' => 'Batteriekapazität',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '~Electricity',
                'pos' => 1104,
                'field' => 'capacity'
            ],
            'tractionbattery-nominalcapacities' => [
                'ident' => 'BatteryNominalCapacities',
                'caption' => 'Nominalkapazitäten',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 1105,
                'field' => 'values',
                'converter' => 'json'
            ],
            'tractionbattery-range' => [
                'ident' => 'BatteryRange',
                'caption' => 'Reichweite Batterie',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Odometer',
                'pos' => 1106,
                'field' => 'value',
                'converter' => 'distance'
            ],
            'tractionbattery-stateofcharge' => [
                'ident' => 'BatteryLevel',
                'caption' => 'Batterieladestand',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Progress',
                'pos' => 1107,
                'field' => 'value'
            ],
                    'tires-pressure' => [
                'handler' => 'special'
            ],
            'tires-treaddepth' => [
                'ident' => 'TireTreadDepth',
                'caption' => 'Reifenprofiltiefe',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 1200,
                'field' => 'values',
                'converter' => 'json'
            ],

            'vehicleidentification-exteriorcolor' => [
                'ident' => 'ExteriorColor',
                'caption' => 'Außenfarbe',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 1300,
                'field' => 'value'
            ],
            'vehicleidentification-make' => [
                'ident' => 'VehicleMake',
                'caption' => 'Fahrzeug Hersteller',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 1301,
                'field' => 'value'
            ],
            'vehicleidentification-model' => [
                'ident' => 'VehicleModel',
                'caption' => 'Fahrzeug Modell',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 1302,
                'field' => 'value'
            ],
            'vehicleidentification-modelyear' => [
                'ident' => 'VehicleYear',
                'caption' => 'Fahrzeug Modelljahr',
                'type' => VARIABLETYPE_INTEGER,
                'profile' => '',
                'pos' => 1303,
                'field' => 'value'
            ],
            'vehicleidentification-nickname' => [
                'ident' => 'Nickname',
                'caption' => 'Fahrzeug-Spitzname',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 1304,
                'field' => 'value'
            ],
            'vehicleidentification-packages' => [
                'ident' => 'Packages',
                'caption' => 'Pakete',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 1305,
                'field' => 'values',
                'converter' => 'json'
            ],
            'vehicleidentification-trim' => [
                'ident' => 'Trim',
                'caption' => 'Ausstattungslinie',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 1306,
                'field' => 'value'
            ],
            'vehicleidentification-vin' => [
                'ident' => 'VIN',
                'caption' => 'Fahrgestellnummer',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 1307,
                'field' => 'value'
            ],
            'vehicleidentification-year' => [
                'ident' => 'VehicleYear',
                'caption' => 'Fahrzeug Baujahr',
                'type' => VARIABLETYPE_INTEGER,
                'profile' => '',
                'pos' => 1308,
                'field' => 'value'
            ],

            'vehiclemotion-acceleration' => [
                'ident' => 'Acceleration',
                'caption' => 'Beschleunigung',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '',
                'pos' => 1400,
                'field' => 'value'
            ],
            'vehiclemotion-acceleratorpedalposition' => [
                'ident' => 'AcceleratorPedalPosition',
                'caption' => 'Gaspedalstellung',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Progress',
                'pos' => 1401,
                'field' => 'value'
            ],
            'vehiclemotion-brakepedalstatus' => [
                'ident' => 'BrakePedalStatus',
                'caption' => 'Bremspedalstatus',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 1402,
                'field' => 'value'
            ],
            'vehiclemotion-drivemode' => [
                'ident' => 'DriveMode',
                'caption' => 'Fahrmodus',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 1403,
                'field' => 'value'
            ],
            'vehiclemotion-gearposition' => [
                'ident' => 'GearPosition',
                'caption' => 'Gangwahl',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 1404,
                'field' => 'value'
            ],
            'vehiclemotion-ignitionstatus' => [
                'ident' => 'IgnitionStatus',
                'caption' => 'Zündungsstatus',
                'type' => VARIABLETYPE_STRING,
                'profile' => '',
                'pos' => 1405,
                'field' => 'value'
            ],
            'vehiclemotion-isparked' => [
                'ident' => 'IsParked',
                'caption' => 'Geparkt',
                'type' => VARIABLETYPE_BOOLEAN,
                'profile' => '~Switch',
                'pos' => 1406,
                'field' => 'value'
            ],
            'vehiclemotion-speed' => [
                'ident' => 'Speed',
                'caption' => 'Geschwindigkeit',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '~Speed',
                'pos' => 1407,
                'field' => 'value'
            ],
            'vehiclemotion-steeringwheelangle' => [
                'ident' => 'SteeringWheelAngle',
                'caption' => 'Lenkradwinkel',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '',
                'pos' => 1408,
                'field' => 'value'
            ],

            'vehiclehealth-batterystatus' => [
                'ident' => 'VehicleBatteryStatus',
                'caption' => '12V Batteriestatus',
                'type' => VARIABLETYPE_STRING,
                'profile' => 'SMCAR.Health',
                'pos' => 1500,
                'field' => 'value'
            ],
            'vehiclehealth-serviceintervaldistance' => [
                'ident' => 'ServiceIntervalDistance',
                'caption' => 'Serviceintervall Distanz',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => 'SMCAR.Odometer',
                'pos' => 1501,
                'field' => 'value',
                'converter' => 'distance'
            ],
            'vehiclehealth-serviceintervaltime' => [
                'ident' => 'ServiceIntervalTime',
                'caption' => 'Serviceintervall Zeit',
                'type' => VARIABLETYPE_FLOAT,
                'profile' => '',
                'pos' => 1502,
                'field' => 'value'
            ],
            'vehiclehealth-tirepressurestatus' => [
                'ident' => 'TirePressureStatus',
                'caption' => 'Reifendruckstatus',
                'type' => VARIABLETYPE_STRING,
                'profile' => 'SMCAR.Health',
                'pos' => 1503,
                'field' => 'value'
            ],
        ];
    }
}