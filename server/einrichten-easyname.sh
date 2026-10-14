#!/usr/bin/env bash
# Richtet den Zeitraffer-Server bei easyname ein. Per SSH im hochgeladenen Ordner "server" ausfuehren:
#   bash einrichten-easyname.sh
#
# Was passiert:
#   - Programmteile (lib, config.php) kommen nach  ~/zeitraffer-app        (nicht oeffentlich)
#   - Bilder und Videos kommen nach                ~/zeitraffer-daten      (nicht oeffentlich)
#   - Die Galerie kommt in den Web-Ordner, Standard: ~/html/estherreierweg8
#   - Schluessel und Freigabelinks werden erzeugt und am Ende angezeigt.
# Kann gefahrlos nochmal ausgefuehrt werden (vorhandene Schluessel bleiben).

set -e
QUELLE="$(cd "$(dirname "$0")" && pwd)"
APP="$HOME/zeitraffer-app"
DATEN="$HOME/zeitraffer-daten"
WEB="${1:-$HOME/html/estherreierweg8}"
KAMERA="esther-reier-weg-8"
TITEL="Baustelle Esther-Reier-Weg 8"

ok()   { printf '  [OK]    %s\n' "$*"; }
fail() { printf '  [FEHLT] %s\n' "$*"; exit 1; }

echo
echo "Zeitraffer-Einrichtung"
echo "  Programm:  $APP"
echo "  Daten:     $DATEN"
echo "  Web:       $WEB"
echo

[ -f "$QUELLE/lib/bootstrap.php" ] && [ -f "$QUELLE/public/index.php" ] \
  || fail "Dieses Skript muss im hochgeladenen Ordner 'server' liegen (neben lib/ und public/)."
command -v php >/dev/null || fail "php nicht gefunden"

# 1. Programmteile nach ~/zeitraffer-app
mkdir -p "$APP/lib" "$DATEN" "$WEB"
cp -f "$QUELLE"/lib/*.php "$QUELLE/lib/.htaccess" "$APP/lib/"
cp -f "$QUELLE/config.example.php" "$APP/"
ok "Programmteile kopiert"

# 2. Galerie in den Web-Ordner
cp -f "$QUELLE"/public/*.php "$QUELLE"/public/*.js "$QUELLE"/public/*.css "$WEB/"
echo "<?php require '$APP/lib/bootstrap.php';" > "$WEB/_init.php"
ok "Galerie im Web-Ordner"

# 3. config.php mit neuen Schluesseln (nur beim ersten Mal)
if [ ! -f "$APP/config.php" ]; then
  zufall() { php -r 'echo bin2hex(random_bytes(24));'; }
  API=$(zufall); T_BAUHERR=$(zufall); T_HOMEPAGE=$(zufall)
  cat > "$APP/config.php" <<EOF
<?php
// Erzeugt von einrichten-easyname.sh am $(date +%d.%m.%Y). Vorlage mit Erklaerungen: config.example.php
return [
    'daten_pfad' => '$DATEN',
    'api_schluessel' => '$API',
    'zeitzone' => 'Europe/Vienna',
    'tag_von' => 6,
    'tag_bis' => 20,
    'fusszeile' => 'e.viSio elektrovisionen gmbh',
    'kameras' => [
        '$KAMERA' => [
            'titel' => '$TITEL',
            'freigaben' => [
                '$T_BAUHERR' => 'Bauherr',
                '$T_HOMEPAGE' => 'Homepage',
            ],
            'einbetten_erlaubt' => [],
        ],
    ],
];
EOF
  chmod 600 "$APP/config.php"
  ok "config.php mit neuen Schluesseln angelegt"
else
  ok "config.php war schon vorhanden, Schluessel bleiben gleich"
fi

# Werte aus config.php lesen (auch beim wiederholten Aufruf)
WERTE=$(php -r '$c = require $argv[1]; $k = $c["kameras"][$argv[2]] ?? [];
  echo $c["api_schluessel"], "\n";
  foreach (($k["freigaben"] ?? []) as $t => $n) echo $n, "=", $t, "\n";' "$APP/config.php" "$KAMERA")
API=$(echo "$WERTE" | sed -n 1p)
T_BAUHERR=$(echo "$WERTE" | sed -n 's/^Bauherr=//p')
T_HOMEPAGE=$(echo "$WERTE" | sed -n 's/^Homepage=//p')

# 4. Hochgeladene Kopie aus dem Web-Ordner entfernen (lib/ und config gehoeren nicht ins Web)
case "$QUELLE" in
  "$HOME/html"*)
    rm -rf "$QUELLE/lib" "$QUELLE/public" "$QUELLE/config.example.php"
    if [ "$QUELLE" != "$WEB" ]; then
      rm -f "$QUELLE/einrichten-easyname.sh"
      rmdir "$QUELLE" 2>/dev/null || true
    fi
    ok "Hochgeladene Kopie im Web-Ordner aufgeraeumt"
    ;;
esac

# 5. Adresse abfragen und testen
echo
read -rp "Unter welcher Adresse ist $WEB erreichbar? (z. B. https://www.evisio.at/estherreierweg8): " URL
URL="${URL%/}"
if [ -n "$URL" ]; then
  ANTWORT=$(curl -s -H "X-Api-Key: $API" "$URL/api.php?aktion=status" || true)
  case "$ANTWORT" in
    *'"ok":true'*) ok "Server antwortet: $ANTWORT" ;;
    *) echo "  [FEHLT] Kein gueltige Antwort von $URL/api.php"; echo "          Antwort: ${ANTWORT:0:300}" ;;
  esac
fi
[ -n "$URL" ] || URL="https://DEINE-ADRESSE"

cat <<EOF

==============================================================================
 FERTIG. Bitte diesen Block aufheben (enthaelt Schluessel).
==============================================================================

 Fuer den Windows Server, in abholer\\config.ini unter [server]:

   url = $URL/api.php
   api_schluessel = $API

 Link fuer den Bauherrn:

   $URL/?k=$T_BAUHERR

 Fuer die Homepage (iframe):

   <iframe src="$URL/?k=$T_HOMEPAGE&einbetten=1"
           style="width:100%;aspect-ratio:4/3;border:0" loading="lazy" allowfullscreen></iframe>

==============================================================================
EOF
