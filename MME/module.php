<?php

class MercedesMe extends IPSModule {

    private $clientID = 'b21c1221-a3d7-4d79-b3f8-053d648c13e1';
    private $clientSecret = 'b21c1221-a3d7-4d79-b3f8-053d648c13e1';

    public function Create() {
        // Diese Zeile nicht löschen
        parent::Create();
        // Registriere Eigenschaften
        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('ClientID', $this->clientID);
        $this->RegisterPropertyString('ClientSecret', $this->clientSecret);
        // Registriere Attribute
        $this->RegisterAttributeString('AuthCode', '');
        $this->RegisterAttributeString('AccessToken', '');
    }

    public function ApplyChanges() {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();
    }

    public function GetConfigurationForm() {
        return file_get_contents(__DIR__ . '/form.json');
    }

    public function RequestAction($Ident, $Value) {
        switch ($Ident) {
            case 'RequestCode':
                $this->RequestCode();
                break;
            default:
                throw new Exception("Invalid action");
        }
    }

    public function RequestCode() {
        IPS_LogMessage("MercedesMe", "RequestCode aufgerufen");
        $email = $this->ReadPropertyString('Email');
        $password = $this->ReadPropertyString('Password');
        $clientID = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');

        IPS_LogMessage("MercedesMe", "Email: $email, Password: $password, ClientID: $clientID, ClientSecret: $clientSecret");

        if ($email && $password && $clientID && $clientSecret) {
            $authCode = $this->GenerateAuthCode($email, $password, $clientID, $clientSecret);
            if ($authCode) {
                $accessToken = $this->ExchangeAuthCodeForAccessToken($authCode, $clientID, $clientSecret);
                if ($accessToken) {
                    $this->WriteAttributeString('AccessToken', $accessToken);
                    echo "Der Authentifizierungscode wurde erfolgreich gegen ein Access Token ausgetauscht.";
                } else {
                    echo "Fehler beim Austausch des Authentifizierungscodes gegen ein Access Token.";
                }
            } else {
                echo "Fehler beim Generieren des Authentifizierungscodes.";
            }
        } else {
            echo "Bitte geben Sie Ihre E-Mail, Ihr Passwort, die Client ID und das Client Secret ein.";
        }
    }

    private function GenerateAuthCode($email, $password, $clientID, $clientSecret) {
        // Logik zum Generieren des Authentifizierungscodes
        IPS_LogMessage("MercedesMe", "GenerateAuthCode aufgerufen");
        // Diese URL und die Parameter müssen eventuell angepasst werden
        $url = "https://id.mercedes-benz.com/as/authorization.oauth2";
        $data = [
            "response_type" => "code",
            "client_id" => $clientID,
            "redirect_uri" => "https://your-redirect-uri",
            "scope" => "your-required-scopes"
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
            $error = curl_error($curl);
            IPS_LogMessage("MercedesMe", "HTTP request failed: " . $error);
            echo "Fehler beim Generieren des Authentifizierungscodes: HTTP Status Code " . $httpCode . " - " . $error;
            curl_close($curl);
            return null;
        }

        curl_close($curl);
        IPS_LogMessage("MercedesMe", "Result: " . $result);
        // Extrahiere den Authentifizierungscode aus der Antwort
        $response = json_decode($result, true);
        return $response['code'] ?? null;
    }

    private function ExchangeAuthCodeForAccessToken($authCode, $clientID, $clientSecret) {
        IPS_LogMessage("MercedesMe", "ExchangeAuthCodeForAccessToken aufgerufen");
        $url = "https://id.mercedes-benz.com/as/token.oauth2";
        $data = [
            "grant_type" => "authorization_code",
            "code" => $authCode,
            "client_id" => $clientID,
            "client_secret" => $clientSecret,
            "redirect_uri" => "https://your-redirect-uri"
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
            $error = curl_error($curl);
            IPS_LogMessage("MercedesMe", "HTTP request failed: " . $error);
            echo "Fehler beim Austausch des Authentifizierungscodes: HTTP Status Code " . $httpCode . " - " . $error;
            curl_close($curl);
            return null;
        }

        curl_close($curl);
        IPS_LogMessage("MercedesMe", "Result: " . $result);
        // Extrahiere den Access Token aus der Antwort
        $response = json_decode($result, true);
        return $response['access_token'] ?? null;
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
