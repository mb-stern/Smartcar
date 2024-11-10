<?php

class MercedesMe extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString("Email", "");
        $this->RegisterPropertyString("AccessCode", "");
        $this->RegisterPropertyString("AccessToken", "");
        $this->RegisterPropertyInteger("UpdateInterval", 60);

        // Timer für regelmäßige Updates
        $this->RegisterTimer("UpdateData", 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateData", 0);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Variablen für Fahrzeugdaten registrieren
        $this->MaintainVariable("FuelLevel", "Kraftstoffstand", VARIABLETYPE_INTEGER, "~Battery.100", 0, true);
        $this->MaintainVariable("Mileage", "Kilometerstand", VARIABLETYPE_FLOAT, "", 1, true);

        // Timer-Intervall setzen
        $interval = $this->ReadPropertyInteger("UpdateInterval") * 1000;
        $this->SetTimerInterval("UpdateData", $interval);

        if ($this->ReadPropertyString("AccessCode") !== "") {
            $this->Authenticate();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case "RequestAuthCode":
                $this->RequestAuthCode();
                break;
            case "Authenticate":
                $this->Authenticate();
                break;
            case "UpdateData":
                $this->UpdateData();
                break;
            default:
                throw new Exception("Invalid Ident");
        }
    }

    private function RequestAuthCode()
    {
        $email = $this->ReadPropertyString("Email");
    
        if (empty($email)) {
            $this->SendDebug("RequestAuthCode", "E-Mail-Adresse ist nicht gesetzt.", 0);
            return;
        }
    
        $url = "https://api.mercedes-benz.com/auth/request-code"; // Beispiel-Endpoint, den der Adapter nutzen könnte
    
        $postData = [
            'email' => $email
        ];
    
        $this->SendDebug("RequestAuthCode", "URL: $url", 0);
        $this->SendDebug("RequestAuthCode", "Post-Daten: " . json_encode($postData), 0);
    
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json"
            ]
        ]);
    
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
    
        $this->SendDebug("RequestAuthCode", "HTTP-Code: $httpCode", 0);
        $this->SendDebug("RequestAuthCode", "Antwort: $response", 0);
    
        if ($httpCode === 200) {
            $this->SendDebug("RequestAuthCode", "Anfrage erfolgreich. Überprüfen Sie Ihre E-Mails auf den Authentifizierungscode.", 0);
        } else {
            $this->SendDebug("RequestAuthCode", "Fehler beim Anfordern des Codes. Antwortcode: $httpCode", 0);
        }
    }
    

    private function Authenticate()
    {
        $email = $this->ReadPropertyString("Email");
        $code = $this->ReadPropertyString("AccessCode");

        $url = "https://id.mercedes-benz.com/as/token.oauth2";
        $postData = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => 'your-client-id',
            'redirect_uri' => 'your-redirect-uri',
            'scope' => 'openid vehicleStatus',
        ];

        $this->SendDebug("Authenticate", "Sende Authentifizierungsdaten für $email", 0);

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

        $this->SendDebug("Authenticate", "HTTP-Code: $httpCode", 0);
        $this->SendDebug("Authenticate", "Antwort: $response", 0);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $accessToken = $data['access_token'] ?? '';

            if ($accessToken) {
                $this->SetBuffer("AccessToken", $accessToken);
                $this->SendDebug("Authenticate", "Token erfolgreich empfangen.", 0);
            }
        } else {
            $this->SendDebug("Authenticate", "Fehler beim Abrufen des Tokens. Antwortcode: $httpCode", 0);
        }
    }

    public function UpdateData()
    {
        $token = $this->GetBuffer("AccessToken");
        if (!$token) {
            $this->SendDebug("UpdateData", "Kein gültiger Access Token vorhanden.", 0);
            return;
        }

        $url = "https://api.mercedes-benz.com/vehicledata/v2/vehicles";
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $token"
            ]
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        $vehicleData = json_decode($response, true);
        $this->SetValue("FuelLevel", $vehicleData['fuelLevel'] ?? 0);
        $this->SetValue("Mileage", $vehicleData['mileage'] ?? 0);
    }
}

?>
