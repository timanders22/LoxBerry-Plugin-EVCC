#!/bin/bash
# EVCC - preinstall. Laeuft als Benutzer loxberry.
# Argumente wie der Installer sie uebergibt (plugininstall.pl, am Geraet
# gelesen, Regeln/06): <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# Neu in 0.9.34 (I1, Entscheidung 1 vom 29.09.2026), Bauart Bewaesserung
# 0.9.35 / AudiConnect 0.9.22. Der Installer ruft dieses Skript bei JEDEM
# Einbau auf, nach dem Aufraeumen der alten Fassung und VOR dem Kopieren von
# Konfiguration, Cron-Datei und Oberflaeche (sbin/plugininstall.pl:
# preupgrade :846, purge :874, preinstall :877).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes mit der
# Unixzeit anlegt. Seit 0.9.63 nur, solange sie nach der gemeinsamen Regel
# gilt (Frage 112, Block unten); bis dahin genuegte, dass sie lag. Dann tut
# es nichts: die Zweitschrift und die Upgrade-Sicherung braucht postupgrade.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Liegengebliebene Zweitschriften
# einer frueheren Installation (config/plugins/<ordner>.backup.evcc.json mit
# EVCC-Passwort und Aktionstoken) und die Upgrade-Sicherung
# (data/plugins/<ordner>.upgrade_sicherung) gehen nach <name>.alt, gemeldet
# mit genau einer <WARNING>. Bis 0.9.33 zog die Selbstheilung der Bibliothek
# die Zweitschrift beim ersten Takt oder Seitenaufruf, und der unangemeldete
# Endpunkt nahm das alte Token an, bevor jemand die Oberflaeche oeffnete (in
# WSL gemessen, Pruefbericht installer, B1). Die Bibliothek liest .alt nie;
# die Deinstallation raeumt es ab.
#
# Der Schritt, der hier bis 0.9.33 die Zeilenenden mit dos2unix geradeziehen
# sollte, ist entfallen (I4): er nahm $1 als Ordner, aber $1 ist die
# Zufallskennung des Installers - er lief nie und schrieb in jedes
# Installationsprotokoll eine falsche <WARNING> (Pruefbericht installer, B6).
# Das Geradeziehen erledigt der Installer selbst (plugininstall.pl :1194).
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-evcc}"
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

# Die Wurzel wird geprueft, nicht geglaubt - wie in den uebrigen Hakenskripten.
ev_ist_loxberry() {
    [ -n "$1" ] && [ -d "$1/config/plugins" ] && [ -d "$1/data/plugins" ] \
        && [ -f "$1/config/system/general.json" ]
}
BASE=""
for EV_KAND in "$ARGV5" "${LBHOMEDIR:-}"; do
    if ev_ist_loxberry "$EV_KAND"; then
        BASE="$EV_KAND"
        break
    fi
done
if [ -z "$BASE" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$ARGV5') - nichts beiseitegelegt."
    exit 0
fi
# Upgrade-Marke nach der gemeinsamen Regel (Frage 112, 19.11.2026): Codezeilen wortgleich mit dem
# Stammblock Werkzeuge/gemeinsam/takt_upgrade_marke.txt (Minutentakt), hier aber als Bedingung statt
# "exit 0". UPGM_GILT=1 heisst: eine Aktualisierung laeuft - die Marke enthaelt eine Unixzeit, die nicht
# in der Zukunft liegt und juenger als 3600 s ist. Eine aeltere Marke, eine aus der Zukunft und eine
# ohne Unixzeit gelten nicht; liefert die Uhr keine Zahl, gilt sie (geschlossen ausfallen). Bis 0.9.63
# galt hier jede liegende Marke, gleich wie alt und welchen Inhalts.
UPGM_MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
UPGM_GILT=0
if [ -f "$UPGM_MARKE" ]; then
    upgm_jetzt=$(date +%s 2>/dev/null); upgm_seit=$(cat "$UPGM_MARKE" 2>/dev/null)
    case "$upgm_seit" in ''|*[!0-9]*) upgm_seit=0 ;; esac
    case "$upgm_jetzt" in ''|*[!0-9]*|0) UPGM_GILT=1 ;; esac
    if [ "$UPGM_GILT" = 0 ] && [ "$upgm_seit" -le "$upgm_jetzt" ] && [ $((upgm_jetzt - upgm_seit)) -lt 3600 ]; then UPGM_GILT=1; fi
fi
[ "$UPGM_GILT" = 1 ] && exit 0

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER".backup.evcc.json* \
            "$BASE/config/plugins/$PFOLDER/evcc.backup.json" \
            "$BASE/data/plugins/$PFOLDER".upgrade_sicherung*; do
    [ -e "$ZIEL" ] || [ -L "$ZIEL" ] || continue
    case "$ZIEL" in *.alt) continue ;; esac
    rm -rf "${ZIEL:?}.alt" 2>/dev/null
    if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
        BEISEITE="$BEISEITE $(basename "$ZIEL").alt"
        [ -f "$ZIEL.alt" ] && [ ! -L "$ZIEL.alt" ] && chmod 600 "$ZIEL.alt" 2>/dev/null
    else
        FEST="$FEST $ZIEL"
    fi
done
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    T="<WARNING> Neuinstallation: EVCC-Passwort, Aktionstoken und Einstellungen einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && T="$T Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$T"
fi
exit 0
