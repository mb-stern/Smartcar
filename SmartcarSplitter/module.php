<?php

class SmartcarSplitter extends IPSModuleStrict
{
    private const VEHICLE_MODULE_ID = '{1E1B7C9A-2D4F-4E8A-9C3B-7F6D5A4E2B10}';
    private const CONNECT_CONTROL_MODULE_ID = '{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}';

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
        $this->RegisterAttributeString('LastWebhookID', '');
        $this->RegisterAttributeString('LastVehicleStateSequences', '{}');

        $this->RegisterTimer(
            'TokenTimer',
            0,
            'SMCARS_RequestApplicationAccessToken($_IPS["TARGET"]);'
        );
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $hookAddress = 'smartcar_' . $this->InstanceID;
        $hookPath = '/hook/' . $hookAddress;

        $manualRedirectURI = trim($this->ReadPropertyString('ManualRedirectURI'));
        $redirectURI = $manualRedirectURI !== ''
            ? $manualRedirectURI
            : $this->BuildSymconConnectURL($hookPath);

        $this->WriteAttributeString('RedirectURI', $redirectURI);

        $this->SendDebug(
            'ApplyChanges',
            'Hook/Redirect URI: ' . $redirectURI,
            0
        );

        $this->SetStatus(102);

        if (
            trim($this->ReadPropertyString('ClientID')) === ''
            || trim($this->ReadPropertyString('ClientSecret')) === ''
        ) {
            $this->SetStatus(201);
            $this->SetTimerInterval('TokenTimer', 0);
            return;
        }

        $this->RequestApplicationAccessToken();
    }

    public function GetConfigurationForm(): string
    {
        $redirectURI = $this->ReadAttributeString('RedirectURI');
        $hookAddress = 'smartcar_' . $this->InstanceID;
        $hookPath = '/hook/' . $hookAddress;
        $manualRedirectURI = trim($this->ReadPropertyString('ManualRedirectURI'));

        $form = [
            'elements' => [
                [
                    'type' => 'Label',
                    'caption' => 'Lokaler Smartcar Hook-Pfad: ' . $hookPath
                ],
                [
                    'type' => 'Label',
                    'caption' => $redirectURI !== ''
                        ? 'Aktuelle Redirect-/Webhook-URL: ' . $redirectURI
                        : 'Aktuelle Redirect-/Webhook-URL: (leer – Symcon Connect nicht gefunden)'
                ],
                [
                    'type' => 'Label',
                    'caption' => $manualRedirectURI !== ''
                        ? 'URL-Quelle: Manuelle Redirect-/Webhook-URI'
                        : 'URL-Quelle: Automatisch über Symcon Connect'
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
                ],
            ]
        ];

        return json_encode(
            $form,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    public function RequestApplicationAccessToken(): bool
    {
        $clientID = trim($this->ReadPropertyString('ClientID'));
        $clientSecret = trim($this->ReadPropertyString('ClientSecret'));

        if ($clientID === '' || $clientSecret === '') {
            $this->SendDebug(
                'Token',
                'ClientID oder ClientSecret fehlt.',
                0
            );
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
            $this->SendDebug(
                'Token',
                'Fehler HTTP ' .
                $response['statusCode'] .
                ': ' .
                $response['body'],
                0
            );
            $this->SetStatus(202);
            return false;
        }

        $data = json_decode($response['body'], true);

        if (!is_array($data) || empty($data['access_token'])) {
            $this->SendDebug(
                'Token',
                'Unerwartete Antwort: ' . $response['body'],
                0
            );
            $this->SetStatus(202);
            return false;
        }

        $expiresIn = isset($data['expires_in'])
            ? (int)$data['expires_in']
            : 3600;

        $refreshIn = max(60, $expiresIn - 300);

        $this->WriteAttributeString(
            'ApplicationAccessToken',
            (string)$data['access_token']
        );

        $this->WriteAttributeInteger(
            'TokenExpiresAt',
            time() + $expiresIn
        );

        $this->SetTimerInterval(
            'TokenTimer',
            $refreshIn * 1000
        );

        $this->SendDebug(
            'Token',
            'Application Access Token gespeichert. Refresh in ' .
            $refreshIn .
            ' Sekunden.',
            0
        );

        $this->SetStatus(102);

        return true;
    }

    public function ForwardData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);

        if (!is_array($data)) {
            return json_encode([
                'success' => false,
                'error' => 'Invalid JSON'
            ]);
        }

        $command = (string)($data['Command'] ?? '');

        switch ($command) {
            case 'LoadConnections':
                return json_encode(
                    $this->LoadConnections(),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );

            case 'BuildConnectURL':
                $url = $this->BuildConnectURL(
                    (string)($data['Mode'] ?? 'live'),
                    (string)($data['State'] ?? '')
                );

                $success = str_starts_with($url, 'https://');

                return json_encode([
                    'success' => $success,
                    'url' => $success ? $url : '',
                    'error' => $success ? '' : $url,
                    'flow' => 'authorize'
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            case 'BuildAuthorizationURL':
                $vehicleId = trim((string)($data['VehicleID'] ?? ''));
                $state = (string)($data['State'] ?? '');
                $mode = (string)($data['Mode'] ?? 'live');

                if ($vehicleId !== '') {
                    $url = $this->BuildReauthURL(
                        $vehicleId,
                        $state
                    );
                    $flow = 'reauthenticate';
                } else {
                    $url = $this->BuildConnectURL(
                        $mode,
                        $state
                    );
                    $flow = 'authorize';
                }

                $success = str_starts_with($url, 'https://');

                return json_encode([
                    'success' => $success,
                    'url' => $success ? $url : '',
                    'error' => $success ? '' : $url,
                    'flow' => $flow
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            case 'BuildReauthURL':
                $url = $this->BuildReauthURL(
                    (string)($data['VehicleID'] ?? ''),
                    (string)($data['State'] ?? '')
                );

                $success = str_starts_with($url, 'https://');

                return json_encode([
                    'success' => $success,
                    'url' => $success ? $url : '',
                    'error' => $success ? '' : $url,
                    'flow' => 'reauthenticate'
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            case 'GetVehicle':
                return json_encode(
                    $this->ApiGetVehicle(
                        (string)($data['VehicleID'] ?? ''),
                        (string)($data['UserID'] ?? '')
                    ),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );

            case 'GetSignals':
                return json_encode(
                    $this->ApiGetSignals(
                        (string)($data['VehicleID'] ?? ''),
                        (string)($data['UserID'] ?? '')
                    ),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );

            case 'GetSignal':
                return json_encode(
                    $this->ApiGetSignal(
                        (string)($data['VehicleID'] ?? ''),
                        (string)($data['SignalCode'] ?? ''),
                        (string)($data['UserID'] ?? '')
                    ),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );

            case 'Command':
                return json_encode(
                    $this->ApiVehicleCommand(
                        (string)($data['VehicleID'] ?? ''),
                        (string)($data['Method'] ?? 'GET'),
                        (string)($data['Path'] ?? ''),
                        isset($data['Body'])
                            ? json_encode($data['Body'])
                            : '',
                        (string)($data['UserID'] ?? '')
                    ),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );

            case 'GetCompatibleVehicles':
                return json_encode(
                    $this->ApiGetCompatibleVehicles(
                        (string)($data['Make'] ?? ''),
                        (string)($data['PowertrainType'] ?? ''),
                        (string)($data['Region'] ?? 'EUROPE')
                    ),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );

            default:
                return json_encode([
                    'success' => false,
                    'error' => 'Unknown command'
                ]);
        }
    }

    public function LoadConnections(): array
    {
        $token = $this->GetValidApplicationAccessToken();

        if ($token === '') {
            return [];
        }

        $url =
            'https://vehicle.api.smartcar.com/v3/connections?page[size]=100';

        $response = $this->HttpRequestRaw(
            'Connections',
            'GET',
            $url,
            [
                'Authorization: Bearer ' . $token,
                'Accept: application/json'
            ]
        );

        if (
            $response === null
            || $response['statusCode'] !== 200
        ) {
            $this->SendDebug(
                'Connections',
                'Fehler: ' . json_encode($response),
                0
            );
            return [];
        }

        $data = json_decode($response['body'], true);

        if (
            !is_array($data)
            || !isset($data['data'])
            || !is_array($data['data'])
        ) {
            $this->SendDebug(
                'Connections',
                'Unerwartete Antwort: ' . $response['body'],
                0
            );
            return [];
        }

        $connections = [];

        foreach ($data['data'] as $item) {
            $connectionId = (string)($item['id'] ?? '');

            $attributes = is_array($item['attributes'] ?? null)
                ? $item['attributes']
                : [];

            $vehicle = is_array($attributes['vehicle'] ?? null)
                ? $attributes['vehicle']
                : [];

            $vehicleId = (string)(
                $item['relationships']['vehicle']['data']['id']
                ?? ''
            );

            $userId = (string)(
                $item['relationships']['user']['data']['id']
                ?? ''
            );

            if (
                $connectionId === ''
                || $vehicleId === ''
            ) {
                continue;
            }

            $make = (string)($vehicle['make'] ?? '');
            $model = (string)($vehicle['model'] ?? '');
            $year = (string)($vehicle['year'] ?? '');

            $modeValue = (string)(
                $vehicle['mode']
                ?? ($attributes['mode'] ?? '')
            );

            $powertrainType = (string)(
                $vehicle['powertrainType']
                ?? ''
            );

            $caption = trim(
                $make . ' ' . $model . ' ' . $year
            );

            if ($caption === '') {
                $caption = $vehicleId;
            }

            $connections[] = [
                'connectionId' => $connectionId,
                'vehicleId' => $vehicleId,
                'userId' => $userId,
                'caption' => $caption,
                'make' => $make,
                'model' => $model,
                'year' => $year,
                'mode' => $modeValue,
                'powertrainType' => $powertrainType,
                'permissions' => $attributes['permissions'] ?? []
            ];
        }

        return $connections;
    }

    public function ApiGetVehicle(
        string $vehicleId,
        string $userId = ''
    ): array {
        return $this->ApiRequest(
            'GET',
            '/vehicles/' . rawurlencode($vehicleId),
            null,
            $userId
        );
    }

    public function ApiGetSignals(
        string $vehicleId,
        string $userId = ''
    ): array {
        return $this->ApiRequest(
            'GET',
            '/vehicles/' .
            rawurlencode($vehicleId) .
            '/signals',
            null,
            $userId
        );
    }

    public function ApiGetSignal(
        string $vehicleId,
        string $signalCode,
        string $userId = ''
    ): array {
        return $this->ApiRequest(
            'GET',
            '/vehicles/' .
            rawurlencode($vehicleId) .
            '/signals/' .
            rawurlencode($signalCode),
            null,
            $userId
        );
    }

    public function ApiVehicleCommand(
        string $vehicleId,
        string $method,
        string $path,
        string $body = '',
        string $userId = ''
    ): array {
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
            '/vehicles/' .
            rawurlencode($vehicleId) .
            $path,
            $decodedBody,
            $userId
        );
    }

    private function ApiRequest(
        string $method,
        string $path,
        $body = null,
        string $userId = ''
    ): array {
        $token = $this->GetValidApplicationAccessToken();

        if ($token === '') {
            return [
                'success' => false,
                'error' => 'No application access token'
            ];
        }

        $url =
            'https://vehicle.api.smartcar.com/v3' .
            $path;

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json'
        ];

        if ($userId !== '') {
            $headers[] =
                'sc-user-id: ' .
                $userId;
        }

        $content = null;

        if ($body !== null) {
            $headers[] =
                'Content-Type: application/json';

            $content = json_encode(
                $body,
                JSON_UNESCAPED_SLASHES |
                JSON_UNESCAPED_UNICODE
            );
        }

        $response = $this->HttpRequestRaw(
            'ApiRequest',
            $method,
            $url,
            $headers,
            $content
        );

        if (is_array($response)) {
            $this->SendDebug(
                'ApiRequest/Headers',
                json_encode(
                    $response['headers'] ?? [],
                    JSON_UNESCAPED_SLASHES |
                    JSON_UNESCAPED_UNICODE
                ),
                0
            );
        }

        if ($response === null) {
            return [
                'success' => false,
                'error' => 'No response'
            ];
        }

        $decoded = json_decode(
            $response['body'],
            true
        );

        return [
            'success' =>
                $response['statusCode'] >= 200
                && $response['statusCode'] < 300,
            'statusCode' => $response['statusCode'],
            'headers' => $response['headers'],
            'body' =>
                is_array($decoded)
                    ? $decoded
                    : $response['body']
        ];
    }

    private function GetValidApplicationAccessToken(): string
    {
        $token = $this->ReadAttributeString(
            'ApplicationAccessToken'
        );

        $expiresAt = $this->ReadAttributeInteger(
            'TokenExpiresAt'
        );

        if (
            $token === ''
            || $expiresAt <= (time() + 120)
        ) {
            if (
                !$this->RequestApplicationAccessToken()
            ) {
                return '';
            }

            $token =
                $this->ReadAttributeString(
                    'ApplicationAccessToken'
                );
        }

        return $token;
    }

    private function HttpRequestRaw(
        string $context,
        string $method,
        string $url,
        array $headers,
        ?string $content = null
    ): ?array {
        $headerText =
            implode("\r\n", $headers) .
            "\r\n";

        $options = [
            'http' => [
                'method' => strtoupper($method),
                'header' => $headerText,
                'ignore_errors' => true,
                'timeout' => 30
            ]
        ];

        if ($content !== null) {
            $options['http']['content'] =
                $content;
        }

        $this->SendDebug(
            $context,
            strtoupper($method) .
            ' ' .
            $url,
            0
        );

        $ctx =
            stream_context_create(
                $options
            );

        $body =
            @file_get_contents(
                $url,
                false,
                $ctx
            );

        $responseHeaders =
            $http_response_header ?? [];

        $statusCode =
            $this->ExtractStatusCode(
                $responseHeaders
            );

        if ($body === false) {
            $this->SendDebug(
                $context,
                'Keine Antwort. HTTP=' .
                $statusCode,
                0
            );
            return null;
        }

        $this->SendDebug(
            $context,
            'HTTP ' .
            $statusCode .
            ': ' .
            $body,
            0
        );

        return [
            'statusCode' => $statusCode,
            'headers' => $responseHeaders,
            'body' => $body
        ];
    }

    private function ExtractStatusCode(
        array $headers
    ): int {
        foreach ($headers as $header) {
            if (
                preg_match(
                    '#HTTP/\d+(?:\.\d+)?\s+(\d+)#',
                    $header,
                    $m
                )
            ) {
                return (int)$m[1];
            }
        }

        return 0;
    }

    public function ApiGetCompatibleVehicles(
        string $make = '',
        string $powertrainType = '',
        string $region = 'EUROPE'
    ): array {
        $query = [];

        if ($region !== '') {
            $query['filter[region]'] =
                $region;
        }

        if ($make !== '') {
            $query['filter[make]'] =
                $make;
        }

        // PowertrainType bewusst NICHT als harten Compatibility-Filter verwenden.
        // Smartcar kann insbesondere Plug-in-Hybride in der Connection als ICE
        // melden. Mit filter[powertrainType]=ICE würden dann passende PHEV-
        // Compatibility-Einträge bereits serverseitig ausgeschlossen.
        if ($powertrainType !== '') {
            $this->SendDebug(
                'Compatibility/Powertrain',
                'Connection meldet PowertrainType=' .
                $powertrainType .
                '; Compatibility-Abfrage erfolgt testweise ohne Powertrain-Filter.',
                0
            );
        }

        $url =
            'https://compatibility.api.smartcar.com/v3/compatible-vehicles';

        if (!empty($query)) {
            $url .=
                '?' .
                http_build_query($query);
        }

        $response =
            $this->HttpRequestRaw(
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

        $this->SendDebug(
            'Connections/RAW',
            $response['body'],
            0
        );

        $decoded =
            json_decode(
                $response['body'],
                true
            );

        return [
            'success' =>
                $response['statusCode'] >= 200
                && $response['statusCode'] < 300,
            'statusCode' =>
                $response['statusCode'],
            'body' =>
                is_array($decoded)
                    ? $decoded
                    : $response['body']
        ];
    }

    protected function ProcessHookData(): void
    {
        $method =
            $_SERVER['REQUEST_METHOD']
            ?? 'GET';

        $uri =
            $_SERVER['REQUEST_URI']
            ?? '';

        $qs =
            $_SERVER['QUERY_STRING']
            ?? '';

        $this->SendDebug(
            'Webhook/Request',
            'method=' .
            $method .
            ' uri=' .
            $uri .
            ' qs=' .
            $qs,
            0
        );

        if ($method === 'GET') {
            $this->HandleConnectRedirect();
            return;
        }

        if ($method !== 'POST') {
            $this->SendDebug(
                'Webhook',
                'Nicht-POST empfangen.',
                0
            );

            http_response_code(200);
            echo 'OK';
            return;
        }

        $sigHeader =
            $this->GetRequestHeader(
                'SC-Signature'
            )
            ?? $this->GetRequestHeader(
                'X-Smartcar-Signature'
            )
            ?? '';

        $this->SendDebug(
            'Webhook/SignatureHeader',
            $sigHeader !== ''
                ? $sigHeader
                : '(leer)',
            0
        );

        $raw =
            file_get_contents(
                'php://input'
            )
            ?: '';

        $this->SendDebug(
            'Webhook/RAW',
            $raw,
            0
        );

        $payload =
            json_decode(
                $raw,
                true
            );

        if (!is_array($payload)) {
            $this->SendDebug(
                'Webhook/Error',
                'Ungültiges JSON.',
                0
            );

            http_response_code(400);
            echo 'Bad Request';
            return;
        }

        $eventType =
            (string)(
                $payload['eventType']
                ?? ''
            );

        $receivedWebhookId =
            trim(
                (string)(
                    $payload['meta']['webhookId']
                    ?? ''
                )
            );

        if ($receivedWebhookId !== '') {
            $this->WriteAttributeString(
                'LastWebhookID',
                $receivedWebhookId
            );
        }

        if ($eventType === 'VERIFY') {
            $this->HandleWebhookVerify(
                $payload
            );
            return;
        }

        if (
            !$this->VerifyWebhookSignature(
                $raw
            )
        ) {
            http_response_code(401);
            echo 'Unauthorized';
            return;
        }

        $vehicleId =
            (string)(
                $payload['vehicleId']
                ?? $payload['data']['vehicle']['id']
                ?? $payload['data']['vehicleId']
                ?? ''
            );

        $this->SendDebug(
            'Webhook/Event',
            json_encode([
                'eventType' => $eventType,
                'vehicleId' => $vehicleId,
                'eventId' =>
                    $payload['eventId']
                    ?? '',
                'meta' =>
                    $payload['meta']
                    ?? []
            ], JSON_UNESCAPED_SLASHES |
               JSON_UNESCAPED_UNICODE),
            0
        );

        switch ($eventType) {
            case 'VEHICLE_STATE':
                $sequence = 0;
                $lastSequence = 0;
                $lastSequences = [];

                if ($vehicleId !== '') {
                    $sequenceRaw =
                        $payload['meta']['sequence']
                        ?? null;

                    $sequence =
                        is_int($sequenceRaw)
                        || (
                            is_string($sequenceRaw)
                            && ctype_digit($sequenceRaw)
                        )
                            ? (int)$sequenceRaw
                            : 0;

                    if ($sequence > 0) {
                        $lastSequences =
                            json_decode(
                                $this->ReadAttributeString(
                                    'LastVehicleStateSequences'
                                ),
                                true
                            );

                        if (!is_array($lastSequences)) {
                            $lastSequences = [];
                        }

                        $lastSequence =
                            (int)(
                                $lastSequences[$vehicleId]
                                ?? 0
                            );

                        if (
                            $lastSequence > 0
                            && $sequence <= $lastSequence
                        ) {
                            $this->SendDebug(
                                'Webhook/SequenceSkip',
                                json_encode([
                                    'vehicleId' => $vehicleId,
                                    'eventId' =>
                                        $payload['eventId']
                                        ?? '',
                                    'sequence' => $sequence,
                                    'lastSequence' => $lastSequence
                                ], JSON_UNESCAPED_SLASHES |
                                   JSON_UNESCAPED_UNICODE),
                                0
                            );

                            http_response_code(200);
                            echo 'ok';
                            return;
                        }
                    } else {
                        $this->SendDebug(
                            'Webhook/Sequence',
                            'Keine gültige meta.sequence vorhanden; VEHICLE_STATE wird normal verarbeitet.',
                            0
                        );
                    }
                }

                $triggers =
                    is_array(
                        $payload['triggers']
                        ?? null
                    )
                    ? $payload['triggers']
                    : (
                        is_array(
                            $payload['data']['triggers']
                            ?? null
                        )
                        ? $payload['data']['triggers']
                        : []
                    );

                $isFirstDelivery = false;

                foreach ($triggers as $trigger) {
                    if (
                        is_array($trigger)
                        && strtoupper(
                            (string)(
                                $trigger['type']
                                ?? ''
                            )
                        ) === 'FIRST_DELIVERY'
                    ) {
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

                    $this->SyncVehiclesFromConnectionsWithRetry(
                        3
                    );
                }

                if ($vehicleId !== '') {
                    $this->DispatchVehicleStateToVehicle(
                        $vehicleId,
                        $payload
                    );

                    if ($sequence > 0) {
                        $lastSequences[$vehicleId] =
                            $sequence;

                        $this->WriteAttributeString(
                            'LastVehicleStateSequences',
                            json_encode(
                                $lastSequences,
                                JSON_UNESCAPED_SLASHES |
                                JSON_UNESCAPED_UNICODE
                            )
                        );

                        $this->SendDebug(
                            'Webhook/SequenceAccept',
                            json_encode([
                                'vehicleId' => $vehicleId,
                                'eventId' =>
                                    $payload['eventId']
                                    ?? '',
                                'sequence' => $sequence,
                                'previousSequence' => $lastSequence
                            ], JSON_UNESCAPED_SLASHES |
                               JSON_UNESCAPED_UNICODE),
                            0
                        );
                    }
                } else {
                    $this->SendDebug(
                        'Webhook/VEHICLE_STATE',
                        'Keine VehicleID im Payload gefunden.',
                        0
                    );
                }

                http_response_code(200);
                echo 'ok';
                return;

            case 'VEHICLE_ERROR':
                $this->HandleVehicleErrorWebhook(
                    $payload
                );

                http_response_code(200);
                echo 'ok';
                return;

            default:
                $this->SendDebug(
                    'Webhook/UnknownEvent',
                    'Unbekannter eventType=' .
                    $eventType,
                    0
                );

                http_response_code(200);
                echo 'ok';
                return;
        }
    }

    private function HandleWebhookVerify(
        array $payload
    ): void {
        $challenge =
            (string)(
                $payload['data']['challenge']
                ?? ($payload['challenge'] ?? '')
            );

        if ($challenge === '') {
            http_response_code(400);
            echo 'Bad Request';
            return;
        }

        $managementToken =
            trim(
                $this->ReadPropertyString(
                    'ManagementToken'
                )
            );

        if ($managementToken !== '') {
            $challenge =
                hash_hmac(
                    'sha256',
                    $challenge,
                    $managementToken
                );
        }

        header(
            'Content-Type: application/json'
        );

        echo json_encode(
            [
                'challenge' => $challenge
            ],
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        );
    }

    private function VerifyWebhookSignature(
        string $raw
    ): bool {
        $managementToken =
            trim(
                $this->ReadPropertyString(
                    'ManagementToken'
                )
            );

        if ($managementToken === '') {
            $this->SendDebug(
                'Webhook/Signature',
                'ManagementToken fehlt.',
                0
            );
            return false;
        }

        $signature =
            $this->GetRequestHeader(
                'SC-Signature'
            )
            ?? $this->GetRequestHeader(
                'X-Smartcar-Signature'
            )
            ?? '';

        if ($signature === '') {
            $this->SendDebug(
                'Webhook/Signature',
                'Signatur-Header fehlt.',
                0
            );
            return false;
        }

        $calculated =
            hash_hmac(
                'sha256',
                $raw,
                $managementToken
            );

        if (
            !hash_equals(
                $calculated,
                trim($signature)
            )
        ) {
            $this->SendDebug(
                'Webhook/Signature',
                'Ungültig. expected=' .
                $calculated .
                ' received=' .
                $signature,
                0
            );
            return false;
        }

        $this->SendDebug(
            'Webhook/Signature',
            'OK',
            0
        );

        return true;
    }

    private function DispatchVehicleStateToVehicle(
        string $vehicleId,
        array $payload
    ): void {
        $instanceIds =
            @IPS_GetInstanceListByModuleID(
                self::VEHICLE_MODULE_ID
            );

        if (!is_array($instanceIds)) {
            return;
        }

        $payloadJson =
            json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES |
                JSON_UNESCAPED_UNICODE
            );

        foreach ($instanceIds as $instanceId) {
            $currentVehicleId =
                (string)@IPS_GetProperty(
                    $instanceId,
                    'VehicleID'
                );

            if (
                $currentVehicleId !==
                $vehicleId
            ) {
                continue;
            }

            $this->SendDebug(
                'Webhook/Dispatch',
                'Sende VEHICLE_STATE an Instanz ' .
                $instanceId,
                0
            );

            if (
                function_exists(
                    'SMCARV_ProcessWebhookSignals'
                )
            ) {
                SMCARV_ProcessWebhookSignals(
                    $instanceId,
                    $payloadJson
                );
            }

            return;
        }

        $this->SendDebug(
            'Webhook/Dispatch',
            'Keine Vehicle-Instanz für VehicleID ' .
            $vehicleId .
            ' gefunden.',
            0
        );
    }

    private function GetRequestHeader(
        string $name
    ): ?string {
        $target =
            strtolower($name);

        if (
            function_exists(
                'getallheaders'
            )
        ) {
            foreach (
                getallheaders()
                as $key => $value
            ) {
                if (
                    strtolower($key)
                    === $target
                ) {
                    return (string)$value;
                }
            }
        }

        $serverKey =
            'HTTP_' .
            strtoupper(
                str_replace(
                    '-',
                    '_',
                    $name
                )
            );

        if (
            isset(
                $_SERVER[$serverKey]
            )
        ) {
            return (string)
                $_SERVER[$serverKey];
        }

        return null;
    }

    /**
     * Normaler Smartcar Connect Flow für neue Fahrzeuge.
     *
     * Bewusst ohne expliziten Scope:
     * Die Vehicle-Access-Konfiguration der Smartcar Application
     * soll die angeforderten Permissions bestimmen.
     */
    public function BuildConnectURL(
        string $mode,
        string $state
    ): string {
        $applicationID =
            trim(
                $this->ReadPropertyString(
                    'ApplicationID'
                )
            );

        $redirectURI =
            $this->GetRedirectURI();

        if ($applicationID === '') {
            return
                'Fehler: Application ID für Smartcar Connect fehlt.';
        }

        if ($redirectURI === '') {
            return
                'Fehler: Redirect-/Webhook-URI fehlt.';
        }

        $mode =
            strtolower(
                trim($mode)
            );

        if ($mode !== 'simulated') {
            $mode = 'live';
        }

        if ($state === '') {
            try {
                $state =
                    'connect_' .
                    bin2hex(
                        random_bytes(12)
                    );
            } catch (Throwable $e) {
                $state =
                    'connect_' .
                    uniqid('', true);
            }
        }

        $query = [
            'response_type' => 'none',
            'application_id' => $applicationID,
            'redirect_uri' => $redirectURI,
            'state' => $state,
            'mode' => $mode
        ];

        $url =
            'https://connect.smartcar.com/oauth/authorize?' .
            http_build_query(
                $query,
                '',
                '&',
                PHP_QUERY_RFC3986
            );

        $this->SendDebug(
            'ConnectURL/Build',
            json_encode([
                'flow' => 'authorize',
                'mode' => $mode,
                'responseType' => 'none',
                'applicationId' => $applicationID,
                'permissionSource' =>
                    'vehicle_access_dashboard',
                'url' => $url
            ], JSON_UNESCAPED_SLASHES |
               JSON_UNESCAPED_UNICODE),
            0
        );

        return $url;
    }

    /**
     * Reauthentifizierung eines bereits verbundenen Fahrzeugs.
     *
     * Separater Smartcar-Reauth-Flow über /oauth/reauthenticate.
     * Der bestehende Vehicle-Datensatz wird über vehicle_id angegeben.
     */
    public function BuildReauthURL(
        string $vehicleId,
        string $state
    ): string {
        $applicationID =
            trim(
                $this->ReadPropertyString(
                    'ApplicationID'
                )
            );

        $redirectURI =
            $this->GetRedirectURI();

        if ($applicationID === '') {
            return
                'Fehler: Application ID für Smartcar Connect fehlt.';
        }

        if ($redirectURI === '') {
            return
                'Fehler: Redirect-/Webhook-URI fehlt.';
        }

        $vehicleId =
            trim($vehicleId);

        if ($vehicleId === '') {
            return
                'Fehler: Vehicle ID fehlt.';
        }

        if ($state === '') {
            try {
                $state =
                    'reauth_' .
                    $vehicleId .
                    '_' .
                    bin2hex(
                        random_bytes(8)
                    );
            } catch (Throwable $e) {
                $state =
                    'reauth_' .
                    $vehicleId .
                    '_' .
                    uniqid('', true);
            }
        }

        $query = [
            'response_type'  => 'vehicle_id',
            'application_id' => $applicationID,
            'vehicle_id'     => $vehicleId,
            'redirect_uri'   => $redirectURI,
            'state'          => $state
        ];

        $url =
            'https://connect.smartcar.com/oauth/reauthenticate?' .
            http_build_query(
                $query,
                '',
                '&',
                PHP_QUERY_RFC3986
            );

        $this->SendDebug(
            'ReauthURL/Build',
            json_encode([
                'flow' => 'reauthenticate',
                'vehicleId' => $vehicleId,
                'responseType' => 'vehicle_id',
                'applicationId' => $applicationID,
                'permissionSource' => 'vehicle_access_dashboard',
                'url' => $url
            ], JSON_UNESCAPED_SLASHES |
               JSON_UNESCAPED_UNICODE),
            0
        );

        return $url;
    }

    private function GetRedirectURI(): string
    {
        $redirectURI =
            trim(
                $this->ReadAttributeString(
                    'RedirectURI'
                )
            );

        if ($redirectURI !== '') {
            return $redirectURI;
        }

        $hookPath =
            '/hook/smartcar_' .
            $this->InstanceID;

        return
            $this->BuildSymconConnectURL(
                $hookPath
            );
    }

    private function BuildSymconConnectURL(
        string $hookPath
    ): string {
        if (
            $hookPath === ''
            || strpos(
                $hookPath,
                '/hook/'
            ) !== 0
        ) {
            $hookPath =
                '/hook/' .
                ltrim(
                    $hookPath,
                    '/'
                );
        }

        $this->SendDebug(
            'ConnectURL',
            'HookPath=' .
            $hookPath,
            0
        );

        $connectAddress = '';

        $ids =
            @IPS_GetInstanceListByModuleID(
                self::CONNECT_CONTROL_MODULE_ID
            );

        $this->SendDebug(
            'ConnectURL',
            'Connect-Instanzen=' .
            json_encode($ids),
            0
        );

        if (!empty($ids)) {
            if (
                function_exists(
                    'CC_GetUrl'
                )
            ) {
                $connectAddress =
                    @CC_GetUrl(
                        $ids[0]
                    );

                $this->SendDebug(
                    'ConnectURL',
                    'CC_GetUrl=' .
                    $connectAddress,
                    0
                );
            } elseif (
                function_exists(
                    'CC_GetURL'
                )
            ) {
                $connectAddress =
                    @CC_GetURL(
                        $ids[0]
                    );

                $this->SendDebug(
                    'ConnectURL',
                    'CC_GetURL=' .
                    $connectAddress,
                    0
                );
            } else {
                $this->SendDebug(
                    'ConnectURL',
                    'Weder CC_GetUrl noch CC_GetURL vorhanden.',
                    0
                );
            }
        }

        if (
            is_string($connectAddress)
            && $connectAddress !== ''
        ) {
            return
                rtrim(
                    $connectAddress,
                    '/'
                ) .
                $hookPath;
        }

        $this->SendDebug(
            'ConnectURL',
            'Keine Symcon-Connect-Adresse gefunden.',
            0
        );

        return '';
    }

    private function HandleConnectRedirect(): void
    {
        $error = (string)($_GET['error'] ?? '');
        $errorDescription = (string)($_GET['error_description'] ?? '');

        if ($error !== '') {
            $this->SendDebug(
                'Connect/RedirectError',
                $error . ' ' . $errorDescription,
                0
            );

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

        $this->SendDebug(
            'Connect/Redirect',
            json_encode([
                'code' => $code !== '' ? '<present>' : '',
                'vehicle_id' => $redirectVehicleId,
                'user_id' => $userId,
                'state' => $state
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

        $syncVehicleId =
            $this->ExtractVehicleIdFromVehicleAccessSyncState(
                $state
            );

        $vehicleId = $redirectVehicleId !== ''
            ? $redirectVehicleId
            : (
                $syncVehicleId !== ''
                    ? $syncVehicleId
                    : $this->ExtractVehicleIdFromState($state)
            );

        if ($vehicleId !== '' && $userId !== '') {
            $this->UpdateVehicleUserId(
                $vehicleId,
                $userId
            );
        }

        // Connections zuerst aktualisieren, damit UserID und Connection-Daten
        // nach dem Connect auf dem aktuellen Stand sind.
        $this->SyncVehiclesFromConnectionsWithRetry();

        // Nur beim bewussten "Vehicle Access synchronisieren"-Flow:
        // bestehende Webhook-Subscription entfernen und neu erstellen.
        if ($syncVehicleId !== '') {
            if ($userId === '') {
                $userId =
                    $this->FindUserIdForVehicle(
                        $syncVehicleId
                    );
            }

            $resubscribeResult =
                $this->ResubscribeVehicleWebhook(
                    $syncVehicleId,
                    $userId
                );

            $this->SendDebug(
                'Webhook/ResubscribeResult',
                json_encode(
                    $resubscribeResult,
                    JSON_UNESCAPED_SLASHES |
                    JSON_UNESCAPED_UNICODE
                ),
                0
            );
        }

        if ($vehicleId !== '') {
            $instanceId =
                $this->FindVehicleInstanceByVehicleId(
                    $vehicleId
                );

            if (
                $instanceId > 0
                && function_exists(
                    'SMCARV_ApplySelectedCapabilities'
                )
            ) {
                SMCARV_ApplySelectedCapabilities(
                    $instanceId
                );

                $this->SendDebug(
                    'Connect/VehicleRefresh',
                    'Vehicle Access synchronisiert und Capabilities angewendet für Instanz ' .
                    $instanceId,
                    0
                );
            }
        } elseif ($userId !== '') {
            $instanceIds =
                @IPS_GetInstanceListByModuleID(
                    self::VEHICLE_MODULE_ID
                );

            if (is_array($instanceIds)) {
                foreach ($instanceIds as $instanceId) {
                    $instanceUserId =
                        (string)@IPS_GetProperty(
                            $instanceId,
                            'UserID'
                        );

                    if ($instanceUserId !== $userId) {
                        continue;
                    }

                    if (
                        function_exists(
                            'SMCARV_ApplySelectedCapabilities'
                        )
                    ) {
                        SMCARV_ApplySelectedCapabilities(
                            $instanceId
                        );
                    }

                    $this->SendDebug(
                        'Connect/UserRefresh',
                        'Capabilities angewendet für UserID=' .
                        $userId .
                        ', Instanz=' .
                        $instanceId,
                        0
                    );
                }
            }
        }

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');

        echo '<!doctype html><html><head><meta charset="utf-8"><title>Smartcar Connect</title>';
        echo '<style>body{font-family:Arial,sans-serif;margin:40px;color:#222}.ok{color:#16803c}</style>';
        echo '</head><body>';
        echo '<h2 class="ok">Smartcar erfolgreich verbunden</h2>';

        if ($syncVehicleId !== '') {
            echo '<p>Vehicle Access wurde synchronisiert. Die Webhook-Subscription wurde neu initialisiert.</p>';
        } else {
            echo '<p>Die Verbindung zum OEM wurde bestätigt.</p>';
        }

        echo '<p>Du kannst dieses Fenster jetzt schließen und zu IP-Symcon zurückkehren.</p>';
        echo '</body></html>';
    }

    private function ExtractVehicleIdFromVehicleAccessSyncState(string $state): string
    {
        if (preg_match('/^vehicle_access_sync_([0-9a-fA-F-]{36})_[0-9a-fA-F]+$/', $state, $m)) {
            return $m[1];
        }

        return '';
    }

    private function FindUserIdForVehicle(
        string $vehicleId
    ): string {
        $connections =
            $this->LoadConnections();

        foreach ($connections as $connection) {
            if (
                (string)(
                    $connection['vehicleId']
                    ?? ''
                ) !== $vehicleId
            ) {
                continue;
            }

            return
                (string)(
                    $connection['userId']
                    ?? ''
                );
        }

        return '';
    }

    private function GetWebhookIdForResubscribe(): string
    {
        return
            trim(
                $this->ReadAttributeString(
                    'LastWebhookID'
                )
            );
    }

    private function ResubscribeVehicleWebhook(
        string $vehicleId,
        string $userId
    ): array {
        $vehicleId =
            trim(
                $vehicleId
            );

        $userId =
            trim(
                $userId
            );

        $webhookId =
            $this->GetWebhookIdForResubscribe();

        if ($vehicleId === '') {
            return [
                'success' => false,
                'error' => 'VehicleID fehlt.'
            ];
        }

        if ($userId === '') {
            return [
                'success' => false,
                'error' => 'UserID fehlt.'
            ];
        }

        if ($webhookId === '') {
            return [
                'success' => false,
                'error' =>
                    'WebhookID fehlt. Es wurde bisher noch keine Webhook-ID automatisch empfangen.'
            ];
        }

        $token =
            $this->GetValidApplicationAccessToken();

        if ($token === '') {
            return [
                'success' => false,
                'error' =>
                    'Kein gültiges Application Access Token.'
            ];
        }

        $listUrl =
            'https://management.api.smartcar.com/v3/subscriptions?' .
            http_build_query(
                [
                    'filter[vehicleId]' =>
                        $vehicleId,
                    'filter[webhookId]' =>
                        $webhookId,
                    'page[size]' =>
                        100
                ],
                '',
                '&',
                PHP_QUERY_RFC3986
            );

        $listResponse =
            $this->HttpRequestRaw(
                'Webhook/ListSubscriptions',
                'GET',
                $listUrl,
                [
                    'Authorization: Bearer ' .
                    $token,
                    'Accept: application/json'
                ]
            );

        if (
            $listResponse === null
            || $listResponse['statusCode'] < 200
            || $listResponse['statusCode'] >= 300
        ) {
            return [
                'success' => false,
                'error' =>
                    'Subscriptions konnten nicht geladen werden.',
                'response' =>
                    $listResponse
            ];
        }

        $listData =
            json_decode(
                $listResponse['body'],
                true
            );

        $subscriptions =
            is_array(
                $listData['data']
                ?? null
            )
                ? $listData['data']
                : [];

        $deleted = [];

        foreach ($subscriptions as $subscription) {
            if (!is_array($subscription)) {
                continue;
            }

            $subscriptionId =
                trim(
                    (string)(
                        $subscription['id']
                        ?? ''
                    )
                );

            if ($subscriptionId === '') {
                continue;
            }

            $deleteUrl =
                'https://management.api.smartcar.com/v3/subscriptions/' .
                rawurlencode(
                    $subscriptionId
                );

            $deleteResponse =
                $this->HttpRequestRaw(
                    'Webhook/DeleteSubscription',
                    'DELETE',
                    $deleteUrl,
                    [
                        'Authorization: Bearer ' .
                        $token,
                        'Accept: application/json'
                    ]
                );

            $deleteOk =
                $deleteResponse !== null
                && $deleteResponse['statusCode'] >= 200
                && $deleteResponse['statusCode'] < 300;

            $deleted[] = [
                'subscriptionId' =>
                    $subscriptionId,
                'success' =>
                    $deleteOk,
                'statusCode' =>
                    $deleteResponse['statusCode']
                    ?? 0
            ];

            if (!$deleteOk) {
                return [
                    'success' => false,
                    'error' =>
                        'Bestehende Subscription konnte nicht entfernt werden.',
                    'deleted' =>
                        $deleted
                ];
            }
        }

        $createBody =
            json_encode(
                [
                    'data' => [
                        'attributes' => [
                            'webhookId' =>
                                $webhookId,
                            'userId' =>
                                $userId,
                            'vehicleId' =>
                                $vehicleId
                        ]
                    ]
                ],
                JSON_UNESCAPED_SLASHES |
                JSON_UNESCAPED_UNICODE
            );

        $createResponse =
            $this->HttpRequestRaw(
                'Webhook/CreateSubscription',
                'POST',
                'https://management.api.smartcar.com/v3/subscriptions',
                [
                    'Authorization: Bearer ' .
                    $token,
                    'Accept: application/json',
                    'Content-Type: application/json'
                ],
                $createBody
            );

        $createOk =
            $createResponse !== null
            && $createResponse['statusCode'] >= 200
            && $createResponse['statusCode'] < 300;

        return [
            'success' =>
                $createOk,
            'vehicleId' =>
                $vehicleId,
            'userId' =>
                $userId,
            'webhookId' =>
                $webhookId,
            'deleted' =>
                $deleted,
            'createStatusCode' =>
                $createResponse['statusCode']
                ?? 0,
            'createBody' =>
                $createResponse['body']
                ?? ''
        ];
    }

    private function SyncVehiclesFromConnectionsWithRetry(
        int $maxAttempts = 5
    ): void {
        $maxAttempts =
            max(
                1,
                $maxAttempts
            );

        for (
            $attempt = 1;
            $attempt <= $maxAttempts;
            $attempt++
        ) {
            $connections =
                $this->LoadConnections();

            $this->SendDebug(
                'Connect/SyncVehicles',
                'Versuch ' .
                $attempt .
                '/' .
                $maxAttempts .
                ', Connections=' .
                count($connections),
                0
            );

            if (!empty($connections)) {
                $this->UpdateVehiclesFromConnections(
                    $connections
                );
                return;
            }

            if (
                $attempt <
                $maxAttempts
            ) {
                usleep(500000);
            }
        }

        $this->SendDebug(
            'Connect/SyncVehicles',
            'Nach ' .
            $maxAttempts .
            ' Versuchen keine Connection gefunden.',
            0
        );
    }

    private function UpdateVehicleUserId(
        string $vehicleId,
        string $userId
    ): void {
        $instanceId =
            $this->FindVehicleInstanceByVehicleId(
                $vehicleId
            );

        if ($instanceId === 0) {
            $this->SendDebug(
                'Connect/UpdateUserID',
                'Keine Vehicle-Instanz für VehicleID=' .
                $vehicleId,
                0
            );
            return;
        }

        IPS_SetProperty(
            $instanceId,
            'UserID',
            $userId
        );

        IPS_ApplyChanges(
            $instanceId
        );

        if (
            function_exists(
                'SMCARV_ApplySelectedCapabilities'
            )
        ) {
            SMCARV_ApplySelectedCapabilities(
                $instanceId
            );
        }

        $this->SendDebug(
            'Connect/UpdateUserID',
            'UserID gespeichert in Instanz ' .
            $instanceId,
            0
        );
    }

    private function UpdateVehiclesFromConnections(
        array $connections
    ): void {
        foreach ($connections as $connection) {
            $vehicleId =
                (string)(
                    $connection['vehicleId']
                    ?? ''
                );

            if ($vehicleId === '') {
                continue;
            }

            $instanceId =
                $this->FindVehicleInstanceByVehicleId(
                    $vehicleId
                );

            $isNew = false;

            if ($instanceId === 0) {
                $instanceId =
                    IPS_CreateInstance(
                        self::VEHICLE_MODULE_ID
                    );

                $isNew = true;

                $this->SendDebug(
                    'Connect/CreateVehicle',
                    'Neue Vehicle-Instanz erstellt: ' .
                    $instanceId .
                    ' für VehicleID=' .
                    $vehicleId,
                    0
                );
            }

            $caption =
                trim(
                    (string)(
                        $connection['caption']
                        ?? ''
                    )
                );

            if ($caption === '') {
                $caption = $vehicleId;
            }

            IPS_SetProperty(
                $instanceId,
                'VehicleID',
                $vehicleId
            );

            IPS_SetProperty(
                $instanceId,
                'ConnectionID',
                (string)(
                    $connection['connectionId']
                    ?? ''
                )
            );

            IPS_SetProperty(
                $instanceId,
                'UserID',
                (string)(
                    $connection['userId']
                    ?? ''
                )
            );

            IPS_SetProperty(
                $instanceId,
                'VehicleCaption',
                $caption
            );

            IPS_SetProperty(
                $instanceId,
                'Make',
                (string)(
                    $connection['make']
                    ?? ''
                )
            );

            IPS_SetProperty(
                $instanceId,
                'Model',
                (string)(
                    $connection['model']
                    ?? ''
                )
            );

            IPS_SetProperty(
                $instanceId,
                'Year',
                (int)(
                    $connection['year']
                    ?? 0
                )
            );

            IPS_SetProperty(
                $instanceId,
                'PowertrainType',
                (string)(
                    $connection['powertrainType']
                    ?? ''
                )
            );

            IPS_SetName(
                $instanceId,
                $caption
            );

            @IPS_ConnectInstance(
                $instanceId,
                $this->InstanceID
            );

            if ($isNew) {
                $targetParentId =
                    IPS_GetParent(
                        $this->InstanceID
                    );

                if (
                    $targetParentId > 0
                    && IPS_GetParent(
                        $instanceId
                    ) !== $targetParentId
                ) {
                    @IPS_SetParent(
                        $instanceId,
                        $targetParentId
                    );
                }
            }

            IPS_ApplyChanges(
                $instanceId
            );
        }
    }

    private function FindVehicleInstanceByVehicleId(
        string $vehicleId
    ): int {
        $instanceIds =
            @IPS_GetInstanceListByModuleID(
                self::VEHICLE_MODULE_ID
            );

        if (!is_array($instanceIds)) {
            return 0;
        }

        foreach ($instanceIds as $instanceId) {
            if (
                (string)@IPS_GetProperty(
                    $instanceId,
                    'VehicleID'
                ) === $vehicleId
            ) {
                return (int)$instanceId;
            }
        }

        return 0;
    }

    private function ExtractVehicleIdFromState(
        string $state
    ): string {
        if (
            preg_match(
                '/(?:vehicle|reauth|vehicle_access_reauth)_([0-9a-fA-F-]{36})_/',
                $state,
                $m
            )
        ) {
            return $m[1];
        }

        return '';
    }

    private function HandleVehicleErrorWebhook(
        array $payload
    ): void {
        $data =
            is_array(
                $payload['data']
                ?? null
            )
            ? $payload['data']
            : [];

        $vehicleId =
            (string)(
                $payload['vehicleId']
                ?? $data['vehicle']['id']
                ?? $data['vehicleId']
                ?? ''
            );

        $err =
            $data['error']
            ?? (
                $data['errors'][0]
                ?? $data
            );

        if (!is_array($err)) {
            $err = [];
        }

        $this->SendDebug(
            'VEHICLE_ERROR',
            json_encode([
                'eventId' =>
                    $payload['eventId']
                    ?? '',
                'vehicleId' =>
                    $vehicleId,
                'type' =>
                    $err['type']
                    ?? '',
                'code' =>
                    $err['code']
                    ?? '',
                'description' =>
                    $err['description']
                    ?? (
                        $err['message']
                        ?? ''
                    ),
                'requestId' =>
                    $err['requestId']
                    ?? (
                        $err['requestID']
                        ?? ''
                    ),
                'docURL' =>
                    $err['docURL']
                    ?? (
                        $err['docUrl']
                        ?? ''
                    ),
                'resolution' =>
                    $err['resolution']
                    ?? null,
                'meta' =>
                    $payload['meta']
                    ?? []
            ], JSON_UNESCAPED_SLASHES |
               JSON_UNESCAPED_UNICODE),
            0
        );
    }
}
