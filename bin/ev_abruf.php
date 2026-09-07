#!/usr/bin/env php
<?php
/**
 * EVCC fuer LoxBerry - Datenabruf
 *
 * Laeuft aus cron.01min. Weil eine Minute fuer die Anzeige einer laufenden
 * Ladung zu grob ist, schleift das Skript INNERHALB der Minute im
 * eingestellten Takt weiter (5 bis 60 s) und beendet sich vor dem naechsten
 * Cron-Lauf von selbst. So braucht es keinen dauerhaften Dienst, der beim
 * Update haengen bleiben kann.
 *
 * Aufrufe:
 *   ev_abruf.php cron     eine Minute lang im Takt abrufen (aus dem Cron)
 *   ev_abruf.php einmal   genau ein Abruf, dann Schluss
 *   ev_abruf.php test     ein Abruf mit Klartextausgabe
 *
 * Der Abruf gehoert NICHT in die Oberflaeche und nicht in den Endpunkt -
 * ein Plugin, das beim Klick auf die Seite Daten holt, ist falsch gebaut.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Die Bibliothek ueber eine Kandidatenliste finden - NICHT ueber eine feste
 * Zahl von ".." nach oben.
 *
 * Im entpackten Archiv liegen bin/ und webfrontend/ nebeneinander, auf dem
 * installierten LoxBerry in GETRENNTEN Baeumen:
 *
 *     /opt/loxberry/bin/plugins/<ordner>/ev_abruf.php
 *     /opt/loxberry/webfrontend/htmlauth/plugins/<ordner>/ev_lib.php
 *
 * dirname(__DIR__) ergibt dort /opt/loxberry/bin/plugins - gesucht wurde also
 * /opt/loxberry/bin/plugins/webfrontend/htmlauth/ev_lib.php. Die gibt es nicht: der
 * Dienst brach bei JEDEM Cron-Lauf mit einem fatalen Fehler ab, und weil die
 * Cron-Zeile nach /dev/null schreibt, stand das nirgends.
 *
 * Gefunden am 16.08.2026 mit Werkzeuge/installationslage_pruefen.py, nachdem
 * dieselbe Zeile den Hintergrunddienst des Abfahrts-Assistenten von 1.5.0 bis
 * 1.5.7 lahmgelegt hatte.
 */
$ev_lb = getenv('LBHOMEDIR');
$ev_ordner = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
$ev_kandidaten = array();
if ($ev_lb) {
    $ev_kandidaten[] = $ev_lb . '/webfrontend/htmlauth/plugins/' . $ev_ordner . '/ev_lib.php';
}
// installiert, ohne dass die Umgebungsvariablen gesetzt waeren:
// .../bin/plugins/<ordner>  ->  .../webfrontend/htmlauth/plugins/<ordner>
$ev_kandidaten[] = dirname(dirname(dirname(__DIR__)))
                 . '/webfrontend/htmlauth/plugins/' . basename(__DIR__) . '/ev_lib.php';
// entpacktes Archiv: bin/ und webfrontend/ liegen nebeneinander
$ev_kandidaten[] = dirname(__DIR__) . '/webfrontend/htmlauth/ev_lib.php';

$ev_lib = '';
foreach ($ev_kandidaten as $ev_kand) {
    if (is_file($ev_kand)) { $ev_lib = $ev_kand; break; }
}
if ($ev_lib === '') {
    fwrite(STDERR, "EVCC: ev_lib.php nicht gefunden. Gesucht wurde in:\n");
    foreach ($ev_kandidaten as $ev_kand) { fwrite(STDERR, '  ' . $ev_kand . "\n"); }
    exit(1);
}
require_once $ev_lib;

$modus = isset($argv[1]) ? (string) $argv[1] : 'einmal';

/** Ein Durchlauf: holen, umrechnen, veroeffentlichen. */
function ev_durchlauf($laut = false)
{
    $st = ev_state(true);
    /* Preisvorschau, Solarprognose und Statistik nachziehen.
     *
     * Bis 0.9.26 stand diese Zeile hier NICHT. Einziger Aufrufer von
     * ev_zusatz_holen() war der Knopf im Reiter Test - im Betrieb blieben
     * damit neun Felder dauerhaft auf 0: PROGNOSE_HEUTE/MORGEN/UEBERMORGEN
     * und PREIS_MIN/MAX/SCHNITT/RANG/STUNDEN/GUENSTIGSTE_STUNDE. Gemessen am
     * 04.09.2026 gegen ein antwortendes EVCC: ein Dienstlauf stellte genau
     * eine Anfrage (/api/state), und die Statuszeile trug fuer alle neun
     * eine Null. Die Oberflaeche empfiehlt zugleich woertlich
     * "EVCC_PREIS_RANG kleiner gleich 6" - bei Rang 0 ist das immer wahr,
     * also Dauerfreigabe. Ein echter Rang faengt bei 1 an.
     *
     * Die Funktion bremst sich ueber EV_ZUSATZ_ALTER (300 s) selbst; sie
     * fragt also nicht bei jedem Durchlauf nach. */
    ev_zusatz_holen();
    $werte = ev_werte($st);
    $n = ev_mqtt_publish($werte);
    if ($laut) {
        printf("Abruf: %s%s\n", $st['ok'] ? 'ok' : 'FEHLGESCHLAGEN',
            $st['fehler'] !== '' ? ' (' . $st['fehler'] . ')' : '');
        printf("MQTT: %d Themen gesendet\n\n", $n);
        $ohne = array();
        foreach (ev_felder() as $name => $d) {
            $pfad = $werte[$name]['pfad'];
            printf("  %-22s %-14s %s\n", $name, $werte[$name]['wert'],
                $pfad !== '' ? $pfad : ($d['pfade'] ? 'NICHT GEFUNDEN' : '-'));
            if ($pfad === '' && $d['pfade']) { $ohne[] = $name; }
        }
        if ($ohne) {
            echo "\nDiese Felder waren in der Antwort von EVCC nicht zu finden:\n  "
                . implode(', ', $ohne) . "\n"
                . "Das ist kein Fehler, wenn das Geraet fehlt - eine Anlage ohne\n"
                . "Speicher hat keinen Ladestand. Fehlt etwas, das es geben muesste,\n"
                . "zeigt 'aktion=roh' die unveraenderte Antwort von EVCC.\n";
        }
    }
    return $st['ok'] ? 1 : 0;
}

if ($modus === 'test') {
    $cfg = ev_config();
    echo "EVCC-Adresse: " . $cfg['url'] . "\n";
    echo "Takt: " . $cfg['takt'] . " s\n\n";
    exit(ev_durchlauf(true) ? 0 : 1);
}

if ($modus !== 'cron') {
    exit(ev_durchlauf(false) ? 0 : 1);
}

/* ---- Cron-Betrieb ---- */

// Nur ein Lauf gleichzeitig. Ohne Sperre stapeln sich bei einer langsamen
// EVCC-Antwort die Durchlaeufe, bis nichts mehr geht.
/* Die Fehlerdatei des Cron kappen, bevor der Lauf beginnt.
 *
 * cron.01min leitet die FEHLERausgabe nach log/plugins/<ordner>/cron.err -
 * richtig so, denn bis 0.9.8 ging genau diese Auskunft nach /dev/null und
 * verdeckte einen Fehler ueber mehrere Fassungen. Nur wurde die Datei
 * nirgends begrenzt: bei einer Dauerstoerung waechst sie jede Minute weiter,
 * und log/ liegt auf dem LoxBerry auf einer Ramdisk. Dieselbe Regel wie fuer
 * evcc.log, an derselben Stelle im Code wie der Lauf, der sie fuellt. */
$ev_cronerr = dirname(ev_paths()['log']) . '/cron.err';
clearstatcache(true, $ev_cronerr);
if (is_file($ev_cronerr) && filesize($ev_cronerr) > 262144) {
    $ev_rest = array_slice(file($ev_cronerr, FILE_IGNORE_NEW_LINES) ?: array(), -200);
    @file_put_contents($ev_cronerr, implode("\n", $ev_rest) . "\n");
}

$sperre = ev_tmpdir() . '/abruf.lock';
$fh = @fopen($sperre, 'c');
if ($fh === false) {
    // Bis 0.9.0 endete der Lauf hier ohne ein Wort. Der Cron laeuft weiter,
    // im Protokoll steht nichts, und die Werte in Loxone stehen still - ohne
    // dass irgendwo ablesbar waere, warum. Typische Ursache: /tmp ist voll
    // oder die Datei gehoert nach einem Handgriff als root nicht mehr
    // loxberry.
    ev_log_wenn_neu('sperre', 'Sperrdatei ' . $sperre . ' laesst sich nicht '
        . 'oeffnen - der zeitgesteuerte Abruf laeuft NICHT. Pruefen: '
        . 'Platz im Verzeichnis und Eigentuemer der Datei (loxberry).');
    exit(1);
}
if (!flock($fh, LOCK_EX | LOCK_NB)) {
    // Ein Lauf ist noch unterwegs - das ist kein Fehler, nur ein Hinweis.
    exit(0);
}

$cfg = ev_config();
$takt = max(5, min(60, (int) $cfg['takt']));
$ende = time() + 58;   // zwei Sekunden Luft bis zum naechsten Cron-Lauf

do {
    $beginn = microtime(true);
    ev_durchlauf(false);
    $rest = $takt - (microtime(true) - $beginn);
    if (time() + $takt > $ende) { break; }
    if ($rest > 0) { usleep((int) ($rest * 1000000)); }
} while (time() < $ende);

flock($fh, LOCK_UN);
fclose($fh);
exit(0);
