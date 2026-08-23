# Smartcar Integration für IP-Symcon

Dieses Modul ermöglicht es, Fahrzeugdaten über die [Smartcar-Plattform](https://smartcar.com/de) in IP-Symcon zu empfangen bzw. abzufragen und unterstützte Fahrzeugfunktionen zu steuern.

Die verfügbaren Datenpunkte und Steuerfunktionen werden über **Smartcar → Configuration → Vehicle Access** festgelegt.  
Die tatsächlich vom Fahrzeug gelieferten Signale werden von der Fahrzeuginstanz automatisch erkannt und können anschließend im Konfigurationsformular einzeln aktiviert oder deaktiviert werden.

Smartcar unterstützt eine Vielzahl verschiedener Fahrzeugmarken.  
Weitere Informationen zur Fahrzeugunterstützung findest du hier:  
👉 [Kompatible Fahrzeuge bei Smartcar](https://smartcar.com/de/product/compatible-vehicles)

Du findest alle Funktionen für das Smartcar-Symcon-Repository in den entsprechenden Modul-Teilen:

- __SmartcarVehicle__ ([Dokumentation](SmartcarVehicle))
- __SmartcarSplitter__ ([Dokumentation](SmartcarSplitter))
- __SmartcarConfigurator__ ([Dokumentation](SmartcarConfigurator))

> Wichtig: Beim Installieren des Moduls in Symcon muss zuerst ein **Smartcar Configurator** erstellt werden. Alle weiteren benötigten Instanzen werden automatisch erstellt.

## Konfiguration

Die Zugriffsberechtigungen für Fahrzeugdaten und Steuerfunktionen werden direkt bei Smartcar unter **Configuration → Vehicle Access** festgelegt.

Nach einer Änderung der Vehicle-Access-Konfiguration kann diese über die Fahrzeuginstanz mit **„Vehicle Access synchronisieren“** übernommen werden.

Die Fahrzeuginstanz unterscheidet dabei zwischen:

- **Signalen:** Datenpunkte werden über die Smartcar Signals API bzw. über Webhooks empfangen. Es werden nur Datenpunkte berücksichtigt, für die Smartcar tatsächlich gültige Werte liefert.
- **Steuerfunktionen:** Verfügbare Steuerbefehle werden anhand der von Smartcar für die Fahrzeugverbindung zurückgegebenen `control_*`-Berechtigungen bereitgestellt.

### Signal- und Steuervariablen

Alle erkannten und verfügbaren Variablen werden im Konfigurationsformular der Fahrzeuginstanz angezeigt.

Standardmäßig sind verfügbare Variablen aktiviert. Nicht benötigte Variablen können über die Checkbox-Liste deaktiviert werden. Beim Deaktivieren wird die entsprechende Variable aus dem Objektbaum entfernt.

Wird eine Variable wieder aktiviert, wird sie erneut angelegt, sobald entsprechende Daten zur Verfügung stehen.

> Hinweis: Bereits vorhandene Variablennamen werden vom Modul nicht überschrieben. Dadurch bleiben vom Benutzer individuell vergebene Namen erhalten.

Mit **„Signale aus Vehicle Access abrufen“** können die aktuell vom Fahrzeug verfügbaren Signale manuell abgefragt und die Liste der verfügbaren Variablen aktualisiert werden.

### Webhooks

Wenn Smartcar Signals/Webhooks verwendet werden, verarbeitet das Modul eingehende Fahrzeugdaten automatisch.

Es werden nur erfolgreiche Signale berücksichtigt, die mindestens einen tatsächlichen Nutzwert enthalten. Antworten mit ausschließlich `null`, leeren Arrays oder leeren Werten führen nicht zur Anlage einer Variable.

Bereits deaktivierte Variablen werden durch einen Webhook nicht automatisch wieder aktiviert.

## Versionen

| Version | Datum | Änderungen |
|----------|--------|------------|
| **4.6** | 16.08.2026 | - Die Kompatibilitätsprüfung der Fahrzeuginstanz entfällt; alle empfangenen Datenpunkte mit gültigen Werten werden als Variablen angeboten.<br>- Verfügbare Signal- und Steuervariablen können im Konfigurationsformular individuell aktiviert oder deaktiviert werden.<br>- Steuervariablen werden automatisch anhand der über Vehicle Access erteilten Berechtigungen bereitgestellt.<br>- Nun ist auch die Verwaltung der Webhooks integriert. Es könne also pro Fahrzeug einer oder mehrere Webhooks gewählt werden. Auch das neu laden des Webhooks ist so möglich, was automatisch die aktuellsten Daten liefert.|
| **4.5** | 09.08.2026 | - Der Webhook wird nun auf Aktualität geprüft und ältere Daten werden verworfen.<br>- In der Vehicle-Instanz kann nun der Powertrain-Filter deaktiviert werden.<br>- Einige Anpassungen zur API V3. |
| **4.4** | 19.07.2026 | - Smartcar hat den Autorisierungsprozess geändert, dieser ist nun den neuen Vorgaben angepasst.<br>- Die Zugriffsberechtigungen werden nun ausschließlich unter Smartcar → Configuration → Vehicle Access verwaltet.<br>- Ein Fehler wurde beseitigt, durch den eine fehlerhafte Vehicle-Instanz erstellt wurde.<br>- Der Konfigurator ist nun mit dem entsprechenden Element von Symcon aufgebaut. |
| **4.3** | 12.07.2026 | - Variablen aktualisieren sich nur noch, wenn sich der Datenstand ändert.<br>- Der OEM-Aktualisierungszeitpunkt kann über zusätzliche Variablen eingeblendet werden.<br>- Die Variablen bekommen beim Erstellen Positionen im Objektbaum, um die Übersichtlichkeit zu verbessern. |
| **4.2** | 24.05.2026 | - Signalverarbeitung erweitert und bestehende Signale aktualisiert. |
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