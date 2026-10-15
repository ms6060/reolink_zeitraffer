<?php
// Bereiche zum Verpixeln verwalten. Nur fuer angemeldete Admins, mit CSRF-Schutz.
//   GET  zonen.php?tag=..&zeit=..         Bereiche in Koordinaten dieses Bildes
//   POST zonen.php?aktion=speichern       Body JSON {"art","zonen":[{"p":[[x,y],...]}],"tag","zeit","neu":bool}
//   POST zonen.php?aktion=neu&ab=N        bestehende Bilder neu verpixeln (stapelweise)
//   POST zonen.php?aktion=ausnahme        Body JSON {"tag","zeit","an":true|false}
declare(strict_types=1);
require __DIR__ . '/_init.php';

if (($_GET['k'] ?? '') !== '') {
    zr_fehler(403, 'Nur mit Anmeldung');
}
$zugang = zr_zugang();
if ($zugang === null || $zugang[2] !== 'admin') {
    zr_fehler(403, 'Nur fuer Admins');
}
[$slug] = $zugang;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Bereiche in Koordinaten des angezeigten Bildes (Kameraposition und Verschiebung beruecksichtigt)
    $tag = (string) ($_GET['tag'] ?? '');
    $zeit = (string) ($_GET['zeit'] ?? '');
    if (!zr_ist_tag($tag) || !zr_ist_zeit($zeit)) {
        zr_fehler(400, 'Bild fehlt');
    }
    $z = zr_zonen($slug);
    $fb = zr_zonen_fuer_bild($slug, $tag, $zeit, $z);
    $basis = null;
    foreach ($z['ansichten'] as $a) {
        if ($a['id'] === $fb['ansicht']) {
            $basis = $a['basis'];
        }
    }
    zr_json([
        'ok' => true,
        'art' => $z['art'],
        'zonen' => $fb['zonen'],
        'ansicht' => $fb['ansicht'],
        'referenz' => $basis,
        'anzahl_ansichten' => count($z['ansichten']),
        'lage' => $fb['lage']['q'] ?? null,
    ]);
}

zr_session();
$csrf = (string) ($_SESSION['csrf'] ?? '');
if ($csrf === '' || !hash_equals($csrf, (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
    zr_fehler(403, 'Sitzung abgelaufen, bitte Seite neu laden');
}

$aktion = (string) ($_GET['aktion'] ?? '');

if ($aktion === 'speichern') {
    $d = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($d)) {
        zr_fehler(400, 'Ungueltige Daten');
    }
    $tag = (string) ($d['tag'] ?? '');
    $zeit = (string) ($d['zeit'] ?? '');
    if (!zr_ist_tag($tag) || !zr_ist_zeit($zeit) || !is_file(zr_kamera_pfad($slug) . "/bilder/$tag/$zeit.jpg")) {
        zr_fehler(400, 'Bild unbekannt');
    }
    $neu = zr_zonen_speichern($slug, (string) ($d['art'] ?? 'pixel'), (array) ($d['zonen'] ?? []),
        $tag, $zeit, !empty($d['neu']));
    zr_json(['ok' => true, 'anzahl_bilder' => count(zr_alle_bilder($slug)),
             'version' => max($neu['version'], zr_lageversion($slug))]);
}

if ($aktion === 'ausnahme') {
    $d = json_decode((string) file_get_contents('php://input'), true);
    $tag = (string) ($d['tag'] ?? '');
    $zeit = (string) ($d['zeit'] ?? '');
    if (!zr_ist_tag($tag) || !zr_ist_zeit($zeit) || !is_file(zr_kamera_pfad($slug) . "/bilder/$tag/$zeit.jpg")) {
        zr_fehler(400, 'Bild unbekannt');
    }
    zr_json(['ok' => true, 'ausnahmen' => zr_ausnahme_setzen($slug, $tag, $zeit, !empty($d['an']))]);
}

if ($aktion === 'neu') {
    // Neueste Bilder zuerst, damit die aktuelle Ansicht schnell stimmt
    $alle = array_reverse(zr_alle_bilder($slug));
    $ab = max(0, (int) ($_GET['ab'] ?? 0));
    $zonen = zr_zonen($slug);
    $start = microtime(true);
    $i = $ab;
    @set_time_limit(60);
    if (zr_hat_zonen($zonen)) {
        while ($i < count($alle) && microtime(true) - $start < 15) {
            [$tag, $zeit] = $alle[$i];
            zr_bild_fuer_ansicht($slug, 'klein', $tag, $zeit); // erzeugt alle drei Fassungen, falls veraltet
            $i++;
        }
    } else {
        $i = count($alle);
    }
    zr_json(['ok' => true, 'fertig' => $i, 'gesamt' => count($alle)]);
}

zr_fehler(400, 'Unbekannte Aktion');
