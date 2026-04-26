<?php

class SmartcarSplitter extends IPSModuleStrict
{
    private const DATA_ID = '{7C6B5A4F-3E2D-4C1B-9A8F-0E7D6C5B4A3F}';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');

        $this->RegisterAttributeString('ApplicationAccessToken', '');
        $this->RegisterAttributeInteger('TokenExpiresAt', 0);

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

            case 'GetVehicle':
                return json_encode($this->ApiGetVehicle((string)$data['VehicleID']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            case 'GetSignals':
                return json_encode($this->ApiGetSignals((string)$data['VehicleID']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            case 'GetSignal':
                return json_encode($this->ApiGetSignal(
                    (string)$data['VehicleID'],
                    (string)$data['SignalCode']
                ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            case 'Command':
                return json_encode($this->ApiVehicleCommand(
                    (string)$data['VehicleID'],
                    (string)$data['Method'],
                    (string)$data['Path'],
                    isset($data['Body']) ? json_encode($data['Body']) : ''
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

    public function ApiGetCompatibleVehicles(string $make = '', string $powertrainType = '', string $region = 'EUROPE'): array
    {
        $query = [];

        if ($region !== '') {
            $query['filter[region]'] = $region;
        }

        if ($make !== '') {
            $query['filter[make]'] = $make;
        }

        if ($powertrainType !== '' && strtoupper($powertrainType) !== 'ICE') {
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

        $decoded = json_decode($response['body'], true);

        return [
            'success' => ($response['statusCode'] >= 200 && $response['statusCode'] < 300),
            'statusCode' => $response['statusCode'],
            'body' => is_array($decoded) ? $decoded : $response['body']
        ];
    }
}