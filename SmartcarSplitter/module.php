<?php

class SmartcarSplitter extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('Mode', 'live');

        $this->RegisterAttributeString('ApplicationAccessToken', '');
        $this->RegisterAttributeInteger('TokenExpiresAt', 0);
        $this->RegisterAttributeString('ConnectionsCache', '[]');

        $this->RegisterTimer('TokenTimer', 0, 'SMCARS_RequestApplicationAccessToken($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

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
        $connections = $this->GetCachedConnectionsForForm();

        $form = [
            'elements' => [
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
                    'type' => 'Select',
                    'name' => 'Mode',
                    'caption' => 'Modus',
                    'options' => [
                        ['caption' => 'Live', 'value' => 'live'],
                        ['caption' => 'Simuliert', 'value' => 'simulated']
                    ]
                ]
            ],
            'actions' => [
                [
                    'type' => 'Button',
                    'caption' => 'Application Token holen',
                    'onClick' => 'SMCARS_RequestApplicationAccessToken($id);'
                ],
                [
                    'type' => 'Button',
                    'caption' => 'Connections laden',
                    'onClick' => 'SMCARS_LoadConnections($id);'
                ],
                [
                    'type' => 'List',
                    'name' => 'Connections',
                    'caption' => 'Verbundene Fahrzeuge',
                    'rowCount' => 10,
                    'add' => false,
                    'delete' => false,
                    'sort' => [
                        'column' => 'caption',
                        'direction' => 'ascending'
                    ],
                    'columns' => [
                        ['caption' => 'Connection ID', 'name' => 'connectionId', 'width' => '250px', 'visible' => false],
                        ['caption' => 'Vehicle ID', 'name' => 'vehicleId', 'width' => '250px'],
                        ['caption' => 'Fahrzeug', 'name' => 'caption', 'width' => 'auto'],
                        ['caption' => 'Modus', 'name' => 'mode', 'width' => '100px'],
                        ['caption' => 'Powertrain', 'name' => 'powertrainType', 'width' => '120px']
                    ],
                    'values' => $connections
                ],
                [
                    'type' => 'Button',
                    'caption' => 'Fahrzeug-Instanzen erstellen/aktualisieren',
                    'onClick' => 'SMCARS_CreateVehicleInstances($id);'
                ]
            ]
        ];

        return json_encode($form);
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

    public function LoadConnections(): array
    {
        $token = $this->GetValidApplicationAccessToken();
        if ($token === '') {
            return [];
        }

        $mode = $this->ReadPropertyString('Mode');
        $url = 'https://vehicle.api.smartcar.com/v3/connections?filter[vehicle.mode]=' . urlencode($mode) . '&page[size]=100';

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
            $attributes = $item['attributes'] ?? [];
            $vehicle = $attributes['vehicle'] ?? [];
            $vehicleId = (string)($item['relationships']['vehicle']['data']['id'] ?? '');

            if ($connectionId === '' || $vehicleId === '') {
                continue;
            }

            $make = (string)($vehicle['make'] ?? '');
            $model = (string)($vehicle['model'] ?? '');
            $year = (string)($vehicle['year'] ?? '');
            $modeValue = (string)($vehicle['mode'] ?? '');
            $powertrainType = (string)($vehicle['powertrainType'] ?? '');

            $connections[] = [
                'connectionId' => $connectionId,
                'vehicleId' => $vehicleId,
                'caption' => trim($make . ' ' . $model . ' ' . $year),
                'make' => $make,
                'model' => $model,
                'year' => $year,
                'mode' => $modeValue,
                'powertrainType' => $powertrainType,
                'permissions' => $attributes['permissions'] ?? []
            ];
        }

        $this->WriteAttributeString('ConnectionsCache', json_encode($connections, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->ReloadForm();

        return $connections;
    }

    public function GetConnection(string $connectionId): ?array
    {
        $token = $this->GetValidApplicationAccessToken();
        if ($token === '' || $connectionId === '') {
            return null;
        }

        $url = 'https://vehicle.api.smartcar.com/v3/connections/' . rawurlencode($connectionId);

        $response = $this->HttpRequestRaw(
            'GetConnection',
            'GET',
            $url,
            [
                'Authorization: Bearer ' . $token,
                'Accept: application/json'
            ]
        );

        if ($response === null || $response['statusCode'] !== 200) {
            $this->SendDebug('GetConnection', 'Fehler: ' . json_encode($response), 0);
            return null;
        }

        $data = json_decode($response['body'], true);
        return is_array($data) ? $data : null;
    }

    public function RemoveConnection(string $connectionId): bool
    {
        $token = $this->GetValidApplicationAccessToken();
        if ($token === '' || $connectionId === '') {
            return false;
        }

        $url = 'https://vehicle.api.smartcar.com/v3/connections/' . rawurlencode($connectionId);

        $response = $this->HttpRequestRaw(
            'RemoveConnection',
            'DELETE',
            $url,
            [
                'Authorization: Bearer ' . $token,
                'Accept: application/json'
            ]
        );

        if ($response === null) {
            return false;
        }

        $ok = ($response['statusCode'] >= 200 && $response['statusCode'] < 300);
        $this->SendDebug('RemoveConnection', 'HTTP ' . $response['statusCode'] . ': ' . $response['body'], 0);

        if ($ok) {
            $this->LoadConnections();
        }

        return $ok;
    }

    public function CreateVehicleInstances(): void
    {
        $connections = $this->LoadConnections();

        foreach ($connections as $connection) {
            $vehicleId = $connection['vehicleId'];
            $connectionId = $connection['connectionId'];

            $instanceId = $this->FindVehicleInstanceByVehicleId($vehicleId);

            if ($instanceId === 0) {
                IPS_CreateInstance('{1E1B7C9A-2D4F-4E8A-9C3B-7F6D5A4E2B10}');
                IPS_SetParent($instanceId, $this->InstanceID);
            }

            IPS_SetName($instanceId, $connection['caption'] !== '' ? $connection['caption'] : $vehicleId);

            IPS_SetProperty($instanceId, 'VehicleID', $vehicleId);
            IPS_SetProperty($instanceId, 'ConnectionID', $connectionId);
            IPS_SetProperty($instanceId, 'VehicleCaption', $connection['caption']);
            IPS_SetProperty($instanceId, 'Make', $connection['make']);
            IPS_SetProperty($instanceId, 'Model', $connection['model']);
            IPS_SetProperty($instanceId, 'Year', (int)$connection['year']);
            IPS_SetProperty($instanceId, 'PowertrainType', $connection['powertrainType']);
            IPS_ApplyChanges($instanceId);
        }
    }

    public function ForwardData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            return json_encode(['success' => false, 'error' => 'Invalid JSON']);
        }

        $command = $data['Command'] ?? '';

        switch ($command) {
            case 'GetVehicle':
                return json_encode($this->ApiGetVehicle((string)$data['VehicleID']));

            case 'GetSignals':
                return json_encode($this->ApiGetSignals((string)$data['VehicleID']));

            case 'GetSignal':
                return json_encode($this->ApiGetSignal((string)$data['VehicleID'], (string)$data['SignalCode']));

            case 'Command':
                return json_encode($this->ApiVehicleCommand(
                    (string)$data['VehicleID'],
                    (string)$data['Method'],
                    (string)$data['Path'],
                    isset($data['Body']) ? json_encode($data['Body']) : ''
                ));

            default:
                return json_encode(['success' => false, 'error' => 'Unknown command']);
        }
    }

    public function ApiGetVehicle(string $vehicleId): array
    {
        return $this->ApiRequest('GET', '/vehicles/' . rawurlencode($vehicleId));
    }

    public function ApiGetSignals(string $vehicleId): array
    {
        return $this->ApiRequest('GET', '/vehicles/' . rawurlencode($vehicleId) . '/signals');
    }

    public function ApiGetSignal(string $vehicleId, string $signalCode): array
    {
        return $this->ApiRequest('GET', '/vehicles/' . rawurlencode($vehicleId) . '/signals/' . rawurlencode($signalCode));
    }

    public function ApiVehicleCommand(string $vehicleId, string $method, string $path, string $body = ''): array
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
            $decodedBody
        );
    }

    private function ApiRequest(string $method, string $path, $body = null): array
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

    private function GetCachedConnectionsForForm(): array
    {
        $raw = $this->ReadAttributeString('ConnectionsCache');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function FindVehicleInstanceByVehicleId(string $vehicleId): int
    {
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            $obj = IPS_GetObject($childId);
            if (($obj['ObjectType'] ?? -1) !== 1) {
                continue;
            }

            $instance = @IPS_GetInstance($childId);
            if (!is_array($instance) || ($instance['ModuleInfo']['ModuleID'] ?? '') !== '{1E1B7C9A-2D4F-4E8A-9C3B-7F6D5A4E2B10}') {
                continue;
            }

            if (@IPS_GetProperty($childId, 'VehicleID') === $vehicleId) {
                return $childId;
            }
        }

        return 0;
    }
}