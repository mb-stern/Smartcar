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
- Mit Smartcar kompatibles Fahrzeug

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

## 5. Scopes (Berechtigungen)

Die folgenden Scopes können über die API abgefragt werden.  
Sie definieren, welche Daten das Modul aktiv abrufen darf.

| Scope | API-Endpunkte | Beschreibung |
|--------|----------------|---------------|
| `read_vehicle_info` | `/` | Allgemeine Fahrzeuginformationen |
| `read_vin` | `/vin` | Fahrgestellnummer |
| `read_location` | `/location` | GPS-Koordinaten |
| `read_tires` | `/tires/pressure` | Reifendruck |
| `read_odometer` | `/odometer` | Kilometerstand |
| `read_battery` | `/battery`, `/battery/nominal_capacity` | Batteriedaten |
| `read_fuel` | `/fuel` | Tankfüllstand und Reichweite |
| `read_security` | `/security` | Verriegelungsstatus |
| `read_charge` | `/charge`, `/charge/limit` | Ladestatus & Ladelimit |
| `read_engine_oil` | `/engine/oil` | Ölzustand |

> Tipp: Aktiviere nur Scopes, die du wirklich brauchst.  
> Jeder API-Aufruf verbraucht dein monatliches Kontingent.

---

## 6. Smartcar Signals (Webhooks)

Smartcar Signals liefern **Echtzeitdaten** deines Fahrzeugs an das Modul.  
Sobald ein Signal eintrifft, legt das Modul automatisch passende Variablen an und aktualisiert sie.

> Smartcar Signals stehen nur bei Fahrzeugen und Tarifen zur Verfügung, die sie unterstützen.  
> Simulatoren senden keine Signals.

### Einrichtung

1. Im Modul den **Webhook aktivieren**.  
2. Die automatisch angezeigte URI in Smartcar als **Integration Webhook** eintragen.  
3. **Application Management Token** im Modul hinterlegen.  
4. (Optional) **Fahrzeug verifizieren** aktivieren, um nur gültige Vehicle-IDs zuzulassen.  
5. (Optional) **Letzte Aktualisierung** aktivieren.

### Sicherheit

- **VERIFY-Event:** Smartcar sendet bei der Einrichtung ein `eventType:"VERIFY"`.  
  Das Modul antwortet automatisch mit einem HMAC-SHA256 über das Management Token.  
- **Signaturprüfung:** Alle eingehenden Signale werden anhand des Headers `SC-Signature` validiert.  
- **Fahrzeugfilter:** Bei aktivierter Prüfung werden fremde Vehicle-IDs ignoriert.

### Signalgruppen (Beispiele)

| Kategorie | Beispiel-Signale | Beschreibung |
|------------|------------------|---------------|
| **Batterie & Laden** | `tractionbattery-stateofcharge`, `charge-ischarging`, `charge-chargelimits` | SOC, Ladezustand, Ladelimit |
| **Sicherheit & Türen** | `closure-islocked`, `closure-doors`, `closure-windows` | Verriegelungsstatus, offene Türen/Fenster |
| **Fahrzeugbewegung** | `location-preciselocation`, `odometer-traveleddistance` | GPS, Kilometerstand |
| **Fahrzeuginfo** | `vehicleidentification-*`, `engine-*` | Stammdaten & Motorstatus |
| **Reifendruck** | `tires-pressure` | Druckwerte aller Reifen |
| **Sonstige** | `vehicle-speed`, `telematics-*`, `energy-*`, `evse-*` | Nur bei Premium-/Fleet-Plänen verfügbar |

> Es gibt weit über 100 mögliche Signaltypen.  
> Das Modul legt Variablen **automatisch** an, sobald ein neues Signal empfangen wird.


### Hinweise

- Fehlende Variablen = falscher Webhook, fehlendes Token oder ungültige Signatur.  
- VERIFY schlägt fehl → Management Token prüfen.  
- Simulatoren senden keine Webhooks.  
- Doppelte Signale werden idempotent verarbeitet (keine Duplikate).

---

## 7. Statusvariablen und Profile

Variablen werden automatisch angelegt, wenn sie benötigt werden.  
Das Löschen einzelner Variablen kann zu Fehlfunktionen führen.

| Profil | Typ | Beschreibung |
|---------|-----|--------------|
| `SMCAR.Odometer` | Float | Kilometerstand |
| `SMCAR.Pressure` | Float | Reifendruck |
| `SMCAR.Progress` | Float | Prozentwerte |
| `SMCAR.Status` | String | Statusanzeige |
| `SMCAR.Charge` | String | Ladezustand (Text) |
| `SMCAR.Health` | String | Batteriezustand |
| `SMCAR.ChargeLimitSet` | Float | Ladelimit |

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

## 12. Lizenz

Dieses Modul steht unter der **MIT-Lizenz**.  
© 2025 Stefan Künzli  
[https://opensource.org/licenses/MIT](https://opensource.org/licenses/MIT)