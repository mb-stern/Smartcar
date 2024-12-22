<?php

class SmartcarConfigurator extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('Mode', 'simulated');
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeString('RedirectURI', '');
        $this->RegisterAttributeString('CurrentHook', '');
        $this->RegisterTimer('TokenRefreshTimer', 0, 'SMCAR_RefreshAccessToken($id);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterWebhook();
        $this->SetRedirectURI();

        if (!empty($this->ReadAttributeString('AccessToken')) && !empty($this->ReadAttributeString('RefreshToken'))) {
            $this->SetTimerInterval('TokenRefreshTimer', 90 * 60 * 1000); // Alle 90 Minuten
        } else {
            $this->SetTimerInterval('TokenRefreshTimer', 0);
        }
    }

    public function GetConfigurationForm()
    {
        $form = [
            'elements' => [
                ['type' => 'Label', 'caption' => 'Smartcar Konfigurator'],
                ['type' => 'ValidationTextBox', 'name' => 'ClientID', 'caption' => 'Client ID'],
                ['type' => 'ValidationTextBox', 'name' => 'ClientSecret', 'caption' => 'Client Secret'],
                [
                    'type'    => 'Select',
                    'name'    => 'Mode',
                    'caption' => 'Betriebsmodus',
                    'options' => [
                        ['caption' => 'Simuliert', 'value' => 'simulated'],
                        ['caption' => 'Live', 'value' => 'live']
                    ]
                ],
                ['type' => 'Button', 'caption' => 'Smartcar verbinden', 'onClick' => 'SMCAR_GenerateAuthURL($id);'],
                ['type' => 'Button', 'caption' => 'Fahrzeuge abrufen', 'onClick' => 'SMCAR_FetchVehicles($id);'],
                [
                    'type'   => 'List',
                    'name'   => 'Vehicles',
                    'caption' => 'Gefundene Fahrzeuge',
                    'rowCount' => 5,
                    'add'    => false,
                    'delete' => false,
                    'columns' => [['caption' => 'Fahrzeug ID', 'name' => 'id', 'width' => '400px']],
                    'values' => []
                ]
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Instanz für Fahrzeug erstellen', 'onClick' => 'SMCAR_CreateVehicleInstance($id, $Vehicles[0][\'id\']);'],
                ['type' => 'Label', 'caption' => 'Hinweis: Wählen Sie ein Fahrzeug aus der Liste aus.']
            ]
        ];

        $form['elements'][] = ['type' => 'Label', 'caption' => 'Redirect URI: ' . $this->ReadAttributeString('RedirectURI')];

        return json_encode($form);
    }

    public function GenerateAuthURL()
    {
        $clientID = $this->ReadPropertyString('ClientID');
        $redirectURI = $this->ReadAttributeString('RedirectURI');
        $mode = $this->ReadPropertyString('Mode');

        if (empty($clientID) || empty($redirectURI)) {
            echo "Fehler: Client ID oder Redirect URI ist nicht gesetzt!";
            return;
        }

        $authURL = 'https://connect.smartcar.com/oauth/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $clientID,
            'redirect_uri'  => $redirectURI,
            'scope'         => 'read_vehicle_info',
            'state'         => bin2hex(random_bytes(8)),
            'mode'          => $mode
        ]);

        echo $authURL;
    }

    public function ProcessHookData()
    {
        if (!isset($_GET['code'])) {
            $this->SendDebug('ProcessHookData', 'Kein Autorisierungscode erhalten.', 0);
            echo "Fehler: Kein Code erhalten.";
            return;
        }

        $authCode = $_GET['code'];
        $this->RequestAccessToken($authCode);
        echo "Fahrzeug erfolgreich verbunden!";
    }

    private function RequestAccessToken(string $authCode)
    {
        $clientID = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $redirectURI = $this->ReadAttributeString('RedirectURI');

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
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => $postData
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        $responseData = json_decode($response, true);

        if (isset($responseData['access_token'], $responseData['refresh_token'])) {
            $this->WriteAttributeString('AccessToken', $responseData['access_token']);
            $this->WriteAttributeString('RefreshToken', $responseData['refresh_token']);
            $this->SetTimerInterval('TokenRefreshTimer', 90 * 60 * 1000); // 90 Minuten
            $this->SendDebug('RequestAccessToken', 'Access und Refresh Token erfolgreich erhalten.', 0);
        } else {
            $this->SendDebug('RequestAccessToken', 'Fehler beim Abrufen der Tokens: ' . $response, 0);
        }
    }

    public function RefreshAccessToken()
    {
        $clientID = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $refreshToken = $this->ReadAttributeString('RefreshToken');

        $url = 'https://auth.smartcar.com/oauth/token';
        $postData = http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $clientID,
            'client_secret' => $clientSecret
        ]);

        $options = [
            'http' => [
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => $postData
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        $responseData = json_decode($response, true);

        if (isset($responseData['access_token'], $responseData['refresh_token'])) {
            $this->WriteAttributeString('AccessToken', $responseData['access_token']);
            $this->WriteAttributeString('RefreshToken', $responseData['refresh_token']);
            $this->SendDebug('RefreshAccessToken', 'Token erfolgreich erneuert.', 0);
        } else {
            $this->SendDebug('RefreshAccessToken', 'Fehler beim Erneuern der Tokens: ' . $response, 0);
        }
    }

    public function FetchVehicles()
    {
        $accessToken = $this->ReadAttributeString('AccessToken');
        if (empty($accessToken)) {
            echo "Fehler: Kein Access Token vorhanden!";
            return;
        }

        $url = 'https://api.smartcar.com/v2.0/vehicles';
        $options = [
            'http' => [
                'header'  => "Authorization: Bearer $accessToken\r\n",
                'method'  => 'GET'
            ]
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        $data = json_decode($response, true);

        if (!isset($data['vehicles'])) {
            echo "Fehler: Keine Fahrzeuge gefunden!";
            return;
        }

        $vehicles = array_map(fn($id) => ['id' => $id], $data['vehicles']);
        $this->UpdateFormField('Vehicles', 'values', json_encode($vehicles));
    }

    private function RegisterWebhook()
    {
        $hookPath = '/hook/smartcar_configurator_' . $this->InstanceID;
        $this->WriteAttributeString('CurrentHook', $hookPath);

        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (empty($ids)) {
            return;
        }

        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
        $hooks[] = ['Hook' => $hookPath, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }

    private function SetRedirectURI()
    {
        $connectInstances = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}');
        if (!empty($connectInstances)) {
            $connectID = $connectInstances[0];
            $connectURL = CC_GetUrl($connectID);
            if ($connectURL !== false) {
                $hookPath = $this->ReadAttributeString('CurrentHook');
                $this->WriteAttributeString('RedirectURI', $connectURL . $hookPath);
            }
        }
    }
}
