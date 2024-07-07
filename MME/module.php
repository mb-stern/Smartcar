<?php

declare(strict_types=1);

class MercedesMe extends IPSModule
{
    private $clientID = 'b21c1221-a3d7-4d79-b3f8-053d648c13e1';
    private $clientSecret = 'b21c1221-a3d7-4d79-b3f8-053d648c13e1';
    private $authorizeURL = 'https://id.mercedes-benz.com/as/authorization.oauth2';
    private $tokenURL = 'https://id.mercedes-benz.com/as/token.oauth2';
    private $hookName = "/hook/MercedesMeOAuth";

    public function Create()
    {
        // Diese Zeile nicht löschen
        parent::Create();
        // Registriere Eigenschaften
        $this->RegisterPropertyString('ClientID', $this->clientID);
        $this->RegisterPropertyString('ClientSecret', $this->clientSecret);
        $this->RegisterAttributeString('AccessToken', '');

        // WebHook registrieren
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges()
    {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();

        // WebHook registrieren, falls Kernel bereits bereit ist
        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->RegisterHook($this->hookName);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        // Diese Zeile nicht löschen
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        // Wenn der Kernel bereit ist, registriere den WebHook
        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->RegisterHook($this->hookName);
        }
    }

    private function RegisterHook(string $hook)
    {
        IPS_LogMessage("MercedesMe", "RegisterHook aufgerufen");
        $ids = IPS_GetInstanceListByModuleID('{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}');
        if (count($ids) > 0) {
            $id = $ids[0];
            $hooks = IPS_GetProperty($id, 'Hooks');
            if (!$hooks) {
                $hooks = '[]'; // Initialisiere als leeres Array
            }
            $hooksArray = json_decode($hooks, true);

            $found = false;
            foreach ($hooksArray as $index => $hookEntry) {
                if ($hookEntry['Hook'] == $hook) {
                    if ($hookEntry['TargetID'] == $this->InstanceID) {
                        $found = true;
                        break;
                    } else {
                        $hooksArray[$index]['TargetID'] = $this->InstanceID;
                        $found = true;
                        break;
                    }
                }
            }
            if (!$found) {
                $hooksArray[] = ["Hook" => $hook, "TargetID" => $this->InstanceID];
            }

            IPS_SetProperty($id, 'Hooks', json_encode($hooksArray));
            IPS_ApplyChanges($id);
        } else {
            IPS_LogMessage("MercedesMe", "WebHook Instanz nicht gefunden");
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

    private function GetRedirectURI(): string
    {
        return 'https://oauth.ipmagic.de/authorize/' . $this->InstanceID;
    }

    private function GenerateAuthURL(string $clientID, string $redirectURI): string
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

    public function ExchangeAuthCodeForAccessToken(string $authCode)
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

    private function GetMercedesMeData(string $token)
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

    private function ProcessData(array $data)
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
        $this->SendDebug("MercedesMe", "ProcessOAuthData aufgerufen", 0);
        if ($_SERVER['REQUEST_URI'] == $this->hookName) {
            IPS_LogMessage("MercedesMe", "OAuth-Daten empfangen");
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
