<?php
// Liefert ein Bild oder Video aus. Zugriff nur mit Freigabe-Token.
//   datei.php?k=TOKEN&typ=bild|mittel|klein&tag=JJJJ-MM-TT&zeit=HHMMSS[&download=1]
//   datei.php?k=TOKEN&typ=video&name=gesamt|tag-JJJJ-MM-TT[&download=1]
declare(strict_types=1);
require __DIR__ . '/_init.php';

$treffer = zr_kamera_per_token((string) ($_GET['k'] ?? ''));
if ($treffer === null) {
    http_response_code(403);
    exit;
}
[$slug] = $treffer;
$basis = zr_kamera_pfad($slug);
$typ = (string) ($_GET['typ'] ?? '');
$download = ($_GET['download'] ?? '') === '1';

if (in_array($typ, ['bild', 'mittel', 'klein'], true)) {
    $tag = (string) ($_GET['tag'] ?? '');
    $zeit = (string) ($_GET['zeit'] ?? '');
    if (!zr_ist_tag($tag) || !zr_ist_zeit($zeit)) {
        http_response_code(400);
        exit;
    }
    $ordner = ['bild' => 'bilder', 'mittel' => 'mittel', 'klein' => 'klein'][$typ];
    $pfad = "$basis/$ordner/$tag/$zeit.jpg";
    if (!is_file($pfad)) {
        $pfad = "$basis/bilder/$tag/$zeit.jpg"; // Ersatz, falls Verkleinerung fehlt
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
    zr_ausliefern("$basis/videos/$name.mp4", 'video/mp4', 300);
}

http_response_code(400);
