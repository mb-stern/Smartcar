<?php

class MercedesMe extends IPSModule {

    private $hookName = "MercedesMeWebHook";

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('ConnectURL', '');
        $this->RegisterPropertyString('AuthCode', '');
        $this->RegisterAttributeString('AccessToken', '');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        $authCode = $this->ReadPropertyString('AuthCode');
        if ($authCode) {
            $this->ExchangeAuthCodeForAccessToken($authCode);
        }
    }

    public function GetConfigurationForm() {
        return file_get_contents(__DIR__ . '/form.json');
    }

    public function RequestAction($Ident, $Value) {
        switch ($Ident) {
            case 'RequestCode':
                $this->RequestCode();
                break;
            case 'ExchangeAuthCode':
                $this->ExchangeAuthCodeForAccessToken($Value);
                break;
            default:
                throw new Exception("Invalid action");
        }
    }

    public function RequestCode() {
        IPS_LogMessage("MercedesMe", "RequestCode aufgerufen");
        $email = $this->ReadPropertyString('Email');
        $connectURL = rtrim($this->ReadPropertyString('ConnectURL'), '/');
        $redirectURI = $connectURL . '/hook/' . $this->hookName;

        IPS_LogMessage("MercedesMe", "Email: $email, RedirectURI: $redirectURI");

        if ($email && $redirectURI) {
            // Hier sollte der Code hinzugefügt werden, um den Authentifizierungscode anzufordern
            // Angenommen, dies ist eine Anfrage an die Mercedes-Benz API
            $authURL = $this->GenerateAuthURL($email, $redirectURI);
            IPS_LogMessage("MercedesMe", "Auth URL: $authURL");
            echo "Bitte öffnen Sie folgenden Link in Ihrem Browser, um den Authentifizierungscode zu erhalten: $authURL";
        } else {
            echo "Bitte geben Sie die E-Mail-Adresse und die Redirect URI ein.";
        }
    }

    private function GenerateAuthURL($email, $redirectURI) {
        $url = "https://id.mercedes-benz.com/as/authorization.oauth2";
        $data = [
            "response_type" => "code",
            "email" => $email,
            "redirect_uri" => urlencode($redirectURI),
            "scope" => "openid"
        ];

        $query = http_build_query($data);
        return $url . "?" . $query;
    }

    private function ExchangeAuthCodeForAccessToken(string $authCode) {
        IPS_LogMessage("MercedesMe", "ExchangeAuthCodeForAccessToken aufgerufen");
        $connectURL = rtrim($this->ReadPropertyString('ConnectURL'), '/');
        $redirectURI = $connectURL . '/hook/' . $this->hookName;

        $url = "https://id.mercedes-benz.com/as/token.oauth2";
        $data = [
            "grant_type" => "authorization_code",
            "code" => $authCode,
            "redirect_uri" => $redirectURI
        ];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/x-www-form-urlencoded",
                "User-Agent: Mozilla/5.0"
            ],
        ];

        $curl = curl_init();
        curl_setopt_array($curl, $options);
        $result = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        IPS_LogMessage("MercedesMe", "HTTP Code: " . $httpCode);
        IPS_LogMessage("MercedesMe", "Result: " . $result);

        if ($result === FALSE || $httpCode != 200) {
            $error = curl_error($curl);
            IPS_LogMessage("MercedesMe", "HTTP request failed: " . $error);
            echo "Fehler beim Austausch des Authentifizierungscodes: HTTP Status Code " . $httpCode . " - " . $error;
            curl_close($curl);
            return null;
        }

        curl_close($curl);
        IPS_LogMessage("MercedesMe", "Result: " . $result);
        $response = json_decode($result, true);
        $accessToken = $response['access_token'] ?? null;
        if ($accessToken) {
            $this->WriteAttributeString('AccessToken', $accessToken);
            echo "Access Token erfolgreich empfangen und gespeichert.";
        } else {
            echo "Fehler beim Erhalten des Access Tokens.";
        }
    }

    public function RequestData() {
        IPS_LogMessage("MercedesMe", "RequestData aufgerufen");
        $token = $this->ReadAttributeString('AccessToken');

        if ($token) {
            $data = $this->GetMercedesMeData($token);
            $this->ProcessData($data);
        } else {
            echo "Bitte geben Sie den Access Token ein.";
        }
    }

    private function GetMercedesMeData($token) {
        IPS_LogMessage("MercedesMe", "GetMercedesMeData aufgerufen");
        $url = "https://api.mercedes-benz.com/vehicledata/v2/vehicles";
        $options = [
            "http" => [
                "header" => "Authorization: Bearer $token"
            ]
        ];
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        if ($result === FALSE) {
            $error = error_get_last();
            IPS_LogMessage("MercedesMe", "HTTP request failed: " . $error['message']);
            return null;
        }
        IPS_LogMessage("MercedesMe", "Result: $result");
        return json_decode($result, true);
    }

    private function ProcessData($data) {
        IPS_LogMessage("MercedesMe", "ProcessData aufgerufen");
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $this->MaintainVariable($key, $key, VARIABLETYPE_STRING, '', 0, true);
            $this->SetValue($key, $value);
        }
    }
}
?>
