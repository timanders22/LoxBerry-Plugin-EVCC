#!/bin/bash
# Laeuft als Benutzer loxberry, nach postroot.sh - bei der Erstinstallation
# UND bei jedem Update (vor postupgrade.sh; LoxBerry uebergibt kein
# Kennzeichen, Regeln/06).
# Argumente: <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
ARGV3=$3   # Pluginordner
ARGV5=$5   # LoxBerry-Basisordner
PDIR="${ARGV3:-evcc}"
case "$PDIR" in
    ''|.|..|*/*)
        echo "<WARNING> Unbrauchbarer Pluginordner '$PDIR' - es wurde nichts angelegt."
        exit 1
        ;;
esac

# DIE WURZEL WIRD GEPRUEFT, NICHT GEGLAUBT (seit 0.9.33) - dieselbe Pruefung
# wie in preupgrade.sh und postupgrade.sh (dort begruendet). Bis 0.9.32 legte
# dieses Skript die Ordner unter "$5/..." an, ohne $5 anzusehen: mit einem
# fremden Baum als fuenftem Argument entstanden dort data/, log/ und config/
# des Plugins, und ohne fuenftes Argument meldete es "<OK> Fertig", waehrend
# mkdir an /data/plugins scheiterte (in WSL gemessen, Pruefung-EVCC-0.9.33,
# Faelle W5, W6). Ohne Wurzel wird gewarnt und nichts angelegt.
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
for EV_KAND in "$ARGV5" "${LBHOMEDIR:-}"; do
    if ev_ist_loxberry "$EV_KAND"; then
        BASE="$EV_KAND"
        break
    fi
done
if [ -z "$BASE" ]; then
    BASE=$(ev_wurzel_suchen) || BASE=""
fi
if [ -z "$BASE" ]; then
    echo "<WARNING> Das Wurzelverzeichnis des LoxBerry war nicht zu ermitteln (fuenftes"
    echo "<WARNING> Argument: '$ARGV5', LBHOMEDIR: '${LBHOMEDIR:-}', Suche ab dem Ablageort"
    echo "<WARNING> dieses Skripts ohne Treffer). Es wurde nichts angelegt."
    exit 1
fi

echo "<INFO> Lege Daten- und Protokollordner an"
mkdir -p "$BASE/data/plugins/$PDIR"
mkdir -p "$BASE/log/plugins/$PDIR"
chmod 0775 "$BASE/data/plugins/$PDIR" "$BASE/log/plugins/$PDIR"

# Die Konfiguration traegt das Zugriffstoken und moeglicherweise das
# EVCC-Passwort - deshalb 0600 und nicht 0644.
mkdir -p "$BASE/config/plugins/$PDIR"
if [ -f "$BASE/config/plugins/$PDIR/evcc.json" ]; then
    chmod 0600 "$BASE/config/plugins/$PDIR/evcc.json"
fi

# Der Schlusssatz haengt an der Lage (seit 0.9.33, Regel aus
# Bestand-2026-09-18/AUFTRAG_postinstall-hinweis_2026-09-24.md). Bei einem
# Update laeuft dieses Skript VOR postupgrade.sh; die Einstellungen liegen dann
# noch in der Upgrade-Sicherung, die preupgrade.sh angelegt hat. Traegt sie ein
# Zugriffstoken, ist das ein Update einer eingerichteten Anlage, und der Satz
# "Die Oberflaeche legt beim ersten Aufruf ein Zugriffstoken an" stimmt nicht:
# das Token ist da, und die Adressen im Miniserver gelten weiter. Bis 0.9.32
# stand er unbedingt da (Fall P2).
EV_SICHER="$BASE/data/plugins/$PDIR.upgrade_sicherung/evcc.json"
if [ -f "$EV_SICHER" ] && grep -Eq '"aktionstoken"[[:space:]]*:[[:space:]]*"[^"]+"' "$EV_SICHER" 2>/dev/null; then
    echo "<OK> Aktualisierung: Einstellungen und Zugriffstoken liegen in der Upgrade-Sicherung;"
    echo "<OK> postupgrade.sh spielt sie gleich zurueck. Es ist nichts neu einzutragen."
else
    echo "<OK> Fertig. Die Oberflaeche legt beim ersten Aufruf ein Zugriffstoken an."
fi
exit 0
