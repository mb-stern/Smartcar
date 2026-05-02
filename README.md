# Modul für Smartcar für IP-Symcon

Dieses Modul ermöglicht es, Signale über die [Smartcar-Plattform](https://smartcar.com/de) in IP-Symcon zu empfangen/abzufragen, und bestimmte Fahrzeugfunktionen zu steuern.

Smartcar unterstützt aktuell **43 Fahrzeugmarken**.  
Prüfe hier, welche Endpunkte dein Fahrzeug unterstützt:  
👉 [Kompatible Fahrzeuge bei Smartcar](https://smartcar.com/de/product/compatible-vehicles)

Du findest alle Funktionen für das Smartcar Symcon Repository in den entsprechenden Modul-Teilen.

- __Smartcar__ ([Dokumentation](SmartcarVehicle))   
- __Smartcar__ ([Dokumentation](SmartcarSplitter))  
- __Smartcar__ ([Dokumentation](SmartcarConfigurator))  


## Versionen

| Version | Datum | Änderungen |
|----------|--------|------------|
| **4.0** | 02.05.2026 | - Komplett neue Version welche auf der API V3 aufsetzt und nur noch auf Signale ausgelegt ist. Diese Version ist nicht mit den älteren Versionen vor Version 4 kompatibel. Beim Update verbleiben die aktuellen Fahrzeuginstanzen funktionslos im Objektbaum und es können allenfalls noch Daten von aufgezeichneten Variablen transferiert werden |
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