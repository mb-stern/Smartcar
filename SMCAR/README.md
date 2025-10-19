
Diesen Pfad trägst du in der Smartcar-Konfiguration unter  
**Configuration → Redirect URIs** ein, und ebenso unter  
**Integrations → Webhook** für die Signale.

> ⚠️ Wenn du im Konfigurationsformular Scopes änderst, musst du die Berechtigungen erneut über den Button **„Mit Smartcar verbinden“** autorisieren.

---

## 🚗 Unterstützte Scopes (Endpunkte)

| Beschreibung | API-Pfad |
|--------------|-----------|
| Fahrzeuginformationen lesen | `/` |
| VIN lesen | `/vin` |
| Standort lesen | `/location` |
| Reifendruck lesen | `/tires/pressure` |
| Kilometerstand lesen | `/odometer` |
| Batterielevel lesen | `/battery` |
| Batteriekapazität lesen | `/battery/nominal_capacity` |
| Motoröl lesen | `/engine/oil` |
| Kraftstoffstand lesen | `/fuel` |
| Sicherheitsstatus lesen | `/security` |
| Ladelimit lesen | `/charge/limit` |
| Ladestatus lesen | `/charge` |

### Unterstützte Steuerungen
| Beschreibung | API-Pfad |
|--------------|-----------|
| Ladelimit setzen | `/charge/limit` |
| Ladevorgang starten/stoppen | `/charge` |
| Zentralverriegelung setzen | `/security` |

---

## Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)  
2. [Voraussetzungen](#2-voraussetzungen)  
3. [Software-Installation](#3-software-installation)  
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)  
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)  
6. [WebFront](#6-webfront)  
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)  
8. [Bekannte Einschränkungen](#8-bekannte-einschränkungen)  
9. [Versionen](#9-versionen)  

---

## 1. Funktionsumfang

- Abfrage der ausgewählten Fahrzeugdaten und Ausführung von Steuerbefehlen.  
- Die kostenlose Version von Smartcar unterstützt 1 Fahrzeug mit 500 API-Calls pro Monat.  
- Bezahlpläne bieten bis zu 1000 API-Calls/Monat (ab 1,99 $).  
- Das Modul unterstützt auch **Testfahrzeuge** von Smartcar.  
  → Diese eignen sich besonders zum Testen, um API-Verbrauch beim Live-Fahrzeug zu sparen.  
- Frag nur Endpunkte ab, die du wirklich brauchst, um dein monatliches Kontingent zu schonen.  
- Pro Instanz wird ein Fahrzeug verwaltet.  
- Mehrere Fahrzeuge kannst du über mehrere Modul-Instanzen anbinden.  
- Unterstützt **Smartcar Signals** (Webhook-Integration, kostenpflichtiger Plan erforderlich).  
  Diese aktualisieren automatisch die Variablen beim Eintreffen der Daten.  

---

## 2. Voraussetzungen

- IP-Symcon ab Version **7.0**
- Ein gültiges **Smartcar-Profil** mit Test- oder Live-Fahrzeug.

---

## 3. Software-Installation

Über den **Module Store** in IP-Symcon kann das Modul installiert werden.

---

## 4. Einrichten der Instanzen in IP-Symcon

Unter *Instanz hinzufügen* das Modul **Smartcar** wählen.

### Konfigurationsseite

| Name | Beschreibung |
|------|---------------|
| **Redirect-/Webhook-URI** | Diese URI muss in Smartcar unter *Configuration → Redirect URIs* und *Integrations → Webhook* eingetragen werden. |
| **Manuelle Redirect-URI** | Falls vorhanden, wird diese statt der automatisch ermittelten Connect-Adresse verwendet. |
| **Webhook-Empfang aktivieren** | Aktiviert die Verarbeitung eingehender Smartcar-Signale. |
| **Fahrzeug verifizieren** | Prüft, ob eingehende Signale zum aktuell verbundenen Fahrzeug gehören. |
| **Variable für Aktualisierung** | Erstellt eine Variable, die den letzten Zeitpunkt eines empfangenen Signals anzeigt. |
| **Application Management Token** | Aus Smartcar → *Configuration*. |
| **Client ID** | Aus Smartcar → *Configuration*. |
| **Client Secret** | Aus Smartcar → *Configuration*. |
| **Verbindungsmodus** | *Simuliert* oder *Live*. Bei Wechsel neu verbinden. |
| **Berechtigungen (Scopes)** | Auswahl der gewünschten API-Endpunkte. Nur aktivierte Scopes werden abgefragt und als Variablen angelegt. |
| **Auf kompatible Scopes prüfen** | Prüft, welche Scopes dein Fahrzeug tatsächlich unterstützt. In der Regel nur einmal nötig. |
| **Mit Smartcar verbinden** | Öffnet ein Browserfenster zur Authentifizierung und Autorisierung. |
| **Fahrzeugdaten abrufen** | Liest alle aktivierten Scopes. Vorsicht: Jeder Scope = 1 API-Call. |

---

## 5. Statusvariablen und Profile

Die Variablen werden automatisch erstellt und beim Deaktivieren des Scopes wieder entfernt.  
Das manuelle Löschen kann zu Fehlfunktionen führen.

### Profile

| Name | Typ | Beschreibung |
|------|-----|---------------|
| `SMCAR.Odometer` | Float | Kilometerstand |
| `SMCAR.Pressure` | Float | Reifendruck |
| `SMCAR.Progress` | Float | Prozentwerte (z. B. Ladezustand) |
| `SMCAR.Status` | String | Statusanzeige |
| `SMCAR.Charge` | String | Ladezustand (Text) |
| `SMCAR.Health` | String | Batteriezustand |
| `SMCAR.ChargeLimitSet` | Float | Soll-Ladelimit |

---

## 6. WebFront

Die Variablen zur Steuerung der Fahrzeugfunktionen können direkt aus dem WebFront bedient werden (z. B. Zentralverriegelung oder Ladung starten/stoppen).

---

## 7. PHP-Befehlsreferenz

Über Skripte oder Ablaufpläne können gezielt einzelne Endpunkte abgefragt werden, um API-Aufrufe zu sparen.

| Befehl | Beschreibung |
|--------|---------------|
| `SMCAR_FetchBatteryCapacity(12345);` | Batteriekapazität abrufen |
| `SMCAR_FetchBatteryLevel(12345);` | Batterieladestand (SOC) & Reichweite abrufen |
| `SMCAR_FetchChargeLimit(12345);` | Ladelimit abrufen |
| `SMCAR_FetchChargeStatus(12345);` | Ladestatus abrufen |
| `SMCAR_FetchEngineOil(12345);` | Öllebensdauer abrufen |
| `SMCAR_FetchFuel(12345);` | Tankvolumen & Reichweite abrufen |
| `SMCAR_FetchLocation(12345);` | GPS-Koordinaten abrufen |
| `SMCAR_FetchOdometer(12345);` | Kilometerstand abrufen |
| `SMCAR_FetchSecurity(12345);` | Tür-, Klappen- & Fensterstatus abrufen |
| `SMCAR_FetchTires(12345);` | Reifendruck abrufen |
| `SMCAR_FetchVIN(12345);` | Fahrgestellnummer abrufen |
| `SMCAR_FetchVehicleData(12345);` | ⚠️ Alle aktivierten Scopes abrufen (hoher API-Verbrauch) |

> Tipp: In Ablaufplänen sollte zwischen API-Aufrufen ein Abstand von ca. **2 Minuten** liegen, da Smartcar zu häufige Abfragen blockiert.

---

## 8. Bekannte Einschränkungen

- Smartcar erlaubt pro Authorization-Flow nur **ein Fahrzeug**.  
- Webhooks (Smartcar Signals) sind nur mit **echten Fahrzeugen** nutzbar, nicht mit Simulationen.  
- Bei häufigen API-Abfragen kann Smartcar mit `429 (Rate Limit)` antworten. Das Modul wiederholt dann den Aufruf automatisch nach der vorgegebenen Wartezeit.  
- Wenn Variablen fehlen, prüfe bitte:
  - Scopes korrekt aktiviert?
  - Token gültig?
  - Verbindung erfolgreich hergestellt?

---

## 9. Versionen

| Version | Datum | Änderungen |
|----------|--------|------------|
| **3.3** | 19.10.2025 | - Wiederholte Abfrage bei Erreichen des Rate-Limits<br>- Verbesserte Debug- und Fehlerausgabe<br>- Code-Optimierungen |
| **3.2** | 14.10.2025 | - Verbesserte automatische Scope-Erkennung |
| **3.1** | 07.10.2025 | - Automatische Prüfung auf kompatible Scopes im Formular<br>- Fehler bei Batteriekapazität behoben<br>- Ladeleistung korrekt dargestellt<br>- Variable für letzte Signalzeit ergänzt |
| **3.0** | 05.10.2025 | - Unterstützung von Webhooks (Smartcar Signals) |
| **2.3** | 28.09.2025 | - Token-Erneuerung bei Änderungen/Neustart verbessert |
| **2.2** | 26.07.2025 | - Verbesserte Fehlerausgabe im Debug und Statusdialog |
| **2.1** | 15.06.2025 | - Codeanpassungen & Rechtschreibkorrekturen |
| **2.0** | 02.01.2025 | - Kompatibilität für Module Store hergestellt |
| **1.3** | 26.12.2024 | - Token-Handling verbessert<br>- Fehlerbehandlung bei 401-Authentifizierung ergänzt |
| **1.2** | 22.12.2024 | - Variablen- & Profilanpassungen<br>- Modulname & Formular überarbeitet |
| **1.1** | 17.12.2024 | - Fehler bei Fensterstatus behoben |
| **1.0** | 15.12.2024 | - Initiale Version |

---

## 🧾 Lizenz

Dieses Modul steht unter der **MIT-Lizenz**.  
Copyright © 2025  
**Stefan Künzli**

