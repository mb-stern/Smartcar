<?php

class MercedesMe extends IPSModule {

    private $clientID = 'b21c1221-a3d7-4d79-b3f8-053d648c13e1';
    private $clientSecret = 'b21c1221-a3d7-4d79-b3f8-053d648c13e1';
    private $hookName = "/hook/MercedesMeWebHook";

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

        // WebHook registrieren
        $this->RegisterHook($this->hookName);
    }

    public function ApplyChanges() {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();

        // Überprüfe, ob ein AuthCode vorhanden ist und tausche ihn gegen ein Access Token ein
        $authCode = $this->ReadAttributeString('AuthCode');
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
            default:
                throw new Exception("Invalid action");
        }
    }

    public function RequestCode() {
        IPS_LogMessage("MercedesMe", "RequestCode aufgerufen");
        $clientID = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $redirectURI = 'http://' . $_SERVER['SERVER_ADDR'] . ':' . $_SERVER['SERVER_PORT'] . $this->hookName;

        IPS_LogMessage("MercedesMe", "ClientID: $clientID, ClientSecret: $clientSecret");

        if ($clientID && $clientSecret && $redirectURI) {
            $authURL = $this->GenerateAuthURL($clientID, $redirectURI);
            echo "Bitte öffnen Sie folgenden Link in Ihrem Browser, um den Authentifizierungscode zu erhalten: $authURL";
        } else {
            echo "Bitte geben Sie die Client ID, das Client Secret und die Redirect URI ein.";
        }
    }

    private function GenerateAuthURL($clientID, $redirectURI) {
        $url = "https://id.mercedes-benz.com/as/authorization.oauth2";
        $data = [
            "response_type" => "code",
            "client_id" => $clientID,
            "redirect_uri" => $redirectURI,
            "scope" => "openid" // Hier kannst du die erforderlichen Scopes hinzufügen
        ];

        $query = http_build_query($data);
        return $url . "?" . $query;
    }

    private function ExchangeAuthCodeForAccessToken($authCode) {
        IPS_LogMessage("MercedesMe", "ExchangeAuthCodeForAccessToken aufgerufen");
        $clientID = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');
        $redirectURI = 'http://' . $_SERVER['SERVER_ADDR'] . ':' . $_SERVER['SERVER_PORT'] . $this->hookName;

        $url = "https://id.mercedes-benz.com/as/token.oauth2";
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
        // Extrahiere den Access Token aus der Antwort
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

    private function RegisterHook($hook) {
        // WebHook registrieren
        $ids = IPS_GetInstanceListByModuleID("{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}"); // ID der WebHook Control Instanz
        if (count($ids) > 0) {
            $id = $ids[0];
            $data = IPS_GetProperty($id, "Hooks");
            $data = json_decode($data, true);

            if (!is_array($data)) {
                $data = [];
            }

            $found = false;
            foreach ($data as $index => $entry) {
                if ($entry['Hook'] == $hook) {
                    if ($entry['TargetID'] == $this->InstanceID) {
                        return;
                    } else {
                        $data[$index]['TargetID'] = $this->InstanceID;
                        $found = true;
                    }
                }
            }

            if (!$found) {
                $data[] = [
                    "Hook" => $hook,
                    "TargetID" => $this->InstanceID
                ];
            }

            IPS_SetProperty($id, "Hooks", json_encode($data));
            IPS_ApplyChanges($id);
        }
    }
}
?>
