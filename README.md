# e.viSio Zeitraffer

Zeitraffer für Reolink-LTE-Kameras (getestet mit TrackMix LTE Plus 2).

```
Kamera (LTE) ──Reolink-Relay──► Abholer (Windows Server) ──HTTPS──► easyname (PHP)
                                 Neolink holt Bilder              Galerie + Videos
                                 ffmpeg baut Videos               Freigabelinks / iframe
```

- **Tagsüber** (06–20 Uhr) alle 5 Minuten ein Bild, **nachts** einmal pro Stunde.
- **Videos** jeden Abend ab 20 Uhr: ein Tagesvideo und ein Gesamtvideo (je Tag ein Bild pro Stunde). Nur Tagbilder, damit es nicht flackert.
- **Galerie** mit Tagesauswahl, Zeitstrahl, Abspielen im Browser und Videos. Zugriff nur über Freigabelink, einbettbar per `<iframe>`.
- Bilder und Videos liegen bei easyname außerhalb des Web-Ordners und werden nur mit gültigem Link ausgeliefert.

## Ordner

| Ordner | Inhalt |
| --- | --- |
| `abholer/` | Python-Skript, läuft auf dem Windows Server (oder Linux) |
| `server/` | PHP für easyname: `public/` ist das Web, `lib/` und `config.php` liegen außerhalb |

Zugangsdaten stehen nur in `abholer/config.ini` und `server/config.php`. Beide sind in `.gitignore`.

---

## 1. easyname einrichten

1. Per SFTP den Ordner `server/` hochladen nach `/data/web/e71813/zeitraffer/`
   (ergibt `.../zeitraffer/lib`, `.../zeitraffer/public`, `.../zeitraffer/config.example.php`).
2. Den **Inhalt** von `public/` zusätzlich in den Web-Ordner kopieren, z. B. nach `html/zeitraffer/`.
   In `html/zeitraffer/_init.php` die Zeile `require ...` ersetzen durch:
   ```php
   require '/data/web/e71813/zeitraffer/lib/bootstrap.php';
   ```
   (Alternativ eine Subdomain direkt auf `/data/web/e71813/zeitraffer/public` zeigen lassen, dann bleibt `_init.php` unverändert.)
3. Schlüssel erzeugen (per SSH), dreimal ausführen: API-Schlüssel, Token Bauherr, Token Homepage.
   ```
   php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'
   ```
4. `config.example.php` nach `config.php` kopieren (im Ordner `/data/web/e71813/zeitraffer/`) und ausfüllen.
   `daten_pfad` z. B. `/data/web/e71813/zeitraffer-daten` (wird automatisch angelegt).
5. Aufruf zum Test: `https://DOMAIN/zeitraffer/?k=TOKEN` zeigt „Noch keine Bilder“.

## 2. Windows Server einrichten

Einmalig installieren:

- **Python 3** (64-bit) von python.org: „Customize installation“ → „Install Python for all users“ und „Add Python to environment variables“.
- **GStreamer** von gstreamer.freedesktop.org: *Runtime*, *MSVC 64-bit*, Installationsart **Complete**.

Dann:

1. Ordner `abholer/` auf den Server kopieren, z. B. nach `C:\Zeitraffer\abholer`.
2. PowerShell **als Administrator** öffnen:
   ```
   cd C:\Zeitraffer\abholer
   powershell -ExecutionPolicy Bypass -File .\einrichten-windows.ps1
   ```
   Das Skript lädt Neolink und ffmpeg, legt `config.ini` an und öffnet sie im Editor.
3. In `config.ini` eintragen: Server-URL (`https://DOMAIN/zeitraffer/api.php`), API-Schlüssel, Kamerapasswort. Speichern.
4. Skript nochmal starten. Es testet alles, holt ein Bild und richtet die Aufgabe **„Zeitraffer Abholer“** ein: alle 5 Minuten, als SYSTEM, auch ohne Anmeldung und nach Neustart.

Protokoll: `abholer\daten\zeitraffer.log`

Nützliche Befehle (im Ordner `abholer`):

```
python zeitraffer.py test          # alles prüfen + Bild holen
python zeitraffer.py jetzt         # sofort ein Bild holen
python zeitraffer.py videos        # Videos jetzt erstellen
python zeitraffer.py videos --neu  # alle Videos neu erstellen
```

## 3. Freigeben und einbetten

- **Bauherr:** Link `https://DOMAIN/zeitraffer/?k=TOKEN-BAUHERR` schicken.
- **Homepage:** eigener Token, eingebettet mit
  ```html
  <iframe src="https://DOMAIN/zeitraffer/?k=TOKEN-HOMEPAGE&einbetten=1"
          style="width:100%;aspect-ratio:4/3;border:0" loading="lazy" allowfullscreen></iframe>
  ```
  In `config.php` kann man mit `einbetten_erlaubt` festlegen, auf welchen Seiten das iframe funktioniert.
- **Sperren:** Token aus `config.php` entfernen, der Link ist sofort ungültig.

Datenschutz: Auf den Bildern können Nachbargrundstücke, Personen und Kennzeichen erkennbar sein. Vor einer öffentlichen Einbindung den Bildausschnitt prüfen (in der Reolink-App lassen sich Privatzonen setzen).

## 4. Weitere Kamera

- `abholer/config.ini`: neuen Abschnitt `[kamera:kurzname]` mit `uid` und `passwort`.
- `server/config.php`: gleichen `kurzname` unter `kameras` mit Titel und eigenen Tokens.

## Später auf Linux

```
cp config.example.ini config.ini   # neolink- und ffmpeg-Pfad anpassen
python3 zeitraffer.py test
crontab -e
*/5 * * * * cd /opt/zeitraffer/abholer && python3 zeitraffer.py aufnahme
```
Neolink für Linux braucht die GStreamer-Pakete der Distribution.

## Speicherbedarf

Etwa 180 Bilder pro Tag zu je ca. 0,5 MB, also rund 90 MB pro Tag lokal und bei easyname, plus Videos.

## Technik

- Bilder holt [Neolink](https://github.com/QuantumEntangledAndy/neolink) (inoffiziell, Version 0.6.2) über die Reolink-Relay-Server (`discovery = "cellular"`). Ein Abruf dauert etwa 30–50 s.
- Schlägt ein Abruf fehl, versucht der nächste Lauf (5 Minuten später) es erneut. Uploads werden nachgeholt, wenn das Internet wieder da ist.
- Firmware-Updates der Kamera können Neolink stören. Dann zuerst `python zeitraffer.py test` und das Protokoll prüfen.
