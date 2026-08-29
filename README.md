# EVCC für LoxBerry

Ein LoxBerry-Plugin, das [EVCC](https://evcc.io/) auf dem LoxBerry einrichtet
und seine Werte in die Form übersetzt, die der Loxone-Energiemanager erwartet.

EVCC hat sich als herstellerübergreifender Standard für das PV-Überschussladen
etabliert. Es kann das gut und braucht dafür keine Hilfe. Was fehlt, ist der
Weg nach Loxone: EVCC rechnet in Watt und veröffentlicht unter eigenen Namen,
der Energiemanager will Kilowatt an vier bestimmten Anschlüssen. Dieses Plugin
ist der Übersetzer dazwischen.

## Neu in 0.9.18

Die Erkennung einer Entwicklerfassung prüfte nur auf `-dev`. Gemessen an
derselben Fassung schreibt `apt` sie aber als `0.315.0~dev.1786876734+3c25327f7`
mit **Tilde**, während `evcc -v` sie als `0.315.0-dev+3c25327f7` mit
Bindestrich meldet. Beide Schreibweisen werden jetzt erkannt — im Skript und im
Reiter *Test*.

## Neu in 0.9.17

**Das Update spielte eine Entwicklerfassung ein, ohne es zu sagen.**

Am Gerät gemessen: angekündigt war `0.314.0` (EVCCs eigene `availableVersion`),
eingespielt wurde `0.315.0-dev+3c25327f7`. Auf der Maschine ist der
**nightly**-Kanal von `dl.evcc.io` eingetragen, und `apt` nimmt die höchste
Fassung aus *allen* Quellen. Das Skript aus 0.9.15 begrenzte nur das
Auffrischen auf die EVCC-Quelle, nicht die Auswahl — und es nahm mit `head -1`
die erste gefundene Quelldatei, ohne zu erwähnen, dass es mehrere gibt.

- **Alle** `dl.evcc.io`-Quellen werden aufgefrischt und aufgelistet. Sind es
  mehrere, steht der Hinweis dabei, dass `apt` die höchste Fassung aus allen
  nimmt.
- Vor der Installation gibt das Skript **`apt-cache policy evcc`** aus. Dort
  steht, welche Fassung aus welchem Kanal genommen wird — wer das liest, wird
  nicht überrascht.
- Ist das Ergebnis eine Entwicklerfassung, wird das ausdrücklich gesagt — im
  Ausgabetext des Skripts **und** als Hinweis im Reiter *Test*, solange sie
  läuft.

**Gepinnt wird nicht.** Wer den nightly-Kanal eingetragen hat, hat das
vielleicht mit Absicht getan. Das Plugin entscheidet das nicht — es macht es
sichtbar.

## Neu in 0.9.16

Zwei Texte, die seit 0.9.15 nicht mehr stimmten — beide am Bildschirm einer
laufenden Anlage aufgefallen.

- **„Das Plugin aktualisiert EVCC nicht selbst"** stand unter dem Hinweis auf
  eine neuere EVCC-Fassung, während zwei Zeilen darüber in derselben
  Selbstprüfung *„Kann die Oberfläche EVCC aktualisieren? **Ja**"* stand. Zwei
  Aussagen auf einer Seite, die sich widersprechen. Der Satz hängt jetzt an
  dem, was wirklich möglich ist: Knopf vorhanden und freigegeben, vorhanden
  aber gesperrt, oder gar nicht vorhanden. Beide Stellen — Reiter *Test* und
  der Hinweis im Reiter *Einstellungen* — holen ihn aus **einer** Quelle.
- **„Fehlt ein Gerät (kein Speicher, keine PV), ist das richtig so"** erklärte
  nicht, warum 23 Felder fehlen, wenn EVCC schlicht keinen Ladepunkt führt.
  Gemessen an einer Anlage **mit** PV, aber ohne Wallbox: 13 von 51 Feldern
  aufgelöst, und alle fehlenden `lp*`- und `fz*`-Felder hatten genau diesen
  einen Grund. Führt EVCC keinen Ladepunkt, steht das jetzt dabei.

## Neu in 0.9.15

**EVCC lässt sich aus der Oberfläche aktualisieren — als Option, ab Werk aus.**

Das Plugin installiert EVCC ohnehin selbst und zeigt seit 0.9.13 an, wenn eine
neuere Fassung angeboten wird. Der Verweis auf die Kommandozeile war damit eine
halbe Funktion. Der Knopf steht im Reiter *Test*, ist **orange** (er unterbricht
eine laufende Ladung) und erscheint erst, wenn im Reiter *Einstellungen* der
Haken gesetzt ist.

Vier Wachen, ohne die es die Funktion nicht gäbe:

- **`/etc/evcc.yaml` wird nie ersetzt.** Bei einer geänderten
  Konfigurationsdatei fragt `dpkg` nach; in einem nicht-interaktiven Lauf
  entschiede sonst eine Vorgabe über die Datei des Anwenders. Deshalb
  `--force-confold` **und** eine datierte Sicherung vor jedem Lauf.
- **Das ausgeführte Skript liegt nicht im Plugin-Ordner.** Der gehört dem
  Benutzer `loxberry`; eine von root ausgeführte Datei, die ein
  unprivilegierter Benutzer schreiben kann, wäre eine Hintertür. Die
  Installation legt es nach `/usr/local/sbin/loxberry-evcc-update`, es gehört
  `root`, und die sudo-Regel nennt genau diesen einen Pfad — **ohne
  Argumente**, es gibt also nichts einzuschleusen.
- **Nur die Paketquelle von EVCC wird aufgefrischt**, nicht das ganze System.
  Ein `apt-get update` über alle Quellen kann an einer fremden, kaputten
  Quelle scheitern — und dann hätte das Plugin etwas zerlegt, was es nichts
  angeht.
- **Nie automatisch.** Kein Cron, kein stiller Lauf. Ein Update startet EVCC
  neu, und das entscheidet der Mensch.

Gemeldet wird die **Wirkung**, nicht der Rückgabewert allein: die Fassung wird
vor und nach dem Lauf ausgelesen und beides angezeigt. Hat sich nichts
geändert, steht das ausdrücklich da, statt einen Erfolg zu behaupten.

Die Deinstallation räumt Skript und sudo-Regel wieder weg — und sagt es
ausdrücklich, wenn sie ohne Root-Rechte läuft und es nicht kann, statt still zu
scheitern.

## Neu in 0.9.14

Beides am Bildschirm einer echten Anlage aufgefallen.

- **Die Fehlermeldung von EVCC kam abgeschnitten an.** Angezeigt wurde
  *„EVCC meldet: sponsorship"* statt *„sponsorship: token is expired — get a
  fresh one from https://sponsor.evcc.io"*. Der Grund: die Auswertung nahm den
  **ersten** lesbaren Teil der Struktur, und das ist offenbar nur die
  Fehlerklasse. Die Form von `fatal` ist nicht dokumentiert — sie war geraten.
  Jetzt werden **alle** lesbaren Teile in ihrer Reihenfolge zusammengesetzt:
  eine Zeichenkette kommt unverändert heraus, `{klasse, meldung}` ergibt genau
  den Satz aus der EVCC-Oberfläche, und jede andere Form verliert ebenfalls
  nichts.
- **„Es steht hier immer noch 127.0.0.1."** Der Wert im Feld *Adresse von
  EVCC* ist richtig — das Plugin läuft auf dem LoxBerry und erreicht EVCC über
  die Rückschleife. Anklicken lässt er sich von einem anderen Rechner aus
  nicht, denn im Browser heißt `127.0.0.1` immer *dieser* PC. Unter dem Feld
  steht jetzt die Adresse, die von dort aus wirklich trägt.

## Neu in 0.9.13

**Das Plugin hat gemeldet, alles sei in Ordnung, während nichts funktionierte.**

An einer echten Anlage gemessen: EVCC 0.311.1 läuft als Dienst, `/api/state`
antwortet mit HTTP 200 und gültigem JSON — und liefert 30 Schlüssel, die
ausnahmslos Konfiguration sind. Kein Messwert. Die eigene Oberfläche von EVCC
nannte den Grund: `sponsorship: token is expired`. EVCC hatte den Start
abgebrochen. In der Antwort stand das die ganze Zeit, im Schlüssel `fatal`,
dazu `setupRequired: true` und leere `loadpoints`.

Bis 0.9.12 hat das Plugin daraus 97 Nullen gemacht, **22 von 22 Prüfungen als
bestanden gemeldet** und die Leere mit *„Fehlt ein Gerät (kein Speicher, keine
PV), ist das richtig so"* erklärt. Beruhigend und falsch — und in Loxone sieht
0 kW Netzbezug aus wie ein ausgeglichenes Haus. Das ist die stille
Falschaussage, die diese Hausregeln als schlimmste Fehlerart führen.

- Eine neue Prüfzeile **„Läuft EVCC wirklich?"** steht vor der Feldzuordnung,
  weil sie deren Ursache beantwortet. Sie zeigt ein Kreuz und **gibt den
  Fehlertext von EVCC wörtlich wieder**.
- Die Reihenfolge der Diagnose ist Absicht: erst der Startfehler, dann
  „nicht eingerichtet". Wer damit anfängt, schickt jemanden in die
  Grundeinrichtung, dessen Konfiguration längst steht.
- Feldzuordnung und die vier Energiemanager-Zeilen beschwichtigen nicht mehr
  mit *„ohne PV-Anlage ist das normal"*, solange EVCC nicht läuft.
- Lässt sich **kein einziges** Feld zuordnen, obwohl EVCC läuft, ist das ein
  Befund und kein Hinweis.
- Neu für Loxone: **`EVCC_BETRIEBSBEREIT`** (0/1), **`FEHLER_NR = 5`**
  (Startfehler) und **`FEHLER_NR = 4`** (nicht eingerichtet). Der Fehlertext
  steht zusätzlich im Klartextfeld `LETZTER_FEHLER`. `OK` bleibt 1, denn die
  Werte *sind* aktuell; es gibt nur keine. Wer auf brauchbare Zahlen wartet,
  verknüpft `OK` **und** `BETRIEBSBEREIT`.
- Meldet EVCC eine neuere Fassung (`availableVersion`), steht das jetzt in der
  Oberfläche. Das Plugin aktualisiert EVCC nicht selbst.

Die Feldpfade wurden **nicht** geändert. Der erste Verdacht lautete, die Form
von `/api/state` habe sich in EVCC 0.311 verschoben; die Messung hat ihn
widerlegt. Wer hier „korrigiert" hätte, hätte funktionierende Pfade gegen
erfundene getauscht.

## Neu in 0.9.12

Drei Fehler, alle im Betrieb an einer echten Anlage aufgefallen — nicht am
Prüfstand.

- **Die Zweitschrift wurde gelesen, aber nie zurückgeschrieben.** Fehlt
  `evcc.json` (etwa weil ein Update den Konfigurationsordner entfernt hat),
  holte 0.9.11 die Werte bei *jedem* Aufruf erneut aus der Sicherung und
  schrieb jedes Mal eine Protokollzeile. Gemessen: fünf Aufrufe, fünf Zeilen,
  Datei nicht wiederhergestellt — und `ev_config()` läuft je Endpunktaufruf
  mehrfach, der Cron viermal die Minute. 0.9.10 hatte die Datei einmal kopiert
  und danach Ruhe gegeben; das war ein Rückschritt. Jetzt wird sie einmal
  wiederhergestellt und einmal gemeldet. Der unangemeldete Endpunkt legt
  weiterhin nichts an.
- **Der Reiter MQTT trug drei Überschriften mit demselben Wort** — eine fest
  im PHP, dazu `EINST.H_MQTT` und `MQTT.H_TITEL`, beide „MQTT". Die feste ist
  weg, die beiden anderen heißen jetzt *Veröffentlichung nach MQTT* und
  *MQTT-Gateway von LoxBerry*.
- **Der Knopf „EVCC-Oberfläche" konnte nie funktionieren.** Er zeigte auf die
  eingetragene Adresse, und die lautet üblicherweise `http://127.0.0.1:7070`.
  Aus Sicht des LoxBerry ist das richtig — im Browser des Anwenders heißt
  `127.0.0.1` aber *dieser PC*, und es kam ausnahmslos
  `ERR_CONNECTION_REFUSED`. Der Knopf setzt jetzt den Namen ein, unter dem die
  Seite gerade aufgerufen wurde, und behält den Port von EVCC. Die
  Konfiguration bleibt unangetastet: der Abruf des Plugins läuft weiter über
  `127.0.0.1`, und das ist dort auch richtig.
- **„Dienst start ausgeführt."** — der englische Unterbefehl von `systemctl`
  stand wörtlich in einem deutschen Satz. Es gibt jetzt je Vorgang einen
  eigenen, richtig gebeugten Satz in beiden Sprachen: *Der EVCC-Dienst wurde
  gestartet / angehalten / neu gestartet.*

## Neu in 0.9.11

### Zwei Ausfälle, die das Plugin am Gerät unbrauchbar gemacht haben

**Der Endpunkt für den Miniserver ist nie angelaufen.** `webfrontend/html/index.php`
suchte seine Programmbibliothek über `dirname(__DIR__) . '/htmlauth/ev_lib.php'`.
Im entpackten Archiv liegen `html/` und `htmlauth/` nebeneinander, auf dem
installierten LoxBerry in getrennten Bäumen — gesucht wurde dort
`webfrontend/html/plugins/htmlauth/ev_lib.php`, ein Verzeichnis, das es nicht
gibt. `require_once` brach fatal ab, und weil vier Zeilen darüber
`display_errors` abgeschaltet wird, kam beim Miniserver ein **leerer HTTP 500**
an: keine Meldung, kein Protokolleintrag. Gemessen im nachgebauten Aufbau:
**alle 18 Aufrufe** — vier lesende, vierzehn schreibende, dazu die
Token-Abweisung — endeten mit HTTP 500 und 0 Byte. Damit hat dieses Plugin auf
keiner echten Anlage je einen Wert nach Loxone geliefert und keinen Befehl
ausgeführt.

Genau diese Korrektur hatte `bin/ev_abruf.php` schon in 0.9.9 bekommen. Die
Nachbardatei nicht.

**Die Bedienoberfläche war ebenfalls nicht erreichbar.** `loxberry_system.php`
überschreibt beim Einbinden das `$p` des Aufrufers. `webfrontend/htmlauth/index.php`
las danach `$p['home']` und suchte `/libs/phplib/loxberry_web.php` — die Seite
brach fatal ab. Gemessen unter PHP 7.4 **und** 8.4: 0 Zeichen Ausgabe.

Beides ist behoben; jede Variable im Hauptteil trägt jetzt das Plugin-Kürzel.

### Die Selbstheilung hat ihre eigene Rettung zerstört

Eine **abgeschnittene** `evcc.json` — Stromausfall mitten im Schreiben — galt
nicht als beschädigt, sondern als leer. Es entstand die Werkseinstellung, und
weil das Token damit fehlte, wurde sofort zurückgeschrieben — **über die
intakte Zweitschrift**. Gemessen: EVCC-Passwort weg, Token neu (und damit alle
Adressen in Loxone Config ungültig), Sicherung mit vernichtet, kein Wort im
Protokoll.

Jetzt ist ungültiges JSON ein Fehler: er wird protokolliert, die Zweitschrift
wird **gelesen** statt überschrieben, und die beschädigte Datei bleibt als
`evcc.json.kaputt` liegen. Geschrieben wird über eine Nebendatei mit `rename` —
ein halb geschriebener Stand kann nicht mehr entstehen.

### Weitere behobene Fehler

- **MQTT-Autostart** wurde am Schlüssel `Autostart` abgelesen; er heißt
  `Gatewayautostart`. Der Reiter *Test* zeigte deshalb bei korrekt
  eingerichtetem Gateway ein dauerhaftes Kreuz und der Reiter *MQTT* eine
  Warnung, die nie zutraf.
- **Ohne JavaScript war die Seite leer.** `sm-active` stand nur in CSS und
  Skript, nie im ausgelieferten HTML. Jetzt entscheidet der Server, welcher
  Reiter offen ist.
- **Der unangemeldete Endpunkt hat geschrieben.** Ein Aufruf *ohne* Token legte
  Konfiguration, Sperrdatei und Zweitschrift an. Er liest jetzt nur noch.
- **Die Deinstallation ließ Passwort und Token liegen** — in
  `config/plugins/evcc.backup.evcc.json`, neben dem Ordner, den sie entfernt —
  und meldete zwei Zeilen später, beides sei gelöscht.
- **Die sudo-Regel wurde nie angelegt**, wenn EVCC schon installiert war. Wer
  EVCC von Hand eingerichtet hatte, bekam drei Knöpfe, die nie wirken konnten.
- **Die Loxone-Vorlage war nicht stabil**: ohne Zwischenspeicher fehlte je
  Fahrzeug ein Eingang. Da `/tmp` eine Ramdisk ist, entstand nach jedem
  Neustart die kurze Fassung.
- **MQTT lief über `socket_create`** aus der Erweiterung `php-sockets`, die
  nicht in `dpkg/apt` stand. Fehlt sie, ist das kein abfangbarer Fehler,
  sondern ein fataler. Jetzt über `stream_socket_client`, das zum Kern gehört.
- **Der Cron warf die Fehlerausgabe weg** — genau die Auskunft, die 0.9.9
  eingebaut hatte. Sie landet jetzt in `cron.err`.
- **Fehlermeldungen nennen den Antwortenden**: „Verbindung abgewiesen",
  „Zeitüberschreitung" und „kein Weg dorthin" sind drei verschiedene Ursachen.
  Kommt HTML statt JSON, steht das samt HTTP-Code und Anfang der Antwort da.
- Der Endpunkt fragt EVCC nicht mehr bei **jeder** Loxone-Abfrage selbst an;
  die Ausgangsvorlage trägt `CmdOnMethod`, `CmdOffMethod` und `Repeat`, die
  Eingangsvorlage ein `<Info>`-Element und `Unit`; `ctype_digit` ist raus;
  `CUSTOM_LOGLEVELS` und `ARCHITECTURE` stehen auf `false`, weil beide nichts
  bewirkt haben; die Überschrift im Reiter *Logdateien* heißt nicht mehr
  „Protokoll".

### Der Reiter Test ruft den Endpunkt jetzt wirklich auf

Achtzehn Prüfzeilen hatte 0.9.10 — und keine einzige rief den eigenen Endpunkt
auf. Beide Ausfälle wären am ersten Tag sichtbar gewesen. Die erste Zeile ist
jetzt ein echter HTTP-Aufruf. Geeicht in drei Richtungen: gegen 0.9.11 grün,
gegen den Endpunkt aus 0.9.10 rot mit der Meldung *„leere Antwort — der
Endpunkt ist mit einem fatalen Fehler abgebrochen"*, und ohne erreichbaren
Server ein **Hinweis** statt eines Kreuzes — ein Webserver, der nur eine
Anfrage zugleich bearbeitet, kann sich nicht selbst aufrufen, und ein Kreuz,
das nichts bedeutet, ist schlimmer als keine Prüfung.

Dazu neu: findet der Endpunkt seine Bibliothek, stimmen Reiterleiste, Bereiche
und Positivliste überein, ist die Vorlage unabhängig vom Zwischenspeicher, ist
jedes Suchmuster in der Statuszeile eindeutig, darf die Oberfläche den Dienst
schalten, gibt es eine Zweitschrift.

### Neue Werte und Befehle

**Rückmeldung für alles Schaltbare.** Bis 0.9.10 gingen 15 Befehle hinaus und
nur 7 kamen als Wert zurück — Loxone konnte nicht erkennen, ob ein Befehl
gewirkt hat. Ergänzt sind Mindest-Ladestand, kleinster und höchster Ladestrom,
Preisschwelle, Battery Boost, eingestellte Phasen, Batteriemodus,
Residualleistung und Entladeregelung.

**Neue Lesewerte:** Zählerstände für Netzbezug, PV-Ertrag und je Ladepunkt;
Solarprognose für drei Tage; Preisvorschau mit Minimum, Maximum, Durchschnitt,
**Rang der laufenden Stunde** und günstigster Stunde; die Wartegründe
(PV-Überschuss, Phasenumschaltung) als Minutenwerte; Sitzungsdaten mit Energie,
Kosten, Preis je kWh und CO₂; Ladedauer, Restenergie, Ladestrom; Fahrzeugname
als Text; eine Fehlernummer und die letzte Fehlermeldung im Klartext.

**Neue Befehle:** Ladeplan setzen (Ziel-Ladestand und Vorlauf in Stunden — die
Zeit rechnet das Plugin, Loxone kann keine ISO-Zeit bilden) und löschen,
Ladeziel in kWh, Fahrzeug zuordnen und lösen, Netzladegrenze setzen und
abschalten.

**Und eine Warnung, die dazugehört:** die neuen Felder und Befehle stammen aus
der EVCC-Dokumentation und sind **an keiner Anlage gemessen**. Sie sind deshalb
überall als solche gekennzeichnet — im Reiter *Test* getrennt gezählt, in der
Befehlstabelle in der Spalte *Herkunft*, im Kommentar der erzeugten
Loxone-Vorlage und in der Antwort des Endpunkts, die bei einem unbekannten Pfad
ausdrücklich sagt, dass der Befehl aus der Dokumentation stammt. Kennt Ihre
EVCC-Fassung ein Feld nicht, bleibt es leer und der Reiter *Test* sagt welches
— das ist kein Fehler des Plugins.

Nicht behauptet wird die **Einheit der Solarprognose**: ob EVCC sie in Wh oder
kWh liefert, hat niemand gemessen. Die Felder heißen deshalb
`EVCC_PROGNOSE_HEUTE` ohne Einheit im Namen, und der Wert geht durch, wie er
kommt. Einmal gegen die EVCC-Oberfläche halten.

### Nach dem Update prüfen

```bash
php /opt/loxberry/bin/plugins/<ordner>/ev_abruf.php; echo "Rueckgabewert: $?"
```

Danach im Reiter *Test* die erste Zeile ansehen — sie beantwortet die Frage,
an der 0.9.10 gescheitert ist.

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

## Fassung 0.9.25 — der Stat-Zwischenspeicher
Die Protokollkappung (512 000 Byte) stand in
`webfrontend/htmlauth/ev_lib.php:134`. PHP merkt sich aber die Antworten von
`stat()`: innerhalb **eines** Prozesses sieht `filesize()` die erste Größe
und danach nie wieder eine neue — `file_put_contents(…, FILE_APPEND)` macht
den Eintrag nicht ungültig. Die Kappung fällt dann still aus.

Gemessen am 29.08.2026, 20 000 Zeilen im selben Prozess:

| | ohne `clearstatcache` | mit |
|---|---|---|
| PHP 7.4.33 | 1 220 000 Byte, **nicht gekappt** | 220 332 Byte, gekappt |
| PHP 8.4.24 | 220 332 Byte, gekappt | 220 332 Byte, gekappt |

Die beiden PHP-Fassungen verhalten sich also verschieden — und LoxBerry 3.x
fährt 7.4. Wer nur unter 8.4 misst, sieht den Fehler nie. Folgen hatte das
hier nicht: die Aufrufer sind kurzlebig, und ein **frischer** Prozess kappt
richtig. Eine Funktion darf aber nicht davon abhängen, wer sie wie oft ruft.

Abhilfe: `clearstatcache(true, …)` **vor** dem Tor; der zweite Parameter
beschränkt das Leeren auf diese eine Datei. Dasselbe Muster tragen Robonect,
Saugroboter, SignalBot, Octopus, Sprachsteuerung und WärmepumpeCloud schon
länger — es ist am 29.08.2026 im ganzen Bestand nachgezogen worden.

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
