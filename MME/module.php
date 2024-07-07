<?php

class MercedesMe extends IPSModule {

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('AuthCode', '');
        $this->RegisterAttributeString('AccessToken', '');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        $authCode = $this->ReadPropertyString('AuthCode');
        if ($authCode) {
            $this->ExchangeAuthCodeForToken($authCode);
        }
    }

    public function GetConfigurationForm() {
        return file_get_contents(__DIR__ . '/form.json');
    }

    public function RequestAction($Ident, $Value) {
        switch ($Ident) {
            case 'RequestCode':
                $this->RequestAuthCode();
                break;
            case 'ExchangeAuthCode':
                $this->ExchangeAuthCodeForToken($Value);
                break;
            default:
                throw new Exception("Invalid action");
        }
    }

    private function RequestAuthCode() {
        $email = $this->ReadPropertyString('Email');
        $url = "https://id.mercedes-benz.com/as/authorization.oauth2";
        $data = [
            "response_type" => "code",
            "client_id" => "dein_client_id", // Ersetzen Sie dies mit Ihrer tatsächlichen Client-ID
            "redirect_uri" => "deine_redirect_uri", // Ersetzen Sie dies mit Ihrer tatsächlichen Redirect-URI
            "scope" => "openid",
            "email" => $email
        ];
    
        $query = http_build_query($data);
        $authURL = $url . "?" . $query;
        echo "Bitte öffnen Sie diesen Link: $authURL";
    }
    
    private function ExchangeAuthCodeForToken($authCode) {
        $url = "https://id.mercedes-benz.com/as/token.oauth2";
        $data = [
            "grant_type" => "authorization_code",
            "code" => $authCode,
            "client_id" => "dein_client_id", // Ersetzen Sie dies mit Ihrer tatsächlichen Client-ID
            "client_secret" => "dein_client_secret", // Ersetzen Sie dies mit Ihrem tatsächlichen Client-Secret
            "redirect_uri" => "deine_redirect_uri" // Ersetzen Sie dies mit Ihrer tatsächlichen Redirect-URI
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
    
        if ($result === FALSE || $httpCode != 200) {
            echo "Fehler beim Austausch des Authentifizierungscodes: HTTP Status Code $httpCode - " . curl_error($curl);
            curl_close($curl);
            return null;
        }
    
        curl_close($curl);
        $response = json_decode($result, true);
        if (isset($response['access_token'])) {
            $this->WriteAttributeString('AccessToken', $response['access_token']);
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
