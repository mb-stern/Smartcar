<?php

class MercedesMe extends IPSModule {

    private function RequestAuthCode() {
        $email = $this->ReadPropertyString('Email');
        $url = "https://id.mercedes-benz.com/as/authorization.oauth2";
        $data = [
            "response_type" => "code",
            "client_id" => "dein_client_id",
            "redirect_uri" => "deine_redirect_uri",
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
            "client_id" => "dein_client_id",
            "client_secret" => "dein_client_secret",
            "redirect_uri" => "deine_redirect_uri"
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
    