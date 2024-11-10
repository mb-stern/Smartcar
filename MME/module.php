<?php

class MercedesMe extends IPSModule
{
    private $clientId = '01398c1c-dc45-4b42-882b-9f5ba9f175f1';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString("Email", "");
        $this->RegisterPropertyString("LoginCode", "");
        $this->RegisterPropertyString("CountryCode", "DE");
        $this->RegisterPropertyString("AcceptLanguage", "de-DE");
        $this->RegisterTimer("UpdateData", 0, 'MercedesMe_RequestAuthToken($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if ($this->ReadPropertyString("Email") && $this->ReadPropertyString("LoginCode")) {
            $this->RequestAuthToken();
        }
    }

    private function RequestAuthToken()
    {
        $email = $this->ReadPropertyString("Email");
        $loginCode = $this->ReadPropertyString("LoginCode");

        if (empty($email) || empty($loginCode)) {
            $this->SendDebug("RequestAuthToken", "E-Mail-Adresse oder Login-Code nicht gesetzt.", 0);
            return;
        }

        $nonce = uniqid();
        $headers = [
            "Content-Type: application/x-www-form-urlencoded",
            "Accept-Language: " . $this->ReadPropertyString("AcceptLanguage"),
            "X-Authmode: KEYCLOAK",
            "X-SessionId: " . uniqid()
        ];

        $postData = http_build_query([
            'client_id' => $this->clientId,
            'grant_type' => 'password',
            'username' => $email,
            'password' => $nonce . ':' . $loginCode,
            'scope' => 'openid email phone profile offline_access ciam-uid'
        ]);

        $this->SendDebug("RequestAuthToken", "URL: https://id.mercedes-benz.com/as/token.oauth2", 0);
        $this->SendDebug("RequestAuthToken", "Post-Daten: " . $postData, 0);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://id.mercedes-benz.com/as/token.oauth2",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->SendDebug("RequestAuthToken", "HTTP-Code: $httpCode", 0);
        $this->SendDebug("RequestAuthToken", "Antwort: $response", 0);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (isset($data['access_token'])) {
                $this->SetBuffer("AccessToken", $data['access_token']);
                $this->SetBuffer("RefreshToken", $data['refresh_token']);
                $this->SendDebug("RequestAuthToken", "Token erhalten und gespeichert.", 0);
            } else {
                $this->SendDebug("RequestAuthToken", "Kein Token erhalten.", 0);
            }
        } else {
            $this->SendDebug("RequestAuthToken", "Fehler beim Anfordern des Tokens. Antwortcode: $httpCode", 0);
        }
    }

    public function UpdateData()
    {
        $accessToken = $this->GetBuffer("AccessToken");

        if (!$accessToken) {
            $this->SendDebug("UpdateData", "Kein gültiger Access Token vorhanden.", 0);
            return;
        }

        $headers = [
            "Authorization: Bearer " . $accessToken,
            "Accept: application/json",
            "Accept-Language: " . $this->ReadPropertyString("AcceptLanguage")
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://bff.emea-prod.mobilesdk.mercedes-benz.com/v2/vehicles?locale=" . $this->ReadPropertyString("AcceptLanguage"),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->SendDebug("UpdateData", "HTTP-Code: $httpCode", 0);
        $this->SendDebug("UpdateData", "Antwort: $response", 0);

        if ($httpCode === 200) {
            $this->SendDebug("UpdateData", "Daten erfolgreich abgerufen.", 0);
        } else {
            $this->SendDebug("UpdateData", "Fehler beim Abrufen der Daten. Antwortcode: $httpCode", 0);
        }
    }
}
?>
