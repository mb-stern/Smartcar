<?php

class MercedesMe extends IPSModule
{
    private $atoken;
    private $rtoken;

    public function Create()
    {
        parent::Create();
        
        // Register properties
        $this->RegisterPropertyString("Email", "");
        $this->RegisterPropertyString("LoginCode", "");
        $this->RegisterPropertyInteger("UpdateInterval", 60);

        // Timer for regular updates
        $this->RegisterTimer("UpdateData", 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateData", 0);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Set timer interval based on user-defined update frequency
        $interval = $this->ReadPropertyInteger("UpdateInterval") * 1000 * 60;
        $this->SetTimerInterval("UpdateData", $interval);

        $this->atoken = $this->GetBuffer("AccessToken");
        $this->rtoken = $this->GetBuffer("RefreshToken");

        // Request auth code if no access token is set
        if (!$this->atoken) {
            $this->RequestAuthCode();
        } else {
            $this->UpdateData();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case "RequestAuthCode":
                $this->RequestAuthCode();
                break;
            case "UpdateData":
                $this->UpdateData();
                break;
            default:
                throw new Exception("Invalid Ident");
        }
    }

    public function RequestAuthCode()
    {
        $email = $this->ReadPropertyString("Email");

        if (empty($email)) {
            $this->SendDebug("RequestAuthCode", "E-Mail-Adresse fehlt.", 0);
            return;
        }

        $url = "https://id.mercedes-benz.com/as/authorization.oauth2";
        $postData = [
            'client_id' => 'your-client-id',  
            'response_type' => 'code',
            'redirect_uri' => 'your-redirect-uri',
            'scope' => 'openid vehicleStatus',
            'email' => $email
        ];

        $headers = [
            "Content-Type: application/x-www-form-urlencoded"
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $this->SendDebug("RequestAuthCode", "HTTP-Code: $httpCode", 0);
        $this->SendDebug("RequestAuthCode", "Antwort: $response", 0);

        if ($httpCode === 200) {
            $this->SendDebug("RequestAuthCode", "Überprüfen Sie Ihre E-Mails auf den Authentifizierungscode.", 0);
        } else {
            $this->SendDebug("RequestAuthCode", "Fehler beim Anfordern des Codes. Antwortcode: $httpCode", 0);
        }
    }

    public function UpdateData()
    {
        if (!$this->atoken) {
            $this->SendDebug("UpdateData", "Kein gültiger Access Token vorhanden.", 0);
            return;
        }

        $url = "https://bff.emea-prod.mobilesdk.mercedes-benz.com//v2/vehicles";
        $headers = [
            "Authorization: Bearer " . $this->atoken,
            "Content-Type: application/json"
        ];

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
