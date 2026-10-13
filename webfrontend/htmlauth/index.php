<?php
/**
 * EVCC fuer LoxBerry - Bedienoberflaeche
 *
 * NUR Oberflaeche. Der Datenabruf laeuft in bin/ev_abruf.php, der Endpunkt
 * fuer Loxone in webfrontend/html/index.php. Drei Aufgaben, drei Dateien.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/ev_lib.php';
require_once __DIR__ . '/ev_test.php';

/* JEDE Variable im Hauptteil traegt das Plugin-Kuerzel - niemals nur $p.
 *
 * loxberry_system.php setzt beim Einbinden ein eigenes $p in den Bereich des
 * Aufrufers: array("", "libs", "phplib", "loxberry_system.php"). Bis 0.9.10
 * stand hier $p, und Zeile 16 las danach $p['home'] aus genau diesem Feld -
 * also nichts. Gesucht wurde daraufhin /libs/phplib/loxberry_web.php, das es
 * nirgends gibt: die GESAMTE Oberflaeche brach mit einem fatalen Fehler ab,
 * auf dem Geraet als HTTP 500 mit leerem Rumpf. Gemessen unter PHP 7.4 und
 * 8.4, beide Male 0 Zeichen Ausgabe.
 *
 * REGELN_2 nennt EVCC dafuer beim Namen ("genau drei hiessen $p: EVCC,
 * SignalBot und WaermepumpeCloud"); die beiden anderen waren berichtigt.
 *
 * Deshalb: eigener Name, Wert VOR dem Einbinden retten, und danach neu holen. */
$ev_p = ev_paths();
$ev_home = (string) $ev_p['home'];
if ($ev_home !== '' && file_exists($ev_home . '/libs/phplib/loxberry_system.php')) {
    require_once $ev_home . '/libs/phplib/loxberry_system.php';
    if (file_exists($ev_home . '/libs/phplib/loxberry_web.php')) {
        require_once $ev_home . '/libs/phplib/loxberry_web.php';
    }
    $ev_p = ev_paths();   // nach dem Einbinden neu holen
}

/* Wie stand die Konfiguration da, BEVOR ev_config() sie heilen konnte? (O6)
 * Der Reiter Test zeigt das in der Zeile "Ist die Konfiguration heil?"; nach
 * dem ersten ev_config() waere die Frage schon beantwortet und die Antwort
 * immer "ok". */
$GLOBALS['ev_konfig_lage_vorher'] = ev_konfig_lage();

/* Wer einen Reiter hinzufuegt, muss DREI Stellen mitziehen: die Reiterleiste,
   den Bereich (sm-seite mit gleicher id) und diese Positivliste. Fehlt der
   Name hier, springt die Seite nach jedem Absenden zurueck auf Einstellungen.

   Die Leiste bleibt bewusst AUSGESCHRIEBEN und wird nicht aus ev_reiter()
   erzeugt: eine PHP-Schleife macht hausstandard_pruefen.py blind, und genau
   dieser Fehler steht zweimal in REGELN_1. Die Aufloesung ist nicht
   "Schleife oder Hand", sondern ausschreiben UND die Uebereinstimmung im
   Reiter Test nachpruefen lassen - das tut ev_reiter_abgleich(). */
$ev_muster = '/^tab-(settings|mqtt|loxone|test|log)$/';
$ev_tab = preg_match($ev_muster, (string) (isset($_POST['activetab']) ? $_POST['activetab'] : ''))
    ? (string) $_POST['activetab'] : 'tab-settings';
if (isset($_GET['form']) && preg_match($ev_muster, 'tab-' . $_GET['form'])) {
    $ev_tab = 'tab-' . $_GET['form'];
}

$ev_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';
$ev_meldungen = array();
$ev_fehler = array();
/* X-2 (Regeln/04): die Eingaben eines abgewiesenen Formulars und die Namen
 * der beanstandeten Felder - sie reisen mit der Einmalmeldung. */
$ev_eingaben = array();
$ev_bean = array();

/* ---------------------------------------------------------------- *
 * Der Wachposten - EIN Posten, vor allen Handlern.
 * Abgewiesen heisst gemeldet, und es wird NICHTS ausgefuehrt: $_POST
 * wird geleert, nur der aktive Reiter bleibt stehen, damit der Bediener
 * nach der Abweisung dort steht, wo er war.
 * ---------------------------------------------------------------- */
$ev_wache = ev_wachposten();
if ($ev_wache !== '') {
    $ev_reiter_merk = isset($_POST['activetab']) && is_string($_POST['activetab'])
        ? (string) $_POST['activetab'] : null;
    $_POST = array();
    if ($ev_reiter_merk !== null) {
        $_POST['activetab'] = $ev_reiter_merk;
    }
    $ev_fehler[] = $ev_wache;
}

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 * ==================================================================
 *
 * Stand der Kopf davor, war er beim Aufruf von header() schon
 * geschrieben - "Cannot modify header information", und der Knopf
 * "Einstellungen sichern" lieferte eine Seite mit angehaengtem JSON
 * statt einer Datei.
 *
 * Am PHP-CLI ist das unsichtbar: header() ist dort wirkungslos und
 * headers_sent() immer falsch. Und wer OHNE gueltiges Formularmerkmal
 * misst, wird vom Wachposten abgewiesen, bevor der Handler anlaeuft.
 * Beides hat den Fehler lange verdeckt.
 *
 * Reihenfolge: Bibliothek, Konfiguration, Wachposten, Reiterwahl,
 * ALLE Handler samt Downloads, dann erst lbheader(), dann HTML.
 * ================================================================== */
/* ================= Vorlage herunterladen =================
   Vor jeder Ausgabe, sonst stehen HTML-Reste in der XML-Datei. */
if ($ev_post && isset($_POST['vorlage'])) {
    list($ev_vname, $ev_vinhalt) = ($_POST['vorlage'] === 'aus') ? ev_vorlage_aus() : ev_vorlage_ein();
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $ev_vname . '"');
    echo $ev_vinhalt;
    exit;
}

/* ================= Protokoll leeren =================
   Nur mit Haken, und die Meldung haengt am Rueckgabewert (O11, seit 0.9.34).
   Bis 0.9.33 leerte der Knopf ohne Rueckfrage und sagte nichts - auch nicht,
   wenn das Schreiben scheiterte (Pruefbericht oberflaeche, Befund 17). */
if ($ev_post && isset($_POST['clearlog'])) {
    if (empty($_POST['clearlog_ok'])) {
        $ev_fehler[] = ev_t('LOG.LEEREN_HAKEN');
    } else {
        $ev_lf = ev_paths()['log'];
        @mkdir(dirname($ev_lf), 0775, true);
        $ev_lz = '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Oberflaeche)\n";
        if (@file_put_contents($ev_lf, $ev_lz) === strlen($ev_lz)) {
            $ev_meldungen[] = ev_t('LOG.GELEERT');
        } else {
            $ev_fehler[] = sprintf(ev_t('LOG.LEEREN_FEHL'), ev_e($ev_lf));
        }
    }
    $ev_tab = 'tab-log';
}

/* ================= Test-Aktionen ================= */
if ($ev_post && isset($_POST['testaktion'])) {
    list($ev_ok, $ev_text) = ev_test_aktion((string) $_POST['testaktion']);
    if ($ev_ok) { $ev_meldungen[] = $ev_text; } else { $ev_fehler[] = $ev_text; }
    $ev_tab = 'tab-test';
}

/* ================= Einstellungen speichern =================
   Beanstandungen werden GESAMMELT, nicht ueberschrieben: eine einzelne
   Zuweisung verschluckt die vorherigen, und der Benutzer korrigiert dann
   einen Fehler nach dem anderen. */
if ($ev_post && isset($_POST['speichern'])) {
    $ev_cfg = ev_config();

    $ev_url = trim((string) (isset($_POST['url']) ? $_POST['url'] : ''));
    if ($ev_url === '' || !preg_match('#^https?://[A-Za-z0-9\.\-]+(:[0-9]{1,5})?(/\S*)?$#', $ev_url)) {
        $ev_fehler[] = ev_t('EINST.FEHLER_URL');
        $ev_bean[] = 'url';
    } else {
        $ev_cfg['url'] = rtrim($ev_url, '/');
    }

    /* Das Passwort wird NICHT auf einen Zeichenvorrat eingeschraenkt - es
     * darf Sonderzeichen enthalten. Aber Steuerzeichen kommen heraus.
     *
     * Es geht als 'Authorization: Bearer ...' in eine HTTP-Kopfzeile, und
     * der Ersatzweg ohne php-curl setzt die Kopfzeilen mit "\r\n"
     * zusammen. Gemessen an 0.9.26: ein Passwort mit CRLF erzeugte dort
     * eine VIERTE Kopfzeile. Das ist die Hausform - nichts hart filtern,
     * aber Steuerzeichen und Leerraum an den Raendern entfernen.
     *
     * Ein leeres Feld loescht nichts: sonst waere das Passwort nach jedem
     * Speichern weg, weil es aus Sicherheitsgruenden nicht angezeigt wird. */
    /* Das Passwort: Steuerzeichen und Leerzeichen am Rand werden ABGEWIESEN,
     * mit dem Satz, der zum Fehler passt (O2, seit 0.9.34). Bis 0.9.33 hiess
     * es auch bei " geheim" (Leerzeichen am Rand), es enthalte Steuerzeichen
     * (Pruefbericht oberflaeche, Befund 3). Am Rand ginge ein Leerzeichen in
     * der Kopfzeile 'Authorization: Bearer ...' verloren. */
    if (isset($_POST['passwort']) && is_string($_POST['passwort']) && (string) $_POST['passwort'] !== '') {
        $ev_pw = (string) $_POST['passwort'];
        if (preg_match('/[\x00-\x1F\x7F]/', $ev_pw)) {
            $ev_fehler[] = ev_t('EINST.FEHLER_PASSWORT_ZEICHEN');
            $ev_bean[] = 'passwort';
        } elseif ($ev_pw !== trim($ev_pw)) {
            $ev_fehler[] = ev_t('EINST.FEHLER_PASSWORT_RAND');
            $ev_bean[] = 'passwort';
        } elseif (!ev_wert_pruefen('passwort', $ev_pw)) {
            $ev_fehler[] = ev_t('EINST.FEHLER_PASSWORT_LANG');
            $ev_bean[] = 'passwort';
        } else {
            $ev_cfg['passwort'] = $ev_pw;
        }
    }
    if (!empty($_POST['passwort_loeschen'])) { $ev_cfg['passwort'] = ''; }

    /* Takt, Ladepunkte, Fahrzeuge: ABGEWIESEN statt gerundet (O2, seit
     * 0.9.34). Bis 0.9.33 wurde aus 15.7 still 15, aus 20abc 20 und aus 1.9
     * ein Ladepunkt - gemeldet als "Einstellungen gespeichert" (Pruefbericht
     * oberflaeche, Befund 2). Dieselbe Regel wie beim Zurueckspielen. */
    $ev_lp_alt = (int) $ev_cfg['ladepunkte'];
    $ev_fz_alt = (int) $ev_cfg['fahrzeuge'];
    foreach (array('takt' => array('EINST.L_TAKT', 5, 60),
                   'ladepunkte' => array('EINST.L_LADEPUNKTE', 0, EV_LADEPUNKTE),
                   'fahrzeuge' => array('EINST.L_FAHRZEUGE', 0, EV_FAHRZEUGE)) as $ev_k => $ev_r) {
        $ev_roh = (isset($_POST[$ev_k]) && is_string($_POST[$ev_k])) ? (string) $_POST[$ev_k] : '';
        if (!ev_wert_pruefen($ev_k, $ev_roh)) {
            $ev_fehler[] = sprintf(ev_t('EINST.FEHLER_GANZZAHL'), ev_t($ev_r[0]), $ev_r[1], $ev_r[2]);
            $ev_bean[] = $ev_k;
        } else {
            $ev_cfg[$ev_k] = (int) $ev_roh;
        }
    }

    /* mqtt_ein und mqtt_topic werden hier NICHT mehr angefasst: sie
     * wohnen im Reiter MQTT und haben dort ein eigenes Formular. Die
     * Konfiguration kommt aus ev_config(), die Werte ueberleben also
     * unveraendert. */

    $ev_cfg['tarife_ein'] = isset($_POST['tarife_ein']) ? 1 : 0;
    $ev_cfg['steuerung_ein'] = isset($_POST['steuerung_ein']) ? 1 : 0;
    $ev_cfg['update_ein'] = isset($_POST['update_ein']) ? 1 : 0;

    /* Schreiber-Wache (Energie-1 C1, Entscheidung Nr. 25). Abgewiesen statt
     * zurechtgebogen (Nr. 16/19): nichts wird gespeichert, das Feld ist markiert,
     * die Eingabe kommt zurueck (X-2). Still bleibt nur Leerraum am Rand.
     * Dieselbe Regel wie beim Zurueckspielen (ev_wert_pruefen, ev_wache_kreuz). */
    $ev_cfg['wache_ein'] = isset($_POST['wache_ein']) ? 1 : 0;
    $ev_cfg['wache_lb_melden'] = isset($_POST['wache_lb_melden']) ? 1 : 0;
    $ev_cfg['wache_sperren_ein'] = isset($_POST['wache_sperren_ein']) ? 1 : 0;
    $ev_wfr = (isset($_POST['wache_fenster_min']) && is_string($_POST['wache_fenster_min']))
        ? trim((string) $_POST['wache_fenster_min']) : '';
    if (!ev_wert_pruefen('wache_fenster_min', $ev_wfr)) {
        $ev_fehler[] = sprintf(ev_t('EINST.FEHLER_GANZZAHL'), ev_t('EINST.L_WACHE_FENSTER'), 1, 120);
        $ev_bean[] = 'wache_fenster_min';
    } else {
        $ev_cfg['wache_fenster_min'] = (int) $ev_wfr;
    }
    $ev_wer = (isset($_POST['wache_erlaubt']) && is_string($_POST['wache_erlaubt']))
        ? trim((string) $_POST['wache_erlaubt']) : '';
    list(, $ev_wef) = ev_wache_liste($ev_wer);
    if (strlen($ev_wer) > 512) {
        $ev_fehler[] = ev_t('EINST.FEHLER_WACHE_LANG');
        $ev_bean[] = 'wache_erlaubt';
    } elseif ($ev_wef || !ev_wert_pruefen('wache_erlaubt', $ev_wer)) {
        $ev_fehler[] = sprintf(ev_t('EINST.FEHLER_WACHE_LISTE'),
            ev_e(implode(', ', array_slice($ev_wef ? $ev_wef : array('?'), 0, 4))));
        $ev_bean[] = 'wache_erlaubt';
    } elseif (ev_wache_kreuz(array('wache_sperren_ein' => $ev_cfg['wache_sperren_ein'], 'wache_erlaubt' => $ev_wer))) {
        $ev_fehler[] = ev_t('EINST.FEHLER_WACHE_LEER');
        $ev_bean[] = 'wache_sperren_ein';
        $ev_bean[] = 'wache_erlaubt';
    } else {
        $ev_cfg['wache_erlaubt'] = $ev_wer;
    }

    /* Nr. 36 b (Stufe 2): die Ansage. Jede Beanstandung verhindert das Speichern (Nr. 16); kein
     * Sprechtoken steht in einer Meldung, ein leeres Tokenfeld heisst "behalten". */
    $ev_tmangel = array();
    $ev_tbean = array();
    $ev_tneu = ansage_formular_lesen($_POST, ev_tts($ev_cfg), $ev_tmangel, $ev_tbean,
                                     array('modi' => ev_ansage_modi()), ev_ansage_k());
    foreach ($ev_tmangel as $ev_tm) { $ev_fehler[] = ev_e($ev_tm['text']); }
    foreach ($ev_tbean as $ev_tb) { $ev_bean[] = $ev_tb; }
    if (!$ev_tmangel) { $ev_cfg['tts'] = $ev_tneu; }
    foreach (ev_ansage_anlaesse() as $ev_ak) { $ev_cfg[$ev_ak] = isset($_POST[$ev_ak]) ? 1 : 0; }

    /* Abgewiesen: die Eingaben reisen zurueck ins Formular (X-2). */
    if ($ev_fehler) {
        $ev_eingaben = ev_eingaben_sammeln('speichern', $ev_bean);
    }
    if (!$ev_fehler) {
        if (ev_config_write($ev_cfg)) {
            $ev_meldungen[] = ev_t('EINST.GESPEICHERT');
            ev_log('Einstellungen gespeichert');
            /* Weniger Ladepunkte oder Fahrzeuge: deren retained Themen raeumen
             * (M3, seit 0.9.34) - sonst stuenden sie fuer immer im Broker. */
            foreach (ev_mqtt_abraeumen_nach(array('mqtt_topic' => $ev_cfg['mqtt_topic'],
                         'mqtt_ein' => $ev_cfg['mqtt_ein'], 'ladepunkte' => $ev_lp_alt,
                         'fahrzeuge' => $ev_fz_alt), $ev_cfg) as $ev_a) {
                if ($ev_a[1] === '') { continue; }
                if ($ev_a[0] === 0) { $ev_meldungen[] = $ev_a[1]; } else { $ev_fehler[] = $ev_a[1]; }
            }
        } else {
            $ev_fehler[] = sprintf(ev_t('EINST.FEHLER_SPEICHERN'), ev_e(ev_paths()['config']));
            // Nicht gespeichert: auch dann bleiben die Eingaben stehen (X-2).
            $ev_eingaben = ev_eingaben_sammeln('speichern', array());
        }
    }
    $ev_tab = 'tab-settings';
}

/* ---------------- MQTT (eigener Reiter, eigenes Formular) ----------------
 *
 * Eigenes Formular UND eigener Handler gehoeren zusammen. Loesten beide
 * Formulare denselben Handler aus, setzte dieser die Haken des jeweils
 * nicht abgeschickten Formulars per isset() auf 0 - der Benutzer verloere
 * Werte, die er nie gesehen hat. */
if ($ev_post && isset($_POST['save_mqtt'])) {
    $ev_mcfg = ev_config();
    $ev_m_alt_ein = !empty($ev_mcfg['mqtt_ein']);
    $ev_m_alt_topic = (string) $ev_mcfg['mqtt_topic'];
    $ev_mcfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    /* ABGEWIESEN statt verbogen (O2, seit 0.9.34): bis 0.9.33 wurden
     * Anfuehrungszeichen still entfernt (evcc"2lox wurde evcc2lox) und
     * Schraegstriche am Rand abgeschnitten (Pruefbericht oberflaeche,
     * Befund 2). Dieselbe Regel wie beim Zurueckspielen (ev_wert_pruefen). */
    $ev_mtopic = (isset($_POST['mqtt_topic']) && is_string($_POST['mqtt_topic'])) ? (string) $_POST['mqtt_topic'] : '';
    if (!ev_wert_pruefen('mqtt_topic', $ev_mtopic)) {
        $ev_fehler[] = ev_t('EINST.FEHLER_TOPIC');
        $ev_bean[] = 'mqtt_topic';
    } else {
        $ev_mcfg['mqtt_topic'] = $ev_mtopic;
    }
    $ev_mvoll = (isset($_POST['mqtt_vollsend_min']) && is_string($_POST['mqtt_vollsend_min'])) ? (string) $_POST['mqtt_vollsend_min'] : '';
    if (!ev_wert_pruefen('mqtt_vollsend_min', $ev_mvoll)) {
        $ev_fehler[] = ev_t('EINST.FEHLER_VOLLSEND');
        $ev_bean[] = 'mqtt_vollsend_min';
    } else {
        $ev_mcfg['mqtt_vollsend_min'] = (int) $ev_mvoll;
    }
    /* Abgewiesen: die Eingaben reisen zurueck ins Formular (X-2). */
    if ($ev_fehler) {
        $ev_eingaben = ev_eingaben_sammeln('save_mqtt', $ev_bean);
    }
    if (!$ev_fehler) {
        if (ev_config_write($ev_mcfg)) {
            $ev_meldungen[] = ev_t('EINST.GESPEICHERT');
            /* Abraeumen (M3, seit 0.9.34): beim Praefixwechsel das ALTE
             * Praefix, beim Abschalten das eingestellte - mit Ruecklesen am
             * Broker und einem Satz hier. ERST schreiben, dann raeumen: ein
             * Abruf, der gerade laeuft, nimmt ab seinem naechsten Durchlauf
             * die neue Einstellung; was er noch unter dem alten Praefix
             * sendet, fangen die Runden mit Ruecklesen ab. Bis 0.9.33 blieben
             * die Zustaende unter dem alten Praefix fuer immer stehen
             * (Pruefbericht mqtt, B3). */
            foreach (ev_mqtt_abraeumen_nach(array('mqtt_topic' => $ev_m_alt_topic,
                         'mqtt_ein' => $ev_m_alt_ein ? 1 : 0, 'ladepunkte' => $ev_mcfg['ladepunkte'],
                         'fahrzeuge' => $ev_mcfg['fahrzeuge']), $ev_mcfg) as $ev_a) {
                if ($ev_a[1] === '') { continue; }
                if ($ev_a[0] === 0) { $ev_meldungen[] = $ev_a[1]; } else { $ev_fehler[] = $ev_a[1]; }
            }
        } else {
            // Bis 0.9.26 fehlte dieser Zweig: schlug das Schreiben fehl
            // (Rechte, volle Karte), kam weder Erfolgs- noch Fehlermeldung
            // zurueck, die Seite lud neu und zeigte den alten Wert. Der
            // Bediener haelt das fuer einen Bedienfehler und versucht es
            // wieder. Die beiden Schwesterhandler haben den Zweig laengst.
            $ev_fehler[] = sprintf(ev_t('EINST.FEHLER_SPEICHERN'), ev_e(ev_paths()['config']));
            $ev_eingaben = ev_eingaben_sammeln('save_mqtt', array());
        }
    }
    $ev_tab = 'tab-mqtt';
}

$ev_cfg = ev_config();
$ev_p = ev_paths();
$ev_plugin = $ev_p['plugin'];


/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das. */
if ($ev_post && isset($_POST['ev_sichern'])) {
    /* Ein lesbarer Kopf voran - Hausstandard. Er sagt dem, der die Datei ein
     * Jahr spaeter in der Hand hat, woher sie stammt und was in ihr steckt.
     * Die Schluessel beginnen mit einem Unterstrich; ev_sicherung_lesen()
     * uebergeht genau diese und beanstandet sie nicht (bis 0.9.26 haette es
     * die eigene Datei damit abgelehnt). */
    /* Nur die bekannten Schluessel (O3, seit 0.9.34): ev_sicherung_bauen(). */
    $ev_sich = ev_sicherung_bauen();
    $ev_js = json_encode($ev_sich,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($ev_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="evcc_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $ev_js;
        exit;
    }
    $ev_fehler[] = ev_t('EINST.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei des
 * Servers unterschieben. Dann die Groessengrenze - eine Sicherung dieses
 * Plugins ist wenige Kilobyte gross; alles darueber wird gar nicht gelesen. */
if ($ev_post && isset($_POST['ev_zurueck'])) {
    if (!isset($_FILES['ev_sicherung']) || !is_array($_FILES['ev_sicherung'])
        || !isset($_FILES['ev_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['ev_sicherung']['tmp_name'])) {
        $ev_fehler[] = ev_t('EINST.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['ev_sicherung']['size'] > 65536) {
        $ev_fehler[] = ev_t('EINST.SICH_ZU_GROSS');
    } else {
        list($ev_neu, $ev_mangel, $ev_n, $ev_hinw) = ev_sicherung_lesen(
            (string) @file_get_contents($_FILES['ev_sicherung']['tmp_name']));
        if ($ev_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. */
            $ev_fehler[] = ev_t('EINST.SICH_ABGELEHNT') . ' '
                            . implode(' ', $ev_mangel);
        } elseif (($ev_zalt = ev_config()) && ev_config_write($ev_neu)) {
            $ev_meldungen[] = sprintf(ev_t('EINST.SICH_UEBERNOMMEN'), $ev_n);
            // Was dabei nicht uebernommen, sondern behalten wurde (O3).
            foreach ($ev_hinw as $ev_h) { $ev_meldungen[] = $ev_h; }
            /* Eine Sicherung kann das Praefix, den MQTT-Schalter und die Zahl
             * der Ladepunkte aendern - dann dasselbe Abraeumen wie beim
             * Speichern (M3). */
            foreach (ev_mqtt_abraeumen_nach($ev_zalt, $ev_neu) as $ev_a) {
                if ($ev_a[1] === '') { continue; }
                if ($ev_a[0] === 0) { $ev_meldungen[] = $ev_a[1]; } else { $ev_fehler[] = $ev_a[1]; }
            }
        } else {
            $ev_fehler[] = ev_t('EINST.SICH_SCHREIBFEHLER');
        }
    }
}

/* ================= JEDER POST ENDET MIT EINER UMLEITUNG (O1, seit 0.9.34) =================
 *
 * Regeln/04: header('Location: index.php?form=...', true, 303) und exit; das
 * Ergebnis reist als Einmalmeldung (data/plugins/<ordner>/einmalmeldung.json,
 * 0600, 120 s gueltig, nur beim GET gelesen und dabei geloescht). Bis 0.9.33
 * lieferte jeder POST die Seite direkt: F5 nach "Token neu erzeugen" wuerfelte
 * neu, F5 nach "EVCC neu starten" startete erneut, F5 nach "EVCC jetzt
 * aktualisieren" lief ohne die Rueckfrage noch einmal (Pruefbericht
 * oberflaeche, Befund 1). Downloads (Vorlage, Sicherung) sind oben schon mit
 * exit fertig. Auch die Abweisung durch den Wachposten geht diesen Weg. */
if ($ev_post) {
    if (!ev_meldung_ablegen(array('meldungen' => $ev_meldungen, 'fehler' => $ev_fehler,
                                  'eingaben' => $ev_eingaben))) {
        ev_log('Die Einmalmeldung liess sich nicht schreiben - das Ergebnis des letzten '
            . 'Knopfdrucks ist nach der Umleitung nicht zu sehen.');
    }
    header('Location: index.php?form=' . substr($ev_tab, 4), true, 303);
    exit;
}
$ev_einmal = ev_meldung_abholen();
if ($ev_einmal !== null) {
    $ev_meldungen = array_merge($ev_meldungen, $ev_einmal['meldungen']);
    $ev_fehler = array_merge($ev_fehler, $ev_einmal['fehler']);
    // X-2: ev_eingabe() und ev_markierung() lesen sie von hier.
    $ev_eingaben = $ev_einmal['eingaben'];
}

if (class_exists('LBWeb', false)) {
    LBWeb::lbheader(ev_t('ALLG.TITEL'), 'https://docs.evcc.io/', 'help.html');
}

?>
<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
/* Bedienelemente werden von jQuery Mobile umgebaut und bekommen einen eigenen
   Behaelter. Begrenzt man das Feld selbst, bleibt der Behaelter breit - man
   sieht ein schmales Feld in einem breiten weissen Kasten. Deshalb wird
   ausschliesslich der Behaelter begrenzt. */
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
/* Nr. 36 b: Ein Auswahlfeld muss man als Auswahlfeld erkennen. Mit data-role="none" nimmt jQuery Mobile
   den Pfeil weg (Hausform, wie Pumpenwacht). Die Raute im SVG steht als %23. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES <button> mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. Die
   Hover-Farben unten sind kein Feinschliff, sondern Pflicht. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
/* Statuskacheln - bewusst ein anderer Name als sm-knopfreihe. */
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
/* Reiterinhalte: nur der aktive ist sichtbar. Ohne diese zwei Zeilen stehen
   alle fuenf Reiter untereinander, sobald das Skript nicht laeuft. */
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-fehler { border: 1px solid #ef9a9a; background: #ffebee; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
/* Ein beanstandetes Feld nach der Umleitung (X-2, Regeln/04). */
.sm-wrap input.sm-beanstandet { border: 2px solid #c62828 !important; background: #fff5f5 !important; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 200px; }

/* Nachgetragen im CSS-Luecken-Durchgang 13.08.2026, weil sie damals
   benutzt, aber nicht definiert waren. Gemessen am 04.09.2026: benutzt
   werden sie inzwischen nirgends mehr - die Warnkaesten dieser Oberflaeche
   heissen durchgehend sm-warnung. Zwei Regeln, die niemand anwendet, mit
   einer Begruendung, die das Gegenteil behauptet, kosten bei der naechsten
   Durchsicht Zeit; deshalb sind sie fort. */
/* Ergaenzung (Welle Bild, Entscheidung 45): Bild der Bausteine aus dem gemeinsamen Musterprojekt. */
.sm-bild { margin: 12px 0; }
.sm-bild img { max-width: 100%; height: auto; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
.sm-bild figcaption { font-size: .9em; color: #555; margin-top: 4px; }
/* Welle Bild 12 VOLL (0.9.43): Abschnitte, Schritte und Hinweise je Baustein-Liste. */
.sm-wrap h4.sm-h4 { color: #4f7d17; font-size: 0.98em; font-weight: 700; margin: 22px 0 4px;
    padding-top: 10px; border-top: 1px solid #e0e0e0; }
.sm-schritte { margin: 6px 0 0 18px; padding: 0; }
.sm-schritte li { margin: 3px 0; }
</style>

<div class="sm-wrap">

<?php if ($ev_meldungen) { ?>
<div class="sm-hinweis"><ul style="margin:0;padding-left:18px;">
<?php foreach ($ev_meldungen as $ev_m) { ?><li><?= $ev_m ?></li><?php } ?>
</ul></div>
<?php } ?>
<?php if ($ev_fehler) { ?>
<div class="sm-fehler"><ul style="margin:0;padding-left:18px;">
<?php foreach ($ev_fehler as $ev_f) { ?><li><?= $ev_f ?></li><?php } ?>
</ul></div>
<?php } ?>

<?php
/* Kopf (Entscheidung Nr. 43, seit 0.9.40): Statusuebersicht ueber den
 * Reitern, immer sichtbar. Hierher gewandert aus dem Reiter Einstellungen,
 * wo bis 0.9.39 drei Kacheln standen.
 * Nur der Zwischenspeicher des Abrufdienstes, keine eigene Anfrage an EVCC
 * (O4, seit 0.9.34). Bis 0.9.33 fragte JEDER Seitenaufruf EVCC mit bis zu
 * 8 s Zeitgrenze - bei haengendem EVCC wartete man auf jedem Reiter
 * (Pruefbericht oberflaeche, Befund 7). */
$ev_da = ev_dienst_vorhanden();
$ev_laeuft = ev_dienst_laeuft();
$ev_stand = ev_state_gespeichert();
$ev_tokneu = ev_token_neu();
$ev_ein = ev_einrichtung($ev_stand);
$ev_abruf = !empty($ev_stand['stand']) ? (int) $ev_stand['stand'] : 0;
$ev_lp_da = isset($ev_stand['roh']['loadpoints']) && is_array($ev_stand['roh']['loadpoints']);
?>
<table class="sm-tbl" style="max-width:620px">
<tr><th><?= ev_e(ev_t('KACHEL.EIGENSCHAFT')) ?></th><th><?= ev_e(ev_t('KACHEL.WERT')) ?></th></tr>
<tr><td><?= ev_e(ev_t('KACHEL.EVCC')) ?></td>
    <td class="<?= $ev_laeuft ? 'sm-an' : 'sm-aus' ?>"><?= ev_e($ev_laeuft ? ev_t('ALLG.LAEUFT')
        : ($ev_da ? ev_t('ALLG.GESTOPPT') : ev_t('KACHEL.NICHT_INSTALLIERT'))) ?></td></tr>
<tr><td><?= ev_e(ev_t('KACHEL.VERBINDUNG')) ?></td>
    <?= (empty($ev_stand['stand']) && empty($ev_stand['fehler']))
        ? '<td>' . ev_e(ev_t('ALLG.UNBEKANNT')) . '</td>'
        : '<td class="' . (!empty($ev_stand['ok']) ? 'sm-an' : 'sm-aus') . '">'
          . ev_e(!empty($ev_stand['ok']) ? ev_t('ALLG.OK') : ev_t('ALLG.FEHLER')) . '</td>' ?></tr>
<tr><td><?= ev_e(ev_t('KACHEL.ABRUF')) ?></td>
    <td><?= $ev_abruf > 0
        ? ev_e(date('d.m.Y H:i:s', $ev_abruf))
          . (time() - $ev_abruf < 3600 ? ' (' . sprintf(ev_e(ev_t('KACHEL.VOR_S')), max(0, time() - $ev_abruf)) . ')' : '')
        : ev_e(ev_t('ALLG.UNBEKANNT')) ?></td></tr>
<tr><td><?= ev_e(ev_t('KACHEL.LADEPUNKTE')) ?></td>
    <td><?= $ev_lp_da ? (int) $ev_ein['ladepunkte'] : '&ndash;' ?></td></tr>
<tr><td><?= ev_e(ev_t('KACHEL.VERSION')) ?></td>
    <td><?= ev_e($ev_da ? ev_dienst_version() : '-') ?></td></tr>
</table>

<!-- Reiterleiste: echte Links, JavaScript faengt den Klick ab. Warum beides:
     Der Link traegt die Adresse - jeder Reiter ist damit verlinkbar, und die
     Zurueck-Taste tut das Erwartete.

     WELCHER REITER OFFEN IST, ENTSCHEIDET DER SERVER. sm-active steht schon
     im ausgelieferten HTML, an der Leiste UND am Bereich; das Skript schaltet
     danach nur noch ohne Neuladen um. Bis 0.9.10 war das nicht so: gemessen
     kam sm-active im ausgelieferten HTML viermal vor, alle vier Male in CSS
     und JavaScript, kein einziges Mal im Markup. Zusammen mit
     .sm-seite{display:none} war die Seite ohne JavaScript LEER - und der
     Kommentar an dieser Stelle behauptete das Gegenteil. -->
<div class="sm-tabs">
	<a class="sm-tab<?= $ev_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings" href="index.php?form=settings"><?= ev_e(ev_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $ev_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"     href="index.php?form=mqtt"><?= ev_e(ev_t('REITER.MQTT')) ?></a>
	<a class="sm-tab<?= $ev_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"   href="index.php?form=loxone"><?= ev_e(ev_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $ev_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"     href="index.php?form=test"><?= ev_e(ev_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $ev_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"      href="index.php?form=log"><?= ev_e(ev_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $ev_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">

<div class="sm-hinweis"><?= ev_t('EINST.WAS_IST_DAS') ?></div>

<?php if ($ev_tokneu !== null) { ?>
<div class="sm-warnung"><?= sprintf(ev_t('EINST.TOKEN_NEU'), ev_e(date('d.m.Y H:i', $ev_tokneu[0])), ev_e($ev_tokneu[1])) ?></div>
<?php } ?>

<?php if (!$ev_da) { ?>
<div class="sm-warnung"><?= ev_t('EINST.NICHT_INSTALLIERT') ?></div>
<?php } ?>

<?php
/* Die wichtigste Meldung dieser Seite, wenn sie zutrifft.
   EVCC kann laufen, antworten - und trotzdem nichts liefern, weil es mit
   einem Startfehler abgebrochen hat. Bis 0.9.12 stand hier nur die Kachel
   "Verbindung: in Ordnung", und die war sogar richtig. Nur eben nicht die
   ganze Wahrheit. */
/* $ev_ein kommt seit 0.9.40 aus dem Kopf ueber der Reiterleiste. */
if ($ev_ein['fatal'] !== '') { ?>
<div class="sm-fehler"><?= sprintf(ev_t('EINST.EVCC_FATAL'), ev_e($ev_ein['fatal']), ev_e(ev_evcc_link())) ?></div>
<?php } elseif ($ev_ein['einrichtung'] === 0) { ?>
<div class="sm-fehler"><?= sprintf(ev_t('EINST.SETUP_NOETIG'), ev_e(ev_evcc_link())) ?></div>
<?php } elseif ($ev_ein['einrichtung'] === 1 && $ev_ein['ladepunkte'] === 0) { ?>
<div class="sm-warnung"><?= sprintf(ev_t('EINST.KEIN_LADEPUNKT'), ev_e(ev_evcc_link())) ?></div>
<?php } ?>
<?php if ($ev_ein['neuer'] !== '') { ?>
<div class="sm-hinweis"><?= sprintf(ev_t('EINST.EVCC_NEU' . ev_update_weg()), ev_e($ev_ein['version']), ev_e($ev_ein['neuer'])) ?></div>
<?php } ?>

<?php
/* ---- Statistik (Vorschlag E15) ----
   Kommt aus dem Zwischenspeicher, den der Abrufdienst fuellt - diese Seite
   stellt dafuer keine eigene Anfrage. Steht dort nichts, wird auch nichts
   behauptet: dann fehlt der Abschnitt, statt Nullen zu zeigen. */
$ev_stat = ev_statistik();
if ($ev_stat) { ?>
<h2><?= ev_e(ev_t('STAT.H')) ?></h2>
<p class="sm-hilfe"><?= ev_t('STAT.TEXT') ?></p>
<table class="sm-tbl">
<tr><th><?= ev_e(ev_t('STAT.T_ZEITRAUM')) ?></th><th><?= ev_e(ev_t('STAT.T_ENERGIE')) ?></th>
    <th><?= ev_e(ev_t('STAT.T_SOLAR')) ?></th><th><?= ev_e(ev_t('STAT.T_PREIS')) ?></th>
    <th><?= ev_e(ev_t('STAT.T_CO2')) ?></th></tr>
<?php
$ev_zeitraum = array('30d' => 'STAT.Z_30', '365d' => 'STAT.Z_365', 'total' => 'STAT.Z_GESAMT',
                     'thisYear' => 'STAT.Z_JAHR');
foreach ($ev_zeitraum as $ev_k => $ev_s) {
    if (!isset($ev_stat[$ev_k]) || !is_array($ev_stat[$ev_k])) { continue; }
    $ev_r = $ev_stat[$ev_k];
    $ev_z = function ($n, $k, $nach = 1) use ($ev_r) {
        return isset($ev_r[$k]) && is_numeric($ev_r[$k])
            ? number_format((float) $ev_r[$k], $nach, ',', '.') : '-';
    };
?>
<tr><td><?= ev_e(ev_t($ev_s)) ?></td>
    <td><?= $ev_z('e', 'chargedKWh', 1) ?> kWh</td>
    <td><?= $ev_z('s', 'solarPercentage', 1) ?> %</td>
    <td><?= $ev_z('p', 'avgPrice', 3) ?></td>
    <td><?= $ev_z('c', 'avgCo2', 0) ?> g/kWh</td></tr>
<?php } ?>
</table>
<?php } ?>

<form action="index.php" method="post" autocomplete="off">
  <?php echo ev_fmt(); ?>
<input data-role="none" type="hidden" name="speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<?php if (ev_eingaben_aktiv('speichern')) { ?>
<div class="sm-warnung"><?= ev_e(ev_t('EINST.EINGABEN_ZURUECK')) ?></div>
<?php } ?>

<h2><?= ev_e(ev_t('EINST.H_VERBINDUNG')) ?></h2>
<div class="sm-feld">
  <label for="url"><?= ev_e(ev_t('EINST.L_URL')) ?></label>
  <input data-role="none" type="text" id="url" name="url" value="<?= ev_e(ev_eingabe('speichern', 'url', $ev_cfg['url'])) ?>"<?= ev_markierung('speichern', 'url') ?> placeholder="http://127.0.0.1:7070">
  <div class="sm-hilfe"><?= ev_t('EINST.H_URL') ?></div>
<?php
/* "Es steht hier immer noch 127.0.0.1" - die haeufigste Rueckfrage zu diesem
   Feld, und der Wert ist richtig: das Plugin laeuft AUF dem LoxBerry und
   erreicht EVCC ueber die Rueckschleife. Nur anklicken kann man ihn von einem
   anderen Rechner aus nicht. Deshalb steht der Verweis, der im Browser
   wirklich traegt, gleich daneben - statt dass man ihn sich zusammensucht. */
$ev_link = ev_evcc_link();
if ($ev_link !== $ev_cfg['url']) { ?>
  <div class="sm-hilfe"><?= sprintf(ev_t('EINST.H_URL_LINK'), ev_e($ev_link)) ?></div>
<?php } ?>
</div>
<div class="sm-feld">
  <label for="passwort"><?= ev_e(ev_t('EINST.L_PASSWORT')) ?></label>
  <input data-role="none" type="password" id="passwort" name="passwort" value=""<?= ev_markierung('speichern', 'passwort') ?> placeholder="<?= ev_e($ev_cfg['passwort'] !== '' ? ev_t('EINST.P_GESETZT') : ev_t('EINST.P_LEER')) ?>">
  <div class="sm-hilfe"><?= ev_t('EINST.H_PASSWORT') ?></div>
  <label style="display:inline-flex;align-items:center;gap:8px;margin-top:6px;font-weight:400;">
    <input data-role="none" type="checkbox" name="passwort_loeschen" value="1" <?= ev_eingabe('speichern', 'passwort_loeschen', 0) ? 'checked' : '' ?>>
    <?= ev_e(ev_t('EINST.L_PASSWORT_LOESCHEN')) ?>
  </label>
</div>
<div class="sm-row">
  <div class="sm-feld">
    <label for="takt"><?= ev_e(ev_t('EINST.L_TAKT')) ?></label>
    <input data-role="none" type="number" id="takt" name="takt" value="<?= ev_e(ev_eingabe('speichern', 'takt', (int) $ev_cfg['takt'])) ?>"<?= ev_markierung('speichern', 'takt') ?> min="5" max="60">
    <div class="sm-hilfe"><?= ev_t('EINST.H_TAKT') ?></div>
  </div>
  <div class="sm-feld">
    <label for="ladepunkte"><?= ev_e(ev_t('EINST.L_LADEPUNKTE')) ?></label>
    <input data-role="none" type="number" id="ladepunkte" name="ladepunkte" value="<?= ev_e(ev_eingabe('speichern', 'ladepunkte', (int) $ev_cfg['ladepunkte'])) ?>"<?= ev_markierung('speichern', 'ladepunkte') ?> min="0" max="<?= EV_LADEPUNKTE ?>">
  </div>
  <div class="sm-feld">
    <label for="fahrzeuge"><?= ev_e(ev_t('EINST.L_FAHRZEUGE')) ?></label>
    <input data-role="none" type="number" id="fahrzeuge" name="fahrzeuge" value="<?= ev_e(ev_eingabe('speichern', 'fahrzeuge', (int) $ev_cfg['fahrzeuge'])) ?>"<?= ev_markierung('speichern', 'fahrzeuge') ?> min="0" max="<?= EV_FAHRZEUGE ?>">
    <div class="sm-hilfe"><?= ev_t('EINST.H_FAHRZEUGE') ?></div>
  </div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:400;">
    <input data-role="none" type="checkbox" name="tarife_ein" value="1" <?= !empty(ev_eingabe('speichern', 'tarife_ein', $ev_cfg['tarife_ein'])) ? 'checked' : '' ?>>
    <?= ev_e(ev_t('EINST.L_TARIFE')) ?>
  </label>
</div>

<h2><?= ev_e(ev_t('EINST.H_STEUERUNG')) ?></h2>
<div class="sm-warnung"><?= ev_t('EINST.H_STEUERUNG_TEXT') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:600;">
    <input data-role="none" type="checkbox" name="steuerung_ein" value="1" <?= !empty(ev_eingabe('speichern', 'steuerung_ein', $ev_cfg['steuerung_ein'])) ? 'checked' : '' ?>>
    <?= ev_e(ev_t('EINST.L_STEUERUNG')) ?>
  </label>
</div>

<?php /* Schreiber-Wache (Energie-1 C1, Entscheidung Nr. 25): melden ab Werk an,
         sperren ab Werk aus. Steht immer da - gemerkt wird nur, was der Endpunkt
         annimmt, und das setzt "Steuerung aus Loxone zulassen" voraus. */ ?>
<h3><?= ev_e(ev_t('EINST.H_WACHE')) ?></h3>
<div class="sm-hilfe"><?= ev_t('EINST.H_WACHE_TEXT') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:400;">
    <input data-role="none" type="checkbox" name="wache_ein" value="1"<?= ev_markierung('speichern', 'wache_ein') ?> <?= !empty(ev_eingabe('speichern', 'wache_ein', $ev_cfg['wache_ein'])) ? 'checked' : '' ?>>
    <?= ev_e(ev_t('EINST.L_WACHE_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="wache_fenster_min"><?= ev_e(ev_t('EINST.L_WACHE_FENSTER')) ?></label>
  <input data-role="none" type="number" id="wache_fenster_min" name="wache_fenster_min" value="<?= ev_e(ev_eingabe('speichern', 'wache_fenster_min', (string) $ev_cfg['wache_fenster_min'])) ?>"<?= ev_markierung('speichern', 'wache_fenster_min') ?> min="1" max="120">
  <div class="sm-hilfe"><?= ev_t('EINST.H_WACHE_FENSTER') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:400;">
    <input data-role="none" type="checkbox" name="wache_lb_melden" value="1"<?= ev_markierung('speichern', 'wache_lb_melden') ?> <?= !empty(ev_eingabe('speichern', 'wache_lb_melden', $ev_cfg['wache_lb_melden'])) ? 'checked' : '' ?>>
    <?= ev_e(ev_t('EINST.L_WACHE_LB')) ?>
  </label>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:400;">
    <input data-role="none" type="checkbox" name="wache_sperren_ein" value="1"<?= ev_markierung('speichern', 'wache_sperren_ein') ?> <?= !empty(ev_eingabe('speichern', 'wache_sperren_ein', $ev_cfg['wache_sperren_ein'])) ? 'checked' : '' ?>>
    <?= ev_e(ev_t('EINST.L_WACHE_SPERREN')) ?>
  </label>
  <div class="sm-hilfe"><?= ev_t('EINST.H_WACHE_SPERREN') ?></div>
</div>
<div class="sm-feld">
  <label for="wache_erlaubt"><?= ev_e(ev_t('EINST.L_WACHE_ERLAUBT')) ?></label>
  <input data-role="none" type="text" id="wache_erlaubt" name="wache_erlaubt" value="<?= ev_e(ev_eingabe('speichern', 'wache_erlaubt', (string) $ev_cfg['wache_erlaubt'])) ?>"<?= ev_markierung('speichern', 'wache_erlaubt') ?> placeholder="loxone">
  <div class="sm-hilfe"><?= ev_t('EINST.H_WACHE_ERLAUBT') ?></div>
</div>

<?php /* Nr. 36 b (Stufe 2): Ansage ueber die gemeinsame Sprachausgabe, ab Werk aus. */ ?>
<h2><?= ev_e(ev_t('DURCHSAGE.H')) ?></h2>
<div class="sm-hinweis"><?= ev_e(ev_t('DURCHSAGE.TEXT_HILFE')) ?></div>
<div class="sm-feld">
  <label><?= ev_e(ev_t('DURCHSAGE.L_ANLAESSE')) ?></label>
<?php foreach (ev_ansage_anlaesse() as $ev_ab => $ev_ak) { ?>
  <label style="display:inline-flex;align-items:center;gap:8px;margin-right:14px;">
    <input data-role="none" type="checkbox" name="<?= ev_e($ev_ak) ?>" value="1"<?= ev_markierung('speichern', $ev_ak) ?> <?= !empty(ev_eingabe('speichern', $ev_ak, $ev_cfg[$ev_ak])) ? 'checked' : '' ?>>
    <?= ev_e(ev_t('DURCHSAGE.A_' . strtoupper($ev_ab))) ?>
  </label>
<?php } ?>
  <div class="sm-hilfe"><?= ev_e(ev_t('DURCHSAGE.H_ANLAESSE')) ?></div>
</div>
<?= ansage_formular_html(ev_tts($ev_cfg), array(
    'w' => function ($n, $g) { return ev_eingabe('speichern', $n, $g); },
    'm' => function ($n) { return ev_markierung('speichern', $n); },
    'c' => function ($n, $g) { return !empty(ev_eingabe('speichern', $n, $g)); },
    'modi' => ev_ansage_modi()), ev_ansage_k()) ?>

<h2><?= ev_e(ev_t('EINST.H_UPDATE')) ?></h2>
<div class="sm-warnung"><?= ev_t('EINST.H_UPDATE_TEXT') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:600;">
    <input data-role="none" type="checkbox" name="update_ein" value="1" <?= !empty(ev_eingabe('speichern', 'update_ein', $ev_cfg['update_ein'])) ? 'checked' : '' ?>>
    <?= ev_e(ev_t('EINST.L_UPDATE')) ?>
  </label>
  <div class="sm-hilfe"><?= ev_t('EINST.H_UPDATE_HILFE') ?></div>
</div>

<?php /* MQTT stand hier bis zu dieser Fassung. Es wohnt jetzt
         vollstaendig im Reiter MQTT - eine Sache, eine Stelle. */ ?>

<?php /* Die Legende nennt genau die Farben, die in DIESER Knopfreihe
         vorkommen. Bis 0.9.26 stand hier Orange und Gruen ueber einer Reihe
         mit einem einzigen orangen Knopf; der gruene Knopf stand zwoelf
         Zeilen tiefer unter eigener Ueberschrift ganz ohne Legende. */ ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= ev_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ev_e(ev_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= ev_t('EINST.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= ev_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= ev_t('EINST.SICH_WARNUNG') ?></div>
<?php
/* X-3 (Verbesserungsbau 30.09.2026): Wuerde ein gespeicherter Wert das
   Zurueckspielen der eigenen Sicherung nicht bestehen, steht es HIER - am
   Knopf, denn der Download selbst kann keine Seitenmeldung tragen. Gelb: die
   Sicherung wird trotzdem geliefert. Dieselbe Pruefung wie beim
   Zurueckspielen (ev_sicherung_wert_mangel). */
$ev_altwerte = ev_sicherung_altwerte($ev_cfg);
if ($ev_altwerte) { ?>
<div class="sm-warnung"><?= sprintf(ev_t('EINST.SICH_ALTWERT'), ev_e(implode(', ', $ev_altwerte))) ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= ev_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= ev_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <?php echo ev_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="ev_sichern" value="1"><?= ev_t('EINST.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <?php echo ev_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="ev_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ev_zurueck" value="1"><?= ev_t('EINST.K_ZURUECK') ?></button>
  </form>
</div>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $ev_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">

<?php /* Hier stand bis 0.9.11 ein fest eingetragenes <h2>MQTT</h2> - und
         vier Zeilen darunter EINST.H_MQTT, das ebenfalls "MQTT" lautete, und
         weiter unten MQTT.H_TITEL, das auch "MQTT" lautete. Drei
         Ueberschriften, dreimal dasselbe Wort. Am Geraet aufgefallen. */ ?>
<form action="index.php" method="post">
  <?php echo ev_fmt(); ?>
<input data-role="none" type="hidden" name="save_mqtt" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<h2><?= ev_e(ev_t('EINST.H_MQTT')) ?></h2>
<?php if (ev_eingaben_aktiv('save_mqtt')) { ?>
<div class="sm-warnung"><?= ev_e(ev_t('EINST.EINGABEN_ZURUECK')) ?></div>
<?php } ?>
<?php /* Hier stand bis 0.9.10 eine zweite, eingebettete Autostart-Pruefung
         mit hart verdrahteter LoxBerry-Wurzel. Sie las den RICHTIGEN Schluessel
         (Gatewayautostart), waehrend ev_mqtt_zustand() 21 Zeilen weiter unten
         den falschen las - zwei Wege fuer dieselbe Frage, einer davon falsch.
         Jetzt gibt es nur noch ev_mqtt_zustand(), und der stimmt. */ ?>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:400;">
    <input data-role="none" type="checkbox" name="mqtt_ein" value="1" <?= !empty(ev_eingabe('save_mqtt', 'mqtt_ein', $ev_cfg['mqtt_ein'])) ? 'checked' : '' ?>>
    <?= ev_e(ev_t('EINST.L_MQTT_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="mqtt_topic"><?= ev_e(ev_t('EINST.L_MQTT_TOPIC')) ?></label>
  <input data-role="none" type="text" id="mqtt_topic" name="mqtt_topic" value="<?= ev_e(ev_eingabe('save_mqtt', 'mqtt_topic', $ev_cfg['mqtt_topic'])) ?>"<?= ev_markierung('save_mqtt', 'mqtt_topic') ?> placeholder="evcc2lox">
  <div class="sm-hilfe"><?= ev_t('EINST.H_MQTT_TOPIC') ?></div>
</div>
<div class="sm-feld">
  <label for="mqtt_vollsend_min"><?= ev_e(ev_t('EINST.L_MQTT_VOLLSEND')) ?></label>
  <input data-role="none" type="number" id="mqtt_vollsend_min" name="mqtt_vollsend_min" value="<?= ev_e(ev_eingabe('save_mqtt', 'mqtt_vollsend_min', (int) $ev_cfg['mqtt_vollsend_min'])) ?>"<?= ev_markierung('save_mqtt', 'mqtt_vollsend_min') ?> min="0" max="1440">
  <div class="sm-hilfe"><?= ev_t(ev_gateway_schluessel('EINST.H_MQTT_VOLLSEND')) ?></div>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= ev_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ev_e(ev_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<h2><?= ev_e(ev_t('MQTT.H_TITEL')) ?></h2>
<?php $ev_m = ev_mqtt_zustand(); ?>
<?php if (!$ev_m['gefunden']) { ?>
<div class="sm-fehler"><?= ev_t('MQTT.KEIN_ABSCHNITT') ?></div>
<?php } elseif (!$ev_m['autostart']) { ?>
<div class="sm-warnung"><?= ev_t('MQTT.KEIN_AUTOSTART') ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= sprintf(ev_t('MQTT.OK'), (int) $ev_m['udpport']) ?></div>
<?php } ?>

<div class="sm-step"><b><?= ev_e(ev_t('MQTT.H_ABO')) ?></b><br>
<?= ev_t('MQTT.ABO_TEXT') ?>
<div class="sm-pre"><?= ev_e($ev_cfg['mqtt_topic']) ?>/#</div>
<?= ev_abo_text() ?>
</div>

<h3><?= ev_e(ev_t('MQTT.H_THEMEN')) ?></h3>
<p class="sm-hilfe"><?= ev_t('MQTT.THEMEN_TEXT') ?></p>
<p class="sm-hilfe"><?= ev_t(ev_gateway_schluessel('MQTT.RETAIN_TEXT')) ?></p>
<table class="sm-tbl">
<tr><th><?= ev_e(ev_t('MQTT.T_THEMA')) ?></th><th><?= ev_e(ev_t('MQTT.T_BEDEUTUNG')) ?></th><th><?= ev_e(ev_t('MQTT.T_RETAIN')) ?></th><th><?= ev_e(ev_t('MQTT.T_EVCC')) ?></th></tr>
<?php foreach (ev_felder() as $ev_name => $ev_d) {
    if (in_array($ev_name, ev_mqtt_nicht_senden(), true)) { continue; }
    $ev_txt = ev_t($ev_d['text']);
    if (isset($ev_d['nr'])) { $ev_txt = sprintf($ev_txt, (int) $ev_d['nr']); } ?>
<tr>
  <td><span class="sm-mono"><?= ev_e($ev_cfg['mqtt_topic']) ?>/<?= ev_e($ev_name) ?></span></td>
  <td><?= $ev_txt ?><?= $ev_d['einheit'] !== '' ? ' <span class="sm-mono">' . ev_e($ev_d['einheit']) . '</span>' : '' ?></td>
  <td><?= ev_e(ev_t(ev_retain_fuer($ev_name) ? 'MQTT.RETAIN_JA' : 'MQTT.RETAIN_NEIN')) ?></td>
  <td><span class="sm-mono"><?= ev_e($ev_d['mqtt'] !== '' ? $ev_d['mqtt'] : '-') ?></span></td>
</tr>
<?php } ?>
<tr>
  <td><span class="sm-mono"><?= ev_e($ev_cfg['mqtt_topic']) ?>/ts</span></td>
  <td><?= ev_t('MQTT.TS_TEXT') ?> <span class="sm-mono">s</span></td>
  <td><?= ev_e(ev_t('MQTT.RETAIN_NEIN')) ?></td>
  <td><span class="sm-mono">-</span></td>
</tr>
</table>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $ev_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">

<h2><?= ev_e(ev_t('LOX.H_EM')) ?></h2>
<div class="sm-hinweis"><?= ev_t('LOX.EM_EINLEITUNG') ?></div>

<div class="sm-step"><b><?= ev_e(ev_t('LOX.H_EM_TABELLE')) ?></b><br>
<?= ev_t('LOX.EM_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= ev_e(ev_t('LOX.T_VONHIER')) ?></th><th><?= ev_e(ev_t('LOX.T_ANSCHLUSS')) ?></th><th><?= ev_e(ev_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<tr><td><span class="sm-mono">EVCC_NETZ_KW</span></td><td><span class="sm-mono">Gpwr</span></td><td><?= ev_t('LOX.Z_GPWR') ?></td></tr>
<tr><td><span class="sm-mono">EVCC_PV_KW</span></td><td><span class="sm-mono">Ppwr</span></td><td><?= ev_t('LOX.Z_PPWR') ?></td></tr>
<tr><td><span class="sm-mono">EVCC_SPEICHER_KW</span></td><td><span class="sm-mono">Spwr</span></td><td><?= ev_t('LOX.Z_SPWR') ?></td></tr>
<tr><td><span class="sm-mono">EVCC_SPEICHER_SOC</span></td><td><span class="sm-mono">Soc</span></td><td><?= ev_t('LOX.Z_SOC') ?></td></tr>
<tr><td><span class="sm-mono">EVCC_LP1_LEISTUNG_KW</span></td><td><span class="sm-mono">L1 &hellip; L12</span></td><td><?= ev_t('LOX.Z_LAST') ?></td></tr>
</table>
<div class="sm-warnung"><?= ev_t('LOX.EM_WARNUNG') ?></div>
</div>

<h2><?= ev_e(ev_t('LOX.H_VORLAGE')) ?></h2>
<div class="sm-hinweis"><?= ev_t('LOX.VORLAGE_TEXT') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= ev_t('LEGENDE.TECHNIK') ?></span>
</div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
  <?php echo ev_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="vorlage" value="ein">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= ev_e(ev_t('LOX.K_VORLAGE_EIN')) ?></button>
</form>
<?php if (!empty($ev_cfg['steuerung_ein'])) { ?>
<form action="index.php" method="post">
  <?php echo ev_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="vorlage" value="aus">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= ev_e(ev_t('LOX.K_VORLAGE_AUS')) ?></button>
</form>
<?php } ?>
</div>
<?php if (empty($ev_cfg['steuerung_ein'])) { ?>
<div class="sm-hilfe"><?= ev_t('LOX.VORLAGE_AUS_GESPERRT') ?></div>
<?php } ?>

<h2><?= ev_e(ev_t('LOX.H_SCHRITTE')) ?></h2>

<div class="sm-step"><b><?= ev_e(ev_t('LOX.S1_T')) ?></b><br>
<?= ev_t('LOX.S1') ?>
<div class="sm-pre"><?= ev_e(ev_endpunkt('status')) ?></div>
<?= ev_t('LOX.S1_ABFRAGE') ?>
</div>

<div class="sm-step"><b><?= ev_e(ev_t('LOX.S2_T')) ?></b><br>
<?= ev_t('LOX.S2') ?>
</div>

<?php if (!empty($ev_cfg['steuerung_ein'])) { ?>
<div class="sm-step"><b><?= ev_e(ev_t('LOX.S3_T')) ?></b><br>
<?= ev_t('LOX.S3') ?>
<?php /* Die Adresse gehoert hierher, nicht in Schritt 1. Bis 0.9.26 sagte
         der Satz "ein virtueller Ausgang mit DIESER Adresse", und es folgte
         unmittelbar die Tabelle mit den blossen &aktion=-Anhaengseln - das
         Bezugsobjekt fehlte, und der Anwender musste sich Basis und Token
         aus Schritt 1 zusammenklauben. */ ?>
<div class="sm-pre"><?= ev_e(ev_endpunkt('', true)) ?></div>
<table class="sm-tbl">
<tr><th><?= ev_e(ev_t('LOX.T_BEFEHL')) ?></th><th><?= ev_e(ev_t('LOX.T_BEDEUTUNG')) ?></th><th><?= ev_e(ev_t('LOX.T_HERKUNFT')) ?></th></tr>
<?php
/* Erzeugt aus ev_befehle() - derselben Quelle, aus der der Endpunkt seine
   Pruefung und die Vorlage ihre Ausgaenge nimmt. Bis 0.9.10 stand die Liste
   hier ein drittes Mal von Hand. */
foreach (ev_befehle() as $ev_a => $ev_b) {
    // Energie-1 C1: wie die Vorlage mit von=loxone (Schreiber-Wache).
    $ev_adr = '&amp;aktion=' . $ev_a . ($ev_b['ebene'] === 'lp' ? '&amp;lp=1' : '') . '&amp;von=loxone';
    if ($ev_b['pruef'] === 'ohne')          { $ev_adr .= ''; }
    elseif ($ev_b['pruef'] === 'schalter')  { $ev_adr .= '&amp;wert=1|0'; }
    elseif ($ev_b['pruef'] === 'plan')      { $ev_adr .= '&amp;wert=&lt;ziel&gt;&amp;stunden=&lt;vorlauf&gt;'; }
    elseif ($ev_b['pruef'] === 'planziel' || $ev_b['pruef'] === 'planstunden') { $ev_adr .= '&amp;wert=&lt;v&gt;'; }
    // Seit 0.9.43: Fahrzeugwahl je Fahrzeug mit festem Namen, Analogwerte aus ev_vorlage_platzhalter().
    elseif ($ev_b['pruef'] === 'name')      { $ev_adr .= '&amp;wert=&lt;' . ev_e(ev_t('LOX.FAHRZEUG_PLATZ')) . '&gt;'; }
    else                                    { $ev_adr .= '&amp;wert=' . ev_e(ev_vorlage_platzhalter($ev_b)); }
    $ev_bed = ev_t($ev_b['text']);
    if ($ev_b['pruef'] === 'name') { $ev_bed .= ' ' . sprintf(ev_t('LOX.FAHRZEUG_JE'), ev_e(ev_befehl_titel($ev_a, 1, 1))); }
    // Ein Befehl, der nicht in die Vorlage geht, sagt warum (C11).
    if (isset($ev_b['vorlage']) && empty($ev_b['vorlage'])) { $ev_bed .= ' ' . ev_t('LOX.NICHT_IN_VORLAGE'); }
    if ($ev_a === 'modus')        { $ev_bed .= ' (0=off, 1=now, 2=minpv, 3=pv)'; }
    if ($ev_a === 'batteriemodus'){ $ev_bed .= ' (0=normal, 1=hold, 2=charge)'; }
?>
<tr>
  <td><span class="sm-mono"><?= $ev_adr ?></span><?= $ev_b['methode'] === 'DELETE' ? ' <b>(DELETE)</b>' : '' ?></td>
  <td><?= $ev_bed ?></td>
  <?php /* Eine Zuordnung, kein zweiwertiger Ausdruck: kaeme eine dritte
           Herkunft dazu, behauptete "sonst gemessen" etwas Falsches - genau
           das ist bei den Auto-Plugins am 20.08.2026 passiert. Was die
           Zuordnung nicht kennt, wird beim Namen genannt. */
     $ev_q = array('doku'    => '<b>' . ev_e(ev_t('LOX.Q_DOKU')) . '</b>',
                   'bestand' => ev_e(ev_t('LOX.Q_GEMESSEN')));
     ?>
  <td><?= isset($ev_q[$ev_b['quelle']]) ? $ev_q[$ev_b['quelle']]
          : ev_e((string) $ev_b['quelle']) ?></td>
</tr>
<?php } ?>
</table>
<div class="sm-warnung"><?= ev_t('LOX.BEFEHLE_DOKU_WARNUNG') ?></div>
<div class="sm-hilfe"><?= ev_t('LOX.VON_HINWEIS') ?></div>
</div>
<?php } ?>

<h2><?= ev_e(ev_t('SPOT.H')) ?></h2>
<div class="sm-hinweis"><?= ev_t('SPOT.EINLEITUNG') ?></div>
<?php if (empty($ev_cfg['tarife_ein'])) { ?>
<div class="sm-hilfe"><?= ev_t('SPOT.GESPERRT') ?></div>
<?php } else { ?>
<div class="sm-step"><b><?= ev_e(ev_t('SPOT.H_WERTE')) ?></b><br>
<?= ev_t('SPOT.WERTE_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= ev_e(ev_t('LOX.T_VONHIER')) ?></th><th><?= ev_e(ev_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<tr><td><span class="sm-mono">EVCC_TARIF_NETZ</span></td><td><?= ev_t('SPOT.Z_NETZ') ?></td></tr>
<tr><td><span class="sm-mono">EVCC_TARIF_EINSPEISUNG</span></td><td><?= ev_t('SPOT.Z_EINSPEISUNG') ?></td></tr>
<tr><td><span class="sm-mono">EVCC_TARIF_CO2</span></td><td><?= ev_t('SPOT.Z_CO2') ?></td></tr>
<tr><td><span class="sm-mono">EVCC_LP1_SMARTCOST_AKTIV</span></td><td><?= ev_t('SPOT.Z_AKTIV') ?></td></tr>
<tr><td><span class="sm-mono">EVCC_PREIS_MIN_24H</span></td><td><?= ev_t('SPOT.Z_MIN') ?></td></tr>
<tr><td><span class="sm-mono">EVCC_PREIS_MAX_24H</span></td><td><?= ev_t('SPOT.Z_MAX') ?></td></tr>
<tr><td><span class="sm-mono">EVCC_PREIS_SCHNITT_24H</span></td><td><?= ev_t('SPOT.Z_SCHNITT') ?></td></tr>
<tr><td><span class="sm-mono">EVCC_PREIS_RANG</span></td><td><?= ev_t('SPOT.Z_RANG') ?></td></tr>
<tr><td><span class="sm-mono">EVCC_PREIS_STUNDEN</span></td><td><?= ev_t('SPOT.Z_ANZAHL') ?></td></tr>
<tr><td><span class="sm-mono">EVCC_PREIS_GUENSTIGSTE_STUNDE</span></td><td><?= ev_t('SPOT.Z_BESTE') ?></td></tr>
<tr><td><span class="sm-mono">EVCC_PREIS_OK</span></td><td><?= ev_t('SPOT.Z_OK') ?></td></tr>
</table>
<div class="sm-hinweis"><?= ev_t('SPOT.RANG_REZEPT') ?></div>
</div>

<div class="sm-step"><b><?= ev_e(ev_t('SPOT.H_GRENZE')) ?></b><br>
<?= ev_t('SPOT.GRENZE_TEXT') ?>
<?php if (!empty($ev_cfg['steuerung_ein'])) { ?>
<div class="sm-pre"><?= ev_e(ev_endpunkt('smartcostlimit')) ?>&amp;lp=1&amp;von=loxone&amp;wert=&lt;v.3&gt;</div>
<?= ev_t('SPOT.GRENZE_EINHEIT') ?>
<?php } else { ?>
<div class="sm-hilfe"><?= ev_t('SPOT.GRENZE_GESPERRT') ?></div>
<?php } ?>
</div>

<div class="sm-step"><b><?= ev_e(ev_t('SPOT.H_REZEPT')) ?></b><br>
<?= ev_t('SPOT.REZEPT') ?>
<div class="sm-warnung"><?= ev_t('SPOT.WARNUNG') ?></div>
</div>
<?php } ?>

<h2><?= ev_e(ev_t('DIREKT.H')) ?></h2>
<div class="sm-hinweis"><?= ev_t('DIREKT.EINLEITUNG') ?></div>
<div class="sm-step"><b><?= ev_e(ev_t('DIREKT.H_BEDINGUNG')) ?></b><br>
<?php if ((string) $ev_cfg['passwort'] !== '') { ?>
<div class="sm-warnung"><?= ev_t('DIREKT.PASSWORT_GESETZT') ?></div>
<?php } else { ?>
<div class="sm-hilfe"><?= ev_t('DIREKT.PASSWORT_LEER') ?></div>
<table class="sm-tbl">
<tr><th><?= ev_e(ev_t('LOX.T_BEFEHL')) ?></th><th><?= ev_e(ev_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<tr><td><span class="sm-mono"><?= ev_e($ev_cfg['url']) ?>/api/loadpoints/1/mode/pv</span></td><td><?= ev_t('DIREKT.Z_MODUS') ?></td></tr>
<tr><td><span class="sm-mono"><?= ev_e($ev_cfg['url']) ?>/api/loadpoints/1/limitsoc/&lt;v.0&gt;</span></td><td><?= ev_t('DIREKT.Z_LIMITSOC') ?></td></tr>
<tr><td><span class="sm-mono"><?= ev_e($ev_cfg['url']) ?>/api/batterymode/hold</span></td><td><?= ev_t('DIREKT.Z_BATTERIE') ?></td></tr>
</table>
<?= ev_t('DIREKT.METHODE') ?>
<?php } ?>
</div>
<div class="sm-step"><b><?= ev_e(ev_t('DIREKT.H_ABWAEGUNG')) ?></b><br>
<?= ev_t('DIREKT.ABWAEGUNG') ?>
</div>

<div class="sm-step"><b><?= ev_e(ev_t('LOX.S_GRUND_T')) ?></b><br>
<?= ev_t('LOX.S_GRUND') ?>
<table class="sm-tbl">
<tr><th><?= ev_e(ev_t('LOX.T_ABLESEN')) ?></th><th><?= ev_e(ev_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<tr><td><span class="sm-mono">LP1_LAEDT = 1</span></td><td><?= ev_t('LOX.G_LAEDT') ?></td></tr>
<tr><td><span class="sm-mono">LP1_VERBUNDEN = 0</span></td><td><?= ev_t('LOX.G_KEIN_FZ') ?></td></tr>
<tr><td><span class="sm-mono">LP1_MODUS_NR = 0</span></td><td><?= ev_t('LOX.G_AUS') ?></td></tr>
<tr><td><span class="sm-mono">LP1_PV_WARTEN_MIN &gt; 0</span></td><td><?= ev_t('LOX.G_PV') ?></td></tr>
<tr><td><span class="sm-mono">LP1_PHASEN_WARTEN_MIN &gt; 0</span></td><td><?= ev_t('LOX.G_PHASEN') ?></td></tr>
<tr><td><span class="sm-mono">LP1_FAHRZEUG_SOC &ge; LP1_LIMIT_SOC</span></td><td><?= ev_t('LOX.G_VOLL') ?></td></tr>
</table>
<?= ev_t('LOX.S_GRUND_HINWEIS') ?>
</div>

<div class="sm-step"><b><?= ev_e(ev_t('LOX.S_AUSFALL_T')) ?></b><br>
<?= ev_t('LOX.S_AUSFALL') ?>
<table class="sm-tbl">
<tr><th><?= ev_e(ev_t('LOX.T_ABLESEN')) ?></th><th><?= ev_e(ev_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<tr><td><span class="sm-mono">OK</span></td><td><?= ev_t('LOX.A_OK') ?></td></tr>
<tr><td><span class="sm-mono">ALTER_S</span></td><td><?= ev_t('LOX.A_ALTER') ?></td></tr>
<tr><td><span class="sm-mono">FEHLER_NR = 0</span></td><td><?= ev_t('LOX.A_NR0') ?></td></tr>
<tr><td><span class="sm-mono">FEHLER_NR = 1</span></td><td><?= ev_t('LOX.A_NR1') ?></td></tr>
<tr><td><span class="sm-mono">FEHLER_NR = 2</span></td><td><?= ev_t('LOX.A_NR2') ?></td></tr>
<tr><td><span class="sm-mono">FEHLER_NR = 3</span></td><td><?= ev_t('LOX.A_NR3') ?></td></tr>
<tr><td><span class="sm-mono">FEHLER_NR = 4</span></td><td><?= ev_t('LOX.A_NR4') ?></td></tr>
<tr><td><span class="sm-mono">FEHLER_NR = 5</span></td><td><?= ev_t('LOX.A_NR5') ?></td></tr>
<tr><td><span class="sm-mono">BETRIEBSBEREIT</span></td><td><?= ev_t('LOX.A_BETRIEBSBEREIT') ?></td></tr>
<tr><td><span class="sm-mono">DIENST</span></td><td><?= ev_t('LOX.A_DIENST') ?></td></tr>
</table>
<div class="sm-warnung"><?= ev_t('LOX.A_WARNUNG') ?></div>
</div>

<div class="sm-step"><b><?= ev_e(ev_t('LOX.S4_T')) ?></b><br>
<?= ev_t('LOX.S4') ?>
<?php
/* Welle Bild 12 VOLL (0.9.43, Hausherr 11.10.2026: Vollausstattung, ein absoluter Laie soll das
 * Maximum leicht uebernehmen koennen): vier Seiten aus dem LoxBerry-Plugins Musterprojekt, dort in
 * Loxone Config gebaut und mit leitungen_setzen.py verbunden (Musterprojekt/baustein_listen.txt,
 * Abschnitte "EVCC" 43, "EVCC Ladepunkt 1" 32, "EVCC Steuerung" 27, "EVCC Hausspeicher und
 * Tarif" 33 Zeilen). Je Seite: Ueberschrift, Kasten "So uebernehmen Sie diese Seite", Tabelle,
 * Hinweise, Bild, Link - alle immer sichtbar. Bis 0.9.42 stand hier eine Seite mit 13 Zeilen.
 * Die Namen der Eingaenge kommen aus ev_feld_titel(), die der Befehle aus ev_befehl_titel() -
 * denselben Stellen wie die Vorlagen (O8); die Vorlagentitel aus ev_vorlage_titel_ein()/_aus().
 * Die Virtuellen Texteingaenge legt keine Vorlage an (Regeln/07); ihr Name ist der Gateway-Name
 * aus dem eingestellten MQTT-Praefix. Texte aus [BAUSTEIN]; jede Zelle ist fertiges, maskiertes
 * HTML. Zeile: array(Nr, Typ, Name, Parameter, Eingaenge). */
/* Erst vsprintf auf den Sprachtext, dann maskieren: eingesetzte Namen kommen nie roh heraus. */
$ev_bs_k = function ($schluessel, $argumente = array()) {
    $s = (string) ev_t('BAUSTEIN.' . $schluessel);
    return ev_e($argumente ? vsprintf($s, $argumente) : $s);
};
$ev_bs_m = '&mdash;';
$ev_bs_mono = function ($s) { return '<span class="sm-mono">' . ev_e($s) . '</span>'; };
$ev_bs_vi_t = ev_vorlage_titel_ein();
$ev_bs_vo_t = ev_vorlage_titel_aus();
/* Gateway-Name eines MQTT-Themas: Praefix/Thema, Schraegstrich und Prozent werden zu _ (Regeln/07). */
$ev_bs_gw = function ($thema) use ($ev_cfg) {
    return str_replace(array('/', '%'), '_', ev_mqtt_thema($ev_cfg['mqtt_topic'] . '/' . $thema));
};
$ev_bs_ein = function ($nr) use ($ev_bs_k) { return $ev_bs_k('E_EIN', array($nr)); };
$ev_bs_aus = function ($pin, $nr) use ($ev_bs_k) { return $ev_bs_k('E_AUSGANG', array($pin, $nr)); };
$ev_bs_par = function ($param) use ($ev_bs_k, $ev_bs_m) { return $param === '' ? $ev_bs_m : $ev_bs_k($param); };
/* Der Virtuelle HTTP-Eingang selbst (Vorlage "EVCC"). */
$ev_bs_vi_kopf = function ($nr, $param) use ($ev_bs_k, $ev_bs_mono, $ev_bs_par, $ev_bs_vi_t, $ev_bs_m) {
    return array($nr, $ev_bs_k('TYP_VI', array($ev_bs_vi_t)), $ev_bs_mono($ev_bs_vi_t), $ev_bs_par($param), $ev_bs_m);
};
/* Eingang aus der Vorlage "EVCC": Name aus ev_feld_titel(). */
$ev_bs_vi_z = function ($nr, $feld, $param) use ($ev_bs_k, $ev_bs_mono, $ev_bs_par, $ev_bs_vi_t, $ev_bs_m) {
    return array($nr, $ev_bs_k('TYP_VIB', array($ev_bs_vi_t)), $ev_bs_mono(ev_feld_titel($feld)),
                 $ev_bs_par($param), $ev_bs_m);
};
/* Befehl aus der Vorlage "EVCC Befehle": Name aus ev_befehl_titel(). */
$ev_bs_vo_z = function ($nr, $typ, $aktion, $lp, $param, $verb) use ($ev_bs_k, $ev_bs_mono, $ev_bs_par, $ev_bs_vo_t) {
    return array($nr, $ev_bs_k($typ, array($ev_bs_vo_t)), $ev_bs_mono(ev_befehl_titel($aktion, $lp)),
                 $ev_bs_par($param), $verb);
};
/* Virtueller Texteingang: Name = Gateway-Name des Themas. */
$ev_bs_tx_z = function ($nr, $thema, $param) use ($ev_bs_k, $ev_bs_mono, $ev_bs_par, $ev_bs_gw, $ev_bs_m) {
    return array($nr, $ev_bs_k('T_TEXTEIN'), $ev_bs_mono($ev_bs_gw($thema)), $ev_bs_par($param), $ev_bs_m);
};
/* Uebriger Baustein: Typ-Schluessel, Name <S>_NAME, Parameter <S>_PARAM. */
$ev_bs_zl = function ($nr, $typ, $s, $param, $verb) use ($ev_bs_k, $ev_bs_par) {
    return array($nr, $ev_bs_k($typ), $ev_bs_k($s . '_NAME'), $ev_bs_par($param), $verb);
};
$ev_bs_seiten = array(
    array('kopf' => 'BL_EVCC', 'seite' => 'EVCC', 'bild' => 'einbindung_loxone.png', 'zeilen' => array(
        $ev_bs_vi_kopf(1, 'S1_01_PARAM'),
        $ev_bs_zl(2, 'T_EM', 'S1_02', 'S1_02_PARAM', 'Gpwr = EVCC_NETZ_KW, Ppwr = EVCC_PV_KW, Spwr = EVCC_SPEICHER_KW, Soc = EVCC_SPEICHER_SOC, IL1 = EVCC_LP1_LEISTUNG_KW'),
        $ev_bs_zl(3, 'T_STATUS', 'S1_03', 'S1_03_PARAM', 'V1 = EVCC_LP1_LAEDT, V2 = EVCC_LP1_LEISTUNG_KW, V3 = EVCC_LP1_FAHRZEUG_SOC, V4 = #28, V5 = #29, V6 = #31, V7 = #32, V8 = #33'),
        $ev_bs_zl(4, 'T_FLANKE', 'S1_04', 'S1_04_PARAM', $ev_bs_k('E_EIN_NAME', array('EVCC_LP1_LAEDT'))),
        $ev_bs_zl(5, 'T_BENACHR', 'S1_05', 'S1_05_PARAM', $ev_bs_ein(34)),
        $ev_bs_zl(6, 'T_SCHWELLE', 'S1_06', 'S1_06_PARAM', $ev_bs_k('E_EIN_NAME', array('EVCC_ALTER_S'))),
        $ev_bs_zl(7, 'T_UNGLEICH', 'S1_07', 'S1_07_PARAM', 'I1 = EVCC_FEHLER_NR'),
        $ev_bs_zl(8, 'T_ODER', 'S1_08', '', 'I1 = #7, I2 = #6'),
        $ev_bs_zl(9, 'T_BENACHR', 'S1_09', 'S1_09_PARAM', $ev_bs_ein(25)),
        $ev_bs_zl(10, 'T_KLEINERGLEICH', 'S1_10', 'S1_10_PARAM', 'I1 = EVCC_PREIS_RANG'),
        $ev_bs_zl(11, 'T_UND', 'S1_11', '', 'I1 = #10, I2 = EVCC_PREIS_OK'),
        $ev_bs_vo_z(12, 'TYP_VO', 'modus', 1, 'S1_12_PARAM', $ev_bs_ein(35)),
        $ev_bs_zl(13, 'T_SCHWELLE', 'S1_13', 'S1_13_PARAM', $ev_bs_k('E_EIN_NAME', array('EVCC_PROGNOSE_MORGEN'))),
        $ev_bs_vi_z(14, 'haus_kw', 'S1_14_PARAM'),
        $ev_bs_vi_z(15, 'gruen_haus', 'S1_15_PARAM'),
        $ev_bs_zl(16, 'T_STATUS', 'S1_16', 'S1_16_PARAM', 'V1 = EVCC_PV_KW, V2 = #14, V3 = EVCC_NETZ_KW, V4 = EVCC_SPEICHER_KW, V5 = EVCC_SPEICHER_SOC, V6 = #15'),
        $ev_bs_vi_z(17, 'ok', 'S1_17_PARAM'),
        $ev_bs_vi_z(18, 'betriebsbereit', 'S1_18_PARAM'),
        $ev_bs_vi_z(19, 'dienst', 'S1_19_PARAM'),
        $ev_bs_zl(20, 'T_KONSTANTE', 'S1_20', 'S1_20_PARAM', $ev_bs_m),
        $ev_bs_zl(21, 'T_VALID', 'S1_21', 'S1_21_PARAM', 'V = EVCC_ALTER_S, En = #20'),
        $ev_bs_zl(22, 'T_NICHT', 'S1_22', '', 'I = #18'),
        $ev_bs_zl(23, 'T_ODER', 'S1_23', '', 'I1 = ' . $ev_bs_aus('E', 21) . ', I2 = #22'),
        $ev_bs_zl(24, 'T_ODER', 'S1_24', 'S1_24_PARAM', 'I1 = #8, I2 = #23'),
        $ev_bs_zl(25, 'T_EVZ', 'S1_25', 'S1_25_PARAM', $ev_bs_ein(24)),
        $ev_bs_tx_z(26, 'letzter_fehler', 'S1_26_PARAM'),
        $ev_bs_zl(27, 'T_STATUS', 'S1_27', 'S1_27_PARAM', 'V1 = ' . $ev_bs_aus('E', 21) . ', V2 = #19, V3 = EVCC_FEHLER_NR, V4 = #26, V5 = #17, V6 = EVCC_ALTER_S'),
        $ev_bs_vi_z(28, 'lp1_verbunden', 'S1_28_PARAM'),
        $ev_bs_vi_z(29, 'lp1_modus_nr', 'S1_29_PARAM'),
        $ev_bs_vi_z(30, 'lp1_limit_soc', 'S1_30_PARAM'),
        $ev_bs_vi_z(31, 'lp1_pv_warten_min', 'S1_31_PARAM'),
        $ev_bs_vi_z(32, 'lp1_phasen_warten_min', 'S1_32_PARAM'),
        $ev_bs_zl(33, 'T_GROESSERGLEICH', 'S1_33', 'S1_33_PARAM', 'V1 = EVCC_LP1_FAHRZEUG_SOC, V2 = #30'),
        $ev_bs_zl(34, 'T_UND', 'S1_34', 'S1_34_PARAM', 'I1 = ' . $ev_bs_aus('Off', 4) . ', I2 = #33'),
        $ev_bs_zl(35, 'T_RADIO', 'S1_35', 'S1_35_PARAM', $ev_bs_m),
        $ev_bs_vi_z(36, 'tarif_netz', 'S1_36_PARAM'),
        $ev_bs_vi_z(37, 'preis_stunden', 'S1_37_PARAM'),
        $ev_bs_vi_z(38, 'preis_guenstigste_stunde', 'S1_38_PARAM'),
        $ev_bs_vi_z(39, 'lp1_smartcost_aktiv', 'S1_39_PARAM'),
        $ev_bs_zl(40, 'T_STATUS', 'S1_40', 'S1_40_PARAM', 'V1 = #36, V2 = EVCC_PREIS_RANG, V3 = #37, V4 = #11, V5 = EVCC_PREIS_OK, V6 = #38, V7 = #39'),
        $ev_bs_vi_z(41, 'prognose_heute', 'S1_41_PARAM'),
        $ev_bs_vi_z(42, 'prognose_uebermorgen', 'S1_42_PARAM'),
        $ev_bs_zl(43, 'T_STATUS', 'S1_43', 'S1_43_PARAM', 'V1 = #13, V2 = #41, V3 = EVCC_PROGNOSE_MORGEN, V4 = #42'),
        ),
        'schritte' => array(
            array('S1_SCHRITT1', array()),
            array('S1_SCHRITT2', array(ev_t('REITER.EINSTELLUNGEN'), ev_t('EINST.L_URL'), ev_t('EINST.L_LADEPUNKTE'), ev_t('EINST.L_FAHRZEUGE'), ev_t('EINST.L_TARIFE'), ev_t('EINST.L_STEUERUNG'))),
            array('S1_SCHRITT3', array(ev_t('REITER.MQTT'), ev_t('EINST.L_MQTT_EIN'), $ev_cfg['mqtt_topic'], $ev_cfg['mqtt_topic'])),
            array('S1_SCHRITT4', array(ev_t('LOX.K_VORLAGE_EIN'), ev_t('LOX.K_VORLAGE_AUS'))),
            array('S1_SCHRITT5', array()),
            array('S1_SCHRITT6', array($ev_bs_vi_t, $ev_bs_vo_t, ev_befehl_titel('modus', 1))),
            array('S1_SCHRITT7', array($ev_bs_gw('letzter_fehler'))),
            array('S1_SCHRITT8', array()),
            array('S1_SCHRITT9', array(ev_t('LOX.T_VERBINDEN'))),
            array('S1_SCHRITT10', array(ev_t('LOX.T_PARAMETER'))),
            array('S1_SCHRITT11', array()),
            array('S1_SCHRITT12', array(ev_feld_titel('lp1_leistung_kw'))),
            array('S1_SCHRITT13', array(ev_t('REITER.TEST'))),
        ),
        'hinweise' => array(
            array('S1_H1', array()),
            array('S1_H2', array()),
            array('S1_H3', array()),
            array('S1_H4', array(ev_feld_titel('dienst'))),
            array('S1_H5', array(ev_t('TEST.K_TOKEN'), ev_t('REITER.TEST'))),
            array('S1_H6', array()),
            array('S1_H7', array(ev_befehl_titel('modus', 1))),
        )),
    array('kopf' => 'BL_LP1', 'seite' => 'EVCC Ladepunkt 1', 'bild' => 'einbindung_loxone_ladepunkt_1.png', 'zeilen' => array(
        $ev_bs_vi_z(1, 'lp1_geladen_kwh', 'S2_01_PARAM'),
        $ev_bs_vi_z(2, 'lp1_ladedauer_min', 'S2_02_PARAM'),
        $ev_bs_vi_z(3, 'lp1_solaranteil', 'S2_03_PARAM'),
        $ev_bs_vi_z(4, 'lp1_sitzung_preis', 'S2_04_PARAM'),
        $ev_bs_vi_z(5, 'lp1_sitzung_preis_kwh', 'S2_05_PARAM'),
        $ev_bs_vi_z(6, 'lp1_sitzung_co2', 'S2_06_PARAM'),
        $ev_bs_vi_z(7, 'lp1_rest_kwh', 'S2_07_PARAM'),
        $ev_bs_vi_z(8, 'lp1_restzeit_min', 'S2_08_PARAM'),
        $ev_bs_zl(9, 'T_STATUS', 'S2_09', 'S2_09_PARAM', 'V1 = #1, V2 = #2, V3 = #3, V4 = #4, V5 = #5, V6 = #6, V7 = #7, V8 = #8'),
        $ev_bs_vi_z(10, 'lp1_gesamt_kwh', 'S2_10_PARAM'),
        $ev_bs_vi_z(11, 'lp1_phasen', 'S2_11_PARAM'),
        $ev_bs_vi_z(12, 'lp1_strom_a', 'S2_12_PARAM'),
        $ev_bs_vi_z(13, 'lp1_freigegeben', 'S2_13_PARAM'),
        $ev_bs_vi_z(14, 'lp1_fahrzeug_km', 'S2_14_PARAM'),
        $ev_bs_tx_z(15, 'lp1_fahrzeug_name', 'S2_15_PARAM'),
        $ev_bs_zl(16, 'T_STATUS', 'S2_16', 'S2_16_PARAM', 'V1 = #11, V2 = #12, V3 = #13, V4 = #14, V5 = #15, V6 = #10'),
        $ev_bs_vi_z(17, 'lp1_min_soc', 'S2_17_PARAM'),
        $ev_bs_vi_z(18, 'lp1_minstrom_a', 'S2_18_PARAM'),
        $ev_bs_vi_z(19, 'lp1_maxstrom_a', 'S2_19_PARAM'),
        $ev_bs_vi_z(20, 'lp1_phasen_soll', 'S2_20_PARAM'),
        $ev_bs_vi_z(21, 'lp1_prioritaet', 'S2_21_PARAM'),
        $ev_bs_vi_z(22, 'lp1_smartcost_grenze', 'S2_22_PARAM'),
        $ev_bs_vi_z(23, 'lp1_batterieboost', 'S2_23_PARAM'),
        $ev_bs_zl(24, 'T_STATUS', 'S2_24', 'S2_24_PARAM', 'V1 = #17, V2 = #18, V3 = #19, V4 = #20, V5 = #21, V6 = #22, V7 = #23'),
        $ev_bs_vi_z(25, 'lp1_plan_aktiv', 'S2_25_PARAM'),
        $ev_bs_vi_z(26, 'lp1_plan_soc', 'S2_26_PARAM'),
        $ev_bs_vi_z(27, 'lp1_plan_kwh', 'S2_27_PARAM'),
        $ev_bs_zl(28, 'T_STATUS', 'S2_28', 'S2_28_PARAM', 'V1 = #25, V2 = #26, V3 = #27'),
        $ev_bs_vi_z(29, 'fz1_soc', 'S2_29_PARAM'),
        $ev_bs_vi_z(30, 'fz1_km', 'S2_30_PARAM'),
        $ev_bs_vi_z(31, 'fz1_limit_soc', 'S2_31_PARAM'),
        $ev_bs_zl(32, 'T_STATUS', 'S2_32', 'S2_32_PARAM', 'V1 = #29, V2 = #30, V3 = #31'),
        ),
        'schritte' => array(
            array('S2_SCHRITT1', array()),
            array('S2_SCHRITT2', array($ev_bs_vi_t, ev_feld_titel('lp1_gesamt_kwh'))),
            array('S2_SCHRITT3', array($ev_bs_gw('lp1_fahrzeug_name'))),
            array('S2_SCHRITT4', array(ev_t('LOX.T_PARAMETER'))),
        ),
        'hinweise' => array(
            array('S2_H1', array()),
            array('S2_H2', array()),
            array('S2_H3', array($ev_bs_gw('lp2_fahrzeug_name'))),
        )),
    array('kopf' => 'BL_STEUERUNG', 'seite' => 'EVCC Steuerung', 'bild' => 'einbindung_loxone_steuerung.png', 'zeilen' => array(
        $ev_bs_zl(1, 'T_VSCHIEBER', 'S3_01', 'S3_01_PARAM', $ev_bs_m),
        $ev_bs_vo_z(2, 'TYP_VOB', 'limitsoc', 1, 'S3_02_PARAM', $ev_bs_ein(1)),
        $ev_bs_zl(3, 'T_VSCHIEBER', 'S3_03', 'S3_03_PARAM', $ev_bs_m),
        $ev_bs_vo_z(4, 'TYP_VOB', 'minsoc', 1, '', $ev_bs_ein(3)),
        $ev_bs_zl(5, 'T_RADIO', 'S3_05', 'S3_05_PARAM', $ev_bs_m),
        $ev_bs_zl(6, 'T_FORMEL', 'S3_06', 'S3_06_PARAM', 'I1 = #5'),
        $ev_bs_vo_z(7, 'TYP_VOB', 'phasen', 1, '', $ev_bs_ein(6)),
        $ev_bs_zl(8, 'T_VSCHIEBER', 'S3_08', 'S3_08_PARAM', $ev_bs_m),
        $ev_bs_vo_z(9, 'TYP_VOB', 'minstrom', 1, '', $ev_bs_ein(8)),
        $ev_bs_zl(10, 'T_VSCHIEBER', 'S3_10', 'S3_10_PARAM', $ev_bs_m),
        $ev_bs_vo_z(11, 'TYP_VOB', 'maxstrom', 1, '', $ev_bs_ein(10)),
        $ev_bs_zl(12, 'T_VSCHIEBER', 'S3_12', 'S3_12_PARAM', $ev_bs_m),
        $ev_bs_vo_z(13, 'TYP_VOB', 'prioritaet', 1, '', $ev_bs_ein(12)),
        $ev_bs_zl(14, 'T_VSCHIEBER', 'S3_14', 'S3_14_PARAM', $ev_bs_m),
        $ev_bs_vo_z(15, 'TYP_VOB', 'smartcostlimit', 1, 'S3_15_PARAM', $ev_bs_ein(14)),
        $ev_bs_zl(16, 'T_VSCHALTER', 'S3_16', 'S3_16_PARAM', $ev_bs_m),
        $ev_bs_vo_z(17, 'TYP_VOB', 'batterieboost', 1, '', $ev_bs_ein(16)),
        $ev_bs_zl(18, 'T_VSCHIEBER', 'S3_18', 'S3_18_PARAM', $ev_bs_m),
        $ev_bs_vo_z(19, 'TYP_VOB', 'plansoc_ziel', 1, '', $ev_bs_ein(18)),
        $ev_bs_zl(20, 'T_VSCHIEBER', 'S3_20', 'S3_20_PARAM', $ev_bs_m),
        $ev_bs_vo_z(21, 'TYP_VOB', 'plansoc_stunden', 1, 'S3_21_PARAM', $ev_bs_ein(20)),
        $ev_bs_zl(22, 'T_VTASTE', 'S3_22', 'S3_22_PARAM', $ev_bs_m),
        $ev_bs_vo_z(23, 'TYP_VOB', 'planaus', 1, '', $ev_bs_ein(22)),
        $ev_bs_zl(24, 'T_VSCHIEBER', 'S3_24', 'S3_24_PARAM', $ev_bs_m),
        $ev_bs_vo_z(25, 'TYP_VOB', 'limitenergie', 1, 'S3_25_PARAM', $ev_bs_ein(24)),
        $ev_bs_zl(26, 'T_VTASTE', 'S3_26', 'S3_26_PARAM', $ev_bs_m),
        $ev_bs_vo_z(27, 'TYP_VOB', 'fahrzeugaus', 1, '', $ev_bs_ein(26)),
        ),
        'schritte' => array(
            array('S3_SCHRITT1', array(ev_t('REITER.EINSTELLUNGEN'), ev_t('EINST.L_STEUERUNG'))),
            array('S3_SCHRITT2', array($ev_bs_vo_t)),
            array('S3_SCHRITT3', array()),
            array('S3_SCHRITT4', array()),
            array('S3_SCHRITT5', array($ev_bs_vo_t, ev_befehl_titel('smartcostlimit', 1), ev_befehl_titel('limitenergie', 1))),
            array('S3_SCHRITT6', array(ev_feld_titel('lp1_limit_soc'))),
        ),
        'hinweise' => array(
            array('S3_H1', array()),
            array('S3_H2', array()),
            array('S3_H3', array()),
            array('S3_H4', array()),
            array('S3_H5', array(ev_befehl_titel('fahrzeug', 1, 1))),
            array('S3_H6', array()),
        )),
    array('kopf' => 'BL_SPEICHER', 'seite' => 'EVCC Hausspeicher und Tarif', 'bild' => 'einbindung_loxone_hausspeicher_tarif.png', 'zeilen' => array(
        $ev_bs_zl(1, 'T_RADIO', 'S4_01', 'S4_01_PARAM', $ev_bs_m),
        $ev_bs_vo_z(2, 'TYP_VOB', 'batteriemodus', 0, 'S4_02_PARAM', $ev_bs_ein(1)),
        $ev_bs_zl(3, 'T_VSCHIEBER', 'S4_03', 'S4_03_PARAM', $ev_bs_m),
        $ev_bs_vo_z(4, 'TYP_VOB', 'prioritaetssoc', 0, '', $ev_bs_ein(3)),
        $ev_bs_zl(5, 'T_VSCHIEBER', 'S4_05', 'S4_05_PARAM', $ev_bs_m),
        $ev_bs_vo_z(6, 'TYP_VOB', 'puffersoc', 0, '', $ev_bs_ein(5)),
        $ev_bs_zl(7, 'T_VSCHIEBER', 'S4_07', 'S4_07_PARAM', $ev_bs_m),
        $ev_bs_vo_z(8, 'TYP_VOB', 'residualleistung', 0, '', $ev_bs_ein(7)),
        $ev_bs_zl(9, 'T_VSCHALTER', 'S4_09', 'S4_09_PARAM', $ev_bs_m),
        $ev_bs_vo_z(10, 'TYP_VOB', 'entladeregelung', 0, '', $ev_bs_ein(9)),
        $ev_bs_zl(11, 'T_VSCHIEBER', 'S4_11', 'S4_11_PARAM', $ev_bs_m),
        $ev_bs_vo_z(12, 'TYP_VOB', 'netzladegrenze', 0, 'S4_12_PARAM', $ev_bs_ein(11)),
        $ev_bs_zl(13, 'T_VTASTE', 'S4_13', 'S4_13_PARAM', $ev_bs_m),
        $ev_bs_vo_z(14, 'TYP_VOB', 'netzladenaus', 0, '', $ev_bs_ein(13)),
        $ev_bs_vi_z(15, 'batteriemodus_nr', 'S4_15_PARAM'),
        $ev_bs_vi_z(16, 'prioritaets_soc', 'S4_16_PARAM'),
        $ev_bs_vi_z(17, 'puffer_soc', 'S4_17_PARAM'),
        $ev_bs_vi_z(18, 'residualleistung_w', 'S4_18_PARAM'),
        $ev_bs_vi_z(19, 'entladeregelung', 'S4_19_PARAM'),
        $ev_bs_vi_z(20, 'netzladen_aktiv', 'S4_20_PARAM'),
        $ev_bs_vi_z(21, 'speicher_kapazitaet_kwh', 'S4_21_PARAM'),
        $ev_bs_vi_z(22, 'speicher_soc', 'S4_22_PARAM'),
        $ev_bs_zl(23, 'T_STATUS', 'S4_23', 'S4_23_PARAM', 'V1 = #15, V2 = #16, V3 = #17, V4 = #18, V5 = #19, V6 = #20, V7 = #21, V8 = #22'),
        $ev_bs_vi_z(24, 'tarif_einspeisung', 'S4_24_PARAM'),
        $ev_bs_vi_z(25, 'tarif_co2', 'S4_25_PARAM'),
        $ev_bs_vi_z(26, 'preis_min_24h', 'S4_26_PARAM'),
        $ev_bs_vi_z(27, 'preis_max_24h', 'S4_27_PARAM'),
        $ev_bs_vi_z(28, 'preis_schnitt_24h', 'S4_28_PARAM'),
        $ev_bs_vi_z(29, 'preis_ok', 'S4_29_PARAM'),
        $ev_bs_zl(30, 'T_STATUS', 'S4_30', 'S4_30_PARAM', 'V1 = #29, V2 = #26, V3 = #27, V4 = #28, V5 = #24, V6 = #25'),
        $ev_bs_vi_z(31, 'gruen_laden', 'S4_31_PARAM'),
        $ev_bs_vi_z(32, 'zusatz_kw', 'S4_32_PARAM'),
        $ev_bs_zl(33, 'T_STATUS', 'S4_33', 'S4_33_PARAM', 'V1 = #31, V2 = #32'),
        ),
        'schritte' => array(
            array('S4_SCHRITT1', array()),
            array('S4_SCHRITT2', array($ev_bs_vo_t, $ev_bs_vi_t)),
            array('S4_SCHRITT3', array()),
            array('S4_SCHRITT4', array(ev_t('LOX.T_PARAMETER'))),
            array('S4_SCHRITT5', array($ev_bs_vo_t, ev_befehl_titel('netzladegrenze', 0))),
        ),
        'hinweise' => array(
            array('S4_H1', array()),
            array('S4_H2', array()),
            array('S4_H3', array()),
            array('S4_H4', array()),
        )),
);
?>
<?php foreach ($ev_bs_seiten as $ev_bs_s) { ?>
<h4 class="sm-h4"><?= ev_e(ev_t('LOX.' . $ev_bs_s['kopf'])) ?></h4>
<div class="sm-hinweis"><b><?= $ev_bs_k('SCHRITTE_TITEL') ?></b>
<ol class="sm-schritte">
<?php foreach ($ev_bs_s['schritte'] as $ev_bs_x) { ?>
<li><?= $ev_bs_k($ev_bs_x[0], $ev_bs_x[1]) ?></li>
<?php } ?>
</ol></div>
<table class="sm-tbl">
<tr><th>#</th><th><?= ev_e(ev_t('LOX.T_BAUSTEIN')) ?></th><th><?= ev_e(ev_t('LOX.T_NAME')) ?></th><th><?= ev_e(ev_t('LOX.T_PARAMETER')) ?></th><th><?= ev_e(ev_t('LOX.T_VERBINDEN')) ?></th></tr>
<?php foreach ($ev_bs_s['zeilen'] as $ev_bs_z) { ?>
<tr><td><?= (int) $ev_bs_z[0] ?></td><td><?= $ev_bs_z[1] ?></td><td><?= $ev_bs_z[2] ?></td><td><?= $ev_bs_z[3] ?></td><td><?= $ev_bs_z[4] ?></td></tr>
<?php } ?>
</table>
<div class="sm-warnung"><b><?= $ev_bs_k('HINWEISE_TITEL') ?></b>
<ul class="sm-schritte">
<?php foreach ($ev_bs_s['hinweise'] as $ev_bs_x) { ?>
<li><?= $ev_bs_k($ev_bs_x[0], $ev_bs_x[1]) ?></li>
<?php } ?>
</ul></div>
<figure class="sm-bild">
<img src="<?= ev_e($ev_bs_s['bild']) ?>" alt="<?= ev_e(sprintf(ev_t('LOX.BILD_ALT'), $ev_bs_s['seite'])) ?>" loading="lazy">
<figcaption><?= ev_e(sprintf(ev_t('LOX.BILD_UNTERSCHRIFT'), $ev_bs_s['seite'])) ?></figcaption>
</figure>
<p class="sm-hilfe"><?= ev_t('LOX.MUSTERPROJEKT') ?></p>
<?php } ?>
</div>

<div class="sm-step"><b><?= ev_e(ev_t('LOX.S_GEGENPROBE_T')) ?></b><br>
<?= ev_t('LOX.S_GEGENPROBE') ?>
<div class="sm-pre"><?= ev_e(ev_endpunkt('status')) ?></div>
<?= ev_t('LOX.S_GEGENPROBE_2') ?>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<?php /* Die Selbstpruefung laeuft NUR, wenn der Reiter Test serverseitig der
         offene ist (O4, seit 0.9.34; Regeln/04 "Selbstpruefungen, die das
         Netz befragen ... laufen nur, wenn der Reiter Test serverseitig der
         offene ist"). Bis 0.9.33 lief sie bei jedem Seitenaufruf, mit zwei
         Anfragen an EVCC, einem Aufruf des eigenen Endpunkts und zwei
         sudo-Aufrufen (Pruefbericht oberflaeche, Befund 7). Sonst steht hier
         ein Verweis; das Skript unten laesst den Reiter dann neu laden. */ ?>
<div class="sm-seite<?= $ev_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test"<?= $ev_tab === 'tab-test' ? '' : ' data-laden="1"' ?>>
<h2><?= ev_e(ev_t('TEST.H_SELBSTTEST')) ?></h2>
<?php if ($ev_tab !== 'tab-test') { ?>
<div class="sm-hinweis"><?= sprintf(ev_t('TEST.NUR_IM_REITER'), 'index.php?form=test') ?></div>
<?php } else { ?>
<p class="sm-hilfe"><?= ev_t('TEST.SELBSTTEST_TEXT') ?></p>
<?php
/* Drei Zaehler, nicht zwei.
 *
 * Bis 0.9.26 stand hier "count - schlecht" als Zahl der bestandenen
 * Pruefungen; "nicht feststellbar" zaehlte damit als bestanden. Gemessen am
 * 04.09.2026: 24 Zeilen, davon 12 Haken, 8 Hinweise und 4 Kreuze - ausgegeben
 * wurde "20 von 24 bestanden". Faellt das letzte Kreuz weg, stuende dort
 * "24 von 24", obwohl ein Drittel nichts gemessen hat. Das ist wortwoertlich
 * der Vorfall vom 17.08.2026 ("22 von 22"), aus dem die Hausregel entstanden
 * ist: eine Zusammenfassung darf nicht besser aussehen als ihr schlechtester
 * Punkt. */
$ev_pr = ev_pruefungen();
$ev_gut = 0;
$ev_hinweis = 0;
$ev_schlecht = 0;
foreach ($ev_pr as $ev_z) {
    if ($ev_z[0] === 1)      { $ev_gut++; }
    elseif ($ev_z[0] === 0)  { $ev_schlecht++; }
    else                     { $ev_hinweis++; }
}
?>
<div class="<?= $ev_schlecht ? 'sm-warnung' : 'sm-hinweis' ?>">
<?= sprintf(ev_t($ev_schlecht ? 'TEST.SELBSTTEST_FEHL' : 'TEST.SELBSTTEST_OK'),
            $ev_gut, $ev_hinweis, $ev_schlecht, count($ev_pr)) ?>
</div>
<table class="sm-tbl">
<tr><th style="width:34px;">&nbsp;</th><th><?= ev_e(ev_t('TEST.T_FRAGE')) ?></th><th><?= ev_e(ev_t('TEST.T_ANTWORT')) ?></th></tr>
<?php foreach ($ev_pr as $ev_z) { ?>
<tr><td style="text-align:center;"><?= $ev_z[0] === 1 ? '<span class="sm-an">&#10003;</span>' : ($ev_z[0] === 0 ? '<span class="sm-aus">&#10007;</span>' : '<span style="color:#888;">i</span>') ?></td>
    <td><?= $ev_z[1] ?></td><td><?= $ev_z[2] ?></td></tr>
<?php } ?>
</table>
<?php
/* Schreiber der letzten 24 Stunden (Energie-1 C1). Frisch aus dem Merker gelesen
   (LOCK_SH), die Einstellungen aus der Datei - nicht aus der X-2-Rueckfuellung. */
$ev_wtw = ev_wache_einstellungen(ev_config());
list($ev_wtz, $ev_wtl) = ev_wache_lesen(); ?>
<h3><?= ev_e(ev_t('TEST.H_WACHE_TABELLE')) ?></h3>
<?php if ($ev_wtz === 'merker') { ?>
<div class="sm-fehler"><?= sprintf(ev_t('TEST.A_WACHE_MERKER'), ev_e(ev_wache_datei())) ?></div>
<?php } elseif (!$ev_wtl) { ?>
<div class="sm-hilfe"><?= ev_t('TEST.A_WACHE_TABELLE_LEER') ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th><?= ev_e(ev_t('TEST.T_W_KENNUNG')) ?></th><th><?= ev_e(ev_t('TEST.T_W_ABSENDER')) ?></th><th><?= ev_e(ev_t('TEST.T_W_BEFEHL')) ?></th><th><?= ev_e(ev_t('TEST.T_W_ZUERST')) ?></th><th><?= ev_e(ev_t('TEST.T_W_ZULETZT')) ?></th><th><?= ev_e(ev_t('TEST.T_W_ANZAHL')) ?></th><th><?= ev_e(ev_t('TEST.T_W_ABGEWIESEN')) ?></th><th><?= ev_e(ev_t('TEST.T_W_FENSTER')) ?></th></tr>
<?php foreach ($ev_wtl as $ev_wx) { ?>
<tr><td><?= $ev_wx['von'] !== '' ? '<span class="sm-mono">' . ev_e($ev_wx['von']) . '</span>' : ev_e(ev_t('TEST.W_OHNE_KENNUNG')) ?></td>
    <td><span class="sm-mono"><?= ev_e($ev_wx['ip'] !== '' ? $ev_wx['ip'] : '?') ?></span></td>
    <td><span class="sm-mono"><?= ev_e($ev_wx['art']) ?></span></td>
    <td><?= ev_e(date('d.m. H:i:s', $ev_wx['erst'])) ?></td>
    <td><?= ev_e(date('d.m. H:i:s', $ev_wx['zuletzt'])) ?></td>
    <td><?= (int) $ev_wx['n'] ?></td>
    <td><?= (int) $ev_wx['abgewiesen'] ?></td>
    <td><?= ev_e(ev_t(abs(time() - $ev_wx['zuletzt']) < 60 * $ev_wtw['wache_fenster_min'] ? 'TEST.W_JA' : 'TEST.W_NEIN')) ?></td></tr>
<?php } ?>
</table>
<?php } ?>
<?php } ?>

<h3><?= ev_e(ev_t('TEST.H_KNOEPFE')) ?></h3>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= ev_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= ev_t('LEGENDE.TECHNIK') ?></span>
</div>

<div class="sm-knopfreihe">
<a class="sm-btn sm-b-lesen" href="/plugins/<?= ev_e($ev_plugin) ?>/index.php?token=<?= ev_e($ev_cfg['aktionstoken']) ?>&amp;aktion=status" target="_blank"><?= ev_e(ev_t('TEST.K_ZEILE')) ?></a>
<a class="sm-btn sm-b-lesen" href="/plugins/<?= ev_e($ev_plugin) ?>/index.php?token=<?= ev_e($ev_cfg['aktionstoken']) ?>&amp;aktion=json" target="_blank"><?= ev_e(ev_t('TEST.K_JSON')) ?></a>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?php echo ev_fmt(); ?>
  <input data-role="none" type="hidden" name="testaktion" value="abruf">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= ev_e(ev_t('TEST.K_ABRUF')) ?></button></form>
</div>

<div class="sm-knopfreihe">
<a class="sm-btn sm-b-technik" href="/plugins/<?= ev_e($ev_plugin) ?>/index.php?token=<?= ev_e($ev_cfg['aktionstoken']) ?>&amp;aktion=roh" target="_blank"><?= ev_e(ev_t('TEST.K_ROH')) ?></a>
<a class="sm-btn sm-b-technik" href="/plugins/<?= ev_e($ev_plugin) ?>/index.php?token=<?= ev_e($ev_cfg['aktionstoken']) ?>&amp;aktion=befehle" target="_blank"><?= ev_e(ev_t('TEST.K_BEFEHLE')) ?></a>
<a class="sm-btn sm-b-technik" href="<?= ev_e(ev_evcc_link()) ?>" target="_blank"><?= ev_e(ev_t('TEST.K_EVCC_OBERFLAECHE')) ?></a>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?php echo ev_fmt(); ?>
  <input data-role="none" type="hidden" name="testaktion" value="endpunkt">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= ev_e(ev_t('TEST.K_ENDPUNKT')) ?></button></form>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?php echo ev_fmt(); ?>
  <input data-role="none" type="hidden" name="testaktion" value="zusatz">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= ev_e(ev_t('TEST.K_ZUSATZ')) ?></button></form>
</div>

<?php /* Die schaltenden Knoepfe unter eigener Ueberschrift, mit einem Satz,
         dass sie sofort wirken (O10, seit 0.9.34; Regeln/04). "EVCC starten"
         stand bis 0.9.33 gruen unter den lesenden. Am Token-Knopf steht die
         Warnung, BEVOR er gedrueckt wird. */ ?>
<h3><?= ev_e(ev_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-warnung"><?= ev_t('TEST.SCHALTEN_TEXT') ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= ev_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?php echo ev_fmt(); ?>
  <input data-role="none" type="hidden" name="testaktion" value="start">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ev_e(ev_t('TEST.K_START')) ?></button></form>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?php echo ev_fmt(); ?>
  <input data-role="none" type="hidden" name="testaktion" value="restart">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ev_e(ev_t('TEST.K_RESTART')) ?></button></form>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?php echo ev_fmt(); ?>
  <input data-role="none" type="hidden" name="testaktion" value="stop">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ev_e(ev_t('TEST.K_STOP')) ?></button></form>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?php echo ev_fmt(); ?>
  <input data-role="none" type="hidden" name="testaktion" value="ansage">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ev_e(ev_t('DURCHSAGE.K_TEST')) ?></button></form>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?php echo ev_fmt(); ?>
  <input data-role="none" type="hidden" name="testaktion" value="mqtt">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ev_e(ev_t('TEST.K_MQTT')) ?></button></form>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?php echo ev_fmt(); ?>
  <input data-role="none" type="hidden" name="testaktion" value="token">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ev_e(ev_t('TEST.K_TOKEN')) ?></button></form>
</div>
<div class="sm-hilfe"><?= ev_t('TEST.K_TOKEN_WARNUNG') ?></div>
<div class="sm-knopfreihe">
<?php if (!empty($ev_cfg['update_ein'])) { ?>
<form action="index.php" method="post"
      onsubmit="return confirm(<?= ev_e(json_encode(strip_tags(html_entity_decode(ev_t('TEST.K_UPDATE_FRAGE'), ENT_QUOTES, 'UTF-8')))) ?>)">
  <?php echo ev_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <input data-role="none" type="hidden" name="testaktion" value="update">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ev_e(ev_t('TEST.K_UPDATE')) ?></button></form>
<?php } ?>
</div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $ev_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= ev_e(ev_t('LOG.H_TITEL')) ?></h2>
<?php
/* Die Liste des Rahmens zusaetzlich zur eigenen Datei: LoxBerry fuehrt die
   gedrehten Protokolle und die Loglevel-Einstellung dort. 19 Linien im
   Bestand tun das; bis 0.9.10 tat EVCC es nicht. Faellt die Funktion aus
   (Aufruf ausserhalb des Rahmens), bleibt die eigene Anzeige darunter. */
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    $ev_liste = @LBWeb::loglist_html(array('PLUGINNAME' => $ev_plugin, 'ALLPLUGINS' => 0));
    if (is_string($ev_liste) && trim($ev_liste) !== '') { echo $ev_liste; }
}
?>
<h3><?= ev_e(ev_t('LOG.H_EIGEN')) ?></h3>
<?php
$ev_lf = $ev_p['log'];
$ev_zeilen = is_file($ev_lf) ? array_slice(file($ev_lf, FILE_IGNORE_NEW_LINES) ?: array(), -200) : array();
?>
<?php if (!$ev_zeilen) { ?>
<div class="sm-hinweis"><?= ev_t('LOG.LEER') ?></div>
<?php } else { ?>
<div class="sm-pre" style="max-height:480px;"><?= ev_e(implode("\n", $ev_zeilen)) ?></div>
<?php } ?>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= ev_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
  <?php echo ev_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-log">
  <input data-role="none" type="hidden" name="clearlog" value="1">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:400;margin-right:10px;">
    <input data-role="none" type="checkbox" name="clearlog_ok" value="1">
    <?= ev_e(ev_t('LOG.L_LEEREN_OK')) ?>
  </label>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ev_e(ev_t('LOG.K_LEEREN')) ?></button>
</form>
</div>
<div class="sm-hilfe"><?= sprintf(ev_t('LOG.EVCC_HINWEIS'), '<span class="sm-mono">journalctl -u evcc -n 100</span>') ?></div>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) {
			// Der Reiter Test pruebt nur, wenn der Server ihn ausliefert (O4):
			// ist er noch nicht geladen, folgt der Klick dem Link.
			var s = document.getElementById(r.dataset.ziel);
			if (s && s.dataset.laden === '1') { return; }
			e.preventDefault(); zeige(r.dataset.ziel);
		});
	});
	zeige(<?= json_encode($ev_tab) ?>);
})();
</script>
<?php
if (class_exists('LBWeb', false)) { LBWeb::lbfooter(); }
