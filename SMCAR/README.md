# Modul für Smartcar für IP-Symcon
Dieses Modul ermöglicht, Daten von Fahrzeugen über die Smartcar-Plattform abzufragen. 
Erstelle ein Profil und verbinde dein Fahrezug oder ein Testfahrzeug (https://smartcar.com/de)
Smartcar unterstützt aktuell 43 Fahrzeugmarken (https://smartcar.com/de/product/compatible-vehicles)
In der aktuellen Version dieses Moduls ist ein Fahrzeug unterstützt.
In der kostenlosen Version von Smartcar kann sowieso nur ein Live-Fahrezug pro Benutzerprofil angelegt werden.
Für mehrere Fahrezuge ist das Modul mehrmals anzulegen.

# Wichtig zu wissen zur Konfiguration von Smartcar
Das Modul verbindet sich über OAuth 2.0 mit der Smartcar API. 
Daher ist es erforderlich, eine Redirect URI in der Smartcar-Konfiguration einzutragen. 
Die Redirect URI ist der Pfad zum Webhook, welchen das Modul automatisch anlegt. 
Dieser Pfad setzt sich aus deiner Connenct-Adresse und dem Pfad des Webhook zusammen. 
Der Pfad des Webhook wird oben im Konfigurationsformular angezeigt. 
Deine Connect Adresse findest du unter Kern Instanzen/Connect und trägst diese dann im Konfigurationsformular von Symcon ein. 
Beides zusammen hinterlegst du dann in der Konfiguration von Smartcar unter 'REDIRECT URIS' Dies sieht zB so aus: https://hruw8ehwWERUOwehrWWoiuh.ipmagic.de/hook/smartcar_15583


Aktuell sind folgende Scopes (Endpunkte) durch das Modul unterstützt:
* "Fahrzeuginformationen lesen (/)"
* "Standort lesen (/location)"
* "Reifendruck lesen (/tires/pressure)"
* "Kilometerstand lesen (/odometer)"
* "Batteriestatus lesen (/battery)"

Aktuell sind folgende Ansteuerungen unterstützt
* "Laden Limit (/charge/limit)"
* "Laden Starten/Stopen (/charge)"

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

* Abfrage der verschiedener Fahrzeugdaten und ausführen verschiedener Ansteuerung am Fahrzeug.
* Die kostenlose Version unterstützt ein Fahrzeug mit 500 API-Calls pro Monat.
* Es gibt eine Bezahlversion für 2.99$ mit 1000 API-Calls pro Monat
* Die Testfahrzeuge der Smartcar-Plattform sind unterstützt. Zum testen sollten diese Verwendet werden, um den API-Verbrauch des Live-Fahrzeuges zu schonen.
* Vorsicht: Frag nur Endpunkte ab, die du wirklich brauchst, sonst ist das Guthaben schnell aufgebraucht. Lies dazu weiter unten die PHP-Befehlsreferenz

### 2. Voraussetzungen

- IP-Symcon ab Version 7.0
- Smartcar Profil mit einem Test-Fahrzeug oder einem Live-Fahrezug.

### 3. Software-Installation

* Über den Module Store kann das Modul noch nicht installiert werden da noch beta. Es muss im Store nach dem genauen Modulnamen gesucht werden.

### 4. Einrichten der Instanzen in IP-Symcon

- Unter 'Instanz hinzufügen' kann das 'Smartcar'-Modul mithilfe des Schnellfilters gefunden werden.  
- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Name     | Beschreibung
-------- | ------------------
Client ID                   |  Entnimm diesen in der Konfiguration von Smartcar unter OAuth
Client Secret               |  Entnimm diesen in der Konfiguration von Smartcar unter OAuth
Symcon Connect Adresse      |  Die Connect Adresse findest du in Symcon unter Kern Instanzen/Connect
Verbindungsmodus            |  Hier definierst du, ob es sich um ein Simmuliertes oder ein Live-Fahrzeug handelt. Die Fahrzeuge verwaltest du im Dashboard von Smartcar 
Berchtigungen (Scopes)      |  Hier sind die aktuell vom Modul unterstützen Scopes zur Auswahl. Wichtig ist, dass alle angewählt werden, die später abgefragt werden, sonst werden hier keine Werte geliefert. Im Zweifelsfalle alle aktivieren. Die Variablen werden automatisch erstellt und beim Deaktivieren wieder gelöscht.
Verbindung starten          |  Es erscheint ein Fenster mit einem URL. Diesen URL in eine Browserfenster kopieren und nun das Fahrezug mit dem Modul koppeln. Es erscheinen die unterstützen gewählten Scopes und die Berechtigungen wird erteilt. Am schluss erscheint eine Erfolgsmeldung. Das heisst, die Berchtigung wurde über die Redierct URI (Webhook) an das Modul erteilt
Fahrzeugdaten abrufen       |  Hier rufst du alle aktivierten Scopes ab. Sei vorsichtig bei einem Live-Fahrezug. Fünf aktivierte Scopes ergeben 5 API-Calls 


### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Es werden Variablen/Typen je nach Wahl der ID's erstellt. Pro ID wird eine Variable erstellt.

#### Profile

Name   | Typ
------ | ------- 
SMCAR.Odometer   |  Float  
SMCAR.Pressure   |  Float   
SMCAR.Progress   |  Float  

### 6. WebFront

Die Variablen zur Steuerung der Fahrezugfunktion können aus der Visualisierung heraus gesteuert werden.

### 7. PHP-Befehlsreferenz

`boolean SMCAR_BeispielFunktion(integer $InstanzID);`
Erklärung der Funktion.

Beispiel:
`WPLUX_FetchBattery(12345);`
`WPLUX_FetchLocation(12345);`
`WPLUX_FetchOdometer(12345);`
`WPLUX_FetchTires(12345);`
`WPLUX_FetchVehicleInfo(12345);`
`WPLUX_SetChargeLimit(12345);`
`WPLUX_SetChargeStartStop(12345);`

### 8. Versionen

Version 1.0 (04.02.2024)

- Initiale Version