#!/bin/bash
# Laeuft als Benutzer loxberry, vor der Installation.
#
# Argumente: <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
ARGV1=$1   # Temporaerordner
ARGV5=$5   # LoxBerry-Basisordner

# Zeilenenden im ausgepackten Ordner geradeziehen. Bewusst mit gesetzter
# Variablen und Leerpruefung: ein leeres $ARGV1 wuerde den Befehl auf das
# gesamte Elternverzeichnis loslassen.
if [ -n "$ARGV1" ] && [ -d "$ARGV1" ]; then
    find "$ARGV1" -type f \( -name '*.sh' -o -name '*.php' -o -name '*.ini' -o -name '*.cfg' \) \
        -print0 | xargs -0 -r dos2unix -q 2>/dev/null
    echo "<OK> Zeilenenden geprueft."
else
    echo "<WARNING> Temporaerordner nicht gefunden - Zeilenenden nicht geprueft."
fi

exit 0
