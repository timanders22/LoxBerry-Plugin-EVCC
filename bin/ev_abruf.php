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

require_once dirname(__DIR__) . '/webfrontend/htmlauth/ev_lib.php';

$modus = isset($argv[1]) ? (string) $argv[1] : 'einmal';

/** Ein Durchlauf: holen, umrechnen, veroeffentlichen. */
function ev_durchlauf($laut = false)
{
    $st = ev_state(true);
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
