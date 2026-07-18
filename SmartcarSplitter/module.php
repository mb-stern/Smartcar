<?php

class SmartcarSplitter extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterHook('smartcar_' . $this->InstanceID);

        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('ManualRedirectURI', '');
        $this->RegisterPropertyString('ManagementToken', '');
        $this->RegisterPropertyString('ApplicationID', '');
        $this->RegisterAttributeString('ApplicationAccessToken', '');
        $this->RegisterAttributeInteger('TokenExpiresAt', 0);
        $this->RegisterAttributeString('RedirectURI', '');

        $this->RegisterTimer('TokenTimer', 0, 'SMCARS_RequestApplicationAccessToken($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $hookAddress = 'smartcar_' . $this->InstanceID;
        $hookPath = '/hook/' . $hookAddress;

        $manualRedirectURI = trim($this->ReadPropertyString('ManualRedirectURI'));
        $redirectURI = $manualRedirectURI !== '' ? $manualRedirectURI : $this->BuildSymconConnectURL($hookPath);
        $this->WriteAttributeString('RedirectURI', $redirectURI);

        $this->SendDebug('ApplyChanges', 'Hook/Redirect URI: ' . $redirectURI, 0);

        $this->SetStatus(102);

        if ($this->ReadPropertyString('ClientID') === '' || $this->ReadPropertyString('ClientSecret') === '') {
            $this->SetStatus(201);
            $this->SetTimerInterval('TokenTimer', 0);
            return;
        }

        $this->RequestApplicationAccessToken();
    }

    public function GetConfigurationForm(): string
    {
        $redirectURI = $this->ReadAttributeString('RedirectURI');

        $form = [
            'elements' => [
                [
                    'type' => 'Label',
                    'caption' => $redirectURI !== ''
                        ? 'Redirect-/Webhook-URI: ' . $redirectURI
                        : 'Redirect-/Webhook-URI: (leer – Symcon Connect nicht gefunden)'
                ],
                [
                    'type' => 'ValidationTextBox',
                    'name' => 'ManualRedirectURI',
                    'caption' => 'Redirect-/Webhook-URI manuell überschreiben'
                ],
                [
                    'type' => 'ValidationTextBox',
                    'name' => 'ClientID',
                    'caption' => 'Client ID'
                ],
                [
                    'type' => 'ValidationTextBox',
                    'name' => 'ClientSecret',
                    'caption' => 'Client Secret'
                ],
                [
                    'type' => 'ValidationTextBox',
                    'name' => 'ApplicationID',
                    'caption' => 'Application ID für Smartcar Connect'
                ],
                [
                    'type' => 'ValidationTextBox',
                    'name' => 'ManagementToken',
                    'caption' => 'Application Management Token'
                ]
            ]
        ];

        return json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function RequestApplicationAccessToken(): bool
    {
        $clientID = trim($this->ReadPropertyString('ClientID'));
        $clientSecret = trim($this->ReadPropertyString('ClientSecret'));

        if ($clientID === '' || $clientSecret === '') {
            $this->SendDebug('Token', 'ClientID oder ClientSecret fehlt.', 0);
            return false;
        }

        $url = 'https://iam.smartcar.com/oauth2/token';

        $postData = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $clientID,
            'client_secret' => $clientSecret
        ]);

        $response = $this->HttpRequestRaw(
            'Token',
            'POST',
            $url,
            [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ],
            $postData
        );

        if ($response === null) {
            return false;
        }

        if ($response['statusCode'] !== 200) {
            $this->SendDebug('Token', 'Fehler HTTP ' . $response['statusCode'] . ': ' . $response['body'], 0);
            $this->SetStatus(202);
            return false;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['access_token'])) {
            $this->SendDebug('Token', 'Unerwartete Antwort: ' . $response['body'], 0);
            $this->SetStatus(202);
            return false;
        }

        $expiresIn = isset($data['expires_in']) ? (int)$data['expires_in'] : 3600;
        $refreshIn = max(60, $expiresIn - 300);

        $this->WriteAttributeString('ApplicationAccessToken', (string)$data['access_token']);
        $this->WriteAttributeInteger('TokenExpiresAt', time() + $expiresIn);
        $this->SetTimerInterval('TokenTimer', $refreshIn * 1000);

        $this->SendDebug('Token', 'Application Access Token gespeichert. Refresh in ' . $refreshIn . ' Sekunden.', 0);
        $this->SetStatus(102);

        return true;
    }

    public function ForwardData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            return json_encode(['success' => false, 'error' => 'Invalid JSON']);
        }

        $command = (string)($data['Command'] ?? '');

        switch ($command) {
            case 'LoadConnections':
                return json_encode($this->LoadConnections(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            case 'BuildConnectURL':
                return json_encode([
                    'success' => true,
                    'url' => $this->BuildConnectURL(
                        (string)($data['Mode'] ?? 'live'),
                        (string)($data['State'] ?? ''),
                        is_array($data['Permissions'] ?? null) ? $data['Permissions'] : [],
                        (string)($data['VehicleID'] ?? ''),
                        (bool)($data['Reauthenticate'] ?? false)
                    )
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            case 'GetVehicle':
                return json_encode($this->ApiGetVehicle(
                    (string)$data['VehicleID'],
                    (string)($data['UserID'] ?? '')
                ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            case 'GetSignals':
                return json_encode($this->ApiGetSignals(
                    (string)$data['VehicleID'],
                    (string)($data['UserID'] ?? '')
                ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            case 'GetSignal':
                return json_encode($this->ApiGetSignal(
                    (string)$data['VehicleID'],
                    (string)$data['SignalCode'],
                    (string)($data['UserID'] ?? '')
                ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            case 'Command':
                return json_encode($this->ApiVehicleCommand(
                    (string)$data['VehicleID'],
                    (string)$data['Method'],
                    (string)$data['Path'],
                    isset($data['Body']) ? json_encode($data['Body']) : '',
                    (string)($data['UserID'] ?? '')
                ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            case 'GetCompatibleVehicles':
                return json_encode($this->ApiGetCompatibleVehicles(
                    (string)($data['Make'] ?? ''),
                    (string)($data['PowertrainType'] ?? ''),
                    (string)($data['Region'] ?? 'EUROPE')
                ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            default:
                return json_encode(['success' => false, 'error' => 'Unknown command']);
        }
    }

    public function LoadConnections(): array
    {
        $token = $this->GetValidApplicationAccessToken();
        if ($token === '') {
            return [];
        }

        $url = 'https://vehicle.api.smartcar.com/v3/connections?page[size]=100';

        $response = $this->HttpRequestRaw(
            'Connections',
            'GET',
            $url,
            [
                'Authorization: Bearer ' . $token,
                'Accept: application/json'
            ]
        );

        if ($response === null || $response['statusCode'] !== 200) {
            $this->SendDebug('Connections', 'Fehler: ' . json_encode($response), 0);
            return [];
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
            $this->SendDebug('Connections', 'Unerwartete Antwort: ' . $response['body'], 0);
            return [];
        }

        $connections = [];

        foreach ($data['data'] as $item) {
            $connectionId = (string)($item['id'] ?? '');
            $attributes   = is_array($item['attributes'] ?? null) ? $item['attributes'] : [];
            $vehicle      = is_array($attributes['vehicle'] ?? null) ? $attributes['vehicle'] : [];

            $vehicleId = (string)($item['relationships']['vehicle']['data']['id'] ?? '');
            $userId    = (string)($item['relationships']['user']['data']['id'] ?? '');

            if ($connectionId === '' || $vehicleId === '') {
                continue;
            }

            $make  = (string)($vehicle['make'] ?? '');
            $model = (string)($vehicle['model'] ?? '');
            $year  = (string)($vehicle['year'] ?? '');

            $modeValue      = (string)($vehicle['mode'] ?? ($attributes['mode'] ?? ''));
            $powertrainType = (string)($vehicle['powertrainType'] ?? '');

            $caption = trim($make . ' ' . $model . ' ' . $year);
            if ($caption === '') {
                $caption = $vehicleId;
            }

            $connections[] = [
                'connectionId'   => $connectionId,
                'vehicleId'      => $vehicleId,
                'userId'         => $userId,
                'caption'        => $caption,
                'make'           => $make,
                'model'          => $model,
                'year'           => $year,
                'mode'           => $modeValue,
                'powertrainType' => $powertrainType,
                'permissions'    => $attributes['permissions'] ?? []
            ];
        }

        return $connections;
    }

    public function ApiGetVehicle(string $vehicleId, string $userId = ''): array
    {
        return $this->ApiRequest('GET', '/vehicles/' . rawurlencode($vehicleId), null, $userId);
    }

    public function ApiGetSignals(string $vehicleId, string $userId = ''): array
    {
        return $this->ApiRequest('GET', '/vehicles/' . rawurlencode($vehicleId) . '/signals', null, $userId);
    }

    public function ApiGetSignal(string $vehicleId, string $signalCode, string $userId = ''): array
    {
        return $this->ApiRequest(
            'GET',
            '/vehicles/' . rawurlencode($vehicleId) . '/signals/' . rawurlencode($signalCode),
            null,
            $userId
        );
    }

    public function ApiVehicleCommand(string $vehicleId, string $method, string $path, string $body = '', string $userId = ''): array
    {
        $decodedBody = null;

        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $decodedBody = $decoded;
            }
        }

        $path = '/' . ltrim($path, '/');

        return $this->ApiRequest(
            $method,
            '/vehicles/' . rawurlencode($vehicleId) . $path,
            $decodedBody,
            $userId
        );
    }

    private function ApiRequest(string $method, string $path, $body = null, string $userId = ''): array
    {
        $token = $this->GetValidApplicationAccessToken();
        if ($token === '') {
            return ['success' => false, 'error' => 'No application access token'];
        }

        $url = 'https://vehicle.api.smartcar.com/v3' . $path;

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json'
        ];

        if ($userId !== '') {
            $headers[] = 'sc-user-id: ' . $userId;
        }

        $content = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $content = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $response = $this->HttpRequestRaw('ApiRequest', $method, $url, $headers, $content);

        if (is_array($response)) {
            $this->SendDebug(
                'ApiRequest/Headers',
                json_encode($response['headers'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                0
            );
        }

        if ($response === null) {
            return ['success' => false, 'error' => 'No response'];
        }

        $decoded = json_decode($response['body'], true);

        return [
            'success' => ($response['statusCode'] >= 200 && $response['statusCode'] < 300),
            'statusCode' => $response['statusCode'],
            'headers' => $response['headers'],
            'body' => is_array($decoded) ? $decoded : $response['body']
        ];
    }

    private function GetValidApplicationAccessToken(): string
    {
        $token = $this->ReadAttributeString('ApplicationAccessToken');
        $expiresAt = $this->ReadAttributeInteger('TokenExpiresAt');

        if ($token === '' || $expiresAt <= (time() + 120)) {
            if (!$this->RequestApplicationAccessToken()) {
                return '';
            }

            $token = $this->ReadAttributeString('ApplicationAccessToken');
        }

        return $token;
    }

    private function HttpRequestRaw(string $context, string $method, string $url, array $headers, ?string $content = null): ?array
    {
        $headerText = implode("\r\n", $headers) . "\r\n";

        $options = [
            'http' => [
                'method' => strtoupper($method),
                'header' => $headerText,
                'ignore_errors' => true,
                'timeout' => 30
            ]
        ];

        if ($content !== null) {
            $options['http']['content'] = $content;
        }

        $this->SendDebug($context, strtoupper($method) . ' ' . $url, 0);

        $ctx = stream_context_create($options);
        $body = @file_get_contents($url, false, $ctx);
        $responseHeaders = $http_response_header ?? [];
        $statusCode = $this->ExtractStatusCode($responseHeaders);

        if ($body === false) {
            $this->SendDebug($context, 'Keine Antwort. HTTP=' . $statusCode, 0);
            return null;
        }

        $this->SendDebug($context, 'HTTP ' . $statusCode . ': ' . $body, 0);

        return [
            'statusCode' => $statusCode,
            'headers' => $responseHeaders,
            'body' => $body
        ];
    }

    private function ExtractStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#HTTP/\d+(?:\.\d+)?\s+(\d+)#', $header, $m)) {
                return (int)$m[1];
            }
        }

        return 0;
    }

    public function ApiGetCompatibleVehicles(string $make = '', string $powertrainType = '', string $region = 'EUROPE'): array
    {
        $query = [];

        if ($region !== '') {
            $query['filter[region]'] = $region;
        }

        if ($make !== '') {
            $query['filter[make]'] = $make;
        }

        if ($powertrainType !== '') {
            $query['filter[powertrainType]'] = $powertrainType;
        }

        $url = 'https://compatibility.api.smartcar.com/v3/compatible-vehicles';
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $response = $this->HttpRequestRaw(
            'Compatibility',
            'GET',
            $url,
            [
                'Accept: application/json'
            ]
        );

        if ($response === null) {
            return [
                'success' => false,
                'error' => 'No response'
            ];
        }

        $this->SendDebug('Connections/RAW', $response['body'], 0);

        $decoded = json_decode($response['body'], true);

        return [
            'success' => ($response['statusCode'] >= 200 && $response['statusCode'] < 300),
            'statusCode' => $response['statusCode'],
            'body' => is_array($decoded) ? $decoded : $response['body']
        ];
    }

    protected function ProcessHookData(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $_SERVER['REQUEST_URI'] ?? '';
        $qs     = $_SERVER['QUERY_STRING'] ?? '';

        $this->SendDebug('Webhook/Request', 'method=' . $method . ' uri=' . $uri . ' qs=' . $qs, 0);

        // Smartcar Connect Redirect
        if ($method === 'GET') {
            $this->HandleConnectRedirect();
            return;
        }

        if ($method !== 'POST') {
            $this->SendDebug('Webhook', 'Nicht-POST empfangen.', 0);
            http_response_code(200);
            echo 'OK';
            return;
        }

        $sigHeader = $this->GetRequestHeader('SC-Signature')
            ?? $this->GetRequestHeader('X-Smartcar-Signature')
            ?? '';

        $this->SendDebug('Webhook/SignatureHeader', $sigHeader !== '' ? $sigHeader : '(leer)', 0);

        $raw = file_get_contents('php://input') ?: '';
        $this->SendDebug('Webhook/RAW', $raw, 0);

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $this->SendDebug('Webhook/Error', 'Ungültiges JSON.', 0);
            http_response_code(400);
            echo 'Bad Request';
            return;
        }

        $eventType = (string)($payload['eventType'] ?? '');

        // VERIFY muss vor der normalen Signaturprüfung beantwortet werden
        if ($eventType === 'VERIFY') {
            $this->HandleWebhookVerify($payload);
            return;
        }

        if (!$this->VerifyWebhookSignature($raw)) {
            http_response_code(401);
            echo 'Unauthorized';
            return;
        }

        $vehicleId = (string)(
            $payload['vehicleId']
            ?? $payload['data']['vehicle']['id']
            ?? $payload['data']['vehicleId']
            ?? ''
        );

        $this->SendDebug('Webhook/Event', json_encode([
            'eventType' => $eventType,
            'vehicleId' => $vehicleId,
            'eventId'   => $payload['eventId'] ?? '',
            'meta'      => $payload['meta'] ?? []
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        switch ($eventType) {
            case 'VEHICLE_STATE':
                $triggers = is_array($payload['triggers'] ?? null)
                    ? $payload['triggers']
                    : (is_array($payload['data']['triggers'] ?? null) ? $payload['data']['triggers'] : []);

                $isFirstDelivery = false;
                foreach ($triggers as $trigger) {
                    if (is_array($trigger) && strtoupper((string)($trigger['type'] ?? '')) === 'FIRST_DELIVERY') {
                        $isFirstDelivery = true;
                        break;
                    }
                }

                if ($isFirstDelivery) {
                    $this->SendDebug(
                        'Webhook/FIRST_DELIVERY',
                        'Synchronisiere Connections für automatische Vehicle-Instanz.',
                        0
                    );
                    $this->SyncVehiclesFromConnectionsWithRetry(3);
                }

                if ($vehicleId !== '') {
                    $this->DispatchVehicleStateToVehicle($vehicleId, $payload);
                } else {
                    $this->SendDebug('Webhook/VEHICLE_STATE', 'Keine VehicleID im Payload gefunden.', 0);
                }

                http_response_code(200);
                echo 'ok';
                return;

            case 'VEHICLE_ERROR':
                $this->HandleVehicleErrorWebhook($payload);
                http_response_code(200);
                echo 'ok';
                return;

            default:
                $this->SendDebug('Webhook/UnknownEvent', 'Unbekannter eventType=' . $eventType, 0);
                http_response_code(200);
                echo 'ok';
                return;
        }
    }

    private function HandleWebhookVerify(array $payload): void
    {
        $challenge = (string)($payload['data']['challenge'] ?? ($payload['challenge'] ?? ''));

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
        echo json_encode(['challenge' => $challenge], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function VerifyWebhookSignature(string $raw): bool
    {
        $managementToken = trim($this->ReadPropertyString('ManagementToken'));
        if ($managementToken === '') {
            $this->SendDebug('Webhook/Signature', 'ManagementToken fehlt.', 0);
            return false;
        }

        $signature = $this->GetRequestHeader('SC-Signature') ?? $this->GetRequestHeader('X-Smartcar-Signature') ?? '';
        if ($signature === '') {
            $this->SendDebug('Webhook/Signature', 'Signatur-Header fehlt.', 0);
            return false;
        }

        $calculated = hash_hmac('sha256', $raw, $managementToken);

        if (!hash_equals($calculated, trim($signature))) {
            $this->SendDebug('Webhook/Signature', 'Ungültig. expected=' . $calculated . ' received=' . $signature, 0);
            return false;
        }

        $this->SendDebug('Webhook/Signature', 'OK', 0);
        return true;
    }

    private function DispatchVehicleStateToVehicle(string $vehicleId, array $payload): void
    {
        $vehicleModuleId = '{1E1B7C9A-2D4F-4E8A-9C3B-7F6D5A4E2B10}';

        $instanceIds = @IPS_GetInstanceListByModuleID($vehicleModuleId);
        if (!is_array($instanceIds)) {
            return;
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach ($instanceIds as $instanceId) {
            $currentVehicleId = (string)@IPS_GetProperty($instanceId, 'VehicleID');

            if ($currentVehicleId !== $vehicleId) {
                continue;
            }

            $this->SendDebug('Webhook/Dispatch', 'Sende VEHICLE_STATE an Instanz ' . $instanceId, 0);

            if (function_exists('SMCARV_ProcessWebhookSignals')) {
                SMCARV_ProcessWebhookSignals($instanceId, $payloadJson);
            }

            return;
        }

        $this->SendDebug('Webhook/Dispatch', 'Keine Vehicle-Instanz für VehicleID ' . $vehicleId . ' gefunden.', 0);
    }

    private function GetRequestHeader(string $name): ?string
    {
        $target = strtolower($name);

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $key => $value) {
                if (strtolower($key) === $target) {
                    return (string)$value;
                }
            }
        }

        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$serverKey])) {
            return (string)$_SERVER[$serverKey];
        }

        return null;
    }

    public function BuildConnectURL(string $mode, string $state, array $permissions, string $vehicleId = '', bool $reauthenticate = false): string
    {
        $applicationId = trim($this->ReadPropertyString('ApplicationID'));

        $hookAddress = 'smartcar_' . $this->InstanceID;
        $hookPath = '/hook/' . $hookAddress;

        $redirectURI = $this->ReadAttributeString('RedirectURI');
        if ($redirectURI === '') {
            $redirectURI = $this->BuildSymconConnectURL($hookPath);
        }

        if ($applicationId === '') {
            return 'Fehler: Application ID für Smartcar Connect fehlt.';
        }

        if ($redirectURI === '') {
            return 'Fehler: Redirect-/Webhook-URI fehlt.';
        }

        $permissions = array_values(array_unique(array_filter(array_map(
            static fn($permission): string => trim((string)$permission),
            $permissions
        ))));

        if (empty($permissions)) {
            return 'Fehler: Keine Permissions aus den aktivierten Signalen gefunden.';
        }

        if ($state === '') {
            $state = bin2hex(random_bytes(12));
        }

        $mode = strtolower(trim($mode));
        if ($mode !== 'simulated') {
            $mode = 'live';
        }

        // Smartcar API V3 / Application Access Token Flow:
        // - application_id statt client_id
        // - response_type=none, daher kein Auth-Code-Exchange
        // - scope überschreibt für diesen Connect-Vorgang Vehicle Access
        // - approval_prompt=force zeigt den Consent-Screen erneut
        $query = [
            'application_id'  => $applicationId,
            'redirect_uri'    => $redirectURI,
            'response_type'   => 'none',
            'scope'           => implode(' ', $permissions),
            'state'           => $state,
            'mode'            => $mode,
            'approval_prompt' => 'force'
        ];

        // Stabile Zuordnung dieser Connect-Sitzung zur bestehenden Vehicle-Instanz.
        if ($vehicleId !== '') {
            $query['external_id'] = 'vehicle_' . str_replace('-', '_', $vehicleId);
        }

        $url = 'https://connect.smartcar.com/oauth/authorize?' . http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $this->SendDebug('ConnectURL/Build', json_encode([
            'flow'           => $vehicleId !== '' ? 'v3_existing_vehicle_consent' : 'v3_initial_connect',
            'applicationId'  => $applicationId,
            'vehicleId'      => $vehicleId,
            'responseType'   => 'none',
            'permissions'    => $permissions,
            'effectiveScope' => $query['scope'],
            'externalId'     => $query['external_id'] ?? '',
            'url'            => $url
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        return $url;
    }

    private function BuildSymconConnectURL(string $hookPath): string
    {
        if ($hookPath === '' || strpos($hookPath, '/hook/') !== 0) {
            $hookPath = '/hook/' . ltrim($hookPath, '/');
        }

        $this->SendDebug('ConnectURL', 'HookPath=' . $hookPath, 0);

        $connectAddress = '';
        $ids = @IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}');

        $this->SendDebug('ConnectURL', 'Connect-Instanzen=' . json_encode($ids), 0);

        if (!empty($ids)) {
            if (function_exists('CC_GetUrl')) {
                $connectAddress = @CC_GetUrl($ids[0]);
                $this->SendDebug('ConnectURL', 'CC_GetUrl=' . $connectAddress, 0);
            } elseif (function_exists('CC_GetURL')) {
                $connectAddress = @CC_GetURL($ids[0]);
                $this->SendDebug('ConnectURL', 'CC_GetURL=' . $connectAddress, 0);
            } else {
                $this->SendDebug('ConnectURL', 'Weder CC_GetUrl noch CC_GetURL vorhanden.', 0);
            }
        }

        if (is_string($connectAddress) && $connectAddress !== '') {
            return rtrim($connectAddress, '/') . $hookPath;
        }

        $this->SendDebug('ConnectURL', 'Keine Symcon-Connect-Adresse gefunden.', 0);
        return '';
    }

    private function HandleConnectRedirect(): void
    {
        $error = (string)($_GET['error'] ?? '');
        $errorDescription = (string)($_GET['error_description'] ?? '');

        if ($error !== '') {
            $this->SendDebug('Connect/RedirectError', $error . ' ' . $errorDescription, 0);

            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html><head><meta charset="utf-8"><title>Smartcar Connect</title></head><body>';
            echo '<h2>Smartcar Connect fehlgeschlagen</h2>';
            echo '<p>' . htmlspecialchars($error . ' ' . $errorDescription) . '</p>';
            echo '<p>Dieses Fenster kann geschlossen werden.</p>';
            echo '</body></html>';
            return;
        }

        $code = (string)($_GET['code'] ?? '');
        $userId = (string)($_GET['user_id'] ?? ($_GET['userId'] ?? ''));
        $state = (string)($_GET['state'] ?? '');
        $redirectVehicleId = (string)($_GET['vehicle_id'] ?? ($_GET['vehicleId'] ?? ''));

        $this->SendDebug('Connect/Redirect', json_encode([
            'code'       => $code !== '' ? '<present>' : '',
            'vehicle_id' => $redirectVehicleId,
            'user_id'    => $userId,
            'state'      => $state
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        $vehicleId = $redirectVehicleId !== ''
            ? $redirectVehicleId
            : $this->ExtractVehicleIdFromState($state);

        if ($vehicleId !== '' && $userId !== '') {
            $this->UpdateVehicleUserId($vehicleId, $userId);
        }

        // Die neue Smartcar-Connection ist nach dem Redirect nicht immer
        // sofort über /v3/connections sichtbar. Deshalb mit kurzen Retries
        // synchronisieren, damit die Vehicle-Instanz zuverlässig entsteht.
        $this->SyncVehiclesFromConnectionsWithRetry();

        // Nach dem V3-Connect die tatsächlich von Smartcar gespeicherten
        // Connection-Permissions protokollieren.
        $this->DebugGrantedPermissionsForVehicle($vehicleId);

        if ($vehicleId !== '') {
            $instanceId = $this->FindVehicleInstanceByVehicleId($vehicleId);
            if ($instanceId > 0 && function_exists('SMCARV_ApplySelectedCapabilities')) {
                SMCARV_ApplySelectedCapabilities($instanceId);
                $this->SendDebug('Connect/Reauthenticate', 'Aktuelle Capabilities angewendet für Instanz ' . $instanceId, 0);
            }
        }

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Smartcar Connect</title>';
        echo '<style>body{font-family:Arial,sans-serif;margin:40px;color:#222} .ok{color:#16803c}</style>';
        echo '</head><body>';
        echo '<h2 class="ok">Smartcar erfolgreich verbunden</h2>';
        echo '<p>Die Verbindung zum OEM wurde bestätigt.</p>';
        echo '<p>Du kannst dieses Fenster jetzt schließen und zu IP-Symcon zurückkehren.</p>';
        echo '</body></html>';
    }


    private function DebugGrantedPermissionsForVehicle(string $vehicleId): void
    {
        if ($vehicleId === '') {
            $this->SendDebug('Connect/GrantedPermissions', 'Keine VehicleID aus Redirect/state verfügbar.', 0);
            return;
        }

        $connections = $this->LoadConnections();

        foreach ($connections as $connection) {
            if (!is_array($connection)) {
                continue;
            }

            if ((string)($connection['vehicleId'] ?? '') !== $vehicleId) {
                continue;
            }

            $this->SendDebug(
                'Connect/GrantedPermissions',
                json_encode([
                    'vehicleId'    => $vehicleId,
                    'connectionId' => (string)($connection['connectionId'] ?? ''),
                    'permissions'  => $connection['permissions'] ?? []
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                0
            );

            return;
        }

        $this->SendDebug(
            'Connect/GrantedPermissions',
            'VehicleID ' . $vehicleId . ' wurde nach Connect nicht in /v3/connections gefunden.',
            0
        );
    }

    private function SyncVehiclesFromConnectionsWithRetry(int $maxAttempts = 5): void
    {
        $maxAttempts = max(1, $maxAttempts);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $connections = $this->LoadConnections();

            $this->SendDebug(
                'Connect/SyncVehicles',
                'Versuch ' . $attempt . '/' . $maxAttempts . ', Connections=' . count($connections),
                0
            );

            if (!empty($connections)) {
                $this->UpdateVehiclesFromConnections($connections);
                return;
            }

            if ($attempt < $maxAttempts) {
                usleep(500000);
            }
        }

        $this->SendDebug(
            'Connect/SyncVehicles',
            'Nach ' . $maxAttempts . ' Versuchen keine Connection gefunden.',
            0
        );
    }

    private function UpdateVehicleUserId(string $vehicleId, string $userId): void
    {
        $instanceId = $this->FindVehicleInstanceByVehicleId($vehicleId);
        if ($instanceId === 0) {
            $this->SendDebug('Connect/UpdateUserID', 'Keine Vehicle-Instanz für VehicleID=' . $vehicleId, 0);
            return;
        }

        IPS_SetProperty($instanceId, 'UserID', $userId);
        IPS_ApplyChanges($instanceId);

        if (function_exists('SMCARV_ApplySelectedCapabilities')) {
            SMCARV_ApplySelectedCapabilities($instanceId);
        }

        $this->SendDebug('Connect/UpdateUserID', 'UserID gespeichert in Instanz ' . $instanceId, 0);
    }

    private function UpdateVehiclesFromConnections(array $connections): void
    {
        foreach ($connections as $connection) {
            $vehicleId = (string)($connection['vehicleId'] ?? '');
            if ($vehicleId === '') {
                continue;
            }

            $instanceId = $this->FindVehicleInstanceByVehicleId($vehicleId);
            $isNew = false;

            if ($instanceId === 0) {
                $instanceId = IPS_CreateInstance('{1E1B7C9A-2D4F-4E8A-9C3B-7F6D5A4E2B10}');
                $isNew = true;

                $this->SendDebug(
                    'Connect/CreateVehicle',
                    'Neue Vehicle-Instanz erstellt: ' . $instanceId . ' für VehicleID=' . $vehicleId,
                    0
                );
            }

            $caption = trim((string)($connection['caption'] ?? ''));
            if ($caption === '') {
                $caption = $vehicleId;
            }

            // Die VehicleID muss vor ApplyChanges gesetzt werden. Andernfalls
            // wechselt die Vehicle-Instanz in den Fehlerstatus und kann beim
            // nächsten Synchronisieren nicht mehr über die VehicleID gefunden
            // werden, wodurch Dubletten entstehen können.
            IPS_SetProperty($instanceId, 'VehicleID', $vehicleId);
            IPS_SetProperty($instanceId, 'ConnectionID', (string)($connection['connectionId'] ?? ''));
            IPS_SetProperty($instanceId, 'UserID', (string)($connection['userId'] ?? ''));
            IPS_SetProperty($instanceId, 'VehicleCaption', $caption);
            IPS_SetProperty($instanceId, 'Make', (string)($connection['make'] ?? ''));
            IPS_SetProperty($instanceId, 'Model', (string)($connection['model'] ?? ''));
            IPS_SetProperty($instanceId, 'Year', (int)($connection['year'] ?? 0));
            IPS_SetProperty($instanceId, 'PowertrainType', (string)($connection['powertrainType'] ?? ''));

            IPS_SetName($instanceId, $caption);

            // Die automatisch angelegte Vehicle-Instanz direkt mit diesem
            // Splitter verbinden. Ohne Parent-Verbindung können spätere API-
            // Aufrufe der Vehicle-Instanz nicht funktionieren.
            @IPS_ConnectInstance($instanceId, $this->InstanceID);

            // Neue Instanzen neben dem Splitter einsortieren. Das entspricht
            // in der üblichen Modulstruktur der gemeinsamen Kategorie von
            // Splitter, Configurator und Vehicles.
            if ($isNew) {
                $targetParentId = IPS_GetParent($this->InstanceID);
                if ($targetParentId > 0 && IPS_GetParent($instanceId) !== $targetParentId) {
                    @IPS_SetParent($instanceId, $targetParentId);
                }
            }

            IPS_ApplyChanges($instanceId);
        }
    }

    private function FindVehicleInstanceByVehicleId(string $vehicleId): int
    {
        $vehicleModuleId = '{1E1B7C9A-2D4F-4E8A-9C3B-7F6D5A4E2B10}';

        $instanceIds = @IPS_GetInstanceListByModuleID($vehicleModuleId);
        if (!is_array($instanceIds)) {
            return 0;
        }

        foreach ($instanceIds as $instanceId) {
            if ((string)@IPS_GetProperty($instanceId, 'VehicleID') === $vehicleId) {
                return (int)$instanceId;
            }
        }

        return 0;
    }

    private function ExtractVehicleIdFromState(string $state): string
    {
        if (preg_match('/vehicle_([0-9a-fA-F-]{36})_/', $state, $m)) {
            return $m[1];
        }

        return '';
    }

    private function HandleVehicleErrorWebhook(array $payload): void
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $vehicleId = (string)(
            $payload['vehicleId']
            ?? $data['vehicle']['id']
            ?? $data['vehicleId']
            ?? ''
        );

        $err = $data['error'] ?? ($data['errors'][0] ?? $data);
        if (!is_array($err)) {
            $err = [];
        }

        $this->SendDebug('VEHICLE_ERROR', json_encode([
            'eventId'     => $payload['eventId'] ?? '',
            'vehicleId'   => $vehicleId,
            'type'        => $err['type'] ?? '',
            'code'        => $err['code'] ?? '',
            'description' => $err['description'] ?? ($err['message'] ?? ''),
            'requestId'   => $err['requestId'] ?? ($err['requestID'] ?? ''),
            'docURL'      => $err['docURL'] ?? ($err['docUrl'] ?? ''),
            'resolution'  => $err['resolution'] ?? null,
            'meta'        => $payload['meta'] ?? []
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
    }
}