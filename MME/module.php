<?php

class MercedesMe extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString("Email", "");
        $this->RegisterPropertyString("ClientID", "01398c1c-dc45-4b42-882b-9f5ba9f175f1"); // Beispiel-Client-ID
        $this->RegisterPropertyString("RedirectURI", "https://your-redirect-uri"); // Anpassbare Redirect URI
        $this->RegisterPropertyString("AuthCode", "");
        $this->RegisterPropertyString("AccessToken", "");
        $this->RegisterPropertyString("RefreshToken", "");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function RequestAuthCode()
    {
        $email = $this->ReadPropertyString("Email");
        $clientID = $this->ReadPropertyString("ClientID");
        $redirectURI = $this->ReadPropertyString("RedirectURI");

        if (empty($email)) {
            $this->SendDebug("RequestAuthCode", "E-Mail-Adresse ist nicht gesetzt.", 0);
            return;
        }

        $url = "https://id.mercedes-benz.com/as/authorization.oauth2";
        $postData = [
            'client_id' => $clientID,
            'response_type' => 'code',
            'redirect_uri' => $redirectURI,
            'scope' => 'openid vehicleStatus',
        ];

        $this->SendDebug("RequestAuthCode", "URL: $url", 0);
        $this->SendDebug("RequestAuthCode", "Post-Daten: " . json_encode($postData), 0);

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/x-www-form-urlencoded"
            ]
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $this->SendDebug("RequestAuthCode", "HTTP-Code: $httpCode", 0);
        $this->SendDebug("RequestAuthCode", "Antwort: $response", 0);

        if ($httpCode === 200) {
            $this->SendDebug("RequestAuthCode", "Anfrage erfolgreich. Authentifizierungscode gesendet.", 0);
        } else {
            $this->SendDebug("RequestAuthCode", "Fehler beim Anfordern des Codes. Antwortcode: $httpCode", 0);
        }
    }

    public function RequestAuthToken()
    {
        $clientID = $this->ReadPropertyString("ClientID");
        $redirectURI = $this->ReadPropertyString("RedirectURI");
        $authCode = $this->ReadPropertyString("AuthCode");

        if (empty($authCode)) {
            $this->SendDebug("RequestAuthToken", "Kein Authentifizierungscode vorhanden.", 0);
            return;
        }

        $url = "https://id.mercedes-benz.com/as/token.oauth2";
        $postData = [
            'grant_type' => 'authorization_code',
            'client_id' => $clientID,
            'redirect_uri' => $redirectURI,
            'code' => $authCode
        ];

        $this->SendDebug("RequestAuthToken", "URL: $url", 0);
        $this->SendDebug("RequestAuthToken", "Post-Daten: " . json_encode($postData), 0);

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/x-www-form-urlencoded"
            ]
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $this->SendDebug("RequestAuthToken", "HTTP-Code: $httpCode", 0);
        $this->SendDebug("RequestAuthToken", "Antwort: $response", 0);

        if ($httpCode === 200) {
            $tokenData = json_decode($response, true);
            $this->WriteAttributeString("AccessToken", $tokenData['access_token']);
            $this->WriteAttributeString("RefreshToken", $tokenData['refresh_token']);
            $this->SendDebug("RequestAuthToken", "Token erfolgreich abgerufen.", 0);
        } else {
            $this->SendDebug("RequestAuthToken", "Fehler beim Abrufen des Tokens. Antwortcode: $httpCode", 0);
        }
    }
}
