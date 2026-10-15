<?php
// Liefert die Bild- und Videoliste einer Kamera als JSON. Zugriff nur mit Freigabe-Token.
declare(strict_types=1);
require __DIR__ . '/_init.php';

$zugang = zr_zugang();
if ($zugang === null) {
    zr_fehler(403, 'Kein Zugriff');
}
[$slug, $kamera, $rolle] = $zugang;
$basis = zr_kamera_pfad($slug);

$tage = [];
foreach (glob("$basis/bilder/*", GLOB_ONLYDIR) ?: [] as $ordner) {
    $tag = basename($ordner);
    if (!zr_ist_tag($tag)) {
        continue;
    }
    $zeiten = [];
    foreach (glob("$ordner/*.jpg") ?: [] as $datei) {
        $z = basename($datei, '.jpg');
        if (zr_ist_zeit($z)) {
            $zeiten[] = $z;
        }
    }
    if ($zeiten) {
        sort($zeiten);
        $tage[$tag] = $zeiten;
    }
}
krsort($tage);

$zv = zr_zonen($slug)['version'];
$videos = [];
foreach (glob("$basis/videos/*.mp4") ?: [] as $datei) {
    $name = basename($datei, '.mp4');
    // Videos, die vor der letzten Aenderung der Bereiche erstellt wurden, sind noch nicht
    // (richtig) verpixelt. Sie bleiben ausgeblendet, bis der Abholer die neue Fassung liefert.
    if (zr_ist_video($name) && filemtime($datei) > $zv) {
        $videos[] = ['name' => $name, 'groesse' => filesize($datei), 'stand' => date('c', filemtime($datei))];
    }
}
usort($videos, function ($a, $b) {
    if ($a['name'] === 'gesamt') return -1;
    if ($b['name'] === 'gesamt') return 1;
    return strcmp($b['name'], $a['name']);
});

zr_json([
    'ok' => true,
    'titel' => (string) ($kamera['titel'] ?? $slug),
    'rolle' => $rolle,
    'zv' => $zv,
    'ausnahmen' => zr_ausnahmen($slug),
    'tage' => $tage,
    'videos' => $videos,
    'tag_von' => (int) ($kamera['tag_von'] ?? zr_cfg('tag_von', 6)),
    'tag_bis' => (int) ($kamera['tag_bis'] ?? zr_cfg('tag_bis', 20)),
]);
