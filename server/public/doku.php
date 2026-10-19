<?php
// Foto- und Videodoku der Baustelle.
//   GET  doku.php                                   Liste (Admins: alle, sonst nur freigegebene)
//   GET  doku.php?aktion=datei&id=ID&g=klein|mittel|voll    Datei ausliefern
//   POST doku.php?aktion=start          JSON {typ:"foto"|"video", mime, groesse, zeit, name}  -> {id}
//   POST doku.php?aktion=teil&id=ID&offset=N&fertig=0|1     Body: Teilstueck der Datei
//   POST doku.php?aktion=vorschau&id=ID                     Body: JPEG-Vorschaubild (Videos)
//   POST doku.php?aktion=aendern        JSON {id, notiz?, frei?, zeit?}
//   POST doku.php?aktion=loeschen       JSON {id}
// Hochladen, Aendern und Loeschen nur fuer angemeldete Admins (mit CSRF-Schutz).
declare(strict_types=1);
require __DIR__ . '/_init.php';

$zugang = zr_zugang();
if ($zugang === null) {
    zr_fehler(403, 'Kein Zugriff');
}
[$slug, , $rolle, $name] = $zugang;
$istAdmin = $rolle === 'admin' && ($_GET['k'] ?? '') === '';
$aktion = (string) ($_GET['aktion'] ?? '');

// ---------------------------------------------------------------- Lesen

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $aktion === '') {
    $liste = [];
    foreach (zr_doku_liste($slug) as $e) {
        if (!empty($e['offen'])) {
            continue; // Upload noch nicht abgeschlossen
        }
        if ($istAdmin || !empty($e['frei'])) {
            unset($e['datei'], $e['offen']);
            $liste[] = $e;
        }
    }
    zr_json(['ok' => true, 'admin' => $istAdmin, 'eintraege' => $liste]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $aktion === 'datei') {
    $e = zr_doku_eintrag($slug, (string) ($_GET['id'] ?? ''));
    if ($e === null || !empty($e['offen']) || (!$istAdmin && empty($e['frei']))) {
        http_response_code(404);
        exit;
    }
    $ordner = zr_doku_pfad($slug) . '/' . substr($e['id'], 0, 10);
    $g = (string) ($_GET['g'] ?? 'mittel');
    if ($g === 'voll') {
        if (($_GET['download'] ?? '') === '1') {
            header('Content-Disposition: attachment; filename="' . $e['id'] . '.' . pathinfo($e['datei'], PATHINFO_EXTENSION) . '"');
        }
        zr_ausliefern("$ordner/{$e['datei']}", $e['mime'], 86400 * 30);
    }
    $p = "$ordner/{$e['id']}_" . ($g === 'klein' ? 'klein' : 'mittel') . '.jpg';
    if (!is_file($p)) {
        $p = "$ordner/{$e['id']}_klein.jpg";
    }
    zr_ausliefern($p, 'image/jpeg', 86400 * 30);
}

// ---------------------------------------------------------------- Schreiben (nur Admins)

if (!$istAdmin || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    zr_fehler(403, 'Nur fuer Admins');
}
zr_session();
$csrf = (string) ($_SESSION['csrf'] ?? '');
if ($csrf === '' || !hash_equals($csrf, (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
    zr_fehler(403, 'Sitzung abgelaufen, bitte Seite neu laden');
}

const DOKU_MAX = 1024 * 1024 * 1024;   // 1 GB je Datei
const DOKU_TYPEN = [
    'image/jpeg' => ['foto', 'jpg'],
    'video/mp4' => ['video', 'mp4'],
    'video/quicktime' => ['video', 'mov'],
    'video/webm' => ['video', 'webm'],
    'video/x-m4v' => ['video', 'm4v'],
    'video/3gpp' => ['video', '3gp'],
];

if ($aktion === 'start') {
    $d = json_decode((string) file_get_contents('php://input'), true);
    $mime = strtolower((string) ($d['mime'] ?? ''));
    if (!isset(DOKU_TYPEN[$mime])) {
        zr_fehler(415, 'Dateiformat nicht unterstuetzt: ' . $mime);
    }
    $groesse = (int) ($d['groesse'] ?? 0);
    if ($groesse < 100 || $groesse > DOKU_MAX) {
        zr_fehler(413, 'Datei zu gross oder leer');
    }
    [$typ, $endung] = DOKU_TYPEN[$mime];
    $zeit = zr_doku_zeit((string) ($d['zeit'] ?? ''));
    $id = date('Y-m-d', strtotime($zeit)) . '_' . date('His', strtotime($zeit)) . '_' . bin2hex(random_bytes(3));
    $e = [
        'id' => $id,
        'typ' => $typ,
        'mime' => $mime,
        'datei' => "$id.$endung",
        'groesse' => $groesse,
        'zeit' => $zeit,
        'hochgeladen' => date('c'),
        'von' => (string) $name,
        'notiz' => '',
        'frei' => false,
        'dauer' => max(0, (float) ($d['dauer'] ?? 0)),
        'offen' => true,
    ];
    zr_doku_aendern($slug, function (array $liste) use ($e) {
        $liste[] = $e;
        return $liste;
    });
    zr_json(['ok' => true, 'id' => $id]);
}

$id = (string) ($_GET['id'] ?? '');
if (in_array($aktion, ['teil', 'vorschau'], true)) {
    $e = zr_doku_eintrag($slug, $id);
    if ($e === null) {
        zr_fehler(404, 'Eintrag unbekannt');
    }
    $ordner = zr_doku_pfad($slug) . '/' . substr($id, 0, 10);
    zr_ordner($ordner);
}

if ($aktion === 'teil') {
    if (empty($e['offen'])) {
        zr_fehler(409, 'Bereits abgeschlossen');
    }
    $offset = (int) ($_GET['offset'] ?? -1);
    $teil = "$ordner/{$e['datei']}.teil";
    clearstatcache();
    $vorhanden = is_file($teil) ? filesize($teil) : 0;
    if ($offset !== 0 && $offset !== $vorhanden) {
        zr_json(['ok' => false, 'fehler' => 'Offset passt nicht', 'vorhanden' => $vorhanden], 409);
    }
    $ein = fopen('php://input', 'rb');
    $aus = fopen($teil, $offset === 0 ? 'wb' : 'ab');
    stream_copy_to_stream($ein, $aus);
    fclose($ein);
    fclose($aus);
    clearstatcache();
    $jetzt = filesize($teil);
    if ($jetzt > DOKU_MAX) {
        @unlink($teil);
        zr_fehler(413, 'Datei zu gross');
    }
    if (($_GET['fertig'] ?? '') !== '1') {
        zr_json(['ok' => true, 'vorhanden' => $jetzt]);
    }
    // Abschliessen
    $ziel = "$ordner/{$e['datei']}";
    rename($teil, $ziel);
    $kopf = (string) file_get_contents($ziel, false, null, 0, 16);
    if ($e['typ'] === 'foto') {
        if (substr($kopf, 0, 2) !== "\xFF\xD8") {
            @unlink($ziel);
            zr_doku_entfernen($slug, $id);
            zr_fehler(415, 'Kein gueltiges JPEG');
        }
        $jpeg = (string) file_get_contents($ziel);
        zr_verkleinern($jpeg, 1600, "$ordner/{$id}_mittel.jpg");
        zr_verkleinern($jpeg, 480, "$ordner/{$id}_klein.jpg");
    } elseif (strpos($kopf, 'ftyp') === false && substr($kopf, 0, 4) !== "\x1A\x45\xDF\xA3") {
        @unlink($ziel);
        zr_doku_entfernen($slug, $id);
        zr_fehler(415, 'Keine gueltige Videodatei');
    }
    zr_doku_aendern($slug, function (array $liste) use ($id, $jetzt) {
        foreach ($liste as &$x) {
            if ($x['id'] === $id) {
                unset($x['offen']);
                $x['groesse'] = $jetzt;
            }
        }
        return $liste;
    });
    zr_json(['ok' => true, 'fertig' => true]);
}

if ($aktion === 'vorschau') {
    $jpeg = (string) file_get_contents('php://input');
    if (strlen($jpeg) < 200 || strlen($jpeg) > 5 * 1024 * 1024 || substr($jpeg, 0, 2) !== "\xFF\xD8") {
        zr_fehler(400, 'Kein gueltiges JPEG');
    }
    zr_verkleinern($jpeg, 1600, "$ordner/{$id}_mittel.jpg");
    zr_verkleinern($jpeg, 480, "$ordner/{$id}_klein.jpg");
    zr_doku_aendern($slug, function (array $liste) use ($id) {
        foreach ($liste as &$x) {
            if ($x['id'] === $id) {
                $x['vorschau'] = true;
            }
        }
        return $liste;
    });
    zr_json(['ok' => true]);
}

if ($aktion === 'aendern') {
    $d = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $id = (string) ($d['id'] ?? '');
    $neu = null;
    zr_doku_aendern($slug, function (array $liste) use ($id, $d, &$neu) {
        foreach ($liste as &$x) {
            if ($x['id'] === $id) {
                if (array_key_exists('notiz', $d)) {
                    $n = trim((string) $d['notiz']);
                    $x['notiz'] = function_exists('mb_substr') ? mb_substr($n, 0, 2000) : substr($n, 0, 4000);
                }
                if (array_key_exists('frei', $d)) {
                    $x['frei'] = (bool) $d['frei'];
                }
                if (array_key_exists('zeit', $d) && (string) $d['zeit'] !== '') {
                    $x['zeit'] = zr_doku_zeit((string) $d['zeit']);
                }
                $neu = $x;
            }
        }
        return $liste;
    });
    if ($neu === null) {
        zr_fehler(404, 'Eintrag unbekannt');
    }
    unset($neu['datei'], $neu['offen']);
    zr_json(['ok' => true, 'eintrag' => $neu]);
}

if ($aktion === 'loeschen') {
    $d = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $id = (string) ($d['id'] ?? '');
    $e = zr_doku_eintrag($slug, $id);
    if ($e === null) {
        zr_fehler(404, 'Eintrag unbekannt');
    }
    $ordner = zr_doku_pfad($slug) . '/' . substr($id, 0, 10);
    foreach (["$ordner/{$e['datei']}", "$ordner/{$e['datei']}.teil", "$ordner/{$id}_mittel.jpg", "$ordner/{$id}_klein.jpg"] as $p) {
        @unlink($p);
    }
    zr_doku_entfernen($slug, $id);
    zr_json(['ok' => true]);
}

zr_fehler(400, 'Unbekannte Aktion');
