# Modul für Smartcar für IP-Symcon
Dieses Modul ermöglicht, Daten von Fahrzeugen über die Smartcar-Plattform abzufragen. 
Erstelle ein Profil und verbinde dein Fahrzeug oder ein Testfahrzeug (https://smartcar.com/de)
Smartcar unterstützt aktuell 43 Fahrzeugmarken. Prüfe hier welche Endpunkte dein Fahrezug unterstützt. (https://smartcar.com/de/product/compatible-vehicles)


### Wichtig zu wissen zur Konfiguration von Smartcar
Das Modul verbindet sich über OAuth 2.0 mit der Smartcar API. 
Daher ist es erforderlich, eine Redirect URI in der Smartcar-Konfiguration einzutragen. 
Die Redirect URI ist der Pfad zum Webhook, welchen das Modul automatisch anlegt. 
Dieser Pfad setzt sich aus deiner Connenct-Adresse und dem Pfad des Webhook zusammen. 
Der Pfad der Redirect-URI wird oben im Konfigurationsformular angezeigt. 
Diesen hinterlegst du dann in der Konfiguration von Smartcar unter 'REDIRECT URIS' Dies sieht zB so aus: https://hruw8ehwWERUOwehrWWoiuh.ipmagic.de/hook/smartcar_15583
Wenn du im Konfigurationsformular die Scopes gewählt oder geändert hast, sind diese erneut über den Button 'Smartcar verbinden' bei Smartcar zu registrieren.


Aktuell sind folgende Scopes (Endpunkte) durch das Modul unterstützt:
* "Fahrzeuginformationen lesen (/)"
* "VIN lesen (/vin)"
* "Standort lesen (/location)"
* "Reifendruck lesen (/tires/pressure)"
* "Kilometerstand lesen (/odometer)"
* "Batteriestlevel lesen (/battery)"
* "Batterieststatus lesen (/battery/capacity)"
* "Motoröl lesen (/oil)"
* "Kraftstoffstand lesen (/fuel)"
* "Sicherheitsstatus lesen (/security)"
* "Ladelimit lesen (/charge/limit)"
* "Ladestatus lesen (/charge)"

Aktuell sind folgende Ansteuerungen unterstützt
* "Ladelimit setzen (/charge/limit)"
* "Ladestatus setzen (/charge)"
* "Zentralverriegelung setzen (/security)"

![alt text](image.png)


### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [WebFront](#6-webfront)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)
8. [Versionen](#8-versionen)

### 1. Funktionsumfang

* Abfrage der ausgewählterFahrzeugdaten und ausführen verschiedener Ansteuerung am Fahrzeug.
* Die kostenlose Version unterstützt ein Fahrzeug mit 500 API-Calls pro Monat.
* Es gibt eine Bezahlversion für 2.99$ mit 1000 API-Calls pro Monat
* Die Testfahrzeuge der Smartcar-Plattform sind unterstützt. Zum testen sollten diese Verwendet werden, um den API-Verbrauch des Live-Fahrzeuges zu schonen.
* Vorsicht: Frag nur Endpunkte ab, die du wirklich brauchst, sonst ist das Guthaben schnell aufgebraucht. Lies dazu weiter unten die [PHP-Befehlsreferenz](#7-php-befehlsreferenz)
* In der aktuellen Version dieses Moduls ist ein Fahrzeug unterstützt, für mehrere Fahrzeuge/Profile ist das Modul mehrmals anzulegen.
* Im Smartcar-Profil können mehrere Redirct-URI's angelegt werden, womit auch mehrere Module mit Zugriff auf dasselbe Smartcar-Konto unterstützt sind.
* Nicht unterstützt ist ein Benutzerprofil bei einem Fahrzeug-Hersteller, wo mehrere Fahrzeuge verknüpft sind. Dies ist aber nur ein Thema, wenn mehrere Fahrzeuge desselben Herstellers gehalten werden. Hier muss dann jedes Fahrzeug auf ein anderes Profil lauten.

### 2. Voraussetzungen

- IP-Symcon ab Version 7.0
- Smartcar Profil mit einem Test-Fahrzeug oder einem Live-Fahrezug.

### 3. Software-Installation

* Über den Module Store kann das Modul installiert werden.

### 4. Einrichten der Instanzen in IP-Symcon

- Unter 'Instanz hinzufügen' kann das 'Smartcar'-Modul mithilfe des Schnellfilters gefunden werden.  
- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Name     | Beschreibung
-------- | ------------------
Redirect-URI                |  Das ist der Pfad zum Webhook. Dieser Pfad gehört in die Konfiguration von Smartcar unter REDIRECT URIS.
Client ID                   |  Entnimm diesen in der Konfiguration von Smartcar unter OAuth
Client Secret               |  Entnimm diesen in der Konfiguration von Smartcar unter OAuth
Verbindungsmodus            |  Hier definierst du, ob es sich um ein Simmuliertes oder ein Live-Fahrzeug handelt. Die Fahrzeuge verwaltest du im Dashboard von Smartcar. Es kann auch zwischen simmulierem und Live-Fahrezug gewechstelt werden, jedoch muss danach 'Smartcar verbinden' erneut gewählt werden.
Berchtigungen (Scopes)      |  Hier sind die aktuell vom Modul unterstützen Scopes zur Auswahl. Wichtig ist, dass alle angewählt werden, die später abgefragt werden, sonst werden hier keine Werte geliefert. Im Zweifelsfalle vor der Abfrage alle aktivieren. Die Variablen werden automatisch erstellt und beim Deaktivieren wieder gelöscht. Die Berechtigungen bleiben aber.
Smartcar verbinden         |  Es öffnet siche ein Browserfenster, wo du dich mit deinen Zugangsdaten vom Fahrzeughersteller anmeldest und die gewählten Berechtigungen bei Smartcar noch genemigst. Am Anschluss erscheint eine Erfolgsmeldung und die Zugriff-Token werden über die Redirect-URI an das Modul übertragen.
Fahrzeugdaten abrufen       |  Hier rufst du alle aktivierten Scopes ab. Sei vorsichtig bei einem Live-Fahrzeug. Fünf aktivierte Scopes ergeben 5 API-Calls. Lies hier [PHP-Befehlsreferenz](#7-php-befehlsreferenz) wie du exklusiv die gewünschten Variablen aktualisierst.


### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Es werden Variablen/Typen je nach Wahl der Scopes erstellt. Es können pro Scope mehrere Variablen erstellt werden. Beim Deaktivieren des jeweiligen Scope werden die Variablen wieder gelöscht.

#### Profile

Name   | Typ
------ | ------- 
SMCAR.Odometer   |  Float  
SMCAR.Pressure   |  Float   
SMCAR.Progress   |  Float  
SMCAR.Status     |  String   
SMCAR.Charge     |  String

### 6. WebFront

Die Variablen zur Steuerung der Fahrzeugfunktion können aus der Visualisierung heraus gesteuert werden.

### 7. PHP-Befehlsreferenz

Hier findest du die Info, wie geziehlt (zb über einen Ablaufplan) nur bestimmte Endpunkte (Scopes) abgefragt werden, um API-Calls zu sparen. 
Ein Scenario wäre, dass der SOC nur bei aktiviertem Ladevorgang alle 15min über einen Ablaufplan aktualisiert wird.
Beachte, dass nur im Konfigurationsformuler (Berechtigungen) freigegebene Scopes abgefragt werden können. Falls über einen Ablaufplan mehere Scopes nacheinander abgerufen werden ist ein Abstand von ca 2 Minuten empfehlensert, da Smartcar bei häufigerer Abfragefrequnz diese blockiert.

Befehl   | Beschreibung
------ | -------
SMCAR_FetchBatteryCapacity(12345);  |   Abfrage der Batteriekapazität
SMCAR_FetchBatteryLevel(12345);     |   Abfrage des Batterieladestand (SOC) und der Reichweite Batterie
SMCAR_FetchChargeLimit(12345);      |   Abfrage des Ladelimits
SMCAR_FetchChargeStatus(12345);     |   Abfrage des Ladestatus
SMCAR_FetchEngineOil(12345);        |   Abfrage der restliche Oellebensdauer
SMCAR_FetchFuel(12345);             |   Abfrage des Tankvolumens und der Reichweite Tank    
SMCAR_FetchLocation(12345);         |   Abfragen der GPS-Koordinaten
SMCAR_FetchOdometer(12345);         |   Abfragen des Kilomterstandes
SMCAR_FetchCSecurity(12345);        |   Abfrage des Verriegelungsstatus der Türen, Klappen und Fenster
SMCAR_FetchTires(12345);            |   Abfrage des Reifendruckes
SMCAR_FetchVIN(12345);              |   Abfrage der Fahrgestellnummer
SMCAR_FetchVehicleData(12345);      |   Alle im Modul aktiverten Scopes abfragen. Vorsicht, es könnten sehr viele API-Calls verbraucht werden

### 8. Versionen

Version 2.0 (02.01.2025)
- Code und Readme anepasst
- Version um die Store-Kompatibilität zu erlangen

Version 1.3 (26.12.2024)
- Timer für Token-Erneuerung auf 90 min fixiert.
- Token wird nun zusätzlich bei jeder Konfigurationsänderung erneuert.
- Abhandlung bei 401-Fehler (Authentication) wärend der Datenabfrage hinzugefügt, so dass der Access-Token erneuert und die Abfrage erneut ausgeführt wird.
- Fehlerausgabe in Log aktiviert

Version 1.2 (22.12.2024)
- Anpassungen einiger Variablennamen
- Anpassung des Readme
- Anpassung Modulname
- Anpassung Konfigurationsformular
- Einige Code Modifikationen
- Variablenprofil für Zentralverriegelung geändert

Version 1.1 (17.12.2024)
- Fehlermeldung BackLeftWindow und BackRightWindow behoben, Variablen hinzugefügt

Version 1.0 (15.12.2024)
- Initiale Version