#!/bin/bash
# Laeuft als Benutzer loxberry, als allererster Schritt eines Updates.
#
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
ARGV1=$1
ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
LBHOMEDIR="${LBHOMEDIR:-$5}"

# DIE WURZEL WIRD GEPRUEFT, NICHT GEGLAUBT (seit 0.9.32).
#
# Bis 0.9.31 stand hier nur SICHER="$ARGV5/data/plugins/...". War $ARGV5
# leer, zielte die Sicherung auf "/data/plugins/...", mkdir scheiterte als
# loxberry, cp scheiterte stumm (2>/dev/null) - und das Skript meldete
# trotzdem "<OK> Konfiguration gesichert". Danach loeschte der Installer
# config/plugins/<ordner>/ (purge_installation), und das EVCC-Passwort und
# das Token des Endpunkts waren weg.
#
# Ein Verzeichnis zaehlt nur als LoxBerry-Wurzel, wenn es config/plugins,
# data/plugins UND config/system/general.json traegt (Regeln/06). Reihenfolge:
# fuenftes Argument, LBHOMEDIR, dann aufwaerts vom Ablageort dieses Skripts
# (der Installer ruft es im ausgepackten Paket unterhalb von
# data/system/tmp/uploads auf), hoechstens acht Ebenen. Findet sich keine,
# endet das Skript mit 2: plugininstall.pl bricht bei einem Wert groesser 1
# VOR purge_installation ab, die alte Fassung bleibt samt Konfiguration
# stehen. Ein Update ohne Sicherung waere schlimmer als gar keins.
# Dieselbe Bauart wie Kodi NG 1.2.8, dort in WSL mit nachgestelltem
# purge_installation gemessen.
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
if [ -z "$BASE" ]; then
    echo "<FAIL> Das Wurzelverzeichnis des LoxBerry war nicht zu ermitteln (fuenftes"
    echo "<FAIL> Argument: '$ARGV5', LBHOMEDIR: '$LBHOMEDIR', Suche ab dem Ablageort"
    echo "<FAIL> dieses Skripts ohne Treffer). Die Konfiguration kann deshalb NICHT"
    echo "<FAIL> gesichert werden, und das Update wuerde sie loeschen. Das Update wird"
    echo "<FAIL> hier abgebrochen; die bisherige Fassung bleibt unveraendert installiert."
    exit 2
fi
PDIR="${ARGV3:-evcc}"

# DIE MARKE "AKTUALISIERUNG LAEUFT" - ALS ERSTES (I1, Entscheidung 1 vom
# 29.09.2026). preinstall.sh legt ohne sie liegengebliebene Zweitschriften
# nach .alt, postupgrade.sh spielt nur mit ihr zurueck und raeumt sie ueber
# einen trap ab, die Bibliothek legt waehrend der Luecke kein neues Token an,
# und der Minutentakt ruht. Kein Altersvergleich. Lag sie schon (ein frueheres
# Update brach ab), gilt sie weiter (Entscheidung 8, Frage 17). Bricht DIESES
# Skript mit 2 ab, faellt die Marke wieder weg, wenn es sie selbst angelegt
# hat - die alte Fassung bleibt dann unveraendert installiert.
EV_MARKE="$BASE/data/plugins/$PDIR.upgrade_laeuft"
EV_MARKE_VORHER=0
[ -e "$EV_MARKE" ] && EV_MARKE_VORHER=1
if ! touch "$EV_MARKE" 2>/dev/null; then
    echo "<FAIL> Die Marke $EV_MARKE liess sich nicht anlegen - Platz und Rechte unter"
    echo "<FAIL> $BASE/data/plugins pruefen. Das Update wird abgebrochen."
    exit 2
fi
trap 'ev_rc=$?; if [ "$ev_rc" = "2" ] && [ "$EV_MARKE_VORHER" = "0" ]; then rm -f "$EV_MARKE"; fi' EXIT

# Gruppe 4 / MQTT (0.9.45): die Befehlsbruecke anhalten. Die Marke liegt schon (oben) - der Waechter im
# Minutentakt startet sie bis postupgrade.sh nicht neu; es ist dieselbe Marke, die befehlsbruecke.sh liest.
# Bis 0.9.44 gab es keine Bruecke (dann fehlt die Datei).
BBSH="$BASE/bin/plugins/$PDIR/befehlsbruecke.sh"
if [ -f "$BBSH" ]; then
    echo "<INFO> Befehlsbruecke: $(timeout 40 bash "$BBSH" stop 2>&1 | head -n 1)"
fi

# Die Sicherung liegt BEWUSST NICHT unter /tmp.
#
# Auf dem LoxBerry ist /tmp eine Ramdisk. Zwischen preupgrade und postupgrade
# liegt eine Paketinstallation; braucht die einen Neustart oder bricht das
# Update in der Mitte ab, ist die Ramdisk leer - und mit ihr die einzige
# Kopie der Zugangsdaten und aller Einstellungen. Genau deshalb liegt sie
# jetzt unter data/plugins/, also auf der Karte.
# Die Sicherung liegt NEBEN dem Ordner, nicht darin. Gemessen an
# sbin/plugininstall.pl (Zweig master, 23.08.2026): der Installer ruft
# &purge_installation nicht nur beim Deinstallieren, sondern auch im
# Upgrade-Zweig (:886), und deren Rumpf loescht ohne jede Bedingung
# (:1629 ff.) config/plugins/<x>/, bin/plugins/<x>/, data/plugins/<x>/,
# templates/plugins/<x>/ und beide webfrontend/-Ordner. Eine Sicherung IN
# data/plugins/<x>/ wird also von genau dem Schritt vernichtet, den sie
# ueberdauern soll. Der Punkt im Namen ist der ganze Unterschied:
# "rm -rf .../<x>/" trifft den Nachbarn "<x>.upgrade_sicherung" nicht.
SICHER="$BASE/data/plugins/$PDIR.upgrade_sicherung"
NEU="$SICHER.neu"

# NEBEN DEM PLATZ BAUEN, PRUEFEN, UMBENENNEN - DIE ALTE FAELLT ZULETZT.
#
# Bis 0.9.31 wurde in eine vorhandene Sicherung hineinkopiert: Dateien, die
# es in der Konfiguration nicht mehr gab, lebten in der Sicherung weiter und
# kamen beim Zurueckstellen wieder. Und ein abgebrochener Lauf haette eine
# halbe Sicherung hinterlassen, die niemand als halb erkennt.
#
# Jetzt entsteht die neue unter .neu und wird mit diff -r gegen das Original
# geprueft. Erst dann wandert die alte nach .alt, die neue an ihren Platz, und
# zuletzt wird die alte weggeworfen. Misslingt das Umbenennen, kommt die alte
# zurueck. In keinem Augenblick gibt es keine Sicherung.
echo "<INFO> Sichere die Konfiguration nach $SICHER"
rm -rf "$NEU" 2>/dev/null
if ! mkdir -p "$NEU"; then
    echo "<FAIL> $NEU liess sich nicht anlegen - Platz und Rechte unter"
    echo "<FAIL> $BASE/data/plugins pruefen. Das Update wird abgebrochen."
    exit 2
fi
chmod 0700 "$NEU" 2>/dev/null

if [ -d "$BASE/config/plugins/$PDIR" ] \
   && [ -n "$(ls -A "$BASE/config/plugins/$PDIR" 2>/dev/null)" ]; then
    # Rueckgabewert UND Inhalt pruefen - bis 0.9.31 hiess es "gesichert",
    # ohne dass irgendetwas davon nachgesehen wurde.
    if cp -a "$BASE/config/plugins/$PDIR/." "$NEU/" \
       && diff -r "$BASE/config/plugins/$PDIR" "$NEU" >/dev/null 2>&1; then
        # Die Datei enthaelt das Passwort im Klartext - die Kopie auch.
        chmod 0600 "$NEU/evcc.json" 2>/dev/null
        rm -rf "$SICHER.alt" 2>/dev/null
        if [ -e "$SICHER" ] || [ -L "$SICHER" ]; then
            mv "$SICHER" "$SICHER.alt" 2>/dev/null
        fi
        if mv "$NEU" "$SICHER" 2>/dev/null; then
            rm -rf "$SICHER.alt" 2>/dev/null
            echo "<OK> Konfiguration gesichert nach $SICHER."
        else
            if [ -e "$SICHER.alt" ] || [ -L "$SICHER.alt" ]; then
                mv "$SICHER.alt" "$SICHER" 2>/dev/null
            fi
            rm -rf "$NEU" 2>/dev/null
            echo "<FAIL> Die neue Sicherung liess sich nicht an ihren Platz bringen."
            echo "<FAIL> Platz und Rechte unter $BASE/data/plugins pruefen."
            echo "<FAIL> Das Update wird abgebrochen, damit die Konfiguration"
            echo "<FAIL> nicht verlorengeht."
            exit 2
        fi
    else
        rm -rf "$NEU" 2>/dev/null
        echo "<FAIL> Die Konfiguration liess sich NICHT vollstaendig nach $SICHER sichern."
        if [ -d "$SICHER" ]; then
            echo "<FAIL> Die bisherige Sicherung unter $SICHER bleibt unangetastet."
        fi
        echo "<FAIL> Das Update wird abgebrochen, damit sie nicht verlorengeht."
        exit 2
    fi
else
    rm -rf "$NEU" 2>/dev/null
    echo "<INFO> Keine Konfiguration vorhanden - es gibt nichts zu sichern."
    # Eine vorhandene Sicherung stammt aus einem FRUEHEREN Vorgang (I1, B2,
    # Entscheidung 1: "preupgrade.sh raeumt einen alten Bestand weg"). Bis
    # 0.9.33 blieb sie liegen, und postupgrade.sh spielte sie ein - EVCC-
    # Passwort und Token einer frueheren Installation (in WSL gemessen,
    # Pruefbericht installer, B2). Jetzt geht sie nach .alt. Ausnahme: lag die
    # Marke schon, bevor dieses Skript lief, ist ein Update abgebrochen, und
    # die Sicherung ist dessen einzige Abschrift - dann bleibt sie.
    if [ -d "$SICHER" ]; then
        if [ "$EV_MARKE_VORHER" = "1" ]; then
            echo "<INFO> Die Sicherung unter $SICHER bleibt liegen: die Marke eines abgebrochenen"
            echo "<INFO> Updates lag schon, und die Sicherung ist dessen einzige Abschrift."
        else
            rm -rf "${SICHER:?}.alt" 2>/dev/null
            if mv "$SICHER" "$SICHER.alt" 2>/dev/null; then
                echo "<WARNING> Eine Upgrade-Sicherung aus einem frueheren Vorgang lag noch da. Sie wird NICHT eingespielt und liegt jetzt unter $SICHER.alt (die Deinstallation raeumt sie ab)."
            else
                echo "<FAIL> Eine Upgrade-Sicherung aus einem frueheren Vorgang ($SICHER) liess sich nicht"
                echo "<FAIL> beiseitelegen und wuerde eingespielt. Das Update wird abgebrochen."
                exit 2
            fi
        fi
    fi
fi
exit 0
