#!/usr/bin/env bash
# Passwoerter fuer die Galerie verwalten. Per SSH im Web-Ordner ausfuehren:
#   bash _privat/zugang.sh
# Speichert in _privat/zugaenge.txt: name|rolle|kamera|salt|sha256(salt+passwort)

set -e
ORDNER="$(cd "$(dirname "$0")" && pwd)"
DATEI="$ORDNER/zugaenge.txt"
touch "$DATEI"; chmod 600 "$DATEI"

# Kameras aus config.php lesen
mapfile -t KAMERAS < <(sed -n "/'kameras'/,\$p" "$ORDNER/config.php" | grep -oE "^ {8}'[a-z0-9-]+' => \[" | cut -d"'" -f2)
[ ${#KAMERAS[@]} -gt 0 ] || { echo "Keine Kamera in config.php gefunden."; exit 1; }

zeigen() {
  echo; echo "Vorhandene Zugaenge:"
  if [ -s "$DATEI" ]; then awk -F'|' '{printf "  - %-20s %-8s %s\n", $1, $2, $3}' "$DATEI"; else echo "  (keine)"; fi
  echo
}

zeigen
echo "1) Zugang anlegen oder Passwort aendern"
echo "2) Zugang loeschen"
echo "3) Beenden"
read -rp "Auswahl: " WAHL

case "$WAHL" in
1)
  read -rp "Name (z. B. Bauherr, Martin): " NAME
  NAME="${NAME//|/}"
  [ -n "$NAME" ] || { echo "Name fehlt."; exit 1; }
  echo "Rolle:  1) Ansicht (nur anschauen)   2) Admin (darf spaeter auch einstellen)"
  read -rp "Auswahl [1]: " R
  [ "$R" = "2" ] && ROLLE=admin || ROLLE=ansicht
  if [ ${#KAMERAS[@]} -eq 1 ]; then
    KAMERA="${KAMERAS[0]}"
  else
    echo "Kamera:"; i=1; for k in "${KAMERAS[@]}"; do echo "  $i) $k"; i=$((i+1)); done
    read -rp "Auswahl: " K; KAMERA="${KAMERAS[$((K-1))]}"
    [ -n "$KAMERA" ] || { echo "Ungueltig."; exit 1; }
  fi
  read -rsp "Passwort (mind. 8 Zeichen): " PW1; echo
  read -rsp "Passwort wiederholen: " PW2; echo
  [ "$PW1" = "$PW2" ] || { echo "Passwoerter stimmen nicht ueberein."; exit 1; }
  [ ${#PW1} -ge 8 ] || { echo "Zu kurz, bitte mindestens 8 Zeichen."; exit 1; }
  SALT=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
  HASH=$(printf '%s' "$SALT$PW1" | sha256sum | cut -d' ' -f1)
  grep -v "^$NAME|" "$DATEI" > "$DATEI.neu" || true
  echo "$NAME|$ROLLE|$KAMERA|$SALT|$HASH" >> "$DATEI.neu"
  mv "$DATEI.neu" "$DATEI"; chmod 600 "$DATEI"
  echo "Gespeichert: $NAME ($ROLLE) fuer $KAMERA. Wer mit dem alten Passwort angemeldet war, wird abgemeldet."
  ;;
2)
  read -rp "Name des Zugangs, der geloescht werden soll: " NAME
  grep -q "^$NAME|" "$DATEI" || { echo "Nicht gefunden."; exit 1; }
  grep -v "^$NAME|" "$DATEI" > "$DATEI.neu" || true
  mv "$DATEI.neu" "$DATEI"; chmod 600 "$DATEI"
  echo "Geloescht: $NAME (ist damit sofort abgemeldet)."
  ;;
*) ;;
esac
zeigen
