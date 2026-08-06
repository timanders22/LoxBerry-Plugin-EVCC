#!/bin/bash
# Laeuft als Benutzer loxberry, nach dem Update.
ARGV3=$3
ARGV5=$5

if [ -d "/tmp/${ARGV3}_upgrade" ]; then
    echo "<INFO> Stelle die Konfiguration zurueck"
    mkdir -p "$ARGV5/config/plugins/$ARGV3"
    cp -a "/tmp/${ARGV3}_upgrade/." "$ARGV5/config/plugins/$ARGV3/" 2>/dev/null
    rm -rf "/tmp/${ARGV3}_upgrade"
    chmod 0600 "$ARGV5/config/plugins/$ARGV3/evcc.json" 2>/dev/null
    echo "<OK> Konfiguration zurueckgestellt."
fi
exit 0
