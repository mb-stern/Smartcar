<?php

declare(strict_types=1);

class SMCAR extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Eigenschaften definieren
        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('RedirectURI', '');
        $this->RegisterPropertyString('AccessToken', '');
        $this->RegisterPropertyString('VIN', ''); // Fahrgestellnummer
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterHook('/hook/smartcar');
    }

    public function ConnectVehicle()
    {
        $this->LogMessage('Fahrzeug wird verbunden...', KL_NOTIFY);
        // Logik für die Fahrzeugverbindung
    }

    private function RegisterHook(string $Hook)
    {
        $ID = @IPS_GetObjectIDByIdent('WebHook', 0);
        if ($ID === false) {
            $ID = IPS_CreateInstance("{Webhook-Modul-GUID}");
            IPS_SetIdent($ID, 'WebHook');
            IPS_SetParent($ID, $this->InstanceID);
        }
        IPS_SetProperty($ID, 'Hook', $Hook);
        IPS_ApplyChanges($ID);
    }
}
