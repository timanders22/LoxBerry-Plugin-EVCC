<?php
/**
 * EVCC fuer LoxBerry - gemeinsame Bibliothek
 *
 * EVCC (evcc.io) regelt das PV-Ueberschussladen herstelleruebergreifend. Es
 * bringt eine eigene Oberflaeche mit und macht seine Arbeit gut. Was fehlt,
 * ist der Weg nach Loxone: EVCC rechnet in Watt und veroeffentlicht unter
 * seinen eigenen Namen, der Loxone-Energiemanager will Kilowatt an vier
 * bestimmten Anschluessen. Dieses Plugin ist der Uebersetzer dazwischen -
 * und richtet EVCC bei der Installation gleich mit ein.
 *
 * WAS HIER PASSIERT
 *   ev_felder()   die EINE Quelle. Jedes Feld nennt seinen Weg in die
 *                 EVCC-Antwort, seine Einheit, seine Grenzen und seinen
 *                 Sprachschluessel. Daraus entstehen die MQTT-Themen, die
 *                 Textzeile fuer Loxone, die XML-Vorlage und der Selbsttest.
 *                 Wer ein Feld ergaenzt, ergaenzt es genau einmal.
 *   ev_state()    holt /api/state von EVCC und legt es kurz beiseite
 *   ev_werte()    loest die Feldtabelle gegen den Zustand auf
 *
 * ZU DEN VORZEICHEN - der Grund, warum das Uebersetzen ueberhaupt lohnt:
 *   EVCC  grid/power     positiv = Netzbezug
 *   Loxone Gpwr          negativ = Einspeisung        -> gleiche Richtung
 *   EVCC  battery/power  positiv = Entladung
 *   Loxone Spwr          negativ = Speicher laedt     -> gleiche Richtung
 * Die Vorzeichen passen also, nur die Einheit nicht: EVCC liefert Watt,
 * der Energiemanager will Kilowatt.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('Europe/Berlin');

/** Anzahl der Ladepunkte, fuer die Felder erzeugt werden. */
define('EV_LADEPUNKTE', 4);
/** Anzahl der Fahrzeuge, fuer die Felder erzeugt werden. */
define('EV_FAHRZEUGE', 4);

/* ==================================================================
 * Pfade und Protokoll
 * ================================================================== */


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function ev_paths()
{
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) { $home = $k; break; }
        }
    }
    $plugin = getenv('LBPPLUGINDIR');
    if (!$plugin) {
        // Ohne Umgebungsvariable (Aufruf von Hand) aus dem Ablageort ableiten.
        $plugin = basename(dirname(dirname(__DIR__)));
        if ($home && !is_dir($home . '/config/plugins/' . $plugin)) { $plugin = 'evcc'; }
    }
    if ($home) {
        return array(
            'home'      => $home,
            'plugin'    => $plugin,
            'config'    => $home . '/config/plugins/' . $plugin . '/evcc.json',
            'sicherung' => $home . '/config/plugins/' . $plugin . '/evcc.backup.json',
            'configdir' => $home . '/config/plugins/' . $plugin,
            'data'      => $home . '/data/plugins/' . $plugin,
            'log'       => $home . '/log/plugins/' . $plugin . '/evcc.log',
            'tmp'       => '/tmp/' . $plugin,
        );
    }
    $eigen = dirname(dirname(__DIR__));
    return array(
        'home' => '', 'plugin' => 'evcc',
        'config' => $eigen . '/config/evcc.json',
        'sicherung' => $eigen . '/config/evcc.backup.json',
        'configdir' => $eigen . '/config',
        'data' => sys_get_temp_dir() . '/evcc',
        'log' => sys_get_temp_dir() . '/evcc/evcc.log',
        'tmp' => sys_get_temp_dir() . '/evcc',
    );
}

function ev_tmpdir()
{
    $p = ev_paths();
    if (!is_dir($p['tmp'])) { @mkdir($p['tmp'], 0775, true); }
    return $p['tmp'];
}

function ev_datadir()
{
    $p = ev_paths();
    if (!is_dir($p['data'])) { @mkdir($p['data'], 0775, true); }
    return $p['data'];
}

function ev_log($text)
{
    $p = ev_paths();
    $d = dirname($p['log']);
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    if (is_file($p['log']) && filesize($p['log']) > 512000) {
        // Rotation: die letzten 200 Zeilen behalten.
        $rest = array_slice(file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -200);
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    @file_put_contents($p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND);
}

/** Nur protokollieren, wenn sich der Text geaendert hat. */
/**
 * Dieselbe Meldung nur einmal ins Protokoll.
 *
 * Der Merker liegt jetzt ZUERST im Arbeitsspeicher. ev_abruf.php schleift
 * innerhalb einer Minute bis zu zwoelfmal durch; bis 0.9.0 wurde dabei jedes
 * Mal eine Datei gelesen und - bei einer Dauerstoerung - nie geschrieben.
 * Das ist kein Beinbruch (/tmp liegt im Arbeitsspeicher), aber es ist Arbeit
 * ohne Ertrag.
 *
 * Die Datei bleibt trotzdem: der Cron startet jede Minute einen NEUEN
 * Prozess, und ohne die Datei stuende dieselbe Dauerstoerung dann jede
 * Minute erneut im Protokoll.
 */
function ev_log_wenn_neu($schluessel, $text)
{
    static $merker = array();
    $schluessel = preg_replace('/[^a-z0-9_]/', '', $schluessel);
    if (array_key_exists($schluessel, $merker)) {
        if ($merker[$schluessel] === $text) { return; }
    } else {
        $f = ev_tmpdir() . '/letzte_' . $schluessel . '.txt';
        $merker[$schluessel] = is_file($f) ? (string) file_get_contents($f) : '';
        if ($merker[$schluessel] === $text) { return; }
    }
    $merker[$schluessel] = $text;
    ev_log($schluessel . ': ' . $text);
    @file_put_contents(ev_tmpdir() . '/letzte_' . $schluessel . '.txt', $text);
}

function ev_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function ev_x($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

/* ==================================================================
 * Konfiguration
 * ================================================================== */

function ev_vorgaben()
{
    return array(
        // Verbindung zu EVCC
        'url'            => 'http://127.0.0.1:7070',
        'passwort'       => '',        // nur noetig, wenn EVCC eines verlangt
        'takt'           => 15,        // Abruf alle X Sekunden (5..60)
        // Steuerung aus Loxone
        'steuerung_ein'  => 0,         // schreibende Aktionen zulassen
        'aktionstoken'   => '',        // wird beim ersten Aufruf erzeugt
        // MQTT
        'mqtt_ein'       => 1,
        'mqtt_topic'     => 'evcc2lox',
        // Umfang
        'ladepunkte'     => 2,         // wie viele Ladepunkte ausgeben
        'fahrzeuge'      => 2,         // wie viele Fahrzeuge ausgeben
        'tarife_ein'     => 1,
    );
}

function ev_config()
{
    $p = ev_paths();
    // Selbstheilung: leere oder fehlende Konfiguration aus der Sicherung holen.
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if (($roh === '' || $roh === '{}') && is_file($p['sicherung'])) {
        @mkdir($p['configdir'], 0775, true);
        @copy($p['sicherung'], $p['config']);
    }
    $cfg = array();
    if (is_file($p['config'])) {
        $cfg = json_decode((string) @file_get_contents($p['config']), true);
    }
    if (!is_array($cfg)) { $cfg = array(); }
    $cfg = array_merge(ev_vorgaben(), $cfg);

    $cfg['url'] = rtrim(trim((string) $cfg['url']), '/');
    if ($cfg['url'] === '') { $cfg['url'] = 'http://127.0.0.1:7070'; }
    $cfg['takt'] = max(5, min(60, (int) $cfg['takt']));
    $cfg['ladepunkte'] = max(0, min(EV_LADEPUNKTE, (int) $cfg['ladepunkte']));
    $cfg['fahrzeuge'] = max(0, min(EV_FAHRZEUGE, (int) $cfg['fahrzeuge']));
    $cfg['steuerung_ein'] = empty($cfg['steuerung_ein']) ? 0 : 1;
    $cfg['mqtt_ein'] = empty($cfg['mqtt_ein']) ? 0 : 1;
    $cfg['tarife_ein'] = empty($cfg['tarife_ein']) ? 0 : 1;
    $cfg['mqtt_topic'] = preg_replace('#[^A-Za-z0-9_/\-]#', '', (string) $cfg['mqtt_topic']);
    if ($cfg['mqtt_topic'] === '') { $cfg['mqtt_topic'] = 'evcc2lox'; }

    // Token beim ersten Mal selbst erzeugen und gleich sichern.
    //
    // Mit Sperre. Beim ersten Aufruf nach der Einrichtung koennen die
    // Oberflaeche, der Cron-Abruf und der Miniserver-Endpunkt gleichzeitig
    // hier ankommen. Ohne Sperre erzeugt jeder ein eigenes Token und
    // ueberschreibt die anderen - wer sich das Token vorher aus der
    // Oberflaeche abgeschrieben hat, haelt danach ein ungueltiges in der Hand.
    if (!preg_match('/^[A-Za-z0-9]{24,}$/', (string) $cfg['aktionstoken'])) {
        $sperre = @fopen($p['configdir'] . '/.token.lock', 'c');
        if ($sperre !== false && flock($sperre, LOCK_EX)) {
            // Innerhalb der Sperre noch einmal nachsehen: vielleicht war ein
            // anderer Prozess schneller, dann wird seines uebernommen.
            $frisch = @json_decode((string) @file_get_contents($p['config']), true);
            if (is_array($frisch)
                && preg_match('/^[A-Za-z0-9]{24,}$/', (string) (isset($frisch['aktionstoken']) ? $frisch['aktionstoken'] : ''))) {
                $cfg['aktionstoken'] = $frisch['aktionstoken'];
            } else {
                try {
                    $cfg['aktionstoken'] = ev_token();
                    ev_config_write($cfg);
                } catch (RuntimeException $e) {
                    // ev_token bricht ab, wenn das System keinen sicheren
                    // Zufall hat. Dann bleibt das Token leer - der Endpunkt
                    // weist jede Anfrage ab, und im Reiter Test steht warum.
                    $cfg['aktionstoken'] = '';
                }
            }
            flock($sperre, LOCK_UN);
        }
        if ($sperre !== false) { fclose($sperre); }
    }
    return $cfg;
}

function ev_config_write($cfg)
{
    $p = ev_paths();
    @mkdir($p['configdir'], 0775, true);
    $js = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
    // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
    if ($js === false || @file_put_contents($p['config'], $js) === false) { return false; }
    // Die Datei traegt Token und moeglicherweise das EVCC-Passwort.
    @chmod($p['config'], 0600);
    @copy($p['config'], $p['sicherung']);
    @chmod($p['sicherung'], 0600);
    return true;
}

/** Zufaelliges Token. random_bytes, nicht rand() - das Token schuetzt einen
 *  Endpunkt, der ohne Anmeldung erreichbar ist. */
/**
 * Ein Token fuer den unangemeldeten Endpunkt.
 *
 * KEIN Rueckfall auf mt_rand. Bis 0.9.0 stand hier einer - und er war
 * gefaehrlicher als gar keiner: mt_rand ist ein Mersenne-Twister, kein
 * Zufallsgenerator fuer Sicherheitszwecke. Wer ein paar Ausgaben kennt, kann
 * den inneren Zustand bestimmen und alle weiteren vorhersagen. Dieses Token
 * ist das EINZIGE, was den schaltenden Endpunkt schuetzt.
 *
 * random_bytes wirft nur, wenn das Betriebssystem keine Zufallsquelle
 * anbietet. Dann ist etwas grundlegend nicht in Ordnung, und ein erratbares
 * Token waere die falsche Antwort darauf. Also wird abgebrochen und gesagt,
 * warum.
 *
 * Nebenbei: der Modulo auf 62 Zeichen verteilt nicht ganz gleichmaessig
 * (256 ist kein Vielfaches von 62). Bei 32 Zeichen aus 62 bleiben auch mit
 * dieser Schiefe rund 190 Bit - das genuegt bei weitem. Erwaehnt sei es
 * trotzdem, damit niemand die Stelle spaeter fuer bewiesen haelt.
 */
function ev_token($laenge = 32)
{
    $zeichen = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    try {
        $roh = random_bytes($laenge);
    } catch (Exception $e) {
        ev_log('FEHLER: das Betriebssystem liefert keinen sicheren Zufall ('
             . $e->getMessage() . '). Es wird KEIN Token erzeugt - ein '
             . 'erratbares waere schlimmer als keines.');
        throw new RuntimeException(
            'Kein sicherer Zufall verfuegbar - es wurde kein Token erzeugt.');
    }
    $out = '';
    for ($i = 0; $i < $laenge; $i++) {
        $out .= $zeichen[ord($roh[$i]) % strlen($zeichen)];
    }
    return $out;
}

/* ==================================================================
 * EVCC ansprechen
 * ================================================================== */

/**
 * HTTP gegen EVCC. Rueckgabe: array(ok, code, body, fehler).
 *
 * Kopfzeilen nach Hausregel: manche Zwischenstellen weisen Anfragen ohne
 * User-Agent ab, und ein sprechender Name hilft beim Suchen im EVCC-Log.
 */
function ev_http($pfad, $methode = 'GET', $rumpf = null, $zeit = 8)
{
    $cfg = ev_config();
    $url = $cfg['url'] . $pfad;
    $kopf = array(
        'User-Agent: LoxBerry-EVCC-Plugin',
        'Accept: application/json',
    );
    if ((string) $cfg['passwort'] !== '') {
        // EVCC nimmt das Administratorpasswort als Bearer-Token entgegen.
        $kopf[] = 'Authorization: Bearer ' . $cfg['passwort'];
    }
    if ($rumpf !== null) { $kopf[] = 'Content-Type: application/json'; }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $zeit,
            CURLOPT_CONNECTTIMEOUT => min(5, $zeit),
            CURLOPT_HTTPHEADER => $kopf,
            CURLOPT_CUSTOMREQUEST => $methode,
        ));
        if ($rumpf !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $rumpf); }
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return array('ok' => 0, 'code' => 0, 'body' => '', 'fehler' => $err !== '' ? $err : 'keine Antwort');
        }
        return array('ok' => ($code >= 200 && $code < 300) ? 1 : 0, 'code' => $code,
                     'body' => (string) $body, 'fehler' => ($code >= 200 && $code < 300) ? '' : ('HTTP ' . $code));
    }

    $ctx = stream_context_create(array('http' => array(
        'method' => $methode, 'timeout' => $zeit, 'ignore_errors' => true,
        'header' => implode("\r\n", $kopf),
        'content' => $rumpf === null ? '' : $rumpf,
    )));
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    if ($body === false) {
        return array('ok' => 0, 'code' => $code, 'body' => '', 'fehler' => 'keine Antwort');
    }
    return array('ok' => ($code >= 200 && $code < 300) ? 1 : 0, 'code' => $code,
                 'body' => (string) $body, 'fehler' => ($code >= 200 && $code < 300) ? '' : ('HTTP ' . $code));
}

/**
 * Zustand von EVCC holen. Cache: eine halbe Taktlaenge, damit mehrere
 * Aufrufe in derselben Sekunde EVCC nicht mehrfach befragen.
 *
 * Rueckgabe: array('ok','stand','fehler','roh' => <dekodiertes JSON>)
 */
function ev_state($force = false)
{
    $cfg = ev_config();
    $cache = ev_tmpdir() . '/state.json';
    $alter = max(2, (int) floor($cfg['takt'] / 2));
    if (!$force && is_file($cache) && (time() - filemtime($cache)) < $alter) {
        $c = json_decode((string) file_get_contents($cache), true);
        if (is_array($c) && isset($c['ok'])) { return $c; }
    }
    $a = ev_http('/api/state');
    $st = array('ok' => 0, 'stand' => 0, 'fehler' => '', 'roh' => array());
    if (!$a['ok']) {
        $st['fehler'] = $a['fehler'];
        ev_log_wenn_neu('abruf', 'FEHLGESCHLAGEN: ' . $a['fehler']);
        // Letzten guten Stand behalten, damit ein kurzer Aussetzer nicht
        // alle Werte in Loxone auf null zieht - aber den Fehler MITSCHREIBEN.
        //
        // Bis 0.9.0 wurde der entwertete Stand nur zurueckgegeben, nicht in
        // den Zwischenspeicher geschrieben. Die Datei behielt damit auf Dauer
        // 'ok' => 1, und das hat drei Folgen:
        //
        //   1. Wer die Datei unmittelbar liest - ev_fahrzeugnamen() tut das -
        //      sieht bis in alle Ewigkeit einen Zustand, der als gut markiert
        //      ist.
        //   2. Der Zeitstempel der Datei blieb alt. Der Miniserver-Endpunkt
        //      hielt den Zwischenspeicher deshalb bei JEDEM Abruf fuer
        //      veraltet und stellte selbst eine HTTP-Anfrage. Bei
        //      abgeschaltetem EVCC laeuft die in die Zeitgrenze - und die
        //      Loxone-Abfrage haengt jedes Mal mehrere Sekunden.
        //   3. 'stand' blieb der alte, was richtig ist: das Alter soll ja
        //      wachsen. Das bleibt so.
        //
        // Geschrieben wird deshalb jetzt der ENTWERTETE Stand: alte Werte,
        // alter Zeitstempel, aber ok = 0 und der Fehlertext. Ein Leser sieht
        // damit dasselbe, egal ob er ueber ev_state() geht oder die Datei
        // aufmacht.
        if (is_file($cache)) {
            $c = json_decode((string) file_get_contents($cache), true);
            if (is_array($c) && !empty($c['roh'])) {
                $c['ok'] = 0;
                $c['fehler'] = $a['fehler'];
                // 'stand' NICHT anfassen - daraus rechnet der Endpunkt das
                // Alter, und das soll wachsen.
                @file_put_contents($cache, json_encode($c));
                return $c;
            }
        }
        // Es gibt gar keinen alten Stand. Auch das gehoert festgehalten,
        // sonst fragt jeder Aufruf erneut und laeuft erneut in die Zeitgrenze.
        @file_put_contents($cache, json_encode($st));
        return $st;
    }
    $d = json_decode($a['body'], true);
    if (!is_array($d)) {
        $st['fehler'] = 'Antwort ist kein JSON';
        ev_log_wenn_neu('abruf', 'Antwort ist kein JSON');
        return $st;
    }
    // EVCC verpackt den Zustand je nach Fassung in 'result'. Beides annehmen.
    if (isset($d['result']) && is_array($d['result'])) { $d = $d['result']; }
    $st = array('ok' => 1, 'stand' => time(), 'fehler' => '', 'roh' => $d);
    @file_put_contents($cache, json_encode($st));
    ev_log_wenn_neu('abruf', 'ok, ' . count($d) . ' Felder');
    return $st;
}

/**
 * Einen Wert aus dem Zustand holen. $pfade ist eine LISTE von Kandidaten in
 * Punktschreibweise, der erste Treffer gewinnt.
 *
 * Warum mehrere Kandidaten: Die MQTT-Themen von EVCC sind dokumentiert
 * (evcc/site/grid/power), die genaue Form von /api/state ist es nicht - und
 * sie hat sich zwischen den Fassungen schon verschoben (frueher flach
 * 'gridPower', heute verschachtelt 'grid.power'). Statt eine Form zu raten
 * und beim naechsten EVCC-Update stumm falsch zu liegen, werden beide
 * angenommen. Der Reiter Test zeigt an, welcher Weg wirklich getroffen hat.
 */
function ev_hole($roh, $pfade)
{
    foreach ((array) $pfade as $pfad) {
        $wert = $roh;
        $gefunden = true;
        foreach (explode('.', $pfad) as $teil) {
            if (is_array($wert) && array_key_exists($teil, $wert)) {
                $wert = $wert[$teil];
            } elseif (is_array($wert) && ctype_digit($teil) && array_key_exists((int) $teil, $wert)) {
                $wert = $wert[(int) $teil];
            } else {
                $gefunden = false;
                break;
            }
        }
        if ($gefunden && $wert !== null && !is_array($wert)) {
            return array($wert, $pfad);
        }
    }
    return array(null, '');
}

/* ==================================================================
 * Die Feldtabelle - die EINE Quelle
 *
 * Je Feld:
 *   pfade    Kandidaten in /api/state, erster Treffer gewinnt
 *   typ      wie umgerechnet wird (siehe ev_umrechnen)
 *   analog   0 = Ja/Nein, 1 = Zahl
 *   min/max  Grenzen des FERTIGEN Wertes, fuer die Loxone-Vorlage
 *   einheit  erscheint im Kommentar des virtuellen Eingangs
 *   text     Sprachschluessel
 *   mqtt     das dokumentierte EVCC-Topic - nur zur Nachvollziehbarkeit,
 *            damit man die Zuordnung gegen die EVCC-Doku halten kann
 * ================================================================== */

function ev_umrechnen($typ, $wert)
{
    if ($wert === null) { return null; }
    switch ($typ) {
        case 'bool':
            return ($wert === true || $wert === 1 || $wert === '1'
                    || $wert === 'true' || $wert === 'on') ? 1 : 0;
        case 'text':
            return (string) $wert;
        case 'kw':        // Watt -> Kilowatt, das will der Energiemanager
            return round(((float) $wert) / 1000, 3);
        case 'kwh':       // Wattstunden -> Kilowattstunden
            return round(((float) $wert) / 1000, 3);
        case 'prozent1':  // Anteil 0..1 -> Prozent
            return round(((float) $wert) * 100, 1);
        case 'minuten':   // Nanosekunden -> Minuten. EVCC gibt Dauern in ns aus.
            return (int) round(((float) $wert) / 1000000000 / 60);
        case 'zahl':
            $z = (float) $wert;
            return (float) $z == (int) $z ? (int) $z : round($z, 3);
        case 'komma3':
            return round((float) $wert, 3);
    }
    return null;
}

function ev_felder()
{
    $cfg = ev_config();
    $f = array();

    /* ---- Anlage: die vier Groessen des Energiemanagers zuerst ---- */
    $f['netz_kw'] = array(
        'pfade' => array('grid.power', 'gridPower'),
        'typ' => 'kw', 'analog' => 1, 'min' => -100, 'max' => 100, 'einheit' => 'kW',
        'text' => 'FELD.NETZ_KW', 'mqtt' => 'evcc/site/grid/power');
    $f['pv_kw'] = array(
        'pfade' => array('pvPower', 'pv.power'),
        'typ' => 'kw', 'analog' => 1, 'min' => 0, 'max' => 100, 'einheit' => 'kW',
        'text' => 'FELD.PV_KW', 'mqtt' => 'evcc/site/pvPower');
    $f['speicher_kw'] = array(
        'pfade' => array('batteryPower', 'battery.power'),
        'typ' => 'kw', 'analog' => 1, 'min' => -100, 'max' => 100, 'einheit' => 'kW',
        'text' => 'FELD.SPEICHER_KW', 'mqtt' => 'evcc/site/battery/power');
    $f['speicher_soc'] = array(
        'pfade' => array('batterySoc', 'battery.soc'),
        'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 100, 'einheit' => '%',
        'text' => 'FELD.SPEICHER_SOC', 'mqtt' => 'evcc/site/battery/soc');

    /* ---- Anlage: alles Weitere ---- */
    $f['haus_kw'] = array(
        'pfade' => array('homePower'),
        'typ' => 'kw', 'analog' => 1, 'min' => 0, 'max' => 100, 'einheit' => 'kW',
        'text' => 'FELD.HAUS_KW', 'mqtt' => 'evcc/site/homePower');
    $f['gruen_haus'] = array(
        'pfade' => array('greenShareHome'),
        'typ' => 'prozent1', 'analog' => 1, 'min' => 0, 'max' => 100, 'einheit' => '%',
        'text' => 'FELD.GRUEN_HAUS', 'mqtt' => 'evcc/site/greenShareHome');
    $f['gruen_laden'] = array(
        'pfade' => array('greenShareLoadpoints'),
        'typ' => 'prozent1', 'analog' => 1, 'min' => 0, 'max' => 100, 'einheit' => '%',
        'text' => 'FELD.GRUEN_LADEN', 'mqtt' => 'evcc/site/greenShareLoadpoints');
    $f['netzladen_aktiv'] = array(
        'pfade' => array('batteryGridChargeActive'),
        'typ' => 'bool', 'analog' => 0, 'min' => 0, 'max' => 1, 'einheit' => '',
        'text' => 'FELD.NETZLADEN_AKTIV', 'mqtt' => 'evcc/site/batteryGridChargeActive');
    $f['prioritaets_soc'] = array(
        'pfade' => array('prioritySoc'),
        'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 100, 'einheit' => '%',
        'text' => 'FELD.PRIORITAETS_SOC', 'mqtt' => 'evcc/site/prioritySoc');
    $f['puffer_soc'] = array(
        'pfade' => array('bufferSoc'),
        'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 100, 'einheit' => '%',
        'text' => 'FELD.PUFFER_SOC', 'mqtt' => 'evcc/site/bufferSoc');

    if (!empty($cfg['tarife_ein'])) {
        $f['tarif_netz'] = array(
            'pfade' => array('tariffGrid'),
            'typ' => 'komma3', 'analog' => 1, 'min' => -10, 'max' => 10, 'einheit' => '/kWh',
            'text' => 'FELD.TARIF_NETZ', 'mqtt' => 'evcc/site/tariffGrid');
        $f['tarif_einspeisung'] = array(
            'pfade' => array('tariffFeedIn'),
            'typ' => 'komma3', 'analog' => 1, 'min' => -10, 'max' => 10, 'einheit' => '/kWh',
            'text' => 'FELD.TARIF_EINSPEISUNG', 'mqtt' => 'evcc/site/tariffFeedIn');
        $f['tarif_co2'] = array(
            'pfade' => array('tariffCo2'),
            'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 1000, 'einheit' => 'g/kWh',
            'text' => 'FELD.TARIF_CO2', 'mqtt' => 'evcc/site/tariffCo2');
    }

    /* ---- Ladepunkte. In /api/state ist loadpoints eine Liste ab Index 0,
            in den MQTT-Themen zaehlen sie ab 1. Hier gilt die MQTT-Zaehlung,
            weil der Anwender sie in der EVCC-Oberflaeche so sieht. ---- */
    for ($i = 1; $i <= (int) $cfg['ladepunkte']; $i++) {
        $j = $i - 1;
        $lp = 'lp' . $i . '_';
        $m = 'evcc/loadpoints/' . $i . '/';
        $f[$lp . 'verbunden'] = array(
            'pfade' => array("loadpoints.$j.connected"),
            'typ' => 'bool', 'analog' => 0, 'min' => 0, 'max' => 1, 'einheit' => '',
            'text' => 'FELD.LP_VERBUNDEN', 'nr' => $i, 'mqtt' => $m . 'connected');
        $f[$lp . 'laedt'] = array(
            'pfade' => array("loadpoints.$j.charging"),
            'typ' => 'bool', 'analog' => 0, 'min' => 0, 'max' => 1, 'einheit' => '',
            'text' => 'FELD.LP_LAEDT', 'nr' => $i, 'mqtt' => $m . 'charging');
        $f[$lp . 'freigegeben'] = array(
            'pfade' => array("loadpoints.$j.enabled"),
            'typ' => 'bool', 'analog' => 0, 'min' => 0, 'max' => 1, 'einheit' => '',
            'text' => 'FELD.LP_FREIGEGEBEN', 'nr' => $i, 'mqtt' => $m . 'enabled');
        $f[$lp . 'leistung_kw'] = array(
            'pfade' => array("loadpoints.$j.chargePower"),
            'typ' => 'kw', 'analog' => 1, 'min' => 0, 'max' => 50, 'einheit' => 'kW',
            'text' => 'FELD.LP_LEISTUNG', 'nr' => $i, 'mqtt' => $m . 'chargePower');
        $f[$lp . 'geladen_kwh'] = array(
            'pfade' => array("loadpoints.$j.chargedEnergy"),
            'typ' => 'kwh', 'analog' => 1, 'min' => 0, 'max' => 1000, 'einheit' => 'kWh',
            'text' => 'FELD.LP_GELADEN', 'nr' => $i, 'mqtt' => $m . 'chargedEnergy');
        $f[$lp . 'restzeit_min'] = array(
            'pfade' => array("loadpoints.$j.chargeRemainingDuration"),
            'typ' => 'minuten', 'analog' => 1, 'min' => 0, 'max' => 6000, 'einheit' => 'min',
            'text' => 'FELD.LP_RESTZEIT', 'nr' => $i, 'mqtt' => $m . 'chargeRemainingDuration');
        $f[$lp . 'phasen'] = array(
            'pfade' => array("loadpoints.$j.phasesActive"),
            'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 3, 'einheit' => '',
            'text' => 'FELD.LP_PHASEN', 'nr' => $i, 'mqtt' => $m . 'phasesActive');
        $f[$lp . 'fahrzeug_soc'] = array(
            'pfade' => array("loadpoints.$j.vehicleSoc"),
            'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 100, 'einheit' => '%',
            'text' => 'FELD.LP_FZ_SOC', 'nr' => $i, 'mqtt' => $m . 'vehicleSoc');
        $f[$lp . 'fahrzeug_km'] = array(
            'pfade' => array("loadpoints.$j.vehicleRange"),
            'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 2000, 'einheit' => 'km',
            'text' => 'FELD.LP_FZ_KM', 'nr' => $i, 'mqtt' => $m . 'vehicleRange');
        $f[$lp . 'solaranteil'] = array(
            'pfade' => array("loadpoints.$j.sessionSolarPercentage"),
            'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 100, 'einheit' => '%',
            'text' => 'FELD.LP_SOLARANTEIL', 'nr' => $i, 'mqtt' => $m . 'sessionSolarPercentage');
        $f[$lp . 'plan_aktiv'] = array(
            'pfade' => array("loadpoints.$j.planActive"),
            'typ' => 'bool', 'analog' => 0, 'min' => 0, 'max' => 1, 'einheit' => '',
            'text' => 'FELD.LP_PLAN', 'nr' => $i, 'mqtt' => $m . 'planActive');
        $f[$lp . 'smartcost_aktiv'] = array(
            'pfade' => array("loadpoints.$j.smartCostActive"),
            'typ' => 'bool', 'analog' => 0, 'min' => 0, 'max' => 1, 'einheit' => '',
            'text' => 'FELD.LP_SMARTCOST', 'nr' => $i, 'mqtt' => $m . 'smartCostActive');
        // Der Lademodus ist Text (off/now/minpv/pv). Fuer Loxone zusaetzlich
        // als Zahl, weil ein Analogeingang leichter zu verdrahten ist als ein
        // Texteingang - die Zuordnung steht im Kommentar der Vorlage.
        $f[$lp . 'modus_nr'] = array(
            'pfade' => array("loadpoints.$j.mode"),
            'typ' => 'modus', 'analog' => 1, 'min' => 0, 'max' => 3, 'einheit' => '',
            'text' => 'FELD.LP_MODUS', 'nr' => $i, 'mqtt' => $m . 'mode');
        $f[$lp . 'limit_soc'] = array(
            'pfade' => array("loadpoints.$j.limitSoc"),
            'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 100, 'einheit' => '%',
            'text' => 'FELD.LP_LIMIT_SOC', 'nr' => $i, 'mqtt' => $m . 'limitSoc');
        $f[$lp . 'prioritaet'] = array(
            'pfade' => array("loadpoints.$j.effectivePriority", "loadpoints.$j.priority"),
            'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 10, 'einheit' => '',
            'text' => 'FELD.LP_PRIORITAET', 'nr' => $i, 'mqtt' => $m . 'effectivePriority');
    }

    /* ---- Fahrzeuge. In /api/state ist 'vehicles' ein Objekt mit dem
            Fahrzeugnamen als Schluessel - eine Nummer gibt es dort nicht.
            Deshalb wird zur Laufzeit aufgeloest (ev_fahrzeugnamen). ---- */
    $namen = ev_fahrzeugnamen();
    for ($i = 1; $i <= (int) $cfg['fahrzeuge']; $i++) {
        $name = isset($namen[$i - 1]) ? $namen[$i - 1] : '';
        $fz = 'fz' . $i . '_';
        if ($name === '') {
            // Kein Fahrzeug an dieser Stelle: Felder trotzdem anlegen, damit
            // die Vorlage stabil bleibt, aber ohne Pfad - sie liefern 0.
            $f[$fz . 'soc'] = array('pfade' => array(), 'typ' => 'zahl', 'analog' => 1,
                'min' => 0, 'max' => 100, 'einheit' => '%', 'text' => 'FELD.FZ_SOC', 'nr' => $i, 'mqtt' => '');
            $f[$fz . 'km'] = array('pfade' => array(), 'typ' => 'zahl', 'analog' => 1,
                'min' => 0, 'max' => 2000, 'einheit' => 'km', 'text' => 'FELD.FZ_KM', 'nr' => $i, 'mqtt' => '');
            continue;
        }
        $f[$fz . 'soc'] = array(
            'pfade' => array("vehicles.$name.soc"),
            'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 100, 'einheit' => '%',
            'text' => 'FELD.FZ_SOC', 'nr' => $i, 'mqtt' => 'evcc/vehicles/' . $name . '/soc');
        $f[$fz . 'km'] = array(
            'pfade' => array("vehicles.$name.range"),
            'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 2000, 'einheit' => 'km',
            'text' => 'FELD.FZ_KM', 'nr' => $i, 'mqtt' => 'evcc/vehicles/' . $name . '/range');
        $f[$fz . 'limit_soc'] = array(
            'pfade' => array("vehicles.$name.limitSoc"),
            'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 100, 'einheit' => '%',
            'text' => 'FELD.FZ_LIMIT_SOC', 'nr' => $i, 'mqtt' => 'evcc/vehicles/' . $name . '/limitSoc');
    }

    /* ---- Zustand des Plugins selbst ---- */
    $f['ok'] = array('pfade' => array(), 'typ' => 'bool', 'analog' => 0, 'min' => 0, 'max' => 1,
        'einheit' => '', 'text' => 'FELD.OK', 'mqtt' => '');
    $f['alter_s'] = array('pfade' => array(), 'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 86400,
        'einheit' => 's', 'text' => 'FELD.ALTER', 'mqtt' => '');
    $f['dienst'] = array('pfade' => array(), 'typ' => 'bool', 'analog' => 0, 'min' => 0, 'max' => 1,
        'einheit' => '', 'text' => 'FELD.DIENST', 'mqtt' => '');
    return $f;
}

/** Lademodus als Zahl: 0 aus, 1 sofort, 2 min+PV, 3 nur PV. */
function ev_modus_nr($text)
{
    $k = array('off' => 0, 'now' => 1, 'minpv' => 2, 'pv' => 3);
    $t = strtolower(trim((string) $text));
    return isset($k[$t]) ? $k[$t] : 0;
}

function ev_modus_text($nr)
{
    $k = array(0 => 'off', 1 => 'now', 2 => 'minpv', 3 => 'pv');
    return isset($k[(int) $nr]) ? $k[(int) $nr] : 'off';
}

/** Die Fahrzeugnamen aus dem Zustand, in stabiler Reihenfolge. */
function ev_fahrzeugnamen($st = null)
{
    if ($st === null) {
        // Nicht ev_state() aufrufen - ev_felder() wird aus ev_state()-Naehe
        // heraus benutzt, das gaebe eine Schleife. Nur den Cache lesen.
        $cache = ev_tmpdir() . '/state.json';
        if (!is_file($cache)) { return array(); }
        $st = json_decode((string) file_get_contents($cache), true);
    }
    if (!is_array($st) || empty($st['roh']['vehicles']) || !is_array($st['roh']['vehicles'])) {
        return array();
    }
    $namen = array_keys($st['roh']['vehicles']);
    sort($namen);   // stabil, damit fz1 morgen dasselbe Fahrzeug ist
    return $namen;
}

/**
 * Alle Felder gegen den Zustand aufloesen.
 * Rueckgabe: array('name' => array('wert','pfad'))
 */
function ev_werte($st = null)
{
    if ($st === null) { $st = ev_state(); }
    $roh = isset($st['roh']) ? $st['roh'] : array();
    $out = array();
    foreach (ev_felder() as $name => $d) {
        if (empty($d['pfade'])) {
            $out[$name] = array('wert' => 0, 'pfad' => '');
            continue;
        }
        list($w, $pfad) = ev_hole($roh, $d['pfade']);
        if ($d['typ'] === 'modus') {
            $out[$name] = array('wert' => $w === null ? 0 : ev_modus_nr($w), 'pfad' => $pfad);
            continue;
        }
        $u = ev_umrechnen($d['typ'], $w);
        $out[$name] = array('wert' => $u === null ? 0 : $u, 'pfad' => $pfad);
    }
    // Die drei eigenen Felder.
    $out['ok'] = array('wert' => (int) (!empty($st['ok'])), 'pfad' => '-');
    $out['alter_s'] = array('wert' => !empty($st['stand']) ? max(0, time() - (int) $st['stand']) : 99999, 'pfad' => '-');
    $out['dienst'] = array('wert' => ev_dienst_laeuft() ? 1 : 0, 'pfad' => '-');
    return $out;
}

/* ==================================================================
 * Der EVCC-Dienst
 * ================================================================== */

/** Laeuft der systemd-Dienst? Ohne sudo pruefbar. */
function ev_dienst_laeuft()
{
    $aus = array();
    @exec('systemctl is-active evcc 2>/dev/null', $aus);
    return trim(implode('', $aus)) === 'active';
}

function ev_dienst_vorhanden()
{
    $aus = array();
    @exec('command -v evcc 2>/dev/null', $aus);
    return trim(implode('', $aus)) !== '';
}

function ev_dienst_version()
{
    $aus = array();
    @exec('evcc -v 2>/dev/null', $aus);
    $z = trim(implode(' ', $aus));
    return $z !== '' ? $z : '-';
}

/**
 * Dienst steuern. Erlaubt sind genau die drei Unterbefehle, fuer die
 * postroot.sh eine sudo-Regel angelegt hat - nichts wird zusammengesetzt.
 */
function ev_dienst($befehl)
{
    $erlaubt = array('start', 'stop', 'restart');
    if (!in_array($befehl, $erlaubt, true)) {
        return array(0, 'unbekannter Befehl');
    }
    $aus = array();
    $rc = 0;
    @exec('sudo -n /bin/systemctl ' . $befehl . ' evcc 2>&1', $aus, $rc);
    if ($rc !== 0) {
        // Zweiter Versuch mit dem anderen ueblichen Pfad.
        $aus = array();
        @exec('sudo -n /usr/bin/systemctl ' . $befehl . ' evcc 2>&1', $aus, $rc);
    }
    ev_log('Dienst ' . $befehl . ' -> ' . ($rc === 0 ? 'ok' : 'Fehler: ' . implode(' ', $aus)));
    return array($rc === 0 ? 1 : 0, trim(implode(' ', $aus)));
}

/* ==================================================================
 * MQTT ueber das LoxBerry-Gateway (UDP-Relay)
 *
 * Das MQTT-Gateway ist seit LoxBerry 3 Bestandteil des Systems und kein
 * Plugin. Es wird nicht nachinstalliert, sondern unter System -> MQTT
 * Gateway eingeschaltet.
 * ================================================================== */

function ev_mqtt_zustand()
{
    $p = ev_paths();
    $out = array('gefunden' => 0, 'udpport' => 0, 'autostart' => 0);
    if ($p['home'] === '') { return $out; }
    $gen = @json_decode((string) @file_get_contents($p['home'] . '/config/system/general.json'), true);
    if (!is_array($gen)) { return $out; }
    foreach (array('Mqtt', 'mqtt') as $k) {
        // is_array reicht hier wirklich: PHP 8 wuerde bei $gen[$k][$pk] auf
        // einer Zeichenkette zwar keinen fatalen Fehler werfen (es liest den
        // Buchstaben an dieser Stelle, und isset() ist bei einem nicht
        // numerischen Schluessel false), aber das Ergebnis waere Unsinn.
        // Die Pruefung stand schon da und bleibt - hier nur der Vollstaendig-
        // keit halber benannt, weil sie beim Lesen leicht zu uebersehen ist.
        if (!isset($gen[$k]) || !is_array($gen[$k])) { continue; }
        $out['gefunden'] = 1;
        foreach (array('Udpinport', 'udpinport') as $pk) {
            if (isset($gen[$k][$pk])) { $out['udpport'] = (int) $gen[$k][$pk]; }
        }
        foreach (array('Autostart', 'autostart') as $ak) {
            if (isset($gen[$k][$ak])) { $out['autostart'] = (int) $gen[$k][$ak] ? 1 : 0; }
        }
    }
    return $out;
}

function ev_mqtt_publish($werte = null)
{
    $cfg = ev_config();
    if (empty($cfg['mqtt_ein'])) { return 0; }
    $z = ev_mqtt_zustand();
    if (!$z['udpport']) {
        ev_log_wenn_neu('mqtt', 'kein UDP-Eingangsport in der general.json - Gateway eingerichtet?');
        return 0;
    }
    if ($werte === null) { $werte = ev_werte(); }
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) {
        ev_log_wenn_neu('mqtt', 'UDP-Socket liess sich nicht anlegen - ist die '
            . 'PHP-Erweiterung sockets vorhanden?');
        return 0;
    }
    $n = 0;
    foreach ($werte as $name => $d) {
        $msg = 'publish ' . ev_mqtt_thema($cfg['mqtt_topic'] . '/' . $name)
             . ' ' . ev_mqtt_nutzlast($d['wert']);
        $ok = @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $z['udpport']);
        if ($ok !== false) { $n++; }
    }
    socket_close($s);
    return $n;
}

/**
 * Ein Thema fuer das MQTT-Gateway.
 *
 * Das Gateway liest eine UDP-Zeile als drei Teile: Verb, Thema, Rest. Getrennt
 * wird an Leerzeichen - ein Leerzeichen IM Thema verschiebt deshalb alles
 * dahinter. Ausserdem beendet ein Zeilenumbruch die Nachricht.
 *
 * mqtt_topic ist zwar schon in ev_config() gefiltert, aber $name kommt aus
 * ev_felder() und koennte durch eine Erweiterung spaeter anders aussehen.
 * Deshalb wird hier noch einmal gefiltert - an der Stelle, wo es zaehlt.
 */
function ev_mqtt_thema($thema)
{
    $t = preg_replace('#[^A-Za-z0-9_/\-]#', '_', (string) $thema);
    $t = preg_replace('#/+#', '/', $t);
    return trim($t, '/');
}

/**
 * Eine Nutzlast fuer das MQTT-Gateway.
 *
 * Zeilenumbrueche muessen weg: das Gateway liest zeilenweise, ein \n mitten
 * in der Nutzlast macht aus einer Nachricht zwei - die zweite beginnt dann
 * nicht mit 'publish' und wird verworfen, aber der Rest des Wertes ist futsch.
 *
 * Hier stehen zwar nur Zahlen drin. Aber genau darauf hat sich das Plugin
 * schon einmal verlassen, und ein spaeter ergaenztes Textfeld (Fahrzeugname,
 * Fehlermeldung) faellt sonst niemandem auf, bis es kaputtgeht.
 */
function ev_mqtt_nutzlast($wert)
{
    $w = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $wert);
    $w = preg_replace('/\s+/', ' ', $w);
    return trim($w);
}

/* ==================================================================
 * Die Zeile fuer den Miniserver
 * ================================================================== */

/**
 * Eine Funktion, damit der Endpunkt und die Selbstpruefung dieselbe Zeile
 * erzeugen. Stuende die Zusammenstellung im Endpunkt, koennte die Pruefung
 * sie nicht gegenlesen, ohne den Endpunkt einzubinden - und ein include
 * wuerde dort ein header() nach der Ausgabe ausloesen.
 */
function ev_zeile($werte = null)
{
    if ($werte === null) { $werte = ev_werte(); }
    $teile = array();
    foreach ($werte as $name => $d) {
        $teile[] = strtoupper($name) . '=' . $d['wert'];
    }
    return 'EVCC;' . implode(';', $teile) . "\n";
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Geprüfter PHP-Nachbau des LoxoneTemplateBuilder - Attributreihenfolge,
 * CRLF und der Tabulator vor den Kindelementen entsprechen dem Original.
 * Uebernommen aus LoxBerry-Plugin-APC-UPS, nur das Kuerzel getauscht.
 *
 * Umgerechnet wird im PLUGIN, nicht in Loxone: der virtuelle Eingang liest
 * den fertigen Wert. Deshalb bleiben SourceVal und DestVal 1:1.
 * ================================================================== */

function ev_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . ev_x($kopf['title']) . '" ';
    $o .= 'Comment="' . ev_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . ev_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . ev_x(isset($kopf['polling']) ? $kopf['polling'] : '30') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . ev_x($c['title']) . '" ';
        $o .= 'Comment="' . ev_x($c['comment']) . '" ';
        $o .= 'Check="' . ev_x($c['check']) . '" ';
        $o .= 'Signed="' . ($c['min'] < 0 ? 'true' : 'false') . '" ';
        $o .= 'Analog="' . (!empty($c['analog']) ? 'true' : 'false') . '" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="1" ';
        $o .= 'DestValHigh="1" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="' . (int) $c['min'] . '" ';
        $o .= 'MaxVal="' . (int) $c['max'] . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

function ev_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut ';
    $o .= 'Title="' . ev_x($kopf['title']) . '" ';
    $o .= 'Comment="' . ev_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . ev_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'CloseAfterSend="false" ';
    $o .= 'CmdSep=""';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . ev_x($c['title']) . '" ';
        $o .= 'Comment="' . ev_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'CmdOn="' . ev_x($c['on']) . '" ';
        if (isset($c['off']) && $c['off'] !== '') {
            $o .= 'CmdOff="' . ev_x($c['off']) . '" ';
        }
        $o .= 'Analog="' . (!empty($c['analog']) ? 'true' : 'false') . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/** Adresse des eigenen Endpunkts. */
function ev_endpunkt($aktion = 'status', $mit_token = true)
{
    $p = ev_paths();
    $cfg = ev_config();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    return 'http://' . $host . '/plugins/' . $p['plugin'] . '/index.php'
         . ($mit_token ? '?token=' . $cfg['aktionstoken'] : '?token=TOKEN')
         . '&aktion=' . $aktion;
}

/** Vorlage der virtuellen EINGAENGE. Rueckgabe: array(name, inhalt) */
function ev_vorlage_ein()
{
    $cmds = array();
    foreach (ev_felder() as $name => $d) {
        $text = ev_t($d['text']);
        if (isset($d['nr'])) { $text = sprintf($text, (int) $d['nr']); }
        $text = trim(strip_tags(html_entity_decode($text, ENT_QUOTES, 'UTF-8')));
        if ($d['einheit'] !== '') { $text .= ' [' . $d['einheit'] . ']'; }
        $cmds[] = array(
            'title' => 'EVCC_' . strtoupper($name),
            'comment' => $text,
            'check' => '\i' . strtoupper($name) . '=\i\v',
            'analog' => $d['analog'], 'min' => $d['min'], 'max' => $d['max'],
        );
    }
    return array('VI_evcc.xml', ev_xml_virtual_in_http(array(
        'title'   => 'EVCC',
        'address' => ev_endpunkt('status'),
        'polling' => '30',
        'comment' => 'Erzeugt vom LoxBerry-Plugin EVCC (' . date('d.m.Y') . '). '
                   . 'Loxone Config legt beim Import neu an und ueberschreibt nichts - '
                   . 'zweimal eingelesen ergibt doppelte Bausteine.',
    ), $cmds));
}

/** Vorlage der virtuellen AUSGAENGE. Rueckgabe: array(name, inhalt) */
function ev_vorlage_aus()
{
    $cfg = ev_config();
    $basis = ev_endpunkt('', true);
    $teile = explode('/index.php?', $basis, 2);
    $adresse = $teile[0];
    $frage = '/index.php?token=' . $cfg['aktionstoken'] . '&aktion=';

    $cmds = array();
    $wert = function ($aktion, $titel, $zusatz = '') use (&$cmds, $frage) {
        $cmds[] = array(
            'title' => 'EVCC_' . strtoupper(str_replace('&', '_', $aktion . $zusatz)),
            'comment' => trim(strip_tags(html_entity_decode(ev_t($titel), ENT_QUOTES, 'UTF-8'))),
            'analog' => true,
            'on' => $frage . $aktion . $zusatz . '&wert=<v.0>',
        );
    };
    $schalter = function ($aktion, $titel, $an, $aus, $zusatz = '') use (&$cmds, $frage) {
        $cmds[] = array(
            'title' => 'EVCC_' . strtoupper(str_replace('&', '_', $aktion . $zusatz)),
            'comment' => trim(strip_tags(html_entity_decode(ev_t($titel), ENT_QUOTES, 'UTF-8'))),
            'analog' => false,
            'on' => $frage . $aktion . $zusatz . '&wert=' . $an,
            'off' => $frage . $aktion . $zusatz . '&wert=' . $aus,
        );
    };

    for ($i = 1; $i <= (int) $cfg['ladepunkte']; $i++) {
        $lp = '&lp=' . $i;
        $wert('modus', 'AUS.MODUS', $lp);
        $wert('limitsoc', 'AUS.LIMITSOC', $lp);
        $wert('minsoc', 'AUS.MINSOC', $lp);
        $wert('phasen', 'AUS.PHASEN', $lp);
        $wert('minstrom', 'AUS.MINSTROM', $lp);
        $wert('maxstrom', 'AUS.MAXSTROM', $lp);
        $wert('prioritaet', 'AUS.PRIORITAET', $lp);
        $wert('smartcostlimit', 'AUS.SMARTCOSTLIMIT', $lp);
        $schalter('batterieboost', 'AUS.BATTERIEBOOST', 1, 0, $lp);
    }
    $wert('batteriemodus', 'AUS.BATTERIEMODUS');
    $wert('prioritaetssoc', 'AUS.PRIORITAETSSOC');
    $wert('puffersoc', 'AUS.PUFFERSOC');
    $wert('residualleistung', 'AUS.RESIDUALLEISTUNG');
    $schalter('entladeregelung', 'AUS.ENTLADEREGELUNG', 1, 0);

    return array('VQ_evcc.xml', ev_xml_virtual_out(array(
        'title'   => 'EVCC Befehle',
        'address' => $adresse,
        'comment' => 'Schreibende Befehle muessen im Reiter Einstellungen freigegeben sein. '
                   . 'Erzeugt vom LoxBerry-Plugin EVCC (' . date('d.m.Y') . ').',
    ), $cmds));
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini
 * immer vollstaendig sein.
 * ================================================================== */

function ev_sprache()
{
    $s = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $s = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $s = getenv('LBLANG');
    }
    $s = strtolower(substr((string) $s, 0, 2));
    return in_array($s, array('de', 'en'), true) ? $s : 'en';
}

function ev_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $p = ev_paths();
        $pfad = $p['home'] . '/templates/plugins/' . $p['plugin'] . '/lang';
        if (!is_dir($pfad)) {
            // Nicht installiert (Entwicklung): neben dem Plugin nachsehen.
            $pfad = dirname(dirname(__DIR__)) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . ev_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        // INI_SCANNER_RAW liefert die Anfuehrungszeichen mit zurueck, in die
        // die Werte in der Datei stehen muessen. Die gehoeren nicht ins Bild.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) { $texte[$ab][$s] = trim((string) $w, '"'); }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}
