<?php
// Galerie mit Anmeldung.
//   /                         Anmeldung per Passwort, danach Galerie
//   /?k=TOKEN&einbetten=1     Galerie ohne Anmeldung (fuer iframe auf der Homepage)
//   /?abmelden=1              Abmelden
declare(strict_types=1);
require __DIR__ . '/_init.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

// Abmelden
if (isset($_GET['abmelden'])) {
    zr_session(true);
    $_SESSION = [];
    session_destroy();
    setcookie('zeitraffer', '', ['expires' => time() - 3600, 'path' => '/']);
    header('Location: ./');
    exit;
}

// Anmelden
$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['passwort'])) {
    $z = zr_anmelden((string) $_POST['passwort']);
    if ($z !== null) {
        zr_session(true);
        session_regenerate_id(true);
        $_SESSION = ['kamera' => $z['kamera'], 'name' => $z['name'], 'hash' => $z['hash']];
        session_write_close();
        header('Location: ./');
        exit;
    }
    sleep(2); // bremst Durchprobieren
    $fehler = 'Das Passwort ist nicht richtig.';
}

$token = (string) ($_GET['k'] ?? '');
$zugang = zr_zugang();
$kameras = zr_kameras();
$kamera = $zugang[1] ?? (count($kameras) === 1 ? reset($kameras) : []);
$design = zr_design($kamera);
$titel = (string) ($kamera['titel'] ?? 'Baustellenkamera');
$einbetten = $zugang !== null && $token !== '' && ($_GET['einbetten'] ?? '') === '1';

if ($zugang !== null && $token !== '') {
    $erlaubt = $kamera['einbetten_erlaubt'] ?? [];
    if ($erlaubt) {
        $quellen = implode(' ', array_map(fn($u) => preg_replace('/[^a-zA-Z0-9:\/._*-]/', '', (string) $u), $erlaubt));
        header("Content-Security-Policy: frame-ancestors 'self' $quellen");
    }
} else {
    header("Content-Security-Policy: frame-ancestors 'self'");
}

function farbe(string $f, string $ersatz): string
{
    return preg_match('/^#[0-9a-fA-F]{3,8}$/', $f) ? $f : $ersatz;
}

$v = ZR_VERSION;
?><!doctype html>
<html lang="de-AT">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($titel) ?></title>
<?php if ($design['schrift_css'] !== ''): ?>
<link rel="stylesheet" href="<?= h($design['schrift_css']) ?>">
<?php endif; ?>
<link rel="stylesheet" href="app.css?v=<?= h($v) ?>">
<style>
:root {
  --akzent: <?= farbe($design['akzent'], '#F0A720') ?>;
  --akzent-text: <?= farbe($design['akzent_text'], '#1a1406') ?>;
  --dunkel: <?= farbe($design['dunkel'], '#1f2328') ?>;
<?php if ($design['schrift'] !== ''): ?>
  --schrift: <?= preg_replace('/[^a-zA-Z0-9 ,"\'-]/', '', $design['schrift']) ?>;
<?php endif; ?>
}
</style>
</head>
<?php if ($zugang === null): ?>
<body class="anmeldung">
<main class="anmelde-karte">
  <?php if ($design['logo'] !== ''): ?>
    <img class="anmelde-logo" src="<?= h($design['logo']) ?>" alt="<?= h($design['firma']) ?>">
  <?php endif; ?>
  <h1><?= h($titel) ?></h1>
  <?php if ($token !== ''): ?>
    <p class="hinweis-fehler">Dieser Link ist ungültig oder abgelaufen.</p>
  <?php else: ?>
    <p class="anmelde-text">Bitte Passwort eingeben.</p>
    <form method="post" action="./">
      <input type="password" name="passwort" autocomplete="current-password" placeholder="Passwort" required autofocus aria-label="Passwort">
      <?php if ($fehler !== ''): ?><p class="hinweis-fehler"><?= h($fehler) ?></p><?php endif; ?>
      <button type="submit">Anmelden</button>
    </form>
  <?php endif; ?>
</main>
<?php if ($design['fusszeile'] !== ''): ?><footer class="fuss"><?= h($design['fusszeile']) ?></footer><?php endif; ?>
</body>
</html>
<?php exit; endif; ?>
<body class="<?= $einbetten ? 'einbetten' : '' ?>" data-token="<?= h($token) ?>">
<?php if (!$einbetten): ?>
<div class="leiste-oben">
  <div class="leiste-innen">
    <?php if ($design['logo'] !== ''): ?>
      <img class="logo" src="<?= h($design['logo']) ?>" alt="<?= h($design['firma']) ?>">
    <?php else: ?>
      <span class="firma"><?= h($design['firma']) ?></span>
    <?php endif; ?>
    <?php if ($token === ''): ?>
      <a class="abmelden" href="?abmelden=1">Abmelden</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
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

  <?php if ($design['fusszeile'] !== '' && !$einbetten): ?>
  <footer class="fuss"><?= h($design['fusszeile']) ?></footer>
  <?php endif; ?>
</div>
<script src="app.js?v=<?= h($v) ?>"></script>
</body>
</html>
