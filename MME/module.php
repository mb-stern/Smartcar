<?php

class MercedesMe extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();

        $this->RegisterPropertyString("Email", "");
        $this->RegisterPropertyString("ClientID", "");
        $this->RegisterPropertyString("RedirectURI", "");
        $this->RegisterPropertyString("AuthCode", ""); // Für den Eingabecode
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'RequestAuthCode':
                $this->RequestAuthCode();
                break;
            case 'RequestAuthToken':
                $this->RequestAuthToken();
                break;
            default:
                throw new Exception("Invalid Ident");
        }
    }

    private function RequestAuthCode()
    {
        $email = $this->ReadPropertyString("Email");
        $clientID = $this->ReadPropertyString("ClientID");
        $redirectURI = $this->ReadPropertyString("RedirectURI");

        if (empty($email) || empty($clientID) || empty($redirectURI)) {
            $this->SendDebug("RequestAuthCode", "Fehlende Parameter", 0);
            return;
        }

        $url = "https://id.mercedes-benz.com/as/authorization.oauth2";
        $postData = [
            'client_id' => $clientID,
            'response_type' => 'code',
            'redirect_uri' => $redirectURI,
            'scope' => 'openid vehicleStatus',
            'email' => $email
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
            $this->SendDebug("RequestAuthCode", "Anfrage erfolgreich. Bitte prüfen Sie Ihre E-Mails.", 0);
        } else {
            $this->SendDebug("RequestAuthCode", "Fehler beim Anfordern des Codes. Antwortcode: $httpCode", 0);
        }
    }

    private function RequestAuthToken()
    {
        $email = $this->ReadPropertyString("Email");
        $authCode = $this->ReadPropertyString("AuthCode");
        $clientID = $this->ReadPropertyString("ClientID");

        if (empty($email) || empty($authCode) || empty($clientID)) {
            $this->SendDebug("RequestAuthToken", "Fehlende Parameter", 0);
            return;
        }

        $url = "https://id.mercedes-benz.com/as/token.oauth2";
        $postData = [
            'grant_type' => 'authorization_code',
            'client_id' => $clientID,
            'code' => $authCode,
            'redirect_uri' => $this->ReadPropertyString("RedirectURI")
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
            $this->SendDebug("RequestAuthToken", "Token erfolgreich abgerufen.", 0);
        } else {
            $this->SendDebug("RequestAuthToken", "Fehler beim Abrufen des Tokens. Antwortcode: $httpCode", 0);
        }
    }
}
