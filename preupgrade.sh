#!/bin/bash
# Laeuft als Benutzer loxberry, als allererster Schritt eines Updates.
ARGV1=$1
ARGV3=$3
ARGV5=$5

echo "<INFO> Sichere die Konfiguration"
mkdir -p "/tmp/${ARGV3}_upgrade"
if [ -d "$ARGV5/config/plugins/$ARGV3" ]; then
    cp -a "$ARGV5/config/plugins/$ARGV3/." "/tmp/${ARGV3}_upgrade/" 2>/dev/null
    echo "<OK> Konfiguration gesichert."
else
    echo "<INFO> Keine Konfiguration vorhanden."
fi
exit 0
