#!/bin/bash
# Laeuft als Benutzer loxberry, nach postroot.sh.
ARGV3=$3   # Pluginordner
ARGV5=$5   # LoxBerry-Basisordner

echo "<INFO> Lege Daten- und Protokollordner an"
mkdir -p "$ARGV5/data/plugins/$ARGV3"
mkdir -p "$ARGV5/log/plugins/$ARGV3"
chmod 0775 "$ARGV5/data/plugins/$ARGV3" "$ARGV5/log/plugins/$ARGV3"

# Die Konfiguration traegt das Zugriffstoken und moeglicherweise das
# EVCC-Passwort - deshalb 0600 und nicht 0644.
mkdir -p "$ARGV5/config/plugins/$ARGV3"
if [ -f "$ARGV5/config/plugins/$ARGV3/evcc.json" ]; then
    chmod 0600 "$ARGV5/config/plugins/$ARGV3/evcc.json"
fi

echo "<OK> Fertig. Die Oberflaeche legt beim ersten Aufruf ein Zugriffstoken an."
exit 0
