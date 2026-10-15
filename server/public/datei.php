<?php
// Liefert ein Bild oder Video aus. Zugriff mit Anmeldung oder Freigabe-Token.
//   datei.php?typ=bild|mittel|klein&tag=JJJJ-MM-TT&zeit=HHMMSS[&original=1][&download=1]
//   datei.php?typ=video&name=gesamt|tag-JJJJ-MM-TT[&download=1]
// Betrachter bekommen immer die verpixelte Fassung. Originale nur fuer Admins (original=1).
declare(strict_types=1);
require __DIR__ . '/_init.php';

$zugang = zr_zugang();
if ($zugang === null) {
    http_response_code(403);
    exit;
}
[$slug, , $rolle] = $zugang;
$basis = zr_kamera_pfad($slug);
$typ = (string) ($_GET['typ'] ?? '');
$download = ($_GET['download'] ?? '') === '1';
$original = ($_GET['original'] ?? '') === '1' && $rolle === 'admin';

if (in_array($typ, ['bild', 'mittel', 'klein'], true)) {
    $tag = (string) ($_GET['tag'] ?? '');
    $zeit = (string) ($_GET['zeit'] ?? '');
    if (!zr_ist_tag($tag) || !zr_ist_zeit($zeit)) {
        http_response_code(400);
        exit;
    }
    if ($original) {
        $pfad = $typ === 'bild' ? "$basis/bilder/$tag/$zeit.jpg" : "$basis/$typ/$tag/$zeit.jpg";
        if (!is_file($pfad)) {
            $pfad = "$basis/bilder/$tag/$zeit.jpg";
        }
    } else {
        $pfad = zr_bild_fuer_ansicht($slug, $typ, $tag, $zeit);
        if ($pfad === null) {
            http_response_code(404);
            exit;
        }
    }
    if ($download) {
        header('Content-Disposition: attachment; filename="' . $slug . '_' . $tag . '_' . $zeit . '.jpg"');
    }
    zr_ausliefern($pfad, 'image/jpeg', 86400 * 30);
}

if ($typ === 'video') {
    $name = (string) ($_GET['name'] ?? '');
    if (!zr_ist_video($name)) {
        http_response_code(400);
        exit;
    }
    if ($download) {
        header('Content-Disposition: attachment; filename="' . $slug . '_' . $name . '.mp4"');
    }
    $pfad = "$basis/videos/$name.mp4";
    if (is_file($pfad) && filemtime($pfad) <= zr_zonen($slug)['version']) {
        http_response_code(404); // noch nicht mit den aktuellen Bereichen verpixelt
        exit;
    }
    zr_ausliefern($pfad, 'video/mp4', 300);
}

http_response_code(400);
