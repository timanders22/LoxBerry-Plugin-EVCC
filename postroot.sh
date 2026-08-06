#!/bin/bash
# Laeuft als ROOT, nach der Installation.
#
# Hier und nur hier wird EVCC eingerichtet: das Eintragen einer Paketquelle
# und das Installieren eines Systemdienstes gehen nicht als Benutzer
# loxberry. Alles andere gehoert in postinstall.sh.
#
# Argumente: <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
ARGV3=$3
ARGV5=$5

echo "<INFO> Pruefe, ob EVCC bereits vorhanden ist"
if command -v evcc >/dev/null 2>&1; then
    echo "<OK> EVCC ist bereits installiert ($(evcc -v 2>/dev/null | head -1)). Es wird nichts veraendert."
    exit 0
fi

# Architektur pruefen, bevor eine Paketquelle eingetragen wird. EVCC gibt es
# fuer arm64, armv6/armhf und amd64 - auf allem anderen waere die Quelle
# nutzlos und muesste hinterher von Hand entfernt werden.
BOGEN=$(dpkg --print-architecture 2>/dev/null)
case "$BOGEN" in
    arm64|armhf|armel|amd64) ;;
    *)
        echo "<FAIL> Fuer die Architektur '$BOGEN' gibt es kein EVCC-Paket."
        echo "<INFO> Das Plugin laesst sich trotzdem benutzen: im Reiter Einstellungen"
        echo "<INFO> die Adresse einer EVCC-Instanz im Netz eintragen."
        exit 0
        ;;
esac

echo "<INFO> Trage die EVCC-Paketquelle ein (dl.evcc.io)"
if ! curl -1sLf 'https://dl.evcc.io/public/evcc/stable/setup.deb.sh' -o /tmp/evcc_setup.deb.sh; then
    echo "<FAIL> Die Paketquelle liess sich nicht laden. Internetverbindung pruefen."
    echo "<INFO> EVCC kann spaeter von Hand nachinstalliert werden:"
    echo "<INFO>   curl -1sLf https://dl.evcc.io/public/evcc/stable/setup.deb.sh | sudo -E bash"
    echo "<INFO>   sudo apt update && sudo apt install -y evcc"
    exit 0
fi
bash /tmp/evcc_setup.deb.sh
rm -f /tmp/evcc_setup.deb.sh

echo "<INFO> Installiere EVCC"
apt-get update -qq
if ! DEBIAN_FRONTEND=noninteractive apt-get install -y -qq evcc; then
    echo "<FAIL> Die Installation von EVCC ist fehlgeschlagen. Siehe Protokoll."
    exit 0
fi

echo "<INFO> Richte den Dienst ein"
systemctl enable evcc >/dev/null 2>&1
systemctl start evcc >/dev/null 2>&1

# Der Benutzer loxberry muss den Dienst aus der Oberflaeche steuern koennen,
# ohne Passwort - aber nur genau diesen einen Dienst und nur diese vier
# Unterbefehle. Ein pauschales NOPASSWD:ALL waere hier eine Hintertuer.
cat > /etc/sudoers.d/loxberry-evcc <<'SUDO'
loxberry ALL=(root) NOPASSWD: /bin/systemctl start evcc, /bin/systemctl stop evcc, /bin/systemctl restart evcc, /bin/systemctl status evcc
loxberry ALL=(root) NOPASSWD: /usr/bin/systemctl start evcc, /usr/bin/systemctl stop evcc, /usr/bin/systemctl restart evcc, /usr/bin/systemctl status evcc
SUDO
chmod 0440 /etc/sudoers.d/loxberry-evcc
if ! visudo -cf /etc/sudoers.d/loxberry-evcc >/dev/null 2>&1; then
    echo "<WARNING> Die sudo-Regel war fehlerhaft und wurde wieder entfernt."
    rm -f /etc/sudoers.d/loxberry-evcc
fi

echo "<OK> EVCC eingerichtet. Oberflaeche: http://$(hostname -I | awk '{print $1}'):7070"
echo "<INFO> Dort zuerst ein Passwort vergeben und die Geraete einrichten."
exit 0
