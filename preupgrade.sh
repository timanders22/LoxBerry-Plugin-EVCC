#!/bin/bash
# Laeuft als Benutzer loxberry, als allererster Schritt eines Updates.
ARGV1=$1
ARGV3=$3
ARGV5=$5

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
SICHER="$ARGV5/data/plugins/$ARGV3.upgrade_sicherung"

echo "<INFO> Sichere die Konfiguration"
mkdir -p "$SICHER"
chmod 0700 "$SICHER" 2>/dev/null
if [ -d "$ARGV5/config/plugins/$ARGV3" ]; then
    cp -a "$ARGV5/config/plugins/$ARGV3/." "$SICHER/" 2>/dev/null
    # Die Datei enthaelt das Passwort im Klartext - die Kopie auch.
    chmod 0600 "$SICHER/evcc.json" 2>/dev/null
    echo "<OK> Konfiguration gesichert nach $SICHER."
else
    echo "<INFO> Keine Konfiguration vorhanden."
fi
exit 0
