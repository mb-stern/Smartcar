<?php

class MercedesMe extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString("Email", "");
        $this->RegisterPropertyString("AccessCode", "");
        $this->RegisterPropertyString("AccessToken", "");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if ($this->ReadPropertyString("Email") && $this->ReadPropertyString("AccessCode")) {
            $this->Authenticate();
        }
    }

    private function Authenticate()
    {
        $email = $this->ReadPropertyString("Email");
        $code = $this->ReadPropertyString("AccessCode");

        // Anfrage an den Token-Endpunkt
        $url = "https://id.mercedes-benz.com/as/token.oauth2";
        $postData = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://your-redirect-uri.com',
            'client_id' => 'your-client-id',
            'client_secret' => 'your-client-secret'
        ];

        $options = [
            'http' => [
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($postData),
            ],
        ];

        $context  = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        if ($result === FALSE) {
            // Fehlerbehandlung
            return;
        }

        $response = json_decode($result, true);
        if (isset($response['access_token'])) {
            $this->UpdateAccessToken($response['access_token']);
        }
    }

    private function UpdateAccessToken($token)
    {
        IPS_SetProperty($this->InstanceID, 'AccessToken', $token);
        IPS_ApplyChanges($this->InstanceID);
    }
}
