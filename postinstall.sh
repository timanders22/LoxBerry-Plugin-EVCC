#!/bin/bash
# Laeuft als Benutzer loxberry, VOR postroot.sh - bei der Erstinstallation
# UND bei jedem Update (vor postupgrade.sh). Reihenfolge in plugininstall.pl:
# postinstall :1305, postupgrade :1330, postroot :1358 (Pruefbericht
# installer, B8; bis 0.9.33 stand hier "nach postroot.sh", I5). EVCC und die
# sudo-Regel gibt es an dieser Stelle also bei einer Erstinstallation noch
# nicht. Ob es ein Update ist, sagt die Marke <ordner>.upgrade_laeuft.
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

# Der Schlusssatz haengt an der MARKE, nicht am Inhalt der Sicherung (I2,
# seit 0.9.34; Entscheidung 1). Bis 0.9.33 riet er die Lage aus der
# Upgrade-Sicherung und lag in beiden Richtungen falsch: bei einer
# Neuinstallation mit liegengebliebener Sicherung versprach er ein
# Zurueckspielen, das nie kommt (postupgrade.sh laeuft dort nicht), und bei
# einem Update mit beschaedigter evcc.json kuendigte er ein neues Token an
# (Pruefbericht installer, B3).
# Gruppe 4 / MQTT (0.9.45): bei einer Neuinstallation die Befehlsbruecke starten (startet nur mit dem Haken
# "Befehle ueber MQTT annehmen", ab Werk aus). Bei einem Update startet sie postupgrade.sh nach der Marke.
BBSH="$BASE/bin/plugins/$PDIR/befehlsbruecke.sh"
if [ ! -f "$BASE/data/plugins/$PDIR.upgrade_laeuft" ] && [ -f "$BBSH" ]; then
    BB_AUS=$(timeout 40 bash "$BBSH" start 2>&1 | head -n 1)
    case "$BB_AUS" in
        *gestartet*|*laeuft*) echo "<OK> Befehlsbruecke: $BB_AUS" ;;
        "Haken aus - kein Start.") echo "<INFO> Befehlsbruecke: aus (Reiter MQTT, Haken 'Befehle ueber MQTT annehmen')." ;;
        *) echo "<WARNING> Befehlsbruecke: $BB_AUS - der Minutentakt versucht es erneut." ;;
    esac
fi

if [ -f "$BASE/data/plugins/$PDIR.upgrade_laeuft" ]; then
    echo "<OK> Aktualisierung: postupgrade.sh spielt Einstellungen und Zugriffstoken gleich"
    echo "<OK> zurueck. Es ist nichts neu einzutragen."
else
    echo "<OK> Fertig. Die Oberflaeche legt beim ersten Aufruf ein Zugriffstoken an."
fi
exit 0
