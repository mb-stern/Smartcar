<?php

class MercedesMe extends IPSModule {

    private $clientID = 'e4a5de35-6fa0-4093-a1fa-01a3e3dced4e'; 
    private $clientSecret = '7d0c7a22-d293-4902-a7db-04ad1d36474b'; 
    private $redirectURI = 'https://b66f003aec754cc62ffe1660b37d1c05.ipmagic.de/hook/MercedesMeWebHook';
    private $hookName = "/hook/MercedesMeWebHook";

    public function Create() {
        parent::Create();
        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('AuthCode', '');
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterHook($this->hookName);
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
            case 'RefreshToken':
                $this->RefreshToken();
                break;
            default:
                throw new Exception("Invalid action");
        }
    }

    private function RequestAuthCode() {
        $email = $this->ReadPropertyString('Email');
        $nonce = bin2hex(random_bytes(16));
        $url = "https://id.mercedes-benz.com/as/authorization.oauth2";
        $data = [
            "response_type" => "code",
            "client_id" => $this->clientID,
            "redirect_uri" => $this->redirectURI,
            "scope" => "openid email profile",
            "email" => $email,
            "nonce" => $nonce
        ];

        $query = http_build_query($data);
        $authURL = $url . "?" . $query;
        IPS_LogMessage("MercedesMe", "Auth URL: $authURL");
        echo "Bitte öffnen Sie diesen Link: $authURL";
        header("Location: $authURL");
        exit;
    }

    private function ExchangeAuthCodeForToken($authCode) {
        $url = "https://id.mercedes-benz.com/as/token.oauth2";
        $nonce = bin2hex(random_bytes(16));
        $data = [
            "grant_type" => "authorization_code",
            "code" => $authCode,
            "client_id" => $this->clientID,
            "client_secret" => $this->clientSecret,
            "redirect_uri" => $this->redirectURI,
            "nonce" => $nonce
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
        IPS_LogMessage("MercedesMe", "HTTP Code: $httpCode");
        IPS_LogMessage("MercedesMe", "Result: $result");

        if ($result === FALSE || $httpCode != 200) {
            $error = curl_error($curl);
            IPS_LogMessage("MercedesMe", "Fehler: $error");
            echo "Fehler beim Austausch des Authentifizierungscodes: HTTP Status Code $httpCode - $error";
            curl_close($curl);
            return null;
        }

        curl_close($curl);
        $response = json_decode($result, true);
        if (isset($response['access_token'])) {
            $this->WriteAttributeString('AccessToken', $response['access_token']);
            $this->WriteAttributeString('RefreshToken', $response['refresh_token']);
            echo "Access Token erfolgreich empfangen und gespeichert.";
        } else {
            echo "Fehler beim Erhalten des Access Tokens.";
        }
    }

    private function RefreshToken() {
        $refreshToken = $this->ReadAttributeString('RefreshToken');
        $url = "https://id.mercedes-benz.com/as/token.oauth2";
        $data = [
            "grant_type" => "refresh_token",
            "refresh_token" => $refreshToken,
            "client_id" => $this->clientID,
            "client_secret" => $this->clientSecret
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
        IPS_LogMessage("MercedesMe", "HTTP Code: $httpCode");
        IPS_LogMessage("MercedesMe", "Result: $result");

        if ($result === FALSE || $httpCode != 200) {
            $error = curl_error($curl);
            IPS_LogMessage("MercedesMe", "Fehler: $error");
            echo "Fehler beim Aktualisieren des Tokens: HTTP Status Code $httpCode - $error";
            curl_close($curl);
            return null;
        }

        curl_close($curl);
        $response = json_decode($result, true);
        if (isset($response['access_token'])) {
            $this->WriteAttributeString('AccessToken', $response['access_token']);
            $this->WriteAttributeString('RefreshToken', $response['refresh_token']);
            echo "Token erfolgreich aktualisiert.";
        } else {
            echo "Fehler beim Aktualisieren des Tokens.";
        }
    }

    private function RegisterHook($hook) {
        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
            return;
        }

        $ids = IPS_GetInstanceListByModuleID("{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}");
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

    protected function ProcessHookData() {
        $hook = explode('/', $_SERVER['REQUEST_URI']);
        $hook = end($hook);
        if ($hook == "MercedesMeWebHook") {
            IPS_LogMessage("MercedesMe", "WebHook empfangen");
            $code = $_GET['code'] ?? '';
            if ($code) {
                $this->WriteAttributeString('AuthCode', $code);
                IPS_ApplyChanges($this->InstanceID);
                echo "Authentifizierungscode erhalten.";
            } else {
                echo "Kein Authentifizierungscode erhalten.";
            }
        }
    }
}

?>
