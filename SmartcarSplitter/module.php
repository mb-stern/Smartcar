<?php

class SmartcarSplitter extends IPSModuleStrict
{
    private const DATA_ID = '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterHook('smartcar_' . $this->InstanceID);

        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');

        $this->RegisterPropertyString('ManualRedirectURI', '');

        $this->RegisterPropertyBoolean('EnableWebhook', true);
        $this->RegisterPropertyBoolean('VerifyWebhookSignature', true);
        $this->RegisterPropertyString('ManagementToken', '');

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
                    'type' => 'CheckBox',
                    'name' => 'EnableWebhook',
                    'caption' => 'Webhook aktivieren'
                ],
                [
                    'type' => 'CheckBox',
                    'name' => 'VerifyWebhookSignature',
                    'caption' => 'Webhook-Signatur prüfen'
                ],
                [
                    'type' => 'ValidationTextBox',
                    'name' => 'ManagementToken',
                    'caption' => 'Application Management Token'
                ]
            ],
            'actions' => [
                [
                    'type' => 'Button',
                    'caption' => 'Application Token holen',
                    'onClick' => 'SMCARS_RequestApplicationAccessToken($id);'
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
                        is_array($data['Permissions'] ?? null) ? $data['Permissions'] : []
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

        $this->SendDebug(
            'Hook/Request',
            'method=' . $method . ' query=' . json_encode($_GET, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

        // Smartcar Connect Redirect
        if ($method === 'GET') {
            $this->HandleConnectRedirect();
            return;
        }

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
        $this->SendDebug('Webhook/RAW', $raw, 0);

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo 'Bad Request';
            return;
        }

        if (($payload['eventType'] ?? '') === 'VERIFY') {
            $this->HandleWebhookVerify($payload);
            return;
        }

        if (!$this->VerifyWebhookSignature($raw)) {
            http_response_code(401);
            echo 'Unauthorized';
            return;
        }

        $eventType = (string)($payload['eventType'] ?? '');
        $vehicleId = (string)($payload['data']['vehicle']['id'] ?? '');
        $userId = (string)($payload['data']['user']['id'] ?? '');

        $this->SendDebug('Webhook/Event', 'eventType=' . $eventType . ' vehicleId=' . $vehicleId . ' userId=' . $userId, 0);

        if ($eventType === 'VEHICLE_STATE' && $vehicleId !== '') {
            $this->DispatchVehicleStateToVehicle($vehicleId, $payload);
        }

        http_response_code(200);
        echo 'ok';
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

        if ($this->ReadPropertyBoolean('VerifyWebhookSignature') && $managementToken !== '') {
            $challenge = hash_hmac('sha256', $challenge, $managementToken);
        }

        header('Content-Type: application/json');
        echo json_encode(['challenge' => $challenge], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function VerifyWebhookSignature(string $raw): bool
    {
        if (!$this->ReadPropertyBoolean('VerifyWebhookSignature')) {
            $this->SendDebug('Webhook/Signature', 'Prüfung deaktiviert.', 0);
            return true;
        }

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

    public function BuildConnectURL(string $mode, string $state, array $permissions): string
    {
        $clientID = trim($this->ReadPropertyString('ClientID'));

        $hookAddress = 'smartcar_' . $this->InstanceID;
        $hookPath = '/hook/' . $hookAddress;

        $redirectURI = $this->ReadAttributeString('RedirectURI');
        if ($redirectURI === '') {
            $redirectURI = $this->BuildSymconConnectURL($hookPath);
        }

        if ($clientID === '') {
            return 'Fehler: Client ID fehlt.';
        }

        if ($redirectURI === '') {
            return 'Fehler: Redirect-/Webhook-URI fehlt.';
        }

        $permissions = array_values(array_unique(array_filter(array_map('strval', $permissions))));

        if (empty($permissions)) {
            return 'Fehler: Keine Permissions aus den aktivierten Signalen gefunden.';
        }

        if ($state === '') {
            $state = bin2hex(random_bytes(12));
        }

        $query = [
            'response_type' => 'code',
            'client_id'     => $clientID,
            'redirect_uri'  => $redirectURI,
            'scope'         => implode(' ', $permissions),
            'state'         => $state,
            'mode'          => $mode !== '' ? $mode : 'live'
        ];

        $url = 'https://connect.smartcar.com/oauth/authorize?' . http_build_query($query);

        $this->SendDebug('ConnectURL/Build', $url, 0);

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
        if ($error !== '') {
            $description = (string)($_GET['error_description'] ?? '');
            $this->SendDebug('Connect/RedirectError', $error . ' ' . $description, 0);

            http_response_code(200);
            echo 'Smartcar Connect abgebrochen/fehlgeschlagen: ' . htmlspecialchars($error . ' ' . $description);
            return;
        }

        $userId = (string)($_GET['user_id'] ?? ($_GET['userId'] ?? ''));
        $state  = (string)($_GET['state'] ?? '');
        $code   = (string)($_GET['code'] ?? '');

        $this->SendDebug('Connect/Redirect', json_encode([
            'user_id' => $userId,
            'state'   => $state,
            'code'    => $code !== '' ? '<present>' : ''
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);

        if ($userId === '') {
            http_response_code(200);
            echo 'Smartcar Connect abgeschlossen, aber user_id fehlt. Debug prüfen.';
            return;
        }

        $vehicleId = $this->ExtractVehicleIdFromState($state);
        if ($vehicleId !== '') {
            $this->UpdateVehicleUserId($vehicleId, $userId);
        }

        // Connections neu laden, damit ConnectionID/VehicleID/userId aus Smartcar synchronisiert werden
        $connections = $this->LoadConnections();
        $this->UpdateVehiclesFromConnections($connections);

        http_response_code(200);
        echo 'Smartcar Connect erfolgreich abgeschlossen. Dieses Fenster kann geschlossen werden.';
    }

    private function ExtractVehicleIdFromState(string $state): string
    {
        if (preg_match('/vehicle_([0-9a-fA-F-]{36})_/', $state, $m)) {
            return $m[1];
        }

        return '';
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
            if ($instanceId === 0) {
                continue;
            }

            IPS_SetProperty($instanceId, 'ConnectionID', (string)($connection['connectionId'] ?? ''));
            IPS_SetProperty($instanceId, 'UserID', (string)($connection['userId'] ?? ''));
            IPS_SetProperty($instanceId, 'VehicleCaption', (string)($connection['caption'] ?? $vehicleId));
            IPS_SetProperty($instanceId, 'Make', (string)($connection['make'] ?? ''));
            IPS_SetProperty($instanceId, 'Model', (string)($connection['model'] ?? ''));
            IPS_SetProperty($instanceId, 'Year', (int)($connection['year'] ?? 0));
            IPS_SetProperty($instanceId, 'PowertrainType', (string)($connection['powertrainType'] ?? ''));

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
}