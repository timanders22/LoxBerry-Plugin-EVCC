<?php
/**
 * EVCC fuer LoxBerry - die Aktionen des Reiters Test
 *
 * Getrennt von der Oberflaeche, damit index.php nur Oberflaeche bleibt.
 * Die Selbstpruefung beantwortet OHNE Loxone die Frage: traegt die
 * Einrichtung?
 *
 * WAS 0.9.10 GEFEHLT HAT
 * Achtzehn Pruefzeilen, und keine einzige hat den eigenen Endpunkt
 * aufgerufen. Beide Ausfaelle jener Fassung - der Endpunkt fand seine
 * Bibliothek nicht, die Oberflaeche starb an einer ueberschriebenen
 * Variablen - waeren am ersten Tag sichtbar gewesen. Die erste Pruefzeile
 * dieser Datei ist deshalb ein echter HTTP-Aufruf gegen den eigenen
 * Endpunkt. Eine Leseprueferei sieht diese Fehlerklasse nicht.
 */

/** Eine Zeile der Selbstpruefung. $stand: 1 gut, 0 schlecht, -1 Hinweis. */
function ev_pruefzeile($stand, $frage, $antwort)
{
    return array((int) $stand, $frage, $antwort);
}

/**
 * Stimmen Reiterleiste, Bereiche und Positivliste ueberein?
 *
 * Gelesen wird aus der DATEI, nicht geraten. Eine Pruefung, die den
 * Sprachschluessel oder den Reiternamen errechnet, steht sonst dauerhaft auf
 * Rot, ohne dass etwas falsch waere - genau das ist am 16.08.2026 in der
 * Waermepumpe-Sitzung passiert.
 *
 * Diese Pruefung ersetzt die Erzeugung der Leiste aus einer Schleife: eine
 * PHP-Schleife wuerde hausstandard_pruefen.py blind machen (steht zweimal in
 * REGELN_1). Ausschreiben UND nachpruefen ist die Auflaesung.
 */
function ev_reiter_abgleich()
{
    $datei = __DIR__ . '/index.php';
    if (!is_file($datei)) { return array(-1, 0, 'index.php nicht lesbar'); }
    $t = (string) @file_get_contents($datei);

    /* Das Muster steht als  '/^tab-(settings|mqtt|loxone|test|log)$/'  in der
     * Datei. Die erste Fassung dieser Zeile verlangte einen Backslash vor dem
     * Dollarzeichen, den es dort nicht gibt - die Positivliste blieb leer, und
     * die Pruefung meldete ALLE Reiter als fehlend. Ein rotes Kreuz, das
     * nichts bedeutet. Gefunden hat es die Abnahme am gerenderten HTML. */
    $liste = array();
    if (preg_match('#\^tab-\(([a-z|]+)\)\$#', $t, $m)) {
        $liste = explode('|', $m[1]);
    }
    preg_match_all('#data-ziel="tab-([a-z]+)"#', $t, $m1);
    preg_match_all('#id="tab-([a-z]+)"#', $t, $m2);
    $leiste = array_values(array_unique($m1[1]));
    $bereiche = array_values(array_unique($m2[1]));
    sort($liste); sort($leiste); sort($bereiche);

    $fehlt = array();
    foreach (array_unique(array_merge($liste, $leiste, $bereiche)) as $n) {
        $wo = array();
        if (!in_array($n, $liste, true))    { $wo[] = 'Positivliste'; }
        if (!in_array($n, $leiste, true))   { $wo[] = 'Leiste'; }
        if (!in_array($n, $bereiche, true)) { $wo[] = 'Bereich'; }
        if ($wo) { $fehlt[] = $n . ' (fehlt in: ' . implode(', ', $wo) . ')'; }
    }

    /* Und: entscheidet wirklich der SERVER, welcher Reiter offen ist?
     *
     * Bis 0.9.10 stand sm-active nur in CSS und Skript - ohne JavaScript war
     * die Seite leer. Seit 0.9.11 haengt es an Leiste und Bereich, aber als
     * zusammengesetztes Attribut: hausstandard_pruefen.py meldet dafuer
     * "zusammengesetzte CSS-Klasse (nicht pruefbar)". Damit waere die
     * Eigenschaft ungeprueft - eine Korrektur, die eine Pruefung blind macht,
     * ist keine. Also wird hier nachgezaehlt. */
    $mit_aktiv_leiste = preg_match_all('#class="sm-tab<\?[^"]*sm-active#', $t);
    $mit_aktiv_seite = preg_match_all('#class="sm-seite<\?[^"]*sm-active#', $t);
    if ($mit_aktiv_leiste < count($leiste)) {
        $fehlt[] = sprintf('nur %d von %d Reitern setzen sm-active serverseitig',
                           $mit_aktiv_leiste, count($leiste));
    }
    if ($mit_aktiv_seite < count($bereiche)) {
        $fehlt[] = sprintf('nur %d von %d Bereichen setzen sm-active serverseitig',
                           $mit_aktiv_seite, count($bereiche));
    }

    /* Ueber die leere Menge wird nicht geurteilt.
     *
     * Greift KEINES der drei Suchmuster mehr - etwa nach einer Umbenennung
     * des Vorsatzes 'tab-' -, sind alle drei Listen leer, die Schleife
     * darueber laeuft nicht an, und bis 0.9.26 meldete diese Zeile dann
     * "in Ordnung, 0 Reiter". Gemessen: ein einzelnes zerbrochenes Muster
     * wird richtig rot, erst alle drei zusammen ergaben den gruenen Haken
     * ueber nichts. */
    if (!$liste || !$leiste || !$bereiche) {
        return array(0, count($leiste), ev_t('TEST.A_REITER_BLIND'));
    }
    return array($fehlt ? 0 : 1, count($leiste), $fehlt ? implode('; ', $fehlt) : '');
}

function ev_pruefungen()
{
    $cfg = ev_config();
    $z = array();

    /* ---- Der eigene Endpunkt. Die wichtigste Zeile dieser Datei. ----
     *
     * DREI Ausgaenge, nicht zwei. Kommt eine HTTP-Antwort, wird sie beurteilt:
     * 200 mit einer EVCC-Zeile ist gut, alles andere ist ein Befund. Kommt
     * ueberhaupt keine Antwort, ist das KEIN Befund am Plugin - ein Webserver,
     * der nur eine Anfrage zugleich bearbeitet, kann sich waehrend dieser
     * Seite nicht selbst aufrufen. Dann steht hier ein Hinweis samt Adresse
     * zum Nachklicken, kein Kreuz. Gemessen im Pruefaufbau: mit dem
     * eingebauten Webserver von PHP faellt genau dieser Fall an. */
    list($e_ok, $e_code, $e_text, $e_url) = ev_selbsttest_endpunkt('status');
    if ($e_ok) {
        $z[] = ev_pruefzeile(1, ev_t('TEST.F_ENDPUNKT'),
            sprintf(ev_t('TEST.A_ENDPUNKT_OK'), (int) $e_code, ev_e(substr($e_text, 0, 60))));
    } elseif ((int) $e_code === 0) {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_ENDPUNKT'),
            sprintf(ev_t('TEST.A_ENDPUNKT_UNKLAR'), ev_e($e_text), ev_e($e_url)));
    } else {
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_ENDPUNKT'),
            sprintf(ev_t('TEST.A_ENDPUNKT_FEHL'), (int) $e_code,
                    ev_e($e_text), ev_e($e_url)));
    }

    /* ---- Findet der Endpunkt seine Bibliothek? Der bessere Hinweistext,
           wenn die Zeile darueber rot ist. ---- */
    $kand = ev_endpunkt_kandidaten();
    $treffer = '';
    foreach ($kand as $k) { if (is_file($k)) { $treffer = $k; break; } }
    $z[] = ev_pruefzeile($treffer !== '' ? 1 : ($kand ? 0 : -1), ev_t('TEST.F_BIBLIOTHEK'),
        $treffer !== '' ? sprintf(ev_t('TEST.A_BIBLIOTHEK_OK'), ev_e($treffer))
                        : sprintf(ev_t('TEST.A_BIBLIOTHEK_FEHL'),
                                  ev_e(implode(' | ', $kand))));

    /* ---- Reiter: drei Stellen, ein Ergebnis ---- */
    list($r_ok, $r_anz, $r_text) = ev_reiter_abgleich();
    $z[] = ev_pruefzeile($r_ok, ev_t('TEST.F_REITER'),
        $r_ok === 1 ? sprintf(ev_t('TEST.A_REITER_OK'), (int) $r_anz)
                    : sprintf(ev_t('TEST.A_REITER_FEHL'), ev_e($r_text)));

    /* ---- EVCC selbst ---- */
    if (ev_dienst_vorhanden()) {
        /* Eine Entwicklerfassung ist kein Fehler - aber sie soll nicht
         * unbemerkt laufen. Am 17.08.2026 hat ein Update 0.315.0-dev
         * eingespielt, obwohl 0.314.0 angekuendigt war: auf der Maschine war
         * der nightly-Kanal eingetragen, und apt nimmt die hoechste Fassung
         * aus allen Quellen. Ein Hinweis, kein Kreuz. */
        $ev_fassung = ev_dienst_version();
        /* Beide Schreibweisen. Gemessen am 17.08.2026: apt fuehrt
         * 0.315.0~dev.1786876734+3c25327f7 mit TILDE, 'evcc -v' meldet
         * dieselbe Fassung als 0.315.0-dev+3c25327f7 mit Bindestrich. Wer
         * nur eine der beiden prueft, sieht die Haelfte. */
        $ev_dev = (stripos($ev_fassung, '-dev') !== false
                   || stripos($ev_fassung, '~dev') !== false
                   || stripos($ev_fassung, 'nightly') !== false);
        $z[] = ev_pruefzeile($ev_dev ? -1 : 1, ev_t('TEST.F_INSTALLIERT'),
            sprintf(ev_t($ev_dev ? 'TEST.A_INSTALLIERT_DEV' : 'TEST.A_INSTALLIERT'),
                    ev_e($ev_fassung)));
    } else {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_INSTALLIERT'), ev_t('TEST.A_NICHT_INSTALLIERT'));
    }

    $laeuft = ev_dienst_laeuft();
    $z[] = ev_pruefzeile($laeuft ? 1 : (ev_dienst_vorhanden() ? 0 : -1),
        ev_t('TEST.F_DIENST'),
        $laeuft ? ev_t('TEST.A_DIENST_LAEUFT') : ev_t('TEST.A_DIENST_TOT'));

    /* ---- Darf die Oberflaeche den Dienst ueberhaupt schalten? ----
     *
     * postroot.sh legte die sudo-Regel bis 0.9.10 NUR an, wenn es EVCC selbst
     * installiert hatte - bei vorhandenem EVCC stieg es vorher aus. Wer EVCC
     * von Hand installiert hat (ein ausdruecklich unterstuetzter Fall), bekam
     * drei Knoepfe, die nie wirken konnten. Das laesst sich hier ablesen,
     * ohne etwas zu schalten. */
    /* Gemessen wird die WIRKUNG, nicht das Vorhandensein einer Datei.
     *
     * Bis 0.9.26 stand hier ein blosses is_file(): eine vorhandene, aber
     * falsch geschriebene oder auf einen anderen Pfad zeigende Regel ergab
     * einen gruenen Haken auf eine Frage, die mit "darf" nach der Wirkung
     * fragt. 'systemctl is-active' ist ein LESENDER Aufruf, der aber durch
     * dieselbe sudo-Regel muss - er schaltet nichts und beantwortet die
     * Frage trotzdem. */
    $sudo = '/etc/sudoers.d/loxberry-evcc';
    $ev_sudo_wirkt = -1;
    if (function_exists('exec')) {
        /* 'status' ist der eine LESENDE Unterbefehl, den die sudo-Regel aus
         * postroot.sh ausdruecklich mit abdeckt. Er schaltet nichts und muss
         * trotzdem durch dieselbe Regel - damit misst diese Zeile die
         * Wirkung, ohne eine zu haben. Beide ueblichen Pfade, genau wie
         * ev_dienst() sie versucht.
         *
         * Nicht 'is-active': das steht NICHT in der Regel und waere auf jeder
         * richtig eingerichteten Anlage rot geworden. */
        foreach (array('/bin/systemctl', '/usr/bin/systemctl') as $ev_sc) {
            $ev_aus = array();
            $ev_rc = 1;
            @exec('sudo -n ' . $ev_sc . ' status evcc 2>&1', $ev_aus, $ev_rc);
            $ev_txt = strtolower(implode(' ', $ev_aus));
            if (strpos($ev_txt, 'password') !== false || strpos($ev_txt, 'sudo:') !== false) {
                $ev_sudo_wirkt = 0;      // sudo verlangt etwas - die Regel greift nicht
                continue;                // der andere Pfad darf es noch richten
            }
            // 0 = laeuft, 3 = angehalten. Beides heisst: die Regel hat
            // getragen. 4 (Unit unbekannt) und 127 (kein systemctl) sagen
            // ueber die Regel nichts - dann bleibt es bei "nicht feststellbar".
            if ($ev_rc === 0 || $ev_rc === 3) { $ev_sudo_wirkt = 1; break; }
        }
    }
    if ($ev_sudo_wirkt === 1) {
        $z[] = ev_pruefzeile(1, ev_t('TEST.F_SUDO'), ev_t('TEST.A_SUDO_WIRKT'));
    } elseif ($ev_sudo_wirkt === 0) {
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_SUDO'),
            is_file($sudo) ? ev_t('TEST.A_SUDO_DA_WIRKT_NICHT') : ev_t('TEST.A_SUDO_FEHLT'));
    } else {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_SUDO'),
            is_file($sudo) ? ev_t('TEST.A_SUDO_UNKLAR_DA') : ev_t('TEST.A_SUDO_UNKLAR'));
    }

    /* ---- Kann die Oberflaeche EVCC aktualisieren? ----
     * Ein Hinweis, kein Kreuz: wer die Option nicht eingeschaltet hat,
     * vermisst nichts. Fehlt aber das Skript, OBWOHL die Option an ist, ist
     * das ein Befund - der Knopf koennte dann nie wirken. */
    if (empty($cfg['update_ein'])) {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_UPDATE'), ev_t('TEST.A_UPDATE_AUS'));
    } elseif (ev_update_moeglich()) {
        $z[] = ev_pruefzeile(1, ev_t('TEST.F_UPDATE'),
            sprintf(ev_t('TEST.A_UPDATE_BEREIT'), EV_UPDATE_SKRIPT));
    } else {
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_UPDATE'),
            sprintf(ev_t('TEST.A_UPDATE_KEIN_SKRIPT'), EV_UPDATE_SKRIPT));
    }

    /* ---- Erreichbarkeit ---- */
    $st = ev_state(true);
    if ($st['ok']) {
        $z[] = ev_pruefzeile(1, ev_t('TEST.F_ERREICHBAR'),
            sprintf(ev_t('TEST.A_ERREICHBAR'), ev_e($cfg['url'])));
    } else {
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_ERREICHBAR'),
            sprintf(ev_t('TEST.A_NICHT_ERREICHBAR'), ev_e($cfg['url']), ev_e($st['fehler'])));
    }

    /* ---- Laeuft EVCC wirklich, oder antwortet es nur? ----
     *
     * Diese Zeile steht VOR der Feldzuordnung, weil sie deren Ursache
     * beantwortet. Ohne sie meldete das Plugin "22 von 22 bestanden",
     * waehrend keine einzige Zahl ankam.
     *
     * Reihenfolge: Startfehler zuerst. Wer bei "nicht eingerichtet" anfaengt,
     * schickt jemanden in die Grundeinrichtung, dessen Konfiguration laengst
     * steht und nur wegen eines abgelaufenen Tokens nicht geladen wird. */
    $ein = ev_einrichtung($st);
    if (!$st['ok']) {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_BETRIEB'), ev_t('TEST.A_BETRIEB_UNBEKANNT'));
    } elseif ($ein['fatal'] !== '') {
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_BETRIEB'),
            sprintf(ev_t('TEST.A_BETRIEB_FATAL'), ev_e($ein['fatal']), ev_e(ev_evcc_link())));
    } elseif ($ein['einrichtung'] === 0) {
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_BETRIEB'),
            sprintf(ev_t('TEST.A_BETRIEB_SETUP'), ev_e(ev_evcc_link())));
    } elseif ($ein['ladepunkte'] === 0 && $ein['einrichtung'] === 1) {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_BETRIEB'),
            sprintf(ev_t('TEST.A_BETRIEB_OHNE_LP'), ev_e(ev_evcc_link())));
    } elseif ($ein['einrichtung'] === 1) {
        $z[] = ev_pruefzeile(1, ev_t('TEST.F_BETRIEB'),
            sprintf(ev_t('TEST.A_BETRIEB_JA'), (int) $ein['ladepunkte']));
    } else {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_BETRIEB'), ev_t('TEST.A_BETRIEB_UNKLAR'));
    }
    if ($ein['neuer'] !== '') {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_EVCC_NEU'),
            sprintf(ev_t('TEST.A_EVCC_NEU' . ev_update_weg()),
                    ev_e($ein['version']), ev_e($ein['neuer'])));
    }

    /* ---- Feldzuordnung: der wichtigste Punkt ----
     *
     * Die MQTT-Themen von EVCC sind dokumentiert, die genaue Form von
     * /api/state ist es nicht. Deshalb wird hier nicht behauptet, sondern
     * nachgesehen, welches Feld sich wirklich aufloesen liess.
     *
     * Getrennt gezaehlt wird, was in 0.9.11 aus der Dokumentation kam und an
     * keiner Anlage gemessen ist. Eine Angabe, die niemand gemessen hat, darf
     * nicht aussehen wie eine, die jemand gemessen hat. */
    $werte = ev_werte($st);
    $felder = ev_felder();
    $ohne = array();
    $ohne_doku = array();
    $mit = 0;
    $mit_doku = 0;
    $anz_doku = 0;
    foreach ($felder as $name => $d) {
        if (empty($d['pfade'])) { continue; }
        $doku = ($d['quelle'] === 'doku');
        if ($doku) { $anz_doku++; }
        if ($werte[$name]['pfad'] === '') {
            if ($doku) { $ohne_doku[] = $name; } else { $ohne[] = $name; }
        } else {
            $mit++;
            if ($doku) { $mit_doku++; }
        }
    }
    $ev_laeuft = ($ein['fatal'] === '' && $ein['einrichtung'] !== 0);
    if (!$ohne) {
        $z[] = ev_pruefzeile(1, ev_t('TEST.F_FELDER'), sprintf(ev_t('TEST.A_FELDER_OK'), $mit));
    } elseif (!$ev_laeuft) {
        /* Nicht mit "fehlt ein Geraet, ist das richtig so" beruhigen, wenn
         * EVCC selbst meldet, dass es gar nicht laeuft. Genau dieser Satz
         * stand bis 0.9.12 unter 43 nicht aufgeloesten Feldern. */
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_FELDER'),
            sprintf(ev_t('TEST.A_FELDER_KEIN_BETRIEB'), count($ohne)));
    } elseif ($mit === 0) {
        // Kein einziges Feld aufgeloest, obwohl EVCC laeuft - das ist kein
        // fehlendes Geraet mehr, das ist ein Befund.
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_FELDER'),
            sprintf(ev_t('TEST.A_FELDER_KEINS'), count($ohne)));
    } else {
        /* Ohne Ladepunkt fehlen alle lp*- und fz*-Felder, und der Satz
         * "fehlt ein Geraet, ist das richtig so" sagt dann das Falsche.
         * Gemessen an einer Anlage MIT PV, aber ohne Wallbox: 13 von 51
         * aufgeloest, und alle 23 fehlenden Felder mit lp- oder fz-Praefix
         * hatten genau diesen einen Grund.
         *
         * (In der ersten Fassung stand hier "lp*-Stern-Schraegstrich-fz*" -
         * die Zeichenfolge Stern-Schraegstrich beendet einen Blockkommentar.
         * Derselbe Fehler steht mit </script> schon einmal in REGELN_1: ein
         * Kommentar, der den beschriebenen Fehler selbst begeht.) */
        $ev_text = sprintf(ev_t('TEST.A_FELDER_FEHLEN'), $mit, count($ohne),
                           ev_e(implode(', ', $ohne)));
        if ($ein['ladepunkte'] === 0) {
            $ev_text .= ' ' . ev_t('TEST.A_FELDER_OHNE_LP');
        }
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_FELDER'), $ev_text);
    }
    /* Diese Zeile ist bewusst ein HINWEIS und nie ein Kreuz: dass eine
     * EVCC-Fassung ein neues Feld nicht kennt, ist kein Defekt des Plugins.
     * Ein rotes Kreuz, das nichts bedeutet, ist schlimmer als keine Pruefung. */
    $z[] = ev_pruefzeile(-1, ev_t('TEST.F_DOKU'),
        $anz_doku === 0 ? ev_t('TEST.A_DOKU_KEINE')
            : sprintf(ev_t('TEST.A_DOKU'), $mit_doku, $anz_doku,
                      $ohne_doku ? ev_e(implode(', ', $ohne_doku)) : '-'));

    /* ---- Die vier Energiemanager-Groessen einzeln ---- */
    foreach (array('netz_kw' => 'Gpwr', 'pv_kw' => 'Ppwr',
                   'speicher_kw' => 'Spwr', 'speicher_soc' => 'Soc') as $feld => $anschluss) {
        $da = isset($werte[$feld]) && $werte[$feld]['pfad'] !== '';
        // "Ohne Hausspeicher oder PV-Anlage ist das normal" gilt nur, wenn
        // EVCC ueberhaupt laeuft. Sonst ist es eine Beschwichtigung.
        $z[] = ev_pruefzeile($da ? 1 : ($ev_laeuft ? -1 : 0),
            sprintf(ev_t('TEST.F_EM_FELD'), $anschluss),
            $da ? sprintf(ev_t('TEST.A_EM_FELD'), $feld, $werte[$feld]['wert'],
                          ev_e($werte[$feld]['pfad']))
                : sprintf(ev_t($ev_laeuft ? 'TEST.A_EM_FELD_FEHLT'
                                          : 'TEST.A_EM_FELD_KEIN_BETRIEB'), $feld));
    }

    /* ---- Zusatzwerte: Preisvorschau, Prognose, Statistik ---- */
    $roh = isset($st['roh']['lox']) && is_array($st['roh']['lox']) ? $st['roh']['lox'] : array();
    $teile = array();
    if (isset($roh['preis']['anzahl'])) {
        $teile[] = sprintf(ev_t('TEST.A_ZUSATZ_PREIS'), (int) $roh['preis']['anzahl'],
                           (int) $roh['preis']['rang']);
    }
    if (isset($roh['prognose']['heute']) && $roh['prognose']['heute'] !== null) {
        // NICHT durch 1000 teilen: in welcher Einheit EVCC die Prognose
        // liefert, hat niemand gemessen. Der Rohwert steht hier, damit man
        // ihn einmal gegen die EVCC-Oberflaeche halten kann.
        $teile[] = sprintf(ev_t('TEST.A_ZUSATZ_PROGNOSE'),
                           ev_e((string) $roh['prognose']['heute']));
    }
    if (!empty($roh['statistik'])) { $teile[] = ev_t('TEST.A_ZUSATZ_STATISTIK'); }
    $z[] = ev_pruefzeile($teile ? 1 : -1, ev_t('TEST.F_ZUSATZ'),
        $teile ? implode(' &middot; ', $teile) : ev_t('TEST.A_ZUSATZ_LEER'));

    /* ---- MQTT ---- */
    $m = ev_mqtt_zustand();
    if (empty($cfg['mqtt_ein'])) {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_MQTT'), ev_t('TEST.A_MQTT_AUS'));
    } elseif (!$m['gefunden']) {
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_MQTT'), ev_t('TEST.A_MQTT_KEIN_ABSCHNITT'));
    } elseif (!$m['udpport']) {
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_MQTT'), ev_t('TEST.A_MQTT_KEIN_PORT'));
    } elseif (!$m['autostart']) {
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_MQTT'), ev_t('TEST.A_MQTT_KEIN_AUTOSTART'));
    } else {
        $z[] = ev_pruefzeile(1, ev_t('TEST.F_MQTT'),
            sprintf(ev_t('TEST.A_MQTT_OK'), (int) $m['udpport'], ev_e($cfg['mqtt_topic'])));
    }

    /* ---- Token und Steuerung ----
     *
     * Drei Ausgaenge, und kein Mustervergleich mehr. Bis 0.9.26 stand hier
     * '^[A-Za-z0-9]{24,}$' - dasselbe zu enge Muster, das ev_config() dazu
     * gebracht hat, ein hinterlegtes Token stillschweigend zu ersetzen. Ein
     * von Hand gesetztes oder aus einer Sicherung zurueckgespieltes Token
     * taugt; es ist nur schwaecher als ein selbst erzeugtes. Gemeldet wird
     * das, abgewiesen nicht. */
    $ev_tok = (string) $cfg['aktionstoken'];
    if ($ev_tok === '') {
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_TOKEN'), ev_t('TEST.A_TOKEN_LEER'));
    } elseif (strlen($ev_tok) < 24) {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_TOKEN'),
            sprintf(ev_t('TEST.A_TOKEN_KURZ'), strlen($ev_tok)));
    } else {
        $z[] = ev_pruefzeile(1, ev_t('TEST.F_TOKEN'),
            sprintf(ev_t('TEST.A_TOKEN_OK'), strlen($ev_tok)));
    }

    $z[] = ev_pruefzeile(-1, ev_t('TEST.F_STEUERUNG'),
        !empty($cfg['steuerung_ein']) ? ev_t('TEST.A_STEUERUNG_EIN') : ev_t('TEST.A_STEUERUNG_AUS'));

    /* ---- Zweitschrift und beschaedigte Konfiguration ----
     *
     * Bis 0.9.10 hat eine abgeschnittene evcc.json die Werkseinstellung
     * erzeugt UND die intakte Zweitschrift damit ueberschrieben. Beides ist
     * hier ablesbar, bevor es jemandem passiert. */
    $p = ev_paths();
    $z[] = ev_pruefzeile(is_file($p['sicherung']) ? 1 : -1, ev_t('TEST.F_SICHERUNG'),
        is_file($p['sicherung'])
            ? sprintf(ev_t('TEST.A_SICHERUNG_OK'), date('d.m.Y H:i', (int) filemtime($p['sicherung'])))
            : ev_t('TEST.A_SICHERUNG_KEINE'));
    if (is_file($p['config'] . '.kaputt')) {
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_KAPUTT'),
            sprintf(ev_t('TEST.A_KAPUTT'), ev_e($p['config'] . '.kaputt')));
    }

    /* ---- Vorlagen wirklich erzeugen und zurueck einlesen ----
     *
     * Ein Sonderzeichen in einem Fahrzeugnamen zerlegt sonst die Datei, und
     * Loxone Config meldet dazu nichts Brauchbares. */
    $vorher = libxml_use_internal_errors(true);
    $kaputt = array();
    $proben = array('VI' => ev_vorlage_ein(), 'VQ' => ev_vorlage_aus());
    foreach ($proben as $was => $paar) {
        libxml_clear_errors();
        if (simplexml_load_string($paar[1]) === false) {
            $fehler = libxml_get_errors();
            $kaputt[] = $was . ' (' . (isset($fehler[0]) ? trim($fehler[0]->message) : '?') . ')';
        }
    }
    libxml_clear_errors();
    libxml_use_internal_errors($vorher);
    $z[] = ev_pruefzeile($kaputt ? 0 : 1, ev_t('TEST.F_VORLAGE'),
        $kaputt ? sprintf(ev_t('TEST.A_VORLAGE_FEHL'), ev_e(implode(', ', $kaputt)))
                : sprintf(ev_t('TEST.A_VORLAGE_OK'), count($proben)));

    /* ---- Ist die Vorlage stabil, auch ohne Zwischenspeicher? ----
     *
     * Bis 0.9.10 legte der Zweig "kein Fahrzeugname bekannt" ein Feld weniger
     * an als der Zweig mit Namen. Gemessen mit zwei Fahrzeugen: 35 Befehle
     * ohne Zwischenspeicher, 37 mit. Und /tmp ist auf dem LoxBerry eine
     * Ramdisk - nach jedem Neustart entstand die kurze Fassung. Hier wird
     * nachgezaehlt, ob beide Zweige gleich viele Felder erzeugen. */
    $fz_soll = 3 * (int) $cfg['fahrzeuge'];
    $fz_ist = 0;
    foreach (array_keys($felder) as $n) {
        if (preg_match('/^fz[0-9]+_/', $n)) { $fz_ist++; }
    }
    /* Bei null eingestellten Fahrzeugen gibt es nichts zu vergleichen -
     * "0 === 0" waere ein Haken ueber einer nicht gestellten Frage. */
    if ((int) $cfg['fahrzeuge'] === 0) {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_VORLAGE_STABIL'),
            ev_t('TEST.A_VORLAGE_STABIL_KEINE'));
    } else {
        $z[] = ev_pruefzeile($fz_ist === $fz_soll ? 1 : 0, ev_t('TEST.F_VORLAGE_STABIL'),
            $fz_ist === $fz_soll ? sprintf(ev_t('TEST.A_VORLAGE_STABIL_OK'), $fz_ist)
                                 : sprintf(ev_t('TEST.A_VORLAGE_STABIL_FEHL'), $fz_ist, $fz_soll));
    }

    /* ---- Sind die Bausteintitel ueber BEIDE Vorlagen eindeutig? ----
     *
     * In der Bausteinsuche von Loxone Config fehlt der Geraeteknoten;
     * Eingaenge und Ausgaenge stehen dort nebeneinander. Gemessen an 0.9.26:
     * 141 Titel, davon 140 verschieden - das Feld 'entladeregelung' und der
     * gleichnamige Befehl ergaben zweimal EVCC_ENTLADEREGELUNG. Gefunden hat
     * das keine Pruefung, sondern ein Abgleich von Hand. Seit 0.9.27 tragen
     * die Ausgangstitel den Vorsatz SET_; diese Zeile sorgt dafuer, dass es
     * beim naechsten neuen Feld auffaellt und nicht wieder erst hinterher. */
    $ev_titel = array();
    foreach (array(ev_vorlage_ein(), ev_vorlage_aus()) as $ev_paar) {
        if (preg_match_all('/<VirtualIn(?:Http)?Cmd Title="([^"]*)"/', $ev_paar[1], $ev_m)) {
            $ev_titel = array_merge($ev_titel, $ev_m[1]);
        }
        if (preg_match_all('/<VirtualOutCmd Title="([^"]*)"/', $ev_paar[1], $ev_m)) {
            $ev_titel = array_merge($ev_titel, $ev_m[1]);
        }
    }
    $ev_doppelt = array();
    foreach (array_count_values($ev_titel) as $ev_t1 => $ev_c) {
        if ($ev_c > 1) { $ev_doppelt[] = $ev_t1 . ' (' . $ev_c . 'x)'; }
    }
    if (!$ev_titel) {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_TITEL'), ev_t('TEST.A_TITEL_LEER'));
    } else {
        $z[] = ev_pruefzeile($ev_doppelt ? 0 : 1, ev_t('TEST.F_TITEL'),
            $ev_doppelt ? sprintf(ev_t('TEST.A_TITEL_DOPPELT'), ev_e(implode(', ', $ev_doppelt)))
                        : sprintf(ev_t('TEST.A_TITEL_OK'), count($ev_titel)));
    }

    /* ---- Stehen die spaeter hinzugekommenen Felder am Ende? ----
     *
     * Bis 0.9.26 nicht: gemessen gegen das Tag-Archiv v0.9.10 sassen 59 neue
     * Felder ab Stelle 11, und 40 alte standen dahinter. Geschadet hat es
     * nicht, weil die Befehlserkennung namensbasiert ist - aber jede spaetere
     * Umsortierung waere teuer geworden. Seit 0.9.27 ist die Tabelle geteilt,
     * und diese Zeile haelt sie so. */
    $ev_reihe_fehler = array();
    $ev_spaeter_gesehen = '';
    foreach ($felder as $ev_n => $ev_d) {
        $ev_seit = isset($ev_d['seit']) ? (string) $ev_d['seit'] : '0.9.10';
        if ($ev_seit !== '0.9.10') {
            $ev_spaeter_gesehen = $ev_n;
        } elseif ($ev_spaeter_gesehen !== '') {
            $ev_reihe_fehler[] = $ev_n;
        }
        // Ein Feld aus der Dokumentation kann nicht seit 0.9.10 dastehen.
        if ($ev_d['quelle'] === 'doku' && $ev_seit === '0.9.10') {
            $ev_reihe_fehler[] = $ev_n;
        }
    }
    if (!$felder) {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_REIHE'), ev_t('TEST.A_REIHE_LEER'));
    } else {
        $z[] = ev_pruefzeile($ev_reihe_fehler ? 0 : 1, ev_t('TEST.F_REIHE'),
            $ev_reihe_fehler
                ? sprintf(ev_t('TEST.A_REIHE_FEHL'),
                          ev_e(implode(', ', array_slice(array_unique($ev_reihe_fehler), 0, 6))))
                : sprintf(ev_t('TEST.A_REIHE_OK'), count($felder)));
    }

    /* ---- Hat jede Einstellung eine Regel fuer das Zurueckspielen? ----
     *
     * ev_wert_pruefen() faellt geschlossen aus: ein Schluessel ohne Regel
     * wird beim Zurueckspielen abgelehnt. Das ist richtig - aber wer eine
     * neue Vorgabe ergaenzt und die Regel vergisst, braeche damit still das
     * Zurueckspielen. Diese Zeile findet das, bevor es ein Anwender tut. */
    $ev_ohne_regel = array();
    foreach (ev_vorgaben() as $ev_k => $ev_v) {
        if (!ev_wert_pruefen($ev_k, $ev_v)) { $ev_ohne_regel[] = $ev_k; }
    }
    $z[] = ev_pruefzeile($ev_ohne_regel ? 0 : 1, ev_t('TEST.F_SICH_REGELN'),
        $ev_ohne_regel ? sprintf(ev_t('TEST.A_SICH_REGELN_FEHL'), ev_e(implode(', ', $ev_ohne_regel)))
                       : sprintf(ev_t('TEST.A_SICH_REGELN_OK'), count(ev_vorgaben())));

    /* ---- Vorlage und Zeile muessen dieselben Feldnamen kennen ----
     *
     * Steht in der Vorlage ein Suchmuster, das die Zeile nicht liefert,
     * bleibt der virtuelle Eingang in Loxone stumm - ohne Fehlermeldung.
     * Geprueft werden nur die Felder mit zeile = 1; Textfelder stehen
     * absichtlich nicht in der Zeile. */
    $zeile = ev_zeile($werte);
    $fehlend = array();
    foreach (array_keys(ev_felder_zeile()) as $name) {
        if (strpos($zeile, ';' . strtoupper($name) . '=') === false) {
            $fehlend[] = strtoupper($name);
        }
    }
    $z[] = ev_pruefzeile($fehlend ? 0 : 1, ev_t('TEST.F_ABGLEICH'),
        $fehlend ? sprintf(ev_t('TEST.A_ABGLEICH_FEHL'), ev_e(implode(', ', $fehlend)))
                 : sprintf(ev_t('TEST.A_ABGLEICH_OK'), count(ev_felder_zeile())));

    /* ---- Und das Suchmuster muss eindeutig sein ----
     * Jedes Feld steht in der Zeile hinter einem Semikolon. Traefe ';NAME='
     * mehr als einmal, laese Loxone die falsche Stelle. */
    $doppelt = array();
    foreach (array_keys(ev_felder_zeile()) as $name) {
        if (substr_count($zeile, ';' . strtoupper($name) . '=') > 1) { $doppelt[] = $name; }
    }
    // Zwei Platzhalter, zwei Argumente. Die erste Fassung dieser Zeile gab
    // nur eines mit: unter 7.4 eine Warnung, unter 8.x ein
    // ArgumentCountError - und der toetet die ganze Seite. Gefunden hat es
    // rendern.py unter 8.4, die ganze Klasse dann sprintf_pruefen.py.
    $ev_zeilenfelder = count(ev_felder_zeile());
    $z[] = ev_pruefzeile($doppelt ? 0 : 1, ev_t('TEST.F_EINDEUTIG'),
        $doppelt ? sprintf(ev_t('TEST.A_EINDEUTIG_FEHL'), ev_e(implode(', ', $doppelt)))
                 : sprintf(ev_t('TEST.A_EINDEUTIG_OK'),
                           $ev_zeilenfelder - count($doppelt), $ev_zeilenfelder));

    return $z;
}

/**
 * Die Knopf-Aktionen des Reiters Test.
 * Rueckgabe: array(ok, Meldung)
 */
function ev_test_aktion($was)
{
    switch ($was) {
        case 'start':
        case 'stop':
        case 'restart':
            /* Je Vorgang ein eigener Satz.
             *
             * Bis 0.9.11 stand hier sprintf('Dienst %s ausgefuehrt.', $was) -
             * und $was ist der Unterbefehl von systemctl. Auf dem Bildschirm
             * erschien "Dienst start ausgefuehrt.". Ein englisches Wort in
             * einem deutschen Satz, und dazu noch ein ungebeugtes: Deutsch
             * und Englisch brauchen hier verschiedene Formen, die sich nicht
             * aus einem Platzhalter erzeugen lassen. */
            list($ok, $text) = ev_dienst($was);
            $ev_s = strtoupper($was);
            if (!$ok) {
                return array(0, sprintf(ev_t('TEST.M_DIENST_' . $ev_s . '_FEHL'), ev_e($text)));
            }
            /* systemctl liefert 0, sobald die Unit angestossen ist - nicht,
             * sobald sie laeuft. Bis 0.9.26 meldete die Oberflaeche deshalb
             * "Der EVCC-Dienst wurde gestartet", auch wenn EVCC sofort wieder
             * umfiel. Fuer die Aktualisierung liest dasselbe Plugin die
             * Fassung vor und nach dem Lauf nach; hier wird ebenso die
             * Wirkung gemessen. Eine Sekunde Luft, damit systemd den Start
             * vollziehen kann. */
            if ($was !== 'stop') {
                sleep(1);
                if (!ev_dienst_laeuft()) {
                    return array(0, ev_t('TEST.M_DIENST_OHNE_WIRKUNG'));
                }
            } else {
                sleep(1);
                if (ev_dienst_laeuft()) {
                    return array(0, ev_t('TEST.M_DIENST_LAEUFT_NOCH'));
                }
            }
            return array(1, ev_t('TEST.M_DIENST_' . $ev_s));

        case 'abruf':
            $st = ev_state(true);
            return array($st['ok'], $st['ok'] ? ev_t('TEST.M_ABRUF_OK')
                                              : sprintf(ev_t('TEST.M_ABRUF_FEHL'), ev_e($st['fehler'])));

        case 'update':
            // Nur, wenn der Anwender es ausdruecklich freigegeben hat. Der
            // Knopf wird sonst gar nicht erst angezeigt - aber ein Handler,
            // der sich auf die Sichtbarkeit eines Knopfes verlaesst, ist
            // keiner.
            $cfg = ev_config();
            if (empty($cfg['update_ein'])) {
                return array(0, ev_t('TEST.M_UPDATE_GESPERRT'));
            }
            return ev_update_ausfuehren();

        case 'zusatz':
            /* Preisvorschau, Prognose und Statistik von Hand nachziehen.
             *
             * Der Rueckgabewert von ev_state() entscheidet. Bis 0.9.26 stand
             * hier eine feste 1: auch wenn EVCC gar nicht antwortete,
             * erschien der gruene Kasten "Zusatzwerte geholt". */
            $ev_st = ev_state(true);
            ev_zusatz_holen(true);
            $roh = ev_statistik();
            if (empty($ev_st['ok'])) {
                return array(0, sprintf(ev_t('TEST.M_ZUSATZ_FEHL'), ev_e((string) $ev_st['fehler'])));
            }
            return array(1, sprintf(ev_t('TEST.M_ZUSATZ'), $roh ? count($roh) : 0));

        case 'endpunkt':
            list($ok, $code, $text, $url) = ev_selbsttest_endpunkt('status');
            return array($ok, $ok ? sprintf(ev_t('TEST.M_ENDPUNKT_OK'), ev_e(substr($text, 0, 120)))
                                  : sprintf(ev_t('TEST.M_ENDPUNKT_FEHL'), (int) $code,
                                            ev_e($text), ev_e($url)));

        case 'mqtt':
            $n = ev_mqtt_publish();
            return array($n > 0 ? 1 : 0, $n > 0 ? sprintf(ev_t('TEST.M_MQTT_OK'), $n)
                                                : ev_t('TEST.M_MQTT_FEHL'));

        case 'token':
            $cfg = ev_config();
            try {
                $cfg['aktionstoken'] = ev_token();
            } catch (RuntimeException $e) {
                // Kein sicherer Zufall vorhanden. Lieber gar kein Token als
                // ein erratbares - und das hier auch sagen.
                return array(0, ev_e($e->getMessage()));
            }
            if (ev_config_write($cfg)) {
                ev_log('Zugriffstoken neu erzeugt');
                return array(1, ev_t('TEST.M_TOKEN_OK'));
            }
            return array(0, ev_t('TEST.M_TOKEN_FEHL'));
    }
    return array(0, ev_t('TEST.M_UNBEKANNT'));
}
