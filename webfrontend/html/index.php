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
$ev_kandidaten = array(
    dirname(dirname(dirname(__DIR__))) . '/htmlauth/plugins/' . basename(__DIR__) . '/ev_lib.php',
    dirname(dirname(__DIR__)) . '/htmlauth/plugins/' . basename(__DIR__) . '/ev_lib.php',
    dirname(__DIR__) . '/htmlauth/ev_lib.php',
);
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

/* Wie alt darf der zwischengespeicherte Zustand sein?
 *
 * Bis 0.9.10 galt takt/2 - bei Takt 15 also 7 Sekunden, waehrend die erzeugte
 * Vorlage alle 30 Sekunden fragt. Damit war der Stand bei JEDER Abfrage
 * abgelaufen und der Endpunkt stellte selbst eine HTTP-Anfrage mit bis zu 8 s
 * Zeitgrenze - obwohl in seinem eigenen Kopf steht, er lese nur den
 * Zwischenspeicher. Der Abrufdienst fuellt ihn jede Taktlaenge; zweieinhalb
 * Takte Nachsicht heisst: im Normalbetrieb kein einziger eigener Abruf, und
 * bei stehendem Dienst trotzdem nach kurzer Zeit ein frischer Versuch. */
$ev_hoechstalter = max(30, (int) round($cfg['takt'] * 2.5));

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
    $werte = ev_werte(ev_state(false, $ev_hoechstalter));
    if ($feld === '' || !isset($werte[$feld])) {
        ev_ende(400, '-');
    }
    echo $werte[$feld]['wert'] . "\n";
    exit;
}

if ($aktion === 'status') {
    echo ev_zeile(ev_werte(ev_state(false, $ev_hoechstalter)));
    exit;
}

/* ================= Schreibende Aktionen ================= */

if (empty($cfg['steuerung_ein'])) {
    ev_ende(403, "EVCC;OK=0;GRUND=STEUERUNG_AUS\n"
                 . 'Schreibende Befehle sind gesperrt. Reiter Einstellungen, '
                 . 'Haken "Steuerung aus Loxone zulassen".');
}

$b = $befehle[$aktion];

/* Ladepunkt pruefen - aber nur, wo einer gebraucht wird. */
$lp = isset($_GET['lp']) ? (int) $_GET['lp'] : 1;
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

$pfad = str_replace(
    array('%LP%', '%WERT%', '%ZEIT%'),
    array((string) $lp, rawurlencode($klar), rawurlencode($zeit)),
    $b['pfad']);

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
// Der zwischengespeicherte Zustand ist jetzt veraltet.
@unlink(ev_tmpdir() . '/state.json');
printf("EVCC;OK=1;AKTION=%s;WERT=%s%s\n", $aktion, $klar,
       $zeit !== '' ? ';ZEIT=' . $zeit : '');
