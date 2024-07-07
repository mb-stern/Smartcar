<?php

class MercedesMe extends IPSModule {

    private $clientID = 'b21c1221-a3d7-4d79-b3f8-053d648c13e1';
    private $clientSecret = 'b21c1221-a3d7-4d79-b3f8-053d648c13e1';

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
    }

    public function ApplyChanges() {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();
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
        $email = $this->ReadPropertyString('Email');
        $password = $this->ReadPropertyString('Password');
        $clientID = $this->ReadPropertyString('ClientID');
        $clientSecret = $this->ReadPropertyString('ClientSecret');

        IPS_LogMessage("MercedesMe", "Email: $email, Password: $password, ClientID: $clientID, ClientSecret: $clientSecret");

        if ($email && $password && $clientID && $clientSecret) {
            $response = $this->SendAuthCodeRequest($email, $password, $clientID, $clientSecret);
            IPS_LogMessage("MercedesMe", "Response: " . print_r($response, true));
            if (isset($response['access_token'])) {
                $this->WriteAttributeString('AccessToken', $response['access_token']);
                echo "Der Authentifizierungscode wurde an Ihre E-Mail-Adresse gesendet.";
            } else {
                echo "Fehler beim Anfordern des Authentifizierungscodes.";
            }
        } else {
            echo "Bitte geben Sie Ihre E-Mail, Ihr Passwort, die Client ID und das Client Secret ein.";
        }
    }

    private function SendAuthCodeRequest($email, $password, $clientID, $clientSecret) {
        IPS_LogMessage("MercedesMe", "SendAuthCodeRequest aufgerufen");
        $url = "https://id.mercedes-benz.com/as/token.oauth2";
        $data = [
            "client_id" => $clientID,
            "client_secret" => $clientSecret,
            "grant_type" => "password",
            "username" => $email,
            "password" => $password
        ];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/x-www-form-urlencoded",
                "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.36"
            ],
        ];

        $curl = curl_init();
        curl_setopt_array($curl, $options);
        $result = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if ($result === FALSE || $httpCode != 200) {
            $error = curl_error($curl);
            IPS_LogMessage("MercedesMe", "HTTP request failed: " . $error);
            echo "Fehler beim Anfordern des Authentifizierungscodes: HTTP Status Code " . $httpCode . " - " . $error;
            curl_close($curl);
            return null;
        }

        curl_close($curl);
        IPS_LogMessage("MercedesMe", "Result: " . $result);
        return json_decode($result, true);
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
}

?>
