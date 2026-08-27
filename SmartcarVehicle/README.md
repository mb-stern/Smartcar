# 🚗 Smartcar Integration für IP-Symcon (Vehicle Instanz)

## 1. Funktionsumfang

- Verbindung eines Fahrzeugs über Smartcar (Testfahrzeuge werden nicht unterstützt).
- Verarbeitung der über Smartcar Vehicle Access freigegebenen Fahrzeugdaten und Steuerfunktionen.
- Automatische Erkennung von Signalen, für die Smartcar tatsächlich gültige Daten liefert.
- Auswahl der gewünschten Signal- und Steuervariablen über eine Checkbox-Liste.
- Manuelle oder externe Abfrage aller, einzelner oder mehrerer Signale über die Smartcar API (Pull).
- Unterstützung von **Smartcar Signals (Webhooks)** zur automatischen Aktualisierung direkt vom OEM-Backend (Push).
- Steuerung unterstützter Funktionen wie Zentralverriegelung, Ladevorgang, Ladelimit und Navigationsziel.
- PHP-Funktionen zur Verwendung der Signalabfragen und Fahrzeugbefehle in eigenen IP-Symcon-Skripten.
- Automatische Erstellung und Verwaltung der ausgewählten Statusvariablen.
- Fehler- und Debug-Ausgaben im Symcon-Debug-Fenster.

---

## 2. Voraussetzungen

- IP-Symcon ab Version **8.2**
- Smartcar-Konto
- Mit Smartcar kompatibles Fahrzeug, das mit dem OEM-Portal verbunden ist
- Für Steuerbefehle müssen die jeweiligen `control_*`-Berechtigungen unter Smartcar Vehicle Access freigegeben sein.

---

## 3. Installation

- Das Modul kann direkt über den **Symcon Module Store** installiert werden.
- Während der Installation des Smartcar-Moduls ist zuerst der **Smartcar Configurator** zu erstellen.
- Splitter und Fahrzeug-Instanzen werden anschließend über den Konfigurator erstellt.
- Fahrzeug-Instanzen dürfen nicht automatisch aufgrund eingehender Nachrichten und nicht manuell angelegt werden.

---

## 4. Einrichten der Instanz

<img width="1781" height="888" alt="Smartcar Vehicle Instanz" src="https://github.com/user-attachments/assets/141ec08f-b3e9-4c36-b7ed-7bbdaa2bb5fa" />

Die Berechtigungen für Fahrzeugdaten und Steuerfunktionen werden unter **Smartcar → Configuration → Vehicle Access** festgelegt.

Die Fahrzeuginstanz verwendet keine separate Smartcar-Kompatibilitätsprüfung mehr. Stattdessen werden die tatsächlich vom Fahrzeug gelieferten Signale ausgewertet.

| Feld | Beschreibung |
| --- | --- |
| **OEM-Aktualisierungszeit** | Blendet für unterstützte Signale eine zusätzliche Variable mit der vom OEM gelieferten Aktualisierungszeit ein. |
| **Verfügbare Signal- und Steuervariablen** | Zeigt die vom Fahrzeug erkannten Signale und die über Vehicle Access freigegebenen Steuerfunktionen an. Über die Checkboxen können einzelne Variablen aktiviert oder deaktiviert werden. |
| **Vehicle Access synchronisieren** | Synchronisiert die zuvor unter Smartcar → Configuration → Vehicle Access geänderten Berechtigungen und aktualisiert die Fahrzeugverbindung. |
| **Signale aus Vehicle Access abrufen** | Ruft die aktuell verfügbaren Signale über die Smartcar API ab und aktualisiert die Liste der verfügbaren Variablen. |

### Auswahl der Variablen

Erkannte Signal- und Steuervariablen sind standardmäßig aktiviert.

Nicht benötigte Variablen können über die Checkbox-Liste deaktiviert werden. Beim Speichern der Konfiguration wird die entsprechende Variable aus dem Objektbaum entfernt.

Wird eine Variable später wieder aktiviert, wird sie erneut angelegt, sobald entsprechende Daten verfügbar sind.

> Bereits vorhandene Variablennamen werden vom Modul nicht überschrieben. Individuell vom Benutzer vergebene Namen bleiben dadurch erhalten.

---

## 5. Smartcar Signals

Es gibt mehrere Möglichkeiten, die Signale zu aktualisieren:

1. Über den Button **„Signale aus Vehicle Access abrufen“** im Konfigurationsformular (Pull)
2. Über ein Skript zum Abrufen aller, einzelner oder mehrerer Signale (Pull)
3. Automatisch über den Webhook, sobald Smartcar neue Daten liefert (Push)

Bei der Verarbeitung werden nur Signale berücksichtigt, die erfolgreich geliefert wurden und mindestens einen tatsächlichen Nutzwert enthalten.

`null`, leere Arrays oder leere Werte führen nicht zur Erstellung einer Signalvariable. Werte wie `0` oder `false` sind dagegen gültige Signalwerte.

### Alle, einzelne oder mehrere Signale abrufen

Ein Aufruf mit einer leeren Liste verwendet den gemeinsamen `/signals`-Endpunkt und verarbeitet alle verfügbaren, aktivierten Signale:

```php
SMCARV_FetchSelectedSignals(12345, []);
```

Wird ein Signalcode angegeben, ruft das Modul gezielt nur dieses Signal ab:

```php
SMCARV_FetchSelectedSignals(
    12345,
    ['tractionbattery-stateofcharge']
);
```

Es können auch mehrere Signalcodes übergeben werden:

```php
SMCARV_FetchSelectedSignals(
    12345,
    [
        'tractionbattery-stateofcharge',
        'charge-ischarging',
        'odometer-traveleddistance'
    ]
);
```

Mehrere ausgewählte Signale werden nacheinander über die jeweiligen Einzel-Signal-Endpunkte abgerufen. Bei vielen benötigten Signalen ist deshalb der gemeinsame Abruf mit `[]` effizienter. Für häufig benötigte Einzelwerte, beispielsweise den Batterieladestand, ist der gezielte Abruf sinnvoll.

Die PHP-Funktion liefert `true`, wenn alle angeforderten Abrufe erfolgreich waren. Sie liefert `false`, wenn mindestens ein Abruf nicht erfolgreich war.

### Signalgruppen und Befehle (Beispiele)

| Kategorie | Signaltyp / Befehlstyp | Beispiel-Signale / Befehle | Beschreibung |
| --- | --- | --- | --- |
| **Batterie & Laden** | `tractionbattery-*`, `charge-*` | `tractionbattery-stateofcharge`, `charge-ischarging`, `charge-chargelimits` | SOC, Ladezustand, Ladelimit |
| **Ladebefehle** | `charge-*` Commands | `charge-start`, `charge-stop`, `charge-set-limit` | Ladevorgang starten/stoppen, Ladelimit setzen |
| **Sicherheit & Türen** | `closure-*`, `security-*` | `closure-islocked`, `closure-doors`, `closure-windows` | Verriegelungsstatus, offene Türen/Fenster |
| **Türbefehle** | `security-*` Commands | `security-lock`, `security-unlock` | Fahrzeug verriegeln/entriegeln |
| **Navigation** | `location-*`, Navigation Command | `location-preciselocation`, `navigation-set-destination` | GPS-Position lesen und Navigationsziel als Koordinaten an das Fahrzeug senden |
| **Fahrzeugbewegung** | `location-*`, `odometer-*` | `location-preciselocation`, `odometer-traveleddistance` | GPS, Kilometerstand |
| **Fahrzeuginfo** | `vehicleidentification-*`, `internalcombustionengine-*` | `vehicleidentification-*`, `internalcombustionengine-fuellevel` | Stammdaten und Motorstatus |
| **Reifendruck** | `tires-*` | `tires-pressure` | Druckwerte der Reifen |
| **Konnektivität** | `connectivitystatus-*`, `connectivitysoftware-*` | `connectivitystatus-isonline`, `connectivitysoftware-currentfirmwareversion` | Online-Status, Firmware |
| **Klima** | `climate-*`, `climatecontrol-*` | `climate-externaltemperature`, `climatecontrol-isheateractive` | Innen-/Außentemperatur, Heizung |
| **Sonstige** | `vehicle-*`, `telematics-*`, `energy-*`, `evse-*` | `vehicle-speed`, `telematics-*`, `energy-*`, `evse-*` | Weitere von Smartcar bereitgestellte Fahrzeugdaten |

> Welche Signale und Befehle tatsächlich zur Verfügung stehen, hängt vom Fahrzeug, Hersteller, Smartcar-Plan und den unter Vehicle Access erteilten Berechtigungen ab.
>
> Das Modul verwendet keine separate Compatibility-Liste mehr. Entscheidend ist, welche Signale Smartcar erfolgreich mit gültigen Daten liefert und welche Steuerberechtigungen für die Fahrzeugverbindung zurückgegeben werden.

---

## 6. Statusvariablen und Profile

Profile werden automatisch angelegt.

Signalvariablen werden erstellt, sobald Smartcar dafür gültige Daten liefert und die entsprechende Variable im Konfigurationsformular aktiviert ist.

Steuervariablen werden entsprechend den von Smartcar für die Fahrzeugverbindung zurückgegebenen `control_*`-Berechtigungen angeboten und können ebenfalls einzeln aktiviert oder deaktiviert werden.

| Profil | Typ | Beschreibung |
| --- | --- | --- |
| `SMCAR.Odometer` | Float | Kilometerstand und Reichweite |
| `SMCAR.Pressure` | Float | Reifendruck in bar |
| `SMCAR.Progress` | Float | Prozentwerte, beispielsweise Batterieladestand, Ladelimit, Tankfüllstand oder Öl-Lebensdauer |
| `SMCAR.Status` | String | Statusanzeige |
| `SMCAR.Charge` | String | Ladezustand |
| `SMCAR.Power` | Float | Ladeleistung in kW |
| `SMCAR.Energy` | Float | Energie in kWh |
| `SMCAR.TimeMinutes` | Integer | Zeitangaben in Minuten |
| `SMCAR.LatLon` | Float | GPS-Koordinaten (Latitude/Longitude) |

---

## 7. WebFront

Unterstützte Fahrzeugfunktionen können direkt aus dem WebFront gesteuert werden, beispielsweise:

- Fahrzeug verriegeln oder entriegeln
- Ladelimit setzen
- Ladevorgang starten oder stoppen
- Navigationsziel an das Fahrzeug senden

Welche Steuerfunktionen verfügbar sind, richtet sich nach den von Smartcar für die aktuelle Fahrzeugverbindung zurückgegebenen `control_*`-Berechtigungen.

Die verfügbaren Steuervariablen werden im Konfigurationsformular angezeigt und können dort einzeln aktiviert oder deaktiviert werden.

### Navigationsziel im WebFront

Die Variable **„Ziel setzen“** akzeptiert die Koordinaten in der Reihenfolge Breitengrad und Längengrad:

```text
47.3769,8.5417
```

Alternativ kann das Ziel als JSON eingegeben werden:

```json
{"latitude":47.3769,"longitude":8.5417}
```

Der Breitengrad muss zwischen `-90` und `90` liegen. Der Längengrad muss zwischen `-180` und `180` liegen.

Eine Adresse oder ein Ortsname kann nicht direkt übergeben werden. Die Smartcar-V3-API erwartet geografische Koordinaten.

---

## 8. PHP-Befehlsreferenz

Die Zahl `12345` steht in den folgenden Beispielen für die Instanz-ID der Smartcar-Vehicle-Instanz.

### Signale abrufen

| Befehl | Beschreibung |
| --- | --- |
| `SMCARV_FetchSelectedSignals(12345, []);` | Alle aktivierten Signale über den gemeinsamen Signal-Endpunkt abrufen |
| `SMCARV_FetchSelectedSignals(12345, ['tractionbattery-stateofcharge']);` | Nur das angegebene Signal abrufen |
| `SMCARV_FetchSelectedSignals(12345, ['tractionbattery-stateofcharge', 'charge-ischarging']);` | Mehrere angegebene Signale nacheinander abrufen |

### Fahrzeugbefehle senden

| Befehl | Beschreibung |
| --- | --- |
| `SMCARV_StartCharging(12345);` | Ladevorgang starten |
| `SMCARV_StopCharging(12345);` | Ladevorgang stoppen |
| `SMCARV_SetChargeLimit(12345, 80);` | Ladelimit auf 80 Prozent setzen |
| `SMCARV_LockDoors(12345);` | Fahrzeug verriegeln |
| `SMCARV_UnlockDoors(12345);` | Fahrzeug entriegeln |
| `SMCARV_SetNavigationDestination(12345, 47.3769, 8.5417);` | Navigationsziel mit Breitengrad und Längengrad senden |

Die Befehlsfunktionen liefern `true`, wenn Smartcar den Befehl erfolgreich angenommen beziehungsweise ausgeführt hat. Bei einem nicht erfolgreichen API-Aufruf liefern sie `false`.

Für ein Navigationsziel werden die Koordinaten geprüft:

```php
$VehicleInstanceID = 12345;
$Latitude = 47.3769;
$Longitude = 8.5417;

$success = SMCARV_SetNavigationDestination(
    $VehicleInstanceID,
    $Latitude,
    $Longitude
);

if (!$success) {
    echo 'Das Navigationsziel konnte nicht gesendet werden.';
}
```

Die verfügbaren Signale können über **„Signale aus Vehicle Access abrufen“** ermittelt werden. Steuerbefehle stehen nur zur Verfügung, wenn die dazugehörige Berechtigung unter Smartcar Vehicle Access freigegeben und vom Fahrzeug unterstützt wird.

---

## 9. Verhalten bei API und Webhook

API-Abfragen und Webhooks verwenden dieselbe Signalverarbeitung.

Ein Signal wird nur als erfolgreich verarbeitet, wenn:

- Smartcar einen erfolgreichen Status zurückliefert und
- mindestens ein tatsächlicher Nutzwert enthalten ist.

Nicht erfolgreiche oder leere Signale führen nicht zur Erstellung neuer Variablen.

Deaktivierte Variablen werden durch nachfolgende API-Abfragen oder Webhooks nicht automatisch wieder aktiviert.

Die Namen bereits vorhandener Variablen werden bei späteren Aktualisierungen nicht verändert.

Bei einem gezielten Abruf mehrerer Signale wird jeder Signalcode separat abgefragt. Schlägt mindestens ein Abruf fehl, liefert `SMCARV_FetchSelectedSignals()` den Rückgabewert `false`. Erfolgreich gelieferte Signale werden trotzdem verarbeitet.

---

## 10. Bekannte Einschränkungen

- Simulierte Fahrzeuge werden aktuell nicht unterstützt.
- Nicht jedes Fahrzeug stellt alle von Smartcar definierten Signale oder Steuerfunktionen bereit.
- Die Verfügbarkeit einzelner Daten und Befehle kann zusätzlich vom Hersteller, Fahrzeugmodell, Smartcar-Plan und den Vehicle-Access-Berechtigungen abhängen.
- Navigationsziele können nur als Breitengrad und Längengrad übergeben werden. Eine Geocodierung von Adressen ist nicht Bestandteil des Moduls.
- Mehrere gezielt angeforderte Signale erzeugen mehrere einzelne API-Aufrufe. Für eine größere Anzahl von Signalen sollte der gemeinsame Abruf mit `SMCARV_FetchSelectedSignals(12345, []);` verwendet werden.
- Ladezeitpläne werden derzeit nicht über die Smartcar-API verwaltet. Zeitabhängiges Starten und Stoppen des Ladevorgangs kann über IP-Symcon-Ereignisse und die öffentlichen Ladebefehle umgesetzt werden.

---

## 11. Lizenz

Dieses Modul steht unter der **MIT-Lizenz**.

© 2025 Stefan Künzli

https://opensource.org/licenses/MIT
