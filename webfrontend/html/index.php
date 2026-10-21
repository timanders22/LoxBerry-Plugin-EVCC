<?php
/**
 * EVCC fuer LoxBerry - Endpunkt fuer den Miniserver
 *
 * Liegt im UNANGEMELDETEN Bereich, damit Loxone ihn ohne Zugangsdaten
 * erreicht, und ist deshalb durch ein Token geschuetzt:
 *
 *   /plugins/<Ordner>/index.php?token=<TOKEN>&aktion=<Befehl>
 *
 * Verglichen wird mit hash_equals - ein einfaches == liesse sich ueber die
 * Antwortzeit Zeichen fuer Zeichen erraten.
 *
 * Selbstpruefung:
 *   selftest=1  beantwortet nur die Tokenfrage, loest nichts aus
 *
 * Lesende Aktionen:
 *   status      eine Zeile EVCC;FELD=WERT;...   (Vorgabe)
 *   json        der Zustand als JSON, mit allen Feldern - auch den Texten
 *   roh         die unveraenderte Antwort von EVCC - fuer die Fehlersuche
 *   wert        ein einzelner Wert, blank ausgegeben (&feld=netz_kw)
 *   befehle     die Liste der schreibenden Befehle samt Herkunft
 *
 * Schreibende Aktionen (nur wenn im Reiter Einstellungen freigegeben):
 *   siehe ev_befehle() in ev_lib.php - dort stehen sie EINMAL, und Endpunkt,
 *   Oberflaeche und Loxone-Vorlage lesen alle von dort.
 *
 * Trockenlauf (seit dem Verbesserungsbau 30.09.2026, EVCC-b1):
 *   probe=1     an einem schreibenden Befehl: alles pruefen wie echt, Antwort
 *               mit PROBE=1, aber nichts an EVCC senden und keinen Merker
 *               (Bremse, Ladeplan) anlegen oder schreiben
 *
 * Schreiber-Wache (Energie-1 C1, Entscheidung Nr. 25):
 *   von=<kennung>  an einem schreibenden Befehl, optional (die Vorlage setzt
 *               von=loxone); gemerkt wird Kennung@Absender. Eine ungueltige
 *               Kennung: HTTP 400 GRUND=VON. Mehr als ein Schreiber im Fenster
 *               (ab Werk 15 min): Protokoll, Reiter Test, Antwort ;SCHREIBER=n -
 *               abgewiesen wird nichts. Nur mit "Fremde Schreiber abweisen" (ab
 *               Werk aus) bekommt ein nicht erlaubter Schreiber HTTP 409
 *               GRUND=FREMDSCHREIBER, und nichts geht an EVCC; Ruecknahmen nie.
 *               Merker nicht nutzbar: der Befehl geht trotzdem, ;WACHE=MERKER.
 *
 * Der Datenabruf gehoert NICHT hierher - der laeuft in bin/ev_abruf.php.
 * Dieser Endpunkt liest den zwischengespeicherten Zustand und reicht
 * Schaltbefehle weiter.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');

/* ==================================================================
 * DIE BIBLIOTHEK FINDEN - in BEIDEN Ablagen
 * ==================================================================
 *
 * Bis 0.9.10 stand hier schlicht
 *
 *     require_once dirname(__DIR__) . '/htmlauth/ev_lib.php';
 *
 * Das stimmt im entpackten Archiv, wo html/ und htmlauth/ nebeneinander
 * liegen. Auf einem installierten LoxBerry liegen sie in GETRENNTEN Baeumen:
 *
 *     <home>/webfrontend/html/plugins/<ordner>/       <- diese Datei
 *     <home>/webfrontend/htmlauth/plugins/<ordner>/   <- die Bibliothek
 *
 * dirname(__DIR__) ergab dort <home>/webfrontend/html/plugins, gesucht wurde
 * also .../html/plugins/htmlauth/ev_lib.php. Die gibt es nicht. require_once
 * brach fatal ab, und weil vier Zeilen darueber display_errors abgeschaltet
 * wird, kam beim Miniserver ein leerer HTTP 500 an - kein Text, kein
 * Protokolleintrag, nichts.
 *
 * Gemessen am 17.08.2026 im nachgebauten Aufbau: ALLE 18 Aufrufe (vier
 * lesende, vierzehn schreibende) endeten mit HTTP 500 und 0 Byte, auch die
 * Token-Abweisung. Nach der Korrektur antworten sie mit 200, 403 und 400.
 *
 * bin/ev_abruf.php hat diese Kandidatenliste seit 0.9.9 - die Nachbardatei
 * hat sie damals nicht bekommen. Dieselbe Klasse hatte Renault bis 2.0.6,
 * Heimkino bis 1.2.10 und Intercom bis 2.1.12.
 *
 * ACHTUNG: Diese Liste hat ein Gegenstueck in ev_endpunkt_kandidaten()
 * (ev_lib.php), das der Reiter Test anzeigt. Sie laesst sich nicht
 * zusammenlegen - hier wird sie gebraucht, BEVOR die Bibliothek geladen ist.
 * Belegt wird der Zustand deshalb nicht durch die Liste, sondern dadurch,
 * dass der Reiter Test diesen Endpunkt wirklich ueber HTTP aufruft.
 */
/* Seit 0.9.33 entscheidet der eigene Ablageort, welche Lage gilt: liegt diese
 * Datei unter .../plugins/<ordner>, ist sie installiert, sonst liegt sie in
 * einem ausgepackten Archiv. Bis 0.9.32 wurden drei Kandidaten der Reihe nach
 * probiert; aus einem Archiv unter / war der erste
 * /htmlauth/plugins/html/ev_lib.php ab der Laufwerkswurzel, und was dort lag,
 * lief als Bibliothek (in WSL gemessen, Pruefung-EVCC-0.9.33, Fall T2;
 * Bauart ZendureSolarFlow 0.9.26). */
if (basename(dirname(__DIR__)) === 'plugins') {
    $ev_kandidaten = array(dirname(dirname(dirname(__DIR__))) . '/htmlauth/plugins/' . basename(__DIR__) . '/ev_lib.php');
} else {
    $ev_kandidaten = array(dirname(__DIR__) . '/htmlauth/ev_lib.php');
}
$ev_lib = '';
foreach ($ev_kandidaten as $ev_k) {
    if (is_file($ev_k)) { $ev_lib = $ev_k; break; }
}
if ($ev_lib === '') {
    // Reden, nicht schweigen. Ein leerer 500 hat dieses Plugin eine ganze
    // Fassung gekostet.
    http_response_code(500);
    echo "EVCC;OK=0;GRUND=BIBLIOTHEK_FEHLT\n";
    echo "ev_lib.php nicht gefunden. Erwartet unter htmlauth/plugins/"
       . basename(__DIR__) . "/ - gesucht wurde in:\n";
    foreach ($ev_kandidaten as $ev_k) { echo '  ' . $ev_k . "\n"; }
    exit;
}
require_once $ev_lib;

/**
 * Noch gar keine Daten? Dann HTTP 503 mit Grund (C5, seit 0.9.34; Regeln/07
 * "Das gilt auch vor dem ersten Abruf"). Bis 0.9.33 antwortete der Endpunkt
 * vor dem ersten gelungenen Abruf mit 200 und lauter Nullen, die in Loxone
 * als Messwerte ankamen (Pruefbericht code, Befund 5). Mit einem alten Stand
 * bleibt es bei 200, OK=0 und den alten Werten (Entscheidung 8).
 */
function ev_ohne_daten_503($st)
{
    if (!empty($st['stand'])) { return; }
    $nr = isset($st['fehlernr']) ? (int) $st['fehlernr'] : 9;
    ev_ende(503, 'EVCC;OK=0;GRUND=NOCH_KEINE_DATEN;FEHLER_NR=' . $nr);
}

/** Die Adresse des Anrufers, auf die zulaessigen Zeichen beschraenkt. */
function ev_anrufer()
{
    return isset($_SERVER['REMOTE_ADDR'])
        ? preg_replace('/[^0-9a-fA-F:.]/', '', (string) $_SERVER['REMOTE_ADDR'])
        : '?';
}

/* $fertig (seit 0.9.45): die Zeile kommt fertig aus ev_befehl_ausfuehren() (Zusatz der Wache und PROBE=1 stehen
 * schon darin) - dann nur noch Code, Protokoll und Ausgabe wie bisher. */
function ev_ende($code, $text, $fertig = false)
{
    http_response_code($code);
    // Die Antwort ist EINE Zeile - Loxone liest sie mit einer
    // Befehlserkennung. Steht in einem Grund ein Umbruch (curl-Fehlertexte
    // koennen mehrzeilig sein, ebenso Antworten von EVCC), zerfaellt die
    // Zeile, und Loxone sieht nur noch den ersten Teil.
    //
    // Die mehrzeiligen Ausgaben (json, roh, befehle) laufen nicht ueber diese
    // Funktion, sondern schreiben direkt. Was hier ankommt, ist immer eine
    // Statuszeile - auch die Liste der erlaubten Aktionen, die dadurch in
    // einer Zeile steht statt in zweien. Lesbar bleibt sie.
    $text = str_replace(array("\r\n", "\r", "\n"), ' ', (string) $text);
    /* Schreiber-Wache (Energie-1 C1): jede Antwort NACH der Wache traegt ihren
     * Zusatz (;SCHREIBER=n, ;WACHE=MERKER) - auch UNVERAENDERT, 429 und 502. Mit
     * einem Schreiber ist er leer, die Zeile also wie bisher. */
    if (!$fertig && isset($GLOBALS['ev_wz']) && is_string($GLOBALS['ev_wz']) && $GLOBALS['ev_wz'] !== '') {
        $text = rtrim($text) . $GLOBALS['ev_wz'];
    }
    /* Ein Trockenlauf (EVCC-b1) sagt es in JEDER Antwort, auch in einer
     * Abweisung - gleich hinter OK, damit es nicht hinter einem Grund steht. */
    if (!$fertig && !empty($GLOBALS['ev_probe'])) {
        $text = preg_replace('/^EVCC;OK=([01])/', 'EVCC;OK=$1;PROBE=1', $text, 1);
    }
    /* Jede Abweisung hinterlaesst eine Zeile.
     *
     * Bis 0.9.26 protokollierte dieser Endpunkt nur den abgesetzten Befehl.
     * Gemessen: fuenf Abweisungswege (kein Token, falsches Token, Token als
     * Feld, unbekannte Aktion, unzulaessiger Wert) - null Protokollzeilen.
     * Damit war "der Miniserver ruft nicht an" von "er ruft an und wird
     * abgewiesen" nicht zu unterscheiden; ein Virtueller Ausgang wertet die
     * Antwort nicht aus und kann sich nicht beschweren.
     *
     * Die Zugangsmarke steht NIE darin - der abgewiesene Text ist die
     * Antwort des Plugins, nicht die Anfrage. Gebremst ueber den Merker,
     * sonst schriebe ein Miniserver im 30-Sekunden-Takt zwei Zeilen die
     * Minute. */
    if ($code >= 400) {
        ev_log_wenn_neu('endpunkt_abweisung', 'Anfrage von ' . ev_anrufer()
            . ' mit HTTP ' . (int) $code . ' beantwortet: ' . substr($text, 0, 120));
    }
    echo rtrim($text) . "\n";
    exit;
}

/* Die Schreiber-Wache (ev_wache_anwenden()) steht seit 0.9.45 in ev_lib.php - sie gehoert zur
 * Befehlsverarbeitung ev_befehl_ausfuehren(), die auch die Befehlsbruecke ruft (Gruppe 4 / MQTT). */

/* VOR der Tokenpruefung wird nichts angelegt.
 *
 * Bis 0.9.10 rief dieser Endpunkt ev_config() ohne Einschraenkung auf.
 * Gemessen mit leerem Konfigurationsordner: ein einziger Aufruf OHNE Token -
 * beantwortet mit 403 - hinterliess .token.lock, evcc.json (mit frisch
 * erzeugtem Token) und die Zweitschrift. Wer sich nicht ausweisen kann, legt
 * nichts an; nachgemessen am 04.09.2026, der Konfigordner blieb leer.
 *
 * Genau so weit reicht die Zusage. HINTER der Tokenpruefung darf die
 * Selbstheilung greifen - ev_state() und ev_felder() rufen ev_config() dann
 * ohne Einschraenkung, und eine fehlende Konfiguration wird aus der
 * Zweitschrift zurueckgeschrieben. Das ist der Hausstandard, und es steht
 * hier, damit der Satz oben nicht mehr verspricht, als er haelt. */
$cfg = ev_config(false);

/* ---------------- Selbstpruefung ----------------
 *
 * ?selftest=1&token=<TOKEN> beantwortet die Tokenfrage, OHNE etwas
 * auszuloesen: kein Geraetekontakt, kein Schreibzugriff, kein Zwischenspeicher
 * wird angefasst. So kann der Miniserver pruefen, ob seine Adressen noch
 * stimmen, ohne einen Ladepunkt zu beruehren.
 *
 * Die drei Antworten sind der Hausstandard und stehen fest. Sie benutzen
 * bewusst 403 auch dort, wo der normale Weg 503 sagt: der Selbsttest hat
 * seinen eigenen Vertrag, an dem fremde Prueflaeufe haengen, und der normale
 * Weg unterscheidet weiterhin "kein Token eingerichtet" (503) von "falsches
 * Token" (403). */
$ev_selftest = isset($_GET['selftest']) && is_string($_GET['selftest'])
               && (string) $_GET['selftest'] === '1';

/* ---------------- Token ---------------- */
$soll = (string) $cfg['aktionstoken'];
/* is_string() zuerst: ?token[]=x macht aus dem Parameter ein Feld, und
 * (string) auf ein Feld ist unter PHP 8 eine Warnung. */
$ist  = (isset($_GET['token']) && is_string($_GET['token'])) ? (string) $_GET['token'] : '';
if ($soll === '') {
    if ($ev_selftest) { ev_ende(403, 'SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET'); }
    // Kein Token in der Konfiguration: das Plugin wurde noch nie in der
    // Oberflaeche geoeffnet, oder das Betriebssystem liefert keinen sicheren
    // Zufall. Das ist etwas anderes als ein falsches Token, und der Nutzer
    // soll es unterscheiden koennen - preisgegeben wird dabei nichts.
    ev_ende(503, 'EVCC;OK=0;GRUND=KEIN_TOKEN_EINGERICHTET');
}
if (!hash_equals($soll, $ist)) {
    // Bewusst keine Auskunft darueber, ob das Token zu kurz, zu lang oder
    // schlicht falsch war.
    if ($ev_selftest) { ev_ende(403, 'SELFTEST;OK=0;ERR=TOKEN'); }
    ev_ende(403, 'EVCC;OK=0;GRUND=TOKEN');
}
if ($ev_selftest) {
    // Hier endet die Selbstpruefung. Nichts darunter laeuft mehr an.
    ev_ende(200, 'SELFTEST;OK=1;TOKEN=OK');
}
/* Ein Anruf ist angekommen und hat sich ausgewiesen. Eine Zeile, gebremst -
 * sie beantwortet spaeter die Frage, ob der Miniserver ueberhaupt anruft. */
ev_log_wenn_neu('endpunkt_angenommen', 'Anfrage von ' . ev_anrufer() . ' angenommen.');

/* ---------------- Trockenlauf (EVCC-b1, Verbesserungsbau 30.09.2026) ----------------
 *
 * &probe=1 an einem schreibenden Befehl prueft alles, was der echte Befehl
 * prueft - das Token (oben), die Freigabe, die Weissliste, den Ladepunkt, den
 * Wert und die Bremse - und antwortet wie echt, mit PROBE=1. Gesendet wird
 * nichts; der Merker der Bremse und der des Ladeplans werden nur gelesen, nie
 * angelegt oder geschrieben. So laesst sich eine Adresse in Loxone Config
 * pruefen, ohne einen Ladepunkt zu beruehren.
 * Gelesen erst HINTER der Tokenpruefung: wer sich nicht ausweist, bekommt
 * dieselbe Antwort wie ohne probe. Nur der Wert 1 gilt; jeder andere wird
 * abgewiesen, nicht als "kein Trockenlauf" gelesen - sonst ginge ein
 * vertippter Trockenlauf als echter Befehl hinaus. */
$ev_probe = false;
if (isset($_GET['probe'])) {
    if (!is_string($_GET['probe']) || (string) $_GET['probe'] !== '1') {
        ev_ende(400, 'EVCC;OK=0;GRUND=PROBE_UNGUELTIG;ERLAUBT=1');
    }
    $ev_probe = true;
}

/* ---------------- Aktion gegen die Weissliste ---------------- */
$lesend = array('status', 'json', 'roh', 'wert', 'befehle');
$befehle = ev_befehle();
$schreibend = array_keys($befehle);
$aktion = (isset($_GET['aktion']) && is_string($_GET['aktion']))
          ? (string) $_GET['aktion'] : 'status';
if (!in_array($aktion, array_merge($lesend, $schreibend), true)) {
    ev_ende(400, "EVCC;OK=0;GRUND=UNBEKANNTE_AKTION\n"
                 . 'Erlaubt: ' . implode(', ', array_merge($lesend, $schreibend)));
}
/* Ein lesender Aufruf sendet ohnehin nichts; probe=1 dort ist ein
 * Missverstaendnis und wird gesagt, nicht still uebergangen (EVCC-b1). */
if ($ev_probe && in_array($aktion, $lesend, true)) {
    ev_ende(400, 'EVCC;OK=0;GRUND=PROBE_NUR_FUER_BEFEHLE;AKTION=' . $aktion);
}

/* Wie alt darf der zwischengespeicherte Zustand sein?
 *
 * Bis 0.9.10 galt takt/2 - bei Takt 15 also 7 Sekunden, waehrend die erzeugte
 * Vorlage alle 30 Sekunden fragt. Damit war der Stand bei JEDER Abfrage
 * abgelaufen und der Endpunkt stellte selbst eine HTTP-Anfrage mit bis zu 8 s
 * Zeitgrenze - obwohl in seinem eigenen Kopf steht, er lese nur den
 * Zwischenspeicher. Der Abrufdienst fuellt ihn jede Taktlaenge; zweieinhalb
 * Takte Nachsicht heisst: im Normalbetrieb kein einziger eigener Abruf, und
 * bei stehendem Dienst trotzdem nach kurzer Zeit ein frischer Versuch. */
/* Seit 0.9.34 (C6, Entscheidung 4): OK=0 ab 3 x Takt. Die Nachsicht des
 * Endpunkts liegt deshalb darunter - bei 2 x Takt (mindestens 10 s) fragt er
 * selbst nach. Bis 0.9.33 stand hier max(30, 2,5 x Takt): bei Takt 5 lieferte
 * er einen 29 s alten Stand als OK=1 (Pruefbericht code, Befund 6). */
$ev_hoechstalter = max(10, 2 * (int) $cfg['takt']);

/* ---------------- Lesende Aktionen ---------------- */

/* json_encode() liefert bei ungueltigem UTF-8 false, und 'echo false' ist
 * eine leere Antwort mit HTTP 200 - genau das Bild, das dieses Plugin an
 * mehreren Stellen als seinen teuersten Fehler fuehrt. Gemessen an 0.9.26:
 * eine lange EVCC-Fehlermeldung mit einem Umlaut auf der Kappungsgrenze
 * ergab 200 und NULL Byte. Die Kappung ist seit 0.9.27 zeichensicher; die
 * Wache hier bleibt trotzdem - sie kostet nichts und faengt jede andere
 * Quelle ungueltiger Zeichen ab. */
if ($aktion === 'roh') {
    $st = ev_state(false, $ev_hoechstalter);
    $ev_js = json_encode($st['roh'],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($ev_js === false) {
        ev_ende(500, 'EVCC;OK=0;GRUND=JSON_UNLESBAR;INFO=' . json_last_error_msg());
    }
    header('Content-Type: application/json; charset=utf-8');
    echo $ev_js;
    exit;
}

if ($aktion === 'json') {
    $st = ev_state(false, $ev_hoechstalter);
    $werte = ev_werte($st);
    $felder = ev_felder();
    $flach = array();
    foreach ($werte as $k => $d) { $flach[$k] = $d['wert']; }
    // Welche Felder sich nicht aufloesen liessen, gehoert dazu - sonst sieht
    // eine 0 aus wie eine Messung. Und welche davon in 0.9.11 aus der
    // Dokumentation kamen, ebenfalls.
    $ohne = array();
    $ungemessen = array();
    foreach ($felder as $k => $d) {
        if (!empty($d['pfade']) && isset($werte[$k]) && $werte[$k]['pfad'] === '') { $ohne[] = $k; }
        if ($d['quelle'] === 'doku') { $ungemessen[] = $k; }
    }
    $ev_js = json_encode(array('ok' => $st['ok'], 'stand' => $st['stand'],
                           'fehler' => $st['fehler'],
                           'fehler_nr' => isset($st['fehlernr']) ? (int) $st['fehlernr'] : 0,
                           'nicht_gefunden' => $ohne,
                           'aus_der_dokumentation' => $ungemessen,
                           'werte' => $flach),
                     JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($ev_js === false) {
        ev_ende(500, 'EVCC;OK=0;GRUND=JSON_UNLESBAR;INFO=' . json_last_error_msg());
    }
    header('Content-Type: application/json; charset=utf-8');
    echo $ev_js;
    exit;
}

if ($aktion === 'befehle') {
    // Damit man ohne Oberflaeche nachsehen kann, was es gibt - und was davon
    // gemessen ist.
    $aus = array();
    foreach ($befehle as $n => $b) {
        $aus[$n] = array('ebene' => $b['ebene'], 'methode' => $b['methode'],
                         'pfad' => $b['pfad'], 'pruefung' => $b['pruef'],
                         'min' => isset($b['min']) ? $b['min'] : null,
                         'max' => isset($b['max']) ? $b['max'] : null,
                         'quelle' => $b['quelle']);
    }
    $ev_js = json_encode($aus, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($ev_js === false) {
        ev_ende(500, 'EVCC;OK=0;GRUND=JSON_UNLESBAR;INFO=' . json_last_error_msg());
    }
    header('Content-Type: application/json; charset=utf-8');
    echo $ev_js;
    exit;
}

if ($aktion === 'wert') {
    $feld = (isset($_GET['feld']) && is_string($_GET['feld']))
            ? preg_replace('/[^a-z0-9_]/', '', (string) $_GET['feld']) : '';
    $st = ev_state(false, $ev_hoechstalter);
    ev_ohne_daten_503($st);
    $werte = ev_werte($st);
    if ($feld === '' || !isset($werte[$feld])) {
        ev_ende(400, '-');
    }
    $ev_felder = ev_felder();
    $ev_w = $werte[$feld]['wert'];
    if (!empty($werte[$feld]['ohne']) && isset($ev_felder[$feld])
        && ($ev_ow = ev_ohne_wert($feld, $ev_felder[$feld], 'http')) !== null) {
        $ev_w = $ev_ow;   // keine Aussage (C5, Nr. 5/8; seit 0.9.45 auch -999, Entwurf ev 2.3)
    }
    echo $ev_w . "\n";
    exit;
}

if ($aktion === 'status') {
    $st = ev_state(false, $ev_hoechstalter);
    ev_ohne_daten_503($st);
    echo ev_zeile(ev_werte($st));
    exit;
}

/* ================= Schreibende Aktionen ================= */

/* Gruppe 4 / MQTT (0.9.45; Entwurf ev 3.2, Bauvorschrift Gruppe 4): die Befehlsverarbeitung steht in
 * ev_befehl_ausfuehren() (ev_lib.php) - dieselbe Funktion ruft die Befehlsbruecke (bin/befehle_zuordnung.php,
 * Quelle 'mqtt'). Haken "Steuerung aus Loxone zulassen", von=, lp=, Wert, Ladeplan, Schreiber-Wache,
 * Befehlsbremse samt Nachreichen, GERUNDET, Trockenlauf und Senden wie bis 0.9.44 hier; Antwort, Code und
 * Protokoll unveraendert (Wirkungstest Quelle gegen Bau). Das Token prueft weiter nur dieser Endpunkt. */
list($ev_bc, $ev_bz) = ev_befehl_ausfuehren($aktion, array(
    'lp' => isset($_GET['lp']) ? $_GET['lp'] : null,
    'wert' => isset($_GET['wert']) ? $_GET['wert'] : null,
    'stunden' => isset($_GET['stunden']) ? $_GET['stunden'] : null,
    'von' => isset($_GET['von']) ? $_GET['von'] : null,
    'ip' => ev_wache_absender(), 'probe' => $ev_probe, 'anrufer' => ev_anrufer()), 'http');
ev_ende($ev_bc, $ev_bz, true);
