<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie EVCC.
 *
 * Gruppe 4 / MQTT (0.9.45; Bauvorschrift wz_bild/GRUPPE4_BAUVORSCHRIFT.md, Entwurf Musterprojekt/entwurf_gruppe4_ev.txt
 * Abschnitt 3.2, ENTSCHEIDUNGEN_GRUPPE4_MQTT.md; Muster vb_g1_bruecke_bau/beispiel/bin/befehle_zuordnung.php und
 * vb_g3_gv_bau/bin/befehle_zuordnung.php). Die Bruecke selbst (bin/befehlsbruecke.php und .sh) ist byte-gleich die
 * Stammfassung Werkzeuge/gemeinsam/ - linieneigen ist nur diese Datei.
 *
 * Regeln (Entwurf gemeinsam 2.5): die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts.
 * 'tun' ruft DIESELBE Funktion wie der HTTP-Endpunkt webfrontend/html/index.php - ev_befehl_ausfuehren() - mit Quelle
 * 'mqtt' (Haken, Wertpruefung ev_befehl_pruefen(), Schreiber-Wache mit Kennung "mqtt", Befehlsbremse samt Nachreichen,
 * Ladeplan aus zwei Werten, Senden an EVCC) und gibt array(bool ok, string grund) zurueck (ev_befehl_bruecke()).
 * Das Token des Endpunkts ersetzen die Anmeldung am Broker und die beiden Haken (Entwurf gemeinsam E13).
 *
 * EVCC selbst nimmt mit eigenem mqtt-Abschnitt auch evcc/.../set an - Standard ist aber diese Bruecke
 * (ENTSCHEIDUNGEN Gruppe 4 Punkt 1, Entwurf ev E1). 15 Befehle je Ladepunkt unter <praefix>/befehl/lp<N>/...
 * (lp1 bis lp4, EV_LADEPUNKTE), 7 fuer die Anlage. Alle 22 greifen ein und stehen hinter dem zweiten Haken
 * "Steuerung aus Loxone zulassen" (steuerung_ein, E2) - derselbe Haken sperrt den HTTP-Weg.
 * Sperrzeit 0 s fuer alle (E5): die Befehlsbremse des Plugins gilt.
 * Platzhalter: die Stammfassung schreibt fuer die Art 'zahl' <v.1>; Preisgrenze, Energieziel und Netzladegrenze
 * brauchen <v.3> - das setzt ev_bb_liste() (E7), die Stammfassung bleibt unberuehrt.
 */
if (!function_exists('ev_befehl_ausfuehren')) {
    $ev_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $ev_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/htmlauth/plugins/' . basename(__DIR__) . '/ev_lib.php';
    } else {
        $ev_kand[] = dirname(__DIR__) . '/webfrontend/htmlauth/ev_lib.php';
    }
    foreach ($ev_kand as $ev_k) {
        if (is_file($ev_k)) { require_once $ev_k; break; }
    }
}
$ev_ohne_sperre = 'Die Befehlsbremse des Plugins gilt (gleicher Wert 60 s -> UNVERAENDERT, anderer Wert fruehestens nach '
    . '10 s, der letzte abgewiesene wird nachgereicht, ev_lib.php ev_bremse_urteil()). Eine zweite Sperre in der Bruecke '
    . 'verschluckte einen gewollten Rueckwechsel (A -> B -> A binnen 60 s), den das Nachreichen sonst richtig zu Ende '
    . 'braechte (Entwurf ev E5).';
/* Ein Befehl: $art/$min/$max fuer die Vorpruefung der Bruecke, $aktion fuer ev_befehl_ausfuehren(). */
$ev_b = function ($aktion, array $art, $titel, $lp) use ($ev_ohne_sperre) {
    return $art + array('eingreifend' => true, 'sperre_s' => 0, 'begruendung' => $ev_ohne_sperre,
        'titel' => $titel, 'kachel' => 'AUS.' . strtoupper($aktion), 'quittung' => 'ausgefuehrt',
        'tun' => function ($w, $t) use ($aktion, $lp) {
            return ev_befehl_bruecke(ev_befehl_ausfuehren($aktion, array(
                'lp' => $lp ? (string) (int) substr((string) $t['lp'], 2) : null, 'wert' => (string) $w,
                'stunden' => null, 'von' => 'mqtt', 'ip' => '', 'probe' => false, 'anrufer' => 'mqtt'), 'mqtt'));
        });
};
$ganz = function ($min, $max) { return array('art' => 'ganz', 'min' => $min, 'max' => $max); };
$zahl = function ($min, $max) { return array('art' => 'zahl', 'min' => $min, 'max' => $max); };
$puls = array('art' => 'puls');
$schalter = array('art' => 'schalter');
return array(
    'fassung' => 1,
    'linie'   => 'evcc',
    'plugin'  => 'EVCC',
    'geladen' => function_exists('ev_config') && function_exists('ev_befehl_ausfuehren') && function_exists('ev_befehl_bruecke'),
    'zweiter_haken' => 'steuerung_ein',
    'einstellungen' => function () {
        return ev_befehle_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        ev_log('Befehlsbruecke ' . ($stufe === 'INFO' ? '' : $stufe . ': ') . $text);
    },
    'teile' => array('lp' => 'lp[1-4]'),
    'befehle' => array(
        '{lp}/modus'           => $ev_b('modus', $ganz(0, 3), 'EVCC Lademodus Ladepunkt (MQTT)', true),
        '{lp}/limitsoc'        => $ev_b('limitsoc', $ganz(0, 100), 'EVCC Ladegrenze Ladepunkt (MQTT)', true),
        '{lp}/minsoc'          => $ev_b('minsoc', $ganz(0, 100), 'EVCC Mindestladung Ladepunkt (MQTT)', true),
        '{lp}/phasen'          => $ev_b('phasen', $ganz(0, 3), 'EVCC Phasen Ladepunkt (MQTT)', true),
        '{lp}/minstrom'        => $ev_b('minstrom', $ganz(0, 80), 'EVCC Kleinster Ladestrom Ladepunkt (MQTT)', true),
        '{lp}/maxstrom'        => $ev_b('maxstrom', $ganz(0, 80), 'EVCC Höchster Ladestrom Ladepunkt (MQTT)', true),
        '{lp}/prioritaet'      => $ev_b('prioritaet', $ganz(0, 10), 'EVCC Priorität Ladepunkt (MQTT)', true),
        '{lp}/smartcostlimit'  => $ev_b('smartcostlimit', $zahl(-10, 10), 'EVCC Preisgrenze Ladepunkt (MQTT)', true),
        '{lp}/batterieboost'   => $ev_b('batterieboost', $schalter, 'EVCC Battery Boost Ladepunkt (MQTT)', true),
        '{lp}/plansoc_ziel'    => $ev_b('plansoc_ziel', $ganz(0, 100), 'EVCC Abfahrt Ziel-Ladestand Ladepunkt (MQTT)', true),
        '{lp}/plansoc_stunden' => $ev_b('plansoc_stunden', $zahl(0.25, 168), 'EVCC Abfahrt in Stunden Ladepunkt (MQTT)', true),
        '{lp}/planaus'         => $ev_b('planaus', $puls, 'EVCC Ladeplan löschen Ladepunkt (MQTT)', true),
        '{lp}/limitenergie'    => $ev_b('limitenergie', $zahl(0, 500), 'EVCC Energieziel Ladepunkt (MQTT)', true),
        '{lp}/fahrzeug'        => $ev_b('fahrzeug', array('art' => 'text', 'max_byte' => 64), 'EVCC Fahrzeug wählen Ladepunkt (MQTT)', true),
        '{lp}/fahrzeugaus'     => $ev_b('fahrzeugaus', $puls, 'EVCC Fahrzeug-Zuordnung lösen Ladepunkt (MQTT)', true),
        'batteriemodus'        => $ev_b('batteriemodus', $ganz(0, 2), 'EVCC Hausspeicher Betriebsart (MQTT)', false),
        'prioritaetssoc'       => $ev_b('prioritaetssoc', $ganz(0, 100), 'EVCC Speicher vor Auto bis (MQTT)', false),
        'puffersoc'            => $ev_b('puffersoc', $ganz(0, 100), 'EVCC Speicher hilft dem Auto ab (MQTT)', false),
        'residualleistung'     => $ev_b('residualleistung', $ganz(-100000, 100000), 'EVCC Residualleistung (MQTT)', false),
        'entladeregelung'      => $ev_b('entladeregelung', $schalter, 'EVCC Entladeregelung Hausspeicher (MQTT)', false),
        'netzladegrenze'       => $ev_b('netzladegrenze', $zahl(-10, 10), 'EVCC Netzladen Hausspeicher bis (MQTT)', false),
        'netzladenaus'         => $ev_b('netzladenaus', $puls, 'EVCC Netzladen Hausspeicher aus (MQTT)', false),
    ),
);
