# Smartcar Integration für IP-Symcon

Mit diesem Modul kannst du Fahrzeugdaten in IP-Symcon anzeigen und unterstützte Fahrzeugfunktionen steuern. Die Verbindung zum Fahrzeug erfolgt über [Smartcar](https://smartcar.com/de).

## Funktionen

### Fahrzeugdaten anzeigen

Je nach Fahrzeug stehen unter anderem diese Informationen zur Verfügung:

- Batteriestand, Reichweite und Kraftstoffstand.
- Standort, Fahrtrichtung, Kilometerstand und Geschwindigkeit.
- Reifendruck sowie Innen- und Außentemperatur.
- Zustand von Türen, Fenstern, Kofferraum und Zentralverriegelung.
- Fahrzeugzustand, Fehlercodes und Wartungsinformationen.

Welche Werte dein Fahrzeug bereitstellt, siehst du nach dem Verbinden in der Variablenauswahl.

### Ladevorgang verfolgen

- **Ladestatus und Lädt:** Zeigen, ob das Fahrzeug gerade lädt.
- **Ladekabel eingesteckt:** Zeigt den Anschlusszustand.
- **Ladeleistung:** Anzeige in kW.
- **Aktuelles Ladelimit:** Zeigt das vom Fahrzeug gemeldete Ladeziel.
- **Fertiggeladen:** Zeigt die voraussichtliche Fertig-Uhrzeit.
- **Verbleibende Ladezeit:** Anzeige in Minuten ohne Nachkommastellen.

Die Genauigkeit der Anzeigen hängt von den Informationen ab, die das Fahrzeug liefert.

### Fahrzeugfunktionen steuern

Bei unterstützten Fahrzeugen und entsprechender Freigabe sind diese Funktionen möglich:

- Laden starten oder stoppen und ein Ladelimit setzen.
- Ladeport öffnen oder schließen.
- Fahrzeug verriegeln oder entriegeln.
- Ein Navigationsziel an das Fahrzeug senden.
- Tägliche, wöchentliche oder Werktag-/Wochenend-Ladepläne setzen und löschen.

Nicht jedes Fahrzeug unterstützt alle Befehle. Eine Freigabe bei Smartcar bedeutet noch nicht, dass das Fahrzeug den jeweiligen Befehl ausführen kann. Beispielsweise kann das aktuelle Ladelimit lesbar sein, obwohl das Ändern nicht unterstützt wird.

Informationen zur Fahrzeugunterstützung findest du bei [Smartcar](https://smartcar.com/de/product/compatible-vehicles).

### Anzeigen nach Bedarf auswählen

- Über Checkboxen bestimmst du, welche Variablen du verwenden möchtest.
- Ausgewählte Variablen werden beim Speichern angelegt, auch wenn noch kein Fahrzeugwert vorliegt.
- Abgewählte Variablen werden beim Speichern entfernt und bei erneuter Auswahl wieder angelegt.
- Bestehende Werte und selbst vergebene Namen bleiben erhalten.
- Jede Checkbox bleibt auch bei neuen Datenpunkten der richtigen Variable zugeordnet.

Ein Fahrzeugwert kann mehrere Anzeigen bereitstellen, etwa Breitengrad, Längengrad und Fahrtrichtung. Die Fahrtrichtung wird mit Gradzeichen und einer Nachkommastelle angezeigt, beispielsweise **187,6°**.

Ausdrücklich nicht unterstützte Fahrzeugdaten werden aus der Auswahl ausgeblendet. Bereits vorhandene Variablen bleiben dabei erhalten. Vorübergehend fehlende Daten verändern die Auswahl nicht. Wird ein Wert später wieder erfolgreich geliefert, erscheint er mit seiner bisherigen Auswahl.

### Fahrzeugdaten aktualisieren

Eingerichtete Webhooks aktualisieren die Fahrzeugdaten automatisch. Mit **„Signale aus Vehicle Access abrufen“** kannst du sie zusätzlich manuell abrufen.

Abgewählte Variablen werden durch neue Fahrzeugdaten nicht wieder aktiviert. Fehlende Werte überschreiben keine gültigen gespeicherten Werte. Die Fahrzeugkonfiguration bleibt auch bei einer vorübergehenden Verbindungsstörung bedienbar.

## Einrichtung

IP-Symcon ab Version **8.2** und ein Smartcar-Konto sind erforderlich.

1. Das Modul installieren und zuerst einen **Smartcar Configurator** erstellen.
2. Über den Konfigurator die Verbindung und die Fahrzeuginstanzen einrichten.
3. Bei Smartcar unter **Configuration → Vehicle Access** die gewünschten Daten und Steuerfunktionen freigeben.
4. In der Fahrzeuginstanz die gewünschten Variablen auswählen und speichern.

Änderungen an den Freigaben übernimmst du mit **„Vehicle Access synchronisieren“**.

Weitere Anleitungen:

- [Fahrzeug einrichten und bedienen](SmartcarVehicle/README.md)
- [Smartcar-Verbindung einrichten](SmartcarSplitter/README.md)
- [Fahrzeuge hinzufügen](SmartcarConfigurator/README.md)

## Versionen

**4.9** – 13.10.2026

- Zusätzliche Fahrzeugdaten sowie Funktionen für Ladeport und Ladepläne ergänzt.
- Checkbox-Auswahl korrigiert: Auch bei neuen Datenpunkten bleibt jede Auswahl der richtigen Variable zugeordnet.
- Ausgewählte Variablen werden auch ohne aktuellen Fahrzeugwert angelegt und können durch Abwählen wieder entfernt werden.
- Ausdrücklich nicht unterstützte Datensignale werden ausgeblendet. Vorübergehend fehlende Daten ändern die Auswahl nicht.
- Ladeleistung wird korrekt in kW angezeigt.
- Fertig-Uhrzeit wiederhergestellt und verbleibende Ladezeit ohne Nachkommastellen ergänzt.
- Fahrtrichtung mit Gradzeichen und einer Nachkommastelle dargestellt.
- Fahrzeugkonfiguration lässt sich auch bei einer vorübergehend nicht erreichbaren Verbindung öffnen.
- Bestehende Variablen, gespeicherte Werte und selbst vergebene Namen bleiben erhalten.

**4.8** – 03.09.2026

- Codeänderungen nach Store-Review.

**4.7** – 27.08.2026

- Ein Problem wurde behoben, durch das Instanzen aufgrund eingehender Nachrichten angelegt werden konnten.
- Ein Problem wurde behoben, durch das der Konfigurator trotz einwandfreier Funktion als fehlerhaft markiert wurde.
- Das Senden von Befehlen und Abfragen einzelner Signale per Skript funktioniert wieder.
- Einige Codeanpassungen.

**4.6** – 16.08.2026

- Die Kompatibilitätsprüfung der Fahrzeuginstanz entfällt; alle empfangenen Datenpunkte mit gültigen Werten werden als Variablen angeboten.
- Verfügbare Signal- und Steuervariablen können im Konfigurationsformular individuell aktiviert oder deaktiviert werden.
- Steuervariablen werden automatisch anhand der über Vehicle Access erteilten Berechtigungen bereitgestellt.
- Webhook-Verwaltung ergänzt: Pro Fahrzeug können ein oder mehrere Webhooks ausgewählt und neu geladen werden.

**4.5** – 09.08.2026

- Der Webhook wird nun auf Aktualität geprüft und ältere Daten werden verworfen.
- In der Vehicle-Instanz kann nun der Powertrain-Filter deaktiviert werden.
- Einige Anpassungen zur API V3.

**4.4** – 19.07.2026

- Smartcar hat den Autorisierungsprozess geändert, dieser ist nun den neuen Vorgaben angepasst.
- Die Zugriffsberechtigungen werden nun ausschließlich unter Smartcar → Configuration → Vehicle Access verwaltet.
- Ein Fehler wurde beseitigt, durch den eine fehlerhafte Vehicle-Instanz erstellt wurde.
- Der Konfigurator ist nun mit dem entsprechenden Element von Symcon aufgebaut.

**4.3** – 12.07.2026

- Variablen aktualisieren sich nur noch, wenn sich der Datenstand ändert.
- Der OEM-Aktualisierungszeitpunkt kann über zusätzliche Variablen eingeblendet werden.
- Die Variablen bekommen beim Erstellen Positionen im Objektbaum, um die Übersichtlichkeit zu verbessern.

**4.2** – 24.05.2026

- Signalverarbeitung erweitert und bestehende Signale aktualisiert.

**4.1** – 06.05.2026

- Control Permissions wurden bisher noch nicht gesetzt. Alle aktuell möglichen Steuerbefehle sollten nun funktionieren.

**4.0** – 02.05.2026

- Komplett neue Version, welche auf der API V3 basiert und nur noch auf Signale ausgelegt ist.
- Diese Version ist nicht mit älteren Versionen vor 4.0 kompatibel.
- Beim Update verbleiben bestehende Fahrzeuginstanzen funktionslos im Objektbaum und es können allenfalls noch Daten aus aufgezeichneten Variablen übernommen werden.

**3.8** – 25.01.2026

- Diverse Fehler beim Senden von Befehlen (z. B. endlose Wiederholversuche und falsche Darstellung des Ladelimits) behoben.

**3.7** – 10.01.2026

- Code-Korrektur aufgrund Store-Review.

**3.6** – 04.01.2026

- Anpassung der Ausgabe für das Ladeende. Die Zeit wird entweder als Dezimalwert oder in Minuten geliefert.
- Zusätzliche Debug-Ausgabe für das Alter der OEM-Daten (teilweise nicht aktuell).

**3.5** – 02.01.2026

- Umstellung auf IPSModuleStrict und Anhebung der Kompatibilität auf Version 8.2.
- Variable für das Ladeende angepasst. Die alte Variable `Restladezeit` muss gelöscht werden.
- Tokenerneuerung verbessert.

**3.4** – 21.12.2025

- Fehlermeldung beim Verbinden des Fahrzeugs behoben.
- Automatische Scope-Erkennung deaktiviert (Probleme mit Signalen).
- Diverse Code-Anpassungen.

**3.3** – 19.10.2025

- Bei Erreichen des Rate-Limits wird nach der vorgegebenen Wartezeit erneut abgefragt.
- Verbesserung der Debug- und Fehlerausgaben.
- Code überarbeitet.
- README neu strukturiert (Scopes & Signals getrennt).

**3.2** – 14.10.2025

- Automatische Scope-Erkennung verbessert.

**3.1** – 07.10.2025

- Automatische Prüfung kompatibler Scopes im Konfigurationsformular.
- Es werden nur noch unterstützte Scopes abgefragt → weniger Fehler und unnötige Requests.
- Fehler bei der Abfrage der Batteriekapazität behoben.
- Ladeleistung wird korrekt dargestellt.
- Optionale Variable für Zeitpunkt der letzten Signale hinzugefügt.

**3.0** – 05.10.2025

- Unterstützung für Webhooks (Smartcar Signals) hinzugefügt.
- Hinweis: Erfordert entsprechenden Smartcar-Plan.
- Variablen werden automatisch erstellt.

**2.3** – 28.09.2025

- Token wird bei jeder Konfigurationsänderung oder nach Neustart automatisch erneuert.
- Behebt Probleme mit abgelaufenen Tokens.

**2.2** – 26.07.2025

- Verbesserung der Fehlerausgabe im Debug und Statusdialog.

**2.1** – 15.06.2025

- Rechtschreibkorrekturen.
- Codeanpassungen für den Ladestatus.

**2.0** – 02.01.2025

- Code und README überarbeitet.
- Anpassung für Store-Kompatibilität.

**1.3** – 26.12.2024

- Timer für Token-Erneuerung auf 90 Minuten fixiert.
- Token wird zusätzlich bei jeder Konfigurationsänderung erneuert.
- Behandlung von 401-Fehlern verbessert (Token wird erneuert und Anfrage wiederholt).
- Fehlerausgabe im Log aktiviert.

**1.2** – 22.12.2024

- Anpassung einiger Variablennamen.
- README überarbeitet.
- Modulname angepasst.
- Konfigurationsformular angepasst.
- Diverse Code-Änderungen.
- Variablenprofil für Zentralverriegelung angepasst.

**1.1** – 17.12.2024

- Fehler bei *BackLeftWindow* und *BackRightWindow* behoben.
- Weitere Variablen hinzugefügt.

**1.0** – 15.12.2024

- Initiale Version.

---

## Lizenz

MIT License

---

## Unterstützung

Falls dir das Modul gefällt und du die Weiterentwicklung unterstützen möchtest:

**PayPal:** https://paypal.me/mbstern
