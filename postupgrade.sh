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
        echo "<OK> Konfiguration zurueckgestellt."
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
