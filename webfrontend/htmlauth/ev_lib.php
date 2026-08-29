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
            /* Die Zweitschrift liegt NEBEN dem Plugin-Ordner, nicht darin.
             * LoxBerry entfernt config/plugins/<ordner>/ bei Deinstallation
             * und Neuinstallation - eine Sicherung im Ordner stirbt also
             * genau in dem Fall mit, fuer den es sie gibt. So halten es auch
             * Weissware, Kodi und die uebrigen 18 Linien mit Zweitschrift.
             * 'sicherung_alt' ist der frueher benutzte Ort; er wird beim
             * Heilen weiter gelesen, damit bestehende Anlagen ihre
             * vorhandene Sicherung nicht verlieren. */
            'sicherung' => $home . '/config/plugins/' . $plugin . '.backup.evcc.json',
            'sicherung_alt' => $home . '/config/plugins/' . $plugin . '/evcc.backup.json',
            'configdir' => $home . '/config/plugins/' . $plugin,
            'datadir'      => $home . '/data/plugins/' . $plugin,
            'log'       => $home . '/log/plugins/' . $plugin . '/evcc.log',
            'tmp'       => '/tmp/' . $plugin,
        );
    }
    $eigen = dirname(dirname(__DIR__));
    return array(
        'home' => '', 'plugin' => 'evcc',
        'config' => $eigen . '/config/evcc.json',
        'sicherung' => $eigen . '/config/evcc.backup.json',
        'sicherung_alt' => $eigen . '/config/evcc.backup.json',
        'configdir' => $eigen . '/config',
        'datadir' => sys_get_temp_dir() . '/evcc',
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

function ev_log($text)
{
    $p = ev_paths();
    $d = dirname($p['log']);
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    clearstatcache(true, $p['log']);
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
        // Ab Werk AUS. Ein Update startet EVCC neu und unterbricht eine
        // laufende Ladung; das schaltet niemand ungefragt ein.
        'update_ein'     => 0,
    );
}

/**
 * Die Konfiguration lesen.
 *
 * $erzeugen = false verlangt, dass NICHTS geschrieben wird. Der unangemeldete
 * Endpunkt ruft so auf: bis 0.9.10 legte ein Aufruf OHNE Token die
 * Konfigurationsdatei, die Sperrdatei und die Zweitschrift an - gemessen mit
 * leerem Ordner, drei neue Dateien nach einer Anfrage, die mit 403 endete.
 * Wer nicht angemeldet ist, darf nichts anlegen.
 *
 * ZUR SELBSTHEILUNG - der teuerste Fehler der Fassung 0.9.10:
 * Dort galt nur '' und '{}' als heilungsbeduerftig. Eine ABGESCHNITTENE Datei
 * - Stromausfall mitten im Schreiben - ergab json_decode() === null, daraus
 * wurde array(), daraus per array_merge die Werkseinstellung. Weil das Token
 * damit leer war, wurde sofort zurueckgeschrieben, und ev_config_write()
 * kopierte die Werkseinstellung UEBER die intakte Zweitschrift. Gemessen am
 * 17.08.2026: EVCC-Passwort weg, Token neu (alle Loxone-Adressen ungueltig),
 * Zweitschrift mit vernichtet, kein Wort im Protokoll.
 *
 * Jetzt gilt: ungueltiges JSON ist ein FEHLER, kein leerer Wert. Es wird
 * protokolliert, die Zweitschrift wird GELESEN statt kopiert, und die
 * beschaedigte Datei bleibt als .kaputt liegen, damit man nachsehen kann.
 */
function ev_config($erzeugen = true)
{
    $p = ev_paths();
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    $cfg = null;
    $ziehen = false;
    $ev_geholt = '';

    if ($roh === '' || $roh === '{}') {
        $ziehen = true;                     // fehlt oder leer - der harmlose Fall
    } else {
        $cfg = json_decode($roh, true);
        if (!is_array($cfg)) {
            $cfg = null;
            $ziehen = true;
            ev_log('FEHLER: ' . $p['config'] . ' ist kein gueltiges JSON ('
                 . json_last_error_msg() . ', ' . strlen($roh) . ' Byte). Die '
                 . 'Zweitschrift wird gelesen; die beschaedigte Datei bleibt '
                 . 'als .kaputt liegen.');
            if ($erzeugen && !is_file($p['config'] . '.kaputt')) {
                @copy($p['config'], $p['config'] . '.kaputt');
            }
        }
    }

    if ($ziehen && $cfg === null) {
        /* Zweitschrift ziehen - zuerst am heutigen Ort (neben dem
         * Plugin-Ordner), dann am frueheren Ort darin, sonst verloere eine
         * bestehende Anlage beim Update ihre vorhandene Sicherung.
         * GELESEN, nicht kopiert: zurueckgeschrieben wird erst durch
         * ev_config_write(), und zwar erst nach gelungenem Lesen. */
        foreach (array($p['sicherung'], $p['sicherung_alt']) as $ev_quelle) {
            if ($ev_quelle !== '' && is_file($ev_quelle)) {
                $ev_s = json_decode((string) @file_get_contents($ev_quelle), true);
                if (is_array($ev_s) && $ev_s) {
                    $cfg = $ev_s;
                    $ev_geholt = $ev_quelle;
                    break;
                }
            }
        }
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
    $cfg['update_ein'] = empty($cfg['update_ein']) ? 0 : 1;
    $cfg['mqtt_topic'] = preg_replace('#[^A-Za-z0-9_/\-]#', '', (string) $cfg['mqtt_topic']);
    if ($cfg['mqtt_topic'] === '') { $cfg['mqtt_topic'] = 'evcc2lox'; }

    // Token beim ersten Mal selbst erzeugen und gleich sichern.
    //
    // Mit Sperre. Beim ersten Aufruf nach der Einrichtung koennen die
    // Oberflaeche, der Cron-Abruf und der Miniserver-Endpunkt gleichzeitig
    // hier ankommen. Ohne Sperre erzeugt jeder ein eigenes Token und
    // ueberschreibt die anderen - wer sich das Token vorher aus der
    // Oberflaeche abgeschrieben hat, haelt danach ein ungueltiges in der Hand.
    if (!preg_match('/^[A-Za-z0-9]{24,}$/', (string) $cfg['aktionstoken']) && $erzeugen) {
        @mkdir($p['configdir'], 0775, true);
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

    /* Die Zweitschrift EINMAL zurueckschreiben - und einmal melden.
     *
     * 0.9.11 hat sie nur gelesen. Damit fehlte evcc.json dauerhaft, jeder
     * Aufruf zog erneut die Zweitschrift, und jeder Aufruf schrieb eine
     * Protokollzeile. Gemessen: fuenf Aufrufe, fuenf Zeilen, Datei nicht
     * wiederhergestellt - und ev_config() laeuft je Endpunktaufruf mehrfach,
     * der Cron viermal die Minute. Das ist ein Rueckschritt gegenueber
     * 0.9.10, das die Datei einmal kopiert hat und danach Ruhe gab.
     *
     * Zurueckgeschrieben wird nur, wo Schreiben erlaubt ist: der unangemeldete
     * Endpunkt legt weiterhin nichts an, er arbeitet mit dem gelesenen Stand.
     * Gemeldet wird ueber ev_log_wenn_neu - der Merker in /tmp haelt auch die
     * naechsten Prozesse still. */
    if ($ev_geholt !== '') {
        ev_log_wenn_neu('zweitschrift', 'Konfiguration aus der Zweitschrift '
            . $ev_geholt . ' geholt' . ($erzeugen ? ' und wiederhergestellt.' : '.'));
        if ($erzeugen && !is_file($p['config'])) {
            ev_config_write($cfg);
        }
    }
    return $cfg;
}

/**
 * Die Konfiguration schreiben - ueber eine Nebendatei.
 *
 * Bis 0.9.10 ging das mit einem einzelnen file_put_contents auf die Zieldatei.
 * Ein Abbruch mittendrin (Stromausfall, volle Karte) hinterliess genau die
 * halbe Datei, an der sich ev_config() dann verschluckt hat. Mit
 * Nebendatei + rename() gibt es nur zwei Zustaende: alte Datei oder neue.
 * Ein halb geschriebener Stand kann nicht mehr entstehen.
 */
function ev_config_write($cfg)
{
    $p = ev_paths();
    @mkdir($p['configdir'], 0775, true);
    $js = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
    // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
    if ($js === false) { return false; }

    $neben = $p['config'] . '.neu';
    if (@file_put_contents($neben, $js, LOCK_EX) === false) { return false; }
    @chmod($neben, 0600);
    if (!@rename($neben, $p['config'])) {
        @unlink($neben);
        return false;
    }
    // Die Datei traegt Token und moeglicherweise das EVCC-Passwort.
    @chmod($p['config'], 0600);

    /* Die Zweitschrift wird erst JETZT erneuert - nach einem vollstaendig
     * geschriebenen Ziel. Bis 0.9.10 wurde sie auch dann ueberschrieben, wenn
     * der geschriebene Stand aus blossen Vorgaben bestand, weil die
     * Konfiguration unlesbar war. Damit war genau die Rettung weg, fuer die
     * es die Zweitschrift gibt. */
    $sneben = $p['sicherung'] . '.neu';
    if (@file_put_contents($sneben, $js, LOCK_EX) !== false) {
        @chmod($sneben, 0600);
        if (@rename($sneben, $p['sicherung'])) { @chmod($p['sicherung'], 0600); }
        else { @unlink($sneben); }
    }
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
/**
 * Einen Betriebssystemfehler in einen Satz uebersetzen, der weiterhilft.
 *
 * Der nackte Text hilft niemandem: 'Connection refused' heisst, der Rechner
 * ist da und EVCC laeuft nicht - eine Zeitueberschreitung heisst, es antwortet
 * ueberhaupt nichts, und 'No route to host' heisst, es gibt keinen Weg dorthin.
 * Drei verschiedene Ursachen, drei verschiedene Handgriffe.
 */
function ev_netzfehler($text, $url)
{
    $t = strtolower((string) $text);
    $wirt = parse_url($url, PHP_URL_HOST) . ':' . (parse_url($url, PHP_URL_PORT) ?: 80);
    if (strpos($t, 'refused') !== false || strpos($t, 'verweigert') !== false) {
        return 'Verbindung abgewiesen (' . $wirt . ') - der Rechner ist erreichbar, '
             . 'aber auf diesem Port lauscht nichts. Laeuft EVCC? Stimmt der Port?';
    }
    if (strpos($t, 'timed out') !== false || strpos($t, 'timeout') !== false
        || strpos($t, 'zeit') !== false) {
        return 'Zeitueberschreitung (' . $wirt . ') - es antwortet nichts. '
             . 'Adresse falsch, Rechner aus, oder eine Firewall verschluckt es.';
    }
    if (strpos($t, 'no route') !== false || strpos($t, 'unreachable') !== false) {
        return 'Kein Weg zu ' . $wirt . ' - falsches Netz oder falsche Adresse.';
    }
    if (strpos($t, 'resolve') !== false || strpos($t, 'not known') !== false
        || strpos($t, 'getaddrinfo') !== false) {
        return 'Name nicht aufloesbar (' . $wirt . ') - Schreibfehler im '
             . 'Rechnernamen, oder der DNS antwortet nicht.';
    }
    return $text !== '' ? (string) $text : 'keine Antwort von ' . $wirt;
}

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
        $typ = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($body === false) {
            return array('ok' => 0, 'code' => 0, 'body' => '', 'typ' => '',
                         'fehler' => ev_netzfehler($err, $url));
        }
        return array('ok' => ($code >= 200 && $code < 300) ? 1 : 0, 'code' => $code,
                     'body' => (string) $body, 'typ' => (string) $typ,
                     'fehler' => ($code >= 200 && $code < 300) ? ''
                                 : ('HTTP ' . $code . ' von ' . $url));
    }

    $ctx = stream_context_create(array('http' => array(
        'method' => $methode, 'timeout' => $zeit, 'ignore_errors' => true,
        'header' => implode("\r\n", $kopf),
        'content' => $rumpf === null ? '' : $rumpf,
    )));
    // Der Grund steckt in der unterdrueckten Warnung - ohne ihn sind
    // ECONNREFUSED, Zeitueberschreitung und EHOSTUNREACH ununterscheidbar,
    // und genau das stand bis 0.9.10 als blosses 'keine Antwort' im Protokoll.
    $vorher = error_get_last();
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    $typ = '';
    if (isset($http_response_header) && is_array($http_response_header)) {
        if (isset($http_response_header[0])
            && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        foreach ($http_response_header as $ev_z) {
            if (stripos($ev_z, 'content-type:') === 0) { $typ = trim(substr($ev_z, 13)); }
        }
    }
    if ($body === false) {
        $nachher = error_get_last();
        $grund = ($nachher && $nachher !== $vorher) ? (string) $nachher['message'] : '';
        return array('ok' => 0, 'code' => $code, 'body' => '', 'typ' => '',
                     'fehler' => ev_netzfehler($grund, $url));
    }
    return array('ok' => ($code >= 200 && $code < 300) ? 1 : 0, 'code' => $code,
                 'body' => (string) $body, 'typ' => $typ,
                 'fehler' => ($code >= 200 && $code < 300) ? ''
                             : ('HTTP ' . $code . ' von ' . $url));
}

/**
 * Zustand von EVCC holen. Cache: eine halbe Taktlaenge, damit mehrere
 * Aufrufe in derselben Sekunde EVCC nicht mehrfach befragen.
 *
 * Rueckgabe: array('ok','stand','fehler','roh' => <dekodiertes JSON>)
 */
function ev_state($force = false, $hoechstalter = null)
{
    $cfg = ev_config();
    $cache = ev_tmpdir() . '/state.json';
    /* $hoechstalter erlaubt es dem Miniserver-Endpunkt, sich mit einem
     * aelteren Stand zufriedenzugeben.
     *
     * Bis 0.9.10 galt fest takt/2 - bei Takt 15 also 7 Sekunden. Die erzeugte
     * Loxone-Vorlage fragt aber alle 30 Sekunden. Damit war der Stand bei
     * JEDER Abfrage abgelaufen, und der Endpunkt stellte selbst eine
     * HTTP-Anfrage mit bis zu 8 s Zeitgrenze - obwohl direkt darueber steht,
     * er lese nur den Zwischenspeicher. Der Abrufdienst fuellt ihn ohnehin
     * jede Taktlaenge; der Endpunkt darf also warten. */
    $alter = $hoechstalter !== null
        ? max(2, (int) $hoechstalter)
        : max(2, (int) floor($cfg['takt'] / 2));
    if (!$force && is_file($cache) && (time() - filemtime($cache)) < $alter) {
        $c = json_decode((string) file_get_contents($cache), true);
        if (is_array($c) && isset($c['ok'])) { return $c; }
    }
    $a = ev_http('/api/state');
    $st = array('ok' => 0, 'stand' => 0, 'fehler' => '', 'fehlernr' => 0, 'roh' => array());
    if (!$a['ok']) {
        $st['fehler'] = $a['fehler'];
        // 1 = es kam gar keine Antwort, 3 = EVCC hat mit einem Fehlercode
        // geantwortet. Der Unterschied ist fuer die Fehlersuche entscheidend
        // und geht in FEHLER_NR nach Loxone.
        $st['fehlernr'] = ((int) $a['code'] === 0) ? 1 : 3;
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
                $c['fehlernr'] = $st['fehlernr'];
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
        /* Kommt HTML statt JSON zurueck, hat eine Zwischenstelle geantwortet
         * und nicht EVCC. Das gehoert in die Meldung - sonst sucht man den
         * Fehler beim Passwort, das laengst stimmt. Bis 0.9.10 stand hier
         * nur 'Antwort ist kein JSON', ohne Code, ohne Typ, ohne Probe. */
        $ev_kopf = trim(substr(preg_replace('/\s+/', ' ', (string) $a['body']), 0, 80));
        $ev_typ = isset($a['typ']) ? (string) $a['typ'] : '';
        $st['fehler'] = 'Antwort ist kein JSON (HTTP ' . (int) $a['code']
            . ($ev_typ !== '' ? ', Content-Type ' . $ev_typ : '') . ')'
            . (stripos($ev_typ, 'html') !== false || stripos($ev_kopf, '<html') !== false
               ? ' - es hat eine Zwischenstelle geantwortet, nicht EVCC.' : '')
            . ($ev_kopf !== '' ? ' Anfang: ' . $ev_kopf : '');
        $st['fehlernr'] = 2;
        ev_log_wenn_neu('abruf', $st['fehler']);
        // Auch dieser Stand gehoert in den Zwischenspeicher: sonst fragt jeder
        // Aufruf erneut und laeuft erneut in die Zeitgrenze.
        @file_put_contents($cache, json_encode($st));
        return $st;
    }
    // EVCC verpackt den Zustand je nach Fassung in 'result'. Beides annehmen.
    if (isset($d['result']) && is_array($d['result'])) { $d = $d['result']; }
    /* Die vom Plugin errechneten Zusatzwerte (Preisvorschau, Prognose,
     * Statistik) aus dem alten Stand mitnehmen: sie werden nur vom
     * Abrufdienst erneuert, nicht bei jedem Lesen. */
    $ev_alt = is_file($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
    if (is_array($ev_alt) && isset($ev_alt['roh']['lox'])) { $d['lox'] = $ev_alt['roh']['lox']; }
    $st = array('ok' => 1, 'stand' => time(), 'fehler' => '', 'fehlernr' => 0, 'roh' => $d);
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
            // preg_match statt ctype_digit: ctype_* steckt in einer Erweiterung,
            // die nicht garantiert geladen ist. Diese Stelle liegt im Pfad des
            // Loxone-Endpunkts - ein 'undefined function' toetet ihn dort still.
            } elseif (is_array($wert) && preg_match('/^[0-9]+$/', $teil) && array_key_exists((int) $teil, $wert)) {
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
 *   quelle   'bestand' = seit 0.9.x im Betrieb
 *            'doku'    = in 0.9.11 aus der EVCC-Dokumentation ergaenzt und an
 *                        KEINER Anlage gemessen. Der Reiter Test zaehlt diese
 *                        getrennt und zeigt, welche sich wirklich aufloesen.
 *                        Ein Feld, das niemand gemessen hat, darf nicht
 *                        aussehen wie eines, das jemand gemessen hat - so
 *                        haelt es BatterieBMS mit 'quelle' und 'stand'.
 *   zeile    1 = geht in die Statuszeile und in die Loxone-Vorlage (Vorgabe)
 *            0 = nur ueber MQTT und aktion=json. Fuer TEXTE: ein Semikolon
 *                oder ein Gleichheitszeichen im Wert wuerde die Statuszeile
 *                zerlegen, und Loxone saehe nur noch den Anfang.
 *
 * Fehlt eine der beiden Angaben, ergaenzt sie die Schlussschleife der
 * Funktion - so muessen die 40 Eintraege des Bestandes nicht angefasst werden.
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
        case 'batteriemodus':
            // Wie beim Lademodus: Text nach Zahl, damit ein Analogeingang
            // genuegt. Die Zuordnung ist dieselbe wie beim Befehl.
            $k = array('normal' => 0, 'hold' => 1, 'charge' => 2);
            $t = strtolower(trim((string) $wert));
            return isset($k[$t]) ? $k[$t] : (is_numeric($wert) ? (int) $wert : 0);
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

    /* ---- Rueckmeldung der schreibbaren Anlagengroessen (Vorschlag B) ----
     *
     * Bis 0.9.10 gingen 15 Befehle hinaus und nur 7 kamen als Wert zurueck.
     * Ohne Gegenstueck kann Loxone nicht erkennen, ob ein Befehl gewirkt hat -
     * der Baustein sendet dann dauernd nach oder zeigt einen Stand, den es
     * nicht gibt. */
    $f['batteriemodus_nr'] = array(
        'pfade' => array('batteryMode'),
        'typ' => 'batteriemodus', 'analog' => 1, 'min' => 0, 'max' => 2, 'einheit' => '',
        'text' => 'FELD.BATTERIEMODUS', 'mqtt' => 'evcc/site/batteryMode', 'quelle' => 'doku');
    $f['residualleistung_w'] = array(
        'pfade' => array('residualPower'),
        'typ' => 'zahl', 'analog' => 1, 'min' => -10000, 'max' => 10000, 'einheit' => 'W',
        'text' => 'FELD.RESIDUALLEISTUNG', 'mqtt' => 'evcc/site/residualPower', 'quelle' => 'doku');
    $f['entladeregelung'] = array(
        'pfade' => array('batteryDischargeControl'),
        'typ' => 'bool', 'analog' => 0, 'min' => 0, 'max' => 1, 'einheit' => '',
        'text' => 'FELD.ENTLADEREGELUNG', 'mqtt' => 'evcc/site/batteryDischargeControl', 'quelle' => 'doku');

    /* ---- Zaehlerstaende (Vorschlag C4) ----
     * Der Loxone-Energiemonitor rechnet aus Momentanleistungen sonst selbst
     * und driftet. Ein Zaehlerstand driftet nicht. */
    $f['netz_bezug_kwh'] = array(
        'pfade' => array('grid.energy', 'gridEnergy'),
        'typ' => 'komma3', 'analog' => 1, 'min' => 0, 'max' => 1000000, 'einheit' => 'kWh',
        'text' => 'FELD.NETZ_BEZUG', 'mqtt' => 'evcc/site/grid/energy', 'quelle' => 'doku');
    $f['pv_ertrag_kwh'] = array(
        'pfade' => array('pvEnergy'),
        'typ' => 'komma3', 'analog' => 1, 'min' => 0, 'max' => 1000000, 'einheit' => 'kWh',
        'text' => 'FELD.PV_ERTRAG', 'mqtt' => 'evcc/site/pvEnergy', 'quelle' => 'doku');
    $f['speicher_kapazitaet_kwh'] = array(
        'pfade' => array('batteryCapacity'),
        'typ' => 'komma3', 'analog' => 1, 'min' => 0, 'max' => 1000, 'einheit' => 'kWh',
        'text' => 'FELD.SPEICHER_KAPAZITAET', 'mqtt' => 'evcc/site/batteryCapacity', 'quelle' => 'doku');
    $f['zusatz_kw'] = array(
        'pfade' => array('auxPower'),
        'typ' => 'kw', 'analog' => 1, 'min' => -100, 'max' => 100, 'einheit' => 'kW',
        'text' => 'FELD.ZUSATZ_KW', 'mqtt' => 'evcc/site/auxPower', 'quelle' => 'doku');

    /* ---- Solarprognose (Vorschlag C5) ----
     * Der klassische Ausloeser fuer "den Speicher heute Nacht nicht aus dem
     * Netz laden". Wird vom Abrufdienst unter 'lox' abgelegt, siehe
     * ev_zusatz_holen(). */
    /* OHNE '_kwh' im Namen und ohne Umrechnung: in welcher Einheit EVCC die
     * Prognose liefert (Wh oder kWh), hat niemand gemessen. Ein Feldname, der
     * eine Einheit behauptet, sieht aus wie eine Messung und ist keine. Der
     * Wert geht durch, wie er kommt; der Reiter Test zeigt ihn roh, und der
     * Hilfetext sagt, dass er einmal gegen die EVCC-Oberflaeche zu halten
     * ist. Die Grenzen sind deshalb weit genug fuer beide Einheiten. */
    $f['prognose_heute'] = array(
        'pfade' => array('lox.prognose.heute'),
        'typ' => 'komma3', 'analog' => 1, 'min' => 0, 'max' => 1000000, 'einheit' => '',
        'text' => 'FELD.PROGNOSE_HEUTE', 'mqtt' => '', 'quelle' => 'doku');
    $f['prognose_morgen'] = array(
        'pfade' => array('lox.prognose.morgen'),
        'typ' => 'komma3', 'analog' => 1, 'min' => 0, 'max' => 1000000, 'einheit' => '',
        'text' => 'FELD.PROGNOSE_MORGEN', 'mqtt' => '', 'quelle' => 'doku');
    $f['prognose_uebermorgen'] = array(
        'pfade' => array('lox.prognose.uebermorgen'),
        'typ' => 'komma3', 'analog' => 1, 'min' => 0, 'max' => 1000000, 'einheit' => '',
        'text' => 'FELD.PROGNOSE_UEBERMORGEN', 'mqtt' => '', 'quelle' => 'doku');

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

        /* ---- Preisvorschau (Vorschlag C6) ----
         *
         * Bis 0.9.10 gab es nur den Momentanpreis. In Loxone gebraucht werden
         * die abgeleiteten Zahlen: wie tief geht es noch, wie hoch, und wo
         * steht die laufende Stunde im Vergleich. Genau die liefern
         * Spotpreis-aWATTar und Tibber; EVCC hat den Verlauf, das Plugin hat
         * ihn bis 0.9.10 nicht angesehen.
         *
         * PREIS_RANG ist die eigentlich nuetzliche Zahl: 1 = die guenstigste
         * der bekannten Stunden. "Lade, wenn Rang <= 6" ist ein einziger
         * Vergleichsbaustein - ohne jede Preisautomatik in Loxone. */
        $f['preis_min_24h'] = array(
            'pfade' => array('lox.preis.min'),
            'typ' => 'komma3', 'analog' => 1, 'min' => -10, 'max' => 10, 'einheit' => '/kWh',
            'text' => 'FELD.PREIS_MIN', 'mqtt' => '', 'quelle' => 'doku');
        $f['preis_max_24h'] = array(
            'pfade' => array('lox.preis.max'),
            'typ' => 'komma3', 'analog' => 1, 'min' => -10, 'max' => 10, 'einheit' => '/kWh',
            'text' => 'FELD.PREIS_MAX', 'mqtt' => '', 'quelle' => 'doku');
        $f['preis_schnitt_24h'] = array(
            'pfade' => array('lox.preis.schnitt'),
            'typ' => 'komma3', 'analog' => 1, 'min' => -10, 'max' => 10, 'einheit' => '/kWh',
            'text' => 'FELD.PREIS_SCHNITT', 'mqtt' => '', 'quelle' => 'doku');
        $f['preis_rang'] = array(
            'pfade' => array('lox.preis.rang'),
            'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 48, 'einheit' => '',
            'text' => 'FELD.PREIS_RANG', 'mqtt' => '', 'quelle' => 'doku');
        $f['preis_stunden'] = array(
            'pfade' => array('lox.preis.anzahl'),
            'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 48, 'einheit' => '',
            'text' => 'FELD.PREIS_STUNDEN', 'mqtt' => '', 'quelle' => 'doku');
        $f['preis_guenstigste_stunde'] = array(
            'pfade' => array('lox.preis.beste_stunde'),
            'typ' => 'zahl', 'analog' => 1, 'min' => -1, 'max' => 23, 'einheit' => 'h',
            'text' => 'FELD.PREIS_BESTE', 'mqtt' => '', 'quelle' => 'doku');
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

        /* ---- Rueckmeldung der schreibbaren Ladepunktgroessen (Vorschlag B) ---- */
        $f[$lp . 'min_soc'] = array(
            'pfade' => array("loadpoints.$j.minSoc"),
            'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 100, 'einheit' => '%',
            'text' => 'FELD.LP_MIN_SOC', 'nr' => $i, 'mqtt' => $m . 'minSoc', 'quelle' => 'doku');
        $f[$lp . 'minstrom_a'] = array(
            'pfade' => array("loadpoints.$j.effectiveMinCurrent", "loadpoints.$j.minCurrent"),
            'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 80, 'einheit' => 'A',
            'text' => 'FELD.LP_MINSTROM', 'nr' => $i, 'mqtt' => $m . 'minCurrent', 'quelle' => 'doku');
        $f[$lp . 'maxstrom_a'] = array(
            'pfade' => array("loadpoints.$j.effectiveMaxCurrent", "loadpoints.$j.maxCurrent"),
            'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 80, 'einheit' => 'A',
            'text' => 'FELD.LP_MAXSTROM', 'nr' => $i, 'mqtt' => $m . 'maxCurrent', 'quelle' => 'doku');
        $f[$lp . 'smartcost_grenze'] = array(
            'pfade' => array("loadpoints.$j.smartCostLimit"),
            'typ' => 'komma3', 'analog' => 1, 'min' => -10, 'max' => 10, 'einheit' => '/kWh',
            'text' => 'FELD.LP_SMARTCOST_GRENZE', 'nr' => $i, 'mqtt' => $m . 'smartCostLimit', 'quelle' => 'doku');
        $f[$lp . 'batterieboost'] = array(
            'pfade' => array("loadpoints.$j.batteryBoost"),
            'typ' => 'bool', 'analog' => 0, 'min' => 0, 'max' => 1, 'einheit' => '',
            'text' => 'FELD.LP_BATTERIEBOOST', 'nr' => $i, 'mqtt' => $m . 'batteryBoost', 'quelle' => 'doku');
        // phasesActive sind die LAUFENDEN Phasen, phasesConfigured ist die
        // EINGESTELLTE Vorgabe. Beides ist interessant, es ist aber nicht
        // dasselbe - bis 0.9.10 gab es nur das erste, und der Befehl 'phasen'
        // hatte damit keine Rueckmeldung.
        $f[$lp . 'phasen_soll'] = array(
            'pfade' => array("loadpoints.$j.phasesConfigured"),
            'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 3, 'einheit' => '',
            'text' => 'FELD.LP_PHASEN_SOLL', 'nr' => $i, 'mqtt' => $m . 'phasesConfigured', 'quelle' => 'doku');

        /* ---- Warum laedt es gerade nicht (Vorschlag C7) ----
         * EVCC zeigt in seiner eigenen Oberflaeche "warte auf PV-Ueberschuss,
         * noch 4 min". Diese beiden Zahlen sind genau das. Zusammen mit
         * MODUS_NR, VERBUNDEN und FREIGEGEBEN laesst sich der Grund in Loxone
         * ohne jede Rateregel ablesen - die Zuordnung steht im Reiter
         * Einbindung in Loxone. Einen erfundenen Sammelcode gibt es bewusst
         * NICHT: den haette niemand gemessen. */
        $f[$lp . 'pv_warten_min'] = array(
            'pfade' => array("loadpoints.$j.pvRemaining"),
            'typ' => 'minuten', 'analog' => 1, 'min' => 0, 'max' => 600, 'einheit' => 'min',
            'text' => 'FELD.LP_PV_WARTEN', 'nr' => $i, 'mqtt' => $m . 'pvRemaining', 'quelle' => 'doku');
        $f[$lp . 'phasen_warten_min'] = array(
            'pfade' => array("loadpoints.$j.phaseRemaining"),
            'typ' => 'minuten', 'analog' => 1, 'min' => 0, 'max' => 600, 'einheit' => 'min',
            'text' => 'FELD.LP_PHASEN_WARTEN', 'nr' => $i, 'mqtt' => $m . 'phaseRemaining', 'quelle' => 'doku');

        /* ---- Sitzungsdaten und Zaehlerstand (Vorschlaege C4, C9) ---- */
        $f[$lp . 'gesamt_kwh'] = array(
            'pfade' => array("loadpoints.$j.chargeTotalImport"),
            'typ' => 'komma3', 'analog' => 1, 'min' => 0, 'max' => 1000000, 'einheit' => 'kWh',
            'text' => 'FELD.LP_GESAMT', 'nr' => $i, 'mqtt' => $m . 'chargeTotalImport', 'quelle' => 'doku');
        $f[$lp . 'sitzung_kwh'] = array(
            'pfade' => array("loadpoints.$j.sessionEnergy"),
            'typ' => 'kwh', 'analog' => 1, 'min' => 0, 'max' => 1000, 'einheit' => 'kWh',
            'text' => 'FELD.LP_SITZUNG_KWH', 'nr' => $i, 'mqtt' => $m . 'sessionEnergy', 'quelle' => 'doku');
        $f[$lp . 'sitzung_preis'] = array(
            'pfade' => array("loadpoints.$j.sessionPrice"),
            'typ' => 'komma3', 'analog' => 1, 'min' => -1000, 'max' => 1000, 'einheit' => '',
            'text' => 'FELD.LP_SITZUNG_PREIS', 'nr' => $i, 'mqtt' => $m . 'sessionPrice', 'quelle' => 'doku');
        $f[$lp . 'sitzung_preis_kwh'] = array(
            'pfade' => array("loadpoints.$j.sessionPricePerKWh"),
            'typ' => 'komma3', 'analog' => 1, 'min' => -10, 'max' => 10, 'einheit' => '/kWh',
            'text' => 'FELD.LP_SITZUNG_PREIS_KWH', 'nr' => $i, 'mqtt' => $m . 'sessionPricePerKWh', 'quelle' => 'doku');
        $f[$lp . 'sitzung_co2'] = array(
            'pfade' => array("loadpoints.$j.sessionCo2PerKWh"),
            'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 1000, 'einheit' => 'g/kWh',
            'text' => 'FELD.LP_SITZUNG_CO2', 'nr' => $i, 'mqtt' => $m . 'sessionCo2PerKWh', 'quelle' => 'doku');
        $f[$lp . 'ladedauer_min'] = array(
            'pfade' => array("loadpoints.$j.chargeDuration"),
            'typ' => 'minuten', 'analog' => 1, 'min' => 0, 'max' => 6000, 'einheit' => 'min',
            'text' => 'FELD.LP_LADEDAUER', 'nr' => $i, 'mqtt' => $m . 'chargeDuration', 'quelle' => 'doku');
        $f[$lp . 'rest_kwh'] = array(
            'pfade' => array("loadpoints.$j.chargeRemainingEnergy"),
            'typ' => 'kwh', 'analog' => 1, 'min' => 0, 'max' => 1000, 'einheit' => 'kWh',
            'text' => 'FELD.LP_REST_KWH', 'nr' => $i, 'mqtt' => $m . 'chargeRemainingEnergy', 'quelle' => 'doku');
        $f[$lp . 'strom_a'] = array(
            'pfade' => array("loadpoints.$j.chargeCurrent"),
            'typ' => 'komma3', 'analog' => 1, 'min' => 0, 'max' => 80, 'einheit' => 'A',
            'text' => 'FELD.LP_STROM', 'nr' => $i, 'mqtt' => $m . 'chargeCurrent', 'quelle' => 'doku');

        /* ---- Ladeplan (Vorschlag D10) ---- */
        $f[$lp . 'plan_soc'] = array(
            'pfade' => array("loadpoints.$j.planSoc"),
            'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 100, 'einheit' => '%',
            'text' => 'FELD.LP_PLAN_SOC', 'nr' => $i, 'mqtt' => $m . 'planSoc', 'quelle' => 'doku');
        $f[$lp . 'plan_kwh'] = array(
            'pfade' => array("loadpoints.$j.planEnergy"),
            'typ' => 'kwh', 'analog' => 1, 'min' => 0, 'max' => 1000, 'einheit' => 'kWh',
            'text' => 'FELD.LP_PLAN_KWH', 'nr' => $i, 'mqtt' => $m . 'planEnergy', 'quelle' => 'doku');

        /* ---- Fahrzeugname als TEXT ----
         * Nicht in die Statuszeile: ein Semikolon im Namen zerlegte sie.
         * Ueber MQTT und aktion=json ist er da. */
        $f[$lp . 'fahrzeug_name'] = array(
            'pfade' => array("loadpoints.$j.vehicleName", "loadpoints.$j.vehicleTitle"),
            'typ' => 'text', 'analog' => 0, 'min' => 0, 'max' => 1, 'einheit' => '',
            'text' => 'FELD.LP_FZ_NAME', 'nr' => $i, 'mqtt' => $m . 'vehicleName',
            'quelle' => 'doku', 'zeile' => 0);
    }

    /* ---- Fahrzeuge. In /api/state ist 'vehicles' ein Objekt mit dem
            Fahrzeugnamen als Schluessel - eine Nummer gibt es dort nicht.
            Deshalb wird zur Laufzeit aufgeloest (ev_fahrzeugnamen). ---- */
    $namen = ev_fahrzeugnamen();
    for ($i = 1; $i <= (int) $cfg['fahrzeuge']; $i++) {
        $name = isset($namen[$i - 1]) ? $namen[$i - 1] : '';
        $fz = 'fz' . $i . '_';
        /* Kein Fahrzeug an dieser Stelle: Felder trotzdem anlegen, damit die
         * Vorlage stabil bleibt - aber ohne Pfad, sie liefern dann 0.
         *
         * Bis 0.9.10 war das nur die halbe Wahrheit: der Zweig legte
         * fz*_limit_soc NICHT an, der Zweig mit Namen schon. Gemessen mit
         * zwei Fahrzeugen: 35 Befehle in der Vorlage ohne Zwischenspeicher,
         * 37 mit. Und der Zwischenspeicher liegt in /tmp, auf dem LoxBerry
         * eine Ramdisk - nach jedem Neustart erzeugte der erste Export die
         * kurze Fassung, ohne dass es jemandem aufgefallen waere. Jetzt
         * entstehen in beiden Zweigen dieselben drei Felder. */
        if ($name === '') {
            $f[$fz . 'soc'] = array('pfade' => array(), 'typ' => 'zahl', 'analog' => 1,
                'min' => 0, 'max' => 100, 'einheit' => '%', 'text' => 'FELD.FZ_SOC', 'nr' => $i, 'mqtt' => '');
            $f[$fz . 'km'] = array('pfade' => array(), 'typ' => 'zahl', 'analog' => 1,
                'min' => 0, 'max' => 2000, 'einheit' => 'km', 'text' => 'FELD.FZ_KM', 'nr' => $i, 'mqtt' => '');
            $f[$fz . 'limit_soc'] = array('pfade' => array(), 'typ' => 'zahl', 'analog' => 1,
                'min' => 0, 'max' => 100, 'einheit' => '%', 'text' => 'FELD.FZ_LIMIT_SOC', 'nr' => $i, 'mqtt' => '');
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

    /* ---- Ausfallerkennung (Vorschlag E14) ----
     *
     * Bis 0.9.10 wurde jeder nicht aufloesbare Wert zu 0. NETZ_KW=0 sieht in
     * Loxone aus wie "gerade ausgeglichen", nicht wie "EVCC antwortet nicht".
     * OK und ALTER_S gab es, aber keinen Hinweis, WORAN es liegt.
     *
     * FEHLER_NR ist eine Zahl, weil ein Analogeingang in Loxone leichter zu
     * verdrahten ist als ein Texteingang. Die Zuordnung steht im Reiter
     * Einbindung in Loxone und in der Sprachdatei - sie ist NICHT geraten,
     * sondern hier vergeben:
     *   0 alles in Ordnung
     *   1 keine Antwort (Netz, Zeitgrenze, Verbindung abgewiesen)
     *   2 Antwort war kein JSON (meist hat eine Zwischenstelle geantwortet)
     *   3 EVCC hat mit einem Fehlercode geantwortet
     *   9 sonstiger Fehler
     */
    $f['fehler_nr'] = array('pfade' => array(), 'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 9,
        'einheit' => '', 'text' => 'FELD.FEHLER_NR', 'mqtt' => '');
    /* EVCC kann antworten und trotzdem nichts liefern - weil es mit einem
     * Startfehler abgebrochen hat oder noch nicht eingerichtet ist. Fuer
     * Loxone ist das der Unterschied zwischen "die Anlage ist ausgeglichen"
     * und "es misst niemand". OK bleibt dabei 1: die Werte SIND aktuell, es
     * gibt nur keine. Wer auf brauchbare Zahlen wartet, verknuepft OK UND
     * BETRIEBSBEREIT. */
    $f['betriebsbereit'] = array('pfade' => array(), 'typ' => 'bool', 'analog' => 0, 'min' => 0, 'max' => 1,
        'einheit' => '', 'text' => 'FELD.BETRIEBSBEREIT', 'mqtt' => '');
    $f['letzter_fehler'] = array('pfade' => array(), 'typ' => 'text', 'analog' => 0, 'min' => 0, 'max' => 1,
        'einheit' => '', 'text' => 'FELD.LETZTER_FEHLER', 'mqtt' => '', 'zeile' => 0);

    /* Vorgaben ergaenzen, damit die 40 Eintraege des Bestandes unangetastet
     * bleiben konnten. 'bestand' heisst: seit 0.9.x im Betrieb. 'doku' heisst:
     * in 0.9.11 aus der EVCC-Dokumentation ergaenzt und an keiner Anlage
     * gemessen - der Reiter Test zaehlt die getrennt. */
    foreach ($f as $ev_n => $ev_d) {
        if (!isset($f[$ev_n]['quelle'])) { $f[$ev_n]['quelle'] = 'bestand'; }
        if (!isset($f[$ev_n]['zeile'])) { $f[$ev_n]['zeile'] = 1; }
    }
    return $f;
}

/** Nur die Felder, die in die Statuszeile und in die Loxone-Vorlage gehoeren. */
function ev_felder_zeile()
{
    $out = array();
    foreach (ev_felder() as $n => $d) {
        if (!empty($d['zeile'])) { $out[$n] = $d; }
    }
    return $out;
}

/**
 * Aus einer beliebig verschachtelten Antwort ALLES Lesbare zusammensetzen.
 *
 * Die erste Fassung nahm den ERSTEN Skalar - und lieferte damit auf dem Geraet
 * nur "sponsorship" statt "sponsorship: token is expired - get a fresh one
 * from https://sponsor.evcc.io". Die Struktur von 'fatal' ist nicht
 * dokumentiert; offenbar steht die Fehlerklasse vor der Meldung. Ich hatte die
 * Form geraten.
 *
 * Die Auflaesung ist nicht besseres Raten, sondern Verlustfreiheit: jeder
 * lesbare Teil wird in der Reihenfolge genommen, in der er dasteht, und mit
 * ": " verbunden. Ist es eine blosse Zeichenkette, kommt sie unveraendert
 * heraus. Ist es {klasse, meldung}, entsteht genau der Satz, den EVCC in
 * seiner eigenen Oberflaeche zeigt. Und ist es etwas Drittes, geht trotzdem
 * nichts verloren.
 *
 * $grenze kappt sehr lange Ketten - eine Fehlermeldung soll lesbar bleiben,
 * und sie steht ohnehin ausfuehrlich in der EVCC-Oberflaeche.
 */
function ev_flach_text($v, $grenze = 400)
{
    $teile = array();
    ev_flach_sammeln($v, $teile, 0);
    $t = '';
    foreach ($teile as $s) {
        if ($s === '') { continue; }
        if ($t === '') { $t = $s; continue; }
        // Endet der bisherige Text schon auf einem Satzzeichen, kein zweites
        // dazusetzen.
        $t .= (substr($t, -1) === ':' ? ' ' : ': ') . $s;
    }
    $t = trim(preg_replace('/\s+/', ' ', $t));
    if ($grenze > 0 && strlen($t) > $grenze) {
        $t = rtrim(substr($t, 0, $grenze)) . ' [...]';
    }
    return $t;
}

/** Hilfsschleife zu ev_flach_text - sammelt die Blaetter der Reihe nach. */
function ev_flach_sammeln($v, &$teile, $tiefe)
{
    if ($tiefe > 6 || count($teile) > 20) { return; }
    if (is_string($v)) { $teile[] = trim($v); return; }
    if (is_bool($v)) { $teile[] = $v ? 'true' : 'false'; return; }
    if (is_numeric($v)) { $teile[] = (string) $v; return; }
    if (is_array($v)) {
        foreach ($v as $x) { ev_flach_sammeln($x, $teile, $tiefe + 1); }
    }
}

/**
 * Laeuft EVCC wirklich - oder antwortet es nur?
 *
 * Am 17.08.2026 an einer echten Anlage gemessen: der Dienst lief, /api/state
 * antwortete mit HTTP 200 und gueltigem JSON, und trotzdem kam kein einziger
 * Messwert an. Die Antwort enthielt 30 Schluessel, alle Konfiguration, dazu
 * 'fatal', 'setupRequired' = true und leere 'loadpoints'. Die Oberflaeche von
 * EVCC nannte den Grund: ein abgelaufenes Sponsor-Token, EVCC bricht damit den
 * Start ab.
 *
 * Bis 0.9.12 hat das Plugin daraus 97 Nullen gemacht und "alles in Ordnung"
 * gemeldet. In Loxone sieht 0 kW Netzbezug aus wie ein ausgeglichenes Haus -
 * das ist die stille Falschaussage, die die Hausregeln als schlimmste
 * Fehlerart fuehren.
 *
 * REIHENFOLGE: 'fatal' zuerst. Wer bei setupRequired anfaengt, schickt jemanden
 * in die Grundeinrichtung, dessen Konfiguration laengst steht.
 *
 * Rueckgabe:
 *   antwortet    1, wenn ueberhaupt eine Antwort kam
 *   fatal        Startfehler von EVCC im Klartext, sonst ''
 *   einrichtung  1 fertig, 0 noch noetig, -1 nicht feststellbar
 *   ladepunkte   Zahl der in EVCC gefuehrten Ladepunkte
 *   version      Fassung von EVCC, wie sie in der Antwort steht
 *   neuer        von EVCC angebotene neuere Fassung, sonst ''
 */
function ev_einrichtung($st = null)
{
    if ($st === null) { $st = ev_state(); }
    $out = array('antwortet' => !empty($st['ok']) ? 1 : 0, 'fatal' => '',
                 'einrichtung' => -1, 'ladepunkte' => 0, 'version' => '', 'neuer' => '');
    $roh = isset($st['roh']) && is_array($st['roh']) ? $st['roh'] : array();
    if (!$roh) { return $out; }

    if (isset($roh['fatal'])) {
        $out['fatal'] = ev_flach_text($roh['fatal']);
    }
    if (array_key_exists('setupRequired', $roh)) {
        $out['einrichtung'] = in_array($roh['setupRequired'], array(true, 1, '1', 'true'), true) ? 0 : 1;
    }
    if (isset($roh['loadpoints']) && is_array($roh['loadpoints'])) {
        $out['ladepunkte'] = count($roh['loadpoints']);
    }
    if (isset($roh['version']) && is_string($roh['version'])) { $out['version'] = $roh['version']; }
    if (isset($roh['availableVersion']) && is_string($roh['availableVersion'])) {
        $neu = $roh['availableVersion'];
        // Die eigene Fassung traegt einen Commit in Klammern, die angebotene
        // nicht - deshalb wird nur der Teil davor verglichen.
        $eigen = trim(strtok((string) $out['version'], ' '));
        if ($neu !== '' && $eigen !== '' && $neu !== $eigen) { $out['neuer'] = $neu; }
    }
    return $out;
}

/** Lademodus als Zahl: 0 aus, 1 sofort, 2 min+PV, 3 nur PV. *//** Lademodus als Zahl: 0 aus, 1 sofort, 2 min+PV, 3 nur PV. */
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
    // Die eigenen Felder.
    $out['ok'] = array('wert' => (int) (!empty($st['ok'])), 'pfad' => '-');
    $out['alter_s'] = array('wert' => !empty($st['stand']) ? max(0, time() - (int) $st['stand']) : 99999, 'pfad' => '-');
    $out['dienst'] = array('wert' => ev_dienst_laeuft() ? 1 : 0, 'pfad' => '-');
    $ev_nr = isset($st['fehlernr']) ? (int) $st['fehlernr'] : (empty($st['ok']) ? 9 : 0);
    $ev_ein = ev_einrichtung($st);
    // 4 = verbunden, aber EVCC ist nicht eingerichtet
    // 5 = verbunden, aber EVCC meldet einen Startfehler
    // Beides ist ein eigener Zustand: die Verbindung steht, es gibt nur nichts
    // zu holen. Der Startfehler zuerst - er ist die genauere Auskunft.
    if ($ev_nr === 0 && $ev_ein['fatal'] !== '') { $ev_nr = 5; }
    elseif ($ev_nr === 0 && $ev_ein['einrichtung'] === 0) { $ev_nr = 4; }
    $out['fehler_nr'] = array('wert' => $ev_nr, 'pfad' => '-');
    $out['betriebsbereit'] = array(
        'wert' => ($ev_ein['fatal'] === '' && $ev_ein['einrichtung'] !== 0 && !empty($st['ok'])) ? 1 : 0,
        'pfad' => '-');
    /* Der Klartext: der eigene Abruffehler, sonst der Startfehler von EVCC.
     *
     * Die erste Fassung dieser Stelle setzte den Startfehler VOR die
     * urspruengliche Zuweisung - und die hat ihn wieder ueberschrieben. Das
     * Feld blieb leer, obwohl EVCC genau sagt, was los ist. Gefunden von der
     * Eichung, nicht beim Lesen. */
    $ev_klartext = isset($st['fehler']) ? (string) $st['fehler'] : '';
    if ($ev_klartext === '' && $ev_ein['fatal'] !== '') {
        $ev_klartext = 'EVCC: ' . $ev_ein['fatal'];
    }
    $out['letzter_fehler'] = array('wert' => $ev_klartext, 'pfad' => '-');
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

/** Wo das Aktualisierungsskript liegt, das postroot.sh als root angelegt hat. */
define('EV_UPDATE_SKRIPT', '/usr/local/sbin/loxberry-evcc-update');

/** Ist das Aktualisierungsskript vorhanden? */
function ev_update_moeglich()
{
    return is_file(EV_UPDATE_SKRIPT);
}

/**
 * Welcher Sprachschluessel beschreibt den Weg zur neueren EVCC-Fassung?
 *
 * Bis 0.9.15 stand unter dem Hinweis auf eine neuere Fassung fest der Satz
 * "Das Plugin aktualisiert EVCC nicht selbst" - und zwei Zeilen darueber in
 * derselben Selbstpruefung "Kann die Oberflaeche EVCC aktualisieren? Ja".
 * Zwei Aussagen auf einer Seite, die sich widersprechen; genau die
 * Fehlerquelle, vor der REGELN_1 warnt. Der Satz haengt jetzt an dem, was
 * wirklich moeglich ist - und beide Stellen holen ihn von hier.
 *
 * Rueckgabe: '_KNOPF', '_GESPERRT' oder '' (dann der Weg ueber apt).
 */
function ev_update_weg()
{
    if (!ev_update_moeglich()) { return ''; }
    $cfg = ev_config();
    return empty($cfg['update_ein']) ? '_GESPERRT' : '_KNOPF';
}

/**
 * EVCC aktualisieren.
 *
 * Rueckgabe: array(ok, Ausgabe im Klartext).
 *
 * Das Skript nimmt keine Argumente - es gibt hier also nichts zusammenzusetzen
 * und nichts einzuschleusen. Der Rueckgabewert wird ausgewertet, nicht der
 * blosse Umstand, dass der Aufruf durchlief: apt meldet einen Misserfolg
 * ausschliesslich ueber ihn.
 *
 * Was die WIRKUNG angeht, verlaesst sich diese Funktion auf nichts: sie liest
 * die Fassung von EVCC vor und nach dem Lauf selbst noch einmal aus.
 */
function ev_update_ausfuehren()
{
    if (!ev_update_moeglich()) {
        return array(0, sprintf(ev_t('TEST.M_UPDATE_KEIN_SKRIPT'), EV_UPDATE_SKRIPT));
    }
    $vorher = ev_dienst_version();
    $aus = array();
    $rc = 0;
    @exec('sudo -n ' . EV_UPDATE_SKRIPT . ' 2>&1', $aus, $rc);
    $text = trim(implode("\n", $aus));
    $nachher = ev_dienst_version();
    ev_log('EVCC-Update: Rueckgabewert ' . $rc . ', vorher ' . $vorher . ', nachher ' . $nachher);
    if ($rc !== 0) {
        return array(0, sprintf(ev_t('TEST.M_UPDATE_FEHL'), (int) $rc, ev_e($text)));
    }
    // Der Zwischenspeicher ist nach einem Neustart von EVCC hinfaellig.
    @unlink(ev_tmpdir() . '/state.json');
    if ($vorher === $nachher) {
        return array(1, sprintf(ev_t('TEST.M_UPDATE_GLEICH'), ev_e($nachher), ev_e($text)));
    }
    return array(1, sprintf(ev_t('TEST.M_UPDATE_OK'), ev_e($vorher), ev_e($nachher), ev_e($text)));
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
        /* Der Schluessel heisst Gatewayautostart, NICHT Autostart.
         *
         * 'Autostart' gibt es in der general.json nicht. Gemessen gegen die
         * Werksvorgabe ("Gatewayautostart": 1) lieferte diese Funktion bis
         * 0.9.10 immer 0 - der Reiter Test zeigte daraufhin ein dauerhaftes
         * Kreuz und der Reiter MQTT eine Warnung, die nie zutraf. Ein rotes
         * Kreuz, das nichts bedeutet, ist schlimmer als keine Pruefung: man
         * sucht dann dort. Fuenfter Fund dieser Klasse nach Midea2Lox 4.0.0,
         * ACTiKamera 1.9.2, Abfahrtsassistent und WaermepumpeCloud. */
        foreach (array('Gatewayautostart', 'gatewayautostart') as $ak) {
            if (isset($gen[$k][$ak])) {
                $out['autostart'] = in_array((string) $gen[$k][$ak],
                    array('1', 'true'), true) ? 1 : 0;
                /* Die FASSUNG des MQTT-Gateways, ab Werk 1. Sie entscheidet, was der
                 * Anwender eintragen muss: unter V1 jedes Thema von Hand, ab V2
                 * erscheint die Themengruppe von selbst in den Subscriptions.
                 * 0 heisst "nicht feststellbar" - dann wird nichts behauptet,
                 * sondern es werden beide Faelle genannt. */
                $out['fassung'] = isset($gen[$k]['Gatewayversion'])
                    ? (int) $gen[$k]['Gatewayversion'] : 0;
            }
        }
    }
    return $out;
}

/**
 * Der Hinweis zum MQTT-Abo - in der Fassung, die zum GATEWAY passt.
 *
 * Bis hierher stand an den Ausgabestellen unbedingt "Ohne diesen Eintrag
 * kommt am Miniserver nichts an". Das gilt fuer Gateway V1, wo jedes Thema
 * von Hand einzutragen ist. Ab V2 erscheint die Themengruppe von selbst in
 * den Subscriptions - der Satz schickte jeden V2-Anwender zu einem
 * Eingabeplatz, den es nicht gibt.
 *
 * Drei Ausgaenge, nicht zwei: ist die Fassung nicht feststellbar, werden
 * BEIDE Faelle genannt statt einer behauptet.
 */
function ev_abo_text()
{
    $m = ev_mqtt_zustand();
    $f = isset($m['fassung']) ? (int) $m['fassung'] : 0;
    if ($f <= 0) {
        return ev_t('MQTT.ABO_UNBEKANNT');
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(ev_t('MQTT.ABO_GEMESSEN'), $f) . '</span>';
    return ev_t($f >= 2 ? 'MQTT.ABO_V2' : 'MQTT.ABO_WARNUNG') . $gemessen;
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
    /* Datenstrom statt socket_create.
     *
     * socket_* steckt in der Erweiterung php-sockets, die nicht garantiert
     * geladen ist - und sie stand nicht in dpkg/apt. Fehlt sie, ist das kein
     * abfangbarer Fehler, sondern ein fataler: 'Call to undefined function'.
     * Der Cron schreibt nach /dev/null, also haette man es nie gesehen.
     * stream_socket_client() ist Kernbestandteil von PHP. So haelt es auch
     * Govee, mit derselben Begruendung wortwoertlich in seiner dpkg/apt. */
    $fehl = 0;
    $grund = '';
    $sock = @stream_socket_client('udp://127.0.0.1:' . (int) $z['udpport'],
                                  $fehl, $grund, 2, STREAM_CLIENT_CONNECT);
    if (!$sock) {
        ev_log_wenn_neu('mqtt', 'UDP-Verbindung zum Gateway auf Port '
            . (int) $z['udpport'] . ' nicht moeglich: ' . $grund . ' (' . $fehl . ')');
        return 0;
    }
    $n = 0;
    foreach ($werte as $name => $d) {
        $msg = 'publish ' . ev_mqtt_thema($cfg['mqtt_topic'] . '/' . $name)
             . ' ' . ev_mqtt_nutzlast($d['wert']);
        if (@fwrite($sock, $msg) !== false) { $n++; }
    }
    fclose($sock);
    if ($n < count($werte)) {
        ev_log_wenn_neu('mqtt_teil', sprintf('nur %d von %d Themen gesendet', $n, count($werte)));
    }
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
    // Nur die Felder mit zeile = 1. Textfelder (Fahrzeugname, letzte
    // Fehlermeldung) bleiben draussen: ein Semikolon oder ein
    // Gleichheitszeichen im Wert zerlegte die Zeile, und Loxone saehe nur
    // noch den Anfang. Ueber MQTT und aktion=json sind sie da.
    foreach (ev_felder_zeile() as $name => $d) {
        if (!isset($werte[$name])) { continue; }
        $teile[] = strtoupper($name) . '=' . $werte[$name]['wert'];
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

/**
 * Virtuelle EINGAENGE.
 *
 * Gegen die Ausfuhren aus der laufenden Anlage gehalten (VI_Marstek,
 * VI_Rasenmaeher): dort tragen Wurzel und Kinder zusaetzlich HintText, die
 * Kinder eine Unit, und als erstes Kindelement steht ein <Info>. Bis 0.9.10
 * fehlte all das - 16 Linien im Bestand fuehren <Info templateType, EVCC als
 * einzige nicht. Attributreihenfolge und CRLF wie in den Mustern.
 */
function ev_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'HintText="" ';
    $o .= 'Title="' . ev_x($kopf['title']) . '" ';
    $o .= 'Comment="' . ev_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . ev_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . ev_x(isset($kopf['polling']) ? $kopf['polling'] : '30') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
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
        $o .= 'MaxVal="' . (int) $c['max'] . '" ';
        $o .= 'Unit="' . ev_x(isset($c['unit']) && $c['unit'] !== ''
                             ? '<v.1> ' . $c['unit'] : '<v.1>') . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Virtuelle AUSGAENGE.
 *
 * CmdOnMethod, CmdOffMethod, Repeat und RepeatRate fehlten bis 0.9.10
 * vollstaendig; die Ausfuhr aus der laufenden Anlage (VO_Rasenmaeher) fuehrt
 * sie alle. Reihenfolge wie in REGELN_2 beschrieben und wie in Dashboard
 * 0.9.7 umgesetzt: die Aus-Angabe steht unmittelbar hinter der Ein-Angabe.
 * (Die Geraeteausfuhr gruppiert die beiden Method-Attribute davor - fuer
 * einen XML-Leser ist die Attributreihenfolge bedeutungslos, hier gilt
 * deshalb der Hausstandard.)
 */
function ev_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut ';
    $o .= 'HintText="" ';
    $o .= 'Title="' . ev_x($kopf['title']) . '" ';
    $o .= 'Comment="' . ev_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . ev_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'CmdInit="" ';
    $o .= 'CloseAfterSend="false" ';
    $o .= 'CmdSep=""';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . ev_x($c['title']) . '" ';
        $o .= 'Comment="' . ev_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'CmdOnMethod="' . ev_x(isset($c['method']) ? $c['method'] : 'GET') . '" ';
        $o .= 'CmdOn="' . ev_x(isset($c['on']) ? $c['on'] : '') . '" ';
        $o .= 'CmdOffMethod="' . ev_x(isset($c['method']) ? $c['method'] : 'GET') . '" ';
        $o .= 'CmdOff="' . ev_x(isset($c['off']) ? $c['off'] : '') . '" ';
        $o .= 'Analog="' . (!empty($c['analog']) ? 'true' : 'false') . '" ';
        $o .= 'Repeat="0" ';
        $o .= 'RepeatRate="0" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/**
 * Adresse der EVCC-Oberflaeche, wie sie im Browser des ANWENDERS trifft.
 *
 * In der Konfiguration steht ueblicherweise http://127.0.0.1:7070 - und das
 * ist aus Sicht des LoxBerry richtig, weil EVCC dort laeuft. Ein Knopf in der
 * Oberflaeche wird aber im Browser des Anwenders geoeffnet, und dort heisst
 * 127.0.0.1 "dieser PC". Der Knopf endete deshalb ausnahmslos mit
 * ERR_CONNECTION_REFUSED. Am Geraet aufgefallen am 17.08.2026.
 *
 * Steht eine Rueckschleifen-Adresse in der Konfiguration, wird sie fuer den
 * Knopf durch den Namen ersetzt, unter dem der Anwender GERADE diese Seite
 * aufgerufen hat - den kennt der Browser mit Sicherheit. Der Port bleibt der
 * von EVCC. Die Konfiguration selbst wird NICHT angefasst: der Abruf des
 * Plugins laeuft weiter ueber 127.0.0.1, und das ist dort auch richtig.
 */
function ev_evcc_link()
{
    $cfg = ev_config();
    $url = (string) $cfg['url'];
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (!in_array($host, array('127.0.0.1', 'localhost', '::1', '0.0.0.0'), true)) {
        return $url;
    }
    $eigen = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    $eigen = preg_replace('/[^A-Za-z0-9\.\-]/', '', preg_replace('/:[0-9]+$/', '', $eigen));
    if ($eigen === '') { return $url; }
    $port = parse_url($url, PHP_URL_PORT);
    return 'http://' . $eigen . ($port ? ':' . (int) $port : '');
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
    foreach (ev_felder_zeile() as $name => $d) {
        $text = ev_t($d['text']);
        if (isset($d['nr'])) { $text = sprintf($text, (int) $d['nr']); }
        $text = trim(strip_tags(html_entity_decode($text, ENT_QUOTES, 'UTF-8')));
        if ($d['einheit'] !== '') { $text .= ' [' . $d['einheit'] . ']'; }
        if ($d['quelle'] === 'doku') { $text .= ' (neu in 0.9.11)'; }
        $cmds[] = array(
            // Das Semikolon gehoert ins Suchmuster. Jedes Feld steht in der
            // Zeile hinter einem ';' - ohne es traefe ein kuenftiger Feldname,
            // der auf einen bestehenden endet, die falsche Stelle. Gemessen
            // kollidiert heute nichts (84 von 84 eindeutig); das hier ist
            // Vorsorge fuer das 85. Feld, und drei Linien im Bestand halten
            // es schon so.
            'title' => 'EVCC_' . strtoupper($name),
            'comment' => $text,
            'check' => '\i;' . strtoupper($name) . '=\i\v',
            'analog' => $d['analog'], 'min' => $d['min'], 'max' => $d['max'],
            'unit' => $d['einheit'],
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

/**
 * Vorlage der virtuellen AUSGAENGE - erzeugt aus ev_befehle().
 *
 * Bis 0.9.10 stand die Liste hier ein drittes Mal ausgeschrieben. Wer einen
 * Befehl ergaenzte, musste an den Endpunkt, an diese Funktion und an die
 * Tabelle im Reiter Loxone denken. Jetzt kommt alles aus einer Quelle.
 *
 * Rueckgabe: array(name, inhalt)
 */
function ev_vorlage_aus()
{
    $cfg = ev_config();
    $basis = ev_endpunkt('', true);
    $teile = explode('/index.php?', $basis, 2);
    $adresse = $teile[0];
    $frage = '/index.php?token=' . $cfg['aktionstoken'] . '&aktion=';

    $cmds = array();
    $bauen = function ($aktion, $b, $lp) use (&$cmds, $frage) {
        // Der Titel darf kein '=' tragen - bis 0.9.10 hiess der Ausgang
        // "EVCC_MODUS_LP=1", weil nur das '&' ersetzt wurde.
        $titel = 'EVCC_' . strtoupper($aktion) . ($lp ? '_LP' . $lp : '');
        $text = trim(strip_tags(html_entity_decode(ev_t($b['text']), ENT_QUOTES, 'UTF-8')));
        if ($b['quelle'] === 'doku') {
            // Ein Befehl, den niemand gemessen hat, wird als solcher
            // gekennzeichnet - auch in Loxone Config.
            $text .= ' (neu in 0.9.11, an keiner Anlage gemessen)';
        }
        $adr = $frage . $aktion . ($lp ? '&lp=' . $lp : '');
        $c = array('title' => $titel, 'comment' => $text,
                   'analog' => !empty($b['analog']), 'method' => 'GET');
        if ($b['pruef'] === 'ohne') {
            // DELETE-Befehle brauchen keinen Wert: ein Digitalausgang, der
            // beim Einschalten ausloest.
            $c['on'] = $adr;
            $c['off'] = '';
        } elseif ($b['pruef'] === 'schalter') {
            $c['on'] = $adr . '&wert=1';
            $c['off'] = $adr . '&wert=0';
        } elseif ($b['pruef'] === 'plan') {
            // Zwei Werte: Ziel-Ladestand und Vorlauf in Stunden. Loxone
            // schickt beide als Analogwerte desselben Befehls.
            $c['on'] = $adr . '&wert=<v.0>&stunden=<v.1>';
            $c['off'] = '';
        } else {
            $c['on'] = $adr . '&wert=<v.0>';
            $c['off'] = '';
        }
        $cmds[] = $c;
    };

    foreach (ev_befehle() as $aktion => $b) {
        if ($b['ebene'] !== 'lp') { continue; }
        for ($i = 1; $i <= (int) $cfg['ladepunkte']; $i++) { $bauen($aktion, $b, $i); }
    }
    foreach (ev_befehle() as $aktion => $b) {
        if ($b['ebene'] !== 'anlage') { continue; }
        $bauen($aktion, $b, 0);
    }

    return array('VQ_evcc.xml', ev_xml_virtual_out(array(
        'title'   => 'EVCC Befehle',
        'address' => $adresse,
        'comment' => 'Schreibende Befehle muessen im Reiter Einstellungen freigegeben sein. '
                   . 'Loxone Config legt beim Import neu an und ueberschreibt nichts - '
                   . 'zweimal eingelesen ergibt doppelte Bausteine. '
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

/* ==================================================================
 * Die Befehlstabelle - die EINE Quelle fuer alles Schreibende
 *
 * Bis 0.9.10 stand dieselbe Liste an DREI Stellen: als switch im Endpunkt,
 * als Tabelle im Reiter Einbindung in Loxone und noch einmal in
 * ev_vorlage_aus(). Wer einen Befehl ergaenzte, musste an drei Stellen daran
 * denken - genau das Muster, das die Feldtabelle fuer die Lesewerte laengst
 * abgeschafft hat. Jetzt steht er hier einmal.
 *
 * Je Befehl:
 *   ebene    'lp'     braucht &lp=<Nummer>
 *            'anlage' gilt fuer die ganze Anlage
 *   methode  POST oder DELETE
 *   pfad     %LP% und %WERT% werden ersetzt; %ZEIT% nur beim Ladeplan
 *   pruef    wie der Wert geprueft wird (siehe ev_befehl_pruefen)
 *   min/max  Grenzen, wo 'pruef' sie braucht
 *   schalter Zuordnung 1/0 auf das, was EVCC erwartet - dann entsteht in der
 *            Loxone-Vorlage ein Digitalausgang mit Ein- UND Ausbefehl
 *   text     Sprachschluessel
 *   quelle   'bestand' = in 0.9.10 in Betrieb und hier gegen eine Attrappe
 *                        nachgemessen (15 von 15 Pfaden)
 *            'doku'    = in 0.9.11 aus der EVCC-Dokumentation ergaenzt und an
 *                        KEINER Anlage gemessen. Der Reiter Test sagt das, die
 *                        Oberflaeche kennzeichnet es, und die Antwort des
 *                        Endpunkts nennt es ebenfalls. Ein Befehl, den niemand
 *                        gemessen hat, darf nicht aussehen wie einer, den
 *                        jemand gemessen hat.
 * ================================================================== */

function ev_befehle()
{
    return array(
        /* ---- Ladepunkt, Bestand ---- */
        'modus' => array('ebene' => 'lp', 'methode' => 'POST',
            'pfad' => '/api/loadpoints/%LP%/mode/%WERT%', 'pruef' => 'modus',
            'text' => 'AUS.MODUS', 'quelle' => 'bestand', 'analog' => 1),
        'limitsoc' => array('ebene' => 'lp', 'methode' => 'POST',
            'pfad' => '/api/loadpoints/%LP%/limitsoc/%WERT%', 'pruef' => 'ganz',
            'min' => 0, 'max' => 100, 'text' => 'AUS.LIMITSOC', 'quelle' => 'bestand', 'analog' => 1),
        'minsoc' => array('ebene' => 'lp', 'methode' => 'POST',
            'pfad' => '/api/loadpoints/%LP%/minsoc/%WERT%', 'pruef' => 'ganz',
            'min' => 0, 'max' => 100, 'text' => 'AUS.MINSOC', 'quelle' => 'bestand', 'analog' => 1),
        'phasen' => array('ebene' => 'lp', 'methode' => 'POST',
            'pfad' => '/api/loadpoints/%LP%/phases/%WERT%', 'pruef' => 'liste',
            'liste' => array(0, 1, 3), 'text' => 'AUS.PHASEN', 'quelle' => 'bestand', 'analog' => 1),
        'minstrom' => array('ebene' => 'lp', 'methode' => 'POST',
            'pfad' => '/api/loadpoints/%LP%/mincurrent/%WERT%', 'pruef' => 'ganz',
            'min' => 0, 'max' => 80, 'text' => 'AUS.MINSTROM', 'quelle' => 'bestand', 'analog' => 1),
        'maxstrom' => array('ebene' => 'lp', 'methode' => 'POST',
            'pfad' => '/api/loadpoints/%LP%/maxcurrent/%WERT%', 'pruef' => 'ganz',
            'min' => 0, 'max' => 80, 'text' => 'AUS.MAXSTROM', 'quelle' => 'bestand', 'analog' => 1),
        'prioritaet' => array('ebene' => 'lp', 'methode' => 'POST',
            'pfad' => '/api/loadpoints/%LP%/priority/%WERT%', 'pruef' => 'ganz',
            'min' => 0, 'max' => 10, 'text' => 'AUS.PRIORITAET', 'quelle' => 'bestand', 'analog' => 1),
        'smartcostlimit' => array('ebene' => 'lp', 'methode' => 'POST',
            'pfad' => '/api/loadpoints/%LP%/smartcostlimit/%WERT%', 'pruef' => 'zahl',
            'text' => 'AUS.SMARTCOSTLIMIT', 'quelle' => 'bestand', 'analog' => 1),
        'batterieboost' => array('ebene' => 'lp', 'methode' => 'POST',
            'pfad' => '/api/loadpoints/%LP%/batteryboost/%WERT%', 'pruef' => 'schalter',
            'schalter' => array(1 => '1', 0 => '0'),
            'text' => 'AUS.BATTERIEBOOST', 'quelle' => 'bestand', 'analog' => 0),

        /* ---- Anlage, Bestand ---- */
        'batteriemodus' => array('ebene' => 'anlage', 'methode' => 'POST',
            'pfad' => '/api/batterymode/%WERT%', 'pruef' => 'batteriemodus',
            'text' => 'AUS.BATTERIEMODUS', 'quelle' => 'bestand', 'analog' => 1),
        'prioritaetssoc' => array('ebene' => 'anlage', 'methode' => 'POST',
            'pfad' => '/api/prioritysoc/%WERT%', 'pruef' => 'ganz',
            'min' => 0, 'max' => 100, 'text' => 'AUS.PRIORITAETSSOC', 'quelle' => 'bestand', 'analog' => 1),
        'puffersoc' => array('ebene' => 'anlage', 'methode' => 'POST',
            'pfad' => '/api/buffersoc/%WERT%', 'pruef' => 'ganz',
            'min' => 0, 'max' => 100, 'text' => 'AUS.PUFFERSOC', 'quelle' => 'bestand', 'analog' => 1),
        'residualleistung' => array('ebene' => 'anlage', 'methode' => 'POST',
            'pfad' => '/api/residualpower/%WERT%', 'pruef' => 'ganz',
            'min' => -100000, 'max' => 100000, 'text' => 'AUS.RESIDUALLEISTUNG',
            'quelle' => 'bestand', 'analog' => 1),
        'entladeregelung' => array('ebene' => 'anlage', 'methode' => 'POST',
            'pfad' => '/api/batterydischargecontrol/%WERT%', 'pruef' => 'schalter',
            'schalter' => array(1 => 'true', 0 => 'false'),
            'text' => 'AUS.ENTLADEREGELUNG', 'quelle' => 'bestand', 'analog' => 0),

        /* ---- Neu in 0.9.11 (Vorschlaege D10 bis D13) ----
         * NICHT gemessen. Antwortet EVCC mit 404, sagt der Endpunkt genau
         * das - samt dem Hinweis, dass dieser Befehl aus der Dokumentation
         * stammt. Er biegt nichts zurecht und behauptet keinen Erfolg. */
        'plansoc' => array('ebene' => 'lp', 'methode' => 'POST',
            'pfad' => '/api/loadpoints/%LP%/plan/soc/%WERT%/%ZEIT%', 'pruef' => 'plan',
            'min' => 0, 'max' => 100, 'text' => 'AUS.PLANSOC', 'quelle' => 'doku', 'analog' => 1),
        'planaus' => array('ebene' => 'lp', 'methode' => 'DELETE',
            'pfad' => '/api/loadpoints/%LP%/plan', 'pruef' => 'ohne',
            'text' => 'AUS.PLANAUS', 'quelle' => 'doku', 'analog' => 0),
        'limitenergie' => array('ebene' => 'lp', 'methode' => 'POST',
            'pfad' => '/api/loadpoints/%LP%/limitenergy/%WERT%', 'pruef' => 'zahl',
            'text' => 'AUS.LIMITENERGIE', 'quelle' => 'doku', 'analog' => 1),
        'fahrzeug' => array('ebene' => 'lp', 'methode' => 'POST',
            'pfad' => '/api/loadpoints/%LP%/vehicle/%WERT%', 'pruef' => 'name',
            'text' => 'AUS.FAHRZEUG', 'quelle' => 'doku', 'analog' => 0),
        'fahrzeugaus' => array('ebene' => 'lp', 'methode' => 'DELETE',
            'pfad' => '/api/loadpoints/%LP%/vehicle', 'pruef' => 'ohne',
            'text' => 'AUS.FAHRZEUGAUS', 'quelle' => 'doku', 'analog' => 0),
        'netzladegrenze' => array('ebene' => 'anlage', 'methode' => 'POST',
            'pfad' => '/api/batterygridchargelimit/%WERT%', 'pruef' => 'zahl',
            'text' => 'AUS.NETZLADEGRENZE', 'quelle' => 'doku', 'analog' => 1),
        'netzladenaus' => array('ebene' => 'anlage', 'methode' => 'DELETE',
            'pfad' => '/api/batterygridchargelimit', 'pruef' => 'ohne',
            'text' => 'AUS.NETZLADENAUS', 'quelle' => 'doku', 'analog' => 0),
    );
}

/**
 * Einen Wert gegen die Regel des Befehls pruefen.
 *
 * Rueckgabe: array(ok, klartext-oder-Grund). Es wird ABGEWIESEN, nicht
 * zurechtgebogen: ein Lademodus, den EVCC nicht kennt, wird gemeldet statt
 * stillschweigend auf 'off' gesetzt. Loxone schickt Analogwerte oft als
 * '3.000000' - deshalb wird bei ganzzahligen Feldern gerundet, BEVOR geprueft
 * wird.
 */
function ev_befehl_pruefen($b, $wert)
{
    $zahl = str_replace(',', '.', (string) $wert);
    $ganz = is_numeric($zahl) ? (int) round((float) $zahl) : null;
    $art = $b['pruef'];

    if ($art === 'ohne') { return array(1, '-'); }

    if ($art === 'modus') {
        $m = is_numeric($zahl) ? ev_modus_text($ganz) : strtolower(trim((string) $wert));
        if (!in_array($m, array('off', 'now', 'minpv', 'pv'), true)) {
            return array(0, 'MODUS_UNGUELTIG;ERLAUBT=off,now,minpv,pv,0,1,2,3');
        }
        return array(1, $m);
    }
    if ($art === 'batteriemodus') {
        $k = array(0 => 'normal', 1 => 'hold', 2 => 'charge');
        $m = (is_numeric($zahl) && isset($k[$ganz])) ? $k[$ganz] : strtolower(trim((string) $wert));
        if (!in_array($m, array('normal', 'hold', 'charge'), true)) {
            return array(0, 'BEREICH;ERLAUBT=normal,hold,charge,0,1,2');
        }
        return array(1, $m);
    }
    if ($art === 'liste') {
        if (!in_array($ganz, $b['liste'], true)) {
            return array(0, 'BEREICH;ERLAUBT=' . implode(',', $b['liste']));
        }
        return array(1, (string) $ganz);
    }
    if ($art === 'schalter') {
        if (!in_array($ganz, array(0, 1), true)) { return array(0, 'BEREICH;ERLAUBT=0,1'); }
        return array(1, (string) $b['schalter'][$ganz]);
    }
    if ($art === 'ganz' || $art === 'plan') {
        if ($ganz === null || $ganz < $b['min'] || $ganz > $b['max']) {
            return array(0, 'BEREICH;ERLAUBT=' . $b['min'] . '..' . $b['max']);
        }
        return array(1, (string) $ganz);
    }
    if ($art === 'zahl') {
        if (!is_numeric($zahl)) { return array(0, 'KEINE_ZAHL'); }
        return array(1, (string) (float) $zahl);
    }
    if ($art === 'name') {
        // Fahrzeugnamen sind undurchsichtige Kennungen. Nichts entfernen,
        // nichts grossschreiben - was nicht ins Muster passt, wird gemeldet.
        $n = trim((string) $wert);
        if ($n === '' || !preg_match('/^[A-Za-z0-9_.\- ]{1,64}$/', $n)) {
            return array(0, 'NAME_UNGUELTIG;ERLAUBT=Buchstaben,Ziffern,_.- und Leerzeichen,1..64');
        }
        return array(1, $n);
    }
    return array(0, 'UNBEKANNTE_PRUEFUNG');
}

/* ==================================================================
 * Zusatzwerte: Preisvorschau, Solarprognose, Statistik
 *
 * Diese drei brauchen eigene Abfragen. Sie werden NUR vom Abrufdienst
 * erneuert und im Zwischenspeicher unter 'lox' abgelegt - der
 * Miniserver-Endpunkt liest sie von dort und stellt keine eigene Anfrage.
 * Bis 0.9.10 stand im Endpunkt der Satz, er lese nur den Zwischenspeicher,
 * und er tat es nicht; das soll nicht wieder passieren.
 * ================================================================== */

/** Wie alt duerfen die Zusatzwerte werden, bevor sie neu geholt werden. */
define('EV_ZUSATZ_ALTER', 300);

function ev_zusatz_holen($erzwingen = false)
{
    $cache = ev_tmpdir() . '/state.json';
    $st = is_file($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
    if (!is_array($st)) { return 0; }
    $alt = isset($st['roh']['lox']['stand']) ? (int) $st['roh']['lox']['stand'] : 0;
    if (!$erzwingen && (time() - $alt) < EV_ZUSATZ_ALTER) { return 0; }

    $lox = array('stand' => time());

    /* ---- Solarprognose. Sie steht schon in /api/state, aber die Form hat
     * sich zwischen den EVCC-Fassungen verschoben. Deshalb mehrere
     * Kandidaten, wie bei den uebrigen Feldern auch. ---- */
    $fc = isset($st['roh']['forecast']) ? $st['roh']['forecast'] : null;
    if (is_array($fc)) {
        $solar = isset($fc['solar']) && is_array($fc['solar']) ? $fc['solar'] : $fc;
        $hol = function ($schluessel) use ($solar) {
            if (!isset($solar[$schluessel])) { return null; }
            $v = $solar[$schluessel];
            if (is_array($v)) { $v = isset($v['energy']) ? $v['energy'] : null; }
            return is_numeric($v) ? (float) $v : null;
        };
        $lox['prognose'] = array(
            'heute' => $hol('today'), 'morgen' => $hol('tomorrow'),
            'uebermorgen' => $hol('dayAfterTomorrow'),
        );
    }

    /* ---- Preisvorschau ---- */
    $a = ev_http('/api/tariff/grid', 'GET', null, 6);
    if ($a['ok']) {
        $j = json_decode($a['body'], true);
        if (isset($j['result'])) { $j = $j['result']; }
        $liste = null;
        foreach (array('rates', 'Rates') as $k) {
            if (isset($j[$k]) && is_array($j[$k])) { $liste = $j[$k]; break; }
        }
        if ($liste === null && is_array($j) && isset($j[0])) { $liste = $j; }
        $preise = array();
        $stunden = array();
        if (is_array($liste)) {
            foreach ($liste as $r) {
                if (!is_array($r)) { continue; }
                $p = null;
                foreach (array('price', 'value', 'Price') as $k) {
                    if (isset($r[$k]) && is_numeric($r[$k])) { $p = (float) $r[$k]; break; }
                }
                if ($p === null) { continue; }
                $s = -1;
                foreach (array('start', 'Start') as $k) {
                    if (isset($r[$k])) { $s = (int) date('G', strtotime((string) $r[$k])); break; }
                }
                $preise[] = $p;
                $stunden[] = $s;
            }
        }
        if ($preise) {
            $jetzt = $preise[0];
            $sortiert = $preise;
            sort($sortiert);
            $rang = 1;
            foreach ($sortiert as $p) { if ($p < $jetzt) { $rang++; } }
            $besti = array_search(min($preise), $preise, true);
            $lox['preis'] = array(
                'min' => min($preise), 'max' => max($preise),
                'schnitt' => round(array_sum($preise) / count($preise), 4),
                'rang' => $rang, 'anzahl' => count($preise),
                'beste_stunde' => isset($stunden[$besti]) ? $stunden[$besti] : -1,
            );
        }
    }

    /* ---- Statistik ---- */
    $a = ev_http('/api/statistics', 'GET', null, 6);
    if ($a['ok']) {
        $j = json_decode($a['body'], true);
        if (isset($j['result'])) { $j = $j['result']; }
        if (is_array($j)) { $lox['statistik'] = $j; }
    }

    $st['roh']['lox'] = $lox;
    @file_put_contents($cache, json_encode($st));
    return 1;
}

/** Die Statistik aus dem Zwischenspeicher - ohne eigene Abfrage. */
function ev_statistik()
{
    $cache = ev_tmpdir() . '/state.json';
    $st = is_file($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
    if (!is_array($st) || !isset($st['roh']['lox']['statistik'])) { return array(); }
    $s = $st['roh']['lox']['statistik'];
    return is_array($s) ? $s : array();
}

/* ==================================================================
 * Selbstpruefung des Endpunkts
 *
 * Der Reiter Test hatte bis 0.9.10 achtzehn Pruefzeilen und rief den eigenen
 * Endpunkt in keiner einzigen auf. Beide Ausfaelle der Fassung 0.9.10 - der
 * Endpunkt fand seine Bibliothek nicht, die Oberflaeche starb an einer
 * ueberschriebenen Variablen - waeren am ersten Tag sichtbar gewesen.
 * ================================================================== */

/**
 * Wo der Endpunkt seine Bibliothek suchen wird.
 *
 * MUSS mit der Liste in webfrontend/html/index.php uebereinstimmen. Dort
 * steht sie ausgeschrieben, weil sie gebraucht wird, BEVOR diese Datei
 * geladen ist - das laesst sich nicht aufloesen. Deshalb prueft der Reiter
 * Test zusaetzlich ueber HTTP, ob der Endpunkt wirklich antwortet; das ist
 * der Beleg, diese Liste hier nur der bessere Hinweistext.
 */
function ev_endpunkt_kandidaten()
{
    $p = ev_paths();
    if ($p['home'] === '') { return array(); }
    $html = $p['home'] . '/webfrontend/html/plugins/' . $p['plugin'];
    return array(
        $p['home'] . '/webfrontend/htmlauth/plugins/' . $p['plugin'] . '/ev_lib.php',
        dirname($html) . '/htmlauth/ev_lib.php',
    );
}

/**
 * Den eigenen Endpunkt WIRKLICH aufrufen.
 *
 * Rueckgabe: array(ok, code, erste Zeile, Adresse).
 */
function ev_selbsttest_endpunkt($aktion = 'status')
{
    $url = ev_endpunkt($aktion) . '&selbsttest=1';
    $kopf = array('User-Agent: LoxBerry-EVCC-Plugin-Selbsttest', 'Accept: text/plain');
    $body = false;
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => $kopf,
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = curl_error($ch);
        curl_close($ch);
        if ($body === false) { return array(0, 0, ev_netzfehler($fehler, $url), $url); }
    } else {
        $ctx = stream_context_create(array('http' => array(
            'method' => 'GET', 'timeout' => 10, 'ignore_errors' => true,
            'header' => implode("\r\n", $kopf))));
        $body = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0])
            && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        if ($body === false) { return array(0, $code, 'keine Antwort', $url); }
    }
    $erste = trim(strtok((string) $body, "\n"));
    if ($erste === '' && $code >= 500) {
        // Genau das Bild der Fassung 0.9.10: Code 500, Rumpf leer. Der Grund
        // steht dann nur im Fehlerprotokoll des Webservers, nicht hier.
        $erste = 'leere Antwort - der Endpunkt ist mit einem fatalen Fehler '
               . 'abgebrochen (display_errors ist dort aus).';
    }
    return array(($code === 200 && strpos($erste, 'EVCC;') === 0) ? 1 : 0,
                 $code, $erste, $url);
}


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Die sieben Punkte aus REGELN_2, und der wichtigste ist der dritte: eine
 * halb gueltige Datei ueberschreibt GAR NICHTS. Wer eine Sicherung
 * zurueckspielt, will entweder den ganzen Stand oder gar keinen - eine zur
 * Haelfte uebernommene Konfiguration ist schlimmer als die alte, und man
 * sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function ev_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(ev_t('EINST.SICH_KEIN_JSON')), 0);
    }
    $neu = ev_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(ev_t('EINST.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = ev_t('EINST.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}


/* ==================================================================
 * WACHPOSTEN GEGEN FREMDE FORMULARE
 * ==================================================================
 *
 * htmlauth/ schuetzt gegen den UNANGEMELDETEN Aufruf. Es schuetzt nicht
 * dagegen, dass der Browser eines angemeldeten Bedieners ein Formular
 * abschickt, das auf einer fremden Seite steht - die Anmeldung schickt er
 * automatisch mit.
 *
 * Gemessen an Schwesterlinien (Skoda Connect 0.9.12, Midea 4.2.12, beide
 * am 27.08.2026): ein einziger fremder POST genuegte, um das Aktionstoken
 * neu zu wuerfeln. Danach beantwortet der Endpunkt jeden Virtuellen Eingang
 * mit 403 - und ein Virtueller Eingang wertet die Antwort NICHT aus. Der
 * Ausfall bleibt still.
 *
 * Der leere Fall wird eigens abgefangen: hash_equals('', '') ist in PHP
 * TRUE. Wer das Feld nicht vor dem Vergleich auf leer prueft, hat einen
 * Posten gebaut, den jeder passiert, der das Feld leer laesst.
 *
 * Das Merkmal wird aus $_POST und $_GET gelesen, nie aus $_REQUEST:
 * $_REQUEST enthaelt je nach variables_order auch Cookies.
 * ================================================================== */

function ev_merkwort()
{
    static $wort = null;
    if ($wort !== null) {
        return $wort;
    }
    $pfade = ev_paths();
    $verz  = isset($pfade['datadir']) ? $pfade['datadir'] : '';
    if ($verz === '') {
        return '';
    }
    $datei = $verz . '/formmerkwort';
    if (is_readable($datei)) {
        $roh = trim((string) @file_get_contents($datei));
        if (preg_match('/^[0-9a-f]{32,64}$/', $roh)) {
            $wort = $roh;
            return $wort;
        }
    }
    if (function_exists('random_bytes')) {
        $neu = bin2hex(random_bytes(24));
    } else {
        $neu = substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 48);
    }
    if (!is_dir($verz)) {
        @mkdir($verz, 0775, true);
    }
    /* Rechte VOR dem Inhalt: zwischen Anlegen und chmod laege sonst ein
     * Fenster, in dem das Merkwort fuer alle lesbar ist. */
    $tmp = $datei . '.tmp';
    if (@file_put_contents($tmp, $neu) !== false) {
        @chmod($tmp, 0600);
        if (@rename($tmp, $datei)) {
            @chmod($datei, 0600);
        } else {
            @unlink($tmp);
        }
    }
    $wort = $neu;
    return $wort;
}

function ev_formtoken()
{
    $grund = ev_merkwort();
    return $grund === '' ? '' : hash_hmac('sha256', 'formular-v1', $grund);
}

/* Das versteckte Feld. Bewusst OHNE den Escape-Helfer des Plugins: der
 * steht bei einigen Linien in index.php und waere von hier aus nicht da.
 * Der Wert ist hexadezimal. */
function ev_fmt()
{
    return '<input data-role="none" type="hidden" name="fmt" value="'
         . htmlspecialchars(ev_formtoken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Rueckgabe: '' wenn die Anfrage durchgelassen wird, sonst der Grund. */
function ev_wachposten()
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return '';
    }
    $soll = ev_formtoken();
    $ist = isset($_POST['fmt']) ? $_POST['fmt']
         : (isset($_GET['fmt']) ? $_GET['fmt'] : null);
    if (!is_string($ist) || $ist === '' || $soll === '') {
        return ev_t('WACHE.FEHLT');
    }
    if (!hash_equals($soll, $ist)) {
        return ev_t('WACHE.FALSCH');
    }
    return '';
}
