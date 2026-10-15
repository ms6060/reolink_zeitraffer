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
import hashlib
import math
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

VERSION = "1.3"

try:  # Bildvergleich (Kamera verrutscht) und Video-Verpixelung je Bild
    import cv2
    import numpy as np
except ImportError:  # ohne OpenCV: Bereiche unverschoben, Videos per ffmpeg-Maske
    cv2 = None
    np = None
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
            params = {"aktion": "bild", "kamera": kam.slug, "tag": tag, "zeit": b.stem}
            lage = lage_eintrag(cfg, kam, tag, b.stem)
            if lage:
                params["lage"] = json.dumps(lage)
            server_anfrage(cfg, params, b.read_bytes(), "image/jpeg")
        except ServerFehler as e:
            log.warning("%s: Upload abgebrochen bei %s/%s: %s (wird spaeter wiederholt)", kam.slug, tag, b.name, e)
            break
        with open(liste, "a", encoding="utf-8") as f:
            f.write(f"{tag}/{b.stem}\n")
        if lage:  # mit dem Bild schon uebertragen
            oben_datei = ordner / "lage_oben.json"
            try:
                oben = json.loads(oben_datei.read_text(encoding="utf-8"))
            except (OSError, ValueError):
                oben = {}
            oben[f"{tag}/{b.stem}"] = json.dumps(lage, sort_keys=True)
            oben_datei.write_text(json.dumps(oben), encoding="utf-8")
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


def zonen_holen(cfg, kam):
    """Bereiche zum Verpixeln vom Server. Bei Verbindungsproblemen die zuletzt bekannten.
    None = unbekannt (dann keine Videos bauen, damit nichts unverpixelt hinausgeht)."""
    cache = cfg.daten / kam.slug / "zonen.json"
    try:
        d = server_anfrage(cfg, {"aktion": "zonen", "kamera": kam.slug}, timeout=30)
        z = {"art": d.get("art", "pixel"), "version": int(d.get("version", 0)),
             "ansichten": d.get("ansichten", []), "ausnahmen": d.get("ausnahmen", []),
             "lageversion": int(d.get("lageversion", 0))}
        cache.parent.mkdir(parents=True, exist_ok=True)
        cache.write_text(json.dumps(z), encoding="utf-8")
        return z
    except (ServerFehler, ValueError, TypeError) as e:
        try:
            z = json.loads(cache.read_text(encoding="utf-8"))
            log.info("%s: Bereiche vom Server nicht abrufbar (%s), verwende letzte bekannte", kam.slug, e)
            return z
        except (OSError, ValueError):
            log.warning("%s: Bereiche unbekannt (%s), Videos werden spaeter erstellt", kam.slug, e)
            return None


def hat_zonen(z):
    return bool(z) and any(a.get("zonen") for a in z.get("ansichten", []))


def ansicht_standard(z, schluessel):
    """Kameraposition fuer ein Bild ohne bekannte Lage: Referenzbild zuletzt davor, sonst die erste."""
    ans = sorted(z.get("ansichten", []), key=lambda a: a["id"])
    if not ans:
        return None
    # Grundwahl: frueheste Referenz (fuer Bilder, die vor allen Referenzbildern liegen)
    wahl = min(ans, key=lambda a: a.get("basis") or "")
    for a in ans:
        if not a.get("basis") or a["basis"] <= schluessel:
            if not wahl.get("basis") or (a.get("basis") and a["basis"] >= wahl["basis"]):
                wahl = a
    return wahl


EINHEIT = [1.0, 0.0, 0.0, 0.0, 1.0, 0.0]


def affin_verketten(b, a):
    """Erst a, dann b anwenden (beide 2x3 als Liste mit 6 Werten)."""
    return [b[0] * a[0] + b[1] * a[3], b[0] * a[1] + b[1] * a[4], b[0] * a[2] + b[1] * a[5] + b[2],
            b[3] * a[0] + b[4] * a[3], b[3] * a[1] + b[4] * a[4], b[3] * a[2] + b[4] * a[5] + b[5]]


def zonen_fuer_bild(z, schluessel, e):
    """Bereiche (Vielecke, 0..1) in Koordinaten dieses Bildes."""
    ans = {a["id"]: a for a in z.get("ansichten", [])}
    if e and e.get("a") in ans:
        a, m = ans[e["a"]], e["m"]
    else:
        a, m = ansicht_standard(z, schluessel), EINHEIT
    if not a:
        return []
    return [[(m[0] * x + m[1] * y + m[2], m[3] * x + m[4] * y + m[5]) for x, y in zone["p"]]
            for zone in a.get("zonen", [])]


def lage_laden(cfg, kam, tag):
    try:
        return json.loads((cfg.daten / kam.slug / "lage" / f"{tag}.json").read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}


def lage_speichern(cfg, kam, tag, daten):
    d = cfg.daten / kam.slug / "lage"
    d.mkdir(parents=True, exist_ok=True)
    tmp = d / f"{tag}.json.tmp"
    tmp.write_text(json.dumps(daten, sort_keys=True), encoding="utf-8")
    os.replace(tmp, d / f"{tag}.json")


def bild_lesen(pfad, grau=False):
    """Bild lesen (auch bei Umlauten im Pfad unter Windows)."""
    daten = np.fromfile(str(pfad), dtype=np.uint8)
    return cv2.imdecode(daten, cv2.IMREAD_GRAYSCALE if grau else cv2.IMREAD_COLOR)


class Vergleich:
    """Bestimmt, wie weit die Kamera gegenueber einem Referenzbild verrutscht ist."""
    BREITE = 1024

    def __init__(self):
        self.orb = cv2.ORB_create(nfeatures=4000, fastThreshold=12)
        self.clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
        self.bf = cv2.BFMatcher(cv2.NORM_HAMMING)

    def merkmale(self, pfad):
        img = bild_lesen(pfad, grau=True)
        if img is None:
            return None
        h0, w0 = img.shape[:2]
        w = self.BREITE
        h = max(2, round(h0 * w / w0))
        img = self.clahe.apply(cv2.resize(img, (w, h), interpolation=cv2.INTER_AREA))
        # Einblendungen der Kamera (Datum oben, Logo, Name unten) stehen immer an derselben
        # Stelle und wuerden "keine Verschiebung" vortaeuschen: diese Streifen auslassen.
        maske = np.zeros((h, w), np.uint8)
        maske[int(h * 0.12):int(h * 0.90), :] = 255
        kp, des = self.orb.detectAndCompute(img, maske)
        if des is None or len(kp) < 30:
            return {"pts": None, "des": None, "w": w, "h": h}
        return {"pts": np.float32([k.pt for k in kp]), "des": des, "w": w, "h": h}

    def vergleiche(self, ref, bild):
        """Liefert (Anzahl passender Punkte, Abbildung ref->bild in 0..1-Koordinaten) oder None."""
        if not ref or not bild or ref["des"] is None or bild["des"] is None:
            return None
        paare = self.bf.knnMatch(ref["des"], bild["des"], k=2)
        gute = [p[0] for p in paare if len(p) == 2 and p[0].distance < 0.75 * p[1].distance]
        if len(gute) < 25:
            return None
        src = np.float32([ref["pts"][m.queryIdx] for m in gute])
        dst = np.float32([bild["pts"][m.trainIdx] for m in gute])
        M, inl = cv2.estimateAffinePartial2D(src, dst, method=cv2.RANSAC, ransacReprojThreshold=3.0,
                                             maxIters=4000, confidence=0.995)
        if M is None or inl is None:
            return None
        n = int(inl.sum())
        if n < 30 or n < 0.3 * len(gute):
            return None
        a, b, tx = (float(v) for v in M[0])
        c, d, ty = (float(v) for v in M[1])
        w, h = bild["w"], bild["h"]
        skala = math.hypot(a, c)
        winkel = math.degrees(math.atan2(c, a))
        if not 0.8 < skala < 1.25 or abs(winkel) > 12 or abs(tx) > 0.5 * w or abs(ty) > 0.5 * h:
            return None
        return n, [a, b * h / w, tx / w, c * w / h, d, ty / h]


def lage_aktualisieren(cfg, kam, z, zeitlimit=240):
    """Lage aller Bilder bestimmen, die noch keine zur aktuellen Kameraposition haben."""
    if cv2 is None or not hat_zonen(z):
        return 0
    ordner = cfg.daten / kam.slug
    basen = {}
    for a in z.get("ansichten", []):
        if a.get("basis"):
            tag, zeit = a["basis"].split("/")
            pfad = ordner / "bilder" / tag / f"{zeit}.jpg"
            if pfad.exists():
                basen[a["id"]] = (a["basis"], pfad)
    if not basen:
        return 0
    sig = ";".join(f"{i}:{basen[i][0]}" for i in sorted(basen))
    vg = Vergleich()
    ref_merkmale = {i: vg.merkmale(p) for i, (_, p) in basen.items()}
    basis_von = {b: i for i, (b, _) in basen.items()}
    start = time.time()
    anzahl = 0
    vorher = None  # (eintrag, pfad, merkmale)
    for tag_ordner in sorted(p for p in (ordner / "bilder").glob("*") if p.is_dir()):
        tag = tag_ordner.name
        lagen = lage_laden(cfg, kam, tag)
        geaendert = False
        for b in sorted(tag_ordner.glob("*.jpg")):
            schluessel = f"{tag}/{b.stem}"
            e = lagen.get(b.stem)
            if e and e.get("sig") == sig:
                vorher = (e, b, None)
                continue
            if time.time() - start > zeitlimit:
                break
            if schluessel in basis_von:
                e = {"a": basis_von[schluessel], "m": EINHEIT, "q": "ref"}
                f = None
            else:
                f = vg.merkmale(b)
                treffer = [(r[0], i, r[1]) for i, rm in ref_merkmale.items() if (r := vg.vergleiche(rm, f))]
                if treffer:
                    n, i, m = max(treffer)
                    e = {"a": i, "m": m, "q": "ok"}
                elif vorher and vorher[0].get("a") in basen:
                    pe, pb, pf = vorher
                    pf = pf or vg.merkmale(pb)
                    r = vg.vergleiche(pf, f)
                    if r:
                        e = {"a": pe["a"], "m": affin_verketten(r[1], pe["m"]), "q": "kette"}
                    else:
                        e = {"a": pe["a"], "m": pe["m"], "q": "alt"}
                else:
                    std = ansicht_standard(z, schluessel)
                    e = {"a": std["id"] if std else 0, "m": EINHEIT, "q": "ohne"}
            e["m"] = [round(v, 6) for v in e["m"]]
            e["sig"] = sig
            lagen[b.stem] = e
            geaendert = True
            anzahl += 1
            if e["q"] in ("alt", "ohne"):
                log.info("%s: Lage von %s nicht bestimmbar (%s)", kam.slug, schluessel, e["q"])
            vorher = (e, b, f)
        if geaendert:
            lage_speichern(cfg, kam, tag, lagen)
        if time.time() - start > zeitlimit:
            log.info("%s: Lagebestimmung wird beim naechsten Lauf fortgesetzt", kam.slug)
            break
    if anzahl:
        log.info("%s: Lage von %d Bild(ern) bestimmt (%.0fs)", kam.slug, anzahl, time.time() - start)
    return anzahl


def lage_eintrag(cfg, kam, tag, zeit):
    e = lage_laden(cfg, kam, tag).get(zeit)
    return {"a": e["a"], "m": e["m"], "q": e.get("q", "")} if e else None


def lage_hochladen(cfg, kam):
    """Geaenderte Lagen bereits hochgeladener Bilder an den Server schicken."""
    ordner = cfg.daten / kam.slug
    oben_datei = ordner / "lage_oben.json"
    try:
        oben = json.loads(oben_datei.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        oben = {}
    liste = ordner / "hochgeladen.txt"
    hochgeladen = set(liste.read_text(encoding="utf-8").split()) if liste.exists() else set()
    offen = {}
    for datei in sorted((ordner / "lage").glob("*.json")):
        tag = datei.stem
        for zeit, e in lage_laden(cfg, kam, tag).items():
            k = f"{tag}/{zeit}"
            wert = json.dumps({"a": e["a"], "m": e["m"], "q": e.get("q", "")}, sort_keys=True)
            if k in hochgeladen and oben.get(k) != wert:
                offen[k] = wert
    if not offen:
        return 0
    schluessel = sorted(offen)
    for i in range(0, len(schluessel), 2000):
        teil = {k: json.loads(offen[k]) for k in schluessel[i:i + 2000]}
        try:
            server_anfrage(cfg, {"aktion": "lage", "kamera": kam.slug},
                           json.dumps(teil).encode("utf-8"), "application/json")
        except ServerFehler as e:
            log.warning("%s: Lage nicht hochgeladen: %s (wird spaeter wiederholt)", kam.slug, e)
            break
        for k in teil:
            oben[k] = offen[k]
        oben_datei.write_text(json.dumps(oben), encoding="utf-8")
    log.info("%s: Lage von %d Bild(ern) aktualisiert", kam.slug, len(offen))
    return len(offen)


def jpeg_groesse(pfad):
    """Breite und Hoehe eines JPEG aus dem Dateikopf (ohne Zusatzbibliothek)."""
    with open(pfad, "rb") as f:
        if f.read(2) != b"\xff\xd8":
            raise ValueError("kein JPEG")
        while True:
            marke = f.read(2)
            if len(marke) < 2 or marke[0] != 0xFF:
                raise ValueError("JPEG-Kopf unlesbar")
            laenge = int.from_bytes(f.read(2), "big")
            if marke[1] in (0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF):
                f.read(1)
                h = int.from_bytes(f.read(2), "big")
                b = int.from_bytes(f.read(2), "big")
                return b, h
            f.seek(laenge - 2, 1)


def maske_holen(cfg, kam, zonen, breite, hoehe):
    """Maske (weiss = verpixeln) in Videogroesse vom Server, lokal zwischengespeichert."""
    pfad = cfg.daten / kam.slug / "masken" / f"maske_{zonen['version']}_{breite}x{hoehe}.png"
    if pfad.exists():
        return pfad
    url = cfg.server_url + ("&" if "?" in cfg.server_url else "?") + urllib.parse.urlencode(
        {"aktion": "maske", "kamera": kam.slug, "b": breite, "h": hoehe})
    req = urllib.request.Request(url, headers={"X-Api-Key": cfg.api_schluessel,
                                               "User-Agent": f"zeitraffer-abholer/{VERSION}"})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            daten = r.read()
    except (urllib.error.URLError, OSError) as e:
        log.warning("%s: Maske nicht abrufbar: %s", kam.slug, e)
        return None
    if not daten.startswith(b"\x89PNG"):
        log.warning("%s: Maske ungueltig", kam.slug)
        return None
    pfad.parent.mkdir(parents=True, exist_ok=True)
    for alt in pfad.parent.glob("maske_*.png"):
        alt.unlink(missing_ok=True)
    pfad.write_bytes(daten)
    return pfad


def ausnahme_bilder(bilder, zonen):
    """Bildnummern-Bereiche [von, bis] im Video, die ohne Verpixelung gezeigt werden."""
    frei = set((zonen or {}).get("ausnahmen") or [])
    bereiche = []
    for i, b in enumerate(bilder):
        if f"{b.parent.name}/{b.stem}" in frei:
            if bereiche and bereiche[-1][1] == i - 1:
                bereiche[-1][1] = i
            else:
                bereiche.append([i, i])
    return bereiche


def video_filter(cfg, zonen, maske, breite, hoehe, frei):
    """ffmpeg-Argumente: skalieren und per Maske verpixeln bzw. weichzeichnen."""
    skal = f"scale={breite}:{hoehe}:flags=lanczos"
    if not maske:
        return ["-vf", f"{skal},format=yuv420p"]
    block = max(8, round(breite / 60))
    if zonen.get("art") == "unscharf":
        effekt = f"gblur=sigma={block * 0.9:.1f}:steps=3"
    else:
        effekt = f"pixelize=w={block}:h={block}"
    mm = "maskedmerge"
    if frei:
        # bei diesen Bildnummern das Original durchlassen (Ausnahmen)
        teile = "+".join(f"between(n,{a},{e})" for a, e in frei)
        mm += f"=enable='not({teile})'"
    graph = (f"[0:v]{skal},format=gbrp,split[a][b];[b]{effekt}[e];"
             f"[1:v]format=gbrp[m];[a][e][m]{mm},format=yuv420p[aus]")
    return ["-i", str(maske), "-filter_complex", graph, "-map", "[aus]"]


def video_bauen(cfg, bilder, ziel, zonen=None, kam=None):
    ziel.parent.mkdir(parents=True, exist_ok=True)
    try:
        qb, qh = jpeg_groesse(bilder[-1])
    except (OSError, ValueError):
        qb, qh = 16, 9
    breite = cfg.video_breite
    hoehe = max(2, int(round(qh * breite / qb / 2)) * 2)
    maske = None
    frei = []
    rahmen_ordner = None
    if hat_zonen(zonen):
        if cv2 is not None:
            # jedes Bild einzeln verpixeln, passend zu seiner Lage (Kamera verrutscht)
            rahmen_ordner = ziel.parent / (ziel.stem + "_bilder")
            bilder = bilder_verpixeln(cfg, kam, zonen, bilder, breite, hoehe, rahmen_ordner)
            if bilder is None:
                shutil.rmtree(rahmen_ordner, ignore_errors=True)
                return False
        else:
            maske = maske_holen(cfg, kam, zonen, breite, hoehe)
            if maske is None:
                return False  # lieber kein Video als ein unverpixeltes
            frei = ausnahme_bilder(bilder, zonen)
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
           *video_filter(cfg, zonen, maske, breite, hoehe, frei),
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
        if rahmen_ordner:
            shutil.rmtree(rahmen_ordner, ignore_errors=True)
    if r.returncode != 0 or not tmp.exists():
        log.error("ffmpeg-Fehler bei %s:\n%s", ziel.name, (r.stderr or "")[-1500:])
        tmp.unlink(missing_ok=True)
        return False
    os.replace(tmp, ziel)
    log.info("Video %s erstellt: %d Bilder, %.0fs", ziel.name, len(bilder), time.time() - start)
    return True


def bilder_verpixeln(cfg, kam, z, bilder, breite, hoehe, ordner):
    """Videobilder in Videogroesse schreiben, jedes passend zu seiner Lage verpixelt."""
    shutil.rmtree(ordner, ignore_errors=True)
    ordner.mkdir(parents=True)
    frei = set(z.get("ausnahmen") or [])
    block = max(8, round(breite / 60))
    lagen = {}
    aus = []
    for i, b in enumerate(bilder):
        tag, zeit = b.parent.name, b.stem
        img = bild_lesen(b)
        if img is None:
            log.warning("%s: Bild %s/%s unlesbar, ausgelassen", kam.slug, tag, zeit)
            continue
        img = cv2.resize(img, (breite, hoehe), interpolation=cv2.INTER_AREA)
        if f"{tag}/{zeit}" not in frei:
            if tag not in lagen:
                lagen[tag] = lage_laden(cfg, kam, tag)
            vielecke = zonen_fuer_bild(z, f"{tag}/{zeit}", lagen[tag].get(zeit))
            if vielecke:
                maske = np.zeros((hoehe, breite), np.uint8)
                for v in vielecke:
                    pts = np.array([[round(x * breite), round(y * hoehe)] for x, y in v], np.int32)
                    cv2.fillPoly(maske, [pts], 255)
                if z.get("art") == "unscharf":
                    k = cv2.resize(img, (max(1, round(breite / (block * 1.5))), max(1, round(hoehe / (block * 1.5)))),
                                   interpolation=cv2.INTER_AREA)
                    eff = cv2.GaussianBlur(cv2.resize(k, (breite, hoehe), interpolation=cv2.INTER_LINEAR),
                                           (0, 0), block * 0.6)
                else:
                    kb, kh = math.ceil(breite / block), math.ceil(hoehe / block)
                    k = cv2.resize(img, (kb, kh), interpolation=cv2.INTER_AREA)
                    eff = cv2.resize(k, (kb * block, kh * block), interpolation=cv2.INTER_NEAREST)[:hoehe, :breite]
                img = np.where(maske[:, :, None] > 0, eff, img)
        ziel = ordner / f"{len(aus):06d}.jpg"
        ok, daten = cv2.imencode(".jpg", img, [cv2.IMWRITE_JPEG_QUALITY, 92])
        if not ok:
            return None
        daten.tofile(str(ziel))
        aus.append(ziel)
    return aus if len(aus) >= 2 else None


def frei_kennung(cfg, kam, bilder, zonen):
    """Fingerabdruck von Ausnahmen und Lagen der Bilder dieses Videos (aendert sich -> neu bauen)."""
    if not hat_zonen(zonen):
        return ""
    frei = set(zonen.get("ausnahmen") or [])
    teile = []
    lagen = {}
    for b in bilder:
        k = f"{b.parent.name}/{b.stem}"
        if k in frei:
            teile.append("A" + k)
        if cv2 is not None:
            if b.parent.name not in lagen:
                lagen[b.parent.name] = lage_laden(cfg, kam, b.parent.name)
            e = lagen[b.parent.name].get(b.stem)
            if e:
                teile.append(f"{k}:{e['a']}:{e['m']}")
    if not teile:
        return ""
    return "|" + hashlib.sha1("\n".join(teile).encode()).hexdigest()[:12]


def videos_aktualisieren(cfg, kam, jetzt, alle_neu=False, zonen=None):
    ordner = cfg.daten / kam.slug
    zonen = zonen if zonen is not None else zonen_holen(cfg, kam)
    if zonen is None:
        return False
    # Aendern sich die Bereiche, aendert sich die Kennung, und alle Videos werden neu gebaut
    zk = f"|z{zonen['version']}" if zonen.get("version") else ""
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
        kennung = f"{len(bilder)}:{bilder[-1].stem}{zk}{frei_kennung(cfg, kam, bilder, zonen)}"
        if gebaut.get(name) == kennung and (ordner / "videos" / f"{name}.mp4").exists():
            continue
        if video_bauen(cfg, bilder, ordner / "videos" / f"{name}.mp4", zonen, kam):
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
        kennung = f"{len(auswahl)}:{auswahl[-1].parent.name}/{auswahl[-1].stem}{zk}{frei_kennung(cfg, kam, auswahl, zonen)}"
        if gebaut.get("gesamt") != kennung or not (ordner / "videos" / "gesamt.mp4").exists():
            if video_bauen(cfg, auswahl, ordner / "videos" / "gesamt.mp4", zonen, kam):
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
            zonen = zonen_holen(cfg, kam)
            if zonen is not None:
                lage_aktualisieren(cfg, kam, zonen)
            bilder_hochladen(cfg, kam)
            lage_hochladen(cfg, kam)
            if cfg.video_an and zonen is not None:
                videos_aktualisieren(cfg, kam, dt.datetime.now(), zonen=zonen)
                videos_hochladen(cfg, kam)
    return 0


def befehl_videos(cfg, args):
    with Sperre(cfg.daten / ".sperre") as sperre:
        if sperre is None:
            print("Anderer Lauf aktiv, bitte spaeter nochmal.")
            return 1
        for kam in cfg.kameras:
            zonen = zonen_holen(cfg, kam)
            if zonen is not None:
                lage_aktualisieren(cfg, kam, zonen, zeitlimit=3600)
                lage_hochladen(cfg, kam)
            videos_aktualisieren(cfg, kam, dt.datetime.now(), alle_neu=args.neu, zonen=zonen)
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
    zeile(cv2 is not None, "OpenCV fuer Bildvergleich" + (f" {cv2.__version__}" if cv2 is not None else
          " fehlt (pip install opencv-python-headless numpy) - Bereiche wandern dann nicht mit"))
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
