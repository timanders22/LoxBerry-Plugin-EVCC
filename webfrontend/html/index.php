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
 * Lesende Aktionen:
 *   status      eine Zeile EVCC;FELD=WERT;...   (Vorgabe)
 *   json        der Zustand als JSON
 *   roh         die unveraenderte Antwort von EVCC - fuer die Fehlersuche
 *   wert        ein einzelner Wert, blank ausgegeben (&feld=netz_kw)
 *
 * Schreibende Aktionen (nur wenn im Reiter Einstellungen freigegeben):
 *   modus, limitsoc, minsoc, phasen, minstrom, maxstrom, prioritaet,
 *   smartcostlimit, batterieboost      je mit &lp=<Nummer>
 *   batteriemodus, prioritaetssoc, puffersoc, residualleistung,
 *   entladeregelung
 *
 * Der Datenabruf gehoert NICHT hierher - der laeuft in bin/ev_abruf.php.
 * Dieser Endpunkt liest nur den zwischengespeicherten Zustand und reicht
 * Schaltbefehle weiter.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');

require_once dirname(__DIR__) . '/htmlauth/ev_lib.php';

function ev_ende($code, $text)
{
    http_response_code($code);
    // Die Antwort ist EINE Zeile - Loxone liest sie mit einer
    // Befehlserkennung. Steht in einem Grund ein Umbruch (curl-Fehlertexte
    // koennen mehrzeilig sein, ebenso Antworten von EVCC), zerfaellt die
    // Zeile, und Loxone sieht nur noch den ersten Teil.
    //
    // Die mehrzeiligen Ausgaben (json, roh) laufen nicht ueber diese
    // Funktion, sondern schreiben direkt. Was hier ankommt, ist immer eine
    // Statuszeile - auch die Liste der erlaubten Aktionen, die dadurch in
    // einer Zeile steht statt in zweien. Lesbar bleibt sie.
    $text = str_replace(array("\r\n", "\r", "\n"), ' ', (string) $text);
    echo rtrim($text) . "\n";
    exit;
}

$cfg = ev_config();

/* ---------------- Token ---------------- */
$soll = (string) $cfg['aktionstoken'];
$ist  = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($soll === '' || !hash_equals($soll, $ist)) {
    // Bewusst keine Auskunft darueber, ob das Token zu kurz, zu lang oder
    // schlicht falsch war.
    ev_ende(403, 'EVCC;OK=0;GRUND=TOKEN');
}

/* ---------------- Aktion gegen die Weissliste ---------------- */
$lesend = array('status', 'json', 'roh', 'wert');
$schreibend = array('modus', 'limitsoc', 'minsoc', 'phasen', 'minstrom', 'maxstrom',
                    'prioritaet', 'smartcostlimit', 'batterieboost',
                    'batteriemodus', 'prioritaetssoc', 'puffersoc',
                    'residualleistung', 'entladeregelung');
$aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : 'status';
if (!in_array($aktion, array_merge($lesend, $schreibend), true)) {
    ev_ende(400, "EVCC;OK=0;GRUND=UNBEKANNTE_AKTION\n"
                 . 'Erlaubt: ' . implode(', ', array_merge($lesend, $schreibend)));
}

/* ---------------- Lesende Aktionen ---------------- */

if ($aktion === 'roh') {
    $st = ev_state();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($st['roh'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($aktion === 'json') {
    $st = ev_state();
    $werte = ev_werte($st);
    $flach = array();
    foreach ($werte as $k => $d) { $flach[$k] = $d['wert']; }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => $st['ok'], 'stand' => $st['stand'],
                           'fehler' => $st['fehler'], 'werte' => $flach),
                     JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($aktion === 'wert') {
    $feld = isset($_GET['feld']) ? preg_replace('/[^a-z0-9_]/', '', (string) $_GET['feld']) : '';
    $werte = ev_werte();
    if ($feld === '' || !isset($werte[$feld])) {
        ev_ende(400, '-');
    }
    echo $werte[$feld]['wert'] . "\n";
    exit;
}

if ($aktion === 'status') {
    echo ev_zeile();
    exit;
}

/* ================= Schreibende Aktionen ================= */

if (empty($cfg['steuerung_ein'])) {
    ev_ende(403, "EVCC;OK=0;GRUND=STEUERUNG_AUS\n"
                 . 'Schreibende Befehle sind gesperrt. Reiter Einstellungen, '
                 . 'Haken "Steuerung aus Loxone zulassen".');
}

$wert = isset($_GET['wert']) ? trim((string) $_GET['wert']) : '';
if ($wert === '') {
    ev_ende(400, 'EVCC;OK=0;GRUND=WERT_FEHLT');
}
$lp = isset($_GET['lp']) ? (int) $_GET['lp'] : 1;
if ($lp < 1 || $lp > EV_LADEPUNKTE) {
    ev_ende(400, 'EVCC;OK=0;GRUND=LADEPUNKT_UNGUELTIG');
}

/**
 * Zuordnung Aktion -> EVCC-Pfad und erlaubter Wertebereich.
 *
 * Die Werte werden GEPRUEFT, nicht zurechtgebogen: ein Lademodus, den EVCC
 * nicht kennt, wird abgewiesen statt stillschweigend auf 'off' gesetzt.
 * Loxone schickt Analogwerte oft als '3.000000' - deshalb wird bei
 * ganzzahligen Feldern gerundet, bevor geprueft wird.
 */
$zahl = str_replace(',', '.', $wert);
$ganz = is_numeric($zahl) ? (int) round((float) $zahl) : null;

switch ($aktion) {
    case 'modus':
        // Loxone kann die Zahl 0..3 oder den Text schicken.
        $modus = is_numeric($zahl) ? ev_modus_text($ganz) : strtolower($wert);
        if (!in_array($modus, array('off', 'now', 'minpv', 'pv'), true)) {
            ev_ende(400, 'EVCC;OK=0;GRUND=MODUS_UNGUELTIG;ERLAUBT=off,now,minpv,pv,0,1,2,3');
        }
        $pfad = '/api/loadpoints/' . $lp . '/mode/' . rawurlencode($modus);
        $klar = $modus;
        break;

    case 'limitsoc':
    case 'minsoc':
        if ($ganz === null || $ganz < 0 || $ganz > 100) {
            ev_ende(400, 'EVCC;OK=0;GRUND=BEREICH;ERLAUBT=0..100');
        }
        $pfad = '/api/loadpoints/' . $lp . '/' . ($aktion === 'limitsoc' ? 'limitsoc' : 'minsoc') . '/' . $ganz;
        $klar = (string) $ganz;
        break;

    case 'phasen':
        if (!in_array($ganz, array(0, 1, 3), true)) {
            ev_ende(400, 'EVCC;OK=0;GRUND=BEREICH;ERLAUBT=0,1,3');
        }
        $pfad = '/api/loadpoints/' . $lp . '/phases/' . $ganz;
        $klar = (string) $ganz;
        break;

    case 'minstrom':
    case 'maxstrom':
        if ($ganz === null || $ganz < 0 || $ganz > 80) {
            ev_ende(400, 'EVCC;OK=0;GRUND=BEREICH;ERLAUBT=0..80');
        }
        $pfad = '/api/loadpoints/' . $lp . '/' . ($aktion === 'minstrom' ? 'mincurrent' : 'maxcurrent') . '/' . $ganz;
        $klar = (string) $ganz;
        break;

    case 'prioritaet':
        if ($ganz === null || $ganz < 0 || $ganz > 10) {
            ev_ende(400, 'EVCC;OK=0;GRUND=BEREICH;ERLAUBT=0..10');
        }
        $pfad = '/api/loadpoints/' . $lp . '/priority/' . $ganz;
        $klar = (string) $ganz;
        break;

    case 'smartcostlimit':
        if (!is_numeric($zahl)) {
            ev_ende(400, 'EVCC;OK=0;GRUND=KEINE_ZAHL');
        }
        $pfad = '/api/loadpoints/' . $lp . '/smartcostlimit/' . rawurlencode((string) (float) $zahl);
        $klar = (string) (float) $zahl;
        break;

    case 'batterieboost':
        if (!in_array($ganz, array(0, 1), true)) {
            ev_ende(400, 'EVCC;OK=0;GRUND=BEREICH;ERLAUBT=0,1');
        }
        $pfad = '/api/loadpoints/' . $lp . '/batteryboost/' . $ganz;
        $klar = (string) $ganz;
        break;

    case 'batteriemodus':
        $m = is_numeric($zahl)
            ? (array(0 => 'normal', 1 => 'hold', 2 => 'charge')[$ganz] ?? '')
            : strtolower($wert);
        if (!in_array($m, array('normal', 'hold', 'charge'), true)) {
            ev_ende(400, 'EVCC;OK=0;GRUND=BEREICH;ERLAUBT=normal,hold,charge,0,1,2');
        }
        $pfad = '/api/batterymode/' . rawurlencode($m);
        $klar = $m;
        break;

    case 'prioritaetssoc':
    case 'puffersoc':
        if ($ganz === null || $ganz < 0 || $ganz > 100) {
            ev_ende(400, 'EVCC;OK=0;GRUND=BEREICH;ERLAUBT=0..100');
        }
        $pfad = '/api/' . ($aktion === 'prioritaetssoc' ? 'prioritysoc' : 'buffersoc') . '/' . $ganz;
        $klar = (string) $ganz;
        break;

    case 'residualleistung':
        if (!is_numeric($zahl)) {
            ev_ende(400, 'EVCC;OK=0;GRUND=KEINE_ZAHL');
        }
        $pfad = '/api/residualpower/' . rawurlencode((string) (int) round((float) $zahl));
        $klar = (string) (int) round((float) $zahl);
        break;

    case 'entladeregelung':
        if (!in_array($ganz, array(0, 1), true)) {
            ev_ende(400, 'EVCC;OK=0;GRUND=BEREICH;ERLAUBT=0,1');
        }
        $pfad = '/api/batterydischargecontrol/' . ($ganz ? 'true' : 'false');
        $klar = $ganz ? 'true' : 'false';
        break;

    default:
        ev_ende(400, 'EVCC;OK=0;GRUND=UNBEKANNTE_AKTION');
}

$a = ev_http($pfad, 'POST');
if (!$a['ok']) {
    ev_log('Befehl ' . $aktion . ' (' . $klar . ') an ' . $pfad . ' FEHLGESCHLAGEN: ' . $a['fehler']);
    ev_ende(502, 'EVCC;OK=0;AKTION=' . $aktion . ';GRUND=' . str_replace(';', ',', (string) $a['fehler']));
}
ev_log('Befehl ' . $aktion . ' (' . $klar . ') an Ladepunkt ' . $lp . ' gesendet');
// Der zwischengespeicherte Zustand ist jetzt veraltet.
@unlink(ev_tmpdir() . '/state.json');
printf("EVCC;OK=1;AKTION=%s;WERT=%s\n", $aktion, $klar);
