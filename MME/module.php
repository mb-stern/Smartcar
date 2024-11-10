<?php

class MercedesMe extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Property for vehicle ID
        $this->RegisterPropertyString("VehicleID", "");

        // Timer for regular updates
        $this->RegisterTimer("UpdateData", 0, 'MercedesMe_UpdateData($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();

        // Create variables for data we want to store
        $this->MaintainVariable("BatteryLevel", "Battery Level", vtInteger, "~Battery.100", 0, true);
        $this->MaintainVariable("Odometer", "Odometer", vtFloat, "", 1, true);
        $this->MaintainVariable("Range", "Range", vtFloat, "", 2, true);

        // Set timer interval if vehicle ID is set
        $interval = ($this->ReadPropertyString("VehicleID") != "") ? 60 * 1000 : 0; // 1 Minute
        $this->SetTimerInterval("UpdateData", $interval);
    }

    public function UpdateData()
    {
        $vehicleID = $this->ReadPropertyString("VehicleID");

        if (empty($vehicleID)) {
            IPS_LogMessage("MercedesMe", "Vehicle ID is not set.");
            return;
        }

        // Here you would implement the data fetching logic, e.g., using cURL
        // For demonstration, we use some example data
        $batteryLevel = 85; // Example value
        $odometer = 12345.67; // Example value in km
        $range = 400.0; // Example value in km

        // Update variables
        $this->SetValue("BatteryLevel", $batteryLevel);
        $this->SetValue("Odometer", $odometer);
        $this->SetValue("Range", $range);
    }
}

?>
