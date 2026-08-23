#!/bin/bash
# Laeuft als Benutzer loxberry, nach dem Update.
ARGV3=$3
ARGV5=$5

SICHER="$ARGV5/data/plugins/$ARGV3.upgrade_sicherung"

# Der alte Ort wird noch gelesen: wer von 0.9.0 kommt, hat seine Sicherung
# im preupgrade DIESER Fassung schon am neuen Ort - aber ein abgebrochenes
# Update von frueher kann dort noch etwas liegen haben.
if [ ! -d "$SICHER" ] && [ -d "/tmp/${ARGV3}_upgrade" ]; then
    SICHER="/tmp/${ARGV3}_upgrade"
fi

if [ -d "$SICHER" ]; then
    echo "<INFO> Stelle die Konfiguration zurueck"
    mkdir -p "$ARGV5/config/plugins/$ARGV3"
    cp -a "$SICHER/." "$ARGV5/config/plugins/$ARGV3/" 2>/dev/null
    chmod 0600 "$ARGV5/config/plugins/$ARGV3/evcc.json" 2>/dev/null
    # Sperrdatei der Tokenerzeugung nicht mitschleppen.
    rm -f "$ARGV5/config/plugins/$ARGV3/.token.lock"
    rm -rf "$SICHER"
    rm -rf "/tmp/${ARGV3}_upgrade"
    echo "<OK> Konfiguration zurueckgestellt."
else
    echo "<INFO> Keine gesicherte Konfiguration gefunden."
fi
exit 0
