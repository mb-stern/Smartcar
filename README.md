# Smartcar Integration für IP-Symcon

Dieses Modul ermöglicht es, Signale über die [Smartcar-Plattform](https://smartcar.com/de) in IP-Symcon zu empfangen bzw. abzufragen und bestimmte Fahrzeugfunktionen zu steuern.

Smartcar unterstützt aktuell **43 Fahrzeugmarken**.  
Prüfe hier, welche Funktionen dein Fahrzeug unterstützt:  
👉 [Kompatible Fahrzeuge bei Smartcar](https://smartcar.com/de/product/compatible-vehicles)

Du findest alle Funktionen für das Smartcar-Symcon-Repository in den entsprechenden Modul-Teilen:

- __SmartcarVehicle__ ([Dokumentation](SmartcarVehicle))   
- __SmartcarSplitter__ ([Dokumentation](SmartcarSplitter))  
- __SmartcarConfigurator__ ([Dokumentation](SmartcarConfigurator))  

> Wichtig: Beim Installieren des Moduls in Symcon muss zuerst ein **Smartcar Configurator** erstellt werden. Alle anderen Instanzen werden automatisch erstellt.

## Versionen

| Version | Datum | Änderungen |
|----------|--------|------------|
| **4.5** | 09.08.2026 | - Der Webhook wird nun auf Aktualität geprüft und ältere Daten werden verworfen.<br>- In der Vehicle-Instanz kann nun der Powertrain-Filter deaktiviert werden.<br>- Einige Anpassungen zur API V3.|
| **4.4** | 19.07.2026 | - Smartcar hat den Authorisierungsprozess geändert, dieser ist nun den neuen Vorgaben angepasst.Die Zugriffsberechtigungen werden nun ausschließlich unter Smartcar → Configuration → Vehicle Access verwaltet.<br>- Ein Fehler wurde beseitigt, dass eine fehlerhafte Vehicle-Instanz erstellt wurde.<br>- Der Konfigurator ist nun mit dem Element von Symcon aufgebaut. |
| **4.3** | 12.07.2026 | - Variablen aktualisieren sich nur noch, wenn sich der Datenstand ändert.<br>- Der OEM Aktualisierungszeitpunkt kann über zusätzliche Variablen eingeblendet werden.<br>- Die Variablen bekommen beim Erstellen eine Positionen im Objektbaum, um die Übersichtlichkeit zu verbessern. |
| **4.2** | 24.05.2026 | - Signalverarbeitung erweitert und bestehende aktualisiert. |
| **4.1** | 06.05.2026 | - Control Permissions wurden bisher noch nicht gesetzt. Alle aktuell möglichen Steuerbefehle sollten nun funktionieren. |
| **4.0** | 02.05.2026 | - Komplett neue Version, welche auf der API V3 basiert und nur noch auf Signale ausgelegt ist.<br>- Diese Version ist nicht mit älteren Versionen vor 4.0 kompatibel.<br>- Beim Update verbleiben bestehende Fahrzeuginstanzen funktionslos im Objektbaum und es können allenfalls noch Daten aus aufgezeichneten Variablen übernommen werden. |
| **3.8** | 25.01.2026 | - Diverse Fehler beim Senden von Befehlen (z. B. endlose Wiederholversuche und falsche Darstellung des Ladelimits) behoben. |
| **3.7** | 10.01.2026 | - Code-Korrektur aufgrund Store-Review. |
| **3.6** | 04.01.2026 | - Anpassung der Ausgabe für das Ladeende. Die Zeit wird entweder als Dezimalwert oder in Minuten geliefert.<br>- Zusätzliche Debug-Ausgabe für das Alter der OEM-Daten (teilweise nicht aktuell). |
| **3.5** | 02.01.2026 | - Umstellung auf IPSModuleStrict und Anhebung der Kompatibilität auf Version 8.2.<br>- Variable für das Ladeende angepasst. Die alte Variable `Restladezeit` muss gelöscht werden.<br>- Tokenerneuerung verbessert. |
| **3.4** | 21.12.2025 | - Fehlermeldung beim Verbinden des Fahrzeugs behoben.<br>- Automatische Scope-Erkennung deaktiviert (Probleme mit Signalen).<br>- Diverse Code-Anpassungen. |
| **3.3** | 19.10.2025 | - Bei Erreichen des Rate-Limits wird nach der vorgegebenen Wartezeit erneut abgefragt.<br>- Verbesserung der Debug- und Fehlerausgaben.<br>- Code überarbeitet.<br>- README neu strukturiert (Scopes & Signals getrennt). |
| **3.2** | 14.10.2025 | - Automatische Scope-Erkennung verbessert. |
| **3.1** | 07.10.2025 | - Automatische Prüfung kompatibler Scopes im Konfigurationsformular.<br>- Es werden nur noch unterstützte Scopes abgefragt → weniger Fehler und unnötige Requests.<br>- Fehler bei der Abfrage der Batteriekapazität behoben.<br>- Ladeleistung wird korrekt dargestellt.<br>- Optionale Variable für Zeitpunkt der letzten Signale hinzugefügt. |
| **3.0** | 05.10.2025 | - Unterstützung für Webhooks (Smartcar Signals) hinzugefügt.<br>- Hinweis: Erfordert entsprechenden Smartcar-Plan.<br>- Variablen werden automatisch erstellt. |
| **2.3** | 28.09.2025 | - Token wird bei jeder Konfigurationsänderung oder nach Neustart automatisch erneuert.<br>- Behebt Probleme mit abgelaufenen Tokens. |
| **2.2** | 26.07.2025 | - Verbesserung der Fehlerausgabe im Debug und Statusdialog. |
| **2.1** | 15.06.2025 | - Rechtschreibkorrekturen.<br>- Codeanpassungen für den Ladestatus. |
| **2.0** | 02.01.2025 | - Code und README überarbeitet.<br>- Anpassung für Store-Kompatibilität. |
| **1.3** | 26.12.2024 | - Timer für Token-Erneuerung auf 90 Minuten fixiert.<br>- Token wird zusätzlich bei jeder Konfigurationsänderung erneuert.<br>- Behandlung von 401-Fehlern verbessert (Token wird erneuert und Anfrage wiederholt).<br>- Fehlerausgabe im Log aktiviert. |
| **1.2** | 22.12.2024 | - Anpassung einiger Variablennamen.<br>- README überarbeitet.<br>- Modulname angepasst.<br>- Konfigurationsformular angepasst.<br>- Diverse Code-Änderungen.<br>- Variablenprofil für Zentralverriegelung angepasst. |
| **1.1** | 17.12.2024 | - Fehler bei *BackLeftWindow* und *BackRightWindow* behoben.<br>- Weitere Variablen hinzugefügt. |
| **1.0** | 15.12.2024 | - Initiale Version. |

---

## Lizenz

MIT License

---

## Unterstützung

Falls dir das Modul gefällt und du die Weiterentwicklung unterstützen möchtest:

**PayPal:** https://paypal.me/mbstern