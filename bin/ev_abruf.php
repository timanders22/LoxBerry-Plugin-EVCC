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
 *   ev_abruf.php --mqtt-leeren   die zurueckbehaltenen MQTT-Themen der Linie
 *                         leeren (fuer uninstall/uninstall, seit 0.9.33)
 *
 * Der Abruf gehoert NICHT in die Oberflaeche und nicht in den Endpunkt -
 * ein Plugin, das beim Klick auf die Seite Daten holt, ist falsch gebaut.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Die Bibliothek finden - nach dem EIGENEN Ablageort, nicht ueber eine feste
 * Zahl von ".." nach oben und nicht ueber eine Reihe von Wetten.
 *
 * Im entpackten Archiv liegen bin/ und webfrontend/ nebeneinander, auf dem
 * installierten LoxBerry in GETRENNTEN Baeumen:
 *
 *     <LoxBerry>/bin/plugins/<ordner>/ev_abruf.php
 *     <LoxBerry>/webfrontend/htmlauth/plugins/<ordner>/ev_lib.php
 *
 * Bis 0.9.8 stand hier nur dirname(__DIR__): installiert gab es die Datei
 * dort nicht, der Dienst brach bei JEDEM Cron-Lauf ab, und weil die
 * Cron-Zeile nach /dev/null schrieb, stand das nirgends (gefunden am
 * 16.08.2026 mit Werkzeuge/installationslage_pruefen.py).
 *
 * Bis 0.9.32 wurden danach drei Kandidaten der Reihe nach probiert. Aus einem
 * Archiv unter / war der zweite /webfrontend/htmlauth/plugins/bin/ev_lib.php
 * ab der Laufwerkswurzel, und was dort lag, lief als Bibliothek (in WSL
 * gemessen, Pruefung-EVCC-0.9.33, Fall T3). Jetzt entscheidet der Ablageort:
 * liegt diese Datei unter .../plugins/<ordner>, ist sie installiert, sonst
 * gilt nur die Bibliothek des eigenen Archivs. Bauart ZendureSolarFlow 0.9.26.
 */
if (basename(dirname(__DIR__)) === 'plugins') {
    $ev_kandidaten = array(dirname(dirname(dirname(__DIR__)))
        . '/webfrontend/htmlauth/plugins/' . basename(__DIR__) . '/ev_lib.php');
} else {
    $ev_kandidaten = array(dirname(__DIR__) . '/webfrontend/htmlauth/ev_lib.php');
}

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

/* Ohne installierte Lage NICHTS tun (seit 0.9.33).
 *
 * Bis 0.9.32 lief dieses Programm aus einem ausgepackten Archiv einfach los:
 * unter einer echten Wurzel (auch nur mit $LBHOMEDIR aus /etc/environment)
 * legte es dort Konfiguration mit frischem Token und Protokoll an und sandte
 * an das MQTT-Gateway der Anlage; ohne Wurzel schrieb es nach /tmp/evcc - auf
 * einem LoxBerry der Zwischenspeicher der Anlage (in WSL gemessen,
 * Pruefung-EVCC-0.9.33, Faelle W11-W13, T4, T5). Wer ein Archiv ausdruecklich
 * gegen eine Anlage laufen lassen will, setzt LBHOMEDIR UND LBPPLUGINDIR
 * (ev_paths(), Archivmodus). Bauart tb_keine_wurzel_abbruch() aus
 * Spotpreis-Tibber 0.9.19. */
$ev_pf = ev_paths();
if ($ev_pf['home'] === '') {
    if ($ev_pf['archiv'] !== '') {
        fwrite(STDERR, 'EVCC: Diese Datei liegt nicht in der Installation unter '
            . $ev_pf['archiv'] . " (ausgepacktes Archiv oder Pruefordner).\n"
            . "Damit nichts in die Anlage kommt, wurde nichts geholt, nichts gesendet und nichts geschrieben.\n"
            . 'Abhilfe: das Programm aus ' . $ev_pf['archiv'] . "/bin/plugins/<ordner> aufrufen\n"
            . "oder LBHOMEDIR und LBPPLUGINDIR ausdruecklich setzen.\n");
    } else {
        fwrite(STDERR, "EVCC: Es wurde kein LoxBerry-Wurzelverzeichnis gefunden.\n"
            . '$LBHOMEDIR ist nicht gesetzt, und oberhalb von ' . __DIR__ . " traegt kein\n"
            . "Verzeichnis config/plugins, data/plugins und config/system/general.json.\n"
            . "Es wurde nichts geholt, nichts gesendet und nichts geschrieben.\n");
    }
    exit(1);
}

$modus = isset($argv[1]) ? (string) $argv[1] : 'einmal';

/* Fuer uninstall/uninstall: die zurueckbehaltenen Themen der Linie leeren
 * (seit 0.9.33, Regeln/07 Abschnitt 3). Rueckgabe wie ev_mqtt_leeren(). */
if ($modus === '--mqtt-leeren') {
    exit(ev_mqtt_leeren());
}

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
/* Hoechstens einmal je Stunde bestimmen, welche Fassung apt einspielen
 * wuerde. Der Aufruf kostet ueber acht Sekunden (gemessen 10.09.2026 auf
 * dem LoxBerry: apt-cache policy evcc 8,57 s) und gehoert deshalb
 * hierher und nicht an einen Seitenaufruf - die Oberflaeche liest nur
 * noch das Ergebnis. In 0.9.29 hing er am Seitenaufbau und machte die
 * Oberflaeche mit 19 Sekunden unbenutzbar. */
$ev_kandidatdatei = ev_tmpdir() . '/apt_kandidat.txt';
clearstatcache(true, $ev_kandidatdatei);
if (!is_file($ev_kandidatdatei)
    || (time() - (int) @filemtime($ev_kandidatdatei)) >= 3600) {
    ev_apt_kandidat(true);
}

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
