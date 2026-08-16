# EVCC für LoxBerry

Ein LoxBerry-Plugin, das [EVCC](https://evcc.io/) auf dem LoxBerry einrichtet
und seine Werte in die Form übersetzt, die der Loxone-Energiemanager erwartet.

EVCC hat sich als herstellerübergreifender Standard für das PV-Überschussladen
etabliert. Es kann das gut und braucht dafür keine Hilfe. Was fehlt, ist der
Weg nach Loxone: EVCC rechnet in Watt und veröffentlicht unter eigenen Namen,
der Energiemanager will Kilowatt an vier bestimmten Anschlüssen. Dieses Plugin
ist der Übersetzer dazwischen.

## Neu in 0.9.9

**Der Abrufdienst konnte nie starten.** `bin/ev_abruf.php` suchte seine Programmbibliothek
ueber `dirname(__DIR__) . '/webfrontend/htmlauth/…'`. Im entpackten Archiv
liegen `bin/` und `webfrontend/` nebeneinander, auf dem installierten
LoxBerry in getrennten Baeumen — der Aufruf endete dort bei jedem Cron-Lauf
mit `Failed opening required`. Weil die Cron-Zeile nach `/dev/null` schreibt,
stand das nirgends. Damit wurden seit der Einfuehrung des Dienstes keine Werte geholt.

Die Bibliothek wird jetzt ueber eine Kandidatenliste gesucht; findet keiner
sie, schreibt der Dienst auf die Fehlerausgabe, **welche Datei er wo gesucht
hat**, und endet mit Rueckgabewert 1 statt stillschweigend.

Nach dem Update einmal von Hand pruefen:

```bash
php /opt/loxberry/bin/plugins/<ordner>/ev_abruf.php; echo "Rueckgabewert: $?"
```

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

## Laden nach Strompreis

Ist in EVCC ein Spottarif hinterlegt (Tibber, aWATTar, Octopus, EPEX Spot),
reicht das Plugin drei Werte durch: den aktuellen Arbeitspreis, die
Einspeisevergütung und den CO₂-Anteil — dazu je Ladepunkt die Meldung, ob
gerade *wegen des günstigen Preises* geladen wird. Die Preisgrenze
(`smartCostLimit`) lässt sich aus Loxone setzen.

Die Entscheidung trifft **EVCC**. In Loxone eine zweite Preisautomatik zu
bauen, führt dazu, dass beide gegeneinander schalten; im Reiter *Loxone* steht,
warum und was stattdessen sinnvoll ist.

## Was beim Entfernen passiert

Das Plugin entfernt **EVCC nicht mit**. Wer das Plugin deinstalliert, will
meistens nur die Loxone-Anbindung los — nicht seine eingerichtete
Wallbox-Steuerung samt Datenbank. Das Entfernen von EVCC steht im
Deinstallationsprotokoll.

Was **sehr wohl** mitgeht, sind Konfiguration und Daten des Plugins. In
`evcc.json` steht das EVCC-Passwort im Klartext und das Token des
unangemeldeten Endpunkts; beides bleibt nach dem Entfernen nicht liegen.

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

## Änderungen

### 0.9.1

- **Zustand nach einem Ausfall.** Schlug der Abruf fehl, gab das Plugin den
  letzten guten Stand mit `ok=0` zurück, schrieb das aber nicht in den
  Zwischenspeicher. Die Datei behielt `ok=1`, und weil ihr Zeitstempel
  stehenblieb, galt sie bei jedem Loxone-Abruf als veraltet — jede Abfrage
  stellte eine eigene HTTP-Anfrage und lief in die Zeitgrenze. Gemessen an
  einem Takt von 10 s und sekündlicher Abfrage: 57 Versuche je Minute vorher,
  12 nachher. Das Alter (`ALTER_S`) wächst weiter wie bisher.
- **Token.** Der Rückfall auf `mt_rand` ist entfallen. Er war gefährlicher als
  gar keiner: aus wenigen Ausgaben eines Mersenne-Twisters lässt sich der
  innere Zustand bestimmen, und dieses Token schützt den einzigen schaltenden
  Endpunkt. Fehlt sichere Zufälligkeit, wird abgebrochen und gesagt, warum.
  Die erste Erzeugung läuft jetzt unter einer Sperre.
- **Sicherung beim Update** liegt nicht mehr unter `/tmp` (auf dem LoxBerry
  eine Ramdisk), sondern unter `data/plugins/`. Ein Neustart zwischen den
  beiden Update-Schritten hätte sonst die Zugangsdaten mitgenommen.
- **Deinstallation** entfernt Konfiguration und Daten mit — bis 0.9.0 blieb das
  Passwort im Klartext liegen.
- **MQTT.** Thema und Nutzlast werden vor dem Senden gesäubert. Das Gateway
  liest zeilenweise und trennt an Leerzeichen; ein Umbruch oder ein Leerzeichen
  im Thema hätte die Nachricht zerteilt. Fehlgeschlagene Sendungen zählen nicht
  mehr als erfolgreich.
- **Sperrdatei.** Ließ sie sich nicht öffnen, endete der zeitgesteuerte Abruf
  ohne einen Eintrag im Protokoll. Jetzt steht dort, was zu prüfen ist.
- **Kleinigkeiten.** `str_replace` bekommt seinen Wert als Zeichenkette
  (unter PHP 8.1 sonst eine Verwarnung, ab 9 ein Fehler); wiederholte Meldungen
  werden im Arbeitsspeicher gemerkt statt bei jedem Durchlauf aus einer Datei
  gelesen; `jq` und `mosquitto-clients` sind aus den Paketabhängigkeiten
  entfernt, weil sie nirgends benutzt wurden.
- **Neu im Reiter Loxone:** ein Abschnitt zum Laden nach Strompreis und einer
  zur Rückfallebene, in der Loxone EVCC ohne dieses Plugin anspricht — samt der
  Bedingung, unter der das überhaupt geht.
