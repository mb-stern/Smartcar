<?php

class MercedesMe extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString("Email", "");
        $this->RegisterPropertyString("AccessCode", "");
        $this->RegisterPropertyInteger("UpdateInterval", 60);

        // Timer for regular updates
        $this->RegisterTimer("UpdateData", 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateData", 0);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->MaintainVariable("FuelLevel", "Fuel Level", VARIABLETYPE_INTEGER, "~Battery.100", 0, true);
        $this->MaintainVariable("Mileage", "Mileage", VARIABLETYPE_FLOAT, "", 1, true);

        $interval = $this->ReadPropertyInteger("UpdateInterval") * 1000;
        $this->SetTimerInterval("UpdateData", $interval);

        if ($this->ReadPropertyString("Email") && $this->ReadPropertyString("AccessCode")) {
            $this->Authenticate();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
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

    public function UpdateData()
    {
        $data = $this->FetchVehicleData();
        if ($data) {
            $this->SetValue("FuelLevel", $data['fuelLevel']);
            $this->SetValue("Mileage", $data['mileage']);
        }
    }

    private function Authenticate()
    {
        $email = $this->ReadPropertyString("Email");
        $accessCode = $this->ReadPropertyString("AccessCode");

        if ($email && $accessCode) {
            // Hier kommt die eigentliche Authentifizierung mit der Mercedes Me API.
            IPS_LogMessage("MercedesMe", "Authentifizierung für $email gestartet.");
        } else {
            IPS_LogMessage("MercedesMe", "E-Mail oder Zugangscode fehlt.");
        }
    }

    private function FetchVehicleData()
    {
        return [
            'fuelLevel' => 75, 
            'mileage' => 15000.0 
        ];
    }
}

?>
