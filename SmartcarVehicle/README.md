# 🚗 Smartcar Modul für IP-Symcon


## 📑 Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)  
2. [Voraussetzungen](#2-voraussetzungen)  
3. [Installation](#3-installation)  
4. [Einrichten der Instanz](#4-einrichten-der-instanz)  
5. [Scopes (Berechtigungen)](#5-scopes-berechtigungen)  
6. [Smartcar Signals (Webhooks)](#6-smartcar-signals-webhooks)  
7. [Statusvariablen und Profile](#7-statusvariablen-und-profile)  
8. [WebFront](#8-webfront)  
9. [PHP-Befehlsreferenz](#9-php-befehlsreferenz)  
10. [Bekannte Einschränkungen](#10-bekannte-einschränkungen)  
11. [Versionen](#11-versionen)  
12. [Lizenz](#12-lizenz)

---

## 1. Funktionsumfang

- Verbindung eines Fahrzeugs über Smartcar (Test-Fahrzeuge sind nicht unterstützt).  
- Abruf der wichtigsten Fahrzeugdaten über API-Endpunkte.  
- Steuerung von Funktionen wie Zentralverriegelung oder Ladelimit.  
- Unterstützung von **Smartcar Signals (Webhooks)** zur automatischen Aktualisierung direkt vom OEM-Backend gepusht.  
- Automatische Erstellung und Verwaltung der Statusvariablen.  
- Fehler- und Debug-Ausgaben im Symcon-Debug-Fenster.  
- Unterstützung mehrerer Fahrzeuge über mehrere Modulinstanzen.  
- **Rate-Limit-Handling** mit automatischer Wiederholung nach Wartezeit.

---

## 2. Voraussetzungen

- IP-Symcon ab Version **8.2**  
- Smartcar-Konto  
- Mit Smartcar kompatibles Fahrzeug welches mit dem OEM-Portal verbunden ist

---

## 3. Installation

Das Modul kann direkt über den **Symcon Module Store** installiert werden.

---

## 4. Einrichten der Instanz

![alt text](image.png)

Unter *Instanz hinzufügen* das Modul **Smartcar** auswählen.

| Feld | Beschreibung |
|------|---------------|
| **Kompatible Signale / Befehle** | Diese Liste wird automatisch beim Installieren der Fahrezuginstanz asu dem Konfigurator geladen. Sie kann auch über den Button 'Kompatibilitätsliste neu laden' neu abgefragt werden. Die Liste wird 24h durch das Modul gecacht. |
| **Kompatibilität neu laden** | Damit kann die Kompatibilitätsliste neu geladen werden. Die Liste wird 24h durch das Modul gecacht. |
| **Gewählte Signale bei Smartcar registrieren** | Damit können die aus der Kompatibilitätsliste ausgewählten Signale bei Smartcar registriert werden, ansonsten die Signale nicht einwandfrei bzw. gar nicht empfangen werden können. |
| **Aktivierte Signale abrufen** | Damit lassen sich alle aktivierten Signale auf einmal abrufen. |

---

## 6. Smartcar Signals

Es gibt zwei möglichkeiten, um die Signale zu aktualisieren.
1. Durch akualieren über den entsprechenden Button 'AKtivierte Signale abrufen' im Konfigurationsformular (Pull).  
2. Durch empfangen der Signale über den Webhook, sobald diese beim OEM aktualisiert wurden (Push).


### Signalgruppen (Beispiele)

| Kategorie | Signaltyp | Beispiel-Signale | Beschreibung |
|------------|------------|------------------|---------------|
| **Batterie & Laden** | `tractionbattery-*`, `charge-*` | `tractionbattery-stateofcharge`, `charge-ischarging`, `charge-chargelimits` | SOC, Ladezustand, Ladelimit |
| **Sicherheit & Türen** | `closure-*` | `closure-islocked`, `closure-doors`, `closure-windows` | Verriegelungsstatus, offene Türen/Fenster |
| **Fahrzeugbewegung** | `location-*`, `odometer-*` | `location-preciselocation`, `odometer-traveleddistance` | GPS, Kilometerstand |
| **Fahrzeuginfo** | `vehicleidentification-*`, `internalcombustionengine-*` | `vehicleidentification-*`, `internalcombustionengine-fuellevel` | Stammdaten & Motorstatus |
| **Reifendruck** | `tires-*` | `tires-pressure` | Druckwerte aller Reifen |
| **Konnektivität** | `connectivitystatus-*`, `connectivitysoftware-*` | `connectivitystatus-isonline`, `connectivitysoftware-currentfirmwareversion` | Online-Status, Firmware |
| **Klima** | `climate-*`, `climatecontrol-*` | `climate-externaltemperature`, `climatecontrol-isheateractive` | Innen-/Außentemperatur, Heizung |
| **Sonstige** | `vehicle-*`, `telematics-*`, `energy-*`, `evse-*` | `vehicle-speed`, `telematics-*`, `energy-*`, `evse-*` | Nur bei Premium-/Fleet-Plänen verfügbar |

> Es gibt weit über 100 mögliche Signaltypen.  
> Es sind bei weitem nicht alle Signale mit allen Fahrzeugen kompatibel. Das Modul filtert automatisch.


---

## 7. Statusvariablen und Profile

Variablen werden automatisch angelegt, wenn sie benötigt werden.  
Das Löschen einzelner Variablen kann zu Fehlfunktionen führen.

| Profil | Typ | Beschreibung |
|---------|-----|--------------|
| `SMCAR.Odometer` | Float | Kilometerstand und Reichweite |
| `SMCAR.Pressure` | Float | Reifendruck in bar |
| `SMCAR.Progress` | Float | Prozentwerte (z. B. Batterieladestand, Ladelimit, Tankfüllstand, Öl-Lebensdauer) |
| `SMCAR.Status` | String | Statusanzeige (z. B. OPEN / CLOSED) |
| `SMCAR.Charge` | String | Ladezustand (Text, z. B. CHARGING, COMPLETED) |
| `SMCAR.Power` | Float | Ladeleistung in kW |
| `SMCAR.Energy` | Float | Energie in kWh (z. B. geladen oder Kapazität) |
| `SMCAR.TimeMinutes` | Integer | Zeitangaben in Minuten |
| `SMCAR.LatLon` | Float | GPS-Koordinaten (Latitude / Longitude) |

---

## 8. WebFront

Steuere Fahrzeugfunktionen direkt aus dem WebFront:  
- Türen verriegeln/entriegeln  
- Ladelimit setzen  
- Ladevorgang starten/stoppen  

---

## 9. PHP-Befehlsreferenz

| Befehl | Beschreibung |
|--------|---------------|
| `SMCARV_FetchSelectedSignals(12345, []);` | Alle aktivierten Signale abrufen |
| `SMCARV_FetchSelectedSignals(12345, ['tractionbattery-stateofcharge']);` | Signal für SOC abrufen |

> Die Namen der einzelnen Signale entnimmst du der Liste der kompatiblen Signale unter der Spalte 'Capability'.

---

## 10. Bekannte Einschränkungen

- Simmulierte Fahrzeuge werden aktuell nicht unterstützt.  

---

## 11. Lizenz

Dieses Modul steht unter der **MIT-Lizenz**.  
© 2025 Stefan Künzli  
[https://opensource.org/licenses/MIT](https://opensource.org/licenses/MIT)