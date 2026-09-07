#!/bin/bash
# Laeuft als Benutzer loxberry, vor der Installation.
#
# Argumente: <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
ARGV1=$1   # Temporaerordner
ARGV5=$5   # LoxBerry-Basisordner

# Zeilenenden im ausgepackten Ordner geradeziehen. Bewusst mit gesetzter
# Variablen und Leerpruefung: ein leeres $ARGV1 wuerde den Befehl auf das
# gesamte Elternverzeichnis loslassen.
# dos2unix steht in dpkg/apt und wird damit mitinstalliert. Trotzdem wird
# es hier geprueft: bis 0.9.26 stand es NICHT in dpkg/apt, die Fehlerausgabe
# ging nach /dev/null, und Zeile 14 meldete unbedingt <OK>. Eine Meldung, die
# genau dann luegt, wenn sie gebraucht wird.
if ! command -v dos2unix >/dev/null 2>&1; then
    echo "<WARNING> dos2unix ist nicht vorhanden - die Zeilenenden wurden NICHT"
    echo "<WARNING> geradegezogen. Das Plugin laeuft, wenn das Archiv sie schon"
    echo "<WARNING> richtig mitbringt; im Zweifel: sudo apt install dos2unix"
elif [ -n "$ARGV1" ] && [ -d "$ARGV1" ]; then
    if find "$ARGV1" -type f \( -name '*.sh' -o -name '*.php' -o -name '*.ini' -o -name '*.cfg' \) \
            -print0 | xargs -0 -r dos2unix -q; then
        echo "<OK> Zeilenenden geprueft."
    else
        echo "<WARNING> dos2unix ist mit einem Fehler ausgestiegen - die"
        echo "<WARNING> Zeilenenden sind moeglicherweise nicht geradegezogen."
    fi
else
    echo "<WARNING> Temporaerordner nicht gefunden - Zeilenenden nicht geprueft."
fi

exit 0
