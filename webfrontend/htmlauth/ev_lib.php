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
/* Gemeinsame Sprachausgabe (Abschrift von Werkzeuge/gemeinsam/sprachausgabe.php, Nr. 36 b). Liegt
 * neben dieser Datei; die Datei schuetzt sich selbst gegen doppeltes Laden. */
require_once __DIR__ . '/sprachausgabe.php';

/* ==================================================================
 * Pfade und Protokoll
 * ================================================================== */


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins, data/plugins UND config/system/general.json traegt
 * (Regeln/06). Bis 0.9.32 genuegten config/plugins und webfrontend - genau
 * diese Ordner hinterlaesst ein Pruefstand auf einem Arbeitsrechner, und ein
 * Archiv in einem solchen Baum nahm ihn als LoxBerry (in WSL gemessen,
 * Pruefung-EVCC-0.9.33, Fall W9). Findet sich nichts, kommt ein Leerstring
 * zurueck; der Aufrufer muss ihn abfangen.
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

/* Die Wurzel in der Reihenfolge der Hausregel: erst die Umgebung, dann die
 * Suche - und DANACH NICHTS MEHR.
 *
 * Bis 0.9.32 stand hier als dritte Stufe ein fest verdrahteter Systempfad
 * (das Heimatverzeichnis des Benutzers loxberry). Er macht jede Suche
 * wirkungslos und trifft auf einem anders installierten LoxBerry die falsche
 * Anlage; dieselbe Stelle wurde in Spotpreis-Tibber 0.9.18,
 * ZendureSolarFlow 0.9.25 und VolkswagenID 0.9.24 entfernt.
 *
 * Ein gesetztes LBHOMEDIR gilt mit config/plugins UND data/plugins darunter.
 * Rueckgabe '' heisst "keine Wurzel"; jeder Aufrufer muss das abfangen. */
function ev_lbhome()
{
    $h = rtrim((string) getenv('LBHOMEDIR'), '/');
    if ($h !== '' && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')) {
        return $h;
    }
    return lb_wurzel_ermitteln();
}

function ev_paths()
{
    $home = ev_lbhome();
    $plugin = getenv('LBPPLUGINDIR');
    if (!$plugin) {
        /* Ohne Umgebungsvariable aus dem Ablageort ableiten - ueber eine
         * KANDIDATENLISTE, nicht ueber eine einzelne Rechnung.
         *
         * Diese Datei liegt in zwei Lagen verschieden tief:
         *   installiert  <home>/webfrontend/htmlauth/plugins/<ordner>/ev_lib.php
         *   Archiv       <wurzel>/webfrontend/htmlauth/ev_lib.php
         * basename(__DIR__) trifft die erste, zwei Ebenen hoeher die zweite.
         *
         * Bis 0.9.26 stand nur die zweite Rechnung da. Installiert ergab sie
         * 'htmlauth'; gerettet hat das nur der fest eingetragene Name 'evcc' -
         * und genau der bricht bei einer Zweitinstallation, die LoxBerry als
         * <ordner>_01 anlegt. Gemessen am 04.09.2026: eine Installation als
         * evcc_01 benutzte Konfiguration, Aktionstoken, Protokoll und
         * Sperrdatei der ERSTEN. Ebenso gemessen: lag ein fremdes
         * config/plugins/htmlauth vor, wanderte alles dorthin.
         *
         * 'htmlauth' und 'html' sind Namen von BAEUMEN, nie von
         * Plugin-Ordnern - eine Rechnung, die dort landet, liegt eine Ebene
         * daneben und wird uebergangen. */
        $plugin = '';
        foreach (array(basename(__DIR__),
                       basename(dirname(dirname(__DIR__)))) as $ev_kand) {
            if ($ev_kand === '' || $ev_kand === 'htmlauth' || $ev_kand === 'html') {
                continue;
            }
            if ($plugin === '') { $plugin = $ev_kand; }
            if ($home && is_dir($home . '/config/plugins/' . $ev_kand)) {
                $plugin = $ev_kand;    // belegt - der sticht die blosse Rechnung
                break;
            }
        }
        if ($plugin === '') { $plugin = 'evcc'; }
    }
    /* Archivmodus (seit 0.9.33). Die Pfade DER ANLAGE gelten nur, wenn diese
     * Bibliothek dort installiert liegt
     * (<Wurzel>/webfrontend/htmlauth/plugins/<ordner>, physisch verglichen)
     * oder der Aufrufer Wurzel UND Ordner ausdruecklich nennt ($LBHOMEDIR und
     * $LBPPLUGINDIR - so ruft uninstall/uninstall bin/ev_abruf.php
     * --mqtt-leeren, und so arbeiten die Pruefwerkzeuge mit ihrer Attrappe).
     * Sonst ist das ein ausgepacktes Archiv oder ein Pruefordner: alles bleibt
     * in dessen eigenem Ordner, und bin/ev_abruf.php steigt aus.
     *
     * Bis 0.9.32 nahm ein Archiv unterhalb einer echten Wurzel diese Wurzel -
     * mit $LBHOMEDIR allein, wie es am Geraet in /etc/environment steht,
     * ebenso - und dazu den Namen des Archivordners als Pluginordner:
     * ev_abruf.php legte dort Konfiguration mit frischem Token und Protokoll
     * an und sandte 109 Themen an das MQTT-Gateway der Anlage (in WSL
     * gemessen, Pruefung-EVCC-0.9.33, Faelle W7, W8, W11, W12). Bauart
     * tb_paths() aus Spotpreis-Tibber 0.9.19. */
    $ev_gefunden = $home;
    if ($home !== '') {
        $ev_soll = @realpath($home . '/webfrontend/htmlauth/plugins/' . basename(__DIR__));
        $ev_ist = @realpath(__DIR__);
        $ev_installiert = ($ev_soll !== false && $ev_ist !== false && $ev_soll === $ev_ist);
        $ev_lbp = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
        $ev_ausdruecklich = $ev_lbp !== ''
            && !in_array($ev_lbp, array('.', '/', 'html', 'htmlauth', 'bin', 'plugins'), true)
            && $home === rtrim((string) getenv('LBHOMEDIR'), '/');
        if (!$ev_installiert && !$ev_ausdruecklich) { $home = ''; }
    }
    if ($home) {
        return array(
            'home'      => $home,
            'plugin'    => $plugin,
            'archiv'    => '',
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
    /* Keine Anlage (Entwicklung, ausgepacktes Archiv, fremder Baum): neben
     * dem Plugin arbeiten. Bis 0.9.32 lagen Daten, Protokoll und
     * Zwischenspeicher hier unter sys_get_temp_dir()/evcc - auf einem LoxBerry
     * ist das /tmp/evcc, der Zwischenspeicher DER ANLAGE (Fall T4). */
    $eigen = dirname(dirname(__DIR__));
    return array(
        'home' => '', 'plugin' => 'evcc', 'archiv' => $ev_gefunden,
        'config' => $eigen . '/config/evcc.json',
        'sicherung' => $eigen . '/config/evcc.backup.json',
        'sicherung_alt' => $eigen . '/config/evcc.backup.json',
        'configdir' => $eigen . '/config',
        'datadir' => $eigen . '/data',
        'log' => $eigen . '/log/evcc.log',
        'tmp' => $eigen . '/tmp',
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

/**
 * Eine Datei ganz schreiben (C7, seit 0.9.34).
 *
 * Nebendatei mit PID (<ziel>.neu.<pid>), Rechte VOR dem Inhalt, Laenge und
 * Ruecklesen pruefen, dann umbenennen (Regeln/03, "atomar schreiben"). Bis
 * 0.9.33 schrieb ev_config_write() erst den Inhalt nach <ziel>.neu (ohne PID)
 * und setzte danach 0600: in WSL mit umask 0002 gemessen lag die Nebendatei
 * mit Aktionstoken und EVCC-Passwort 47-mal mit 664 da, und zwei Schreiber
 * teilten sich dieselbe Nebendatei (Pruefbericht code, Befund 7).
 *
 * Rueckgabe false heisst: am alten Stand hat sich nichts geaendert.
 * Bauform ap_datei_schreiben() (APC-UPS NG 1.2.14).
 */
function ev_datei_schreiben($pfad, $inhalt, $modus = 0600)
{
    $inhalt = (string) $inhalt;
    $ordner = dirname($pfad);
    if (!is_dir($ordner)) { @mkdir($ordner, 0775, true); }
    $neben = $pfad . '.neu.' . getmypid();
    if (file_exists($neben)) { @unlink($neben); }
    $fh = @fopen($neben, 'xb');          // leer angelegt ...
    if ($fh === false) { return false; }
    @chmod($neben, $modus);               // ... sofort geschuetzt ...
    $n = @fwrite($fh, $inhalt);           // ... dann erst gefuellt
    $ok = ($n === strlen($inhalt)) && @fflush($fh);
    $ok = @fclose($fh) && $ok;
    if ($ok) {
        clearstatcache(true, $neben);
        $ok = (@filesize($neben) === strlen($inhalt))
              && ((string) @file_get_contents($neben) === $inhalt);
    }
    if (!$ok || !@rename($neben, $pfad)) {
        @unlink($neben);
        return false;
    }
    @chmod($pfad, $modus);
    return true;
}

/**
 * Laeuft gerade eine Aktualisierung? (Entscheidung 1 vom 29.09.2026)
 *
 * preupgrade.sh legt als Erstes data/plugins/<ordner>.upgrade_laeuft an,
 * postupgrade.sh raeumt die Marke ueber einen trap ab. Kein Altersvergleich:
 * eine vergessene Marke gilt (Entscheidung 8, Frage 17). Solange sie liegt,
 * entsteht kein neues Aktionstoken - in der Luecke zwischen dem Aufraeumen
 * des Installers und postupgrade.sh fehlt die Konfiguration, und ein Takt
 * haette dort ohne Zweitschrift ein neues Token gewuerfelt (Pruefbericht
 * installer, B10).
 */
function ev_upgrade_marke()
{
    $p = ev_paths();
    return ($p['home'] !== '') ? $p['home'] . '/data/plugins/' . $p['plugin'] . '.upgrade_laeuft' : '';
}

function ev_upgrade_laeuft()
{
    $m = ev_upgrade_marke();
    if ($m === '') { return false; }
    clearstatcache(true, $m);
    return file_exists($m);
}

/**
 * Traegt eine gelesene Konfiguration Inhalt? (C2, seit 0.9.34)
 *
 * Inhalt heisst: ein JSON-Objekt MIT dem Schluessel aktionstoken. Bis 0.9.33
 * galt jede gueltige JSON-Datei als heil; eine Datei ohne Token (von Hand
 * bearbeitet, von einem fremden Werkzeug geschrieben, '[]') ergab ein neues
 * Token, und ev_config_write() schrieb es auch in die Zweitschrift - das alte
 * war aus beiden Dateien verschwunden, ohne Protokollzeile (Pruefbericht code,
 * Befund 2). Bauart sp_config_hat_inhalt() (Sprachsteuerung 0.11.11).
 */
function ev_config_hat_inhalt($cfg)
{
    return is_array($cfg) && array_key_exists('aktionstoken', $cfg);
}

/**
 * Die Zweitschrift lesen - erst am heutigen Ort, dann am frueheren.
 * $mit_token: nur eine Zweitschrift mit nicht leerem Aktionstoken zaehlt.
 * Rueckgabe array(cfg, quelle) oder null.
 */
function ev_zweitschrift_lesen($p, $mit_token)
{
    foreach (array($p['sicherung'], $p['sicherung_alt']) as $ev_quelle) {
        if ($ev_quelle === '' || !is_file($ev_quelle)) { continue; }
        $ev_s = json_decode((string) @file_get_contents($ev_quelle), true);
        if (!is_array($ev_s) || !$ev_s) { continue; }
        if ($mit_token && !(isset($ev_s['aktionstoken']) && is_string($ev_s['aktionstoken'])
                            && $ev_s['aktionstoken'] !== '')) {
            continue;
        }
        return array($ev_s, $ev_quelle);
    }
    return null;
}

/**
 * Ein neues Aktionstoken ist entstanden - fuer die Oberflaeche festhalten
 * (C2): sie zeigt es 24 Stunden lang im Reiter Einstellungen an. Die Zeile im
 * Protokoll schreibt ev_config() selbst.
 */
function ev_token_neu_merken($grund)
{
    $p = ev_paths();
    $js = json_encode(array('zeit' => time(), 'grund' => (string) $grund));
    if ($js !== false) { ev_datei_schreiben($p['datadir'] . '/token_neu.json', $js, 0644); }
}

/** Rueckgabe array(zeit, grund) oder null, wenn in den letzten 24 h kein Token entstand. */
function ev_token_neu()
{
    $p = ev_paths();
    $d = @json_decode((string) @file_get_contents($p['datadir'] . '/token_neu.json'), true);
    if (!is_array($d) || !isset($d['zeit']) || (time() - (int) $d['zeit']) > 86400) { return null; }
    return array((int) $d['zeit'], isset($d['grund']) ? (string) $d['grund'] : '');
}

/**
 * Wie steht die Konfiguration VOR jeder Heilung da? (O6)
 * Nur lesen - der Reiter Test ruft das auf, bevor ev_config() heilen kann.
 * Rueckgabe: ok, fehlt, leer, kaputt, ohne_token, token_leer.
 */
function ev_konfig_lage()
{
    $p = ev_paths();
    if (!is_file($p['config'])) { return 'fehlt'; }
    $roh = trim((string) @file_get_contents($p['config']));
    if ($roh === '' || $roh === '{}') { return 'leer'; }
    $c = json_decode($roh, true);
    if (!is_array($c)) { return 'kaputt'; }
    if (!ev_config_hat_inhalt($c)) { return 'ohne_token'; }
    if (!is_string($c['aktionstoken']) || $c['aktionstoken'] === '') { return 'token_leer'; }
    return 'ok';
}

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
        // Alle Werte erneut senden, auch unveraendert, alle N Minuten; sonst
        // geht nur hinaus, was sich geaendert hat, und das Lebenszeichen.
        // 0 = kein Vollversand im Takt (nur bei Start, Pluginstart und
        // Praefixwechsel). Seit 0.9.33, Name wie im Abfahrts-Assistenten.
        'mqtt_vollsend_min' => 15,
        // Umfang
        'ladepunkte'     => 2,         // wie viele Ladepunkte ausgeben
        'fahrzeuge'      => 2,         // wie viele Fahrzeuge ausgeben
        'tarife_ein'     => 1,
        // Ab Werk AUS. Ein Update startet EVCC neu und unterbricht eine
        // laufende Ladung; das schaltet niemand ungefragt ein.
        'update_ein'     => 0,
        // Schreiber-Wache am Endpunkt (Energie-1 C1, Entscheidung Nr. 25): meldet
        // ab Werk (aendert am Haus nichts), sperrt ab Werk nicht.
        'wache_ein'         => 1,   // mehrere Schreiber im Fenster melden (Protokoll, Reiter Test)
        'wache_fenster_min' => 15,  // Fenster in Minuten (1..120)
        'wache_lb_melden'   => 0,   // neue Runde zusaetzlich als LoxBerry-Meldung
        'wache_sperren_ein' => 0,   // fremde Schreiber mit 409 abweisen
        'wache_erlaubt'     => '',  // erlaubte Schreiber: Kennung, Adresse oder Kennung@Adresse
        // Nr. 36 b (Stufe 2): Ansage ueber die gemeinsame Sprachausgabe - ab Werk keine Ausgabeart
        // ('aus'); die Anlaesse sind an, wirken aber erst mit einer Ausgabeart.
        'ansage_ausfall'     => 1,
        'ansage_startfehler' => 1,
        'ansage_fertig'      => 1,
        'tts'               => ansage_vorgaben('aus'),
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
    $ev_defekt = false;
    $ev_hatte_token = false;
    /* Warum ein neues Token entstuende - fuer Protokoll und Oberflaeche (C2). */
    $ev_grund = ($roh === '' && !is_file($p['config'])) ? ev_t('LOG.TOKEN_GRUND_NEU') : ev_t('LOG.TOKEN_GRUND_LEER');

    if ($roh === '' || $roh === '{}') {
        $ziehen = true;                     // fehlt oder leer - der harmlose Fall
    } else {
        $cfg = json_decode($roh, true);
        if (!is_array($cfg)) {
            $cfg = null;
            $ziehen = true;
            $ev_defekt = true;
            /* EINE Zeile, nicht eine je Aufruf. ev_config() laeuft je
             * Endpunktabfrage mehrfach und im Cron bis zu zwoelfmal die
             * Minute; mit ev_log() bestand das Protokoll danach aus dieser
             * einen Meldung, und die Kappung bei 512 kB warf alles andere
             * weg. Gemessen an 0.9.26: fuenf Aufrufe, fuenf Zeilen. */
            ev_log_wenn_neu('configjson', 'FEHLER: ' . $p['config'] . ' ist kein '
                 . 'gueltiges JSON (' . json_last_error_msg() . ', '
                 . strlen($roh) . ' Byte). Die Zweitschrift wird gelesen; die '
                 . 'beschaedigte Datei bleibt als .kaputt liegen.');
        } elseif (!ev_config_hat_inhalt($cfg)) {
            /* C2 (seit 0.9.34): gueltiges JSON, aber ohne Aktionstoken. Liegt
             * eine Zweitschrift MIT Token, wird aus ihr geheilt und die Datei
             * als .kaputt beiseitegelegt. Sonst ist es eine neue Einrichtung
             * des Tokens: die uebrigen Werte der Datei bleiben, das Token
             * entsteht unten - gemeldet und protokolliert, nicht still. */
            if (ev_zweitschrift_lesen($p, true) !== null) {
                $cfg = null;
                $ziehen = true;
                $ev_defekt = true;
                ev_log_wenn_neu('configinhalt', 'FEHLER: ' . $p['config'] . ' ist gueltiges '
                     . 'JSON, traegt aber kein Aktionstoken (' . strlen($roh) . ' Byte). Geheilt '
                     . 'wird aus der Zweitschrift; die Datei bleibt als .kaputt liegen.');
            } else {
                $ev_grund = ev_t('LOG.TOKEN_GRUND_OHNE');
            }
        }
    }
    if ($ev_defekt && $erzeugen && !is_file($p['config'] . '.kaputt')) {
        /* Die Kopie traegt Passwort und Aktionstoken wie das Original. Bis
         * 0.9.33 entstand sie per copy() mit 0666 & ~umask und bekam erst
         * danach 0600; jetzt Rechte vor dem Inhalt (C7). */
        ev_datei_schreiben($p['config'] . '.kaputt', $roh, 0600);
    }

    if ($ziehen && $cfg === null) {
        /* Zweitschrift ziehen - zuerst am heutigen Ort (neben dem
         * Plugin-Ordner), dann am frueheren Ort darin, sonst verloere eine
         * bestehende Anlage beim Update ihre vorhandene Sicherung.
         * GELESEN, nicht kopiert: zurueckgeschrieben wird erst durch
         * ev_config_write(), und zwar erst nach gelungenem Lesen. */
        $ev_zs = ev_zweitschrift_lesen($p, false);
        if ($ev_zs !== null) {
            $cfg = $ev_zs[0];
            $ev_geholt = $ev_zs[1];
        }
    }

    if (!is_array($cfg)) { $cfg = array(); }
    /* Vor dem Zusammenfuehren festhalten, ob ueberhaupt schon einmal ein
     * Aktionstoken hinterlegt war. Nach array_merge() ist das nicht mehr zu
     * unterscheiden: die Vorgabe traegt einen leeren Wert, und 'fehlt' saehe
     * dann aus wie 'bewusst geleert'. */
    $ev_hatte_token = array_key_exists('aktionstoken', $cfg);
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
    $cfg['mqtt_vollsend_min'] = max(0, min(1440, (int) $cfg['mqtt_vollsend_min']));

    /* Ein Token entsteht genau einmal: wenn noch nie eines hinterlegt war -
     * und nur bei wirklich neuer Einrichtung (C2, seit 0.9.34).
     *
     * Mit Sperre. Beim ersten Aufruf nach der Einrichtung koennen die
     * Oberflaeche, der Cron-Abruf und der Miniserver-Endpunkt gleichzeitig
     * hier ankommen. Ohne Sperre erzeugt jeder ein eigenes Token und
     * ueberschreibt die anderen.
     *
     * Bis 0.9.26 stand hier ein Mustervergleich - was nicht auf
     * ^[A-Za-z0-9]{24,}$ passte, wurde stillschweigend ersetzt (gemessen am
     * 04.09.2026). Ein VORHANDENER, aber leerer Wert bleibt leer: Leeren ist
     * der Ausschalter fuer den Endpunkt.
     *
     * Neu in 0.9.34: waehrend einer Aktualisierung (Marke
     * <ordner>.upgrade_laeuft) entsteht KEIN Token - die Konfiguration kommt
     * gleich aus der Upgrade-Sicherung zurueck. Entsteht eines, steht das mit
     * Grund im Protokoll und 24 Stunden lang im Reiter Einstellungen. */
    if (!$ev_hatte_token && $erzeugen && ev_upgrade_laeuft()) {
        ev_log_wenn_neu('tokenmarke', 'Eine Aktualisierung laeuft (' . ev_upgrade_marke()
            . ') - es wird kein neues Aktionstoken angelegt; postupgrade.sh stellt die '
            . 'Einstellungen gleich zurueck.');
    } elseif (!$ev_hatte_token && $erzeugen) {
        @mkdir($p['configdir'], 0775, true);
        $sperre = @fopen($p['configdir'] . '/.token.lock', 'c');
        if ($sperre !== false && flock($sperre, LOCK_EX)) {
            // Innerhalb der Sperre noch einmal nachsehen: vielleicht war ein
            // anderer Prozess schneller, dann wird seines uebernommen.
            $frisch = @json_decode((string) @file_get_contents($p['config']), true);
            if (is_array($frisch) && array_key_exists('aktionstoken', $frisch)
                && is_string($frisch['aktionstoken']) && $frisch['aktionstoken'] !== '') {
                $cfg['aktionstoken'] = (string) $frisch['aktionstoken'];
            } else {
                try {
                    $cfg['aktionstoken'] = ev_token();
                    if (ev_config_write($cfg)) {
                        ev_log('Neues Aktionstoken angelegt (' . $ev_grund . '). Die Adressen '
                            . 'im Miniserver brauchen es; der Reiter Einbindung in Loxone nennt sie.');
                        ev_token_neu_merken($ev_grund);
                    }
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
    } elseif ($ev_hatte_token && (string) $cfg['aktionstoken'] === '') {
        /* Bestehende Anlage, Token leer: das ist eine Lage, kein Fehler -
         * aber der Betreiber soll sie nicht in Loxone suchen muessen. Eine
         * Zeile, gebremst ueber den Merker. Der Knopf liegt im Reiter Test
         * (bis 0.9.33 nannte die Zeile den Reiter Einstellungen, O11). */
        ev_log_wenn_neu('tokenleer', 'Das Aktionstoken ist leer. Der Endpunkt '
            . 'weist jede Anfrage mit KEIN_TOKEN_EINGERICHTET ab. Ein neues '
            . 'entsteht im Reiter Test auf Knopfdruck ("Token neu erzeugen"); von '
            . 'selbst wird keines nachgelegt, damit ein bewusst geleertes Token '
            . 'geleert bleibt.');
    }

    /* Die Zweitschrift EINMAL zurueckschreiben - und einmal melden.
     *
     * Zurueckgeschrieben wird nur, wo Schreiben erlaubt ist: der unangemeldete
     * Endpunkt legt weiterhin nichts an, er arbeitet mit dem gelesenen Stand.
     * Gemeldet wird ueber ev_log_wenn_neu - der Merker in /tmp haelt auch die
     * naechsten Prozesse still.
     *
     * Geheilt wird, wenn die Datei fehlt, unbrauchbar ist (kaputt, ohne Token)
     * oder nur '{}' traegt. Bis 0.9.33 blieb '{}' neben einer Zweitschrift mit
     * Token fuer immer stehen, und jeder Aufruf zog die Zweitschrift erneut. */
    if ($ev_geholt !== '') {
        $ev_heilen = $erzeugen && (!is_file($p['config']) || $ev_defekt || $roh === '{}');
        $ev_geheilt = $ev_heilen ? ev_config_write($cfg) : false;
        ev_log_wenn_neu('zweitschrift', 'Konfiguration aus der Zweitschrift '
            . $ev_geholt . ' geholt'
            . ($ev_geheilt ? ' und wiederhergestellt.'
                           : ($ev_heilen ? '; das Zurueckschreiben ist fehlgeschlagen.'
                                         : '.')));
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

    // Die Datei traegt Token und moeglicherweise das EVCC-Passwort: 0600,
    // und zwar schon, bevor ein Byte Inhalt darin steht (C7).
    if (!ev_datei_schreiben($p['config'], $js, 0600)) { return false; }

    /* Die Zweitschrift wird erst JETZT erneuert - nach einem vollstaendig
     * geschriebenen Ziel - und nur mit einem Stand, der ein Aktionstoken
     * traegt (C2, seit 0.9.34). Bis 0.9.33 ueberschrieb sie jeder
     * Schreibvorgang, auch einer ohne Token; die Rettung war damit genau in
     * dem Fall weg, fuer den es sie gibt (Pruefbericht code, Befund 2;
     * Pruefbericht installer, B10). */
    if (isset($cfg['aktionstoken']) && is_string($cfg['aktionstoken']) && $cfg['aktionstoken'] !== '') {
        ev_datei_schreiben($p['sicherung'], $js, 0600);
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
    /* Die Vorgabe richtet sich nach dem Schema. Bis 0.9.26 stand hier fest
     * 80 - bei einer https-Adresse ohne Portangabe nannte der Fehlertext
     * dann einen Port, den niemand angesprochen hat. */
    $ev_schema = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $ev_port = parse_url($url, PHP_URL_PORT);
    if (!$ev_port) { $ev_port = ($ev_schema === 'https') ? 443 : 80; }
    $wirt = parse_url($url, PHP_URL_HOST) . ':' . $ev_port;
    /* Diese Meldungen sind die aussagekraeftigsten des Plugins, und sie
     * erreichen einen Menschen - in der Oberflaeche, im Feld LETZTER_FEHLER
     * und ueber MQTT. Bis 0.9.26 standen sie fest auf Deutsch im Quelltext
     * und erschienen so auch in der englischen Oberflaeche. */
    if (strpos($t, 'refused') !== false || strpos($t, 'verweigert') !== false) {
        return sprintf(ev_t('FEHLER.ABGEWIESEN'), $wirt);
    }
    if (strpos($t, 'timed out') !== false || strpos($t, 'timeout') !== false
        || strpos($t, 'zeit') !== false) {
        return sprintf(ev_t('FEHLER.ZEIT'), $wirt);
    }
    if (strpos($t, 'no route') !== false || strpos($t, 'unreachable') !== false) {
        return sprintf(ev_t('FEHLER.KEIN_WEG'), $wirt);
    }
    if (strpos($t, 'resolve') !== false || strpos($t, 'not known') !== false
        || strpos($t, 'getaddrinfo') !== false) {
        return sprintf(ev_t('FEHLER.NAME'), $wirt);
    }
    return $text !== '' ? (string) $text : sprintf(ev_t('FEHLER.KEINE_ANTWORT_VON'), $wirt);
}

/**
 * Der Fehlertext zu einer nicht geglueckten Antwort - MIT dem, was die
 * Gegenstelle selbst sagt.
 *
 * Gemessen am 10.09.2026: 'puffersoc' ohne Hausspeicher beantwortete EVCC
 * mit HTTP 400 und {"error":"battery not configured"}. Beim Anwender kam
 * nur "HTTP 400 von http://127.0.0.1:7070/api/buffersoc/1" an - die
 * Begruendung, die alles erklaert, fiel weg. Dieselbe Messung zeigte, dass
 * ein GET auf denselben Pfad 404 "404 page not found" liefert; auch das ist
 * ein brauchbarer Satz.
 *
 * Der Schluessel heisst 'error', nicht 'message' (Regeln/12, 17.08.2026);
 * 'message' wird trotzdem gelesen, falls eine spaetere EVCC-Fassung ihn
 * benutzt. Eine HTML-Seite wird NICHT uebernommen - kommt ein Proxy
 * dazwischen, stuende sonst eine halbe Fehlerseite in der Statuszeile.
 */
function ev_http_fehlertext($code, $url, $body)
{
    $t = sprintf(ev_t('FEHLER.HTTP_VON'), (int) $code, $url);
    $b = trim((string) $body);
    if ($b === '') { return $t; }
    $sagt = '';
    $j = json_decode($b, true);
    if (is_array($j)) {
        foreach (array('error', 'message', 'Error') as $k) {
            if (isset($j[$k]) && is_string($j[$k]) && trim($j[$k]) !== '') {
                $sagt = trim($j[$k]);
                break;
            }
        }
    }
    /* Der Rohtext ist NUR fuer Antworten gedacht, die gar kein JSON sind
     * (gemessen: "404 page not found"). War der Rumpf gueltiges JSON und
     * stand darin kein brauchbarer Grund, wird nichts angehaengt - sonst
     * stuende bei {"error":"   "} das ganze rohe JSON in der Statuszeile.
     * Von der eigenen Eichung gefunden, 10.09.2026. */
    if ($sagt === '' && !is_array($j)
        && strlen($b) <= 120 && strpos($b, '<') === false) {
        $sagt = $b;
    }
    if ($sagt === '') { return $t; }
    $sagt = trim(preg_replace('/\s+/', ' ',
        str_replace(array("\r", "\n", "\t"), ' ', $sagt)));
    if ($sagt === '') { return $t; }
    return $t . ' - ' . sprintf(ev_t('FEHLER.EVCC_SAGT'), $sagt);
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
            // Keiner Umleitung folgen - das Passwort geht sonst an jedes Ziel
            // (Regeln/03). curl folgt ab Werk ohnehin nicht; hier steht es,
            // damit beide Wege sichtbar gleich gebaut sind (C4).
            CURLOPT_FOLLOWLOCATION => false,
        ));
        if ($rumpf !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $rumpf); }
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        $typ = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
        if ($body === false) {
            return array('ok' => 0, 'code' => 0, 'body' => '', 'typ' => '',
                         'fehler' => ev_netzfehler($err, $url));
        }
        return array('ok' => ($code >= 200 && $code < 300) ? 1 : 0, 'code' => $code,
                     'body' => (string) $body, 'typ' => (string) $typ,
                     'fehler' => ($code >= 200 && $code < 300) ? ''
                                 : ev_http_fehlertext($code, $url, $body));
    }

    /* Der Ersatzweg ohne php-curl (C4, seit 0.9.34):
     *   - keiner Umleitung folgen. Bis 0.9.33 folgte file_get_contents einem
     *     302 und schickte 'Authorization: Bearer <Passwort>' an das neue Ziel
     *     (in WSL gemessen, Pruefbericht code, Befund 4); curl tat es nicht.
     *   - die LETZTE Statuszeile gilt (nach einer Umleitung stehen mehrere da).
     *   - der Verbindungsaufbau bekommt dieselbe Zeitgrenze wie das Lesen:
     *     'timeout' gilt nur fuers Lesen, fuer den Aufbau gilt
     *     default_socket_timeout, ab Werk 60 s (Regeln/03, gemessen 8134 ms
     *     gegen 2108 ms). Er wird fuer diesen Aufruf gesetzt und danach
     *     zurueckgestellt. */
    $ctx = stream_context_create(array('http' => array(
        'method' => $methode, 'timeout' => $zeit, 'ignore_errors' => true,
        'follow_location' => 0, 'max_redirects' => 1,
        'header' => implode("\r\n", $kopf),
        'content' => $rumpf === null ? '' : $rumpf,
    )));
    // Der Grund steckt in der unterdrueckten Warnung - ohne ihn sind
    // ECONNREFUSED, Zeitueberschreitung und EHOSTUNREACH ununterscheidbar,
    // und genau das stand bis 0.9.10 als blosses 'keine Antwort' im Protokoll.
    $vorher = error_get_last();
    $ev_dst = ini_get('default_socket_timeout');
    @ini_set('default_socket_timeout', (string) (int) $zeit);
    list($body, $code, $typ) = ev_http_strom($url, $ctx);
    if ($ev_dst !== false) { @ini_set('default_socket_timeout', (string) $ev_dst); }
    if ($body === false) {
        $nachher = error_get_last();
        $grund = ($nachher && $nachher !== $vorher) ? (string) $nachher['message'] : '';
        return array('ok' => 0, 'code' => $code, 'body' => '', 'typ' => '',
                     'fehler' => ev_netzfehler($grund, $url));
    }
    return array('ok' => ($code >= 200 && $code < 300) ? 1 : 0, 'code' => $code,
                 'body' => (string) $body, 'typ' => $typ,
                 'fehler' => ($code >= 200 && $code < 300) ? ''
                             : ev_http_fehlertext($code, $url, $body));
}

/**
 * Eine Adresse ueber den Datenstrom abrufen (Ersatzweg ohne php-curl).
 * Rueckgabe array(Rumpf oder false, HTTP-Code, Content-Type).
 *
 * Die Kopfzeilen kommen aus stream_get_meta_data()['wrapper_data'] (C8, seit
 * 0.9.34). Bis 0.9.33 las das Plugin die vordefinierte Kopfzeilen-Variable
 * von PHP; 8.5 meldet schon deren blosse Nennung beim Uebersetzen als
 * ueberholt, auch hinter einer function_exists-Weiche (gemessen an Docker NG
 * 1.3.9). Entfaellt sie, hiesse jeder Code 0 und jede Antwort Fehlschlag.
 * Bauform ap_http_abruf() (APC-UPS NG 1.2.14), dk_http_kopf() (Docker NG 1.3.9).
 */
function ev_http_strom($url, $ctx)
{
    $fh = @fopen($url, 'rb', false, $ctx);
    if ($fh === false) { return array(false, 0, ''); }
    $meta = @stream_get_meta_data($fh);
    $body = @stream_get_contents($fh);
    @fclose($fh);
    $kopf = (is_array($meta) && isset($meta['wrapper_data']) && is_array($meta['wrapper_data']))
        ? $meta['wrapper_data'] : array();
    $code = 0;
    $typ = '';
    foreach ($kopf as $z) {
        if (!is_string($z)) { continue; }
        if (preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $m)) {
            $code = (int) $m[1];        // die LETZTE Statuszeile gilt
            $typ = '';                  // ... und ihre eigenen Kopfzeilen
        } elseif (stripos($z, 'content-type:') === 0) {
            $typ = trim(substr($z, 13));
        }
    }
    return array($body === false ? '' : (string) $body, $code, $typ);
}

/**
 * Zustand von EVCC holen. Cache: eine halbe Taktlaenge, damit mehrere
 * Aufrufe in derselben Sekunde EVCC nicht mehrfach befragen.
 *
 * Rueckgabe: array('ok','stand','fehler','roh' => <dekodiertes JSON>)
 */
function ev_state($force = false, $hoechstalter = null, $zeit = 8)
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
    $a = ev_http('/api/state', 'GET', null, (int) $zeit);
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
                ev_datei_schreiben($cache, (string) json_encode($c), 0664);
                return $c;
            }
        }
        // Es gibt gar keinen alten Stand. Auch das gehoert festgehalten,
        // sonst fragt jeder Aufruf erneut und laeuft erneut in die Zeitgrenze.
        ev_datei_schreiben($cache, (string) json_encode($st), 0664);
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
        /* Der Satz kommt seit 0.9.34 aus der Sprachdatei (O7): er erreicht
         * einen Menschen - im Reiter Test, in LETZTER_FEHLER und ueber MQTT. */
        $st['fehler'] = sprintf(ev_t('FEHLER.KEIN_JSON'), (int) $a['code'])
            . ($ev_typ !== '' ? sprintf(ev_t('FEHLER.KEIN_JSON_TYP'), $ev_typ) : '')
            . (stripos($ev_typ, 'html') !== false || stripos($ev_kopf, '<html') !== false
               ? ' ' . ev_t('FEHLER.ZWISCHENSTELLE') : '')
            . ($ev_kopf !== '' ? ' ' . sprintf(ev_t('FEHLER.ANFANG'), $ev_kopf) : '');
        $st['fehlernr'] = 2;
        ev_log_wenn_neu('abruf', $st['fehler']);
        // Auch dieser Stand gehoert in den Zwischenspeicher: sonst fragt jeder
        // Aufruf erneut und laeuft erneut in die Zeitgrenze.
        /* Seit 0.9.34 bleibt auch hier der alte Stand stehen, entwertet (ok 0,
         * Fehler dazu) - wie beim ausbleibenden Abruf oben. Bis 0.9.33 warf
         * eine HTML-Antwort einer Zwischenstelle alle Werte weg; mit dem 503
         * vor dem ersten Abruf (C5) hiesse das sonst 503 trotz altem Stand
         * (Entscheidung 8: mit altem Stand 200, OK=0, alte Werte). */
        $c = is_file($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
        if (is_array($c) && !empty($c['roh'])) {
            $c['ok'] = 0;
            $c['fehler'] = $st['fehler'];
            $c['fehlernr'] = 2;
            ev_datei_schreiben($cache, (string) json_encode($c), 0664);
            return $c;
        }
        ev_datei_schreiben($cache, (string) json_encode($st), 0664);
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
    ev_datei_schreiben($cache, (string) json_encode($st), 0664);
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

function ev_felder($ev_namen_vorgabe = null)
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
            'typ' => 'zahl', 'analog' => 1, 'min' => -1, 'max' => 48, 'einheit' => '',
            'text' => 'FELD.PREIS_RANG', 'mqtt' => '', 'quelle' => 'doku');
        $f['preis_stunden'] = array(
            'pfade' => array('lox.preis.anzahl'),
            'typ' => 'zahl', 'analog' => 1, 'min' => -1, 'max' => 48, 'einheit' => '',
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
    /* $ev_namen_vorgabe: der Reiter Test bildet damit BEIDE Zweige - ohne
     * und mit Fahrzeugnamen - und vergleicht sie (O5, seit 0.9.34). */
    $namen = ($ev_namen_vorgabe !== null) ? array_values($ev_namen_vorgabe) : ev_fahrzeugnamen();
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
    /* max 99999, nicht 86400: genau diese Zahl ist der Fehlwert weiter
     * unten ("noch nie gemessen"). Mit MaxVal 86400 kappte Loxone ihn, und
     * "noch nie gemessen" war von "genau 24 h alt" nicht mehr zu
     * unterscheiden. Eine Zahl, eine Stelle. */
    $f['alter_s'] = array('pfade' => array(), 'typ' => 'zahl', 'analog' => 1, 'min' => 0, 'max' => 99999,
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

    /* ---- Neu in 0.9.34 (C1): taugt die Preisvorschau? ----
     *
     * 1 = die Preise gelten fuer die laufende Stunde (frisch geholt oder aus
     * einem frueheren Abruf, solange er sie abdeckt), 0 = keine Aussage. Dann
     * steht PREIS_RANG, PREIS_STUNDEN und PREIS_GUENSTIGSTE_STUNDE auf -1
     * (HTTP) bzw. '-' (MQTT). Bis 0.9.33 wurde aus einem gescheiterten
     * Tarifabruf Rang 0 - und "Rang kleiner gleich 6" hiess Dauerfreigabe
     * (Pruefbericht code, Befund 1). Am ENDE der Tabelle, damit sich die
     * Reihenfolge der Statuszeile nicht verschiebt. */
    if (!empty($cfg['tarife_ein'])) {
        $f['preis_ok'] = array('pfade' => array('lox.preis.ok'), 'typ' => 'bool', 'analog' => 0,
            'min' => 0, 'max' => 1, 'einheit' => '', 'text' => 'FELD.PREIS_OK', 'mqtt' => '',
            'quelle' => 'bestand', 'seit' => '0.9.34');
    }

    /* ---- Seit wann gibt es das Feld? ----
     *
     * Das ist eine ANDERE Frage als 'quelle'. 'quelle' sagt, ob ein Feld an
     * einer Anlage gemessen oder aus der EVCC-Dokumentation uebernommen ist;
     * 'seit' sagt, ab welcher Fassung es in der Tabelle steht. Gemessen am
     * 04.09.2026 gegen das Tag-Archiv v0.9.10: fuenf Felder tragen
     * quelle=bestand und kamen trotzdem erst in 0.9.11 dazu
     * (betriebsbereit, fehler_nr, letzter_fehler, fz*_limit_soc). Wer nach
     * 'quelle' sortiert, laesst genau die vorne stehen.
     *
     * Diese Liste steht hier an EINER Stelle, damit sie beim naechsten neuen
     * Feld nicht uebersehen wird - und der Reiter Test prueft die Ordnung
     * nach, statt sich auf die Liste zu verlassen. */
    $ev_ab0911_anlage = array(
        'batteriemodus_nr', 'residualleistung_w', 'entladeregelung',
        'netz_bezug_kwh', 'pv_ertrag_kwh', 'speicher_kapazitaet_kwh', 'zusatz_kw',
        'prognose_heute', 'prognose_morgen', 'prognose_uebermorgen',
        'preis_min_24h', 'preis_max_24h', 'preis_schnitt_24h',
        'preis_rang', 'preis_stunden', 'preis_guenstigste_stunde',
        'fehler_nr', 'betriebsbereit', 'letzter_fehler',
    );
    $ev_ab0911_lp = array(
        'min_soc', 'minstrom_a', 'maxstrom_a', 'smartcost_grenze', 'batterieboost',
        'phasen_soll', 'pv_warten_min', 'phasen_warten_min', 'gesamt_kwh',
        'sitzung_kwh', 'sitzung_preis', 'sitzung_preis_kwh', 'sitzung_co2',
        'ladedauer_min', 'rest_kwh', 'strom_a', 'plan_soc', 'plan_kwh',
        'fahrzeug_name',
    );
    $ev_ab0911_fz = array('limit_soc');

    $ev_spaet = array();
    foreach ($ev_ab0911_anlage as $ev_n) { $ev_spaet[$ev_n] = 1; }
    for ($ev_i = 1; $ev_i <= EV_LADEPUNKTE; $ev_i++) {
        foreach ($ev_ab0911_lp as $ev_n) { $ev_spaet['lp' . $ev_i . '_' . $ev_n] = 1; }
    }
    for ($ev_i = 1; $ev_i <= EV_FAHRZEUGE; $ev_i++) {
        foreach ($ev_ab0911_fz as $ev_n) { $ev_spaet['fz' . $ev_i . '_' . $ev_n] = 1; }
    }

    /* Vorgaben ergaenzen, damit die 40 Eintraege des Bestandes unangetastet
     * bleiben konnten. 'bestand' heisst: seit 0.9.x im Betrieb. 'doku' heisst:
     * in 0.9.11 aus der EVCC-Dokumentation ergaenzt und an keiner Anlage
     * gemessen - der Reiter Test zaehlt die getrennt. */
    foreach ($f as $ev_n => $ev_d) {
        if (!isset($f[$ev_n]['quelle'])) { $f[$ev_n]['quelle'] = 'bestand'; }
        if (!isset($f[$ev_n]['zeile'])) { $f[$ev_n]['zeile'] = 1; }
        if (!isset($f[$ev_n]['seit'])) {
            $f[$ev_n]['seit'] = isset($ev_spaet[$ev_n]) ? '0.9.11' : '0.9.10';
        }
    }

    /* ---- Stabile Teilung: erst der Stand 0.9.10, dann alles Spaetere ----
     *
     * Innerhalb beider Haelften bleibt die bisherige Reihenfolge Zeichen fuer
     * Zeichen erhalten - es wird nichts umgestellt, nur verschoben. Ab hier
     * gilt: ein neues Feld wird ANGEHAENGT und traegt 'seit' mit der Fassung,
     * in der es dazukam. */
    $ev_alt = array();
    $ev_neu = array();
    foreach ($f as $ev_n => $ev_d) {
        if ($ev_d['seit'] === '0.9.10') { $ev_alt[$ev_n] = $ev_d; }
        else { $ev_neu[$ev_n] = $ev_d; }
    }
    return array_merge($ev_alt, $ev_neu);
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
        // Endet der bisherige Text schon auf einem Doppelpunkt, kein
        // zweites Trennzeichen dazusetzen.
        $t .= (substr($t, -1) === ':' ? ' ' : ': ') . $s;
    }
    $t = trim(preg_replace('/\s+/', ' ', $t));
    if ($grenze > 0 && strlen($t) > $grenze) {
        $t = substr($t, 0, $grenze);
        /* Byteweise kappen darf kein angeschnittenes Mehrbytezeichen
         * zuruecklassen. Gemessen an 0.9.26: lag ein Umlaut genau auf der
         * Grenze, war das Ergebnis ungueltiges UTF-8, json_encode() lieferte
         * false, und aktion=json antwortete mit HTTP 200 und NULL Byte -
         * ausgerechnet dann, wenn EVCC eine lange Fehlermeldung liefert.
         * Ohne mbstring, das auf einem LoxBerry nicht garantiert ist. */
        $t = preg_replace('/(?:[\xC0-\xFF][\x80-\xBF]*)$/', '', $t);
        $t = rtrim($t) . ' [...]';
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
        if ($neu !== '' && $eigen !== '' && ev_fassung_neuer($neu, $eigen)) {
            $out['neuer'] = $neu;
        }
    }
    return $out;
}

/** Lademodus als Zahl: 0 aus, 1 sofort, 2 min+PV, 3 nur PV. *//** Lademodus als Zahl: 0 aus, 1 sofort, 2 min+PV, 3 nur PV. */
/** Die Lademodi von EVCC - EINE Tabelle fuer alle drei Verwender. */
function ev_modus_liste()
{
    return array(0 => 'off', 1 => 'now', 2 => 'minpv', 3 => 'pv');
}

function ev_modus_nr($text)
{
    $k = array_flip(ev_modus_liste());
    $t = strtolower(trim((string) $text));
    return isset($k[$t]) ? $k[$t] : 0;
}

/* ev_modus_text() gibt es seit 0.9.27 nicht mehr. Sie bildete eine Zahl auf
 * einen Modusnamen ab und fiel dabei fuer JEDE unbekannte Zahl auf 'off'
 * zurueck - und genau daran hing der Fehler, dass ein Lademodus 4 oder 7 die
 * Ladung beendete statt abgewiesen zu werden. Nach der Berichtigung rief sie
 * niemand mehr; eine Funktion, die nur noch ein Kommentar rechtfertigt, wird
 * entfernt und nicht aufgehoben. Die Zuordnung steht in ev_modus_liste(). */

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
    $roh = isset($st['roh']) && is_array($st['roh']) ? $st['roh'] : array();
    $cfg = ev_config();
    $out = array();
    foreach (ev_felder() as $name => $d) {
        if (empty($d['pfade'])) {
            /* Ein Fahrzeugplatz ohne Fahrzeug (fz*): das Feld hat keinen Pfad,
             * es gibt keine Aussage (M1, seit 0.9.34). Die eigenen Felder (ok,
             * alter_s, ...) setzt der Schluss dieser Funktion. */
            $out[$name] = array('wert' => 0, 'pfad' => '',
                                'ohne' => preg_match('/^fz[0-9]+_/', $name) ? 1 : 0);
            continue;
        }
        list($w, $pfad) = ev_hole($roh, $d['pfade']);
        if ($d['typ'] === 'modus') {
            $out[$name] = array('wert' => $w === null ? 0 : ev_modus_nr($w), 'pfad' => $pfad,
                                'ohne' => $w === null ? 1 : 0);
            continue;
        }
        $u = ev_umrechnen($d['typ'], $w);
        /* 'ohne' = der Abruf lieferte das Feld nicht (M1, C5, seit 0.9.34).
         * 'wert' bleibt 0 wie bisher; was daraus wird, entscheiden die Ausgaben:
         * die Statuszeile setzt fuer Zustaende und die Rangzahlen -1
         * (ev_ohne_minus1()), MQTT sendet einen Zustand einmal als '-'
         * retained (ev_mqtt_publish()). Nie eine erfundene 0 fuer einen Zustand. */
        $out[$name] = array('wert' => $u === null ? 0 : $u, 'pfad' => $pfad,
                            'ohne' => $u === null ? 1 : 0);
    }
    /* Die eigenen Felder.
     *
     * OK haengt seit 0.9.34 zusaetzlich am Alter (Entscheidung 4, C6): 0,
     * sobald der letzte gelungene Abruf aelter ist als 3 x Takt. Bis 0.9.33
     * lieferte der Endpunkt bei Takt 5 einen bis zu 29 s alten Stand als OK=1
     * (Pruefbericht code, Befund 6). ALTER_S bleibt unveraendert daneben. */
    $ev_takt = max(5, min(60, (int) $cfg['takt']));
    $ev_alter = !empty($st['stand']) ? max(0, time() - (int) $st['stand']) : 99999;
    $ev_ok = !empty($st['ok']) && $ev_alter <= 3 * $ev_takt;
    $out['ok'] = array('wert' => $ev_ok ? 1 : 0, 'pfad' => '-');
    $out['alter_s'] = array('wert' => $ev_alter, 'pfad' => '-');
    /* Ueber MQTT gibt es kein Alter, nur einen Zeitstempel (Regeln/07,
     * Abschnitt 3): ts ist der Zeitpunkt des letzten GELUNGENEN Abrufs in
     * Unix-Sekunden, 0 = noch nie. Er bleibt bei einem Fehlschlag stehen, das
     * Alter rechnet Loxone selbst: (Loxone-Zeit + 1230768000) - ts. Seit
     * 0.9.33; alter_s bleibt in der HTTP-Zeile. */
    $out['ts'] = array('wert' => !empty($st['stand']) ? (int) $st['stand'] : 0, 'pfad' => '-');
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
        'wert' => ($ev_ein['fatal'] === '' && $ev_ein['einrichtung'] !== 0 && $ev_ok) ? 1 : 0,
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

/**
 * Bekommt ein Feld ohne Aussage in der Statuszeile -1 statt 0? (C5, M1, seit 0.9.34)
 *
 * Nr. 5/8 der Entscheidungen: Zahlen ohne Aussage gehen als -1 hinaus, weil
 * Loxone ein '-' als 0 liest. Das gilt fuer
 *   - die Zustaende der Retain-Tabelle, die eine Zahl sind und deren
 *     Wertebereich -1 nicht enthaelt (Lademodus, Ladegrenzen, Prioritaet,
 *     Stromgrenzen, Ladeplan, Speicher-Ladestaende, Batteriemodus ...), und
 *   - die Rangzahlen der Preisvorschau (PREIS_RANG, PREIS_STUNDEN,
 *     PREIS_GUENSTIGSTE_STUNDE), Bauliste C1.
 * NICHT fuer Ja/Nein-Felder: ein Digitaleingang in Loxone kann -1 nicht
 * darstellen; dort sagen OK und BETRIEBSBEREIT, ob der Wert gilt. Und nicht
 * fuer Messwerte (Leistungen, Energien): 0 kW eines fehlenden Geraets ist
 * dort die gewohnte Auskunft, der Reiter Test nennt die fehlenden Felder.
 */
function ev_ohne_minus1($name, $d)
{
    if (in_array($name, array('preis_rang', 'preis_stunden', 'preis_guenstigste_stunde'), true)) {
        return true;
    }
    return ev_retain_fuer($name) && !empty($d['analog']) && (float) $d['min'] >= 0;
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
        return array(0, ev_t('FEHLER.UNBEKANNTER_BEFEHL'));
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
 * Steht das Paket evcc auf "halten"?
 *
 * Ein gehaltenes Paket laesst sich mit -y nicht anfassen; apt bricht ab mit
 * "Held packages were changed and -y was used without
 * --allow-change-held-packages" und Rueckgabewert 100. Gemessen am
 * 10.09.2026 auf dem LoxBerry: apt-mark showhold meldete evcc, zwei
 * Knopfdruecke endeten mit 100, und die Selbstpruefung zeigte trotzdem einen
 * Haken bei "Kann die Oberflaeche EVCC aktualisieren?".
 *
 * Zwei Quellen, weil apt-mark auf aelteren Staenden fehlen kann. Rueckgabe:
 * 1 gehalten, 0 frei, -1 nicht feststellbar - und "nicht feststellbar" ist
 * ausdruecklich nicht dasselbe wie "frei".
 */
function ev_paket_gehalten()
{
    /* dpkg ZUERST. Beide lesen denselben Zustand aus /var/lib/dpkg/status,
     * aber apt-mark laedt dazu den ganzen Paketbestand. Gemessen am
     * 10.09.2026 auf dem LoxBerry:
     *     dpkg --get-selections evcc   0,06 s
     *     apt-mark showhold            7,85 s
     * In 0.9.29 stand apt-mark zuerst - zusammen mit dem Kandidaten kostete
     * das jeden Seitenaufruf 17 Sekunden. */
    $aus = array();
    $rc = 1;
    @exec('LC_ALL=C dpkg --get-selections evcc 2>/dev/null', $aus, $rc);
    if ($rc === 0 && $aus) {
        foreach ($aus as $z) {
            if (preg_match('/^evcc\s+hold$/', trim($z))) { return 1; }
            if (preg_match('/^evcc\s+\S+$/', trim($z))) { return 0; }
        }
    }
    $aus = array();
    $rc = 1;
    @exec('LC_ALL=C apt-mark showhold 2>/dev/null', $aus, $rc);
    if ($rc === 0) {
        foreach ($aus as $z) {
            if (trim($z) === 'evcc') { return 1; }
        }
        return 0;
    }
    return -1;
}

/**
 * Welche Fassung wuerde apt einspielen?
 *
 * Das ist die Zahl, die der Knopf wirklich holt - im Unterschied zu EVCCs
 * eigener Angabe 'availableVersion', die die neueste STABILE Fassung meldet,
 * die EVCC kennt. Gemessen am 10.09.2026 standen beide Zahlen gleichzeitig
 * da: EVCC sagte 0.315.0, apt haette 0.316.0~dev.1788920311 genommen.
 *
 * LC_ALL=C, weil apt seine Feldnamen uebersetzt; ohne das findet das Muster
 * auf einem deutschen System nichts und die Zeile schwiege still.
 */
function ev_apt_kandidat($frisch = false)
{
    $datei = ev_tmpdir() . '/apt_kandidat.txt';

    /* Der Regelfall: NUR lesen. Der Aufruf selbst kostet auf dem Geraet
     * ueber acht Sekunden (gemessen 10.09.2026: apt-cache policy evcc
     * 8,57 s), weil apt dafuer den ganzen Paketbestand einliest. An einem
     * Seitenaufruf hat er deshalb nichts zu suchen - in 0.9.29 hing er
     * dort und machte die Oberflaeche mit 19 Sekunden unbenutzbar.
     *
     * Steht nichts Frisches bereit, wird '' zurueckgegeben und die
     * Oberflaeche sagt nichts ueber den Kandidaten. Das ist "konnte ich
     * nicht feststellen", nicht "es gibt keinen" - beides bleibt
     * unterscheidbar. */
    if (!$frisch) {
        clearstatcache(true, $datei);
        if (is_file($datei) && (time() - (int) @filemtime($datei)) < 3600) {
            return trim((string) @file_get_contents($datei));
        }
        return '';
    }

    /* Bestimmt wird die Zahl vom Abrufdienst, hoechstens einmal je Stunde.
     * Geschrieben wird auch ein LEERES Ergebnis: sonst versuchte es der
     * naechste Lauf sofort wieder, und auf einer Maschine ohne apt liefe
     * jede Minute ein Fehlversuch. */
    $aus = array();
    $rc = 1;
    @exec('LC_ALL=C apt-cache policy evcc 2>/dev/null', $aus, $rc);
    $wert = '';
    if ($rc === 0) {
        foreach ($aus as $z) {
            if (preg_match('/^\s*Candidate:\s*(\S+)/', $z, $m)) {
                $wert = ($m[1] === '(none)') ? '' : $m[1];
                break;
            }
        }
    }
    @file_put_contents($datei, $wert);
    return $wert;
}

/**
 * Fassungsangabe auf eine vergleichbare Form bringen.
 *
 * apt schreibt die Tilde (0.315.0~dev.1786876734+3c25327f7), 'evcc -v' den
 * Bindestrich (0.315.0-dev+3c25327f7); Regeln/12 haelt beide Schreibweisen
 * fest. Der Commit-Anhang hinter dem Pluszeichen sagt nichts ueber die
 * Reihenfolge und faellt weg.
 */
function ev_fassung_norm($v)
{
    $v = trim((string) $v);
    $v = preg_replace('/^[^0-9]*/', '', $v);
    $v = str_replace('~', '-', $v);
    $v = preg_replace('/\+.*$/', '', $v);
    return (string) $v;
}

/**
 * Ist $angeboten WIRKLICH neuer als $eigen?
 *
 * Bis 0.9.28 stand in ev_einrichtung() nur $neu !== $eigen - blosse
 * Ungleichheit. Damit meldet die Oberflaeche auch einen RUECKSCHRITT als
 * neuere Fassung. Heute ging das gut aus; sobald einmal 0.316.0-dev
 * eingespielt ist, meldete dieselbe Zeile 0.315.0 als "neuer".
 *
 * version_compare kennt die Ordnung dev < alpha < beta < RC < Release, und
 * genau die wird hier gebraucht.
 */
function ev_fassung_neuer($angeboten, $eigen)
{
    $a = ev_fassung_norm($angeboten);
    $e = ev_fassung_norm($eigen);
    if ($a === '' || $e === '') { return 0; }
    return version_compare($a, $e, '>') ? 1 : 0;
}

/**
 * Traegt der Weg zum Aktualisieren? Gemessen, nicht vermutet.
 *
 * Rueckgabe:
 *   skript    1/0   liegt /usr/local/sbin/loxberry-evcc-update da?
 *   gehalten  1/0/-1 steht das Paket auf halten?
 *   kandidat  ''    was apt einspielen wuerde
 *   traegt    1/0   beides zusammen: der Knopf kann wirken
 */
function ev_update_lage()
{
    $skript = ev_update_moeglich() ? 1 : 0;
    $gehalten = $skript ? ev_paket_gehalten() : -1;
    return array(
        'skript'   => $skript,
        'gehalten' => $gehalten,
        'kandidat' => $skript ? ev_apt_kandidat() : '',
        'traegt'   => ($skript === 1 && $gehalten === 0) ? 1 : 0,
    );
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
    /* Nach einem Lauf stimmt der hinterlegte Kandidat nicht mehr. Er
     * wird weggeworfen, nicht neu bestimmt: der naechste Cron-Lauf holt
     * ihn innerhalb einer Minute nach, und der Knopf wird nicht um acht
     * Sekunden laenger. */
    @unlink(ev_tmpdir() . '/apt_kandidat.txt');
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
    $out = array('gefunden' => 0, 'udpport' => 0, 'autostart' => 0, 'fassung' => 0);
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
        /* Die FASSUNG des MQTT-Gateways, ab Werk 1. Sie entscheidet, was der
         * Anwender eintragen muss: unter V1 jedes Thema von Hand, ab V2
         * erscheint die Themengruppe von selbst in den Subscriptions.
         * 0 heisst "nicht feststellbar" - dann wird nichts behauptet,
         * sondern es werden beide Faelle genannt.
         *
         * Sie steht HIER und nicht in der Autostart-Schleife darunter. Bis
         * 0.9.26 hing sie dort mit drin: fehlte der Schluessel
         * Gatewayautostart, blieb die Fassung ungelesen, obwohl
         * Gatewayversion unmittelbar daneben stand. Gemessen an drei
         * general.json - nicht der Wert des Nachbarschluessels entschied,
         * sondern sein blosses Vorhandensein. */
        if (isset($gen[$k]['Gatewayversion'])) {
            $out['fassung'] = (int) $gen[$k]['Gatewayversion'];
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


/**
 * Welche Themen gehen ZURUECKBEHALTEN (retained) hinaus?
 *
 * Hausstandard seit 03.09.2026: Zustaende retained, damit Loxone nach einem
 * Neustart des Miniservers oder des Gateways sofort den Stand hat; Messwerte
 * mit Zeitbezug nicht, damit kein alter Wert als aktuell erscheint; das
 * Lebenszeichen nie.
 *
 * Drei Gruppen, damit ein dritter Ladepunkt nichts von Hand verlangt: die
 * Ladepunkt- und Fahrzeugnamen werden ohne ihre Nummer nachgeschlagen.
 *
 * NICHT in dieser Tabelle stehen mit Absicht:
 *   ok, ts, dienst, betriebsbereit  - das ist das Lebenszeichen. Wer es
 *       zurueckbehaelt, laesst nach einem gestorbenen Cron fuer immer
 *       "laeuft" im Broker stehen.
 *   letzter_fehler, lpN_fahrzeug_name    - im Regelfall LEER. Eine leere
 *       Nutzlast loescht ein zurueckbehaltenes Thema; ein Thema, das
 *       ueblicherweise leer ist, gehoert deshalb nicht retained gesendet.
 *   alle Leistungen, Energien, Preise, Prognosen, Sitzungswerte, der
 *       gemessene Ladestand und die Restzeiten - Messwerte mit Zeitbezug.
 *   lpN_pv_warten_min, lpN_phasen_warten_min - ebenfalls Restzeiten ("noch
 *       4 min"): sie laufen von selbst ab und sind damit Messwerte mit
 *       Zeitbezug (Regeln/07, Abschnitt 3, Entscheidung vom 18.09.2026 zum
 *       Alter). Bis 0.9.32 standen sie hier; den Altwert raeumt
 *       ev_mqtt_altlast() ab.
 *
 * Die Tabelle sagt, was retained gehen DARF. Ob es in einem Lauf wirklich
 * retained geht, entscheidet ev_mqtt_publish() zusaetzlich daran, ob EVCC in
 * diesem Lauf geantwortet hat (seit 0.9.33): ohne Antwort sind die Werte
 * Platzhalter oder der alte Stand und gehen fluechtig hinaus, und im Broker
 * bleibt der zuletzt von EVCC gemeldete Stand (Bauart Robonect 1.1.12).
 *
 * fehler_nr steht seit 0.9.34 NICHT mehr hier (M4). Die 0 sagt zuerst, dass
 * der Abruf DES PLUGINS gelang; stirbt der Cron, bliebe "kein Fehler"
 * retained stehen (Pruefbericht mqtt, B4). Ein Ausfallmerker ist nie retained
 * (Regeln/07, Abschnitt 3, Entscheidung vom 19.09.2026). Der alte Wert im
 * Broker wird einmal abgeraeumt, mit Bestaetigung (ev_mqtt_altlast()).
 */
function ev_retain_liste()
{
    return array(
        /* Einstellungen der Anlage (fehler_nr seit 0.9.34 nicht mehr, M4). */
        'anlage' => array(
            'netzladen_aktiv' => 1, 'prioritaets_soc' => 1, 'puffer_soc' => 1,
            'residualleistung_w' => 1, 'entladeregelung' => 1,
            'batteriemodus_nr' => 1, 'speicher_kapazitaet_kwh' => 1,
        ),
        /* Je Ladepunkt, ohne die Nummer: lp1_modus_nr, lp2_modus_nr, ... */
        'ladepunkt' => array(
            'verbunden' => 1, 'laedt' => 1, 'freigegeben' => 1,
            'plan_aktiv' => 1, 'smartcost_aktiv' => 1, 'modus_nr' => 1,
            'limit_soc' => 1, 'prioritaet' => 1, 'min_soc' => 1,
            'minstrom_a' => 1, 'maxstrom_a' => 1, 'smartcost_grenze' => 1,
            'batterieboost' => 1, 'phasen_soll' => 1,
            'plan_soc' => 1, 'plan_kwh' => 1,
        ),
        /* Je Fahrzeug: der eingestellte Ladestand, nicht der gemessene. */
        'fahrzeug' => array(
            'limit_soc' => 1,
        ),
    );
}

/**
 * Geht dieses Feld retained hinaus?
 *
 * $nutzlast wird mitgegeben, wo sie schon feststeht: eine LEERE Nutzlast
 * loescht ein zurueckbehaltenes Thema im Broker ("Delete $udptopic from
 * memory because of empty message", mqttgateway.pl, sub udpin). Sie geht
 * deshalb immer als publish hinaus, auch wenn die Tabelle retain sagt.
 */
function ev_retain_fuer($name, $nutzlast = null)
{
    if ($nutzlast !== null && (string) $nutzlast === '') { return 0; }
    $l = ev_retain_liste();
    $n = (string) $name;
    if (isset($l['anlage'][$n])) { return 1; }
    if (preg_match('/^lp[0-9]+_(.+)$/', $n, $m)
        && isset($l['ladepunkt'][$m[1]])) { return 1; }
    if (preg_match('/^fz[0-9]+_(.+)$/', $n, $m)
        && isset($l['fahrzeug'][$m[1]])) { return 1; }
    return 0;
}

/**
 * Die Themen, deren zurueckbehaltener ALTWERT abgeraeumt werden muss, und je
 * Thema die Werte, die dabei NICHT als Altwert zaehlen.
 *
 * Eine Umstellung von retain auf publish loescht nichts: der alte Wert steht
 * im Broker weiter und wird nach jedem Neustart von Broker oder Gateway
 * wieder ausgeliefert. Seit 0.9.33:
 *   lpN_pv_warten_min, lpN_phasen_warten_min - bis 0.9.32 retained; jeder
 *       zurueckbehaltene Wert ist ein Altwert.
 *   fehler_nr - seit 0.9.34 ganz fluechtig (M4): JEDER zurueckbehaltene Wert
 *       ist ein Altwert. Bis 0.9.33 galten 0, 4 und 5 als erlaubt.
 * Mit $werte nur die Themen, die in diesem Lauf einen Wert haben - abgeraeumt
 * wird unmittelbar vor dem gueltigen Wert, nie ohne ihn.
 */
function ev_mqtt_altlast_liste($werte = null)
{
    $l = array();
    for ($i = 1; $i <= EV_LADEPUNKTE; $i++) {
        $l['lp' . $i . '_pv_warten_min'] = array();
        $l['lp' . $i . '_phasen_warten_min'] = array();
    }
    $l['fehler_nr'] = array();
    if ($werte !== null) {
        foreach (array_keys($l) as $n) {
            if (!isset($werte[$n])) { unset($l[$n]); }
        }
    }
    return $l;
}

/**
 * Den Broker fragen, welche der Themen $themen er zurueckbehaelt - in EINER
 * Verbindung, ein SUBSCRIBE mit allen Filtern.
 *
 * Rueckgabe array('lage' => 'ok'|'unbekannt', 'belegt' => array(thema => wert)).
 * 'ok' heisst: der Broker hat die Anmeldung (CONNACK 0) und JEDEN Filter
 * (SUBACK-Rueckgabe unter 0x80) bestaetigt; was dann nicht unter 'belegt'
 * steht, ist leer. 'unbekannt': er war nicht zu fragen (keine Wurzel, keine
 * general.json, keine Verbindung, Anmeldung abgewiesen, Filter abgelehnt,
 * keine Antwort) - das heisst nie "nichts belegt" (Muster 11 der Nachlese).
 *
 * Warum ueberhaupt fragen: das Abraeumen laeuft ueber den UDP-Eingang des
 * Gateways, und dort meldet fwrite() auch fuer ein verworfenes Datagramm
 * Erfolg (Regeln/07, "Ein Absender merkt nichts davon", Nachtrag vom
 * 19.09.2026). Belegt ist das Abraeumen erst, wenn der Broker selbst sagt,
 * dass nichts mehr dasteht.
 *
 * MQTT 3.1.1 von Hand, nur CONNECT, SUBSCRIBE (QoS 0) und DISCONNECT - ohne
 * fremde Bibliothek; Bauart bw_mqtt_behalten_liste() (Beschattungswaechter
 * 0.9.21), dort aus tb_mqtt_behalten_liste() (Spotpreis-Tibber 0.9.19).
 * Anders als dort kommt der WERT mit zurueck: fehler_nr ist nur mit
 * bestimmten Werten ein Altwert (ev_mqtt_altlast_liste()). Belegt ist ein
 * Thema nur am EMPFANGENEN Paket mit Retain-Merkmal und nicht leerer Nutzlast.
 * Die Anmeldung nimmt Brokeruser/Brokerpass aus der general.json (Regeln/07,
 * Abschnitt 2); das Kennwort steht nur im CONNECT-Paket, nie in einem
 * Protokoll und nie auf einer Kommandozeile.
 */
function ev_mqtt_behalten_liste(array $themen)
{
    $aus = array('lage' => 'unbekannt', 'belegt' => array());
    $soll = array();
    foreach ($themen as $t) {
        if ((string) $t !== '') { $soll[(string) $t] = true; }
    }
    if (!$soll) {
        $aus['lage'] = 'ok';
        return $aus;
    }
    $p = ev_paths();
    if ($p['home'] === '') { return $aus; }
    $d = @json_decode((string) @file_get_contents($p['home'] . '/config/system/general.json'), true);
    if (!is_array($d) || !isset($d['Mqtt']) || !is_array($d['Mqtt'])) { return $aus; }
    $m = $d['Mqtt'];
    $hol = function ($k) use ($m) {
        return (isset($m[$k]) && is_scalar($m[$k])) ? (string) $m[$k] : '';
    };
    $host = trim($hol('Brokerhost'));
    if ($host === '' || $host === 'localhost') { $host = '127.0.0.1'; }
    $port = (int) $hol('Brokerport');
    if ($port <= 0 || $port > 65535) { $port = 1883; }
    $benutzer = $hol('Brokeruser');
    $kennwort = $hol('Brokerpass');

    $errno = 0;
    $errstr = '';
    $s = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 2);
    if (!$s) { return $aus; }
    stream_set_timeout($s, 1);

    $zk = function ($t) { return pack('n', strlen($t)) . $t; };
    $laenge = function ($n) {
        $o = '';
        do {
            $b = $n % 128;
            $n = intdiv($n, 128);
            if ($n > 0) { $b |= 128; }
            $o .= chr($b);
        } while ($n > 0);
        return $o;
    };
    /* Genau $n Bytes lesen oder null - bei Zeitablauf und Verbindungsende. */
    $lies = function ($n) use ($s) {
        $d = '';
        while (strlen($d) < $n) {
            $t = @fread($s, $n - strlen($d));
            if ($t === false || $t === '') {
                $meta = stream_get_meta_data($s);
                if (!empty($meta['timed_out']) || !empty($meta['eof']) || feof($s)) { return null; }
                continue;
            }
            $d .= $t;
        }
        return $d;
    };
    /* Ein Paket: array(kopfbyte, rumpf) oder null. */
    $paket = function () use ($lies) {
        $k = $lies(1);
        if ($k === null) { return null; }
        $n = 0;
        $mult = 1;
        for ($i = 0; $i < 4; $i++) {
            $b = $lies(1);
            if ($b === null) { return null; }
            $n += (ord($b) & 127) * $mult;
            $mult *= 128;
            if (!(ord($b) & 128)) { break; }
        }
        $r = ($n > 0) ? $lies($n) : '';
        return ($r === null) ? null : array(ord($k), $r);
    };

    $flags = 0x02;                                  // saubere Sitzung
    $nutz = $zk('evrueck' . getmypid());
    if ($benutzer !== '') {
        $flags |= 0x80;
        // Ein Kennwort ohne Benutzer laesst MQTT 3.1.1 nicht zu.
        if ($kennwort !== '') { $flags |= 0x40; }
    }
    $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', 10);
    if ($benutzer !== '') {
        $nutz .= $zk($benutzer);
        if ($kennwort !== '') { $nutz .= $zk($kennwort); }
    }
    if (@fwrite($s, chr(0x10) . $laenge(strlen($kopf . $nutz)) . $kopf . $nutz) !== false) {
        $ack = $paket();
        if ($ack !== null && ($ack[0] >> 4) === 2 && strlen($ack[1]) >= 2 && ord($ack[1][1]) === 0) {
            $sub = pack('n', 1);
            foreach (array_keys($soll) as $t) { $sub .= $zk($t) . chr(0); }
            @fwrite($s, chr(0x82) . $laenge(strlen($sub)) . $sub);
            $bestaetigt = false;
            $abgelehnt = false;
            $ende = microtime(true) + 3.0;
            while (microtime(true) < $ende) {
                $pk = $paket();
                if ($pk === null) { break; }           // Zeitablauf: nichts mehr gekommen
                $art = $pk[0] >> 4;
                if ($art === 9) {
                    /* Je Filter ein Rueckgabebyte hinter der Paketkennung;
                       0x80 heisst abgelehnt. */
                    $rc = (string) substr($pk[1], 2);
                    if (strlen($rc) !== count($soll)) { $abgelehnt = true; }
                    for ($i = 0; $i < strlen($rc); $i++) {
                        if (ord($rc[$i]) >= 0x80) { $abgelehnt = true; }
                    }
                    if ($abgelehnt) { break; }
                    $bestaetigt = true;
                    // Zurueckbehaltenes kommt unmittelbar nach dem SUBACK.
                    $ende = min($ende, microtime(true) + 1.0);
                } elseif ($art === 3 && strlen($pk[1]) >= 2) {
                    $tl = unpack('n', substr($pk[1], 0, 2));
                    $t = substr($pk[1], 2, $tl[1]);
                    $versatz = 2 + $tl[1] + ((($pk[0] >> 1) & 3) > 0 ? 2 : 0);
                    $wert = (string) substr($pk[1], $versatz);
                    // Am empfangenen Paket: nur mit gesetztem Retain-Merkmal.
                    if (isset($soll[$t]) && ($pk[0] & 1) && $wert !== '') {
                        $aus['belegt'][$t] = $wert;
                        if (count($aus['belegt']) === count($soll)) { break; }
                    }
                }
            }
            if ($bestaetigt && !$abgelehnt) {
                $aus['lage'] = 'ok';
            } else {
                $aus['belegt'] = array();
            }
        }
        @fwrite($s, chr(0xE0) . chr(0));
    }
    fclose($s);
    return $aus;
}

/**
 * Welche Altwerte muessen in diesem Versand abgeraeumt werden?
 *
 * Rueckgabe array('lage' => 'erledigt'|'belegt'|'unbekannt',
 *                 'themen' => array(<thema ohne praefix>, ...)).
 *
 * Solange der Merker nicht liegt, wird der Broker nach allen Themen aus
 * ev_mqtt_altlast_liste() gefragt (ev_mqtt_behalten_liste()), hoechstens
 * einmal je Minute und Prozess (der Cron-Lauf versendet bis zu zwoelfmal):
 * keines mit einem Altwert belegt -> Merker schreiben, nichts abraeumen
 * ('erledigt'); einige belegt -> genau diese ('belegt'), kein Merker; nicht
 * zu fragen -> alle ('unbekannt'), KEIN Merker - dann raeumt jeder Versand
 * ab. Der Merker entsteht NUR aus der Antwort des Brokers, nie aus dem
 * Senden: ueber den UDP-Eingang ist "gesendet" nicht "geloescht" (Regeln/07,
 * Z. 215, am Geraet belegt).
 *
 * Der Merker traegt die Kennung "leer-bestaetigt-0934 <praefix>: <Themenliste>":
 * ein anderes Praefix oder eine andere Zahl von Ladepunkten gilt nicht. Die
 * Fassungskennung ist seit 0.9.34 dabei, weil fehler_nr seither mit JEDEM
 * Wert Altlast ist - ein Merker von 0.9.33 bestaetigte nur "nichts ausser 0,
 * 4, 5". purge_installation
 * raeumt ihn bei jedem Update mit dem Datenordner ab; dann wird einmal
 * nachgefragt.
 */
function ev_mqtt_altlast($praefix, $werte = null)
{
    static $gemerkt = array();
    $praefix = (string) $praefix;
    $liste = ev_mqtt_altlast_liste($werte);
    if (!$liste) { return array('lage' => 'erledigt', 'themen' => array()); }
    $p = ev_paths();
    $merker = $p['datadir'] . '/retain_altlast_bestaetigt';
    $kennung = 'leer-bestaetigt-0934 ' . $praefix . ': ' . implode(' ', array_keys($liste));
    if (is_file($merker) && trim((string) @file_get_contents($merker)) === $kennung) {
        return array('lage' => 'erledigt', 'themen' => array());
    }
    if (isset($gemerkt[$kennung]) && (time() - $gemerkt[$kennung][0]) < 60) {
        return $gemerkt[$kennung][1];
    }
    $voll = array();
    foreach (array_keys($liste) as $n) { $voll[$praefix . '/' . $n] = $n; }
    $f = ev_mqtt_behalten_liste(array_keys($voll));
    if ($f['lage'] === 'ok') {
        $weg = array();
        foreach ($f['belegt'] as $t => $w) {
            if (!isset($voll[$t])) { continue; }
            if (!in_array((string) $w, $liste[$voll[$t]], true)) { $weg[] = $voll[$t]; }
        }
        if (!$weg) {
            if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0775, true); }
            if (@file_put_contents($merker, $kennung . "\n") !== false) {
                ev_log('MQTT: unter ' . $praefix . '/ steht keiner der frueher zurueckbehaltenen '
                    . 'Altwerte mehr im Broker (' . implode(', ', array_keys($liste))
                    . '; vom Broker bestaetigt).');
            }
            return array('lage' => 'erledigt', 'themen' => array());
        }
        $erg = array('lage' => 'belegt', 'themen' => $weg);
        ev_log_wenn_neu('altlast_belegt', 'MQTT: im Broker stehen noch zurueckbehaltene '
            . 'Altwerte unter ' . $praefix . '/ (' . implode(', ', $weg) . ') - sie gehen mit '
            . 'leerer Nutzlast unmittelbar vor dem gueltigen Wert hinaus; danach wird wieder '
            . 'nachgefragt.');
    } else {
        $erg = array('lage' => 'unbekannt', 'themen' => array_keys($liste));
        ev_log_wenn_neu('altlast_unbekannt', 'MQTT: der Broker liess sich nicht befragen '
            . '(Brokerhost, Brokerport und Zugangsdaten in general.json) - die frueher '
            . 'zurueckbehaltenen Werte unter ' . $praefix . '/ gehen deshalb in jedem Versand '
            . 'mit leerer Nutzlast unmittelbar vor dem gueltigen Wert hinaus. Siehe README.');
    }
    $gemerkt[$kennung] = array(time(), $erg);
    return $erg;
}

/**
 * Die Themen, die die Deinstallation leert: jedes, das eine veroeffentlichte
 * Fassung je retained gesendet haben kann - die Tabelle ev_retain_liste() fuer
 * ALLE moeglichen Ladepunkte und Fahrzeuge (die Einstellung kann frueher
 * hoeher gestanden haben) und die Altwerte. Was nie retained ging, bleibt
 * unberuehrt: eine leere Nachricht darauf loeschte nichts, kaeme aber am
 * Miniserver als leerer Wert an.
 */
function ev_mqtt_leer_themen()
{
    $l = ev_retain_liste();
    $t = array_keys($l['anlage']);
    for ($i = 1; $i <= EV_LADEPUNKTE; $i++) {
        foreach (array_keys($l['ladepunkt']) as $k) { $t[] = 'lp' . $i . '_' . $k; }
    }
    for ($i = 1; $i <= EV_FAHRZEUGE; $i++) {
        foreach (array_keys($l['fahrzeug']) as $k) { $t[] = 'fz' . $i . '_' . $k; }
    }
    foreach (array_keys(ev_mqtt_altlast_liste()) as $k) {
        if (!in_array($k, $t, true)) { $t[] = $k; }
    }
    return $t;
}

/**
 * Die zurueckbehaltenen Themen leeren - fuer uninstall/uninstall
 * (bin/ev_abruf.php --mqtt-leeren).
 *
 * Geloescht wird ueber den UDP-Eingang des Gateways, "retain <thema> " mit
 * leerer Nutzlast. VOR der ersten Runde und nach jeder wird der Broker
 * gefragt (ev_mqtt_behalten_liste()); hinaus geht nur, was dort noch steht,
 * hoechstens $runden Runden. Steht nichts da, geht nichts hinaus. Ist der
 * Broker nicht zu fragen, gehen alle Themen in jeder Runde hinaus, und die
 * Ausgabe sagt, dass nicht nachgelesen wurde - der Eingang verwirft unter
 * Last Datagramme (Regeln/07), ein blosses Senden ist kein Beleg. Bauart
 * bw_mqtt_leeren() (Beschattungswaechter 0.9.21).
 *
 * Rueckgabe 0 geleert oder nicht nachpruefbar, 1 es steht noch etwas bzw.
 * der Eingang war nicht erreichbar, 2 nicht moeglich.
 */
function ev_mqtt_leeren($runden = 3, $pause_us = 1000000)
{
    $c = ev_config(false);
    $jetzt = ev_mqtt_thema($c['mqtt_topic']);
    /* Seit 0.9.34 (M3): ALLE je benutzten Praefixe, nicht nur das
     * eingestellte. Nach einem Praefixwechsel blieben bis 0.9.33 die
     * Zustaende unter dem alten Praefix fuer immer im Broker; keine Aktion der
     * Linie erreichte sie, auch die Deinstallation nicht (Pruefbericht mqtt,
     * B3, Fall F14: 42 Themen). */
    $praefixe = array_values(array_unique(array_merge(array($jetzt), ev_mqtt_praefixe())));
    $rc = 0;
    foreach ($praefixe as $w) {
        $themen = array();
        foreach (ev_mqtt_leer_themen() as $t) { $themen[] = ev_mqtt_thema($w . '/' . $t); }
        list($r, $zeilen) = ev_mqtt_leeren_themen($themen, $w, $runden, $pause_us);
        foreach ($zeilen as $z) { echo $z . "\n"; }
        $rc = max($rc, $r);
    }
    return $rc;
}

/**
 * Zurueckbehaltene Themen leeren (M3, seit 0.9.34 als eigene Funktion).
 *
 * Geloescht wird ueber den UDP-Eingang des Gateways, "retain <thema> " mit
 * leerer Nutzlast. VOR der ersten Runde und nach jeder wird der Broker
 * gefragt (ev_mqtt_behalten_liste()); hinaus geht nur, was dort noch steht,
 * hoechstens $runden Runden. Steht nichts da, geht nichts hinaus. Ist der
 * Broker nicht zu fragen, gehen alle Themen in jeder Runde hinaus, und das
 * Ergebnis sagt, dass nicht nachgelesen wurde - der Eingang verwirft unter
 * Last Datagramme (Regeln/07), ein blosses Senden ist kein Beleg. Bauart
 * bw_mqtt_leeren() (Beschattungswaechter 0.9.21).
 *
 * Rueckgabe array(rc, Zeilen fuer das Installationsprotokoll, offen, nachgelesen):
 * rc 0 geleert oder nicht nachpruefbar, 1 es steht noch etwas bzw. der
 * Eingang war nicht erreichbar, 2 nicht moeglich.
 */
function ev_mqtt_leeren_themen(array $alle, $w, $runden = 3, $pause_us = 1000000)
{
    $zeilen = array();
    $z = ev_mqtt_zustand();
    if (!$z['udpport']) {
        $zeilen[] = '<INFO> MQTT: in der general.json steht kein UDP-Eingangsport des Gateways - '
                  . 'zurueckbehaltene Themen unter ' . $w . '/ wurden nicht geleert.';
        return array(2, $zeilen, $alle, false);
    }
    $n = count($alle);
    $f = ev_mqtt_behalten_liste($alle);
    $nachgelesen = ($f['lage'] === 'ok');
    $offen = $nachgelesen ? array_keys($f['belegt']) : $alle;
    if ($nachgelesen && !$offen) {
        $zeilen[] = '<OK> MQTT: der Broker bestaetigt: keines der ' . $n . ' Themen unter ' . $w
                  . '/ steht zurueckbehalten - nichts zu leeren.';
        return array(0, $zeilen, array(), true);
    }
    $eno = 0;
    $etxt = '';
    $fp = @stream_socket_client('udp://127.0.0.1:' . (int) $z['udpport'], $eno, $etxt, 2);
    if (!$fp) {
        $zeilen[] = '<WARNING> MQTT: der UDP-Eingang des Gateways ist nicht erreichbar (Port '
                  . (int) $z['udpport'] . ') - zurueckbehaltene Themen unter ' . $w
                  . '/ wurden nicht geleert.';
        return array(1, $zeilen, $offen, $nachgelesen);
    }
    $zu_leeren = count($offen);
    $datagramme = 0;
    $gelaufen = 0;
    for ($r = 1; $r <= max(1, (int) $runden) && $offen; $r++) {
        if ($r > 1) { usleep((int) $pause_us); }
        $gelaufen = $r;
        foreach ($offen as $t) {
            // Ein Leerzeichen hinter dem Thema, sonst keine Nutzlast: die
            // Form, die das Gateway als Loeschung liest (Regeln/07, Nachtrag
            // 19.09.2026: mqttgateway.pl:281, :311-315, :357). 5 ms Abstand
            // zwischen den Datagrammen eines Stosses (M5).
            if ($datagramme > 0) { usleep(5000); }
            if (@fwrite($fp, 'retain ' . $t . ' ') !== false) { $datagramme++; }
        }
        usleep(300000);     // dem Gateway Zeit bis zum Broker lassen
        $f = ev_mqtt_behalten_liste($offen);
        if ($f['lage'] === 'ok') {
            $nachgelesen = true;
            $offen = array_keys($f['belegt']);
        } else {
            $nachgelesen = false;
        }
    }
    fclose($fp);
    $zeilen[] = '<INFO> MQTT: ' . $zu_leeren . ' von ' . $n . ' Themen unter ' . $w . '/ mit leerer Nutzlast '
              . 'an den UDP-Eingang ' . (int) $z['udpport'] . ' des Gateways gesendet (' . $gelaufen
              . ' Runde(n), ' . $datagramme . ' Datagramme).';
    if ($nachgelesen && !$offen) {
        $zeilen[] = '<OK> MQTT: der Broker bestaetigt: keines der ' . $n . ' Themen unter ' . $w
                  . '/ steht mehr zurueckbehalten.';
        return array(0, $zeilen, array(), true);
    }
    if ($nachgelesen) {
        $zeilen[] = '<WARNING> MQTT: ' . count($offen) . ' Themen stehen noch zurueckbehalten im Broker ('
                  . implode(', ', array_slice($offen, 0, 5)) . (count($offen) > 5 ? ', ...' : '')
                  . '). Von Hand: mosquitto_pub -r -n -t <thema> (mit den Broker-Zugangsdaten).';
        return array(1, $zeilen, $offen, true);
    }
    $zeilen[] = '<INFO> MQTT: der Broker liess sich nicht befragen - nicht nachgelesen. Der UDP-Eingang '
              . 'verwirft unter Last Datagramme; was stehen bleibt, laesst sich mit '
              . 'mosquitto_pub -r -n -t <thema> von Hand loeschen.';
    return array(0, $zeilen, $offen, false);
}

/**
 * Abraeumen aus der Oberflaeche (M3, seit 0.9.34): beim Praefixwechsel das
 * ALTE Praefix, beim Abschalten das eingestellte, beim Verkleinern der Zahl
 * der Ladepunkte oder Fahrzeuge die nicht mehr gesendeten lpN_/fzN_-Themen.
 * $kurz: Themen ohne Praefix; null = alle, die die Linie je retained sandte.
 * Rueckgabe array(rc, Satz fuer die Einmalmeldung).
 */
function ev_mqtt_abraeumen($praefix, $kurz = null)
{
    $w = ev_mqtt_thema($praefix);
    if ($w === '') { return array(2, ''); }
    if ($kurz === null) { $kurz = ev_mqtt_leer_themen(); }
    if (!$kurz) { return array(0, ''); }
    $voll = array();
    foreach ($kurz as $t) { $voll[] = ev_mqtt_thema($w . '/' . $t); }
    list($rc, $zeilen, $offen, $nachgelesen) = ev_mqtt_leeren_themen($voll, $w, 3, 300000);
    /* Die Sendeliste gilt danach nicht mehr: sie haelt fest, was als retained
     * gesendet ist, und der naechste Lauf schickte sonst nur Aenderungen - nach
     * einem Wiedereinschalten stuende bis zum naechsten Vollversand kein
     * Zustand im Broker (am Bau gemessen, ui_win.py Fall praefix). */
    @unlink(ev_mqtt_gesendet_datei());
    ev_log('MQTT abraeumen unter ' . $w . '/: ' . preg_replace('/<[A-Z]+> /', '', implode(' ', $zeilen)));
    if ($rc === 2) {
        return array(2, sprintf(ev_t('MQTT.ABRAEUMEN_KEIN_PORT'), ev_e($w)));
    }
    if ($nachgelesen && !$offen) {
        return array(0, sprintf(ev_t('MQTT.ABRAEUMEN_OK'), count($voll), ev_e($w)));
    }
    if ($nachgelesen) {
        return array(1, sprintf(ev_t('MQTT.ABRAEUMEN_REST'), count($offen), ev_e($w),
                                ev_e(implode(', ', array_slice($offen, 0, 5)))));
    }
    return array($rc, sprintf(ev_t('MQTT.ABRAEUMEN_UNBEKANNT'), count($voll), ev_e($w)));
}

/**
 * Was ist nach einer Aenderung der Einstellungen abzuraeumen? (M3)
 * EINE Stelle fuer Speichern, Reiter MQTT und Zurueckspielen einer Sicherung:
 *   - anderes Praefix       -> das ALTE Praefix ganz,
 *   - MQTT abgeschaltet     -> das eingestellte Praefix ganz,
 *   - weniger Ladepunkte/Fahrzeuge -> deren Themen.
 * Rueckgabe: Liste von array(rc, Satz). Aufzurufen NACH dem Schreiben der
 * neuen Konfiguration.
 */
function ev_mqtt_abraeumen_nach($alt, $neu)
{
    $aus = array();
    $at = ev_mqtt_thema((string) $alt['mqtt_topic']);
    $nt = ev_mqtt_thema((string) $neu['mqtt_topic']);
    if ($at !== '' && $at !== $nt) {
        $aus[] = ev_mqtt_abraeumen($at);
    } elseif (!empty($alt['mqtt_ein']) && empty($neu['mqtt_ein'])) {
        $aus[] = ev_mqtt_abraeumen($nt);
    } elseif (!empty($neu['mqtt_ein'])
              && ((int) $neu['ladepunkte'] < (int) $alt['ladepunkte']
                  || (int) $neu['fahrzeuge'] < (int) $alt['fahrzeuge'])) {
        $aus[] = ev_mqtt_abraeumen($nt, ev_mqtt_themen_ueber((int) $neu['ladepunkte'], (int) $neu['fahrzeuge']));
    }
    return $aus;
}

/** Die retained Themen der Ladepunkte und Fahrzeuge oberhalb der neuen Zahl (M3). */
function ev_mqtt_themen_ueber($ladepunkte, $fahrzeuge)
{
    $l = ev_retain_liste();
    $t = array();
    for ($i = (int) $ladepunkte + 1; $i <= EV_LADEPUNKTE; $i++) {
        foreach (array_keys($l['ladepunkt']) as $k) { $t[] = 'lp' . $i . '_' . $k; }
    }
    for ($i = (int) $fahrzeuge + 1; $i <= EV_FAHRZEUGE; $i++) {
        foreach (array_keys($l['fahrzeug']) as $k) { $t[] = 'fz' . $i . '_' . $k; }
    }
    return $t;
}

/**
 * Die Liste der je benutzten Praefixe (M3, seit 0.9.34). Sie liegt NEBEN dem
 * Datenordner (data/plugins/<ordner>.mqtt_praefixe.json): der Installer
 * raeumt data/plugins/<ordner>/ bei jedem Update ab (Regeln/03, "Ein Merker,
 * der ein Upgrade ueberleben soll"). Die Deinstallation liest sie und raeumt
 * sie danach weg.
 */
function ev_mqtt_praefixe_datei()
{
    $p = ev_paths();
    return ($p['home'] !== '') ? $p['home'] . '/data/plugins/' . $p['plugin'] . '.mqtt_praefixe.json' : '';
}

function ev_mqtt_praefixe()
{
    $f = ev_mqtt_praefixe_datei();
    if ($f === '' || !is_file($f)) { return array(); }
    $l = json_decode((string) @file_get_contents($f), true);
    $aus = array();
    if (is_array($l)) {
        foreach ($l as $x) {
            if (is_string($x) && ev_mqtt_thema($x) === $x && $x !== '') { $aus[] = $x; }
        }
    }
    return array_values(array_unique($aus));
}

function ev_mqtt_praefix_merken($praefix)
{
    $f = ev_mqtt_praefixe_datei();
    $w = ev_mqtt_thema($praefix);
    if ($f === '' || $w === '') { return; }
    $l = ev_mqtt_praefixe();
    if (in_array($w, $l, true)) { return; }
    $l[] = $w;
    ev_datei_schreiben($f, (string) json_encode($l), 0644);
}

/**
 * Das Lebenszeichen: nie retained (Regeln/07, Abschnitt 3). Seit 0.9.34 bei
 * einer Aenderung sofort, sonst hoechstens alle EV_LEBEN_ABSTAND Sekunden
 * (M5); bis 0.9.33 ging es in JEDEM Lauf. Alles andere nur bei Aenderung und
 * im Vollversand (ev_mqtt_publish()).
 */
function ev_mqtt_lebenszeichen_liste()
{
    return array('ok', 'ts', 'dienst', 'betriebsbereit');
}

/** Hoechstens so oft (Sekunden) geht ein unveraendertes Lebenszeichen hinaus (M5). */
define('EV_LEBEN_ABSTAND', 30);

/**
 * Felder, die NICHT ueber MQTT gehen. alter_s: ueber MQTT gibt es kein Alter,
 * nur einen Zeitstempel (Regeln/07, Abschnitt 3) - ein Alter ist in dem
 * Augenblick falsch, in dem es ankommt, und es haette jeden Aenderungsfilter
 * wirkungslos gemacht (es aendert sich in jedem Lauf). Es war nie retained
 * (Tabelle seit 0.9.29; davor ging alles publish, am Geraet 10.09.2026:
 * --retained-only 0), ein Altwert im Broker kann also nicht stehen. Ueber HTTP
 * bleibt ALTER_S. Seit 0.9.33.
 */
function ev_mqtt_nicht_senden()
{
    return array('alter_s');
}

/**
 * Die Sendeliste: was zuletzt unter welchem Praefix hinausging, und wann der
 * letzte Vollversand war. Sie liegt im Zwischenspeicher (/tmp, auf dem
 * LoxBerry eine RAM-Scheibe): nach einem Neustart des LoxBerry fehlt sie, und
 * der erste Lauf sendet alles.
 */
function ev_mqtt_gesendet_datei()
{
    return ev_tmpdir() . '/mqtt_gesendet.json';
}

/**
 * Kennung der eingespielten Fassung: Aenderungszeit und Groesse dieser Datei.
 * Ein Update spielt sie neu ein (plugininstall.pl kopiert ohne -p) - danach
 * gilt die alte Sendeliste nicht, und der erste Lauf sendet alles.
 */
function ev_mqtt_fassung()
{
    clearstatcache(true, __FILE__);
    return (int) @filemtime(__FILE__) . '-' . (int) @filesize(__FILE__);
}

function ev_mqtt_publish($werte = null, $voll = false)
{
    $GLOBALS['ev_mqtt_grund'] = '';
    $cfg = ev_config();
    if (empty($cfg['mqtt_ein'])) {
        // Wird MQTT wieder eingeschaltet, beginnt es mit einem Vollversand.
        @unlink(ev_mqtt_gesendet_datei());
        $GLOBALS['ev_mqtt_grund'] = 'aus';
        return 0;
    }
    $z = ev_mqtt_zustand();
    if (!$z['udpport']) {
        ev_log_wenn_neu('mqtt', 'kein UDP-Eingangsport in der general.json - Gateway eingerichtet?');
        $GLOBALS['ev_mqtt_grund'] = 'kein_port';
        return 0;
    }
    if ($werte === null) { $werte = ev_werte(); }
    /* Hat EVCC in diesem Lauf geantwortet? Nur dann gehen die Themen der
     * Retain-Tabelle retained hinaus (siehe ev_retain_liste()). */
    $ev_antwort = isset($werte['ok']['wert']) && (int) $werte['ok']['wert'] === 1;
    /* Keine Antwort UND kein alter Stand (Neustart des LoxBerry, EVCC noch
     * nicht da): dann gehen nur das Lebenszeichen und die Fehlerfelder
     * hinaus (M2, seit 0.9.34). Bis 0.9.33 kamen 41 Themen der Retain-Tabelle
     * als 'publish ... 0' beim Gateway an - "Lademodus aus, Ladegrenze 0" in
     * Loxone nach jedem Neustart (Pruefbericht mqtt, B2). */
    $ev_ohne_stand = !$ev_antwort && (!isset($werte['ts']['wert']) || (int) $werte['ts']['wert'] === 0);
    $ev_nur = array_flip(array('ok', 'ts', 'dienst', 'betriebsbereit', 'fehler_nr', 'letzter_fehler'));
    $ev_praefix = ev_mqtt_thema($cfg['mqtt_topic']);
    ev_mqtt_praefix_merken($ev_praefix);
    $ev_felder = ev_felder();
    /* Die Altwerte, die der Broker noch haelt (oder alle, wenn er nicht zu
     * fragen war), bekommen eine leere retain-Nutzlast UNMITTELBAR vor ihrem
     * gueltigen Wert - im selben Versand, als Nachbarzeile. */
    $ev_weg = array_flip(ev_mqtt_altlast($ev_praefix, $werte)['themen']);
    /* NUR AENDERUNGEN, alles im groben Takt (seit 0.9.33; Regeln/07,
     * Abschnitt 2). Vollversand: ohne Sendeliste, nach einem Pluginstart,
     * nach einem Praefixwechsel, auf Wunsch ($voll, Knopf im Reiter Test) und
     * alle mqtt_vollsend_min Minuten.
     *
     * Das Lebenszeichen (M5, seit 0.9.34): bei einer Aenderung sofort, sonst
     * hoechstens alle 30 s (Regeln/07, Einspeisebremse 0.9.20). ts geht mit,
     * sobald ein anderes Lebenszeichen geht, sonst ebenfalls alle 30 s. Bis
     * 0.9.33 ging es in jedem Lauf - bei Takt 5 s 48 Datagramme je Minute nur
     * dafuer (Pruefbericht mqtt, B5).
     *
     * Ein Zustand ohne Aussage (M1, seit 0.9.34) - der Abruf gelang, lieferte
     * das Feld aber nicht (Ladepunkt fehlt, Startfehler ohne Ladepunkte) -
     * geht EINMAL als '-' retained hinaus, nie als erfundene 0; auch ein
     * Vollversand wiederholt ihn nicht. Die Rangzahlen der Preisvorschau
     * gehen ohne Aussage als '-' fluechtig. Ohne Antwort geht ein Feld ohne
     * Aussage gar nicht hinaus.
     *
     * Grenze: der UDP-Eingang bestaetigt nichts. */
    $ev_liste = @json_decode((string) @file_get_contents(ev_mqtt_gesendet_datei()), true);
    $ev_kennung = $ev_praefix . '|' . ev_mqtt_fassung();
    $ev_takt = (int) $cfg['mqtt_vollsend_min'];
    $ev_gleich = is_array($ev_liste) && isset($ev_liste['kennung'], $ev_liste['voll'], $ev_liste['werte'])
        && is_array($ev_liste['werte']) && (string) $ev_liste['kennung'] === $ev_kennung;
    if (!$ev_gleich || ($ev_takt > 0 && (time() - (int) $ev_liste['voll']) >= $ev_takt * 60)) {
        $voll = true;
    }
    $ev_bisher = $ev_gleich ? $ev_liste['werte'] : array();
    $ev_leben_bisher = ($ev_gleich && isset($ev_liste['leben']) && is_array($ev_liste['leben']))
        ? $ev_liste['leben'] : array();
    $ev_alt = $voll ? array() : $ev_bisher;
    $ev_neu = $ev_alt;
    $ev_leben_neu = $ev_leben_bisher;
    $ev_leben = array_flip(ev_mqtt_lebenszeichen_liste());
    $ev_nicht = array_flip(ev_mqtt_nicht_senden());
    /* Datenstrom statt socket_create: socket_* steckt in php-sockets, das
     * nicht garantiert geladen ist; stream_socket_client() ist Kern. */
    $fehl = 0;
    $grund = '';
    $sock = @stream_socket_client('udp://127.0.0.1:' . (int) $z['udpport'],
                                  $fehl, $grund, 2, STREAM_CLIENT_CONNECT);
    if (!$sock) {
        ev_log_wenn_neu('mqtt', 'UDP-Verbindung zum Gateway auf Port '
            . (int) $z['udpport'] . ' nicht moeglich: ' . $grund . ' (' . $fehl . ')');
        $GLOBALS['ev_mqtt_grund'] = 'udp';
        return 0;
    }
    $n = 0;
    $versucht = 0;
    $gesendet = 0;          // Datagramme dieses Stosses, fuer die 5-ms-Pause (M5)
    $ev_jetzt = time();
    $ev_leben_geht = false;
    $senden = function ($zeile) use ($sock, &$gesendet) {
        if ($gesendet > 0) { usleep(5000); }
        $gesendet++;
        return @fwrite($sock, $zeile) !== false;
    };
    foreach ($werte as $name => $d) {
        if (isset($ev_nicht[$name])) { continue; }
        if ($ev_ohne_stand && !isset($ev_nur[$name])) { continue; }
        $ev_ohne = !empty($d['ohne']);
        $ev_retain = ev_retain_fuer($name);
        if ($ev_ohne && !$ev_antwort) { continue; }
        if ($ev_ohne && $ev_retain) {
            $nutz = '-';
            $verb = 'retain';
        } elseif ($ev_ohne && isset($ev_felder[$name]) && ev_ohne_minus1($name, $ev_felder[$name])) {
            $nutz = '-';
            $verb = 'publish';
        } else {
            /* Erst die Nutzlast, dann das Verb: eine leere Nutzlast LOESCHT
             * ein zurueckbehaltenes Thema, sie darf nie mit retain hinaus. */
            $nutz = ev_mqtt_nutzlast($d['wert']);
            $verb = ($ev_antwort && ev_retain_fuer($name, $nutz)) ? 'retain' : 'publish';
        }
        $thema = ev_mqtt_thema($cfg['mqtt_topic'] . '/' . $name);
        $ev_zeile = $verb . ' ' . $nutz;
        if (isset($ev_leben[$name])) {
            $lb = isset($ev_leben_bisher[$name]) && is_array($ev_leben_bisher[$name])
                ? $ev_leben_bisher[$name] : null;
            $faellig = $voll || $lb === null
                || ($ev_jetzt - (int) $lb['t']) >= EV_LEBEN_ABSTAND
                || ($name !== 'ts' && (string) $lb['z'] !== $ev_zeile)
                || ($name === 'ts' && $ev_leben_geht && (string) $lb['z'] !== $ev_zeile);
            if (!$faellig) { continue; }
        } elseif ($ev_zeile === 'retain -' && isset($ev_bisher[$name])
                  && (string) $ev_bisher[$name] === 'retain -') {
            // '-' steht schon im Broker - einmal genuegt, auch im Vollversand.
            $ev_neu[$name] = 'retain -';
            continue;
        } elseif (!$voll && !isset($ev_weg[$name])
                  && isset($ev_alt[$name]) && (string) $ev_alt[$name] === $ev_zeile) {
            continue;
        }
        $versucht++;
        if (isset($ev_weg[$name])) {
            /* Ein Leerzeichen hinter dem Thema, sonst keine Nutzlast: die
             * Form, die das Gateway als Loeschung liest (Regeln/07, Nachtrag
             * 19.09.2026: mqttgateway.pl:281, :311-315, :357). */
            $senden('retain ' . $thema . ' ');
        }
        if ($senden($verb . ' ' . $thema . ' ' . $nutz)) {
            $n++;
            if (isset($ev_leben[$name])) {
                $ev_leben_neu[$name] = array('z' => $ev_zeile, 't' => $ev_jetzt);
                if ($name !== 'ts') { $ev_leben_geht = true; }
            } else {
                $ev_neu[$name] = $ev_zeile;
            }
        }
    }
    fclose($sock);
    if ($n < $versucht) {
        ev_log_wenn_neu('mqtt_teil', sprintf('nur %d von %d Themen gesendet', $n, $versucht));
    }
    if ($voll || $ev_neu !== $ev_alt || $ev_leben_neu !== $ev_leben_bisher) {
        $ev_js = json_encode(array(
            'kennung' => $ev_kennung,
            'voll' => $voll ? time() : (int) $ev_liste['voll'],
            'werte' => $ev_neu,
            'leben' => $ev_leben_neu));
        if ($ev_js !== false) {
            ev_datei_schreiben(ev_mqtt_gesendet_datei(), $ev_js, 0664);
        }
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
        $w = $werte[$name]['wert'];
        /* Keine erfundene 0 fuer einen Zustand ohne Aussage (C5, M1, seit
         * 0.9.34): liefert der Abruf das Feld nicht, steht -1 da, wo die Zahl
         * -1 sonst nie annimmt (ev_ohne_minus1()). */
        if (!empty($werte[$name]['ohne']) && ev_ohne_minus1($name, $d)) { $w = -1; }
        $teile[] = strtoupper($name) . '=' . $w;
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
        // Der Vorbehalt "ungemessen" steht seit 0.9.34 hier statt im Kachelnamen (O9).
        $o .= 'HintText="' . ev_x(isset($c['hint']) ? $c['hint'] : '') . '"';
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
        $o .= 'HintText="' . ev_x(isset($c['hint']) ? $c['hint'] : '') . '"';
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
/**
 * Aus einer Beschriftung einen KACHELNAMEN machen.
 *
 * Der Comment einer Importvorlage wird beim Import zum Attribut Desc und
 * damit zum Anzeigenamen in Loxone Config. Ein Satz taugt dafuer nicht:
 * gemessen an 0.9.26 waren 64 von 106 Kommentaren laenger als vierzig
 * Zeichen, der laengste 72 - lauter Saetze als Kachelnamen. Dieselbe Klasse
 * wie APC-UPS 1.1.6 (161 Zeichen).
 *
 * Geschnitten wird an der ersten Klammer: davor steht der Name, darin die
 * Erklaerung. Die Erklaerung geht nicht verloren - sie steht weiterhin in
 * der Feldtabelle des Reiters Einbindung in Loxone und in der Thementabelle
 * des Reiters MQTT, also dort, wo jemand liest statt nachschlaegt.
 */
function ev_kachelname($text)
{
    $t = trim((string) $text);
    $i = strpos($t, ' (');
    if ($i !== false && $i > 0) { $t = substr($t, 0, $i); }
    return rtrim($t, ' .,;:');
}

function ev_vorlage_ein()
{
    $cmds = array();
    foreach (ev_felder_zeile() as $name => $d) {
        /* Kachelname hoechstens 40 Zeichen aus dem Kurzschluessel (O9); die
         * Einheit steht in Unit, der Vorbehalt fuer Felder aus der
         * Dokumentation im HintText - aus der Sprachdatei (O7). Bis 0.9.33
         * hingen beide am Kommentar, und "(ungemessen)" stand fest auf
         * Deutsch auch in der englischen Vorlage. */
        $text = ev_kurztext($d['text'], isset($d['nr']) ? $d['nr'] : null);
        /* Kann das Feld ohne Aussage -1 tragen (C5, M1)? Dann muss der
         * Eingang es auch annehmen - sonst kappte Loxone auf MinVal. */
        $min = ev_ohne_minus1($name, $d) ? min(-1, (int) $d['min']) : $d['min'];
        $cmds[] = array(
            // Das Semikolon gehoert ins Suchmuster. Jedes Feld steht in der
            // Zeile hinter einem ';' - ohne es traefe ein kuenftiger Feldname,
            // der auf einen bestehenden endet, die falsche Stelle.
            'title' => 'EVCC_' . strtoupper($name),
            'comment' => $text,
            'check' => '\i;' . strtoupper($name) . '=\i\v',
            'analog' => $d['analog'], 'min' => $min, 'max' => $d['max'],
            'unit' => $d['einheit'],
            'hint' => ($d['quelle'] === 'doku') ? ev_t('VORLAGE.HINT_UNGEMESSEN') : '',
        );
    }
    return array('VI_evcc.xml', ev_xml_virtual_in_http(array(
        'title'   => 'EVCC',
        'address' => ev_endpunkt('status'),
        'polling' => '30',
        'comment' => sprintf(ev_t('VORLAGE.KOMMENTAR_EIN'), date('d.m.Y')),
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
        /* Der Titel traegt seit 0.9.27 den Vorsatz SET_ - aus
         * ev_befehl_titel(), derselben Stelle, aus der die Baustein-Liste im
         * Reiter Einbindung in Loxone ihn nimmt (O8, seit 0.9.34). */
        $titel = ev_befehl_titel($aktion, $lp);
        $c = array('title' => $titel, 'comment' => ev_kurztext($b['text']),
                   'analog' => !empty($b['analog']), 'method' => 'GET',
                   // Ein Befehl, den niemand gemessen hat, sagt das - im HintText (O9).
                   'hint' => ($b['quelle'] === 'doku') ? ev_t('VORLAGE.HINT_UNGEMESSEN') : '');
        /* Energie-1 C1: jede Adresse traegt von=loxone, damit die Schreiber-Wache
         * den Miniserver von anderen Schreibern unterscheidet. Wer die Vorlage nicht
         * neu einliest, erscheint dort als "ohne Kennung" - kein Fehler. */
        $adr = $frage . $aktion . ($lp ? '&lp=' . $lp : '') . '&von=loxone';
        if ($b['pruef'] === 'ohne') {
            // DELETE-Befehle brauchen keinen Wert: ein Digitalausgang, der
            // beim Einschalten ausloest.
            $c['on'] = $adr;
            $c['off'] = '';
        } elseif ($b['pruef'] === 'schalter') {
            $c['on'] = $adr . '&wert=1';
            $c['off'] = $adr . '&wert=0';
        } elseif ($b['pruef'] === 'planziel' || $b['pruef'] === 'planstunden') {
            /* Ladeplan aus zwei Ausgaengen, jeder mit <v> (C11, seit 0.9.34):
             * <v.N> ist in Loxone derselbe Wert mit N Nachkommastellen, zwei
             * Werte lassen sich aus einem Ausgang nicht bilden. */
            $c['on'] = $adr . '&wert=<v>';
            $c['off'] = '';
        } else {
            $c['on'] = $adr . '&wert=<v.0>';
            $c['off'] = '';
        }
        $cmds[] = $c;
    };

    foreach (ev_befehle() as $aktion => $b) {
        if ($b['ebene'] !== 'lp' || (isset($b['vorlage']) && empty($b['vorlage']))) { continue; }
        for ($i = 1; $i <= (int) $cfg['ladepunkte']; $i++) { $bauen($aktion, $b, $i); }
    }
    foreach (ev_befehle() as $aktion => $b) {
        if ($b['ebene'] !== 'anlage' || (isset($b['vorlage']) && empty($b['vorlage']))) { continue; }
        $bauen($aktion, $b, 0);
    }

    return array('VQ_evcc.xml', ev_xml_virtual_out(array(
        'title'   => ev_t('VORLAGE.TITEL_AUS'),
        'address' => $adresse,
        'comment' => sprintf(ev_t('VORLAGE.KOMMENTAR_AUS'), date('d.m.Y')),
    ), $cmds));
}

/* ==================================================================
 * Oberflaeche: Einmalmeldung, Zwischenspeicher, Gateway-Texte (seit 0.9.34)
 * ================================================================== */

/**
 * Einmalmeldung nach dem POST (O1). Regeln/04: data/plugins/<ordner>/
 * einmalmeldung.json, 0600, 120 s gueltig, nur beim GET gelesen und dabei
 * geloescht. Das Aktionstoken und das Formularmerkmal stehen darin nur als
 * *** (Regeln/04, Nachtrag Raumklima 17.09.2026). Bauform ap_meldung_ablegen()
 * (APC-UPS NG 1.2.14).
 */
function ev_meldung_datei()
{
    return ev_paths()['datadir'] . '/einmalmeldung.json';
}

function ev_meldung_ablegen($daten)
{
    $daten['zeit'] = time();
    $geheim = array();
    $c = ev_config(false);
    if ((string) $c['aktionstoken'] !== '') { $geheim[] = (string) $c['aktionstoken']; }
    if ((string) $c['passwort'] !== '') { $geheim[] = (string) $c['passwort']; }
    if (ev_formtoken() !== '') { $geheim[] = ev_formtoken(); }
    array_walk_recursive($daten, function (&$w) use ($geheim) {
        if (is_string($w)) {
            foreach ($geheim as $g) { $w = str_replace($g, '***', $w); }
        }
    });
    $js = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $js !== false && ev_datei_schreiben(ev_meldung_datei(), $js, 0600);
}

function ev_meldung_abholen()
{
    $f = ev_meldung_datei();
    clearstatcache(true, $f);
    if (!is_file($f)) { return null; }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);                        // loeschen VOR der Anzeige
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) { return null; }
    $liste = function ($s) use ($d) {
        return (isset($d[$s]) && is_array($d[$s])) ? array_values(array_filter($d[$s], 'is_string')) : array();
    };
    return array('meldungen' => $liste('meldungen'), 'fehler' => $liste('fehler'),
                 'eingaben' => ev_eingaben_pruefen(isset($d['eingaben']) ? $d['eingaben'] : null));
}

/* ==================================================================
 * Eingaben nach einer Beanstandung (X-2, Regeln/04, Hausregel 30.09.2026)
 * ==================================================================
 *
 * Seit der Umleitung nach jedem POST (O1, 0.9.34) zeigte der GET nach einer
 * Abweisung die GESPEICHERTEN Werte: wer drei Felder richtig und eines falsch
 * eingab, tippte alle vier neu. Jetzt reisen die Eingaben des beanstandeten
 * Formulars mit der Einmalmeldung (0600, Datenordner, 120 s, beim GET gelesen
 * und geloescht) - nur die Felder DIESES Formulars aus der Liste unten, und
 * nie ein Geheimnis: das Passwort steht als 'geheim' darin, damit es markiert
 * werden kann; sein Wert reist nie mit (das Feld bleibt leer und zeigt den
 * Platzhalter). Aktionstoken und Formularmerkmal ersetzt ev_meldung_ablegen()
 * ohnehin durch ***. Nur nach einer Beanstandung: nach erfolgreichem
 * Speichern zeigt der GET die gespeicherten Werte. */

/** Die Felder je Formular (Wert des versteckten Feldes), mit ihrer Art. */
function ev_eingabe_felder()
{
    $ev_l = array(
        'speichern' => array('url' => 'text', 'passwort' => 'geheim', 'passwort_loeschen' => 'haken',
                             'takt' => 'text', 'ladepunkte' => 'text', 'fahrzeuge' => 'text',
                             'tarife_ein' => 'haken', 'steuerung_ein' => 'haken', 'update_ein' => 'haken',
                             // Energie-1 C1, Schreiber-Wache
                             'wache_ein' => 'haken', 'wache_fenster_min' => 'text', 'wache_lb_melden' => 'haken',
                             'wache_sperren_ein' => 'haken', 'wache_erlaubt' => 'text'),
        'save_mqtt' => array('mqtt_ein' => 'haken', 'mqtt_topic' => 'text', 'mqtt_vollsend_min' => 'text'),
    );
    /* Nr. 36 b: Felder und Anlaesse der Ansage; die Sprechtoken als 'geheim' (reisen nie mit). */
    foreach (ev_ansage_x2() as $ev_n => $ev_a) { $ev_l['speichern'][$ev_n] = $ev_a; }
    return $ev_l;
}

/** Die Eingaben eines abgewiesenen POST fuer die Einmalmeldung. */
function ev_eingaben_sammeln($formular, $beanstandet)
{
    $liste = ev_eingabe_felder();
    if (!isset($liste[$formular])) { return array(); }
    $werte = array();
    foreach ($liste[$formular] as $k => $art) {
        if ($art === 'geheim') { continue; }            // nie mitnehmen
        if ($art === 'haken') {
            $werte[$k] = isset($_POST[$k]) ? 1 : 0;
        } else {
            $werte[$k] = (isset($_POST[$k]) && is_string($_POST[$k])) ? (string) $_POST[$k] : '';
        }
    }
    $felder = array();
    foreach ((array) $beanstandet as $k) {
        if (is_string($k) && isset($liste[$formular][$k]) && !in_array($k, $felder, true)) { $felder[] = $k; }
    }
    return array('formular' => $formular, 'werte' => $werte, 'felder' => $felder);
}

/** Die Eingaben aus der Einmalmeldung - nur, was die Liste kennt. */
function ev_eingaben_pruefen($e)
{
    $liste = ev_eingabe_felder();
    if (!is_array($e) || !isset($e['formular']) || !is_string($e['formular'])
        || !isset($liste[$e['formular']])) {
        return array();
    }
    $f = $e['formular'];
    $werte = array();
    if (isset($e['werte']) && is_array($e['werte'])) {
        foreach ($liste[$f] as $k => $art) {
            if ($art === 'geheim' || !array_key_exists($k, $e['werte'])) { continue; }
            $w = $e['werte'][$k];
            if ($art === 'haken') { $werte[$k] = empty($w) ? 0 : 1; }
            elseif (is_string($w)) { $werte[$k] = $w; }
        }
    }
    $felder = array();
    if (isset($e['felder']) && is_array($e['felder'])) {
        foreach ($e['felder'] as $k) {
            if (is_string($k) && isset($liste[$f][$k])) { $felder[] = $k; }
        }
    }
    return array('formular' => $f, 'werte' => $werte, 'felder' => $felder);
}

/** Traegt die Seite gerade die Eingaben dieses Formulars? */
function ev_eingaben_aktiv($formular)
{
    $e = isset($GLOBALS['ev_eingaben']) ? $GLOBALS['ev_eingaben'] : array();
    return is_array($e) && isset($e['formular']) && $e['formular'] === $formular;
}

/** Der anzuzeigende Wert: die Eingabe nach einer Beanstandung, sonst der gespeicherte. */
function ev_eingabe($formular, $feld, $gespeichert)
{
    if (!ev_eingaben_aktiv($formular)) { return $gespeichert; }
    $w = $GLOBALS['ev_eingaben']['werte'];
    return array_key_exists($feld, $w) ? $w[$feld] : $gespeichert;
}

/** Markierung eines beanstandeten Feldes (Klasse und aria-invalid). */
function ev_markierung($formular, $feld)
{
    if (!ev_eingaben_aktiv($formular)) { return ''; }
    return in_array($feld, $GLOBALS['ev_eingaben']['felder'], true)
        ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}

/**
 * Der Zustand aus dem Zwischenspeicher des Abrufdienstes - ohne jede eigene
 * Anfrage an EVCC (O4). Ohne Zwischenspeicher: ein leerer Stand (stand 0).
 */
function ev_state_gespeichert()
{
    $f = ev_tmpdir() . '/state.json';
    $c = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (is_array($c) && isset($c['ok'])) { return $c; }
    return array('ok' => 0, 'stand' => 0, 'fehler' => '', 'fehlernr' => 0, 'roh' => array());
}

/**
 * Der Sprachschluessel passend zur Fassung des MQTT-Gateways (M6):
 * <basis>_V1, <basis>_V2 oder, wenn die Fassung nicht feststellbar ist, die
 * Basis selbst - dort sind beide Faelle beschrieben. Unter V2 gibt das
 * Gateway zurueckbehaltene Nachrichten beim Neuverbinden NICHT an den
 * Miniserver weiter und unterdrueckt gleiche Werte; der Text darf dort nichts
 * zusagen, was V2 nicht einloest (Pruefbericht mqtt, B6).
 */
function ev_gateway_schluessel($basis)
{
    $m = ev_mqtt_zustand();
    $f = isset($m['fassung']) ? (int) $m['fassung'] : 0;
    if ($f >= 2) { return $basis . '_V2'; }
    if ($f === 1) { return $basis . '_V1'; }
    return $basis;
}

/**
 * Ein Kachelname fuer die Vorlagen, hoechstens 40 Zeichen (O9, seit 0.9.34).
 * Zuerst der Kurzschluessel KURZ.<name> (bei Befehlen KURZ.AUS_<name>), sonst
 * der Text bis zur ersten Klammer. Die Einheit steht im Attribut Unit, der
 * Vorbehalt "ungemessen" im HintText - beides hing bis 0.9.33 am Kommentar,
 * und 34 von 70 Eingangs- und 7 von 21 Ausgangskommentaren waren laenger als
 * 40 Zeichen, der laengste 75 (Pruefbericht oberflaeche, Befund 14).
 */
function ev_kurztext($schluessel, $nr = null)
{
    list($a, $s) = array_pad(explode('.', (string) $schluessel, 2), 2, '');
    $k = 'KURZ.' . ($a === 'AUS' ? 'AUS_' : '') . $s;
    $t = ev_t($k);
    if ($t === $k) { $t = ev_kachelname(strip_tags(html_entity_decode(ev_t($schluessel), ENT_QUOTES, 'UTF-8'))); }
    if ($nr !== null) { $t = sprintf($t, (int) $nr); }
    return $t;
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
    static $ev_s = null;
    if ($ev_s !== null) { return $ev_s; }
    $s = '';
    /* Drei Quellen, in dieser Reihenfolge - LBLANG behaelt den Vorrang.
     *
     * Die dritte ist die entscheidende: im Cron und im Abrufdienst ist
     * LBSystem nicht geladen und LBLANG setzt niemand. Ohne Base.Lang faellt
     * der Dienst auf seine eingebaute Vorgabe zurueck, und seit die
     * Netzfehlertexte uebersetzt werden, stuende die Meldung in
     * LETZTER_FEHLER dann auf Deutsch in einer englischen Anlage. */
    if (getenv('LBLANG')) {
        $s = (string) getenv('LBLANG');
    } elseif (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $s = (string) LBSystem::lblanguage();
    } else {
        $p = ev_paths();
        if ($p['home'] !== '') {
            $g = @json_decode((string) @file_get_contents(
                $p['home'] . '/config/system/general.json'), true);
            foreach (array('Base', 'base') as $k) {
                if (isset($g[$k]) && is_array($g[$k]) && isset($g[$k]['Lang'])) {
                    $s = (string) $g[$k]['Lang'];
                    break;
                }
            }
        }
    }
    $s = strtolower(substr($s, 0, 2));
    $ev_s = in_array($s, array('de', 'en'), true) ? $s : 'de';
    return $ev_s;
}

function ev_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $p = ev_paths();
        /* Ohne Wurzel NUR die eigenen Sprachdateien. Bis 0.9.32 wurde der
         * Installationspfad auch mit leerer Wurzel gebildet und abgefragt -
         * aus dem ausgepackten Archiv also /templates/plugins/evcc/lang ab der
         * Laufwerkswurzel; lag dort etwas, zeigte die Oberflaeche fremde Texte
         * (in WSL gemessen, Pruefung-EVCC-0.9.33, Fall T1; Bauart zd_t() aus
         * ZendureSolarFlow 0.9.26). */
        $pfad = '';
        if ($p['home'] !== '' && is_dir($p['home'] . '/templates/plugins/' . $p['plugin'] . '/lang')) {
            $pfad = $p['home'] . '/templates/plugins/' . $p['plugin'] . '/lang';
        }
        if ($pfad === '') {
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
        /* min/max der drei 'zahl'-Befehle sind eine WAHL DIESES PLUGINS,
         * keine gemessene Schranke von EVCC: sie sollen einen verrutschten
         * Analogausgang abfangen, nicht eine sinnvolle Eingabe verhindern.
         * Preise in der Waehrung des Tarifs je kWh, Energie in kWh. */
        'smartcostlimit' => array('ebene' => 'lp', 'methode' => 'POST',
            'pfad' => '/api/loadpoints/%LP%/smartcostlimit/%WERT%', 'pruef' => 'zahl',
            'min' => -10, 'max' => 10,
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
        /* 'plansoc' traegt zwei Werte in EINER Adresse. In Loxone ist <v.N>
         * aber derselbe Analogwert mit N Nachkommastellen - aus einem Ausgang
         * lassen sich Ziel und Stunden nicht getrennt bilden (Pruefbericht
         * oberflaeche, Befund 13). Der Befehl bleibt fuer eigene Aufrufe
         * bestehen (Namen behalten ihre Bedeutung), geht aber seit 0.9.34
         * nicht mehr in die Vorlage ('vorlage' => 0). Dort stehen die zwei
         * getrennten Befehle darunter, jeder mit <v> (C11). */
        'plansoc' => array('ebene' => 'lp', 'methode' => 'POST',
            'pfad' => '/api/loadpoints/%LP%/plan/soc/%WERT%/%ZEIT%', 'pruef' => 'plan',
            'min' => 0, 'max' => 100, 'text' => 'AUS.PLANSOC', 'quelle' => 'doku', 'analog' => 1,
            'vorlage' => 0),
        'plansoc_ziel' => array('ebene' => 'lp', 'methode' => 'POST',
            'pfad' => '/api/loadpoints/%LP%/plan/soc/%WERT%/%ZEIT%', 'pruef' => 'planziel',
            'min' => 0, 'max' => 100, 'text' => 'AUS.PLANSOC_ZIEL', 'quelle' => 'doku', 'analog' => 1),
        'plansoc_stunden' => array('ebene' => 'lp', 'methode' => 'POST',
            'pfad' => '/api/loadpoints/%LP%/plan/soc/%WERT%/%ZEIT%', 'pruef' => 'planstunden',
            'min' => 0.25, 'max' => 168, 'text' => 'AUS.PLANSOC_STUNDEN', 'quelle' => 'doku', 'analog' => 1),
        'planaus' => array('ebene' => 'lp', 'methode' => 'DELETE',
            'pfad' => '/api/loadpoints/%LP%/plan', 'pruef' => 'ohne',
            'text' => 'AUS.PLANAUS', 'quelle' => 'doku', 'analog' => 0),
        'limitenergie' => array('ebene' => 'lp', 'methode' => 'POST',
            'pfad' => '/api/loadpoints/%LP%/limitenergy/%WERT%', 'pruef' => 'zahl',
            'min' => 0, 'max' => 500,
            'text' => 'AUS.LIMITENERGIE', 'quelle' => 'doku', 'analog' => 1),
        'fahrzeug' => array('ebene' => 'lp', 'methode' => 'POST',
            'pfad' => '/api/loadpoints/%LP%/vehicle/%WERT%', 'pruef' => 'name',
            'text' => 'AUS.FAHRZEUG', 'quelle' => 'doku', 'analog' => 0),
        'fahrzeugaus' => array('ebene' => 'lp', 'methode' => 'DELETE',
            'pfad' => '/api/loadpoints/%LP%/vehicle', 'pruef' => 'ohne',
            'text' => 'AUS.FAHRZEUGAUS', 'quelle' => 'doku', 'analog' => 0),
        'netzladegrenze' => array('ebene' => 'anlage', 'methode' => 'POST',
            'pfad' => '/api/batterygridchargelimit/%WERT%', 'pruef' => 'zahl',
            'min' => -10, 'max' => 10,
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
/**
 * Der Titel eines virtuellen Ausgangs in der Vorlage - EINE Stelle fuer die
 * Vorlage und die Baustein-Liste im Reiter Einbindung in Loxone (O8, seit
 * 0.9.34). Bis 0.9.33 nannte die Liste EVCC_MODUS_LP1, die Vorlage aber seit
 * 0.9.27 EVCC_SET_MODUS_LP1 (Pruefbericht oberflaeche, Befund 12).
 */
function ev_befehl_titel($aktion, $lp = 0)
{
    return 'EVCC_SET_' . strtoupper((string) $aktion) . ($lp ? '_LP' . (int) $lp : '');
}

function ev_befehl_pruefen($b, $wert)
{
    $zahl = str_replace(',', '.', (string) $wert);
    $ganz = is_numeric($zahl) ? (int) round((float) $zahl) : null;
    $art = $b['pruef'];

    if ($art === 'ohne') { return array(1, '-'); }

    if ($art === 'modus') {
        /* isset() ist der ganze Unterschied. Bis 0.9.26 stand hier
         * ev_modus_text($ganz), und das faellt fuer JEDE unbekannte Zahl auf
         * 'off' zurueck: gemessen gingen 4, 7, 99 und -3 mit OK=1 durch und
         * BEENDETEN die Ladung. Zwei Zeilen tiefer, bei batteriemodus, war es
         * von Anfang an richtig. */
        $k = ev_modus_liste();
        $m = (is_numeric($zahl) && isset($k[$ganz]))
             ? $k[$ganz] : strtolower(trim((string) $wert));
        if (!in_array($m, $k, true)) {
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
    if ($art === 'planstunden') {
        // Vorlauf in Stunden, mit Nachkommastellen (C11).
        if (!is_numeric($zahl)) { return array(0, 'KEINE_ZAHL'); }
        $z = (float) $zahl;
        if ($z < (float) $b['min'] || $z > (float) $b['max']) {
            return array(0, 'BEREICH;ERLAUBT=' . $b['min'] . '..' . $b['max']);
        }
        return array(1, (string) $z);
    }
    if ($art === 'ganz' || $art === 'plan' || $art === 'planziel') {
        if ($ganz === null || $ganz < $b['min'] || $ganz > $b['max']) {
            return array(0, 'BEREICH;ERLAUBT=' . $b['min'] . '..' . $b['max']);
        }
        return array(1, (string) $ganz);
    }
    if ($art === 'zahl') {
        if (!is_numeric($zahl)) { return array(0, 'KEINE_ZAHL'); }
        $z = (float) $zahl;
        /* Grenzen auswerten, wo die Befehlstabelle welche nennt. Bis 0.9.26
         * kannte 'zahl' keine: gemessen gingen eine negative
         * Ladeenergiegrenze und eine Preisgrenze von einer Milliarde
         * ungeprueft an EVCC - und alle drei Befehle dieser Art sind
         * Analogausgaenge in der Loxone-Vorlage, also genau die Stelle, an
         * der ein falsch verdrahteter Baustein ankommt. */
        if (isset($b['min']) && $z < (float) $b['min']) {
            return array(0, 'BEREICH;ERLAUBT=' . $b['min'] . '..'
                            . (isset($b['max']) ? $b['max'] : ''));
        }
        if (isset($b['max']) && $z > (float) $b['max']) {
            return array(0, 'BEREICH;ERLAUBT=' . (isset($b['min']) ? $b['min'] : '')
                            . '..' . $b['max']);
        }
        return array(1, (string) $z);
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

/** Wie alt duerfen die Zusatzwerte werden, bevor sie neu geholt werden.
 *  Die Preisvorschau ueberlebt einen gescheiterten Abruf, solange ihre
 *  Raten die laufende Stunde abdecken (C1, seit 0.9.34).
 *
 *  $ohne_netz = true (EVCC-a1): der Zustand von EVCC war eben nicht
 *  abrufbar. Dann wird NICHTS abgefragt, nur aus den gespeicherten Raten
 *  neu gerechnet. */
define('EV_ZUSATZ_ALTER', 300);

function ev_zusatz_holen($erzwingen = false, $ohne_netz = false)
{
    $cache = ev_tmpdir() . '/state.json';
    $st = is_file($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
    if (!is_array($st)) { return 0; }
    $alt = isset($st['roh']['lox']['stand']) ? (int) $st['roh']['lox']['stand'] : 0;
    if (!$erzwingen && (time() - $alt) < EV_ZUSATZ_ALTER) { return 0; }
    $ev_alt_lox = (isset($st['roh']['lox']) && is_array($st['roh']['lox'])) ? $st['roh']['lox'] : array();

    /* EVCC ausgefallen (EVCC-a1, Verbesserungsbau 30.09.2026).
     *
     * Bis 0.9.36 fragte der Abrufdienst Tarif und Statistik auch dann, wenn
     * EVCC eben nicht geantwortet hatte: zu den 8 s des Zustandsabrufs kamen
     * 6 + 6 s Zeitgrenze, ein Lauf dauerte ~20 s statt ~8 s
     * (EVCC_BEFUNDE_UND_VERBESSERUNGEN.md, a1). Jetzt wird nichts abgefragt:
     *   - die gespeicherten Raten gelten weiter wie bei einem gescheiterten
     *     Tarifabruf (C1, seit 0.9.34) und werden fuer JETZT neu gerechnet;
     *     deckt keine mehr die laufende Stunde ab, gilt PREIS_OK=0;
     *   - Prognose und Statistik bleiben stehen;
     *   - der Zeitpunkt 'stand' der Zusatzwerte bleibt stehen - der naechste
     *     gelungene Lauf holt deshalb sofort nach.
     * Ohne gespeicherte Zusatzwerte gibt es nichts neu zu rechnen. */
    if ($ohne_netz) {
        if (!$ev_alt_lox) { return 0; }
        $lox = $ev_alt_lox;
        $grund = ev_t('LOG.PREIS_OHNE_EVCC');
        $alt_raten = (isset($ev_alt_lox['preis']['raten']) && is_array($ev_alt_lox['preis']['raten']))
            ? $ev_alt_lox['preis']['raten'] : array();
        $preis = $alt_raten ? ev_preis_rechnen($alt_raten, time()) : null;
        if ($preis !== null) {
            $preis['raten'] = $alt_raten;
            $preis['alt'] = 1;
            ev_log_wenn_neu('preis', sprintf(ev_t('LOG.PREIS_ALT'), $grund));
        } else {
            $preis = array('ok' => 0);
            ev_log_wenn_neu('preis', sprintf(ev_t('LOG.PREIS_KEINE'), $grund));
        }
        $lox['preis'] = $preis;
        $st2 = json_decode((string) @file_get_contents($cache), true);
        if (is_array($st2) && isset($st2['roh']) && is_array($st2['roh'])) { $st = $st2; }
        $st['roh']['lox'] = $lox;
        ev_datei_schreiben($cache, (string) json_encode($st), 0664);
        return 1;
    }

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

    /* ---- Preisvorschau (C1, seit 0.9.34) ----
     *
     * Bis 0.9.33 ersetzte jeder Lauf 'lox' ganz; scheiterte der Tarifabruf,
     * waren die Preise weg, und ev_werte() machte aus jedem fehlenden Feld
     * eine 0 - Rang 0 bei OK=1 und FEHLER_NR=0, ohne Protokollzeile
     * (Pruefbericht code, Befund 1). Jetzt:
     *   - die Raten werden mit Anfang und Ende gespeichert;
     *   - scheitert der Abruf oder liefert er nichts, gelten die gespeicherten
     *     Raten weiter, solange eine davon die laufende Stunde abdeckt - die
     *     Kennzahlen werden fuer JETZT neu gerechnet;
     *   - sonst ok = 0: PREIS_OK=0, und Rang, Stundenzahl und beste Stunde
     *     haben keine Aussage (-1 bzw. '-');
     *   - der Fehlschlag steht einmal im Protokoll (gebremst). */
    $raten = null;
    $grund = '';
    $a = ev_http('/api/tariff/grid', 'GET', null, 6);
    if ($a['ok']) {
        $raten = ev_preis_raten(json_decode($a['body'], true));
        if (!$raten) { $grund = ev_t('LOG.PREIS_LEER'); }
    } else {
        $grund = (string) $a['fehler'];
    }
    $jetzt = time();
    $preis = $raten ? ev_preis_rechnen($raten, $jetzt) : null;
    if ($preis === null && $raten) { $grund = ev_t('LOG.PREIS_NICHT_JETZT'); }
    if ($preis !== null) {
        $preis['raten'] = $raten;
        ev_log_wenn_neu('preis', 'ok, ' . count($raten) . ' Raten');
    } else {
        $alt_raten = (isset($ev_alt_lox['preis']['raten']) && is_array($ev_alt_lox['preis']['raten']))
            ? $ev_alt_lox['preis']['raten'] : array();
        $preis = $alt_raten ? ev_preis_rechnen($alt_raten, $jetzt) : null;
        if ($preis !== null) {
            $preis['raten'] = $alt_raten;
            $preis['alt'] = 1;
            ev_log_wenn_neu('preis', sprintf(ev_t('LOG.PREIS_ALT'), $grund));
        } else {
            $preis = array('ok' => 0);
            ev_log_wenn_neu('preis', sprintf(ev_t('LOG.PREIS_KEINE'), $grund));
        }
    }
    $lox['preis'] = $preis;

    /* ---- Statistik ---- */
    $a = ev_http('/api/statistics', 'GET', null, 6);
    if ($a['ok']) {
        $j = json_decode($a['body'], true);
        if (isset($j['result'])) { $j = $j['result']; }
        if (is_array($j)) { $lox['statistik'] = $j; }
    } elseif (isset($ev_alt_lox['statistik'])) {
        // Die Statistik hat keinen Zeitbezug zur laufenden Stunde; bei einem
        // Fehlschlag bleibt die letzte stehen, wie bei der Oberflaeche.
        $lox['statistik'] = $ev_alt_lox['statistik'];
    }

    /* Den JUENGSTEN Stand ergaenzen, nicht den von vorhin: zwischen Lesen und
     * Schreiben kann ein Abruf state.json erneuert haben (C7). */
    $st2 = json_decode((string) @file_get_contents($cache), true);
    if (is_array($st2) && isset($st2['roh']) && is_array($st2['roh'])) { $st = $st2; }
    $st['roh']['lox'] = $lox;
    ev_datei_schreiben($cache, (string) json_encode($st), 0664);
    return 1;
}

/**
 * Die Raten aus der Antwort von /api/tariff/grid: Liste von
 * array(anfang_ts, ende_ts, preis). Fehlt das Ende, gilt der Anfang der
 * naechsten Rate bzw. eine Stunde. Rueckgabe array() ohne verwertbare Rate.
 */
function ev_preis_raten($j)
{
    if (is_array($j) && isset($j['result'])) { $j = $j['result']; }
    $liste = null;
    foreach (array('rates', 'Rates') as $k) {
        if (is_array($j) && isset($j[$k]) && is_array($j[$k])) { $liste = $j[$k]; break; }
    }
    if ($liste === null && is_array($j) && isset($j[0])) { $liste = $j; }
    $raten = array();
    if (!is_array($liste)) { return $raten; }
    foreach ($liste as $r) {
        if (!is_array($r)) { continue; }
        $p = null;
        foreach (array('price', 'value', 'Price') as $k) {
            if (isset($r[$k]) && is_numeric($r[$k])) { $p = (float) $r[$k]; break; }
        }
        $a = null;
        foreach (array('start', 'Start') as $k) {
            if (isset($r[$k])) { $t = strtotime((string) $r[$k]); if ($t !== false) { $a = $t; } break; }
        }
        $e = null;
        foreach (array('end', 'End') as $k) {
            if (isset($r[$k])) { $t = strtotime((string) $r[$k]); if ($t !== false) { $e = $t; } break; }
        }
        if ($p === null || $a === null) { continue; }
        $raten[] = array($a, $e, $p);
    }
    usort($raten, function ($x, $y) { return $x[0] - $y[0]; });
    $n = count($raten);
    for ($i = 0; $i < $n; $i++) {
        if ($raten[$i][1] === null || $raten[$i][1] <= $raten[$i][0]) {
            $raten[$i][1] = ($i + 1 < $n) ? $raten[$i + 1][0] : $raten[$i][0] + 3600;
        }
    }
    return $raten;
}

/**
 * Die Kennzahlen fuer den Zeitpunkt $jetzt: nur Raten, die noch nicht zu
 * Ende sind; die laufende Rate muss darunter sein, sonst null (keine Aussage).
 * Der Rang zaehlt, wie viele der kuenftigen Raten billiger sind als die
 * laufende (1 = die guenstigste).
 */
function ev_preis_rechnen($raten, $jetzt)
{
    $kommend = array();
    $laufend = null;
    foreach ($raten as $r) {
        if (!is_array($r) || count($r) < 3) { continue; }
        if ((int) $r[1] <= $jetzt) { continue; }
        $kommend[] = $r;
        if ($laufend === null && (int) $r[0] <= $jetzt && $jetzt < (int) $r[1]) { $laufend = $r; }
    }
    if ($laufend === null || !$kommend) { return null; }
    $preise = array();
    foreach ($kommend as $r) { $preise[] = (float) $r[2]; }
    $rang = 1;
    foreach ($preise as $p) { if ($p < (float) $laufend[2]) { $rang++; } }
    $besti = array_search(min($preise), $preise, true);
    return array(
        'ok' => 1,
        'min' => min($preise), 'max' => max($preise),
        'schnitt' => round(array_sum($preise) / count($preise), 4),
        'rang' => $rang, 'anzahl' => count($preise),
        'beste_stunde' => (int) date('G', (int) $kommend[$besti][0]),
    );
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
    return array(
        $p['home'] . '/webfrontend/htmlauth/plugins/' . $p['plugin'] . '/ev_lib.php',
    );
}

/**
 * Den eigenen Endpunkt WIRKLICH aufrufen.
 *
 * Rueckgabe: array(ok, code, erste Zeile, Adresse).
 */
function ev_selbsttest_endpunkt($aktion = 'status', $zeit = 10)
{
    $url = ev_endpunkt($aktion) . '&selbsttest=1';
    $kopf = array('User-Agent: LoxBerry-EVCC-Plugin-Selbsttest', 'Accept: text/plain');
    $body = false;
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $zeit,
            CURLOPT_CONNECTTIMEOUT => min(5, $zeit), CURLOPT_HTTPHEADER => $kopf,
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = curl_error($ch);
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
        if ($body === false) { return array(0, 0, ev_netzfehler($fehler, $url), $url); }
    } else {
        /* Kopfzeilen ueber den Datenstrom (C8, seit 0.9.34) - ev_http_strom(). */
        $ctx = stream_context_create(array('http' => array(
            'method' => 'GET', 'timeout' => $zeit, 'ignore_errors' => true,
            'follow_location' => 0, 'max_redirects' => 1,
            'header' => implode("\r\n", $kopf))));
        $ev_dst = ini_get('default_socket_timeout');
        @ini_set('default_socket_timeout', (string) (int) $zeit);
        list($body, $code) = ev_http_strom($url, $ctx);
        if ($ev_dst !== false) { @ini_set('default_socket_timeout', (string) $ev_dst); }
        if ($body === false) { return array(0, $code, ev_t('FEHLER.KEINE_ANTWORT'), $url); }
    }
    $erste = trim(strtok((string) $body, "\n"));
    if ($erste === '' && $code >= 500) {
        // Genau das Bild der Fassung 0.9.10: Code 500, Rumpf leer. Der Grund
        // steht dann nur im Fehlerprotokoll des Webservers, nicht hier.
        $erste = ev_t('FEHLER.LEER_500');
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
/**
 * Taugt der Wert ueberhaupt fuer eine Einstellung dieses Plugins?
 *
 * Die erste von zwei Pruefungen: Form, nicht Bedeutung. Bis 0.9.26 gab es
 * keine - gemessen wurden aus einer Sicherungsdatei ein Feld als URL, ein
 * Text als Takt, ein Nullbyte im Passwort und ein Zeilenumbruch im
 * MQTT-Thema anstandslos uebernommen.
 */
function ev_wert_taugt($v)
{
    if (is_array($v) || is_object($v) || is_bool($v) || is_null($v)) { return false; }
    $s = (string) $v;
    if (strlen($s) > 4096) { return false; }
    // Steuerzeichen ohne den einfachen Zeilenumbruch: die Konfiguration ist
    // JSON, aber Werte daraus landen in HTTP-Kopfzeilen und MQTT-Zeilen.
    return preg_match('/[\x00-\x1F\x7F]/', $s) !== 1;
}

/**
 * Ist der Wert fuer DIESE Einstellung zulaessig?
 *
 * Die zweite Pruefung, gegen dieselbe Positivliste, die auch das Formular
 * benutzt. Fail closed: ein Schluessel ohne Regel wird NICHT angenommen -
 * der Reiter Test zaehlt nach, ob jede Vorgabe eine Regel hat, damit ein
 * neuer Schluessel nicht stillschweigend das Zurueckspielen bricht.
 */
function ev_wert_pruefen($schluessel, $wert)
{
    /* Nr. 36 b: der Block tts ist ein Feld; Ausgabeart, Adresse (Heimnetz) und Vorlage prueft das Modul. */
    if ($schluessel === 'tts') {
        $ev_tg = '';
        return is_array($wert) && ansage_wert_pruefen($wert, $ev_tg, ev_ansage_modi()) !== null;
    }
    /* NICHT getrimmt (C9, seit 0.9.34): ein Wert mit Leerzeichen am Rand wird
     * abgewiesen, nicht zurechtgebogen (Regeln/05, Ergaenzung 24.09.2026).
     * Bis 0.9.33 stand hier trim(), und eine Sicherung mit " http://... "
     * wurde still berichtigt uebernommen (Pruefbericht code, Befund 9). */
    if (is_array($wert) || is_object($wert) || is_bool($wert) || is_null($wert)) { return false; }
    $s = (string) $wert;
    switch ($schluessel) {
        case 'url':
            return $s !== '' && preg_match(
                '#^https?://[A-Za-z0-9\.\-]+(:[0-9]{1,5})?(/\S*)?$#', $s) === 1;
        case 'passwort':
            // Leerzeichen im Passwort sind erlaubt, am Rand nicht: dort gehen
            // sie in der Kopfzeile 'Authorization: Bearer ...' verloren.
            return strlen($s) <= 256 && $s === trim($s)
                   && preg_match('/[\x00-\x1F\x7F]/', $s) !== 1;
        case 'aktionstoken':
            // Weit gefasst: alles, was ohne Kodierung in eine Adresse passt.
            // Ein zu enges Muster verwirft ein gueltiges Token, und der
            // Schaden ist derselbe wie bei einem verlorenen.
            return preg_match('/^[A-Za-z0-9_.\-]{0,64}$/', $s) === 1;
        case 'mqtt_topic':
            // Kein Schraegstrich am Rand und kein doppelter (O2): das Plugin
            // haette ihn sonst beim Senden still entfernt.
            return preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*$#', $s) === 1 && strlen($s) <= 64;
        case 'mqtt_vollsend_min':
            return preg_match('/^[0-9]{1,4}$/', $s) === 1 && (int) $s <= 1440;
        case 'takt':
            return preg_match('/^[0-9]{1,3}$/', $s) === 1
                   && (int) $s >= 5 && (int) $s <= 60;
        case 'ladepunkte':
            return preg_match('/^[0-9]{1,2}$/', $s) === 1 && (int) $s <= EV_LADEPUNKTE;
        case 'fahrzeuge':
            return preg_match('/^[0-9]{1,2}$/', $s) === 1 && (int) $s <= EV_FAHRZEUGE;
        case 'steuerung_ein':
        case 'mqtt_ein':
        case 'tarife_ein':
        case 'update_ein':
        case 'wache_ein':
        case 'wache_lb_melden':
        case 'wache_sperren_ein':
        case 'ansage_ausfall':
        case 'ansage_startfehler':
        case 'ansage_fertig':
            return in_array($s, array('0', '1'), true);
        case 'wache_fenster_min':
            // Energie-1 C1: ganze Minuten 1..120, ohne Nachkommastellen.
            return preg_match('/^[0-9]{1,3}\z/', $s) === 1 && (int) $s >= 1 && (int) $s <= 120;
        case 'wache_erlaubt':
            // Energie-1 C1: dieselbe Zerlegung wie der Endpunkt (ev_wache_liste()).
            if (strlen($s) > 512 || preg_match('/[\x00-\x1F\x7F]/', $s) === 1) { return false; }
            list(, $ev_wf) = ev_wache_liste($s);
            return !$ev_wf;
    }
    return false;
}

/**
 * Besteht ein Wert das Zurueckspielen? Rueckgabe '' oder die Beanstandung.
 *
 * EINE Pruefung fuer zwei Stellen (X-3, Verbesserungsbau 30.09.2026):
 * ev_sicherung_lesen() weist damit ab, und ev_sicherung_altwerte() sagt damit
 * vor dem Sichern, welcher GESPEICHERTE Wert die eigene Sicherung zu Fall
 * braechte. Zwei Pruefungen liefen auseinander.
 *   - Form (ev_wert_taugt), Rand (nicht getrimmt, C9/O3), Regel (ev_wert_pruefen);
 *   - ein leeres Aktionstoken ist kein Mangel: es heisst "keins gesichert",
 *     und ev_sicherung_lesen() behaelt dann das geltende (O3).
 */
function ev_sicherung_wert_mangel($k, $w)
{
    /* Nr. 36 b: der Block tts - welche gespeicherten Werte (nie die Werte selbst) brachten das Zurueckspielen zu Fall? */
    if ($k === 'tts') {
        $ev_tx = is_array($w) ? ansage_sicherung_x3($w, ev_ansage_modi()) : array('tts');
        return $ev_tx ? sprintf(ev_t('EINST.SICH_WERT_UNZULAESSIG'), ev_e(implode(', ', $ev_tx))) : '';
    }
    if (!ev_wert_taugt($w)) {
        return sprintf(ev_t('EINST.SICH_WERT_FORM'), ev_e((string) $k));
    }
    /* Ein Wert mit Leerzeichen am Rand wird abgewiesen und benannt, nicht
     * getrimmt (C9, O3, seit 0.9.34). */
    if (is_string($w) && $w !== trim($w)) {
        return sprintf(ev_t('EINST.SICH_WERT_RAND'), ev_e((string) $k));
    }
    if ($k === 'aktionstoken' && (string) $w === '') {
        return '';
    }
    if (!ev_wert_pruefen($k, $w)) {
        return sprintf(ev_t('EINST.SICH_WERT_UNZULAESSIG'), ev_e((string) $k));
    }
    return '';
}

/**
 * Welche GESPEICHERTEN Werte wuerde das Zurueckspielen der eigenen Sicherung
 * abweisen? (X-3) Rueckgabe: die Schluessel, leer = keiner.
 *
 * Moeglich ist das, wo ev_config() einen Wert nicht selbst in die Form bringt:
 * ein von Hand eingetragenes Thema mit Schraegstrich am Rand ('evcc2lox/'),
 * eine Adresse oder ein Passwort mit Leerzeichen am Rand, ein Token mit
 * Zeichen ausserhalb von A-Z a-z 0-9 _ . -. Die Sicherung wird trotzdem
 * geliefert; die Seite sagt am Knopf, welcher Wert sie zu Fall braechte.
 */
function ev_sicherung_altwerte($cfg = null)
{
    if (!is_array($cfg)) { $cfg = ev_config(); }
    $schlecht = array();
    foreach (array_keys(ev_vorgaben()) as $k) {
        if (array_key_exists($k, $cfg) && ev_sicherung_wert_mangel($k, $cfg[$k]) !== '') {
            $schlecht[] = $k;
        }
    }
    /* Energie-1 C1: Sperren an ohne erlaubten Schreiber weist das Zurueckspielen ab
     * (Kreuzpruefung), also warnt auch der Knopf. */
    if (ev_wache_kreuz($cfg) && !in_array('wache_erlaubt', $schlecht, true)) {
        $schlecht[] = 'wache_erlaubt';
    }
    return $schlecht;
}

function ev_sicherung_lesen($roh)
{
    $mangel = array();
    $hinweise = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(ev_t('EINST.SICH_KEIN_JSON')), 0, array());
    }
    $neu = ev_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        /* Der lesbare Kopf wird UEBERGANGEN, nicht beanstandet. Er ist keine
         * Einstellung, sondern die Auskunft, aus welcher Anlage und aus
         * welcher Fassung die Datei stammt. Bis 0.9.26 hat der Leser ihn als
         * fremden Schluessel abgelehnt - und damit die eigene Sicherung,
         * sobald sie einen bekam. */
        if ((string) $k !== '' && substr((string) $k, 0, 1) === '_') {
            continue;
        }
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(ev_t('EINST.SICH_FREMD'), ev_e((string) $k));
            continue;
        }
        if ($k === 'tts') {
            /* Nr. 36 b (Stufe 2): eine Sicherung dieses Plugins traegt nie ein Sprechtoken - traegt die
             * Datei eines, wird sie abgewiesen. Die geltenden Sprechtoken bleiben; Ausgabeart, Adresse und
             * Vorlage werden wie im Formular geprueft (Heimnetz). */
            $ev_tm = ansage_sicherung_mangel($w);
            if ($ev_tm) {
                $mangel[] = sprintf(ev_t('DURCHSAGE.SICH_TOKEN'), ev_e(implode(', ', $ev_tm)));
                continue;
            }
            $ev_tg = '';
            $ev_tp = ansage_wert_pruefen($w, $ev_tg, ev_ansage_modi());
            if ($ev_tp === null) {
                $mangel[] = sprintf(ev_t('DURCHSAGE.SICH_WERT'), ev_e(ansage_kennung_text($ev_tg, ev_ansage_k())));
                continue;
            }
            $ev_tj = ev_tts(ev_config());
            list($ev_tv) = ansage_vervollstaendigen($ev_tp + $ev_tj);
            $neu['tts'] = ansage_sicherung_tokens_behalten($ev_tv, $ev_tj);
            $anzahl++;
            continue;
        }
        /* Form, Rand und Regel prueft EINE Funktion - dieselbe, mit der
         * "Einstellungen sichern" vorher warnt (X-3). */
        $ev_wm = ev_sicherung_wert_mangel($k, $w);
        if ($ev_wm !== '') {
            $mangel[] = $ev_wm;
            continue;
        }
        /* Ein LEERES Aktionstoken in der Sicherung heisst "keins gesichert"
         * (O3, Regeln/05, VolkswagenID-Aufloesung): das geltende bleibt, und
         * die Seite sagt es. Bis 0.9.33 wurde es uebernommen - danach
         * antwortete der Endpunkt jedem Virtuellen Eingang mit 503, und die
         * Meldung lautete "12 Werte uebernommen" (Pruefbericht oberflaeche,
         * Befund 4). */
        if ($k === 'aktionstoken' && (string) $w === '') {
            $ev_jetzt = (string) ev_config()['aktionstoken'];
            $neu[$k] = $ev_jetzt;
            $hinweise[] = ev_t($ev_jetzt !== '' ? 'EINST.SICH_TOKEN_BLEIBT' : 'EINST.SICH_TOKEN_KEINS');
            $anzahl++;
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = ev_t('EINST.SICH_LEER');
    }
    /* FEHLENDE Schluessel sind eine Beanstandung, kein stiller Rueckfall.
     *
     * Eine Datei mit einem einzigen Schluessel lief bis 0.9.26 ohne
     * Beanstandung durch, und alle uebrigen Einstellungen fielen auf Werk
     * zurueck - quittiert mit "1 Wert uebernommen" (gemessen an VolkswagenID
     * 0.9.11 am 03.09.2026, am 07.09.2026 ueber den Bestand ausgerollt). Der
     * Hausstandard sagt: eine halb gueltige Datei aendert gar nichts. */
    $fehlend = array();
    $ev_behalten = array();
    $ev_abehalten = array();
    $ev_jetzt_cfg = null;
    foreach (array_keys(ev_vorgaben()) as $fk) {
        if (!array_key_exists($fk, $daten)) {
            /* Energie-1 C1: eine Sicherung von vor der Schreiber-Wache kennt deren
             * Einstellungen nicht. Sie ist trotzdem vollstaendig; die geltenden Werte
             * der Wache bleiben, und die Seite sagt es (wie Marstek 1.1.19). */
            /* Nr. 36 b: ebenso die Ansage (tts und die Anlaesse) aus einer Sicherung von vor 0.9.39. */
            if (in_array($fk, ev_ansage_schluessel(), true)) {
                if ($ev_jetzt_cfg === null) { $ev_jetzt_cfg = ev_config(); }
                $neu[$fk] = $ev_jetzt_cfg[$fk];
                $ev_abehalten[] = $fk;
                continue;
            }
            if (in_array($fk, ev_wache_schluessel(), true)) {
                if ($ev_jetzt_cfg === null) { $ev_jetzt_cfg = ev_config(); }
                $neu[$fk] = $ev_jetzt_cfg[$fk];
                $ev_behalten[] = $fk;
                continue;
            }
            $fehlend[] = $fk;
        }
    }
    if ($fehlend) {
        $mangel[] = sprintf(ev_t('EINST.SICH_FEHLEND'), count($fehlend),
            htmlspecialchars(implode(', ', $fehlend), ENT_QUOTES, 'UTF-8'));
    }
    if ($ev_behalten) {
        $hinweise[] = sprintf(ev_t('EINST.SICH_WACHE_BEHALTEN'), ev_e(implode(', ', $ev_behalten)));
    }
    if ($ev_abehalten) {
        $hinweise[] = sprintf(ev_t('DURCHSAGE.SICH_BEHALTEN'), ev_e(implode(', ', $ev_abehalten)));
    }
    /* Energie-1 C1: Sperren an ohne erlaubten Schreiber - dieselbe Kreuzpruefung wie
     * das Formular. */
    if (!$mangel && ev_wache_kreuz($neu)) {
        $mangel[] = ev_t('EINST.SICH_WACHE_KREUZ');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl, $mangel ? array() : $hinweise);
}

/**
 * Die Sicherung, die "Einstellungen sichern" ausgibt (O3, seit 0.9.34): der
 * lesbare Kopf und NUR die bekannten Schluessel. Bis 0.9.33 ging die ganze
 * Konfiguration hinaus; stand darin ein fremder Schluessel (Rest einer
 * Handbearbeitung), wies das Zurueckspielen die eigene Sicherung ab
 * (Pruefbericht oberflaeche, Befund 6).
 */
function ev_sicherung_bauen()
{
    $kopf = array(
        '_hinweis' => 'Sicherung des LoxBerry-Plugins EVCC. Enthaelt das '
                    . 'Aktionstoken dieser Anlage und gegebenenfalls das '
                    . 'EVCC-Passwort - wie ein Passwort behandeln. Die Sprechtoken der '
                    . 'Sprachausgabe sind nie enthalten.',
        '_stand'   => date('Y-m-d H:i:s'),
    );
    /* X-3: Wuerde ein gespeicherter Wert das Zurueckspielen nicht bestehen,
     * sagt es auch die Datei - nur der Schluessel, nie der Wert. Ein Schluessel
     * mit Unterstrich wird beim Zurueckspielen uebergangen. */
    $ev_cfg = ev_config();
    $ev_alt = ev_sicherung_altwerte($ev_cfg);
    if ($ev_alt) {
        $kopf['_warnung'] = 'Diese Sicherung wird beim Zurueckspielen abgewiesen, solange '
                          . 'diese Werte unzulaessig sind: ' . implode(', ', $ev_alt)
                          . '. In der Oberflaeche berichtigen und neu sichern.';
    }
    $ev_aus = array_intersect_key($ev_cfg, ev_vorgaben());
    /* Nr. 36 b: die Sprechtoken der Sprachausgabe gehen nie in eine Sicherung. */
    if (isset($ev_aus['tts']) && is_array($ev_aus['tts'])) {
        $ev_aus['tts'] = ansage_sicherung_bereinigen($ev_aus['tts']);
    }
    return $kopf + $ev_aus;
}


/* ================= Nr. 36 b (Stufe 2, seit 0.9.39): Ansage ueber die gemeinsame Sprachausgabe ==================
 *
 * Ab Werk aus (Ausgabeart 'aus'). Angesagt werden nur Ereignisse, die ein Mensch hoeren will - nie ein Wert
 * im Takt:
 *   ausfall      EVCC liefert seit 5 Minuten keine Daten (nur nachdem es in diesem Lauf schon einmal
 *                geantwortet hat; nach einem Neustart des LoxBerry beginnt das neu), einmal je Ausfall;
 *   startfehler  EVCC antwortet, meldet aber einen Startfehler (FEHLER_NR 5), einmal je Auftreten;
 *   fertig       ein Ladepunkt hoert auf zu laden, das Fahrzeug steckt noch und hat seine Ladegrenze erreicht
 *                (Fahrzeug-SoC >= Ladegrenze, beide bekannt). Ohne Fahrzeug-SoC keine Ansage: eine Pause im
 *                PV-Modus sieht sonst genauso aus.
 * Jeder Anlass ist einzeln abwaehlbar; hoechstens eine Ansage je Anlass (Ladeende: je Ladepunkt) in 30 min,
 * eine gesperrte Ansage wird NICHT nachgeholt. Aus dem Abrufdienst, NACH Zeile und MQTT; die LoxBerry-Meldung
 * der Schreiber-Wache laeuft unabhaengig davon weiter. Ins Protokoll kommt nur das Ergebnis, nie der Text
 * (Nr. 18). Der Merker liegt im Zwischenspeicher (<tmp>/ansage.json) und wird nur bei einer Aenderung
 * geschrieben.
 */
if (!defined('EV_ANSAGE_SPERRE_S')) { define('EV_ANSAGE_SPERRE_S', 1800); }
if (!defined('EV_ANSAGE_AUSFALL_S')) { define('EV_ANSAGE_AUSFALL_S', 300); }

/** Erlaubte Ausgabearten: alle des Moduls ausser 'audioserver' (kein Antwortweg zu Loxone im Abrufdienst). */
function ev_ansage_modi()
{
    return array('aus', 'musicserver', 'ms4h', 'custom', 'alexang', 'cc4lox');
}

/** Die Anlaesse: Kennung => Konfigurationsschluessel. */
function ev_ansage_anlaesse()
{
    return array('ausfall' => 'ansage_ausfall', 'startfehler' => 'ansage_startfehler', 'fertig' => 'ansage_fertig');
}

/** Alle Schluessel der Ansage in der Konfiguration (fuer die Sicherung von vor 0.9.39). */
function ev_ansage_schluessel()
{
    return array_merge(array('tts'), array_values(ev_ansage_anlaesse()));
}

/** Der Block tts, vervollstaendigt (ab Werk 'aus'). */
function ev_tts($cfg = null)
{
    $cfg = is_array($cfg) ? $cfg : ev_config();
    list($t) = ansage_vervollstaendigen(isset($cfg['tts']) && is_array($cfg['tts']) ? $cfg['tts'] : array(), 'aus');
    return $t;
}

/** Ist eine Ausgabeart gewaehlt? */
function ev_ansage_an($cfg = null)
{
    $t = ev_tts($cfg);
    return is_string($t['mode']) && $t['mode'] !== 'aus' && in_array($t['mode'], ev_ansage_modi(), true);
}

/** Der Kontext des Moduls: Webport, Kopfzeile, Datenordner, Texte. */
function ev_ansage_k()
{
    $p = ev_paths();
    return array(
        'port'   => $p['home'] !== '' ? ansage_webport($p['home'] . '/config/system/general.json') : 80,
        'kopf'   => array('User-Agent: LoxBerry EVCC'),
        'ordner' => @is_dir($p['datadir']) ? $p['datadir'] : '',
        't'      => function ($s) { return ev_t($s); },
        /* K_TTS_EINTRAG: den Satz bringt das Modul seit 1.1.2 selbst mit; die Umlenkung auf
         * DURCHSAGE.SICH_EINTRAG ist seit 0.9.41 gestrichen (X-10). Ab Werk aus - kein 'werk'. */
    );
}

/** Die Felder der Ansage fuer X-2 (ev_eingabe_felder()): Sprechtoken als 'geheim' - sie reisen nie mit. */
function ev_ansage_x2()
{
    $a = array();
    foreach (ansage_feldnamen() as $id => $n) {
        if (substr($id, -9) === '_loeschen') { $a[$n] = 'haken'; }
        elseif (substr($id, -6) === '_token') { $a[$n] = 'geheim'; }
        else { $a[$n] = 'text'; }
    }
    foreach (ev_ansage_anlaesse() as $k) { $a[$k] = 'haken'; }
    return $a;
}

/** Ein Satz der Ansage aus der Sprachdatei, ohne Auszeichnung. */
function ev_ansage_satz($schluessel, array $werte)
{
    return trim(html_entity_decode(strip_tags(vsprintf(ev_t($schluessel), $werte)), ENT_QUOTES, 'UTF-8'));
}

/**
 * Aus dem Abrufdienst, nach Zeile und MQTT. $werte: ev_werte(). Rueckgabe array(versucht, gescheitert).
 * Der Merker haelt: ob EVCC in diesem Lauf schon geantwortet hat, ob der laufende Ausfall angesagt ist, ob
 * der Startfehler schon gemeldet ist, je Ladepunkt "laedt" und die Sperre je Anlass - nie Text oder Token.
 */
function ev_ansage_takt(array $werte, $jetzt = null)
{
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $cfg = ev_config(false);
    $datei = ev_tmpdir() . '/ansage.json';
    if (!ev_ansage_an($cfg)) {
        /* Aus: nichts sagen, nichts merken - sonst kaeme beim Einschalten ein alter Zustand. */
        if (is_file($datei)) { @unlink($datei); }
        return array(0, 0);
    }
    $fh = @fopen(ev_tmpdir() . '/ansage.lock', 'ce');
    if ($fh === false) { return array(0, 0); }
    if (!@flock($fh, LOCK_EX | LOCK_NB)) { @fclose($fh); return array(0, 0); }
    $n = 0;
    $fehl = 0;
    $roh = is_file($datei) ? (string) @file_get_contents($datei) : '';
    $m = $roh === '' ? array() : json_decode($roh, true);
    if (!is_array($m)) { $m = array(); }
    $alt_js = json_encode($m);
    $w = function ($name) use ($werte) { return isset($werte[$name]['wert']) ? $werte[$name]['wert'] : null; };
    $ohne = function ($name) use ($werte) { return !isset($werte[$name]) || !empty($werte[$name]['ohne']); };
    $ok = (int) $w('ok') === 1;
    $faelle = array();     // array(Anlass, Sperrschluessel, Satz, Name im Protokoll)

    /* Ausfall: erst wenn EVCC in diesem Lauf schon geantwortet hat (sonst ist es Einrichtung, kein Ausfall). */
    if ($ok) {
        $m['ok_gesehen'] = 1;
        $m['ausfall_gesagt'] = 0;
    } elseif (!empty($m['ok_gesehen']) && empty($m['ausfall_gesagt'])
              && (int) $w('alter_s') >= EV_ANSAGE_AUSFALL_S && (int) $w('alter_s') < 99999) {
        $m['ausfall_gesagt'] = 1;
        $faelle[] = array('ausfall', 'ausfall',
            ev_ansage_satz('DURCHSAGE.TEXT_AUSFALL', array((int) floor((int) $w('alter_s') / 60))), 'Ausfall');
    }

    /* Startfehler: nur aus einer Antwort von EVCC (FEHLER_NR 0, 4 oder 5) - ein Abruffehler dazwischen
     * setzt den Merker nicht zurueck. */
    $nr = (int) $w('fehler_nr');
    if (in_array($nr, array(0, 4, 5), true)) {
        $fatal = $nr === 5 ? 1 : 0;
        if ($fatal && empty($m['fatal'])) {
            $faelle[] = array('startfehler', 'startfehler', ev_ansage_satz('DURCHSAGE.TEXT_START', array()), 'Startfehler');
        }
        $m['fatal'] = $fatal;
    }

    /* Ladeende je Ladepunkt: laedt 1 -> 0, Fahrzeug steckt, SoC >= Ladegrenze (beide bekannt). */
    $lp_alt = (isset($m['lp']) && is_array($m['lp'])) ? $m['lp'] : array();
    $lp_neu = array();
    for ($i = 1; $i <= EV_LADEPUNKTE; $i++) {
        $pre = 'lp' . $i . '_';
        $vor = isset($lp_alt[$i]) ? (int) $lp_alt[$i] : -1;
        if (!$ok || $ohne($pre . 'laedt')) {
            if ($ok) { continue; }                  // Ladepunkt nicht da: vergessen
            if ($vor !== -1) { $lp_neu[$i] = $vor; } // kein frischer Stand: Merker behalten
            continue;
        }
        $laedt = (int) $w($pre . 'laedt') === 1 ? 1 : 0;
        $lp_neu[$i] = $laedt;
        if ($vor === 1 && $laedt === 0 && (int) $w($pre . 'verbunden') === 1
            && !$ohne($pre . 'fahrzeug_soc') && !$ohne($pre . 'limit_soc')) {
            $soc = (float) $w($pre . 'fahrzeug_soc');
            $lim = (float) $w($pre . 'limit_soc');
            if ($soc > 0 && $lim > 0 && $soc >= $lim) {
                $name = $ohne($pre . 'fahrzeug_name') ? '' : trim((string) $w($pre . 'fahrzeug_name'));
                if ($name === '' || $name === '0') { $name = sprintf(ev_t('DURCHSAGE.LADEPUNKT'), $i); }
                $faelle[] = array('fertig', 'fertig|lp' . $i,
                    ev_ansage_satz('DURCHSAGE.TEXT_FERTIG', array($name, (int) round($soc))), 'Ladeende Ladepunkt ' . $i);
            }
        }
    }
    $m['lp'] = $lp_neu;

    $anl = ev_ansage_anlaesse();
    $sperre = (isset($m['sperre']) && is_array($m['sperre'])) ? $m['sperre'] : array();
    $tts = null;
    $k = null;
    foreach ($faelle as $f) {
        list($anlass, $schl, $satz, $wer) = $f;
        if (empty($cfg[$anl[$anlass]])) { continue; }    // abgewaehlt
        $zuletzt = isset($sperre[$schl]) ? (int) $sperre[$schl] : 0;
        if ($zuletzt > 0 && ($jetzt - $zuletzt) < EV_ANSAGE_SPERRE_S && ($jetzt - $zuletzt) >= -300) {
            ev_log('Ansage: ' . $wer . ' innerhalb von 30 min nach der letzten Ansage dieses Anlasses - '
                   . 'nicht angesagt (Wiederholsperre).');
            continue;
        }
        $sperre[$schl] = $jetzt;
        if ($tts === null) { $tts = ev_tts($cfg); $k = ev_ansage_k(); }
        $r = ansage_sprechen($satz, $tts, $k);
        $n++;
        if ($r['stand'] === 1) {
            ev_log('Ansage: ' . $wer . ' angesagt (' . ansage_kurz($r) . ').');
        } else {
            $fehl++;
            ev_log('Ansage: ' . $wer . ' nicht angesagt: ' . ansage_kennung_text($r['kennung'], $k)
                   . '. Zeile, MQTT und LoxBerry-Meldung sind davon nicht betroffen; es wird nicht wiederholt.');
        }
    }
    foreach ($sperre as $kk => $t) {
        if (!is_string($kk) || ($jetzt - (int) $t) > 86400 || ($jetzt - (int) $t) < -86400) { unset($sperre[$kk]); }
    }
    $m['sperre'] = $sperre;
    $neu_js = json_encode($m);
    if ($neu_js !== false && $neu_js !== $alt_js && !ev_datei_schreiben($datei, $neu_js, 0600)) {
        ev_log_wenn_neu('ansage_merker', 'WARNUNG: Der Merker fuer die Ansage liess sich nicht schreiben (' . $datei . ').');
    }
    @flock($fh, LOCK_UN);
    @fclose($fh);
    return array($n, $fehl);
}

/** Die Zeile im Reiter Test: 1 Haken, 0 Kreuz, -1 Hinweis (aus). Der Text ist maskiert (Modul). */
function ev_pruefe_ansage($cfg = null)
{
    list($st, $text) = ansage_pruefzeile(ev_tts($cfg), true, ev_ansage_k());
    return array($st === 1 ? 1 : ($st === -2 ? -1 : 0), $text);
}


/* ================= Schreiber-Wache (Energie-1 C1, Entscheidung Nr. 25) ==================
 *
 * WOZU. Ein Stellglied sollen nicht zwei Regler zugleich fuehren. Im Haus
 * koordiniert Loxone (Vorrangkette Hausspeicher vor Auto); ein zweiter Schreiber
 * am EVCC-Endpunkt - ein anderes Plugin, ein Skript, ein zweiter Miniserver -
 * stellte dieselben Groessen (Lademodus, Residualleistung, Batteriemodus ...)
 * gegen Loxone, und bis 0.9.37 unterschied der Endpunkt seine Schreiber nicht.
 *
 * WAS. Jeder schaltende Befehl (alle Aktionen aus ev_befehle()) wird mit seiner
 * Herkunft gemerkt: optional &von=<kennung> (die Vorlage setzt von=loxone) und der
 * Absender (REMOTE_ADDR). Ein Schreiber ist das Paar Kennung@Absender; ohne &von=
 * heisst er "ohne Kennung" - das ist kein Fehler, so erscheint jede Loxone-Vorlage,
 * die nicht neu eingelesen wurde. Kommen innerhalb des Fensters (wache_fenster_min,
 * ab Werk 15) Befehle von mehr als einem Schreiber, steht das
 *   - im Protokoll, gebremst: eine Zeile, wenn die Runde der Schreiber neu ist,
 *     sonst hoechstens eine je Fenster,
 *   - in der Antwort (;SCHREIBER=n),
 *   - im Reiter Test (die Schreiber der letzten 24 h mit Zeitpunkt und Anzahl),
 *   - bei einer neuen Runde und nur mit wache_lb_melden (ab Werk aus) als
 *     LoxBerry-Meldung.
 * Abgewiesen wird dadurch NICHTS (melden ab Werk an, Entscheidung Nr. 25). Der
 * Trockenlauf (&probe=1) merkt sich nichts, prueft aber die Sperre wie echt.
 *
 * SPERREN (wache_sperren_ein, ab Werk aus): ein Befehl eines Schreibers, der nicht
 * in wache_erlaubt steht, bekommt HTTP 409 GRUND=FREMDSCHREIBER, und an EVCC geht
 * nichts. Eine Ruecknahme wird nie abgewiesen (ev_wache_ruecknahme(): Batteriemodus
 * normal, Ladeplan aus, Netzladegrenze aus) - wer die Regie an EVCC zurueckgibt,
 * fuehrt keinen zweiten Regelkreis (ENERGIE1_ENTWURF.md, Weg C 1). Das Urteil braucht
 * den Merker nicht, es haengt nur an der Liste und an der Anfrage. Ist Sperren an,
 * die Liste aber leer oder unbrauchbar (nur von Hand moeglich - Formular und
 * Sicherung weisen das ab), wirkt die Sperre nicht, und das Protokoll sagt es: eine
 * verschriebene Liste darf den Hausregler nicht aussperren.
 *
 * DER MERKER FAELLT OFFEN AUS. <tmp>/schreiber.json unter flock, geoeffnet mit
 * close-on-exec ('e'), damit kein Kindprozess die Sperre erbt; gehalten nur fuer
 * Lesen und Schreiben, nie waehrend des Sendens. Laesst er sich nicht oeffnen,
 * sperren oder schreiben, geht der Befehl trotzdem hinaus - die Antwort traegt
 * ;WACHE=MERKER, das Protokoll eine Zeile je Zustandswechsel. Anders als die
 * Befehlsbremse (503, faellt geschlossen aus): die Bremse entscheidet ueber das
 * Senden, die Wache beobachtet nur. Eine Wache, die wegen einer vollen Ramdisk den
 * Hausregler abwiese, richtete genau den Schaden an, vor dem sie warnen soll.
 *
 * WARUM 15 MINUTEN. Das Fenster muss den langsamsten regelmaessigen Schreiber
 * fassen; Loxone sendet bei jeder Aenderung, ein Fahrplan oft nur alle paar
 * Minuten. Ein laengeres Fenster liesse einen Wechsel (alte Vorlage ohne Kennung ->
 * neue mit von=loxone) entsprechend laenger als zwei Schreiber stehen. Einstellbar
 * 1 bis 120 min, wie bei Marstek 1.1.19.
 */
if (!defined('EV_WACHE_AUFBEWAHREN_S')) {
    define('EV_WACHE_AUFBEWAHREN_S', 86400);   // Reiter Test: Schreiber der letzten 24 h
}
if (!defined('EV_WACHE_HOECHSTENS')) {
    define('EV_WACHE_HOECHSTENS', 20);          // Schreiber im Merker
}

/** Die Einstellungen der Wache - EINE Liste fuer Vorgaben, Sicherung und Formular. */
function ev_wache_schluessel()
{
    return array('wache_ein', 'wache_fenster_min', 'wache_lb_melden', 'wache_sperren_ein', 'wache_erlaubt');
}

/** Pfad des Merkers der Schreiber-Wache. */
function ev_wache_datei()
{
    return ev_tmpdir() . '/schreiber.json';
}

/** Eine Kennung fuer &von= und fuer die Liste: 1 bis 32 Zeichen aus A-Z a-z 0-9 _ -.
 *  Ohne Punkt und Doppelpunkt - so verwechselt sie sich nie mit einer Adresse.
 *  \z statt $: ein angehaengter Zeilenumbruch (von=loxone%0A) passt nicht. */
function ev_wache_kennung_gueltig($k)
{
    return is_string($k) && preg_match('/^[A-Za-z0-9_\-]{1,32}\z/', $k) === 1;
}

/** Eine Absenderadresse (IPv4 oder IPv6) fuer die Liste. */
function ev_wache_adresse_gueltig($a)
{
    return is_string($a) && $a !== '' && filter_var($a, FILTER_VALIDATE_IP) !== false;
}

/** Zwei Adressen gleich? IPv6 in jeder Schreibweise (::1 = 0:0:0:0:0:0:0:1). */
function ev_wache_adresse_gleich($a, $b)
{
    if ((string) $a === (string) $b) {
        return true;
    }
    $x = @inet_pton((string) $a);
    $y = @inet_pton((string) $b);
    return $x !== false && $y !== false && $x === $y;
}

/** Der Absender dieser Anfrage, auf die zulaessigen Zeichen beschraenkt. */
function ev_wache_absender()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? preg_replace('/[^0-9A-Fa-f:.]/', '', (string) $_SERVER['REMOTE_ADDR']) : '';
    return substr((string) $ip, 0, 45);
}

/**
 * Die Liste der erlaubten Schreiber zerlegen (rein).
 * Eintraege durch Komma, Semikolon oder Leerraum getrennt, je Eintrag eine Kennung
 * ("loxone"), eine Adresse ("192.168.178.10") oder beides als Kennung@Adresse.
 * Hoechstens 16 Eintraege. Rueckgabe: array(Eintraege array('von','ip'), unzulaessige Teile).
 */
function ev_wache_liste($text)
{
    if (!is_string($text)) {
        return array(array(), array('?'));
    }
    $ein = array();
    $fehl = array();
    foreach (preg_split('/[\s,;]+/', trim($text)) as $teil) {
        if ($teil === '') {
            continue;
        }
        if (strpos($teil, '@') !== false) {
            list($von, $ip) = explode('@', $teil, 2);
            if (ev_wache_kennung_gueltig($von) && ev_wache_adresse_gueltig($ip)) {
                $ein[] = array('von' => $von, 'ip' => $ip);
                continue;
            }
        } elseif (ev_wache_adresse_gueltig($teil)) {
            $ein[] = array('von' => '', 'ip' => $teil);
            continue;
        } elseif (ev_wache_kennung_gueltig($teil)) {
            $ein[] = array('von' => $teil, 'ip' => '');
            continue;
        }
        $fehl[] = substr((string) preg_replace('/[^\x20-\x7E]/', '?', $teil), 0, 40);
    }
    if (count($ein) > 16) {
        $fehl[] = '> 16';
    }
    return array($ein, $fehl);
}

/** Steht der Schreiber Kennung@Absender in der Liste? (rein) */
function ev_wache_erlaubt(array $eintraege, $von, $ip)
{
    foreach ($eintraege as $e) {
        if ($e['von'] !== '' && $e['von'] !== (string) $von) {
            continue;
        }
        if ($e['ip'] !== '' && !ev_wache_adresse_gleich($e['ip'], $ip)) {
            continue;
        }
        return true;
    }
    return false;
}

/** Die Einstellungen der Wache aus einer Konfiguration. Was die eigene Pruefung
 *  (ev_wert_pruefen) nicht besteht - von Hand bearbeitet -, gilt mit der Vorgabe;
 *  "Einstellungen sichern" warnt dann am Knopf (X-3). */
function ev_wache_einstellungen(array $cfg)
{
    $v = ev_vorgaben();
    $aus = array();
    foreach (ev_wache_schluessel() as $k) {
        $aus[$k] = (array_key_exists($k, $cfg) && ev_wert_pruefen($k, $cfg[$k])) ? $cfg[$k] : $v[$k];
    }
    $aus['wache_ein'] = (int) $aus['wache_ein'];
    $aus['wache_fenster_min'] = (int) $aus['wache_fenster_min'];
    $aus['wache_lb_melden'] = (int) $aus['wache_lb_melden'];
    $aus['wache_sperren_ein'] = (int) $aus['wache_sperren_ein'];
    $aus['wache_erlaubt'] = (string) $aus['wache_erlaubt'];
    return $aus;
}

/** Kreuzpruefung (rein): Sperren an ohne einen einzigen erlaubten Schreiber wiese
 *  jeden Befehl ab - auch den des Hausreglers. Rueckgabe true = Mangel. */
function ev_wache_kreuz($c)
{
    return is_array($c) && isset($c['wache_sperren_ein'], $c['wache_erlaubt'])
        && is_scalar($c['wache_sperren_ein']) && (string) $c['wache_sperren_ein'] === '1'
        && is_string($c['wache_erlaubt']) && trim($c['wache_erlaubt']) === '';
}

/** Ist dieser Befehl eine Ruecknahme? Die gibt die Regie an EVCC zurueck und wird
 *  nie abgewiesen (gemerkt wird sie trotzdem). $klar: der gepruefte Wert. */
function ev_wache_ruecknahme($aktion, $klar)
{
    return ($aktion === 'batteriemodus' && (string) $klar === 'normal')
        || $aktion === 'planaus' || $aktion === 'netzladenaus';
}

/**
 * Das Urteil der Sperre (rein). Rueckgabe array(aktiv, erlaubt, fehler):
 * aktiv = Sperren an UND eine brauchbare Liste. fehler 'LISTE': Sperren an, die
 * Liste aber leer oder unbrauchbar - dann wirkt die Sperre NICHT (Kopf).
 */
function ev_wache_sperre_urteil(array $w, $von, $ip)
{
    if ((int) $w['wache_sperren_ein'] !== 1) {
        return array(false, true, '');
    }
    list($ein, $fehl) = ev_wache_liste((string) $w['wache_erlaubt']);
    if ($fehl || !$ein) {
        return array(false, true, 'LISTE');
    }
    return array(true, ev_wache_erlaubt($ein, $von, $ip), '');
}

/**
 * Den Merker fortschreiben (rein, ohne Datei - von den Proben direkt gerufen).
 * $m: array('schreiber' => array('<von>@<ip>' => Eintrag), 'runde' => '', 'gemeldet' => ts)
 * Rueckgabe: array(Merker, Schreiber im Fenster (neueste zuerst), melden, neue Runde).
 * "Runde" ist die Menge der Schreiber im Fenster; gemeldet wird eine neue Runde
 * sofort, dieselbe hoechstens einmal je Fenster. Faellt die Runde auf einen
 * Schreiber zurueck, gilt die naechste zweite wieder als neu.
 */
function ev_wache_fortschreiben(array $m, $von, $ip, $art, $abgewiesen, $jetzt, $fenster_s)
{
    $jetzt = (int) $jetzt;
    $liste = (isset($m['schreiber']) && is_array($m['schreiber'])) ? $m['schreiber'] : array();
    $schl = (string) $von . '@' . (string) $ip;
    $e = (isset($liste[$schl]) && is_array($liste[$schl])) ? $liste[$schl]
        : array('von' => (string) $von, 'ip' => (string) $ip, 'erst' => $jetzt, 'n' => 0, 'abgewiesen' => 0);
    $e['zuletzt'] = $jetzt;
    $e['n'] = (int) (isset($e['n']) ? $e['n'] : 0) + 1;
    $e['abgewiesen'] = (int) (isset($e['abgewiesen']) ? $e['abgewiesen'] : 0) + ($abgewiesen ? 1 : 0);
    $e['art'] = (string) $art;
    $liste[$schl] = $e;
    // Aufbewahren: 24 h (in beide Richtungen - eine zurueckgesprungene Uhr laesst
    // keinen Eintrag ewig stehen), hoechstens EV_WACHE_HOECHSTENS.
    foreach ($liste as $k => $x) {
        if (!is_array($x) || !isset($x['zuletzt'], $x['von'], $x['ip'])
                || abs($jetzt - (int) $x['zuletzt']) > EV_WACHE_AUFBEWAHREN_S) {
            unset($liste[$k]);
        }
    }
    uasort($liste, function ($a, $b) {
        return (int) $b['zuletzt'] - (int) $a['zuletzt'];
    });
    $liste = array_slice($liste, 0, EV_WACHE_HOECHSTENS, true);
    $fenster = array();
    foreach ($liste as $k => $x) {
        if (abs($jetzt - (int) $x['zuletzt']) < (int) $fenster_s) {
            $fenster[$k] = $x;
        }
    }
    $gemeldet = isset($m['gemeldet']) ? (int) $m['gemeldet'] : 0;
    $runde = '';
    $melden = false;
    $neu = false;
    if (count($fenster) > 1) {
        $k2 = array_keys($fenster);
        sort($k2, SORT_STRING);
        $runde = implode('|', $k2);
        $neu = ($runde !== (isset($m['runde']) ? (string) $m['runde'] : ''));
        $melden = $neu || abs($jetzt - $gemeldet) >= (int) $fenster_s;
        if ($melden) {
            $gemeldet = $jetzt;
        }
    } else {
        $gemeldet = 0;
    }
    return array(array('schreiber' => $liste, 'runde' => $runde, 'gemeldet' => $gemeldet),
                 array_values($fenster), $melden, $neu);
}

/** Ein Schreiber als Text (Kennung@Absender, ohne Kennung so benannt). $ohne: das Wort
 *  fuer "ohne Kennung" - das Protokoll bleibt deutsch, der Reiter Test reicht die
 *  Sprachdatei herein. */
function ev_wache_name(array $x, $ohne = 'ohne Kennung')
{
    return ((string) $x['von'] !== '' ? $x['von'] : (string) $ohne) . '@' . ((string) $x['ip'] !== '' ? $x['ip'] : '?');
}

/** Die Schreiber einer Runde als Text fuer Protokoll und Meldung. */
function ev_wache_text(array $fenster)
{
    $t = array();
    foreach ($fenster as $x) {
        $t[] = ev_wache_name($x)
             . ' (' . (int) $x['n'] . 'x' . (!empty($x['abgewiesen']) ? ', ' . (int) $x['abgewiesen'] . ' abgewiesen' : '')
             . ', zuletzt ' . date('H:i:s', (int) $x['zuletzt']) . ' ' . (string) $x['art'] . ')';
    }
    return implode(', ', $t);
}

/** Den Merker oeffnen - mit close-on-exec ('e'): ein Kindprozess erbt die flock-Sperre
 *  sonst und haelt sie ueber das Ende des Endpunkts hinaus ("Sperre vererbt sich an
 *  Kinder", gemessen an Bewaesserung und Sprachsteuerung). Rueckgabe Handle oder
 *  false; ein Verzeichnis an der Stelle ist false. */
function ev_wache_oeffnen($f, $modus = 'c+')
{
    if (is_dir($f)) {
        return false;
    }
    return @fopen($f, $modus . 'e');
}

/** LoxBerry-Meldung der Wache (nur mit wache_lb_melden). Bindet loxberry_log.php
 *  selbst ein - keine phplib laedt es von allein (notify_ext() sonst nie erreicht).
 *  Die Pfade werden NACH dem Einbinden neu geholt: loxberry_system.php setzt beim
 *  Einbinden ein eigenes $p in den Bereich des Aufrufers. */
function ev_wache_lb_melden($text)
{
    $ev_wp = ev_paths();
    if ($ev_wp['home'] !== '' && !function_exists('notify_ext')) {
        $ev_wl = $ev_wp['home'] . '/libs/phplib/loxberry_log.php';
        if (is_file($ev_wl)) {
            require_once $ev_wl;
        }
    }
    if (!function_exists('notify_ext')) {
        // Kein Bedienelement ohne Wirkung: gesagt, nicht behauptet.
        ev_log_wenn_neu('wache_lb', 'Schreiber-Wache: die LoxBerry-Meldung ist eingeschaltet, aber '
            . 'notify_ext() ist hier nicht vorhanden - gemeldet wird nur im Protokoll.');
        return false;
    }
    notify_ext(array(
        'PACKAGE'  => ev_paths()['plugin'],
        'NAME'     => 'EVCC',
        'MESSAGE'  => (string) $text,
        'SEVERITY' => 4,
    ));
    return true;
}

/**
 * Einen Befehl bei der Wache anmelden. Faellt offen aus (Kopf).
 * $w: ev_wache_einstellungen(). Rueckgabe: array('merker' => ging, 'anzahl' => Schreiber im Fenster).
 */
function ev_wache_merken($von, $ip, $art, $abgewiesen, array $w)
{
    $aus = array('merker' => true, 'anzahl' => 0);
    $fenster_s = 60 * (int) $w['wache_fenster_min'];
    $jetzt = time();
    $f = ev_wache_datei();
    $erg = null;
    $fh = ev_wache_oeffnen($f);
    if ($fh !== false) {
        $ende = microtime(true) + 2;
        $gesperrt = true;
        while (!@flock($fh, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $ende) {
                $gesperrt = false;
                break;
            }
            usleep(20000);
        }
        if ($gesperrt) {
            $roh = (string) stream_get_contents($fh);
            $m = $roh === '' ? array() : json_decode($roh, true);
            if (!is_array($m)) {
                // Unlesbar: neu beginnen - der Merker beobachtet nur. Eine Zeile,
                // danach ist er wieder lesbar (kein Dauerprotokoll).
                ev_log('Schreiber-Wache: der Merker ' . $f . ' war unlesbar (' . strlen($roh) . ' Byte) und beginnt neu.');
                $m = array();
            }
            list($m2, $fenster, $melden, $neu) = ev_wache_fortschreiben($m, $von, $ip, $art, $abgewiesen, $jetzt, $fenster_s);
            $inhalt = (string) json_encode($m2);
            if ($inhalt !== '' && ftruncate($fh, 0) && rewind($fh)
                    && fwrite($fh, $inhalt) === strlen($inhalt) && fflush($fh)) {
                $erg = array($fenster, $melden, $neu);
            }
            flock($fh, LOCK_UN);
        }
        fclose($fh);
    }
    if ($erg === null) {
        $aus['merker'] = false;
        ev_log_wenn_neu('wache_merker', 'Der Merker der Schreiber-Wache (' . $f . ') laesst sich nicht oeffnen, '
            . 'sperren oder schreiben - die Befehle gehen weiter hinaus, nur das Melden mehrerer Schreiber '
            . 'faellt aus, bis das behoben ist. Pruefen: Platz und Eigentuemer (loxberry).');
        return $aus;
    }
    if (is_file(ev_tmpdir() . '/letzte_wache_merker.txt')) {
        ev_log_wenn_neu('wache_merker', 'Der Merker der Schreiber-Wache ist wieder lesbar.');
    }
    list($fenster, $melden, $neu) = $erg;
    $aus['anzahl'] = count($fenster);
    if ($melden) {
        $text = 'Schreiber-Wache: ' . count($fenster) . ' Schreiber in den letzten '
              . (int) $w['wache_fenster_min'] . ' min - ' . ev_wache_text($fenster)
              . ((int) $w['wache_sperren_ein'] === 1 ? '.' : '. Nichts abgewiesen (Sperren aus).');
        ev_log($text);
        if ($neu && (int) $w['wache_lb_melden'] === 1) {
            ev_wache_lb_melden($text);
        }
    }
    return $aus;
}

/** Die Schreiber fuer den Reiter Test, neueste zuerst.
 *  Rueckgabe array(zustand, eintraege): 'ok' | 'leer' (kein Befehl in 24 h oder seit
 *  dem Start - der Merker liegt auf der Ramdisk) | 'merker' (nicht lesbar). */
function ev_wache_lesen()
{
    $f = ev_wache_datei();
    clearstatcache(true, $f);
    if (!file_exists($f)) {
        return array('leer', array());
    }
    $fh = ev_wache_oeffnen($f, 'r');
    if ($fh === false) {
        return array('merker', array());
    }
    $ende = microtime(true) + 2;
    $ok = true;
    while (!@flock($fh, LOCK_SH | LOCK_NB)) {
        if (microtime(true) >= $ende) {
            $ok = false;
            break;
        }
        usleep(20000);
    }
    $roh = $ok ? (string) stream_get_contents($fh) : '';
    if ($ok) {
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    $m = ($ok && $roh !== '') ? json_decode($roh, true) : ($ok ? array() : null);
    if (!is_array($m)) {
        return array('merker', array());
    }
    $aus = array();
    $liste = (isset($m['schreiber']) && is_array($m['schreiber'])) ? $m['schreiber'] : array();
    foreach ($liste as $x) {
        if (!is_array($x) || !isset($x['zuletzt'], $x['n']) || abs(time() - (int) $x['zuletzt']) > EV_WACHE_AUFBEWAHREN_S) {
            continue;
        }
        $aus[] = array('von' => isset($x['von']) && is_string($x['von']) ? $x['von'] : '',
                       'ip' => isset($x['ip']) && is_string($x['ip']) ? $x['ip'] : '',
                       'erst' => (int) (isset($x['erst']) ? $x['erst'] : 0), 'zuletzt' => (int) $x['zuletzt'],
                       'n' => (int) $x['n'], 'abgewiesen' => (int) (isset($x['abgewiesen']) ? $x['abgewiesen'] : 0),
                       'art' => isset($x['art']) && is_string($x['art']) ? $x['art'] : '');
    }
    usort($aus, function ($a, $b) {
        return $b['zuletzt'] - $a['zuletzt'];
    });
    return array($aus ? 'ok' : 'leer', $aus);
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
    /* Rechte VOR dem Inhalt - jetzt wirklich (C7, seit 0.9.34). Bis 0.9.33
     * stand dieser Satz hier ueber einem file_put_contents mit anschliessendem
     * chmod, also dem Gegenteil (Pruefbericht code, Befund 7). */
    ev_datei_schreiben($datei, $neu, 0600);
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
