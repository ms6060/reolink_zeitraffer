#!/usr/bin/env python3
"""Zeitraffer-Abholer fuer Reolink LTE-Kameras.

Holt per Neolink Einzelbilder von den Kameras, laedt sie auf den Zeitraffer-Server
(easyname) hoch und erstellt abends die Zeitraffer-Videos mit ffmpeg.

Befehle:
  aufnahme   Fuer die Aufgabenplanung bzw. Cron, alle 5 Minuten aufrufen.
             Entscheidet selbst anhand des Zeitplans, ob ein Bild faellig ist.
  jetzt      Sofort ein Bild je Kamera holen und hochladen (Zeitplan egal).
  test       Einrichtung pruefen (Neolink, ffmpeg, Server) und ein Bild holen.
  videos     Videos sofort erstellen/aktualisieren und hochladen (--neu: alle neu).

Nur Python-Standardbibliothek, laeuft unter Windows und Linux (Python 3.8+).
"""
import argparse
import configparser
import datetime as dt
import json
import logging
import logging.handlers
import os
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

VERSION = "1.0"
HIER = Path(__file__).resolve().parent
log = logging.getLogger("zeitraffer")
KEIN_FENSTER = 0x08000000 if os.name == "nt" else 0  # CREATE_NO_WINDOW unter Windows


# --------------------------------------------------------------------------- Konfiguration

class Kamera:
    def __init__(self, slug, abschnitt):
        self.slug = slug
        self.uid = abschnitt.get("uid", "").strip()
        self.benutzer = abschnitt.get("benutzer", "admin").strip()
        self.passwort = abschnitt.get("passwort", "")
        self.discovery = abschnitt.get("discovery", "cellular").strip()
        if not self.uid:
            raise SystemExit(f"[kamera:{slug}] uid fehlt in der config.ini")


class Config:
    def __init__(self, pfad):
        cp = configparser.ConfigParser(interpolation=None)
        cp.BOOLEAN_STATES = dict(configparser.ConfigParser.BOOLEAN_STATES, ja=True, nein=False)
        if not cp.read(pfad, encoding="utf-8"):
            raise SystemExit(f"config.ini nicht gefunden: {pfad} (Vorlage: config.example.ini)")
        a = cp["allgemein"]
        self.daten = self._pfad(a.get("daten", "daten"))
        self.neolink = self._programm(a.get("neolink", "neolink"))
        self.ffmpeg = self._programm(a.get("ffmpeg", "ffmpeg"))
        self.gstreamer_bin = a.get("gstreamer_bin", "").strip()
        self.timeout = a.getint("timeout_sekunden", 150)
        self.stream_ersatz = a.getboolean("stream_ersatz", True)

        s = cp["server"]
        self.server_url = s.get("url", "").strip()
        self.api_schluessel = s.get("api_schluessel", "").strip()

        z = cp["zeitplan"] if cp.has_section("zeitplan") else {}
        self.tag_von = int(z.get("tag_von", 6))
        self.tag_bis = int(z.get("tag_bis", 20))
        self.tag_intervall = int(z.get("tag_intervall_minuten", 5))
        self.nacht_intervall = int(z.get("nacht_intervall_minuten", 60))

        v = cp["video"] if cp.has_section("video") else {}
        self.video_an = str(v.get("aktiv", "ja")).lower() in ("ja", "yes", "true", "1")
        self.video_fps = int(v.get("bilder_pro_sekunde", 12))
        self.video_breite = int(v.get("breite", 1920))
        self.video_crf = int(v.get("qualitaet_crf", 24))
        self.gesamt_intervall = int(v.get("gesamt_intervall_minuten", 60))

        self.kameras = [Kamera(n.split(":", 1)[1].strip(), cp[n])
                        for n in cp.sections() if n.startswith("kamera:")]
        if not self.kameras:
            raise SystemExit("Keine Kamera in der config.ini ([kamera:name])")

    @staticmethod
    def _pfad(wert):
        p = Path(os.path.expandvars(os.path.expanduser(wert.strip())))
        return p if p.is_absolute() else HIER / p

    def _programm(self, wert):
        wert = wert.strip()
        if any(t in wert for t in ("/", "\\")):
            return str(self._pfad(wert))
        return shutil.which(wert) or wert


# --------------------------------------------------------------------------- Hilfen

def status_laden(kam_ordner):
    try:
        return json.loads((kam_ordner / "status.json").read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}


def status_speichern(kam_ordner, status):
    kam_ordner.mkdir(parents=True, exist_ok=True)
    tmp = kam_ordner / "status.json.tmp"
    tmp.write_text(json.dumps(status, indent=1, sort_keys=True), encoding="utf-8")
    os.replace(tmp, kam_ordner / "status.json")


def ist_tagzeit(cfg, stunde):
    return cfg.tag_von <= stunde < cfg.tag_bis


def slot(cfg, jetzt):
    """Zeitfenster, in dem jetzt hoechstens ein Bild geholt wird."""
    n = cfg.tag_intervall if ist_tagzeit(cfg, jetzt.hour) else cfg.nacht_intervall
    minuten = jetzt.hour * 60 + jetzt.minute
    beginn = (minuten // n) * n
    return jetzt.replace(hour=beginn // 60, minute=beginn % 60, second=0, microsecond=0)


def bild_ok(pfad):
    try:
        with open(pfad, "rb") as f:
            return f.read(2) == b"\xff\xd8" and pfad.stat().st_size > 1000
    except OSError:
        return False


def programm_env(cfg):
    env = dict(os.environ)
    if os.name == "nt":
        kandidaten = [cfg.gstreamer_bin]
        for var in ("GSTREAMER_1_0_ROOT_MSVC_X86_64", "GSTREAMER_1_0_ROOT_X86_64"):
            if env.get(var):
                kandidaten.append(os.path.join(env[var], "bin"))
        kandidaten += [r"C:\Program Files\gstreamer\1.0\msvc_x86_64\bin", r"C:\gstreamer\1.0\msvc_x86_64\bin"]
        for k in kandidaten:
            if k and os.path.isfile(os.path.join(k, "gstreamer-1.0-0.dll")):
                env["PATH"] = k + os.pathsep + env.get("PATH", "")
                break
    return env


class Sperre:
    """Verhindert, dass sich zwei Laeufe ueberschneiden."""
    def __init__(self, pfad, max_alter=20 * 60):
        self.pfad, self.max_alter, self.fd = pfad, max_alter, None

    def __enter__(self):
        self.pfad.parent.mkdir(parents=True, exist_ok=True)
        for _ in range(2):
            try:
                self.fd = os.open(str(self.pfad), os.O_CREAT | os.O_EXCL | os.O_WRONLY)
                os.write(self.fd, str(os.getpid()).encode())
                return self
            except FileExistsError:
                try:
                    if time.time() - self.pfad.stat().st_mtime > self.max_alter:
                        self.pfad.unlink()
                        continue
                except OSError:
                    continue
                return None
        return None

    def __exit__(self, *a):
        if self.fd is not None:
            os.close(self.fd)
            try:
                self.pfad.unlink()
            except OSError:
                pass


# --------------------------------------------------------------------------- Neolink

def neolink_config(cfg):
    pfad = cfg.daten / "neolink.toml"
    zeilen = ['bind = "127.0.0.1"', ""]
    for k in cfg.kameras:
        zeilen += ["[[cameras]]",
                   f"name = {json.dumps(k.slug)}",
                   f"username = {json.dumps(k.benutzer)}",
                   f"password = {json.dumps(k.passwort)}",
                   f"uid = {json.dumps(k.uid)}",
                   f"discovery = {json.dumps(k.discovery)}",
                   ""]
    cfg.daten.mkdir(parents=True, exist_ok=True)
    pfad.write_text("\n".join(zeilen), encoding="utf-8")
    if os.name != "nt":
        os.chmod(pfad, 0o600)
    return pfad


def bild_holen(cfg, kam, ziel):
    """Ein Bild von der Kamera nach `ziel` holen. Liefert True bei Erfolg."""
    conf = neolink_config(cfg)
    ziel.parent.mkdir(parents=True, exist_ok=True)
    basis = ziel.with_name(ziel.stem + "_neu")
    kandidaten = [ziel.with_name(ziel.stem + "_neu.jpeg"), ziel.with_name(ziel.stem + "_neu.jpg"), basis]
    versuche = [[]] + ([["--use-stream"]] if cfg.stream_ersatz else [])
    for extra in versuche:
        for alt in kandidaten:
            alt.unlink(missing_ok=True)
        cmd = [cfg.neolink, "image", f"--config={conf}", f"--file-path={basis}.jpeg", *extra, kam.slug]
        start = time.time()
        try:
            r = subprocess.run(cmd, capture_output=True, text=True, errors="replace",
                               timeout=cfg.timeout, env=programm_env(cfg), creationflags=KEIN_FENSTER)
            ausgabe = (r.stderr or "") + (r.stdout or "")
        except subprocess.TimeoutExpired as e:
            ausgabe = f"Zeitueberschreitung nach {cfg.timeout}s\n" + str(e.stderr or "")
        except FileNotFoundError:
            log.error("Neolink nicht gefunden: %s", cfg.neolink)
            return False
        for kandidat in kandidaten:
            if kandidat.exists() and bild_ok(kandidat):
                os.replace(kandidat, ziel)
                log.info("%s: Bild %s geholt in %.0fs%s", kam.slug, ziel.name, time.time() - start,
                         " (Videostream)" if extra else "")
                return True
        letzte = "\n".join(ausgabe.strip().splitlines()[-8:])
        log.warning("%s: kein Bild%s nach %.0fs:\n%s", kam.slug,
                    " (Videostream)" if extra else "", time.time() - start, letzte)
    return False


# --------------------------------------------------------------------------- Server

class ServerFehler(Exception):
    pass


def server_anfrage(cfg, params, daten=None, typ="application/octet-stream", timeout=120):
    if not cfg.server_url or not cfg.api_schluessel:
        raise ServerFehler("Server-URL oder API-Schluessel fehlt in der config.ini")
    url = cfg.server_url + ("&" if "?" in cfg.server_url else "?") + urllib.parse.urlencode(params)
    kopf = {"X-Api-Key": cfg.api_schluessel, "User-Agent": f"zeitraffer-abholer/{VERSION}"}
    if daten is not None:
        kopf["Content-Type"] = typ
    req = urllib.request.Request(url, data=daten, headers=kopf, method="POST" if daten is not None else "GET")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        try:
            antwort = json.loads(e.read().decode("utf-8"))
        except ValueError:
            antwort = {"fehler": e.reason}
        antwort["_code"] = e.code
        if e.code == 409:
            return antwort
        raise ServerFehler(f"HTTP {e.code}: {antwort.get('fehler')}")
    except (urllib.error.URLError, OSError, ValueError) as e:
        raise ServerFehler(str(e))


def bilder_hochladen(cfg, kam, max_anzahl=300):
    ordner = cfg.daten / kam.slug
    liste = ordner / "hochgeladen.txt"
    erledigt = set(liste.read_text(encoding="utf-8").split()) if liste.exists() else set()
    offen = []
    for tag_ordner in sorted((ordner / "bilder").glob("*")):
        for b in sorted(tag_ordner.glob("*.jpg")):
            name = f"{tag_ordner.name}/{b.stem}"
            if name not in erledigt:
                offen.append((tag_ordner.name, b))
    anzahl = 0
    for tag, b in offen[:max_anzahl]:
        try:
            server_anfrage(cfg, {"aktion": "bild", "kamera": kam.slug, "tag": tag, "zeit": b.stem},
                           b.read_bytes(), "image/jpeg")
        except ServerFehler as e:
            log.warning("%s: Upload abgebrochen bei %s/%s: %s (wird spaeter wiederholt)", kam.slug, tag, b.name, e)
            break
        with open(liste, "a", encoding="utf-8") as f:
            f.write(f"{tag}/{b.stem}\n")
        anzahl += 1
    if anzahl:
        log.info("%s: %d Bild(er) hochgeladen, %d offen", kam.slug, anzahl, len(offen) - anzahl)
    return anzahl


def video_hochladen(cfg, kam, pfad, name, stueck=4 * 1024 * 1024):
    groesse = pfad.stat().st_size
    offset = 0
    with open(pfad, "rb") as f:
        while True:
            teil = f.read(stueck)
            fertig = offset + len(teil) >= groesse
            antwort = server_anfrage(cfg, {"aktion": "video", "kamera": kam.slug, "name": name,
                                           "offset": offset, "fertig": "1" if fertig else "0"},
                                     teil, "video/mp4", timeout=300)
            if antwort.get("_code") == 409:
                raise ServerFehler("Video-Upload aus dem Tritt (Offset), wird neu begonnen")
            offset += len(teil)
            if fertig:
                break
    log.info("%s: Video %s hochgeladen (%.1f MB)", kam.slug, name, groesse / 1048576)


# --------------------------------------------------------------------------- Videos

def tagbilder(cfg, kam, tag):
    return [b for b in sorted((cfg.daten / kam.slug / "bilder" / tag).glob("*.jpg"))
            if ist_tagzeit(cfg, int(b.stem[:2]))]


def video_bauen(cfg, bilder, ziel):
    ziel.parent.mkdir(parents=True, exist_ok=True)
    liste = ziel.with_suffix(".txt")
    dauer = 1.0 / cfg.video_fps

    def esc(p):
        return str(p.resolve()).replace("\\", "/").replace("'", "'\\''")

    zeilen = ["ffconcat version 1.0"]
    for b in bilder:
        zeilen += [f"file '{esc(b)}'", f"duration {dauer:.6f}"]
    zeilen.append(f"file '{esc(bilder[-1])}'")
    liste.write_text("\n".join(zeilen) + "\n", encoding="utf-8")
    tmp = ziel.with_name(ziel.stem + ".neu.mp4")
    cmd = [cfg.ffmpeg, "-y", "-hide_banner", "-loglevel", "error",
           "-f", "concat", "-safe", "0", "-i", str(liste),
           "-vf", f"scale={cfg.video_breite}:-2:flags=lanczos,format=yuv420p",
           "-r", str(cfg.video_fps), "-c:v", "libx264", "-preset", "medium",
           "-crf", str(cfg.video_crf), "-movflags", "+faststart", str(tmp)]
    start = time.time()
    try:
        r = subprocess.run(cmd, capture_output=True, text=True, errors="replace",
                           timeout=3600, creationflags=KEIN_FENSTER)
    except FileNotFoundError:
        log.error("ffmpeg nicht gefunden: %s", cfg.ffmpeg)
        return False
    finally:
        liste.unlink(missing_ok=True)
    if r.returncode != 0 or not tmp.exists():
        log.error("ffmpeg-Fehler bei %s:\n%s", ziel.name, (r.stderr or "")[-1500:])
        tmp.unlink(missing_ok=True)
        return False
    os.replace(tmp, ziel)
    log.info("Video %s erstellt: %d Bilder, %.0fs", ziel.name, len(bilder), time.time() - start)
    return True


def videos_aktualisieren(cfg, kam, jetzt, alle_neu=False):
    ordner = cfg.daten / kam.slug
    status = status_laden(ordner)
    gebaut = {} if alle_neu else status.get("videos", {})
    heute = jetzt.date().isoformat()
    tage = sorted(p.name for p in (ordner / "bilder").glob("*") if p.is_dir())
    neu = False

    for tag in tage:
        if tag > heute or (tag == heute and jetzt.hour < cfg.tag_bis):
            continue  # Tag noch nicht abgeschlossen
        bilder = tagbilder(cfg, kam, tag)
        if len(bilder) < 2:
            continue
        name = f"tag-{tag}"
        kennung = f"{len(bilder)}:{bilder[-1].stem}"
        if gebaut.get(name) == kennung and (ordner / "videos" / f"{name}.mp4").exists():
            continue
        if video_bauen(cfg, bilder, ordner / "videos" / f"{name}.mp4"):
            gebaut[name] = kennung
            neu = True

    # Gesamtvideo: je Tag ein Bild pro Zeitblock (Standard: eines pro Stunde)
    auswahl = []
    for tag in tage:
        if tag == heute and jetzt.hour < cfg.tag_bis:
            continue
        gesehen = set()
        for b in tagbilder(cfg, kam, tag):
            block = (int(b.stem[:2]) * 60 + int(b.stem[2:4])) // cfg.gesamt_intervall
            if block not in gesehen:
                gesehen.add(block)
                auswahl.append(b)
    if len(auswahl) >= 2:
        kennung = f"{len(auswahl)}:{auswahl[-1].parent.name}/{auswahl[-1].stem}"
        if gebaut.get("gesamt") != kennung or not (ordner / "videos" / "gesamt.mp4").exists():
            if video_bauen(cfg, auswahl, ordner / "videos" / "gesamt.mp4"):
                gebaut["gesamt"] = kennung
                neu = True

    status["videos"] = gebaut
    if alle_neu:
        status["videos_hochgeladen"] = {}
    status_speichern(ordner, status)
    return neu


def videos_hochladen(cfg, kam):
    ordner = cfg.daten / kam.slug
    status = status_laden(ordner)
    oben = status.get("videos_hochgeladen", {})
    for name, kennung in sorted(status.get("videos", {}).items()):
        pfad = ordner / "videos" / f"{name}.mp4"
        if oben.get(name) == kennung or not pfad.exists():
            continue
        try:
            video_hochladen(cfg, kam, pfad, name)
        except ServerFehler as e:
            log.warning("%s: Video %s nicht hochgeladen: %s (wird spaeter wiederholt)", kam.slug, name, e)
            break
        oben[name] = kennung
        status["videos_hochgeladen"] = oben
        status_speichern(ordner, status)


# --------------------------------------------------------------------------- Befehle

def aufnehmen(cfg, kam, jetzt, erzwingen=False):
    ordner = cfg.daten / kam.slug
    status = status_laden(ordner)
    s = slot(cfg, jetzt).isoformat(timespec="minutes")
    if not erzwingen and status.get("letzter_slot") == s:
        return False
    ziel = ordner / "bilder" / jetzt.strftime("%Y-%m-%d") / (jetzt.strftime("%H%M%S") + ".jpg")
    if bild_holen(cfg, kam, ziel):
        status = status_laden(ordner)
        status["letzter_slot"] = s
        status["letztes_bild"] = jetzt.isoformat(timespec="seconds")
        status.pop("fehler_seit", None)
        status_speichern(ordner, status)
        return True
    status = status_laden(ordner)
    status.setdefault("fehler_seit", jetzt.isoformat(timespec="seconds"))
    status_speichern(ordner, status)
    return False


def befehl_aufnahme(cfg, args, erzwingen=False):
    with Sperre(cfg.daten / ".sperre") as sperre:
        if sperre is None:
            log.info("Anderer Lauf aktiv, ueberspringe.")
            return 0
        for kam in cfg.kameras:
            aufnehmen(cfg, kam, dt.datetime.now(), erzwingen)
            bilder_hochladen(cfg, kam)
            if cfg.video_an:
                videos_aktualisieren(cfg, kam, dt.datetime.now())
                videos_hochladen(cfg, kam)
    return 0


def befehl_videos(cfg, args):
    with Sperre(cfg.daten / ".sperre") as sperre:
        if sperre is None:
            print("Anderer Lauf aktiv, bitte spaeter nochmal.")
            return 1
        for kam in cfg.kameras:
            videos_aktualisieren(cfg, kam, dt.datetime.now(), alle_neu=args.neu)
            videos_hochladen(cfg, kam)
    return 0


def befehl_test(cfg, args):
    ok = True

    def zeile(gut, text):
        print(("  [OK]    " if gut else "  [FEHLT] ") + text)

    print(f"Zeitraffer-Abholer {VERSION}, Python {sys.version.split()[0]}")
    print(f"Datenordner: {cfg.daten}")
    gefunden = os.path.isfile(cfg.neolink) or shutil.which(cfg.neolink)
    zeile(bool(gefunden), f"Neolink: {cfg.neolink}")
    ok &= bool(gefunden)
    ff = os.path.isfile(cfg.ffmpeg) or shutil.which(cfg.ffmpeg)
    zeile(bool(ff), f"ffmpeg: {cfg.ffmpeg}" + ("" if ff else " (ohne ffmpeg keine Videos)"))
    try:
        st = server_anfrage(cfg, {"aktion": "status"}, timeout=30)
        zeile(True, f"Server erreichbar, Version {st.get('version')}, PHP {st.get('php')}")
        zeile(bool(st.get("daten_schreibbar")), "Datenordner am Server schreibbar")
        for kam in cfg.kameras:
            bekannt = kam.slug in st.get("kameras", [])
            zeile(bekannt, f"Kamera '{kam.slug}' in der Server-config.php eingetragen")
            ok &= bekannt
    except ServerFehler as e:
        zeile(False, f"Server: {e}")
        ok = False
    if not gefunden:
        return 1
    print("Hole jetzt ein Bild je Kamera (dauert bis zu einer Minute) ...")
    for kam in cfg.kameras:
        gut = aufnehmen(cfg, kam, dt.datetime.now(), erzwingen=True)
        zeile(gut, f"Bild von '{kam.slug}'")
        ok &= gut
        if gut and cfg.server_url:
            zeile(bilder_hochladen(cfg, kam) > 0, "Bild hochgeladen")
    print("Alles bereit." if ok else "Bitte die markierten Punkte pruefen. Details in daten/zeitraffer.log")
    return 0 if ok else 1


def logging_einrichten(cfg, konsole):
    cfg.daten.mkdir(parents=True, exist_ok=True)
    log.setLevel(logging.INFO)
    fmt = logging.Formatter("%(asctime)s %(levelname)s %(message)s", "%Y-%m-%d %H:%M:%S")
    datei = logging.handlers.RotatingFileHandler(cfg.daten / "zeitraffer.log", maxBytes=1_000_000,
                                                 backupCount=3, encoding="utf-8")
    datei.setFormatter(fmt)
    log.addHandler(datei)
    if konsole and sys.stderr:
        k = logging.StreamHandler()
        k.setFormatter(fmt)
        log.addHandler(k)


def main():
    p = argparse.ArgumentParser(description="Zeitraffer-Abholer fuer Reolink LTE-Kameras")
    p.add_argument("befehl", nargs="?", default="aufnahme", choices=["aufnahme", "jetzt", "test", "videos"])
    p.add_argument("--config", default=str(HIER / "config.ini"))
    p.add_argument("--neu", action="store_true", help="bei 'videos': alle Videos neu erstellen")
    args = p.parse_args()

    cfg = Config(args.config)
    logging_einrichten(cfg, konsole=args.befehl != "test")
    try:
        if args.befehl == "aufnahme":
            return befehl_aufnahme(cfg, args)
        if args.befehl == "jetzt":
            return befehl_aufnahme(cfg, args, erzwingen=True)
        if args.befehl == "videos":
            return befehl_videos(cfg, args)
        return befehl_test(cfg, args)
    except Exception:
        log.exception("Unerwarteter Fehler")
        return 2


if __name__ == "__main__":
    sys.exit(main())
