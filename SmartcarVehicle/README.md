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

Unter *Instanz hinzufügen* das Modul **Smartcar** auswählen.

| Feld | Beschreibung |
|------|---------------|
| **Redirect-/Webhook-URI** | Automatisch generiert; muss in Smartcar als Redirect & Webhook eingetragen werden. |
| **Manuelle Redirect-URI** | Optional – überschreibt die Connect-Adresse. |
| **Webhook-Empfang aktivieren** | Aktiviert die Verarbeitung eingehender Signale. |
| **Fahrzeug verifizieren** | Filtert nur Signale des verbundenen Fahrzeugs. |
| **Letzte Aktualisierung** | Erstellt Zeitstempelvariable für letzte Signal-Aktualisierung. |
| **Application Management Token** | Aus Smartcar (*Configuration*). Wird für VERIFY und Signaturprüfung benötigt. |
| **Client ID / Secret** | Aus Smartcar (*Configuration*). |
| **Verbindungsmodus** | *Simuliert* oder *Live*. Bei Wechsel neu verbinden. |
| **Berechtigungen (Scopes)** | Auswahl der gewünschten Berechtigungen für die Scopes. Am besten immer alle Berechtigungen holen. Danach können einzelne Scopes von der Abfrage deaktiviert werden, welche Fehler im Log/Debug generieren. So kann festgestellt werden, was überhaupt von Samrtcar/OEM unterstützt ist.|
| **Mit Smartcar verbinden** | Startet den Prozess für die Berechtigung der Scopes. |
| **Fahrzeugdaten abrufen** | Ruft aktiv alle gewählten Scopes ab. (Achtung: API-Limits beachten) |

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
| `SMCAR_FetchBatteryCapacity(12345);` | Batteriekapazität abrufen |
| `SMCAR_FetchBatteryLevel(12345);` | SOC & Reichweite abrufen |
| `SMCAR_FetchChargeLimit(12345);` | Ladelimit abrufen |
| `SMCAR_FetchChargeStatus(12345);` | Ladestatus abrufen |
| `SMCAR_FetchEngineOil(12345);` | Ölzustand abrufen |
| `SMCAR_FetchFuel(12345);` | Tankfüllstand & Reichweite abrufen |
| `SMCAR_FetchLocation(12345);` | GPS-Koordinaten abrufen |
| `SMCAR_FetchOdometer(12345);` | Kilometerstand abrufen |
| `SMCAR_FetchSecurity(12345);` | Sicherheitsstatus abrufen |
| `SMCAR_FetchTires(12345);` | Reifendruck abrufen |
| `SMCAR_FetchVIN(12345);` | Fahrgestellnummer abrufen |
| `SMCAR_FetchVehicleData(12345);` | Alle aktivierten Scopes abrufen (Achtung: API-Verbrauch!) |

> Empfehlung: Bei mehreren Abfragen mindestens 2 Minuten Abstand lassen, um Rate Limits zu vermeiden.

---

## 10. Bekannte Einschränkungen

- Webhooks funktionieren nur bei echten Fahrzeugen (nicht Simulatoren).  
- API-Aufrufe sind kontingentiert → Rate-Limits beachten.  
- Signals variieren je nach Fahrzeughersteller.  
- Es kann Signals geben, die keinem Scope entsprechen (werden trotzdem verarbeitet).  

---

## 11. Versionen

| Version | Datum | Änderungen |
|----------|--------|------------|
| **3.8** | 25.01.2026 | - Diverse Fehler beim Senden von Befehlen wie endlose Wiederholversuche und falsche Darstellung des Ladelimits behoben.|
| **3.7** | 10.01.2026 | - Code-Korrektur aufgrund Store-Review.|
| **3.6** | 04.01.2026 | - Anpassen der Ausgabe für das Ladeende. Die Zeit kommt entweder als Dezimalzeit oder als Minuten.<br>- Zusätzliche Debugausgabe für das Alter der OEM-Daten, teilweise sind diese nicht aktuell.|
| **3.5** | 02.01.2026 | - Umbau auf IPSModuleStrict und Kompatibilität auf 8.2 hochgesetzt.<br>- Variable für das Ladeende angepasst. Die alte Variable 'Restladezeit' muss gelöscht werden. <br>- Tokenerneuerung verbessert.|
| **3.4** | 21.12.2025 | - Fehlermeldung beim Verbinden des Fahrzeuges behoben.<br>- Automatische Scopeerkennung deaktiviert wegen Problem mit Signalen. Es wurden bei ausgeblendeten Scopes entsprechende Signale blockiert.<br>- Diverse Code Modifikationen |
| **3.3** | 19.10.2025 | - Beim Erreichen des Rate-Limits wird nach der vorgegebenen Wartezeit der Scope erneut abgefragt.<br>- Verbesserung der Debug- und Error-Ausgabe.<br>- Code überarbeitet.<br>- README neu strukturiert mit getrennten Abschnitten für Scopes & Signals |
| **3.2** | 14.10.2025 | - Automatische Scopeerkennung verbessert. |
| **3.1** | 07.10.2025 | - Neu ist eine automatische Prüfung auf kompatible Scopes im Konfigurationsformular verfügbar.<br>- So werden nur noch kompatible Scopes abgefragt und Fehlermeldungen und überflüssige Abfragen vermieden.<br>- Fehler bei der Abfrage der Batteriekapazität behoben.<br>- Ladeleistung wird jetzt korrekt dargestellt.<br>- Eine Variable mit dem Zeitpunkt der letzten Signale kann im Konfigurationsformular aktiviert werden. |
| **3.0** | 05.10.2025 | - Neu werden zusätzlich Signale über Webhooks unterstützt.<br>- Diese müssen über einen Plan von Smartcar erworben werden.<br>- Die entsprechenden Variablen werden automatisch erstellt. |
| **2.3** | 28.09.2025 | - Der Token wird nun bei jeder Konfigurationsänderung oder auch beim Update erneuert, sobald Symcon bereit ist.<br>- Dies sollte die zeitweiligen Token-Fehler nach Neustart des Systems beheben. |
| **2.2** | 26.07.2025 | - Verbesserung der Fehlerausgabe im Debug und Statusdialog von Symcon. |
| **2.1** | 15.06.2025 | - Rechtschreibekorrektur.<br>- Codeanpassungen für Ladestatus. |
| **2.0** | 02.01.2025 | - Code und Readme angepasst.<br>- Version um die Store-Kompatibilität zu erlangen. |
| **1.3** | 26.12.2024 | - Timer für Token-Erneuerung auf 90 min fixiert.<br>- Token wird nun zusätzlich bei jeder Konfigurationsänderung erneuert.<br>- Abhandlung bei 401-Fehler (Authentication) während der Datenabfrage hinzugefügt, so dass der Access-Token erneuert und die Abfrage erneut ausgeführt wird.<br>- Fehlerausgabe in Log aktiviert. |
| **1.2** | 22.12.2024 | - Anpassungen einiger Variablennamen.<br>- Anpassung des Readme.<br>- Anpassung Modulname.<br>- Anpassung Konfigurationsformular.<br>- Einige Code-Modifikationen.<br>- Variablenprofil für Zentralverriegelung geändert. |
| **1.1** | 17.12.2024 | - Fehlermeldung *BackLeftWindow* und *BackRightWindow* behoben.<br>- Variablen hinzugefügt. |
| **1.0** | 15.12.2024 | - Initiale Version. |


---

## 12. Lizenz

Dieses Modul steht unter der **MIT-Lizenz**.  
© 2025 Stefan Künzli  
[https://opensource.org/licenses/MIT](https://opensource.org/licenses/MIT)