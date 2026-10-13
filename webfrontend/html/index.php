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

function ev_ende($code, $text)
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
    if (isset($GLOBALS['ev_wz']) && is_string($GLOBALS['ev_wz']) && $GLOBALS['ev_wz'] !== '') {
        $text = rtrim($text) . $GLOBALS['ev_wz'];
    }
    /* Ein Trockenlauf (EVCC-b1) sagt es in JEDER Antwort, auch in einer
     * Abweisung - gleich hinter OK, damit es nicht hinter einem Grund steht. */
    if (!empty($GLOBALS['ev_probe'])) {
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

/**
 * Schreiber-Wache (Energie-1 C1, Entscheidung Nr. 25; Kopf der Funktionen in
 * ev_lib.php): merken und melden, und nur mit "Fremde Schreiber abweisen" einen
 * Befehl eines nicht erlaubten Schreibers mit 409 abweisen, bevor die Bremse ihn
 * sieht und bevor etwas an EVCC geht. Ruecknahmen nie (ev_wache_ruecknahme()).
 * Der Trockenlauf merkt sich nichts, prueft die Sperre aber wie echt.
 * Rueckgabe: Zusatz fuer die Antwortzeile - ;SCHREIBER=n ab zwei Schreibern im
 * Fenster, ;WACHE=MERKER, wenn das Merken nicht ging (der Befehl geht trotzdem).
 */
function ev_wache_anwenden($aktion, $klar, $von, $probe)
{
    $w = ev_wache_einstellungen(ev_config(false));
    $ip = ev_wache_absender();
    list($aktiv, $erlaubt, $fehler) = ev_wache_sperre_urteil($w, $von, $ip);
    if ($fehler !== '') {
        ev_log_wenn_neu('wache_liste', 'Fremde Schreiber abweisen ist eingeschaltet, aber die Liste der '
            . 'erlaubten Schreiber ist leer oder unbrauchbar - die Sperre wirkt NICHT, bis die Liste im Reiter '
            . 'Einstellungen berichtigt ist.');
    } elseif ($w['wache_sperren_ein'] === 1 && is_file(ev_tmpdir() . '/letzte_wache_liste.txt')) {
        ev_log_wenn_neu('wache_liste', 'Die Liste der erlaubten Schreiber ist brauchbar, die Sperre wirkt.');
    }
    $abweisen = $aktiv && !$erlaubt && !ev_wache_ruecknahme($aktion, $klar);
    $zusatz = '';
    if (!$probe && $w['wache_ein'] === 1) {
        $m = ev_wache_merken($von, $ip, $aktion, $abweisen, $w);
        if ($m['anzahl'] > 1) {
            $zusatz .= ';SCHREIBER=' . (int) $m['anzahl'];
        }
        if (!$m['merker']) {
            $zusatz .= ';WACHE=MERKER';
        }
    }
    if ($abweisen) {
        ev_ende(409, 'EVCC;OK=0;AKTION=' . $aktion . ';GRUND=FREMDSCHREIBER');
    }
    return $zusatz;
}

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
    if (!empty($werte[$feld]['ohne']) && isset($ev_felder[$feld]) && ev_ohne_minus1($feld, $ev_felder[$feld])) {
        $ev_w = -1;       // keine Aussage (C5, Nr. 5/8)
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

if (empty($cfg['steuerung_ein'])) {
    ev_ende(403, "EVCC;OK=0;GRUND=STEUERUNG_AUS\n"
                 . 'Schreibende Befehle sind gesperrt. Reiter Einstellungen, '
                 . 'Haken "Steuerung aus Loxone zulassen".');
}

$b = $befehle[$aktion];

/* Schreiber-Wache (Energie-1 C1): &von= lesen. Fehlt es: '' (ohne Kennung). Eine
 * Kennung, die nicht ins Muster passt (1..32 aus A-Z a-z 0-9 _ -), wird abgewiesen
 * wie ein falscher Wert - abweisen statt zurechtbiegen (Nr. 19); ein Tippfehler
 * faellt beim Einrichten auf. Eine Adresse OHNE von geht immer. */
$ev_von = '';
if (isset($_GET['von'])) {
    $ev_von = is_string($_GET['von']) ? (string) $_GET['von'] : '';
    if (!ev_wache_kennung_gueltig($ev_von)) {
        ev_ende(400, 'EVCC;OK=0;AKTION=' . $aktion . ';GRUND=VON;ERLAUBT=A-Z,a-z,0-9,_,-;LAENGE=1..32');
    }
}

/* Ladepunkt pruefen - aber nur, wo einer gebraucht wird.
 *
 * Streng seit 0.9.34 (C9): lp=1abc wurde bis 0.9.33 per (int) zu Ladepunkt 1
 * und der Befehl ging hinaus (Pruefbericht code, Befund 9). Jetzt nur eine
 * Zahl aus Ziffern; ohne Angabe bleibt es bei Ladepunkt 1. */
$lp = 1;
if (isset($_GET['lp'])) {
    $ev_lp_roh = is_string($_GET['lp']) ? (string) $_GET['lp'] : '';
    if (!preg_match('/^[0-9]{1,2}\z/', $ev_lp_roh)) {
        ev_ende(400, 'EVCC;OK=0;GRUND=LADEPUNKT_UNGUELTIG;ERLAUBT=1..' . EV_LADEPUNKTE);
    }
    $lp = (int) $ev_lp_roh;
}
if ($b['ebene'] === 'lp' && ($lp < 1 || $lp > EV_LADEPUNKTE)) {
    ev_ende(400, 'EVCC;OK=0;GRUND=LADEPUNKT_UNGUELTIG;ERLAUBT=1..' . EV_LADEPUNKTE);
}

/* Wert pruefen. Die Regel steht in ev_befehle(), nicht hier - so kann sie
 * nicht zwischen Endpunkt, Oberflaeche und Vorlage auseinanderlaufen. */
$wert = (isset($_GET['wert']) && is_string($_GET['wert']))
        ? trim((string) $_GET['wert']) : '';
if ($b['pruef'] !== 'ohne' && $wert === '') {
    ev_ende(400, 'EVCC;OK=0;GRUND=WERT_FEHLT');
}
list($ok, $klar) = ev_befehl_pruefen($b, $wert);
if (!$ok) {
    ev_ende(400, 'EVCC;OK=0;AKTION=' . $aktion . ';GRUND=' . $klar);
}

/* Der Ladeplan braucht zusaetzlich eine Zeit. Loxone kann keine ISO-Zeit
 * bilden, deshalb wird sie als VORLAUF IN STUNDEN uebergeben und hier
 * gerechnet - das ist die Zahl, die ein Loxone-Baustein ohnehin hat. */
$zeit = '';
$std = '';
if ($b['pruef'] === 'plan') {
        $std = (isset($_GET['stunden']) && is_string($_GET['stunden']))
           ? str_replace(',', '.', (string) $_GET['stunden']) : '';
    if (!is_numeric($std)) {
        ev_ende(400, 'EVCC;OK=0;AKTION=' . $aktion . ';GRUND=STUNDEN_FEHLT;ERLAUBT=0.25..168');
    }
    $std = (float) $std;
    if ($std < 0.25 || $std > 168) {
        ev_ende(400, 'EVCC;OK=0;AKTION=' . $aktion . ';GRUND=BEREICH;FELD=stunden;ERLAUBT=0.25..168');
    }
    $zeit = gmdate('Y-m-d\TH:i:s\Z', time() + (int) round($std * 3600));
}

/* ---------------- Schreiber-Wache (Energie-1 C1) ----------------
 *
 * VOR der Bremse: auch ein Befehl, den die Bremse als unveraendert beantwortet,
 * kommt von einem Schreiber. Die Bremse selbst bleibt, wie sie war. */
$ev_wz = ev_wache_anwenden($aktion, $klar, $ev_von, $ev_probe);

/* ---------------- Befehlsbremse (C3, seit 0.9.34) ----------------
 *
 * Regeln/03: jeder Ausloeser, den eine fremde Anlage bedient, braucht eine
 * Bremse im Plugin. Bis 0.9.33 schaltete ein flatternder Loxone-Ausgang die
 * Phasenumschaltung im Takt der Anfragen - 20 POST an EVCC in unter einer
 * Sekunde (Pruefbericht code, Befund 3). Jetzt je Befehl und Ladepunkt:
 *   - derselbe Wert innerhalb von 60 s geht nicht erneut hinaus
 *     (200, UNVERAENDERT=1);
 *   - ein anderer Wert hoechstens alle 10 s (429, GRUND=BREMSE, WARTEN_S);
 *   - ist die Merkerdatei nicht zu oeffnen, faellt die Bremse geschlossen
 *     aus: 503 und eine Protokollzeile.
 * Seit 0.9.43 (Hausherr 13.10.2026) wird ein mit 429 abgewiesener Wert nicht
 * mehr verworfen: der Merker haelt ihn am Eintrag des Befehls fest (Antwort
 * ;NACHREICHEN=1), und der Abrufdienst reicht ihn nach Ablauf der Sperre nach -
 * je Befehl und Ladepunkt nur den letzten (ev_bremse_urteil(),
 * ev_bremse_nachreichen() in ev_lib.php). Grenzen dort als Konstanten.
 * Die Sperre bleibt bis zum Ende des Befehls gehalten; zwei gleichzeitige
 * Befehle laufen damit nacheinander, nicht ueber einander. */
$ev_bremse = ev_tmpdir() . '/befehlsbremse.json';
/* Im Trockenlauf (EVCC-b1) wird der Merker nur gelesen: fehlt er, ist nichts
 * gebremst, und er wird NICHT angelegt ($ev_bfh = null). Was den echten
 * Befehl mit 503 abweisen wuerde - an seiner Stelle liegt keine Datei, oder
 * er liesse sich nicht anlegen -, weist auch den Trockenlauf ab. */
if ($ev_probe) {
    clearstatcache(true, $ev_bremse);
    if (!file_exists($ev_bremse)) {
        $ev_bfh = is_writable(dirname($ev_bremse)) ? null : false;
    } elseif (!is_file($ev_bremse)) {
        $ev_bfh = false;
    } else {
        $ev_bfh = @fopen($ev_bremse, 'r');
    }
    $ev_bsperre = LOCK_SH;
} else {
    $ev_bfh = @fopen($ev_bremse, 'c+');
    $ev_bsperre = LOCK_EX;
}
if ($ev_bfh === false || ($ev_bfh !== null && !@flock($ev_bfh, $ev_bsperre))) {
    ev_log_wenn_neu('bremse', 'Die Merkerdatei der Befehlsbremse (' . $ev_bremse . ') laesst sich '
        . 'nicht oeffnen - schreibende Befehle werden mit 503 abgewiesen, bis das behoben ist. '
        . 'Pruefen: Platz und Eigentuemer (loxberry).');
    ev_ende(503, 'EVCC;OK=0;AKTION=' . $aktion . ';GRUND=BREMSE_MERKER');
}
$ev_bm = ($ev_bfh === null) ? array() : json_decode((string) stream_get_contents($ev_bfh), true);
if (!is_array($ev_bm)) { $ev_bm = array(); }
$ev_bschl = $aktion . '|' . ($b['ebene'] === 'lp' ? $lp : 0);
$ev_bwert = $klar . ($b['pruef'] === 'plan' ? '|' . $std : '');
/* Urteil und Nachreichen seit 0.9.43 in ev_bremse_urteil(): gleiche Grenzen und
 * Vergleiche wie bis 0.9.42 hier. Neu: im Fall 429 merkt sich der Merker den
 * Wert zum Nachreichen; UNVERAENDERT und ein durchgehender Wert streichen einen
 * gemerkten. Der Trockenlauf merkt sich nichts und schreibt nichts. */
$ev_bm_vorher = $ev_bm;
list($ev_burteil, $ev_bwarten, $ev_bgemerkt) = ev_bremse_urteil($ev_bm, $ev_bschl, $ev_bwert, time(),
    $ev_probe ? null : array('a' => $aktion, 'lp' => $lp, 'k' => $klar, 's' => $std, 'von' => $ev_von));
if (!$ev_probe && $ev_bm !== $ev_bm_vorher && !ev_bremse_schreiben($ev_bfh, $ev_bm)) {
    ev_log_wenn_neu('bremse', 'Die Merkerdatei der Befehlsbremse (' . $ev_bremse . ') liess sich '
        . 'nicht schreiben - ein abgewiesener Wert wird nicht nachgereicht.');
    $ev_bgemerkt = false;
}
if ($ev_burteil === 'unveraendert') {
    ev_ende(200, 'EVCC;OK=1;AKTION=' . $aktion . ';WERT=' . $klar . ';UNVERAENDERT=1');
}
if ($ev_burteil === 'gebremst') {
    ev_ende(429, 'EVCC;OK=0;AKTION=' . $aktion . ';GRUND=BREMSE;WARTEN_S=' . $ev_bwarten
        . ($ev_bgemerkt ? ';NACHREICHEN=1' : ''));
}

/* ---------------- Ladeplan aus zwei Ausgaengen (C11, seit 0.9.34) ----------------
 *
 * In Loxone ist <v.N> derselbe Analogwert mit N Nachkommastellen; der alte
 * Befehl 'plansoc' mit wert=<v.0>&stunden=<v.1> haette das Ziel 80 % in 80 h
 * gesetzt (Pruefbericht oberflaeche, Befund 13). Ziel und Vorlauf kommen jetzt
 * je aus einem eigenen Ausgang; das Plugin merkt sich beide je Ladepunkt und
 * sendet den Plan, sobald beide bekannt sind - mit dem Vorlauf ab JETZT. */
if ($b['pruef'] === 'planziel' || $b['pruef'] === 'planstunden') {
    $ev_plan = ev_tmpdir() . '/ladeplan.json';
    $ev_teil = ($b['pruef'] === 'planziel') ? 'ziel' : 'stunden';
    if ($ev_probe) {
        /* Trockenlauf (EVCC-b1): der Merker wird nur gelesen; der neue Teil
         * zaehlt nur fuer diese Antwort. */
        clearstatcache(true, $ev_plan);
        if (file_exists($ev_plan) && !is_file($ev_plan)) {
            ev_ende(503, 'EVCC;OK=0;AKTION=' . $aktion . ';GRUND=PLAN_MERKER');
        }
        $ev_pm = is_file($ev_plan) ? json_decode((string) @file_get_contents($ev_plan), true) : array();
        if (!is_array($ev_pm)) { $ev_pm = array(); }
        $ev_pm[(string) $lp][$ev_teil] = $klar;
    } else {
        $ev_pfh = @fopen($ev_plan, 'c+');
        if ($ev_pfh === false || !@flock($ev_pfh, LOCK_EX)) {
            ev_log_wenn_neu('ladeplan', 'Die Merkerdatei des Ladeplans (' . $ev_plan . ') laesst sich '
                . 'nicht oeffnen - der Ladeplan wird nicht gesetzt.');
            ev_ende(503, 'EVCC;OK=0;AKTION=' . $aktion . ';GRUND=PLAN_MERKER');
        }
        $ev_pm = json_decode((string) stream_get_contents($ev_pfh), true);
        if (!is_array($ev_pm)) { $ev_pm = array(); }
        $ev_pm[(string) $lp][$ev_teil] = $klar;
        ftruncate($ev_pfh, 0);
        rewind($ev_pfh);
        fwrite($ev_pfh, (string) json_encode($ev_pm));
        fflush($ev_pfh);
        flock($ev_pfh, LOCK_UN);
        fclose($ev_pfh);
    }
    $ev_z = isset($ev_pm[(string) $lp]['ziel']) ? (string) $ev_pm[(string) $lp]['ziel'] : '';
    $ev_s = isset($ev_pm[(string) $lp]['stunden']) ? (string) $ev_pm[(string) $lp]['stunden'] : '';
    if ($ev_z === '' || $ev_s === '') {
        /* Im Trockenlauf ist nichts gemerkt: GEMERKT=0. */
        ev_ende(200, 'EVCC;OK=1;AKTION=' . $aktion . ';WERT=' . $klar . ';GEMERKT=' . ($ev_probe ? '0' : '1')
            . ';FEHLT=' . ($ev_z === '' ? 'plansoc_ziel' : 'plansoc_stunden'));
    }
    $zeit = gmdate('Y-m-d\\TH:i:s\\Z', time() + (int) round((float) $ev_s * 3600));
    $pfad = str_replace(array('%LP%', '%WERT%', '%ZEIT%'),
        array((string) $lp, rawurlencode($ev_z), rawurlencode($zeit)), $b['pfad']);
} else {
    $pfad = str_replace(
        array('%LP%', '%WERT%', '%ZEIT%'),
        array((string) $lp, rawurlencode($klar), rawurlencode($zeit)),
        $b['pfad']);
}

/* Wurde die Eingabe gerundet? Dann wird es GESAGT.
 *
 * Loxone sendet aus einem Analogbaustein zwangslaeufig Kommazahlen. Ein
 * ganzzahliger Befehl muss sie runden - bis 0.9.28 tat er das STILL.
 * Gemessen am 10.09.2026: limitsoc=50.5 ging als
 * /api/loadpoints/1/limitsoc/51 hinaus, in der Antwort stand nur WERT=51.
 * Abgewiesen wird hier mit Absicht nicht (das machte den Befehl fuer Loxone
 * unbenutzbar), aber CLAUDE.md Abschnitt 4 verlangt, dass nichts still
 * zurechtgebogen wird.
 *
 * Nur fuer die Befehlsarten, bei denen $klar wirklich dieselbe Zahl ist:
 * bei 'modus' und 'schalter' ist $klar eine Uebersetzung ('3' -> 'pv'), da
 * waere ein GERUNDET= irrefuehrend.
 *
 * Seit dem Verbesserungsbau 30.09.2026 steht das VOR dem Senden: die
 * Antwortzeile ist fuer den echten Befehl und den Trockenlauf dieselbe
 * (EVCC-b1), der Trockenlauf traegt nur PROBE=1 dazu. */
$gerundet = '';
if (in_array($b['pruef'], array('ganz', 'plan', 'liste', 'planziel'), true)
    && is_numeric(str_replace(',', '.', $wert))
    && (string) $klar !== (string) $wert) {
    $gerundet = ';GERUNDET=' . str_replace(';', ',', $wert);
}
$ev_pz = $ev_probe ? 'PROBE=1;' : '';
if ($b['pruef'] === 'planziel' || $b['pruef'] === 'planstunden') {
    // Der Ladeplan nennt beide Teile, aus denen er entstand (C11).
    $ev_antwort = sprintf("EVCC;OK=1;%sAKTION=%s;WERT=%s%s;ZIEL=%s;STUNDEN=%s;ZEIT=%s\n", $ev_pz, $aktion,
                          $klar, $gerundet, $ev_z, $ev_s, $zeit);
} else {
    $ev_antwort = sprintf("EVCC;OK=1;%sAKTION=%s;WERT=%s%s%s\n", $ev_pz, $aktion, $klar,
                          $gerundet, $zeit !== '' ? ';ZEIT=' . $zeit : '');
}

// Schreiber-Wache (Energie-1 C1): ;SCHREIBER=n / ;WACHE=MERKER hinten an.
$ev_antwort = rtrim($ev_antwort, "\n") . $ev_wz . "\n";

/* Der Trockenlauf endet hier (EVCC-b1): nichts an EVCC, der Bremsmerker
 * bleibt, wie er war, der Zwischenspeicher auch. */
if ($ev_probe) {
    if (is_resource($ev_bfh)) {
        flock($ev_bfh, LOCK_UN);
        fclose($ev_bfh);
    }
    ev_log_wenn_neu('probe', 'Trockenlauf ' . $aktion . ' (' . $klar . ') fuer '
        . ($b['ebene'] === 'lp' ? 'Ladepunkt ' . $lp : 'die Anlage')
        . ' von ' . ev_anrufer() . ' geprueft - nichts gesendet.');
    echo $ev_antwort;
    exit;
}

$a = ev_http($pfad, $b['methode']);
if (!$a['ok']) {
    ev_log('Befehl ' . $aktion . ' (' . $klar . ') an ' . $pfad . ' FEHLGESCHLAGEN: ' . $a['fehler']);
    /* Ein Befehl aus der Dokumentation, den EVCC nicht kennt, sieht in der
     * Antwort anders aus als ein Netzfehler - sonst sucht man den Fehler bei
     * der Verkabelung, wo er beim Pfad liegt. */
    $zusatz = '';
    if ($b['quelle'] === 'doku' && (int) $a['code'] === 404) {
        $zusatz = ';HINWEIS=Dieser Befehl stammt aus der EVCC-Dokumentation und ist an keiner '
                . 'Anlage gemessen. Ihre EVCC-Fassung kennt den Pfad ' . $b['pfad'] . ' nicht.';
    }
    ev_ende(502, 'EVCC;OK=0;AKTION=' . $aktion . ';CODE=' . (int) $a['code']
                 . ';GRUND=' . str_replace(';', ',', (string) $a['fehler']) . $zusatz);
}
ev_log('Befehl ' . $aktion . ' (' . $klar . ') an '
     . ($b['ebene'] === 'lp' ? 'Ladepunkt ' . $lp : 'die Anlage') . ' gesendet');
/* Die Bremse merkt sich den GESENDETEN Wert (C3). Scheitert das Schreiben,
 * wirkt der Befehl trotzdem - aber das steht im Protokoll. */
$ev_bm[$ev_bschl] = array('w' => $ev_bwert, 't' => time());
if (!(ftruncate($ev_bfh, 0) && rewind($ev_bfh)
      && fwrite($ev_bfh, (string) json_encode($ev_bm)) !== false && fflush($ev_bfh))) {
    ev_log_wenn_neu('bremse', 'Die Merkerdatei der Befehlsbremse (' . $ev_bremse . ') liess sich '
        . 'nicht schreiben - die Bremse erkennt den letzten Befehl nicht.');
}
flock($ev_bfh, LOCK_UN);
fclose($ev_bfh);
// Der zwischengespeicherte Zustand ist jetzt veraltet.
@unlink(ev_tmpdir() . '/state.json');
echo $ev_antwort;
