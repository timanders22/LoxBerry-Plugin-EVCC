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

# Die sudo-Regel gehoert VOR jeden Ausstieg.
#
# Bis 0.9.10 stand sie am Ende der Datei - hinter dem "exit 0" fuer den Fall
# "EVCC ist schon da". Wer EVCC von Hand installiert hatte (ein Fall, den
# README und dieses Skript ausdruecklich unterstuetzen), bekam im Reiter Test
# drei Knoepfe fuer Start, Stopp und Neustart, die nie funktionieren konnten.
# Gemeldet wurde der Fehler immerhin - aber die Ursache lag hier.
# Das Aktualisierungsskript nach /usr/local/sbin legen - NICHT im
# Plugin-Ordner lassen.
#
# Der Plugin-Ordner gehoert dem Benutzer loxberry. Eine Datei, die loxberry
# schreiben kann und root ausfuehrt, ist eine Hintertuer: wer die Oberflaeche
# uebernimmt, uebernimmt damit die Maschine. Deshalb wird der Inhalt hier
# geschrieben, gehoert root und ist fuer andere nicht beschreibbar. Das Skript
# nimmt KEINE Argumente - es gibt nichts einzuschleusen.
update_skript_anlegen() {
    cat > /usr/local/sbin/loxberry-evcc-update <<'UPDATE'
#!/bin/bash
# EVCC aktualisieren. Angelegt vom LoxBerry-Plugin EVCC, gehoert root.
# Nimmt keine Argumente.
set -u
export DEBIAN_FRONTEND=noninteractive

VOR=$(evcc -v 2>/dev/null | head -1)
echo "vorher:  ${VOR:-unbekannt}"

# Die Konfiguration von EVCC vor dem Lauf sichern.
if [ -f /etc/evcc.yaml ]; then
    SICHER="/etc/evcc.yaml.vor-update-$(date +%Y%m%d-%H%M%S)"
    cp -a /etc/evcc.yaml "$SICHER" && echo "Sicherung: $SICHER"
fi

# Nur die Paketquelle von EVCC auffrischen, nicht das ganze System. Ein
# "apt-get update" ueber alle Quellen kann an einer fremden, kaputten Quelle
# scheitern - und dann haette das Plugin etwas kaputtgemacht, was es nichts
# angeht.
# ALLE EVCC-Quellen auffrischen, nicht die erste.
#
# Die erste Fassung nahm "head -1". Auf einer Maschine mit stable UND nightly
# frischte sie damit eine Quelle auf, waehrend apt bei der Installation aus
# beiden waehlt - und die hoechste Fassung gewinnt. Gemessen am 17.08.2026:
# angekuendigt 0.314.0, eingespielt 0.315.0-dev. Ueberraschungen dieser Art
# darf ein Update nicht machen.
LISTEN=$(grep -rl 'dl\.evcc\.io' /etc/apt/sources.list.d/ 2>/dev/null)
if [ -z "$LISTEN" ]; then
    echo "FEHLER: keine EVCC-Paketquelle unter /etc/apt/sources.list.d/ gefunden."
    echo "Ohne sie kann apt keine neue Fassung sehen. Einrichten mit:"
    echo "  curl -1sLf https://dl.evcc.io/public/evcc/stable/setup.deb.sh | sudo -E bash"
    exit 3
fi
ANZ=$(echo "$LISTEN" | wc -l)
echo "EVCC-Paketquellen ($ANZ):"
echo "$LISTEN" | sed 's/^/  /'
if [ "$ANZ" -gt 1 ]; then
    echo "HINWEIS: Es sind mehrere EVCC-Quellen eingetragen. apt nimmt die"
    echo "         HOECHSTE Fassung aus allen - das kann eine Entwicklerfassung"
    echo "         (nightly) sein, auch wenn die stabile Quelle daneben steht."
fi
for L in $LISTEN; do
    if ! apt-get update -qq \
            -o Dir::Etc::sourcelist="$L" \
            -o Dir::Etc::sourceparts=- \
            -o APT::Get::List-Cleanup=0; then
        echo "FEHLER: apt-get update fuer $L fehlgeschlagen."
        exit 2
    fi
done

# Vor der Installation zeigen, WAS genommen wird. Wer das liest, wird von der
# eingespielten Fassung nicht ueberrascht.
echo "--- apt-cache policy evcc ---"
apt-cache policy evcc 2>/dev/null | sed 's/^/  /'
echo "-----------------------------"


# --force-confold: eine vorhandene /etc/evcc.yaml wird NIEMALS ersetzt.
# Ohne diese Zeile entscheidet dpkg in einem nicht interaktiven Lauf selbst
# ueber die Konfiguration des Anwenders.
apt-get install -y -q --only-upgrade \
    -o Dpkg::Options::=--force-confdef \
    -o Dpkg::Options::=--force-confold \
    evcc
RC=$?

NACH=$(evcc -v 2>/dev/null | head -1)
echo "nachher: ${NACH:-unbekannt}"
case "$NACH" in
    *-dev*|*nightly*)
        echo "HINWEIS: Das ist eine ENTWICKLERFASSUNG (nightly), keine stabile."
        echo "         Sie stammt aus einer nightly-Quelle in /etc/apt/sources.list.d/."
        echo "         Wer nur stabile Fassungen will, entfernt diese Quelle -"
        echo "         die stabile richtet man ein mit:"
        echo "           curl -1sLf https://dl.evcc.io/public/evcc/stable/setup.deb.sh | sudo -E bash"
        ;;
esac
if [ "$RC" != "0" ]; then
    echo "FEHLER: apt-get endete mit Rueckgabewert $RC."
    exit "$RC"
fi
if [ "$VOR" = "$NACH" ]; then
    echo "Nichts aktualisiert - die installierte Fassung ist bereits die neueste dieser Paketquelle."
fi
exit 0
UPDATE
    chown root:root /usr/local/sbin/loxberry-evcc-update
    chmod 0755 /usr/local/sbin/loxberry-evcc-update
    echo "<OK> Aktualisierungsskript /usr/local/sbin/loxberry-evcc-update angelegt."
}

sudo_regel_anlegen() {
    update_skript_anlegen
    # Der Benutzer loxberry muss den Dienst aus der Oberflaeche steuern
    # koennen, ohne Passwort - aber nur genau diesen einen Dienst und nur
    # diese vier Unterbefehle. Ein pauschales NOPASSWD:ALL waere hier eine
    # Hintertuer.
    cat > /etc/sudoers.d/loxberry-evcc <<'SUDO'
loxberry ALL=(root) NOPASSWD: /bin/systemctl start evcc, /bin/systemctl stop evcc, /bin/systemctl restart evcc, /bin/systemctl status evcc
loxberry ALL=(root) NOPASSWD: /usr/bin/systemctl start evcc, /usr/bin/systemctl stop evcc, /usr/bin/systemctl restart evcc, /usr/bin/systemctl status evcc
loxberry ALL=(root) NOPASSWD: /usr/local/sbin/loxberry-evcc-update
SUDO
    chmod 0440 /etc/sudoers.d/loxberry-evcc
    if ! visudo -cf /etc/sudoers.d/loxberry-evcc >/dev/null 2>&1; then
        echo "<WARNING> Die sudo-Regel war fehlerhaft und wurde wieder entfernt."
        rm -f /etc/sudoers.d/loxberry-evcc
    else
        echo "<OK> sudo-Regel fuer den Dienst evcc angelegt."
    fi
}

echo "<INFO> Pruefe, ob EVCC bereits vorhanden ist"
if command -v evcc >/dev/null 2>&1; then
    echo "<OK> EVCC ist bereits installiert ($(evcc -v 2>/dev/null | head -1)). Es wird nichts veraendert."
    sudo_regel_anlegen
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
        sudo_regel_anlegen
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

sudo_regel_anlegen

echo "<OK> EVCC eingerichtet. Oberflaeche: http://$(hostname -I | awk '{print $1}'):7070"
echo "<INFO> Dort zuerst ein Passwort vergeben und die Geraete einrichten."
exit 0
