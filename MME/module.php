<?php

class MercedesMe extends IPSModule
{
    private $baseHeader;
    private $atoken;
    private $rtoken;

    public function Create()
    {
        parent::Create();
        
        // Register properties
        $this->RegisterPropertyString("Email", "");
        $this->RegisterPropertyString("LoginCode", "");
        $this->RegisterPropertyInteger("UpdateInterval", 60);

        // Initialize header for API requests
        $this->baseHeader = [
            "Content-Type" => "application/x-www-form-urlencoded",
            "User-Agent" => "MyCar/2168 CFNetwork/1494.0.7 Darwin/23.4.0",
            "X-ApplicationName" => "mycar-store-ece",
            "RIS-OS-Name" => "ios",
            "RIS-SDK-Version" => "9.114.0"
        ];
        
        $this->RegisterTimer("UpdateData", 0, 'MercedesMe_UpdateData($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Set the timer interval
        $interval = $this->ReadPropertyInteger("UpdateInterval") * 1000 * 60; // in minutes
        $this->SetTimerInterval("UpdateData", $interval);

        // Check if tokens are stored
        $this->atoken = $this->GetBuffer("AccessToken");
        $this->rtoken = $this->GetBuffer("RefreshToken");

        if (!$this->atoken) {
            $this->RequestAuthCode();
        } else {
            $this->UpdateData();
        }
    }

    private function RequestAuthCode()
    {
        $email = $this->ReadPropertyString("Email");
        $loginCode = $this->ReadPropertyString("LoginCode");

        if (empty($email)) {
            $this->SendDebug("RequestAuthCode", "E-Mail-Adresse fehlt.", 0);
            return;
        }

        // Mercedes API Login URL and data
        $url = "https://id.mercedes-benz.com/as/token.oauth2";
        $postData = [
            "client_id" => "01398c1c-dc45-4b42-882b-9f5ba9f175f1",
            "grant_type" => "password",
            "username" => urlencode($email),
            "password" => $loginCode
        ];

        $this->SendDebug("RequestAuthCode", "URL: $url", 0);
        $this->SendDebug("RequestAuthCode", "Post-Daten: " . json_encode($postData), 0);

        // HTTP request for access token
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->baseHeader
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $this->SendDebug("RequestAuthCode", "HTTP-Code: $httpCode", 0);
        $this->SendDebug("RequestAuthCode", "Antwort: $response", 0);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $this->atoken = $data['access_token'];
            $this->rtoken = $data['refresh_token'];
            $this->SetBuffer("AccessToken", $this->atoken);
            $this->SetBuffer("RefreshToken", $this->rtoken);
            $this->UpdateData();
        } else {
            $this->SendDebug("RequestAuthCode", "Authentifizierung fehlgeschlagen.", 0);
        }
    }

    public function UpdateData()
    {
        if (!$this->atoken) {
            $this->SendDebug("UpdateData", "Kein gültiger Access Token vorhanden.", 0);
            return;
        }

        $url = "https://bff.emea-prod.mobilesdk.mercedes-benz.com//v2/vehicles";
        $headers = $this->baseHeader;
        $headers["Authorization"] = "Bearer " . $this->atoken;

        // API request to get vehicle data
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $this->SendDebug("UpdateData", "HTTP-Code: $httpCode", 0);
        $this->SendDebug("UpdateData", "Antwort: $response", 0);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (isset($data['assignedVehicles'])) {
                foreach ($data['assignedVehicles'] as $vehicle) {
                    $this->SetValue("VehicleData", json_encode($vehicle));
                }
            }
        } else {
            $this->SendDebug("UpdateData", "Fehler beim Abrufen der Fahrzeugdaten.", 0);
        }
    }
}
?>
