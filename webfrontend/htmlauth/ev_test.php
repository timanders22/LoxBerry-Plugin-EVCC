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
    if (!is_file($datei)) { return array(-1, 0, ev_t('TEST.A_REITER_NICHT_LESBAR')); }
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
        if (!in_array($n, $liste, true))    { $wo[] = ev_t('TEST.W_POSITIVLISTE'); }
        if (!in_array($n, $leiste, true))   { $wo[] = ev_t('TEST.W_LEISTE'); }
        if (!in_array($n, $bereiche, true)) { $wo[] = ev_t('TEST.W_BEREICH'); }
        if ($wo) { $fehlt[] = sprintf(ev_t('TEST.W_FEHLT_IN'), $n, implode(', ', $wo)); }
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
        $fehlt[] = sprintf(ev_t('TEST.W_AKTIV_LEISTE'), $mit_aktiv_leiste, count($leiste));
    }
    if ($mit_aktiv_seite < count($bereiche)) {
        $fehlt[] = sprintf(ev_t('TEST.W_AKTIV_SEITE'), $mit_aktiv_seite, count($bereiche));
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
    $p = ev_paths();
    $z = array();

    /* Wie alt ist der eigene Abruf? VOR der eigenen Anfrage weiter unten
     * gelesen - die schreibt state.json neu, und das Alter waere immer 0 (O6). */
    $ev_sf = ev_tmpdir() . '/state.json';
    clearstatcache(true, $ev_sf);
    $ev_abruf_alter = is_file($ev_sf) ? max(0, time() - (int) @filemtime($ev_sf)) : -1;

    /* ---- EVCC fragen: EINMAL, mit drei Sekunden Zeitgrenze (O4, seit
     * 0.9.34). Bis 0.9.33 fragte diese Seite EVCC zweimal mit je 8 s und lief
     * bei jedem Seitenaufruf (Regeln/04: "Zeitueberschreitung drei Sekunden
     * statt acht"). Die Zeilen unten lesen diesen einen Stand. ---- */
    $st = ev_state(true, null, 3);

    /* ---- Der eigene Endpunkt. Die wichtigste Zeile dieser Datei. ----
     *
     * DREI Ausgaenge, nicht zwei. Kommt eine HTTP-Antwort, wird sie beurteilt:
     * 200 mit einer EVCC-Zeile ist gut, alles andere ist ein Befund. Kommt
     * ueberhaupt keine Antwort, ist das KEIN Befund am Plugin - ein Webserver,
     * der nur eine Anfrage zugleich bearbeitet, kann sich waehrend dieser
     * Seite nicht selbst aufrufen. Er liest den eben geschriebenen
     * Zwischenspeicher und fragt EVCC nicht noch einmal. */
    list($e_ok, $e_code, $e_text, $e_url) = ev_selbsttest_endpunkt('status', 3);
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

    /* ---- Findet der Endpunkt seine Bibliothek? ---- */
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

    /* ---- Tragen alle Formulare das Merkmal? (O6, Regeln/04 Pflichtzeile) ----
     * Gezaehlt in der eigenen Datei; eine Null ist kein "in Ordnung". */
    list($f_n, $f_mit) = ev_formulare_zaehlen();
    if ($f_n === 0) {
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_FORMULARE'), ev_t('TEST.A_FORMULARE_BLIND'));
    } else {
        $z[] = ev_pruefzeile($f_mit === $f_n ? 1 : 0, ev_t('TEST.F_FORMULARE'),
            sprintf(ev_t($f_mit === $f_n ? 'TEST.A_FORMULARE_OK' : 'TEST.A_FORMULARE_FEHL'), $f_mit, $f_n));
    }

    /* ---- Steht der Cron-Eintrag? (O6) An ALLEN Takt-Orten gesucht
     * (Regeln/04, Raumklima 0.11.8): glob ueber cron.*min. ---- */
    if ($p['home'] === '') {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_CRON'), ev_t('TEST.A_CRON_ARCHIV'));
    } else {
        $ev_cron = array();
        foreach (array_unique(array($p['plugin'], 'evcc')) as $ev_cn) {
            foreach ((array) glob($p['home'] . '/system/cron/cron.*min/' . $ev_cn) as $ev_cf) {
                if (is_string($ev_cf) && $ev_cf !== '') { $ev_cron[] = $ev_cf; }
            }
        }
        $ev_cron = array_values(array_unique($ev_cron));
        $ev_cdir = array_filter($ev_cron, 'is_dir');
        if (!$ev_cron) {
            $z[] = ev_pruefzeile(0, ev_t('TEST.F_CRON'),
                sprintf(ev_t('TEST.A_CRON_FEHLT'), ev_e($p['home'] . '/system/cron/cron.*min/')));
        } elseif ($ev_cdir) {
            $z[] = ev_pruefzeile(0, ev_t('TEST.F_CRON'),
                sprintf(ev_t('TEST.A_CRON_VERZEICHNIS'), ev_e(implode(', ', $ev_cdir))));
        } else {
            $z[] = ev_pruefzeile(1, ev_t('TEST.F_CRON'), sprintf(ev_t('TEST.A_CRON_OK'), ev_e(implode(', ', $ev_cron))));
        }
    }

    /* ---- Arbeitet der Abruf noch? Alter des eigenen Abrufs (O6). state.json
     * wird bei JEDEM Abruf geschrieben, auch bei einem gescheiterten. ---- */
    $ev_grenze = max(90, 3 * (int) $cfg['takt']);
    if ($ev_abruf_alter < 0) {
        $z[] = ev_pruefzeile($p['home'] === '' ? -1 : 0, ev_t('TEST.F_ABRUF_ALTER'), ev_t('TEST.A_ABRUF_NIE'));
    } else {
        $z[] = ev_pruefzeile($ev_abruf_alter <= $ev_grenze ? 1 : 0, ev_t('TEST.F_ABRUF_ALTER'),
            sprintf(ev_t($ev_abruf_alter <= $ev_grenze ? 'TEST.A_ABRUF_OK' : 'TEST.A_ABRUF_ALT'),
                    $ev_abruf_alter, $ev_grenze));
    }

    /* ---- Ist die Konfiguration heil? Gelesen VOR der Heilung (O6). ---- */
    $ev_klage = isset($GLOBALS['ev_konfig_lage_vorher']) ? (string) $GLOBALS['ev_konfig_lage_vorher'] : ev_konfig_lage();
    $ev_klage_stand = array('ok' => 1, 'token_leer' => -1, 'fehlt' => 0, 'leer' => 0, 'kaputt' => 0, 'ohne_token' => 0);
    $z[] = ev_pruefzeile(isset($ev_klage_stand[$ev_klage]) ? $ev_klage_stand[$ev_klage] : -1, ev_t('TEST.F_KONFIG'),
        sprintf(ev_t('TEST.A_KONFIG_' . strtoupper($ev_klage)), ev_e($p['config'])));

    /* ---- EVCC selbst ---- */
    if (ev_dienst_vorhanden()) {
        /* Eine Entwicklerfassung ist kein Fehler - aber sie soll nicht
         * unbemerkt laufen. Beide Schreibweisen (Tilde und Bindestrich). */
        $ev_fassung = ev_dienst_version();
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

    /* ---- Darf die Oberflaeche den Dienst schalten? Gemessen wird die
     * WIRKUNG ueber den LESENDEN Unterbefehl 'status', den die sudo-Regel
     * mit abdeckt. Seit 0.9.34 nur noch hier im Reiter Test (O4). ---- */
    $sudo = '/etc/sudoers.d/loxberry-evcc';
    $ev_sudo_wirkt = -1;
    if (function_exists('exec')) {
        foreach (array('/bin/systemctl', '/usr/bin/systemctl') as $ev_sc) {
            $ev_aus = array();
            $ev_rc = 1;
            @exec('sudo -n ' . $ev_sc . ' status evcc 2>&1', $ev_aus, $ev_rc);
            $ev_txt = strtolower(implode(' ', $ev_aus));
            if (strpos($ev_txt, 'password') !== false || strpos($ev_txt, 'sudo:') !== false) {
                $ev_sudo_wirkt = 0;
                continue;
            }
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

    /* ---- Kann die Oberflaeche EVCC aktualisieren? ---- */
    $ev_lage = ev_update_lage();
    if (empty($cfg['update_ein'])) {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_UPDATE'), ev_t('TEST.A_UPDATE_AUS'));
    } elseif ($ev_lage['skript'] !== 1) {
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_UPDATE'),
            sprintf(ev_t('TEST.A_UPDATE_KEIN_SKRIPT'), EV_UPDATE_SKRIPT));
    } elseif ($ev_lage['gehalten'] === 1) {
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_UPDATE'), ev_t('TEST.A_UPDATE_GEHALTEN'));
    } elseif ($ev_lage['gehalten'] === -1) {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_UPDATE'),
            sprintf(ev_t('TEST.A_UPDATE_UNKLAR'), EV_UPDATE_SKRIPT));
    } elseif ($ev_lage['kandidat'] !== '') {
        $z[] = ev_pruefzeile(1, ev_t('TEST.F_UPDATE'),
            sprintf(ev_t('TEST.A_UPDATE_BEREIT_KAND'), EV_UPDATE_SKRIPT,
                    ev_e($ev_lage['kandidat'])));
    } else {
        $z[] = ev_pruefzeile(1, ev_t('TEST.F_UPDATE'),
            sprintf(ev_t('TEST.A_UPDATE_BEREIT'), EV_UPDATE_SKRIPT));
    }

    /* ---- Erreichbarkeit (derselbe Stand wie oben, keine zweite Anfrage) ---- */
    if ($st['ok']) {
        $z[] = ev_pruefzeile(1, ev_t('TEST.F_ERREICHBAR'),
            sprintf(ev_t('TEST.A_ERREICHBAR'), ev_e($cfg['url'])));
    } else {
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_ERREICHBAR'),
            sprintf(ev_t('TEST.A_NICHT_ERREICHBAR'), ev_e($cfg['url']), ev_e($st['fehler'])));
    }

    /* ---- Laeuft EVCC wirklich, oder antwortet es nur? ----
     *
     * Reihenfolge: Startfehler zuerst. */
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

    /* ---- Was wuerde der Knopf WIRKLICH einspielen? ---- */
    if ($ev_lage['kandidat'] !== '') {
        $ev_hoeher = ev_fassung_neuer($ev_lage['kandidat'], $ein['version']);
        $z[] = ev_pruefzeile($ev_hoeher ? -1 : 1, ev_t('TEST.F_APT_KANDIDAT'),
            sprintf(ev_t($ev_hoeher ? 'TEST.A_APT_KANDIDAT_NEUER'
                                    : 'TEST.A_APT_KANDIDAT_GLEICH'),
                    ev_e($ev_lage['kandidat'])));
    }

    /* ---- Feldzuordnung ----
     *
     * KLASSE 8 (O5, seit 0.9.34): Hat EVCC in DIESEM Aufruf nicht
     * geantwortet, wird ueber die Feldzuordnung nicht geurteilt - bis 0.9.33
     * standen hier vier Haken ueber Werten aus dem alten Zwischenspeicher,
     * waehrend zwei Zeilen darueber "Antwortet EVCC? nein" stand
     * (Pruefbericht oberflaeche, Befund 8). Und "EVCC laeuft" heisst:
     * geantwortet, kein Startfehler, eingerichtet - ein unbekannter
     * Einrichtungszustand ist KEIN "laeuft". */
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
    $ev_antwort = !empty($st['ok']);
    $ev_kaputt = $ev_antwort && ($ein['fatal'] !== '' || $ein['einrichtung'] === 0);
    $ev_laeuft = $ev_antwort && $ein['fatal'] === '' && $ein['einrichtung'] === 1;
    if (!$ev_antwort) {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_FELDER'), ev_t('TEST.A_FELDER_UNBEKANNT'));
    } elseif (!$ohne) {
        $z[] = ev_pruefzeile(1, ev_t('TEST.F_FELDER'), sprintf(ev_t('TEST.A_FELDER_OK'), $mit));
    } elseif ($ev_kaputt) {
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_FELDER'),
            sprintf(ev_t('TEST.A_FELDER_KEIN_BETRIEB'), count($ohne)));
    } elseif ($mit === 0) {
        // Kein einziges Feld aufgeloest, obwohl EVCC antwortet - das ist kein
        // fehlendes Geraet mehr, das ist ein Befund.
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_FELDER'),
            sprintf(ev_t('TEST.A_FELDER_KEINS'), count($ohne)));
    } else {
        $ev_text = sprintf(ev_t('TEST.A_FELDER_FEHLEN'), $mit, count($ohne),
                           ev_e(implode(', ', $ohne)));
        if ($ein['ladepunkte'] === 0) {
            $ev_text .= ' ' . ev_t('TEST.A_FELDER_OHNE_LP');
        }
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_FELDER'), $ev_text);
    }
    $z[] = ev_pruefzeile(-1, ev_t('TEST.F_DOKU'),
        !$ev_antwort ? ev_t('TEST.A_FELDER_UNBEKANNT')
        : ($anz_doku === 0 ? ev_t('TEST.A_DOKU_KEINE')
            : sprintf(ev_t('TEST.A_DOKU'), $mit_doku, $anz_doku,
                      $ohne_doku ? ev_e(implode(', ', $ohne_doku)) : '-')));

    /* ---- Die vier Energiemanager-Groessen einzeln ----
     * Ohne Antwort in diesem Aufruf: grau, ohne Wert (O5). */
    foreach (array('netz_kw' => 'Gpwr', 'pv_kw' => 'Ppwr',
                   'speicher_kw' => 'Spwr', 'speicher_soc' => 'Soc') as $feld => $anschluss) {
        if (!$ev_antwort) {
            $z[] = ev_pruefzeile(-1, sprintf(ev_t('TEST.F_EM_FELD'), $anschluss),
                sprintf(ev_t('TEST.A_EM_FELD_UNBEKANNT'), $feld));
            continue;
        }
        $da = isset($werte[$feld]) && $werte[$feld]['pfad'] !== '';
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
    if (!empty($roh['preis']['ok']) && isset($roh['preis']['anzahl'])) {
        $teile[] = sprintf(ev_t('TEST.A_ZUSATZ_PREIS'), (int) $roh['preis']['anzahl'],
                           (int) $roh['preis']['rang']);
    } elseif (isset($roh['preis'])) {
        $teile[] = ev_t('TEST.A_ZUSATZ_PREIS_KEINE');
    }
    if (isset($roh['prognose']['heute']) && $roh['prognose']['heute'] !== null) {
        $teile[] = sprintf(ev_t('TEST.A_ZUSATZ_PROGNOSE'),
                           ev_e((string) $roh['prognose']['heute']));
    }
    if (!empty($roh['statistik'])) { $teile[] = ev_t('TEST.A_ZUSATZ_STATISTIK'); }
    $z[] = ev_pruefzeile($teile ? (isset($roh['preis']) && empty($roh['preis']['ok']) ? -1 : 1) : -1,
        ev_t('TEST.F_ZUSATZ'),
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

    /* ---- Token und Steuerung ---- */
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

    /* ---- Schreiber-Wache (Energie-1 C1, Entscheidung Nr. 25) ----
     * Ueber eine leere Menge wird nicht geurteilt (Klasse 8): kein Befehl im
     * Fenster ist ein Hinweis, kein Haken. Ein Schreiber ist ein Haken, mehrere sind
     * ein Hinweis (die Wache meldet, sie urteilt nicht), ein unlesbarer Merker ist ein
     * Kreuz. Die Tabelle der Schreiber steht unter der Selbstpruefung. */
    $ev_ww = ev_wache_einstellungen($cfg);
    if ($ev_ww['wache_ein'] !== 1) {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_WACHE'), ev_t('TEST.A_WACHE_AUS'));
    } else {
        list($ev_wzs, $ev_wls) = ev_wache_lesen();
        $ev_wim = array();
        foreach ($ev_wls as $ev_wx) {
            if (abs(time() - $ev_wx['zuletzt']) < 60 * $ev_ww['wache_fenster_min']) { $ev_wim[] = $ev_wx; }
        }
        if ($ev_wzs === 'merker') {
            $z[] = ev_pruefzeile(0, ev_t('TEST.F_WACHE'), sprintf(ev_t('TEST.A_WACHE_MERKER'), ev_e(ev_wache_datei())));
        } elseif (!$ev_wim) {
            $z[] = ev_pruefzeile(-1, ev_t('TEST.F_WACHE'), sprintf(ev_t('TEST.A_WACHE_LEER'), $ev_ww['wache_fenster_min']));
        } elseif (count($ev_wim) === 1) {
            $z[] = ev_pruefzeile(1, ev_t('TEST.F_WACHE'),
                sprintf(ev_t('TEST.A_WACHE_EINER'), ev_e(ev_wache_name($ev_wim[0], ev_t('TEST.W_OHNE_KENNUNG'))), $ev_ww['wache_fenster_min']));
        } else {
            $ev_wn = array();
            foreach ($ev_wim as $ev_wx) { $ev_wn[] = ev_wache_name($ev_wx, ev_t('TEST.W_OHNE_KENNUNG')); }
            $z[] = ev_pruefzeile(-1, ev_t('TEST.F_WACHE'),
                sprintf(ev_t('TEST.A_WACHE_MEHRERE'), count($ev_wim), $ev_ww['wache_fenster_min'], ev_e(implode(', ', $ev_wn))));
        }
    }
    if ($ev_ww['wache_sperren_ein'] !== 1) {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_WACHE_SPERRE'), ev_t('TEST.A_WACHE_SPERRE_AUS'));
    } else {
        list(, , $ev_wsf) = ev_wache_sperre_urteil($ev_ww, '', '');
        $z[] = ($ev_wsf !== '')
            ? ev_pruefzeile(0, ev_t('TEST.F_WACHE_SPERRE'), ev_t('TEST.A_WACHE_SPERRE_LISTE'))
            : ev_pruefzeile(1, ev_t('TEST.F_WACHE_SPERRE'), sprintf(ev_t('TEST.A_WACHE_SPERRE_AN'), ev_e($ev_ww['wache_erlaubt'])));
    }

    /* ---- Zweitschrift und beschaedigte Konfiguration ---- */
    $z[] = ev_pruefzeile(is_file($p['sicherung']) ? 1 : -1, ev_t('TEST.F_SICHERUNG'),
        is_file($p['sicherung'])
            ? sprintf(ev_t('TEST.A_SICHERUNG_OK'), date('d.m.Y H:i', (int) filemtime($p['sicherung'])))
            : ev_t('TEST.A_SICHERUNG_KEINE'));
    if (is_file($p['config'] . '.kaputt')) {
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_KAPUTT'),
            sprintf(ev_t('TEST.A_KAPUTT'), ev_e($p['config'] . '.kaputt')));
    }

    /* ---- Vorlagen wirklich erzeugen und zurueck einlesen ---- */
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

    /* ---- Kachelnamen hoechstens 40 Zeichen (O9, seit 0.9.34) ---- */
    $ev_kom = 0;
    $ev_lang = array();
    foreach ($proben as $paar) {
        if (preg_match_all('/<Virtual(?:InHttp|Out)Cmd Title="([^"]*)" Comment="([^"]*)"/', $paar[1], $ev_m, PREG_SET_ORDER)) {
            foreach ($ev_m as $ev_x1) {
                $ev_kom++;
                $ev_l = (int) preg_match_all('/./us', html_entity_decode($ev_x1[2], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($ev_l > 40) { $ev_lang[] = $ev_x1[1] . ' (' . $ev_l . ')'; }
            }
        }
    }
    if ($ev_kom === 0) {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_KURZ'), ev_t('TEST.A_TITEL_LEER'));
    } else {
        $z[] = ev_pruefzeile($ev_lang ? 0 : 1, ev_t('TEST.F_KURZ'),
            $ev_lang ? sprintf(ev_t('TEST.A_KURZ_FEHL'), count($ev_lang), $ev_kom, ev_e(implode(', ', array_slice($ev_lang, 0, 6))))
                     : sprintf(ev_t('TEST.A_KURZ_OK'), $ev_kom));
    }

    /* ---- Ist die Vorlage unabhaengig vom Zwischenspeicher? ----
     *
     * BEIDE Zweige werden gebildet - ohne Fahrzeugnamen (Ramdisk nach einem
     * Neustart) und mit so vielen, wie eingestellt sind - und verglichen
     * (O5, seit 0.9.34). Bis 0.9.33 zaehlte die Zeile nur den gerade
     * gueltigen Zweig und behauptete "in beiden Faellen"; ein Zweig mit einem
     * Feld weniger blieb gruen (Pruefbericht oberflaeche, Befund 9). */
    $ev_fzn = (int) $cfg['fahrzeuge'];
    if ($ev_fzn === 0) {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_VORLAGE_STABIL'),
            ev_t('TEST.A_VORLAGE_STABIL_KEINE'));
    } else {
        $ev_ohne_n = array();
        $ev_mit_n = array();
        $ev_namen = array();
        for ($ev_i = 1; $ev_i <= $ev_fzn; $ev_i++) { $ev_namen[] = 'probe' . $ev_i; }
        foreach (array_keys(ev_felder(array())) as $n) {
            if (preg_match('/^fz[0-9]+_/', $n)) { $ev_ohne_n[] = $n; }
        }
        foreach (array_keys(ev_felder($ev_namen)) as $n) {
            if (preg_match('/^fz[0-9]+_/', $n)) { $ev_mit_n[] = $n; }
        }
        $ev_gleich = ($ev_ohne_n === $ev_mit_n) && $ev_ohne_n;
        $z[] = ev_pruefzeile($ev_gleich ? 1 : 0, ev_t('TEST.F_VORLAGE_STABIL'),
            $ev_gleich ? sprintf(ev_t('TEST.A_VORLAGE_STABIL_OK'), count($ev_mit_n))
                       : sprintf(ev_t('TEST.A_VORLAGE_STABIL_FEHL'), count($ev_ohne_n), count($ev_mit_n)));
    }

    /* ---- Sind die Bausteintitel ueber BEIDE Vorlagen eindeutig? ---- */
    $ev_titel = array();
    foreach ($proben as $ev_paar) {
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

    /* ---- Stehen die spaeter hinzugekommenen Felder am Ende? ---- */
    $ev_reihe_fehler = array();
    $ev_spaeter_gesehen = '';
    foreach ($felder as $ev_n => $ev_d) {
        $ev_seit = isset($ev_d['seit']) ? (string) $ev_d['seit'] : '0.9.10';
        if ($ev_seit !== '0.9.10') {
            $ev_spaeter_gesehen = $ev_n;
        } elseif ($ev_spaeter_gesehen !== '') {
            $ev_reihe_fehler[] = $ev_n;
        }
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

    /* ---- Nr. 36 b: die Ansage (Ausgabeart, Erreichbarkeit, letzte Ansage) ---- */
    list($ev_ast, $ev_atext) = ev_pruefe_ansage($cfg);
    $z[] = ev_pruefzeile($ev_ast, ev_e(ev_t('DURCHSAGE.PRUEF')), $ev_atext);

    /* ---- Hat jede Einstellung eine Regel fuer das Zurueckspielen? ---- */
    $ev_ohne_regel = array();
    foreach (ev_vorgaben() as $ev_k => $ev_v) {
        if (!ev_wert_pruefen($ev_k, $ev_v)) { $ev_ohne_regel[] = $ev_k; }
    }
    $z[] = ev_pruefzeile($ev_ohne_regel ? 0 : 1, ev_t('TEST.F_SICH_REGELN'),
        $ev_ohne_regel ? sprintf(ev_t('TEST.A_SICH_REGELN_FEHL'), ev_e(implode(', ', $ev_ohne_regel)))
                       : sprintf(ev_t('TEST.A_SICH_REGELN_OK'), count(ev_vorgaben())));

    /* ---- Vorlage und Zeile muessen dieselben Feldnamen kennen ---- */
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

    /* ---- Und das Suchmuster muss eindeutig sein ---- */
    $doppelt = array();
    foreach (array_keys(ev_felder_zeile()) as $name) {
        if (substr_count($zeile, ';' . strtoupper($name) . '=') > 1) { $doppelt[] = $name; }
    }
    $ev_zeilenfelder = count(ev_felder_zeile());
    $z[] = ev_pruefzeile($doppelt ? 0 : 1, ev_t('TEST.F_EINDEUTIG'),
        $doppelt ? sprintf(ev_t('TEST.A_EINDEUTIG_FEHL'), ev_e(implode(', ', $doppelt)))
                 : sprintf(ev_t('TEST.A_EINDEUTIG_OK'),
                           $ev_zeilenfelder - count($doppelt), $ev_zeilenfelder));

    return $z;
}

/**
 * Tragen alle Formulare der Oberflaeche das Merkmal? (O6, Regeln/04)
 * Gezaehlt in index.php: jeder Block von <form bis </form> muss ev_fmt()
 * enthalten. Rueckgabe array(Formulare, davon mit Merkmal).
 */
function ev_formulare_zaehlen()
{
    $t = (string) @file_get_contents(__DIR__ . '/index.php');
    $n = 0;
    $mit = 0;
    if (preg_match_all('#<form\b.*?</form>#is', $t, $m)) {
        foreach ($m[0] as $f) {
            $n++;
            if (strpos($f, 'ev_fmt()') !== false) { $mit++; }
        }
    }
    return array($n, $mit);
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
            /* Aus einem ausgepackten Archiv heraus nichts an der Anlage
             * schalten (seit 0.9.33): ohne installierte Lage kennt das Plugin
             * keine Anlage, und der Knopf traefe den EVCC-Dienst des Rechners,
             * auf dem das Archiv liegt (in WSL gemessen, Pruefung-EVCC-0.9.33,
             * Fall W16). */
            if (ev_paths()['home'] === '') { return array(0, ev_t('TEST.M_ARCHIV')); }
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

        case 'ansage':
            /* Nr. 36 b: die Testansage. Ins Protokoll nur die Kurzform ohne Text und Token. */
            $ev_ak = ev_ansage_k();
            $ev_ar = ansage_testansage(ev_tts(ev_config()), $ev_ak);
            ev_log('Testansage: ' . ansage_kurz($ev_ar));
            if ($ev_ar['stand'] === 1) {
                return array(1, ev_e(ev_t('DURCHSAGE.M_TEST_OK')));
            }
            if ($ev_ar['stand'] === -1) {
                return array(1, sprintf(ev_e(ev_t('DURCHSAGE.M_TEST_NICHTS')), ev_e(ansage_kennung_text($ev_ar['kennung'], $ev_ak))));
            }
            return array(0, sprintf(ev_e(ev_t('DURCHSAGE.M_TEST_FEHL')), ev_e(ansage_kennung_text($ev_ar['kennung'], $ev_ak))));

        case 'abruf':
            $st = ev_state(true);
            return array($st['ok'], $st['ok'] ? ev_t('TEST.M_ABRUF_OK')
                                              : sprintf(ev_t('TEST.M_ABRUF_FEHL'), ev_e($st['fehler'])));

        case 'update':
            if (ev_paths()['home'] === '') { return array(0, ev_t('TEST.M_ARCHIV')); }
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
            /* Der Knopf sendet ALLES, nicht nur Aenderungen (seit 0.9.33).
             * Er meldet, was er weiss: "abgeschickt an den UDP-Eingang <Port>"
             * - ob das Gateway annimmt, bestaetigt der Eingang nicht (M7, seit
             * 0.9.34). Bis 0.9.33 hiess es "72 Themen gesendet", auch ohne
             * lauschendes Gateway (Pruefbericht oberflaeche, Befund 16). */
            $n = ev_mqtt_publish(null, true);
            $ev_mz = ev_mqtt_zustand();
            if ($n > 0) {
                return array(1, sprintf(ev_t('TEST.M_MQTT_OK'), $n, (int) $ev_mz['udpport']));
            }
            $ev_g = isset($GLOBALS['ev_mqtt_grund']) ? (string) $GLOBALS['ev_mqtt_grund'] : '';
            $ev_gk = array('aus' => 'TEST.M_MQTT_AUS', 'kein_port' => 'TEST.M_MQTT_KEIN_PORT',
                           'udp' => 'TEST.M_MQTT_UDP');
            return array(0, sprintf(ev_t(isset($ev_gk[$ev_g]) ? $ev_gk[$ev_g] : 'TEST.M_MQTT_FEHL'),
                                    (int) $ev_mz['udpport']));

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
