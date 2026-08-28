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

/* Wer einen Reiter hinzufuegt, muss DREI Stellen mitziehen: die Reiterleiste,
   den Bereich (sm-seite mit gleicher id) und diese Positivliste. Fehlt der
   Name hier, springt die Seite nach jedem Absenden zurueck auf Einstellungen.

   Die Leiste bleibt bewusst AUSGESCHRIEBEN und wird nicht aus ev_reiter()
   erzeugt: eine PHP-Schleife macht hausstandard_pruefen.py blind, und genau
   dieser Fehler steht zweimal in REGELN_1. Die Auflaesung ist nicht
   "Schleife oder Hand", sondern ausschreiben UND die Uebereinstimmung im
   Reiter Test nachpruefen lassen - das tut jetzt ev_pruefung_reiter(). */
$ev_muster = '/^tab-(settings|mqtt|loxone|test|log)$/';
$ev_tab = preg_match($ev_muster, (string) (isset($_POST['activetab']) ? $_POST['activetab'] : ''))
    ? (string) $_POST['activetab'] : 'tab-settings';
if (isset($_GET['form']) && preg_match($ev_muster, 'tab-' . $_GET['form'])) {
    $ev_tab = 'tab-' . $_GET['form'];
}

$ev_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';
$ev_meldungen = array();
$ev_fehler = array();

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

$ev_ausgabe = '';

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

/* ================= Protokoll leeren ================= */
if ($ev_post && isset($_POST['clearlog'])) {
    $ev_lf = ev_paths()['log'];
    @mkdir(dirname($ev_lf), 0775, true);
    @file_put_contents($ev_lf, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Oberflaeche)\n");
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
    } else {
        $ev_cfg['url'] = rtrim($ev_url, '/');
    }

    // Das Passwort wird NICHT gefiltert - es darf Sonderzeichen enthalten.
    // Ein leeres Feld loescht nichts: sonst waere das Passwort nach jedem
    // Speichern weg, weil es aus Sicherheitsgruenden nicht angezeigt wird.
    if (isset($_POST['passwort']) && (string) $_POST['passwort'] !== '') {
        $ev_cfg['passwort'] = (string) $_POST['passwort'];
    }
    if (!empty($_POST['passwort_loeschen'])) { $ev_cfg['passwort'] = ''; }

    $ev_takt = (int) (isset($_POST['takt']) ? $_POST['takt'] : 15);
    if ($ev_takt < 5 || $ev_takt > 60) {
        $ev_fehler[] = sprintf(ev_t('EINST.FEHLER_BEREICH'), ev_t('EINST.L_TAKT'), 5, 60);
    } else {
        $ev_cfg['takt'] = $ev_takt;
    }

    $ev_lp = (int) (isset($_POST['ladepunkte']) ? $_POST['ladepunkte'] : 2);
    if ($ev_lp < 0 || $ev_lp > EV_LADEPUNKTE) {
        $ev_fehler[] = sprintf(ev_t('EINST.FEHLER_BEREICH'), ev_t('EINST.L_LADEPUNKTE'), 0, EV_LADEPUNKTE);
    } else {
        $ev_cfg['ladepunkte'] = $ev_lp;
    }

    $ev_fz = (int) (isset($_POST['fahrzeuge']) ? $_POST['fahrzeuge'] : 2);
    if ($ev_fz < 0 || $ev_fz > EV_FAHRZEUGE) {
        $ev_fehler[] = sprintf(ev_t('EINST.FEHLER_BEREICH'), ev_t('EINST.L_FAHRZEUGE'), 0, EV_FAHRZEUGE);
    } else {
        $ev_cfg['fahrzeuge'] = $ev_fz;
    }

    /* mqtt_ein und mqtt_topic werden hier NICHT mehr angefasst: sie
     * wohnen im Reiter MQTT und haben dort ein eigenes Formular. Die
     * Konfiguration kommt aus ev_config(), die Werte ueberleben also
     * unveraendert. */

    $ev_cfg['tarife_ein'] = isset($_POST['tarife_ein']) ? 1 : 0;
    $ev_cfg['steuerung_ein'] = isset($_POST['steuerung_ein']) ? 1 : 0;
    $ev_cfg['update_ein'] = isset($_POST['update_ein']) ? 1 : 0;

    if (!$ev_fehler) {
        if (ev_config_write($ev_cfg)) {
            $ev_meldungen[] = ev_t('EINST.GESPEICHERT');
            ev_log('Einstellungen gespeichert');
        } else {
            $ev_fehler[] = sprintf(ev_t('EINST.FEHLER_SPEICHERN'), ev_e(ev_paths()['config']));
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
    $ev_mcfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    $ev_mtopic = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
        (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : '')));
    if ($ev_mtopic === '' || !preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $ev_mtopic)) {
        $ev_fehler[] = ev_t('EINST.FEHLER_TOPIC');
    } else {
        $ev_mcfg['mqtt_topic'] = trim($ev_mtopic, '/');
    }
    if (!$ev_fehler) {
        if (ev_config_write($ev_mcfg)) {
            $ev_meldungen[] = ev_t('EINST.GESPEICHERT');
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
    $ev_js = json_encode(ev_config(),
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
    } elseif ((int) $_FILES['ev_sicherung']['size'] > 262144) {
        $ev_fehler[] = ev_t('EINST.SICH_ZU_GROSS');
    } else {
        list($ev_neu, $ev_mangel, $ev_n) = ev_sicherung_lesen(
            (string) @file_get_contents($_FILES['ev_sicherung']['tmp_name']));
        if ($ev_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. */
            $ev_fehler[] = ev_t('EINST.SICH_ABGELEHNT') . ' '
                            . implode(' ', $ev_mangel);
        } elseif (ev_config_write($ev_neu)) {
            $ev_meldungen[] = sprintf(ev_t('EINST.SICH_UEBERNOMMEN'), $ev_n);
        } else {
            $ev_fehler[] = ev_t('EINST.SICH_SCHREIBFEHLER');
        }
    }
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
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 200px; }

/* Nachgetragene Definitionen (CSS-Luecken-Durchgang 13.08.2026):
   benutzt, aber nie definiert - wortgleich aus der Hausstandard-Vorlage
   bzw. der Referenzimplementierung uebernommen. */
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-warn { background: #fdf3e3; border: 1px solid #e0620d; }
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

<?php
$ev_da = ev_dienst_vorhanden();
$ev_laeuft = ev_dienst_laeuft();
$ev_stand = ev_state();
?>
<div class="sm-kacheln">
  <div class="sm-kachel"><?= ev_e(ev_t('KACHEL.EVCC')) ?>
    <b class="<?= $ev_laeuft ? 'sm-an' : 'sm-aus' ?>"><?= ev_e($ev_laeuft ? ev_t('ALLG.LAEUFT') : ev_t('ALLG.GESTOPPT')) ?></b></div>
  <div class="sm-kachel"><?= ev_e(ev_t('KACHEL.VERBINDUNG')) ?>
    <b class="<?= !empty($ev_stand['ok']) ? 'sm-an' : 'sm-aus' ?>"><?= ev_e(!empty($ev_stand['ok']) ? ev_t('ALLG.OK') : ev_t('ALLG.FEHLER')) ?></b></div>
  <div class="sm-kachel"><?= ev_e(ev_t('KACHEL.VERSION')) ?>
    <b style="font-size:1.0em;"><?= ev_e($ev_da ? ev_dienst_version() : '-') ?></b></div>
</div>

<?php if (!$ev_da) { ?>
<div class="sm-warnung"><?= ev_t('EINST.NICHT_INSTALLIERT') ?></div>
<?php } ?>

<?php
/* Die wichtigste Meldung dieser Seite, wenn sie zutrifft.
   EVCC kann laufen, antworten - und trotzdem nichts liefern, weil es mit
   einem Startfehler abgebrochen hat. Bis 0.9.12 stand hier nur die Kachel
   "Verbindung: in Ordnung", und die war sogar richtig. Nur eben nicht die
   ganze Wahrheit. */
$ev_ein = ev_einrichtung($ev_stand);
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

<h2><?= ev_e(ev_t('EINST.H_VERBINDUNG')) ?></h2>
<div class="sm-feld">
  <label for="url"><?= ev_e(ev_t('EINST.L_URL')) ?></label>
  <input data-role="none" type="text" id="url" name="url" value="<?= ev_e($ev_cfg['url']) ?>" placeholder="http://127.0.0.1:7070">
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
  <input data-role="none" type="password" id="passwort" name="passwort" value="" placeholder="<?= ev_e($ev_cfg['passwort'] !== '' ? ev_t('EINST.P_GESETZT') : ev_t('EINST.P_LEER')) ?>">
  <div class="sm-hilfe"><?= ev_t('EINST.H_PASSWORT') ?></div>
  <label style="display:inline-flex;align-items:center;gap:8px;margin-top:6px;font-weight:400;">
    <input data-role="none" type="checkbox" name="passwort_loeschen" value="1">
    <?= ev_e(ev_t('EINST.L_PASSWORT_LOESCHEN')) ?>
  </label>
</div>
<div class="sm-row">
  <div class="sm-feld">
    <label for="takt"><?= ev_e(ev_t('EINST.L_TAKT')) ?></label>
    <input data-role="none" type="number" id="takt" name="takt" value="<?= (int) $ev_cfg['takt'] ?>" min="5" max="60">
    <div class="sm-hilfe"><?= ev_t('EINST.H_TAKT') ?></div>
  </div>
  <div class="sm-feld">
    <label for="ladepunkte"><?= ev_e(ev_t('EINST.L_LADEPUNKTE')) ?></label>
    <input data-role="none" type="number" id="ladepunkte" name="ladepunkte" value="<?= (int) $ev_cfg['ladepunkte'] ?>" min="0" max="<?= EV_LADEPUNKTE ?>">
  </div>
  <div class="sm-feld">
    <label for="fahrzeuge"><?= ev_e(ev_t('EINST.L_FAHRZEUGE')) ?></label>
    <input data-role="none" type="number" id="fahrzeuge" name="fahrzeuge" value="<?= (int) $ev_cfg['fahrzeuge'] ?>" min="0" max="<?= EV_FAHRZEUGE ?>">
    <div class="sm-hilfe"><?= ev_t('EINST.H_FAHRZEUGE') ?></div>
  </div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:400;">
    <input data-role="none" type="checkbox" name="tarife_ein" value="1" <?= !empty($ev_cfg['tarife_ein']) ? 'checked' : '' ?>>
    <?= ev_e(ev_t('EINST.L_TARIFE')) ?>
  </label>
</div>

<h2><?= ev_e(ev_t('EINST.H_STEUERUNG')) ?></h2>
<div class="sm-warnung"><?= ev_t('EINST.H_STEUERUNG_TEXT') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:600;">
    <input data-role="none" type="checkbox" name="steuerung_ein" value="1" <?= !empty($ev_cfg['steuerung_ein']) ? 'checked' : '' ?>>
    <?= ev_e(ev_t('EINST.L_STEUERUNG')) ?>
  </label>
</div>

<h2><?= ev_e(ev_t('EINST.H_UPDATE')) ?></h2>
<div class="sm-warnung"><?= ev_t('EINST.H_UPDATE_TEXT') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:600;">
    <input data-role="none" type="checkbox" name="update_ein" value="1" <?= !empty($ev_cfg['update_ein']) ? 'checked' : '' ?>>
    <?= ev_e(ev_t('EINST.L_UPDATE')) ?>
  </label>
  <div class="sm-hilfe"><?= ev_t('EINST.H_UPDATE_HILFE') ?></div>
</div>

<?php /* MQTT stand hier bis zu dieser Fassung. Es wohnt jetzt
         vollstaendig im Reiter MQTT - eine Sache, eine Stelle. */ ?>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= ev_t('LEGENDE.AKTION') ?></span>
<span><i class="sm-punkt sm-b-lesen"></i> <?= ev_t('LEGENDE.LESEN') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ev_e(ev_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= ev_t('EINST.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= ev_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= ev_t('EINST.SICH_WARNUNG') ?></div>
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
<?php /* Hier stand bis 0.9.10 eine zweite, eingebettete Autostart-Pruefung
         mit hart verdrahtetem /opt/loxberry. Sie las den RICHTIGEN Schluessel
         (Gatewayautostart), waehrend ev_mqtt_zustand() 21 Zeilen weiter unten
         den falschen las - zwei Wege fuer dieselbe Frage, einer davon falsch.
         Jetzt gibt es nur noch ev_mqtt_zustand(), und der stimmt. */ ?>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:400;">
    <input data-role="none" type="checkbox" name="mqtt_ein" value="1" <?= !empty($ev_cfg['mqtt_ein']) ? 'checked' : '' ?>>
    <?= ev_e(ev_t('EINST.L_MQTT_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="mqtt_topic"><?= ev_e(ev_t('EINST.L_MQTT_TOPIC')) ?></label>
  <input data-role="none" type="text" id="mqtt_topic" name="mqtt_topic" value="<?= ev_e($ev_cfg['mqtt_topic']) ?>" placeholder="evcc2lox">
  <div class="sm-hilfe"><?= ev_t('EINST.H_MQTT_TOPIC') ?></div>
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
<table class="sm-tbl">
<tr><th><?= ev_e(ev_t('MQTT.T_THEMA')) ?></th><th><?= ev_e(ev_t('MQTT.T_BEDEUTUNG')) ?></th><th><?= ev_e(ev_t('MQTT.T_EVCC')) ?></th></tr>
<?php foreach (ev_felder() as $ev_name => $ev_d) {
    $ev_txt = ev_t($ev_d['text']);
    if (isset($ev_d['nr'])) { $ev_txt = sprintf($ev_txt, (int) $ev_d['nr']); } ?>
<tr>
  <td><span class="sm-mono"><?= ev_e($ev_cfg['mqtt_topic']) ?>/<?= ev_e($ev_name) ?></span></td>
  <td><?= $ev_txt ?><?= $ev_d['einheit'] !== '' ? ' <span class="sm-mono">' . ev_e($ev_d['einheit']) . '</span>' : '' ?></td>
  <td><span class="sm-mono"><?= ev_e($ev_d['mqtt'] !== '' ? $ev_d['mqtt'] : '-') ?></span></td>
</tr>
<?php } ?>
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
<table class="sm-tbl">
<tr><th><?= ev_e(ev_t('LOX.T_BEFEHL')) ?></th><th><?= ev_e(ev_t('LOX.T_BEDEUTUNG')) ?></th><th><?= ev_e(ev_t('LOX.T_HERKUNFT')) ?></th></tr>
<?php
/* Erzeugt aus ev_befehle() - derselben Quelle, aus der der Endpunkt seine
   Pruefung und die Vorlage ihre Ausgaenge nimmt. Bis 0.9.10 stand die Liste
   hier ein drittes Mal von Hand. */
foreach (ev_befehle() as $ev_a => $ev_b) {
    $ev_adr = '&amp;aktion=' . $ev_a . ($ev_b['ebene'] === 'lp' ? '&amp;lp=1' : '');
    if ($ev_b['pruef'] === 'ohne')          { $ev_adr .= ''; }
    elseif ($ev_b['pruef'] === 'schalter')  { $ev_adr .= '&amp;wert=1|0'; }
    elseif ($ev_b['pruef'] === 'plan')      { $ev_adr .= '&amp;wert=&lt;v.0&gt;&amp;stunden=&lt;v.1&gt;'; }
    else                                    { $ev_adr .= '&amp;wert=&lt;v.0&gt;'; }
    $ev_bed = ev_t($ev_b['text']);
    if ($ev_a === 'modus')        { $ev_bed .= ' (0=off, 1=now, 2=minpv, 3=pv)'; }
    if ($ev_a === 'batteriemodus'){ $ev_bed .= ' (0=normal, 1=hold, 2=charge)'; }
?>
<tr>
  <td><span class="sm-mono"><?= $ev_adr ?></span><?= $ev_b['methode'] === 'DELETE' ? ' <b>(DELETE)</b>' : '' ?></td>
  <td><?= $ev_bed ?></td>
  <td><?= $ev_b['quelle'] === 'doku'
        ? '<b>' . ev_e(ev_t('LOX.Q_DOKU')) . '</b>'
        : ev_e(ev_t('LOX.Q_GEMESSEN')) ?></td>
</tr>
<?php } ?>
</table>
<div class="sm-warnung"><?= ev_t('LOX.BEFEHLE_DOKU_WARNUNG') ?></div>
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
</table>
<div class="sm-hinweis"><?= ev_t('SPOT.RANG_REZEPT') ?></div>
</div>

<div class="sm-step"><b><?= ev_e(ev_t('SPOT.H_GRENZE')) ?></b><br>
<?= ev_t('SPOT.GRENZE_TEXT') ?>
<?php if (!empty($ev_cfg['steuerung_ein'])) { ?>
<div class="sm-pre"><?= ev_e(ev_endpunkt('smartcostlimit')) ?>&amp;lp=1&amp;wert=&lt;v.3&gt;</div>
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
<table class="sm-tbl">
<tr><th>#</th><th><?= ev_e(ev_t('LOX.T_BAUSTEIN')) ?></th><th><?= ev_e(ev_t('LOX.T_NAME')) ?></th><th><?= ev_e(ev_t('LOX.T_PARAMETER')) ?></th><th><?= ev_e(ev_t('LOX.T_VERBINDEN')) ?></th></tr>
<tr><td>1</td><td><?= ev_t('BAUSTEIN.B1_TYP') ?></td><td><span class="sm-mono">EVCC</span></td><td><?= ev_t('BAUSTEIN.B1_PARAM') ?></td><td><?= ev_t('BAUSTEIN.B1_VERB') ?></td></tr>
<tr><td>2</td><td><?= ev_t('BAUSTEIN.B2_TYP') ?></td><td><span class="sm-mono">Energiemanager</span></td><td><?= ev_t('BAUSTEIN.B2_PARAM') ?></td><td><?= ev_t('BAUSTEIN.B2_VERB') ?></td></tr>
<tr><td>3</td><td><?= ev_t('BAUSTEIN.B3_TYP') ?></td><td><span class="sm-mono">Wallbox Status</span></td><td><?= ev_t('BAUSTEIN.B3_PARAM') ?></td><td><?= ev_t('BAUSTEIN.B3_VERB') ?></td></tr>
<tr><td>4</td><td><?= ev_t('BAUSTEIN.B4_TYP') ?></td><td><span class="sm-mono">Ladung fertig</span></td><td><?= ev_t('BAUSTEIN.B4_PARAM') ?></td><td><?= ev_t('BAUSTEIN.B4_VERB') ?></td></tr>
<tr><td>5</td><td><?= ev_t('BAUSTEIN.B5_TYP') ?></td><td><span class="sm-mono">EVCC stumm</span></td><td><?= ev_t('BAUSTEIN.B5_PARAM') ?></td><td><?= ev_t('BAUSTEIN.B5_VERB') ?></td></tr>
<tr><td>6</td><td><?= ev_t('BAUSTEIN.B6_TYP') ?></td><td><span class="sm-mono">EVCC Stoerung</span></td><td><?= ev_t('BAUSTEIN.B6_PARAM') ?></td><td><?= ev_t('BAUSTEIN.B6_VERB') ?></td></tr>
<tr><td>7</td><td><?= ev_t('BAUSTEIN.B7_TYP') ?></td><td><span class="sm-mono">EVCC Meldung</span></td><td><?= ev_t('BAUSTEIN.B7_PARAM') ?></td><td><?= ev_t('BAUSTEIN.B7_VERB') ?></td></tr>
<tr><td>8</td><td><?= ev_t('BAUSTEIN.B8_TYP') ?></td><td><span class="sm-mono">Guenstige Stunde</span></td><td><?= ev_t('BAUSTEIN.B8_PARAM') ?></td><td><?= ev_t('BAUSTEIN.B8_VERB') ?></td></tr>
<tr><td>9</td><td><?= ev_t('BAUSTEIN.B9_TYP') ?></td><td><span class="sm-mono">Abfahrtszeit</span></td><td><?= ev_t('BAUSTEIN.B9_PARAM') ?></td><td><?= ev_t('BAUSTEIN.B9_VERB') ?></td></tr>
<tr><td>10</td><td><?= ev_t('BAUSTEIN.B10_TYP') ?></td><td><span class="sm-mono">Solarprognose</span></td><td><?= ev_t('BAUSTEIN.B10_PARAM') ?></td><td><?= ev_t('BAUSTEIN.B10_VERB') ?></td></tr>
</table>
<?= ev_t('LOX.S4_ERLAEUTERUNG') ?>
</div>

<div class="sm-step"><b><?= ev_e(ev_t('LOX.S_GEGENPROBE_T')) ?></b><br>
<?= ev_t('LOX.S_GEGENPROBE') ?>
<div class="sm-pre"><?= ev_e(ev_endpunkt('status')) ?></div>
<?= ev_t('LOX.S_GEGENPROBE_2') ?>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $ev_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= ev_e(ev_t('TEST.H_SELBSTTEST')) ?></h2>
<p class="sm-hilfe"><?= ev_t('TEST.SELBSTTEST_TEXT') ?></p>
<?php
$ev_pr = ev_pruefungen();
$ev_schlecht = 0;
foreach ($ev_pr as $ev_z) { if ($ev_z[0] === 0) { $ev_schlecht++; } }
?>
<div class="<?= $ev_schlecht ? 'sm-warnung' : 'sm-hinweis' ?>">
<?= sprintf(ev_t($ev_schlecht ? 'TEST.SELBSTTEST_FEHL' : 'TEST.SELBSTTEST_OK'),
            count($ev_pr) - $ev_schlecht, count($ev_pr)) ?>
</div>
<table class="sm-tbl">
<tr><th style="width:34px;">&nbsp;</th><th><?= ev_e(ev_t('TEST.T_FRAGE')) ?></th><th><?= ev_e(ev_t('TEST.T_ANTWORT')) ?></th></tr>
<?php foreach ($ev_pr as $ev_z) { ?>
<tr><td style="text-align:center;"><?= $ev_z[0] === 1 ? '<span class="sm-an">&#10003;</span>' : ($ev_z[0] === 0 ? '<span class="sm-aus">&#10007;</span>' : '<span style="color:#888;">i</span>') ?></td>
    <td><?= $ev_z[1] ?></td><td><?= $ev_z[2] ?></td></tr>
<?php } ?>
</table>

<h3><?= ev_e(ev_t('TEST.H_KNOEPFE')) ?></h3>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= ev_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= ev_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= ev_t('LEGENDE.AKTION') ?></span>
</div>

<div class="sm-knopfreihe">
<a class="sm-btn sm-b-lesen" href="/plugins/<?= ev_e($ev_plugin) ?>/index.php?token=<?= ev_e($ev_cfg['aktionstoken']) ?>&amp;aktion=status" target="_blank"><?= ev_e(ev_t('TEST.K_ZEILE')) ?></a>
<a class="sm-btn sm-b-lesen" href="/plugins/<?= ev_e($ev_plugin) ?>/index.php?token=<?= ev_e($ev_cfg['aktionstoken']) ?>&amp;aktion=json" target="_blank"><?= ev_e(ev_t('TEST.K_JSON')) ?></a>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?php echo ev_fmt(); ?>
  <input data-role="none" type="hidden" name="testaktion" value="abruf">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= ev_e(ev_t('TEST.K_ABRUF')) ?></button></form>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?php echo ev_fmt(); ?>
  <input data-role="none" type="hidden" name="testaktion" value="start">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= ev_e(ev_t('TEST.K_START')) ?></button></form>
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

<div class="sm-knopfreihe">
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
  <input data-role="none" type="hidden" name="testaktion" value="mqtt">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ev_e(ev_t('TEST.K_MQTT')) ?></button></form>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <?php echo ev_fmt(); ?>
  <input data-role="none" type="hidden" name="testaktion" value="token">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= ev_e(ev_t('TEST.K_TOKEN')) ?></button></form>
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
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	zeige(<?= json_encode($ev_tab) ?>);
})();
</script>
<?php
if (class_exists('LBWeb', false)) { LBWeb::lbfooter(); }
