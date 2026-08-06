# EVCC für LoxBerry

Ein LoxBerry-Plugin, das [EVCC](https://evcc.io/) auf dem LoxBerry einrichtet
und seine Werte in die Form übersetzt, die der Loxone-Energiemanager erwartet.

EVCC hat sich als herstellerübergreifender Standard für das PV-Überschussladen
etabliert. Es kann das gut und braucht dafür keine Hilfe. Was fehlt, ist der
Weg nach Loxone: EVCC rechnet in Watt und veröffentlicht unter eigenen Namen,
der Energiemanager will Kilowatt an vier bestimmten Anschlüssen. Dieses Plugin
ist der Übersetzer dazwischen.

## Was es tut

- **Richtet EVCC ein.** Bei der Installation wird die Paketquelle von evcc.io
  eingetragen und EVCC per `apt` installiert — kein Docker, keine Handarbeit.
  Ist EVCC schon vorhanden, bleibt es unangetastet.
- **Holt die Werte** im einstellbaren Takt (5 bis 60 s) über die REST-Schnittstelle.
- **Rechnet um.** Watt zu Kilowatt, Nanosekunden zu Minuten, Anteile zu Prozent.
  Die Vorzeichen passen bereits: EVCC zählt Netzbezug und Speicherentladung
  positiv, der Energiemanager auch.
- **Liefert nach Loxone** auf zwei Wegen: über MQTT (der Regelweg) und über
  einen Token-geschützten HTTP-Endpunkt.
- **Erzeugt die Importdateien** für Loxone Config — virtuelle Eingänge und,
  wenn freigegeben, virtuelle Ausgänge.
- **Nimmt Befehle entgegen**: Lademodus, Limit-SoC, Phasen, Ströme, Priorität,
  Preisschwelle, Battery Boost, Batteriemodus, Puffer- und Prioritäts-SoC.

## Die vier Größen des Energiemanagers

| Vom Plugin | Anschluss | Umrechnung |
|---|---|---|
| `EVCC_NETZ_KW` | `Gpwr` | W → kW, Vorzeichen unverändert |
| `EVCC_PV_KW` | `Ppwr` | W → kW |
| `EVCC_SPEICHER_KW` | `Spwr` | W → kW, Vorzeichen unverändert |
| `EVCC_SPEICHER_SOC` | `Soc` | unverändert |

Ein Hinweis, der in der Oberfläche wiederholt wird: Wer die Wallbox zusätzlich
als Last in den Energiemanager hängt, lässt zwei Systeme dieselbe Steckdose
regeln. Meist ist es besser, die Wallbox EVCC zu überlassen und die
Ladeleistung nur als *Statuseingang* in den Baustein zu geben.

## Voraussetzungen

- LoxBerry 3.0 oder neuer
- Raspberry Pi (arm64/armhf) oder x86 — für diese Architekturen gibt es
  EVCC-Pakete
- Das MQTT-Gateway von LoxBerry, eingeschaltet unter *System → MQTT Gateway*.
  Es ist Bestandteil des Systems und kein Plugin.

## Einrichten

1. **EVCC selbst konfigurieren** — Wallbox, Zähler und Fahrzeuge in der
   EVCC-Oberfläche unter Port 7070. Das Plugin liest nur, was dort steht.
2. Im Reiter *Einstellungen* Adresse prüfen, Umfang festlegen.
3. Im Reiter *MQTT* das Abo ins Gateway eintragen. **Ohne diesen Eintrag kommt
   am Miniserver nichts an** — das ist die häufigste Fehlerursache.
4. Im Reiter *Einbindung in Loxone* die Vorlage erzeugen und importieren.
5. Im Reiter *Test* die Selbstprüfung ansehen.

## Steuerung aus Loxone

Standardmäßig **aus**. EVCC regelt selbst; wer zusätzlich aus Loxone eingreift,
hat zwei Regler am selben Ventil. Die Freigabe ist für Fälle gedacht, in denen
Loxone bewusst die Führung übernimmt.

Der Endpunkt liegt im unangemeldeten Bereich, damit Loxone ihn ohne
Zugangsdaten erreicht, und ist durch ein Token geschützt. Verglichen wird mit
`hash_equals`. Unbekannte Aktionen und Werte außerhalb des erlaubten Bereichs
werden abgewiesen, nicht zurechtgebogen.

## Was beim Entfernen passiert

Das Plugin entfernt **EVCC nicht mit**. Wer das Plugin deinstalliert, will
meistens nur die Loxone-Anbindung los — nicht seine eingerichtete
Wallbox-Steuerung samt Datenbank. Das Entfernen von EVCC steht im
Deinstallationsprotokoll.

## Bekannte Unschärfe

Die MQTT-Themen von EVCC sind dokumentiert, die genaue Form der
REST-Antwort `/api/state` ist es nicht — und sie hat sich zwischen
EVCC-Fassungen schon verschoben. Das Plugin nimmt deshalb je Feld mehrere
Kandidatenpfade an und zeigt im Reiter *Test*, welcher wirklich getroffen hat.
Fehlt ein Feld, das es geben müsste, zeigt der Knopf *Rohantwort* die
unveränderte Antwort von EVCC; die Zuordnung ist dann eine Zeile in
`ev_felder()`.

## Lizenz

Apache 2.0. EVCC selbst steht unter MIT und wird nicht mitgeliefert, sondern
aus der offiziellen Paketquelle installiert.
