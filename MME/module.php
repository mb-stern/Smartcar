<?php

class MercedesMe extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString("Email", "");
        $this->RegisterPropertyString("Password", "");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function RequestAuthToken()
    {
        $email = $this->ReadPropertyString("Email");
        $password = $this->ReadPropertyString("Password");

        if (empty($email) || empty($password)) {
            $this->SendDebug("RequestAuthToken", "E-Mail oder Passwort ist nicht gesetzt.", 0);
            return;
        }

        $url = "https://id.mercedes-benz.com/as/token.oauth2";
        $postData = [
            'grant_type' => 'password',
            'client_id' => '01398c1c-dc45-4b42-882b-9f5ba9f175f1',
            'scope' => 'openid email phone profile offline_access',
            'username' => $email,
            'password' => $password
        ];

        $this->SendDebug("RequestAuthToken", "URL: $url", 0);
        $this->SendDebug("RequestAuthToken", "Post-Daten: " . json_encode($postData), 0);

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Content-Type: application/x-www-form-urlencoded"]
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $this->SendDebug("RequestAuthToken", "HTTP-Code: $httpCode", 0);
        $this->SendDebug("RequestAuthToken", "Antwort: $response", 0);

        if ($httpCode === 200) {
            $this->SendDebug("RequestAuthToken", "Token erfolgreich abgerufen.", 0);
            // Hier kannst du den Token verarbeiten und speichern, wenn nötig
        } else {
            $this->SendDebug("RequestAuthToken", "Fehler beim Abrufen des Tokens. Antwortcode: $httpCode", 0);
        }
    }

    public function UpdateData()
    {
        // Hier würde der Code zur Datenaktualisierung eingefügt werden
        $this->SendDebug("UpdateData", "Daten werden aktualisiert.", 0);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'RequestAuthToken':
                $this->RequestAuthToken();
                break;
            case 'UpdateData':
                $this->UpdateData();
                break;
        }
    }
}
?>
