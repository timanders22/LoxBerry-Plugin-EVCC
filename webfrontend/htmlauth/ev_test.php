<?php
/**
 * EVCC fuer LoxBerry - die Aktionen des Reiters Test
 *
 * Getrennt von der Oberflaeche, damit index.php nur Oberflaeche bleibt.
 * Die Selbstpruefung beantwortet OHNE Loxone die Frage: traegt die
 * Einrichtung?
 */

/** Eine Zeile der Selbstpruefung. $stand: 1 gut, 0 schlecht, -1 Hinweis. */
function ev_pruefzeile($stand, $frage, $antwort)
{
    return array((int) $stand, $frage, $antwort);
}

function ev_pruefungen()
{
    $cfg = ev_config();
    $z = array();

    /* ---- EVCC selbst ---- */
    if (ev_dienst_vorhanden()) {
        $z[] = ev_pruefzeile(1, ev_t('TEST.F_INSTALLIERT'),
            sprintf(ev_t('TEST.A_INSTALLIERT'), ev_e(ev_dienst_version())));
    } else {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_INSTALLIERT'), ev_t('TEST.A_NICHT_INSTALLIERT'));
    }

    $laeuft = ev_dienst_laeuft();
    $z[] = ev_pruefzeile($laeuft ? 1 : (ev_dienst_vorhanden() ? 0 : -1),
        ev_t('TEST.F_DIENST'),
        $laeuft ? ev_t('TEST.A_DIENST_LAEUFT') : ev_t('TEST.A_DIENST_TOT'));

    /* ---- Erreichbarkeit ---- */
    $st = ev_state(true);
    if ($st['ok']) {
        $z[] = ev_pruefzeile(1, ev_t('TEST.F_ERREICHBAR'),
            sprintf(ev_t('TEST.A_ERREICHBAR'), ev_e($cfg['url'])));
    } else {
        $z[] = ev_pruefzeile(0, ev_t('TEST.F_ERREICHBAR'),
            sprintf(ev_t('TEST.A_NICHT_ERREICHBAR'), ev_e($cfg['url']), ev_e($st['fehler'])));
    }

    /* ---- Feldzuordnung: der wichtigste Punkt ----
     *
     * Die MQTT-Themen von EVCC sind dokumentiert, die genaue Form von
     * /api/state ist es nicht. Deshalb wird hier nicht behauptet, sondern
     * nachgesehen, welches Feld sich wirklich aufloesen liess. */
    $werte = ev_werte($st);
    $felder = ev_felder();
    $ohne = array();
    $mit = 0;
    foreach ($felder as $name => $d) {
        if (empty($d['pfade'])) { continue; }
        if ($werte[$name]['pfad'] === '') { $ohne[] = $name; } else { $mit++; }
    }
    if (!$ohne) {
        $z[] = ev_pruefzeile(1, ev_t('TEST.F_FELDER'), sprintf(ev_t('TEST.A_FELDER_OK'), $mit));
    } else {
        $z[] = ev_pruefzeile(-1, ev_t('TEST.F_FELDER'),
            sprintf(ev_t('TEST.A_FELDER_FEHLEN'), $mit, count($ohne), ev_e(implode(', ', $ohne))));
    }

    /* ---- Die vier Energiemanager-Groessen einzeln ---- */
    foreach (array('netz_kw' => 'Gpwr', 'pv_kw' => 'Ppwr',
                   'speicher_kw' => 'Spwr', 'speicher_soc' => 'Soc') as $feld => $anschluss) {
        $da = isset($werte[$feld]) && $werte[$feld]['pfad'] !== '';
        $z[] = ev_pruefzeile($da ? 1 : -1,
            sprintf(ev_t('TEST.F_EM_FELD'), $anschluss),
            $da ? sprintf(ev_t('TEST.A_EM_FELD'), $feld, $werte[$feld]['wert'],
                          ev_e($werte[$feld]['pfad']))
                : sprintf(ev_t('TEST.A_EM_FELD_FEHLT'), $feld));
    }

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
    $z[] = ev_pruefzeile(preg_match('/^[A-Za-z0-9]{24,}$/', (string) $cfg['aktionstoken']) ? 1 : 0,
        ev_t('TEST.F_TOKEN'),
        preg_match('/^[A-Za-z0-9]{24,}$/', (string) $cfg['aktionstoken'])
            ? ev_t('TEST.A_TOKEN_OK') : ev_t('TEST.A_TOKEN_FEHLT'));

    $z[] = ev_pruefzeile(-1, ev_t('TEST.F_STEUERUNG'),
        !empty($cfg['steuerung_ein']) ? ev_t('TEST.A_STEUERUNG_EIN') : ev_t('TEST.A_STEUERUNG_AUS'));

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

    /* ---- Vorlage und Zeile muessen dieselben Feldnamen kennen ----
     *
     * Steht in der Vorlage ein Suchmuster, das die Zeile nicht liefert,
     * bleibt der virtuelle Eingang in Loxone stumm - ohne Fehlermeldung. */
    $zeile = ev_zeile($werte);
    $fehlend = array();
    foreach (array_keys($felder) as $name) {
        if (strpos($zeile, ';' . strtoupper($name) . '=') === false
            && strpos($zeile, 'EVCC;' . strtoupper($name) . '=') === false) {
            $fehlend[] = strtoupper($name);
        }
    }
    $z[] = ev_pruefzeile($fehlend ? 0 : 1, ev_t('TEST.F_ABGLEICH'),
        $fehlend ? sprintf(ev_t('TEST.A_ABGLEICH_FEHL'), ev_e(implode(', ', $fehlend)))
                 : sprintf(ev_t('TEST.A_ABGLEICH_OK'), count($felder)));

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
            list($ok, $text) = ev_dienst($was);
            return array($ok, $ok ? sprintf(ev_t('TEST.M_DIENST_OK'), $was)
                                  : sprintf(ev_t('TEST.M_DIENST_FEHL'), $was, ev_e($text)));

        case 'abruf':
            $st = ev_state(true);
            return array($st['ok'], $st['ok'] ? ev_t('TEST.M_ABRUF_OK')
                                              : sprintf(ev_t('TEST.M_ABRUF_FEHL'), ev_e($st['fehler'])));

        case 'mqtt':
            $n = ev_mqtt_publish();
            return array($n > 0 ? 1 : 0, $n > 0 ? sprintf(ev_t('TEST.M_MQTT_OK'), $n)
                                                : ev_t('TEST.M_MQTT_FEHL'));

        case 'token':
            $cfg = ev_config();
            $cfg['aktionstoken'] = ev_token();
            if (ev_config_write($cfg)) {
                ev_log('Zugriffstoken neu erzeugt');
                return array(1, ev_t('TEST.M_TOKEN_OK'));
            }
            return array(0, ev_t('TEST.M_TOKEN_FEHL'));
    }
    return array(0, ev_t('TEST.M_UNBEKANNT'));
}
