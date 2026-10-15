<?php
// Gemeinsame Funktionen fuer den Zeitraffer-Server.
declare(strict_types=1);

const ZR_VERSION = '1.5';

$zrConfigDatei = dirname(__DIR__) . '/config.php';
if (!is_file($zrConfigDatei)) {
    http_response_code(500);
    exit('config.php fehlt (Vorlage: config.example.php)');
}
$GLOBALS['ZR'] = require $zrConfigDatei;

date_default_timezone_set($GLOBALS['ZR']['zeitzone'] ?? 'Europe/Vienna');

function zr_cfg(string $schluessel, $standard = null)
{
    return $GLOBALS['ZR'][$schluessel] ?? $standard;
}

function zr_kameras(): array
{
    return zr_cfg('kameras', []);
}

/** Liefert [slug, kamera] zu einem Freigabe-Token oder null. */
function zr_kamera_per_token(string $token): ?array
{
    if ($token === '' || strlen($token) > 100) {
        return null;
    }
    foreach (zr_kameras() as $slug => $kamera) {
        foreach (array_keys($kamera['freigaben'] ?? []) as $t) {
            if (hash_equals((string) $t, $token)) {
                return [$slug, $kamera];
            }
        }
    }
    return null;
}

// --------------------------------------------------------------------------- Anmeldung

/**
 * Zugaenge stehen in _privat/zugaenge.txt, eine Zeile je Zugang:
 *   name|rolle|kamera|salt|sha256(salt + passwort)
 * rolle: ansicht oder admin. Anlegen/aendern per SSH: bash _privat/zugang.sh
 */
function zr_zugaenge(): array
{
    $datei = dirname(__DIR__) . '/zugaenge.txt';
    $liste = [];
    foreach (is_file($datei) ? file($datei, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $zeile) {
        $teile = explode('|', trim($zeile));
        if (count($teile) === 5 && $teile[0] !== '' && $teile[0][0] !== '#') {
            [$name, $rolle, $kamera, $salt, $hash] = $teile;
            $liste[] = compact('name', 'rolle', 'kamera', 'salt', 'hash');
        }
    }
    return $liste;
}

function zr_session(bool $schreiben = false): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
    session_name('zeitraffer');
    session_set_cookie_params([
        'lifetime' => 30 * 86400, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string) (30 * 86400));
    // Lesend ohne Sperre, damit viele Bildanfragen parallel laufen koennen
    session_start($schreiben ? [] : ['read_and_close' => true]);
}

/** Prueft ein Passwort. Liefert den Zugang oder null. */
function zr_anmelden(string $passwort): ?array
{
    foreach (zr_zugaenge() as $z) {
        if (hash_equals($z['hash'], hash('sha256', $z['salt'] . $passwort)) && isset(zr_kameras()[$z['kamera']])) {
            return $z;
        }
    }
    return null;
}

/**
 * Wer greift zu? Freigabe-Token (?k=) oder angemeldete Sitzung.
 * Liefert [slug, kamera, rolle, name] oder null.
 */
function zr_zugang(): ?array
{
    $token = (string) ($_GET['k'] ?? '');
    if ($token !== '') {
        $t = zr_kamera_per_token($token);
        return $t ? [$t[0], $t[1], 'ansicht', (string) ($t[1]['freigaben'][$token] ?? 'Link')] : null;
    }
    if (!isset($_COOKIE['zeitraffer'])) {
        return null;
    }
    zr_session();
    $slug = (string) ($_SESSION['kamera'] ?? '');
    $kameras = zr_kameras();
    if ($slug === '' || !isset($kameras[$slug])) {
        return null;
    }
    // Zugang muss noch existieren (Passwort entfernt = abgemeldet)
    foreach (zr_zugaenge() as $z) {
        if ($z['name'] === ($_SESSION['name'] ?? '') && $z['hash'] === ($_SESSION['hash'] ?? '')) {
            return [$slug, $kameras[$slug], $z['rolle'], $z['name']];
        }
    }
    return null;
}

/** Erscheinungsbild: global, je Kamera ueberschreibbar. */
function zr_design(array $kamera): array
{
    $standard = [
        'firma' => 'clever & smart immo gmbh',
        'logo' => 'logo.png',
        'akzent' => '#e9e9ea',
        'akzent_text' => '#141315',
        'dunkel' => '#232224',
        'schrift' => '',
        'schrift_css' => '',
        'fusszeile' => (string) zr_cfg('fusszeile', ''),
    ];
    return array_merge($standard, zr_cfg('design', []), $kamera['design'] ?? []);
}

function zr_daten_pfad(): string
{
    return rtrim((string) zr_cfg('daten_pfad', ''), '/\\');
}

function zr_kamera_pfad(string $slug): string
{
    return zr_daten_pfad() . '/' . $slug;
}

function zr_ist_slug(string $s): bool    { return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/', $s); }
function zr_ist_tag(string $s): bool     { return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s); }
function zr_ist_zeit(string $s): bool    { return (bool) preg_match('/^\d{6}$/', $s); }
function zr_ist_video(string $s): bool   { return (bool) preg_match('/^(tag-\d{4}-\d{2}-\d{2}|gesamt)$/', $s); }

function zr_json($daten, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function zr_fehler(int $code, string $meldung): void
{
    zr_json(['ok' => false, 'fehler' => $meldung], $code);
}

function zr_ordner(string $pfad): void
{
    if (!is_dir($pfad) && !mkdir($pfad, 0750, true) && !is_dir($pfad)) {
        zr_fehler(500, 'Ordner kann nicht angelegt werden');
    }
}

/** Datei atomar schreiben (erst temporaer, dann umbenennen). */
function zr_schreiben(string $ziel, string $inhalt): void
{
    zr_ordner(dirname($ziel));
    $tmp = $ziel . '.tmp' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $inhalt) === false || !rename($tmp, $ziel)) {
        @unlink($tmp);
        zr_fehler(500, 'Datei kann nicht gespeichert werden');
    }
}

/** Verkleinerte Fassung eines JPEG erzeugen. Ohne GD wird das Original kopiert. */
function zr_verkleinern(string $jpeg, int $breite, string $ziel): void
{
    if (!function_exists('imagecreatefromstring')) {
        zr_schreiben($ziel, $jpeg);
        return;
    }
    $bild = @imagecreatefromstring($jpeg);
    if ($bild === false) {
        return;
    }
    $b = imagesx($bild);
    $h = imagesy($bild);
    if ($b <= $breite) {
        zr_schreiben($ziel, $jpeg);
        return;
    }
    $nh = (int) round($h * $breite / $b);
    $klein = imagecreatetruecolor($breite, $nh);
    imagecopyresampled($klein, $bild, 0, 0, 0, 0, $breite, $nh, $b, $h);
    ob_start();
    imagejpeg($klein, null, 80);
    zr_schreiben($ziel, (string) ob_get_clean());
}

// --------------------------------------------------------------------------- Verpixeln

/**
 * Bereiche je Kamera in DATEN/<slug>/zonen.json:
 *   {"art":"pixel|unscharf","version":123,"ansichten":[{"id":1,"basis":"JJJJ-MM-TT/HHMMSS","zonen":[{"p":[[x,y],...]}]}]}
 * Eine Ansicht ist eine Kameraposition: Referenzbild (basis) und die darauf gezeichneten Bereiche.
 * Koordinaten relativ zur Bildgroesse (0..1) im Referenzbild. Aeltere Dateien ("zonen" ohne
 * Ansichten, Rechtecke) werden beim Lesen umgewandelt.
 */
function zr_zonen(string $slug): array
{
    $datei = zr_kamera_pfad($slug) . '/zonen.json';
    $d = is_file($datei) ? json_decode((string) file_get_contents($datei), true) : null;
    $d = is_array($d) ? $d : [];
    $ansichten = [];
    if (isset($d['ansichten']) && is_array($d['ansichten'])) {
        foreach ($d['ansichten'] as $a) {
            if (is_array($a) && (int) ($a['id'] ?? 0) > 0) {
                $b = (string) ($a['basis'] ?? '');
                $ansichten[] = [
                    'id' => (int) $a['id'],
                    'basis' => preg_match('#^\d{4}-\d{2}-\d{2}/\d{6}$#', $b) ? $b : null,
                    'zonen' => zr_zonen_pruefen($a['zonen'] ?? []),
                ];
            }
        }
    } elseif (!empty($d['zonen'])) {
        $ansichten[] = ['id' => 1, 'basis' => null, 'zonen' => zr_zonen_pruefen($d['zonen'])];
    }
    usort($ansichten, fn($a, $b) => $a['id'] <=> $b['id']);
    return [
        'art' => ($d['art'] ?? '') === 'unscharf' ? 'unscharf' : 'pixel',
        'version' => (int) ($d['version'] ?? 0),
        'ansichten' => $ansichten,
    ];
}

/** Gibt es ueberhaupt Bereiche? */
function zr_hat_zonen(array $z): bool
{
    foreach ($z['ansichten'] as $a) {
        if ($a['zonen']) {
            return true;
        }
    }
    return false;
}

/** Bereinigt eine Zonenliste: Vielecke mit 3..400 Punkten im Bereich 0..1, hoechstens 40 Bereiche. */
function zr_zonen_pruefen($liste): array
{
    $aus = [];
    foreach (is_array($liste) ? $liste : [] as $z) {
        if (!is_array($z)) {
            continue;
        }
        if (isset($z['x'], $z['y'], $z['b'], $z['h'])) { // altes Rechteck
            $x = (float) $z['x']; $y = (float) $z['y']; $b = (float) $z['b']; $h = (float) $z['h'];
            $z = ['p' => [[$x, $y], [$x + $b, $y], [$x + $b, $y + $h], [$x, $y + $h]]];
        }
        $punkte = [];
        foreach (is_array($z['p'] ?? null) ? $z['p'] : [] as $pt) {
            if (is_array($pt) && count($pt) >= 2) {
                // etwas Spielraum ueber den Rand hinaus (bei verschobener Kamera sichtbar)
                $punkte[] = [round(max(-0.5, min(1.5, (float) $pt[0])), 5), round(max(-0.5, min(1.5, (float) $pt[1])), 5)];
            }
            if (count($punkte) >= 400) {
                break;
            }
        }
        if (count($punkte) < 3) {
            continue;
        }
        $xs = array_column($punkte, 0);
        $ys = array_column($punkte, 1);
        if (max($xs) - min($xs) < 0.005 || max($ys) - min($ys) < 0.005) {
            continue;
        }
        $aus[] = ['p' => $punkte];
        if (count($aus) >= 40) {
            break;
        }
    }
    return $aus;
}

function zr_zonen_schreiben(string $slug, array $z): array
{
    $alt = zr_zonen($slug);
    // Version immer hoeher als die alte, auch bei zwei Speichervorgaengen in derselben Sekunde
    $z['version'] = max(time(), $alt['version'] + 1, zr_lageversion($slug) + 1);
    zr_schreiben(zr_kamera_pfad($slug) . '/zonen.json', json_encode([
        'art' => $z['art'], 'version' => $z['version'], 'ansichten' => $z['ansichten'],
    ]));
    return $z;
}

/**
 * Bereiche speichern, die im Bild tag/zeit gezeichnet wurden (Koordinaten dieses Bildes).
 * $neu = true: als neue Kameraposition mit diesem Bild als Referenz.
 * Sonst: zur Kameraposition dieses Bildes; die Bereiche werden ins Referenzbild umgerechnet.
 */
function zr_zonen_speichern(string $slug, string $art, array $zonen, string $tag, string $zeit, bool $neu): array
{
    $z = zr_zonen($slug);
    $z['art'] = $art === 'unscharf' ? 'unscharf' : 'pixel';
    $zonen = zr_zonen_pruefen($zonen);
    $schluessel = "$tag/$zeit";
    $lage = zr_lage_fuer_bild($slug, $tag, $zeit, $z);
    $index = null;
    foreach ($z['ansichten'] as $i => $a) {
        if ($lage !== null && $a['id'] === $lage['a']) {
            $index = $i;
        }
    }
    if ($neu || $index === null) {
        $id = 1 + array_reduce($z['ansichten'], fn($m, $a) => max($m, $a['id']), 0);
        $z['ansichten'][] = ['id' => $id, 'basis' => $schluessel, 'zonen' => $zonen];
        zr_lage_setzen($slug, [$schluessel => ['a' => $id, 'm' => [1, 0, 0, 0, 1, 0], 'q' => 'ref']], false);
    } elseif ($z['ansichten'][$index]['basis'] === null) {
        // Kameraposition ohne Referenzbild (aus aelterer Version): dieses Bild wird die Referenz
        $z['ansichten'][$index]['basis'] = $schluessel;
        $z['ansichten'][$index]['zonen'] = $zonen;
        zr_lage_setzen($slug, [$schluessel => ['a' => $z['ansichten'][$index]['id'], 'm' => [1, 0, 0, 0, 1, 0], 'q' => 'ref']], false);
    } else {
        // in Koordinaten des Referenzbildes zurueckrechnen
        $inv = zr_affin_invers($lage['m']);
        $z['ansichten'][$index]['zonen'] = zr_zonen_pruefen(zr_zonen_transformieren($zonen, $inv));
    }
    // Kamerapositionen ohne Bereiche wieder entfernen (ausser es ist die einzige)
    if (count($z['ansichten']) > 1) {
        $z['ansichten'] = array_values(array_filter($z['ansichten'], fn($a) => $a['zonen']));
    }
    return zr_zonen_schreiben($slug, $z);
}

// --------------------------------------------------------------------------- Lage (Kamera verrutscht)

/**
 * Lage je Bild in DATEN/<slug>/lage/<tag>.json: {"HHMMSS": {"a": Ansicht-ID, "m": [a,b,c,d,e,f], "q": Guete}}
 * m bildet Referenzbild-Koordinaten (0..1) auf Bild-Koordinaten ab: x' = a*x + b*y + c, y' = d*x + e*y + f.
 * Wird vom Abholer per Bildvergleich bestimmt.
 */
function zr_lage_tag(string $slug, string $tag): array
{
    $datei = zr_kamera_pfad($slug) . "/lage/$tag.json";
    $k = "$slug/$tag";
    if (!isset($GLOBALS['ZR_LAGE'][$k])) {
        $d = is_file($datei) ? json_decode((string) file_get_contents($datei), true) : null;
        $GLOBALS['ZR_LAGE'][$k] = is_array($d) ? $d : [];
    }
    return $GLOBALS['ZR_LAGE'][$k];
}

function zr_lage_pruefen($e): ?array
{
    if (!is_array($e) || !isset($e['a'], $e['m']) || !is_array($e['m']) || count($e['m']) !== 6) {
        return null;
    }
    $m = array_map('floatval', array_values($e['m']));
    foreach ($m as $v) {
        if (!is_finite($v) || abs($v) > 10) {
            return null;
        }
    }
    return ['a' => (int) $e['a'], 'm' => $m, 'q' => substr(preg_replace('/[^a-z]/', '', (string) ($e['q'] ?? '')), 0, 12)];
}

/**
 * Lagen schreiben: ["JJJJ-MM-TT/HHMMSS" => {...}]. $alteBilder: betrifft schon gezeigte Bilder (Cache erneuern).
 * Liefert die Schluessel der geaenderten Bilder.
 */
function zr_lage_setzen(string $slug, array $eintraege, bool $alteBilder): array
{
    $jeTag = [];
    foreach ($eintraege as $schluessel => $e) {
        if (!preg_match('#^(\d{4}-\d{2}-\d{2})/(\d{6})$#', (string) $schluessel, $m)) {
            continue;
        }
        $e = zr_lage_pruefen($e);
        if ($e !== null) {
            $jeTag[$m[1]][$m[2]] = $e;
        }
    }
    $geaendert = [];
    foreach ($jeTag as $tag => $neu) {
        $datei = zr_kamera_pfad($slug) . "/lage/$tag.json";
        $alt = is_file($datei) ? (json_decode((string) file_get_contents($datei), true) ?: []) : [];
        foreach ($neu as $zeit => $e) {
            if (($alt[$zeit] ?? null) != $e) {
                $alt[$zeit] = $e;
                $geaendert[] = "$tag/$zeit";
            }
        }
        ksort($alt);
        zr_schreiben($datei, json_encode($alt));
        unset($GLOBALS['ZR_LAGE']["$slug/$tag"]);
    }
    if ($geaendert && $alteBilder) {
        // neue Adressen fuer alle Bilder, damit Browser keine alten Fassungen zeigen
        zr_schreiben(zr_kamera_pfad($slug) . '/lageversion.txt',
            (string) max(time(), zr_lageversion($slug) + 1, zr_zonen($slug)['version'] + 1));
    }
    return $geaendert;
}

function zr_lageversion(string $slug): int
{
    $d = zr_kamera_pfad($slug) . '/lageversion.txt';
    return is_file($d) ? (int) file_get_contents($d) : 0;
}

/**
 * Lage eines Bildes. Ohne Eintrag: Kameraposition, deren Referenzbild zuletzt vor dem Bild liegt,
 * ohne Verschiebung. Liefert null, wenn es keine Kameraposition gibt.
 */
function zr_lage_fuer_bild(string $slug, string $tag, string $zeit, ?array $z = null): ?array
{
    $z = $z ?? zr_zonen($slug);
    if (!$z['ansichten']) {
        return null;
    }
    $e = zr_lage_pruefen(zr_lage_tag($slug, $tag)[$zeit] ?? null);
    $ids = array_column($z['ansichten'], 'id');
    if ($e !== null && in_array($e['a'], $ids, true)) {
        return $e;
    }
    $schluessel = "$tag/$zeit";
    // Grundwahl: Kameraposition mit dem fruehesten Referenzbild (fuer Bilder vor allen Referenzen)
    $wahl = $z['ansichten'][0];
    foreach ($z['ansichten'] as $a) {
        if ($a['basis'] === null || ($wahl['basis'] !== null && strcmp($a['basis'], $wahl['basis']) < 0)) {
            $wahl = $a;
        }
    }
    foreach ($z['ansichten'] as $a) {
        if ($a['basis'] === null || strcmp($a['basis'], $schluessel) <= 0) {
            if ($wahl['basis'] === null || ($a['basis'] !== null && strcmp($a['basis'], (string) $wahl['basis']) >= 0)) {
                $wahl = $a;
            }
        }
    }
    return ['a' => $wahl['id'], 'm' => [1, 0, 0, 0, 1, 0], 'q' => 'ohne'];
}

function zr_affin_invers(array $m): array
{
    [$a, $b, $c, $d, $e, $f] = $m;
    $det = $a * $e - $b * $d;
    if (abs($det) < 1e-9) {
        return [1, 0, 0, 0, 1, 0];
    }
    return [$e / $det, -$b / $det, ($b * $f - $c * $e) / $det,
            -$d / $det, $a / $det, ($c * $d - $a * $f) / $det];
}

function zr_zonen_transformieren(array $zonen, array $m): array
{
    [$a, $b, $c, $d, $e, $f] = $m;
    $aus = [];
    foreach ($zonen as $z) {
        $aus[] = ['p' => array_map(fn($q) => [$a * $q[0] + $b * $q[1] + $c, $d * $q[0] + $e * $q[1] + $f], $z['p'])];
    }
    return $aus;
}

/** Bereiche in Koordinaten eines bestimmten Bildes (Kameraposition + Lage beruecksichtigt). */
function zr_zonen_fuer_bild(string $slug, string $tag, string $zeit, ?array $z = null): array
{
    $z = $z ?? zr_zonen($slug);
    $lage = zr_lage_fuer_bild($slug, $tag, $zeit, $z);
    if ($lage === null) {
        return ['ansicht' => null, 'zonen' => [], 'lage' => null];
    }
    foreach ($z['ansichten'] as $a) {
        if ($a['id'] === $lage['a']) {
            return ['ansicht' => $a['id'], 'zonen' => zr_zonen_transformieren($a['zonen'], $lage['m']), 'lage' => $lage];
        }
    }
    return ['ansicht' => null, 'zonen' => [], 'lage' => $lage];
}

/** Punkte eines Bereichs in Pixel (flache Liste x1,y1,x2,y2,... relativ zu ($ox,$oy)). */
function zr_punkte_px(array $zone, int $B, int $H, int $ox = 0, int $oy = 0): array
{
    $flach = [];
    foreach ($zone['p'] as [$x, $y]) {
        $flach[] = (int) round($x * $B) - $ox;
        $flach[] = (int) round($y * $H) - $oy;
    }
    return $flach;
}

/** Vieleck fuellen (PHP 8.1+ ohne, aeltere mit Punktanzahl). */
function zr_vieleck($bild, array $flach, int $farbe): void
{
    if (PHP_VERSION_ID >= 80100) {
        imagefilledpolygon($bild, $flach, $farbe);
    } else {
        imagefilledpolygon($bild, $flach, intdiv(count($flach), 2), $farbe);
    }
}

/** Pixel- bzw. Unschaerfe-Effekt auf ein ganzes (Zwischen-)Bild anwenden. */
function zr_effekt($bild, string $art, int $block)
{
    $b = imagesx($bild);
    $h = imagesy($bild);
    if ($art === 'unscharf') {
        // stark verkleinern, auf 1/6 vergroessern und weichzeichnen, dann auf volle Groesse
        $kb = max(1, (int) round($b / ($block * 1.5)));
        $kh = max(1, (int) round($h / ($block * 1.5)));
        $klein = imagecreatetruecolor($kb, $kh);
        imagecopyresampled($klein, $bild, 0, 0, 0, 0, $kb, $kh, $b, $h);
        $mb = max(2, (int) round($b / 6));
        $mh = max(2, (int) round($h / 6));
        $mittel = imagecreatetruecolor($mb, $mh);
        imagecopyresampled($mittel, $klein, 0, 0, 0, 0, $mb, $mh, $kb, $kh);
        for ($i = 0; $i < 8; $i++) {
            imagefilter($mittel, IMG_FILTER_GAUSSIAN_BLUR);
        }
        $aus = imagecreatetruecolor($b, $h);
        imagecopyresampled($aus, $mittel, 0, 0, 0, 0, $b, $h, $mb, $mh);
        return $aus;
    }
    $kb = max(1, (int) ceil($b / $block));
    $kh = max(1, (int) ceil($h / $block));
    $klein = imagecreatetruecolor($kb, $kh);
    imagecopyresampled($klein, $bild, 0, 0, 0, 0, $kb, $kh, $b, $h);
    $aus = imagecreatetruecolor($b, $h);
    imagecopyresized($aus, $klein, 0, 0, 0, 0, $kb * $block, $kh * $block, $kb, $kh);
    return $aus;
}

/**
 * Wendet die Bereiche auf ein GD-Bild an (veraendert es direkt).
 * Je Bereich: Effekt auf das umschliessende Rechteck, dann per Schablone nur das Vieleck uebernehmen.
 */
function zr_maskieren($bild, array $zonen, string $art): void
{
    $B = imagesx($bild);
    $H = imagesy($bild);
    $block = max(8, (int) round($B / 60));      // Pixelgroesse: etwa 1/60 der Bildbreite
    foreach ($zonen as $z) {
        $xs = array_column($z['p'], 0);
        $ys = array_column($z['p'], 1);
        // Raster an den Bloecken ausrichten, damit Pixel in allen Bereichen gleich sitzen
        $x = max(0, (int) (floor(min($xs) * $B / $block) * $block));
        $y = max(0, (int) (floor(min($ys) * $H / $block) * $block));
        $b = min($B, (int) ceil(max($xs) * $B) + 1) - $x;
        $h = min($H, (int) ceil(max($ys) * $H) + 1) - $y;
        if ($b < 2 || $h < 2) {
            continue;
        }
        $teil = imagecreatetruecolor($b, $h);
        imagecopy($teil, $bild, 0, 0, $x, $y, $b, $h);
        $eff = zr_effekt($teil, $art, $block);

        // Schablone: aussen Schluesselfarbe, innen durchsichtig
        $schablone = imagecreatetruecolor($b, $h);
        $aussen = imagecolorallocate($schablone, 255, 0, 254);
        $innen = imagecolorallocate($schablone, 1, 254, 2);
        imagefill($schablone, 0, 0, $aussen);
        zr_vieleck($schablone, zr_punkte_px($z, $B, $H, $x, $y), $innen);
        imagecolortransparent($schablone, $innen);
        imagecopy($eff, $schablone, 0, 0, 0, 0, $b, $h);   // aussen wird Schluesselfarbe

        imagecolortransparent($eff, imagecolorexact($eff, 255, 0, 254));
        imagecopy($bild, $eff, $x, $y, 0, 0, $b, $h);      // nur das Innere landet im Bild
    }
}

/** Schwarz-weisse Maske (weiss = verpixeln) als PNG, z. B. fuer die Videos. */
function zr_maske_png(array $zonen, int $B, int $H): string
{
    $m = imagecreatetruecolor($B, $H);
    $weiss = imagecolorallocate($m, 255, 255, 255);
    foreach ($zonen as $z) {
        zr_vieleck($m, zr_punkte_px($z, $B, $H), $weiss);
    }
    ob_start();
    imagepng($m);
    return (string) ob_get_clean();
}

// --------------------------------------------------------------------------- Ausnahmen

/** Bilder, die bewusst ohne Verpixelung gezeigt werden: Liste "JJJJ-MM-TT/HHMMSS". */
function zr_ausnahmen(string $slug): array
{
    $datei = zr_kamera_pfad($slug) . '/ausnahmen.json';
    $d = is_file($datei) ? json_decode((string) file_get_contents($datei), true) : null;
    return is_array($d) ? array_values(array_filter($d, fn($s) => is_string($s)
        && preg_match('#^\d{4}-\d{2}-\d{2}/\d{6}$#', $s))) : [];
}

function zr_ist_ausnahme(string $slug, string $tag, string $zeit): bool
{
    return in_array("$tag/$zeit", zr_ausnahmen($slug), true);
}

function zr_ausnahme_setzen(string $slug, string $tag, string $zeit, bool $an): array
{
    $liste = zr_ausnahmen($slug);
    $schluessel = "$tag/$zeit";
    $liste = array_values(array_diff($liste, [$schluessel]));
    if ($an) {
        $liste[] = $schluessel;
    }
    sort($liste);
    zr_schreiben(zr_kamera_pfad($slug) . '/ausnahmen.json', json_encode($liste));
    return $liste;
}

/**
 * Pfad zur Fassung, die Betrachter sehen duerfen (typ: bild|mittel|klein).
 * Ohne Bereiche ist das das Original. Mit Bereichen wird die verpixelte Fassung
 * bei Bedarf erzeugt bzw. nach einer Aenderung (Bereiche oder Lage) neu erzeugt.
 * Liefert null, wenn das Bild nicht existiert oder nicht verpixelt werden kann.
 */
function zr_bild_fuer_ansicht(string $slug, string $typ, string $tag, string $zeit): ?string
{
    $basis = zr_kamera_pfad($slug);
    $original = "$basis/bilder/$tag/$zeit.jpg";
    if (!is_file($original)) {
        return null;
    }
    $z = zr_zonen($slug);
    if (!zr_hat_zonen($z) || zr_ist_ausnahme($slug, $tag, $zeit)) {
        $p = $typ === 'bild' ? $original : "$basis/$typ/$tag/$zeit.jpg";
        return is_file($p) ? $p : $original;
    }
    $fb = zr_zonen_fuer_bild($slug, $tag, $zeit, $z);
    $soll = $z['version'] . '-' . md5(json_encode([$z['art'], $fb['zonen']]));
    $ziel = "$basis/zensiert/$typ/$tag/$zeit.jpg";
    $stempel = "$basis/zensiert/$typ/$tag/$zeit.v";
    if (is_file($ziel) && is_file($stempel) && file_get_contents($stempel) === $soll) {
        return $ziel;
    }
    return zr_verpixeln($slug, $tag, $zeit, $z['art'], $fb['zonen'], $soll) ? $ziel : null;
}

/** Erzeugt alle drei verpixelten Fassungen eines Bildes. */
function zr_verpixeln(string $slug, string $tag, string $zeit, ?string $art = null, ?array $zonen = null, ?string $stempel = null): bool
{
    if (!function_exists('imagecreatefromjpeg')) {
        return false; // ohne GD lieber nichts zeigen als das Original
    }
    if ($zonen === null) {
        $z = zr_zonen($slug);
        $fb = zr_zonen_fuer_bild($slug, $tag, $zeit, $z);
        $art = $z['art'];
        $zonen = $fb['zonen'];
        $stempel = $z['version'] . '-' . md5(json_encode([$art, $zonen]));
    }
    $basis = zr_kamera_pfad($slug);
    $bild = @imagecreatefromjpeg("$basis/bilder/$tag/$zeit.jpg");
    if ($bild === false) {
        return false;
    }
    zr_maskieren($bild, zr_zonen_pruefen_frei($zonen), (string) $art);
    ob_start();
    imagejpeg($bild, null, 88);
    $gross = (string) ob_get_clean();
    zr_schreiben("$basis/zensiert/bild/$tag/$zeit.jpg", $gross);
    zr_verkleinern($gross, 1280, "$basis/zensiert/mittel/$tag/$zeit.jpg");
    zr_verkleinern($gross, 320, "$basis/zensiert/klein/$tag/$zeit.jpg");
    foreach (['bild', 'mittel', 'klein'] as $t) {
        zr_schreiben("$basis/zensiert/$t/$tag/$zeit.v", (string) $stempel);
    }
    return true;
}

/** Wie zr_zonen_pruefen, aber Punkte duerfen ausserhalb liegen (verschobene Bereiche am Bildrand). */
function zr_zonen_pruefen_frei(array $zonen): array
{
    $aus = [];
    foreach ($zonen as $z) {
        $p = [];
        foreach ($z['p'] ?? [] as $q) {
            $p[] = [max(-0.5, min(1.5, (float) $q[0])), max(-0.5, min(1.5, (float) $q[1]))];
        }
        if (count($p) >= 3) {
            $aus[] = ['p' => $p];
        }
    }
    return $aus;
}

/** Alle Bilder einer Kamera als Liste [tag, zeit], aelteste zuerst. */
function zr_alle_bilder(string $slug): array
{
    $liste = [];
    foreach (glob(zr_kamera_pfad($slug) . '/bilder/*', GLOB_ONLYDIR) ?: [] as $ordner) {
        $tag = basename($ordner);
        if (!zr_ist_tag($tag)) {
            continue;
        }
        foreach (glob("$ordner/*.jpg") ?: [] as $datei) {
            $z = basename($datei, '.jpg');
            if (zr_ist_zeit($z)) {
                $liste[] = [$tag, $z];
            }
        }
    }
    sort($liste);
    return $liste;
}

/** Datei ausliefern, mit Caching und Range-Unterstuetzung (fuer Videos auf iPhone/Safari). */
function zr_ausliefern(string $pfad, string $typ, int $maxAlter): void
{
    if (!is_file($pfad)) {
        http_response_code(404);
        exit;
    }
    $groesse = filesize($pfad);
    $mtime = filemtime($pfad);
    $etag = '"' . dechex($mtime) . '-' . dechex($groesse) . '"';

    header('Content-Type: ' . $typ);
    header('Cache-Control: private, max-age=' . $maxAlter);
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('Accept-Ranges: bytes');
    header('X-Robots-Tag: noindex, nofollow');

    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }

    $start = 0;
    $ende = $groesse - 1;
    if (preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'] ?? '', $m)) {
        if ($m[1] === '' && $m[2] !== '') {
            $start = max(0, $groesse - (int) $m[2]);
        } else {
            $start = (int) $m[1];
            if ($m[2] !== '') {
                $ende = min((int) $m[2], $groesse - 1);
            }
        }
        if ($start > $ende || $start >= $groesse) {
            http_response_code(416);
            header("Content-Range: bytes */$groesse");
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$ende/$groesse");
    }
    $laenge = $ende - $start + 1;
    header('Content-Length: ' . $laenge);
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
        exit;
    }

    $fh = fopen($pfad, 'rb');
    fseek($fh, $start);
    $rest = $laenge;
    while ($rest > 0 && !feof($fh)) {
        $stueck = fread($fh, (int) min(65536, $rest));
        echo $stueck;
        $rest -= strlen($stueck);
        flush();
    }
    fclose($fh);
    exit;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
