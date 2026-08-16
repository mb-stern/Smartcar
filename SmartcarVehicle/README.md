# 🚗 Smartcar Integration für IP-Symcon (Vehicle Instanz)

## 1. Funktionsumfang

- Verbindung eines Fahrzeugs über Smartcar (Testfahrzeuge werden nicht unterstützt).
- Verarbeitung der über Smartcar Vehicle Access freigegebenen Fahrzeugdaten und Steuerfunktionen.
- Automatische Erkennung von Signalen, für die Smartcar tatsächlich gültige Daten liefert.
- Auswahl der gewünschten Signal- und Steuervariablen über eine Checkbox-Liste.
- Manuelle oder externe Abfrage der Signale über die Smartcar API (Pull).
- Unterstützung von **Smartcar Signals (Webhooks)** zur automatischen Aktualisierung direkt vom OEM-Backend (Push).
- Steuerung unterstützter Funktionen wie Zentralverriegelung, Ladevorgang oder Ladelimit.
- Automatische Erstellung und Verwaltung der ausgewählten Statusvariablen.
- Fehler- und Debug-Ausgaben im Symcon-Debug-Fenster.

---

## 2. Voraussetzungen

- IP-Symcon ab Version **8.2**
- Smartcar-Konto
- Mit Smartcar kompatibles Fahrzeug, welches mit dem OEM-Portal verbunden ist

---

## 3. Installation

- Das Modul kann direkt über den **Symcon Module Store** installiert werden.
- Während der Installation des Smartcar-Moduls ist zuerst der **Smartcar Configurator** zu erstellen.
- Splitter und Fahrzeug-Instanz werden anschließend automatisch erstellt.

---

## 4. Einrichten der Instanz

<img width="1781" height="888" alt="image" src="https://github.com/user-attachments/assets/141ec08f-b3e9-4c36-b7ed-7bbdaa2bb5fa" />

Die Berechtigungen für Fahrzeugdaten und Steuerfunktionen werden unter **Smartcar → Configuration → Vehicle Access** festgelegt.

Die Fahrzeuginstanz verwendet keine separate Smartcar-Kompatibilitätsprüfung mehr. Stattdessen werden die tatsächlich vom Fahrzeug gelieferten Signale ausgewertet.

| Feld | Beschreibung |
|------|--------------|
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
2. Über ein Script zum Abrufen einzelner oder aller Signale (Pull)
3. Automatisch über den Webhook, sobald Smartcar neue Daten liefert (Push)

Bei der Verarbeitung werden nur Signale berücksichtigt, die erfolgreich geliefert wurden und mindestens einen tatsächlichen Nutzwert enthalten.

`null`, leere Arrays oder leere Werte führen nicht zur Erstellung einer Signalvariable. Werte wie `0` oder `false` sind dagegen gültige Signalwerte.

### Signalgruppen und Befehle (Beispiele)

| Kategorie | Signaltyp / Befehlstyp | Beispiel-Signale / Befehle | Beschreibung |
|------------|------------------------|-----------------------------|--------------|
| **Batterie & Laden** | `tractionbattery-*`, `charge-*` | `tractionbattery-stateofcharge`, `charge-ischarging`, `charge-chargelimits` | SOC, Ladezustand, Ladelimit |
| **Ladebefehle** | `charge-*` Commands | `charge-start`, `charge-stop`, `charge-set-limit` | Ladevorgang starten/stoppen, Ladelimit setzen |
| **Sicherheit & Türen** | `closure-*`, `security-*` | `closure-islocked`, `closure-doors`, `closure-windows` | Verriegelungsstatus, offene Türen/Fenster |
| **Türbefehle** | `security-*` Commands | `security-lock`, `security-unlock` | Fahrzeug verriegeln/entriegeln |
| **Fahrzeugbewegung** | `location-*`, `odometer-*` | `location-preciselocation`, `odometer-traveleddistance` | GPS, Kilometerstand |
| **Fahrzeuginfo** | `vehicleidentification-*`, `internalcombustionengine-*` | `vehicleidentification-*`, `internalcombustionengine-fuellevel` | Stammdaten und Motorstatus |
| **Reifendruck** | `tires-*` | `tires-pressure` | Druckwerte der Reifen |
| **Konnektivität** | `connectivitystatus-*`, `connectivitysoftware-*` | `connectivitystatus-isonline`, `connectivitysoftware-currentfirmwareversion` | Online-Status, Firmware |
| **Klima** | `climate-*`, `climatecontrol-*` | `climate-externaltemperature`, `climatecontrol-isheateractive` | Innen-/Außentemperatur, Heizung |
| **Sonstige** | `vehicle-*`, `telematics-*`, `energy-*`, `evse-*` | `vehicle-speed`, `telematics-*`, `energy-*`, `evse-*` | Weitere von Smartcar bereitgestellte Fahrzeugdaten |

> Welche Signale tatsächlich zur Verfügung stehen, hängt vom Fahrzeug, Hersteller, Smartcar-Plan und den unter Vehicle Access erteilten Berechtigungen ab.
>
> Das Modul verwendet keine separate Compatibility-Liste mehr. Entscheidend ist, welche Signale Smartcar erfolgreich mit gültigen Daten liefert.

---

## 6. Statusvariablen und Profile

Profile werden automatisch angelegt.

Signalvariablen werden erstellt, sobald Smartcar dafür gültige Daten liefert und die entsprechende Variable im Konfigurationsformular aktiviert ist.

Steuervariablen werden entsprechend den von Smartcar für die Fahrzeugverbindung zurückgegebenen `control_*`-Berechtigungen angeboten und können ebenfalls einzeln aktiviert oder deaktiviert werden.

| Profil | Typ | Beschreibung |
|---------|-----|--------------|
| `SMCAR.Odometer` | Float | Kilometerstand und Reichweite |
| `SMCAR.Pressure` | Float | Reifendruck in bar |
| `SMCAR.Progress` | Float | Prozentwerte (z. B. Batterieladestand, Ladelimit, Tankfüllstand, Öl-Lebensdauer) |
| `SMCAR.Status` | String | Statusanzeige |
| `SMCAR.Charge` | String | Ladezustand |
| `SMCAR.Power` | Float | Ladeleistung in kW |
| `SMCAR.Energy` | Float | Energie in kWh |
| `SMCAR.TimeMinutes` | Integer | Zeitangaben in Minuten |
| `SMCAR.LatLon` | Float | GPS-Koordinaten (Latitude / Longitude) |

---

## 7. WebFront

Unterstützte Fahrzeugfunktionen können direkt aus dem WebFront gesteuert werden, beispielsweise:

- Fahrzeug verriegeln / entriegeln
- Ladelimit setzen
- Ladevorgang starten / stoppen

Welche Steuerfunktionen verfügbar sind, richtet sich nach den von Smartcar für die aktuelle Fahrzeugverbindung zurückgegebenen `control_*`-Berechtigungen.

Die verfügbaren Steuervariablen werden im Konfigurationsformular angezeigt und können dort einzeln aktiviert oder deaktiviert werden.

---

## 8. PHP-Befehlsreferenz

| Befehl | Beschreibung |
|--------|--------------|
| `SMCARV_FetchSelectedSignals(12345, []);` | Alle aktivierten Signale abrufen |
| `SMCARV_FetchSelectedSignals(12345, ['tractionbattery-stateofcharge']);` | Nur das angegebene Signal abrufen |

Die verfügbaren Signale können über **„Signale aus Vehicle Access abrufen“** ermittelt werden.

---

## 9. Verhalten bei API und Webhook

API-Abfragen und Webhooks verwenden dieselbe Signalverarbeitung.

Ein Signal wird nur als erfolgreich verarbeitet, wenn:

- Smartcar einen erfolgreichen Status zurückliefert und
- mindestens ein tatsächlicher Nutzwert enthalten ist.

Nicht erfolgreiche oder leere Signale führen nicht zur Erstellung neuer Variablen.

Deaktivierte Variablen werden durch nachfolgende API-Abfragen oder Webhooks nicht automatisch wieder aktiviert.

Die Namen bereits vorhandener Variablen werden bei späteren Aktualisierungen nicht verändert.

---

## 10. Bekannte Einschränkungen

- Simulierte Fahrzeuge werden aktuell nicht unterstützt.
- Nicht jedes Fahrzeug stellt alle von Smartcar definierten Signale oder Steuerfunktionen bereit.
- Die Verfügbarkeit einzelner Daten kann zusätzlich vom Hersteller, Fahrzeugmodell, Smartcar-Plan und den Vehicle-Access-Berechtigungen abhängen.

---

## 11. Lizenz

Dieses Modul steht unter der **MIT-Lizenz**.

© 2025 Stefan Künzli  
https://opensource.org/licenses/MIT
