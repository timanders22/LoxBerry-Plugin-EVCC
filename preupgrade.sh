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
SICHER="$ARGV5/data/plugins/$ARGV3/upgrade_sicherung"

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
