<?php

declare(strict_types=1);

class MercedesMe extends IPSModule
{
    private $clientID = 'b21c1221-a3d7-4d79-b3f8-053d648c13e1';
    private $clientSecret = 'b21c1221-a3d7-4d79-b3f8-053d648c13e1';
    private $authorizeURL = 'https://id.mercedes-benz.com/as/authorization.oauth2';
    private $tokenURL = 'https://id.mercedes-benz.com/as/token.oauth2';

    public function Create()
    {
        // Diese Zeile nicht löschen
        parent::Create();
        // Registriere Eigenschaften
        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('ClientID', $this->clientID);
        $this->RegisterPropertyString('ClientSecret', $this->clientSecret);
        $this->RegisterAttributeString('AccessToken', '');

        // WebOAuth Konfiguration
        $this->RegisterOAuth($this->clientID);
    }

    public function ApplyChanges()
    {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();

        // Überprüfe, ob ein AccessToken vorhanden ist
        $accessToken = $this->ReadAttributeString('AccessToken');
        if ($accessToken) {
            // Abruf von Daten initialisieren
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'RequestData':
                $this->RequestData();
                break;
            default:
                throw new Exception("Invalid action");
        }
    }

    public function RequestData()
    {
        IPS_LogMessage("MercedesMe", "RequestData aufgerufen");
        $token = $this->ReadAttributeString('AccessToken');

        if ($token) {
            $data = $this->GetMercedesMeData($token);
            $this->ProcessData($data);
        } else {
            $this->RequestAuthorizationCode();
        }
    }

    private function RequestAuthorizationCode()
    {
        IPS_LogMessage("MercedesMe", "RequestAuthorizationCode aufgerufen");
        $clientID = $this->ReadPropertyString('ClientID');
        $redirectURI = $this->GetRedirectURI();

        $authURL = $this->GenerateAuthURL($clientID, $redirectURI);
        $this->SendDebug("AuthURL", $authURL, 0);
        echo "Bitte öffnen Sie folgenden Link in Ihrem Browser, um den Authentifizierungscode zu erhalten: $authURL";
    }

    private function GetRedirectURI()
    {
        return 'https://oauth.ipmagic.de/authorize/' . $this->InstanceID;
    }

    private function GenerateAuthURL($clientID, $redirectURI)
    {
        $url = $this->authorizeURL;
        $data = [
            "response_type" => "code",
            "client_id" => $clientID,
            "redirect_uri" => $redirectURI,
            "scope" => "openid"
        ];

        return $url . "?" . http_build_query($data);
    }

    public function ExchangeAuthCodeForAccessToken($authCode)
    {
        IPS_LogMessage("MercedesMe", "ExchangeAuthCodeForAccessToken aufgerufen");
        $clientID = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $redirectURI = $this->GetRedirectURI();

        $url = $this->tokenURL;
        $data = [
            "grant_type" => "authorization_code",
            "code" => $authCode,
            "client_id" => $clientID,
            "client_secret" => $clientSecret,
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

    private function GetMercedesMeData($token)
    {
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

    private function ProcessData($data)
    {
        IPS_LogMessage("MercedesMe", "ProcessData aufgerufen");
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $this->MaintainVariable($key, $key, VARIABLETYPE_STRING, '', 0, true);
            $this->SetValue($key, $value);
        }
    }

    protected function ProcessOAuthData()
    {
        if ($_SERVER['REQUEST_URI'] == $this->hookName) {
            IPS_LogMessage("MercedesMe", "WebHook empfangen");
            $code = $_GET['code'] ?? '';
            if ($code) {
                $this->ExchangeAuthCodeForAccessToken($code);
                echo "Authentifizierungscode erhalten.";
            } else {
                echo "Kein Authentifizierungscode erhalten.";
            }
        }
    }
}
?>
