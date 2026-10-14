<?php
// Galerie. Aufruf: index.php?k=TOKEN   (Einbettung: index.php?k=TOKEN&einbetten=1)
declare(strict_types=1);
require __DIR__ . '/_init.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$token = (string) ($_GET['k'] ?? '');
$treffer = zr_kamera_per_token($token);
if ($treffer === null) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>Kein Zugriff</title>'
       . '<p style="font-family:sans-serif;padding:2rem">Dieser Link ist ungültig oder abgelaufen.</p>';
    exit;
}
[$slug, $kamera] = $treffer;

$erlaubt = $kamera['einbetten_erlaubt'] ?? [];
if ($erlaubt) {
    $quellen = implode(' ', array_map(fn($u) => preg_replace('/[^a-zA-Z0-9:\/._*-]/', '', (string) $u), $erlaubt));
    header("Content-Security-Policy: frame-ancestors 'self' $quellen");
}

$einbetten = ($_GET['einbetten'] ?? '') === '1';
$titel = (string) ($kamera['titel'] ?? $slug);
$fuss = (string) zr_cfg('fusszeile', '');
$v = ZR_VERSION;
?><!doctype html>
<html lang="de-AT">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($titel) ?></title>
<link rel="stylesheet" href="app.css?v=<?= h($v) ?>">
</head>
<body class="<?= $einbetten ? 'einbetten' : '' ?>" data-token="<?= h($token) ?>">
<div class="seite">
  <header class="kopf">
    <div class="kopf-text">
      <h1><?= h($titel) ?></h1>
      <p class="stand" id="stand">Lade …</p>
    </div>
    <nav class="reiter" role="tablist">
      <button role="tab" class="aktiv" data-reiter="bilder" aria-selected="true">Bilder</button>
      <button role="tab" data-reiter="videos" aria-selected="false">Videos</button>
    </nav>
  </header>

  <section id="bilder" class="bereich">
    <div class="buehne">
      <img id="hauptbild" alt="">
      <div class="zeitmarke" id="zeitmarke"></div>
      <a class="original" id="original" target="_blank" rel="noopener" title="Originalbild öffnen">Original</a>
      <div class="leer" id="leer" hidden>Noch keine Bilder vorhanden.</div>
    </div>

    <div class="steuerung">
      <div class="tagwahl">
        <button class="knopf" id="tag-zurueck" title="Vorheriger Tag" aria-label="Vorheriger Tag">‹</button>
        <select id="tag" aria-label="Tag"></select>
        <button class="knopf" id="tag-vor" title="Nächster Tag" aria-label="Nächster Tag">›</button>
      </div>
      <div class="abspielen">
        <button class="knopf haupt" id="play" aria-label="Abspielen">▶</button>
        <select id="tempo" aria-label="Tempo">
          <option value="3">langsam</option>
          <option value="6" selected>normal</option>
          <option value="12">schnell</option>
        </select>
        <label class="schalter"><input type="checkbox" id="nacht" checked> Nachtbilder</label>
      </div>
    </div>

    <input type="range" id="regler" min="0" max="0" value="0" aria-label="Zeitpunkt">
    <div class="leiste" id="leiste"></div>
  </section>

  <section id="videos" class="bereich" hidden>
    <div class="video-haupt">
      <video id="video" controls playsinline preload="metadata"></video>
      <div class="video-titel" id="video-titel"></div>
    </div>
    <div class="video-liste" id="video-liste"></div>
  </section>

  <?php if ($fuss !== '' && !$einbetten): ?>
  <footer class="fuss"><?= h($fuss) ?></footer>
  <?php endif; ?>
</div>
<script src="app.js?v=<?= h($v) ?>"></script>
</body>
</html>
