# 🚗 Smartcar Modul für IP-Symcon (Configurator Instanz)


## 1. Funktionsumfang

- Anzeige aller verbundenen Smartcar-Fahrzeuge.  
- Erstellung von Fahrzeug-Instanzen in IP-Symcon.  
- Übersicht über kompatible Fahrzeuge und deren Fähigkeiten.  
- Vereinfachte Einrichtung neuer Fahrzeuge.  
- Zentrale Verwaltung aller Fahrzeuge innerhalb von IP-Symcon.  

---

## 2. Voraussetzungen

- IP-Symcon ab Version **8.2**  
- Smartcar-Konto  
- Mit Smartcar kompatibles Fahrzeug, welches mit dem OEM-Portal verbunden ist

---

## 3. Installation

Das Modul kann direkt über den **Symcon Module Store** installiert werden.
Wärend der Installation des Smartcar Moduls ist zuerst der **Smartcar Configurator** zu erstellen.
Der Splitter wir automatisch erstellt.

---

## 4. Einrichten der Instanz

![alt text](image.png)

Der Konfigurator benötigt keine klassische Konfiguration.  
Die Oberfläche stellt automatisch alle verfügbaren Fahrzeuge und Aktionen bereit.

| Aktion | Beschreibung |
|--------|--------------|
| **Neues Live-Fahrzeug verbinden** | Startet den Smartcar-Connect-Prozess, um ein neues Fahrzeug mit deinem Smartcar-Account zu verbinden. |
| **Liste aktualisieren** | Aktualisiert die Fahrzeugliste, ohne das Konfigurationsformular neu zu laden (z. B. nach dem Hinzufügen eines Fahrzeugs). |
| **Alle fehlenden Fahrzeug-Instanzen erstellen** | Erstellt automatisch für alle in Smartcar vorhandenen Fahrzeuge die entsprechenden IP-Symcon Fahrzeug-Instanzen. |
| **Smartcar-Fahrzeuge** | Zeigt alle in deinem Smartcar-Account vorhandenen Fahrzeuge an. |

---

## 5. Verwendung

Der Konfigurator zeigt:

- Alle verfügbaren Fahrzeuge aus deinem Smartcar-Account  
- Fahrzeugdetails wie Marke, Modell und Baujahr  
- Status der bereits angelegten Fahrzeug-Instanzen  
- Möglichkeit zur Erstellung neuer Fahrzeug-Instanzen  

---

## 6. Fahrzeug hinzufügen

1. Konfigurator öffnen  
2. **„Neues Live-Fahrzeug verbinden“** auswählen  
3. Smartcar-Connect-Prozess durchführen  
4. Fahrzeug wird automatisch im Konfigurator angezeigt  
5. Fahrzeug-Instanz erstellen  
6. Signale im Fahrzeugmodul auswählen  

---

## 7. Kompatibilität

- Die verfügbaren Signale und Befehle werden automatisch anhand des Fahrzeugs gefiltert.  
- Nicht unterstützte Funktionen werden ausgeblendet.  

---

## 8. Hinweise

- Der Konfigurator dient ausschließlich zur Einrichtung und Verwaltung der Fahrzeuge.  
- Die eigentliche Datenverarbeitung erfolgt im Fahrzeugmodul.  
- Änderungen an Fahrzeugen werden automatisch synchronisiert.  

---

## 9. Lizenz

Dieses Modul steht unter der **MIT-Lizenz**.  
© 2025 Stefan Künzli  
https://opensource.org/licenses/MIT