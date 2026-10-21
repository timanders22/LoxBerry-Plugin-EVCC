#!/bin/bash
# Laeuft als Benutzer loxberry, nach dem Update.
ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
LBHOMEDIR="${LBHOMEDIR:-$5}"

# DIE WURZEL WIRD GEPRUEFT, NICHT GEGLAUBT - dieselbe Pruefung wie in
# preupgrade.sh (dort begruendet). Findet sich keine, wird NICHTS
# zurueckgespielt und NICHTS geloescht, und das Skript endet mit 1. Nicht
# mit 2: an dieser Stelle hat der Installer die alte Fassung schon
# entfernt; mit 1 laeuft die Installation weiter und fuehrt die Zeile in
# ihrer Fehlerliste.
ev_ist_loxberry() {
    [ -n "$1" ] && [ -d "$1/config/plugins" ] && [ -d "$1/data/plugins" ] \
        && [ -f "$1/config/system/general.json" ]
}
ev_wurzel_suchen() {
    v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd)
    i=0
    while [ -n "$v" ] && [ "$v" != "/" ] && [ $i -lt 8 ]; do
        if ev_ist_loxberry "$v"; then
            echo "$v"
            return 0
        fi
        v=$(dirname "$v")
        i=$((i + 1))
    done
    return 1
}
BASE=""
for EV_KAND in "$ARGV5" "$LBHOMEDIR"; do
    if ev_ist_loxberry "$EV_KAND"; then
        BASE="$EV_KAND"
        break
    fi
done
if [ -z "$BASE" ]; then
    BASE=$(ev_wurzel_suchen)
fi
PDIR="${ARGV3:-evcc}"
if [ -z "$BASE" ]; then
    echo "<FAIL> Das Wurzelverzeichnis des LoxBerry war nicht zu ermitteln (fuenftes"
    echo "<FAIL> Argument: '$ARGV5', LBHOMEDIR: '$LBHOMEDIR'). Die gesicherte"
    echo "<FAIL> Konfiguration wurde deshalb NICHT zurueckgespielt und auch nicht"
    echo "<FAIL> geloescht. Sie liegt unter <LoxBerry>/data/plugins/$PDIR.upgrade_sicherung/"
    echo "<FAIL> und gehoert von Hand nach <LoxBerry>/config/plugins/$PDIR/ kopiert."
    exit 1
fi

SICHER="$BASE/data/plugins/$PDIR.upgrade_sicherung"

# NUR MIT MARKE ZURUECKSPIELEN (I1, Entscheidung 1). preupgrade.sh legt die
# Marke als Erstes an; der trap raeumt sie in JEDEM Fall am Ende dieses
# Skripts ab. Ohne Marke ist das hier kein Update, das dieses Plugin
# begonnen hat - dann wird nichts eingespielt, die Sicherung bleibt liegen.
EV_MARKE="$BASE/data/plugins/$PDIR.upgrade_laeuft"
# Gruppe 4 / MQTT (0.9.45): nach dem Entfernen der Marke die Befehlsbruecke starten (startet nur mit dem Haken
# "Befehle ueber MQTT annehmen", ab Werk aus; sonst sagt sie "Haken aus"). Im trap, damit es nach jedem Ausgang
# dieses Skripts geschieht - die Marke faellt erst dort. Gelingt es nicht, startet sie der Minutentakt.
ev_bruecke_start() {
    BBSH="$BASE/bin/plugins/$PDIR/befehlsbruecke.sh"
    [ -f "$BBSH" ] || return 0
    BB_AUS=$(timeout 40 bash "$BBSH" start 2>&1 | head -n 1)
    case "$BB_AUS" in
        *gestartet*|*laeuft*) echo "<OK> Befehlsbruecke: $BB_AUS" ;;
        "Haken aus - kein Start.") echo "<INFO> Befehlsbruecke: aus (Reiter MQTT, Haken 'Befehle ueber MQTT annehmen')." ;;
        *) echo "<WARNING> Befehlsbruecke: $BB_AUS - der Minutentakt versucht es erneut." ;;
    esac
}
trap 'rm -f "$EV_MARKE" 2>/dev/null; ev_bruecke_start' EXIT
if [ ! -e "$EV_MARKE" ]; then
    echo "<WARNING> Es fehlt die Marke einer laufenden Aktualisierung ($EV_MARKE)."
    echo "<WARNING> Es wird nichts zurueckgespielt; eine Sicherung unter $SICHER bleibt liegen."
    exit 0
fi

# Der alte Ort wird noch gelesen: ein abgebrochenes Update von 0.9.0 oder
# frueher kann dort noch etwas liegen haben.
if [ ! -d "$SICHER" ] && [ -d "/tmp/${PDIR}_upgrade" ]; then
    SICHER="/tmp/${PDIR}_upgrade"
fi

if [ -d "$SICHER" ] && [ -n "$(ls -A "$SICHER" 2>/dev/null)" ]; then
    echo "<INFO> Stelle die Konfiguration zurueck"
    mkdir -p "$BASE/config/plugins/$PDIR"
    # Erst pruefen, dann die Sicherung loeschen. Bis 0.9.31 hiess es
    # unbedingt "zurueckgestellt", und danach war die Sicherung weg - auch
    # wenn cp gescheitert war. Jetzt bleibt sie in diesem Fall liegen.
    if cp -a "$SICHER/." "$BASE/config/plugins/$PDIR/" \
       && diff -r "$SICHER" "$BASE/config/plugins/$PDIR" >/dev/null 2>&1; then
        chmod 0600 "$BASE/config/plugins/$PDIR/evcc.json" 2>/dev/null
        # Sperrdatei der Tokenerzeugung nicht mitschleppen.
        rm -f "$BASE/config/plugins/$PDIR/.token.lock"
        # .neu und .alt legt preupgrade.sh seit 0.9.32 an; ein abgebrochener
        # Lauf kann sie hinterlassen, mit demselben Passwort darin.
        rm -rf "$BASE/data/plugins/$PDIR.upgrade_sicherung" \
               "$BASE/data/plugins/$PDIR.upgrade_sicherung.neu" \
               "$BASE/data/plugins/$PDIR.upgrade_sicherung.alt" 2>/dev/null
        rm -rf "/tmp/${PDIR}_upgrade"
        # Den Inhalt pruefen, nicht nur das Kopieren (I7, seit 0.9.34), und
        # die Zweitschrift aus der zurueckgestellten Datei erneuern (I6): ein
        # Takt in der Luecke ohne Zweitschrift konnte bis 0.9.33 eine
        # Zweitschrift mit fremdem Token hinterlassen (Pruefbericht
        # installer, B10); <OK> stand auch ueber einer kaputten Datei (B11).
        EV_CF="$BASE/config/plugins/$PDIR/evcc.json"
        EV_ZS="$BASE/config/plugins/$PDIR.backup.evcc.json"
        EV_PRUEF=3
        if command -v php >/dev/null 2>&1; then
            php -r '$j = json_decode((string) @file_get_contents($argv[1]), true); if (!is_array($j)) { exit(2); } exit((isset($j["aktionstoken"]) && is_string($j["aktionstoken"]) && $j["aktionstoken"] !== "") ? 0 : 1);' "$EV_CF" 2>/dev/null
            EV_PRUEF=$?
        fi
        case "$EV_PRUEF" in
            0)
                if ( umask 077 && cp "$EV_CF" "$EV_ZS.neu.$$" ) 2>/dev/null && chmod 0600 "$EV_ZS.neu.$$" \
                   && mv -f "$EV_ZS.neu.$$" "$EV_ZS" 2>/dev/null; then
                    echo "<OK> Konfiguration zurueckgestellt, Zweitschrift daraus erneuert."
                else
                    rm -f "$EV_ZS.neu.$$" 2>/dev/null
                    echo "<OK> Konfiguration zurueckgestellt."
                    echo "<WARNING> Die Zweitschrift $EV_ZS liess sich nicht erneuern."
                fi
                ;;
            1)
                echo "<OK> Konfiguration zurueckgestellt (ohne Aktionstoken - die Zweitschrift bleibt, wie sie ist)."
                ;;
            2)
                echo "<WARNING> Die zurueckgestellte Konfiguration $EV_CF ist kein gueltiges JSON."
                echo "<WARNING> Die Oberflaeche und der Abruf heilen sie beim ersten Aufruf aus der Zweitschrift;"
                echo "<WARNING> die beschaedigte Datei bleibt dann als evcc.json.kaputt liegen."
                ;;
            *)
                echo "<OK> Konfiguration zurueckgestellt (Inhalt nicht geprueft: php fehlt)."
                ;;
        esac
    else
        chmod 0600 "$BASE/config/plugins/$PDIR/evcc.json" 2>/dev/null
        echo "<FAIL> Die Konfiguration liess sich NICHT vollstaendig zurueckstellen."
        echo "<FAIL> Die Sicherung bleibt unter $SICHER liegen;"
        echo "<FAIL> bitte von Hand nach $BASE/config/plugins/$PDIR/ kopieren."
        exit 1
    fi
else
    echo "<INFO> Keine gesicherte Konfiguration gefunden."
fi
exit 0
