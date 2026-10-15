<?php
// Schnittstelle fuer den Abholer. Authentifizierung ueber Header X-Api-Key.
//
//   GET  api.php?aktion=status
//   GET  api.php?aktion=zonen&kamera=SLUG
//   POST api.php?aktion=bild&kamera=SLUG&tag=JJJJ-MM-TT&zeit=HHMMSS     (Body: JPEG)
//   POST api.php?aktion=video&kamera=SLUG&name=NAME&offset=N&fertig=0|1 (Body: Teilstueck)
declare(strict_types=1);
require __DIR__ . '/_init.php';

$schluessel = (string) zr_cfg('api_schluessel', '');
$gesendet = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
if (strlen($schluessel) < 20 || !hash_equals($schluessel, $gesendet)) {
    zr_fehler(403, 'Schluessel ungueltig');
}

$aktion = (string) ($_GET['aktion'] ?? '');

if ($aktion === 'status') {
    $pfad = zr_daten_pfad();
    zr_json([
        'ok' => true,
        'version' => ZR_VERSION,
        'php' => PHP_VERSION,
        'gd' => function_exists('imagecreatefromstring'),
        'daten_schreibbar' => $pfad !== '' && (is_dir($pfad) ? is_writable($pfad) : is_writable(dirname($pfad))),
        'kameras' => array_keys(zr_kameras()),
    ]);
}

$slug = (string) ($_GET['kamera'] ?? '');
if (!zr_ist_slug($slug) || !isset(zr_kameras()[$slug])) {
    zr_fehler(404, 'Kamera unbekannt: ' . $slug);
}
$basis = zr_kamera_pfad($slug);

if ($aktion === 'zonen') {
    // Fuer den Abholer, damit die Videos gleich verpixelt werden
    zr_json(['ok' => true] + zr_zonen($slug));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    zr_fehler(405, 'Nur POST');
}

if ($aktion === 'bild') {
    $tag = (string) ($_GET['tag'] ?? '');
    $zeit = (string) ($_GET['zeit'] ?? '');
    if (!zr_ist_tag($tag) || !zr_ist_zeit($zeit)) {
        zr_fehler(400, 'tag/zeit ungueltig');
    }
    $jpeg = (string) file_get_contents('php://input');
    if (strlen($jpeg) < 1000 || strlen($jpeg) > 20 * 1024 * 1024 || substr($jpeg, 0, 2) !== "\xFF\xD8") {
        zr_fehler(400, 'Kein gueltiges JPEG');
    }
    zr_schreiben("$basis/bilder/$tag/$zeit.jpg", $jpeg);
    zr_verkleinern($jpeg, 1280, "$basis/mittel/$tag/$zeit.jpg");
    zr_verkleinern($jpeg, 320, "$basis/klein/$tag/$zeit.jpg");
    if (zr_zonen($slug)['zonen']) {
        zr_verpixeln($slug, $tag, $zeit); // gleich erzeugen, damit die Galerie nicht warten muss
    }
    zr_json(['ok' => true]);
}

if ($aktion === 'video') {
    // Videos kommen in Teilstuecken, damit Upload-Limits des Hostings nicht greifen.
    $name = (string) ($_GET['name'] ?? '');
    $offset = (int) ($_GET['offset'] ?? -1);
    $fertig = ($_GET['fertig'] ?? '0') === '1';
    if (!zr_ist_video($name) || $offset < 0) {
        zr_fehler(400, 'name/offset ungueltig');
    }
    $ordner = "$basis/videos";
    zr_ordner($ordner);
    $teil = "$ordner/$name.mp4.teil";

    clearstatcache();
    $vorhanden = is_file($teil) ? filesize($teil) : 0;
    if ($offset === 0) {
        $vorhanden = 0;
    } elseif ($offset !== $vorhanden) {
        zr_json(['ok' => false, 'fehler' => 'Offset passt nicht', 'vorhanden' => $vorhanden], 409);
    }

    $ein = fopen('php://input', 'rb');
    $aus = fopen($teil, $offset === 0 ? 'wb' : 'ab');
    if (!$ein || !$aus) {
        zr_fehler(500, 'Datei kann nicht geschrieben werden');
    }
    stream_copy_to_stream($ein, $aus);
    fclose($ein);
    fclose($aus);
    clearstatcache();

    if ($fertig) {
        if (!rename($teil, "$ordner/$name.mp4")) {
            zr_fehler(500, 'Video kann nicht abgeschlossen werden');
        }
        zr_json(['ok' => true, 'fertig' => true]);
    }
    zr_json(['ok' => true, 'vorhanden' => filesize($teil)]);
}

zr_fehler(400, 'Unbekannte Aktion');
