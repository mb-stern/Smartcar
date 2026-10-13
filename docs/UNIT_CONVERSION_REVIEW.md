# Vergleich der Umrechnungen mit main und Smartcar API

Prüfdatum: 13.10.2026. Vergleichsbasis: `main` bei Commit `9e802e1` und `development`. `main` wurde ausschließlich gelesen.

Die Konverter und Einheiten der Signaldefinitionen wurden mit den 93 offiziellen Signalbeispielen im Repository abgeglichen. 23 davon enthalten eine ausdrückliche Einheit. Die aktuellen API-Seiten der zehn betreffenden Signalgruppen wurden zusätzlich erneut abgerufen. Es wurden keine echten Fahrzeugbefehle gesendet.

## Abweichungen zwischen main und API-Dokumentation

| Signal | main | API-Dokumentation | Entscheidung für development |
| --- | --- | --- | --- |
| `charge-wattage` | Rohwert direkt mit kW-Profil; keine Division durch 1000. | Leistung in Watt; Beispiel enthält `unit: watts`. Für eine mathematische Umrechnung in kW wäre `/ 1000` erforderlich. | Direkte kW-Verarbeitung bleibt auf ausdrücklichen Nutzerwunsch erhalten. Der Mercedes-Dump liefert `6` mit `watts`, während die Hersteller-App 6,1 kW zeigt. Die fehlende Dezimalstelle kann das Modul nicht rekonstruieren. |
| `charge-timetocomplete` | Ganzzahlige Werte werden als Minuten seit Mitternacht in eine Uhrzeit formatiert; Werte mit Dezimalpunkt als Dezimalstunden. `1360` ergibt `22:40 Uhr`, nicht 136 Minuten. | Geschätzte Restdauer bis zum aktiven Ladelimit in Minuten. | Einheitenlose Werte erhalten wieder das laut Nutzer zuvor richtige main-Verhalten. Eine ausdrücklich gelieferte Minuteneinheit wird als Restdauer behandelt. |

Die Uhrzeit-Deutung des einheitenlosen Mercedes-Werts ist eine Kompatibilitätsentscheidung für das zuvor funktionierende Modulverhalten, keine allgemeine Zusicherung der API-Dokumentation. Der Dump allein beweist keine alternative Einheit; ausschlaggebend sind der Code auf main und die Nutzerbestätigung des früheren Verhaltens.

## Weitere geprüfte Faktoren

| Größe | Umsetzung in main und development | Ergebnis |
| --- | --- | --- |
| Reifendruck | `kPa × 0,01 → bar`, beispielsweise 250 kPa → 2,5 bar. | Faktor korrekt für die dokumentierte Einheit kPa. |
| Entfernung/Reichweite | km unverändert; für ausdrücklich als `miles` gelieferte Werte `× 1,609344`. | Faktor korrekt; 1 Meile entspricht exakt 1,609344 km. |
| Energie/Batteriekapazität | kWh unverändert. | Entspricht den dokumentierten Einheiten. |
| Batterie-, Kraftstoff-, Ölzustand und Ladelimit | Prozentwerte 0–100 unverändert; keine Division durch 100. | Entspricht den dokumentierten Prozentwerten. |
| Temperaturen | Celsius unverändert. | Entspricht den dokumentierten Einheiten. |
| Stromstärke und Spannung | Ampere und Volt unverändert. | Kein Skalierungsfaktor erforderlich. |
| Kraftstoffmenge | Liter unverändert. | Kein Skalierungsfaktor erforderlich. |
| Geschwindigkeit und Ladegeschwindigkeit | km/h unverändert. | Kein Skalierungsfaktor erforderlich. |
| Koordinaten und Fahrtrichtung | Grad unverändert; Formatierung über Variablenprofile. | Keine Skalierung erforderlich. |
| OEM/API-Zeitstempel | Unix-Millisekunden `/ 1000 → Sekunden`; Reihenfolge mit höherer Präzision. | Faktor korrekt. |

Unter diesen geprüften Größen wurden außer Ladeleistung und Ladezeit keine weiteren Widersprüche der numerischen Umrechnung zur dokumentierten Einheit festgestellt. Das bestätigt die Umrechnung für die geprüften Einheiten, nicht die sachliche Richtigkeit jedes vom Hersteller oder Smartcar gelieferten Werts.

## Nachstellung des letzten Mercedes-Dumps

- Rohwert: `1360`, ohne Einheit.
- Herstellerzeitpunkt: 13.10.2026, 21:23:45 Uhr in Europe/Zurich.
- Uhrzeit nach main-Formatierung: **22:40 Uhr**.
- Daraus berechnete Restzeit: 76,25 Minuten; angezeigt ohne Nachkommastellen als **76 min**.
- Die frühere development-Berechnung addierte 1360 Minuten auf den Herstellerzeitpunkt und zeigte dadurch **14.10.2026, 20:03 Uhr**. Diese Interpretation wurde für einheitenlose Werte zurückgenommen.
- Bei ausdrücklich gelieferten `1360 min` bleibt die Interpretation als 22 Stunden 40 Minuten Restdauer erhalten.

Bestehende Variablen-IDs bleiben erhalten. Die Steuerfunktionen wurden nicht geändert.

## Quellen

- [Charge: Leistung, Restdauer, Energie, Strom und Spannung](https://smartcar.com/docs/api-reference/signals/charge)
- [TractionBattery: Kapazität, Reichweite und Ladezustand](https://smartcar.com/docs/api-reference/signals/tractionbattery)
- [Odometer](https://smartcar.com/docs/api-reference/signals/odometer)
- [Wheel: Reifendruck](https://smartcar.com/docs/api-reference/signals/wheel)
- [Motion: Geschwindigkeit](https://smartcar.com/docs/api-reference/signals/motion)
- [Climate](https://smartcar.com/docs/api-reference/signals/climate)
- [HVAC](https://smartcar.com/docs/api-reference/signals/hvac)
- [InternalCombustionEngine](https://smartcar.com/docs/api-reference/signals/internalcombustionengine)
- [LowVoltageBattery](https://smartcar.com/docs/api-reference/signals/lowvoltagebattery)
- [Location](https://smartcar.com/docs/api-reference/signals/location)
- Lokale Prüfdaten: `tests/fixtures/smartcar-v3.json`.
