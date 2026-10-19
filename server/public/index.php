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
        $_SESSION = ['kamera' => $z['kamera'], 'name' => $z['name'], 'hash' => $z['hash'],
                     'csrf' => bin2hex(random_bytes(16))];
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
$istAdmin = $zugang !== null && $token === '' && $zugang[2] === 'admin';
$csrf = '';
if ($istAdmin) {
    $csrf = (string) ($_SESSION['csrf'] ?? '');
    if ($csrf === '') { // Anmeldung aus aelterer Version
        zr_session(true);
        $_SESSION['csrf'] = $csrf = bin2hex(random_bytes(16));
        session_write_close();
    }
}

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
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= h($titel) ?></title>
<link rel="manifest" href="manifest.json">
<link rel="icon" type="image/png" href="favicon.png">
<link rel="apple-touch-icon" href="icon-180.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= h($design['app_name']) ?>">
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<meta name="theme-color" content="#232224">
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
<body class="<?= $einbetten ? 'einbetten' : '' ?>" data-token="<?= h($token) ?>"<?= $istAdmin ? ' data-csrf="' . h($csrf) . '"' : '' ?>>
<?php if (!$einbetten): ?>
<div class="leiste-oben">
  <div class="leiste-innen">
    <div class="marke">
      <?php if ($design['logo'] !== ''): ?>
        <img class="logo" src="<?= h($design['logo']) ?>" alt="<?= h($design['firma']) ?>">
      <?php endif; ?>
      <h1><?= h($titel) ?></h1>
    </div>
    <?php if ($token === ''): ?>
      <a class="abmelden" href="?abmelden=1">Abmelden</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<div class="seite">
  <header class="kopf">
    <div class="kopf-text">
      <?php if ($einbetten): ?><h1><?= h($titel) ?></h1><?php endif; ?>
      <p class="stand" id="stand">Lade …</p>
    </div>
    <nav class="reiter" role="tablist">
      <button role="tab" class="aktiv" data-reiter="bilder" aria-selected="true">Bilder</button>
      <button role="tab" data-reiter="videos" aria-selected="false">Videos</button>
      <button role="tab" data-reiter="doku" aria-selected="false" id="reiter-doku" hidden>Doku</button>
    </nav>
  </header>

  <section id="bilder" class="bereich">
    <div class="buehne">
      <img id="hauptbild" alt="">
      <div class="zeitmarke" id="zeitmarke"></div>
      <a class="original" id="original" target="_blank" rel="noopener" title="Bild in voller Größe öffnen">Vollbild</a>
      <div class="leer" id="leer" hidden>Noch keine Bilder vorhanden.</div>
      <div class="zonen-ebene" id="zonen-ebene" hidden></div>
    </div>

    <div class="admin-leiste" id="admin-leiste" hidden>
      <span class="admin-marke">admin</span>
      <label class="schalter"><input type="checkbox" id="original-zeigen"> Original anzeigen</label>
      <label class="schalter" title="Nur dieses Bild wird allen ohne Verpixelung gezeigt"><input type="checkbox" id="ausnahme"> Dieses Bild ohne Verpixelung</label>
      <button class="text-knopf" id="zonen-bearbeiten">Bereiche verpixeln</button>
    </div>
    <div class="zonen-leiste" id="zonen-leiste" hidden>
      <p class="zonen-hinweis">Freihand: Bereich mit gedrückter Maustaste bzw. dem Finger umfahren, die Linie schließt sich von selbst. Rechteck: aufziehen. Mit × entfernen. Gilt für alle Bilder dieser Kameraposition; verrutscht die Kamera, wandern die Bereiche automatisch mit. Am besten auf einem Bild ohne Bagger davor zeichnen.</p>
      <div class="zonen-aktionen">
        <div class="werkzeuge" role="radiogroup" aria-label="Werkzeug">
          <label><input type="radio" name="werkzeug" value="frei" checked> freihand</label>
          <label><input type="radio" name="werkzeug" value="rechteck"> rechteck</label>
        </div>
        <select id="zonen-art" aria-label="Art">
          <option value="pixel">verpixeln</option>
          <option value="unscharf">unscharf</option>
        </select>
        <button class="text-knopf" id="zonen-abbrechen">abbrechen</button>
        <button class="text-knopf zonen-neu" id="zonen-neu" title="Nur nach bewusstem Neuausrichten der Kamera">als neue kameraposition</button>
        <button class="voll-knopf" id="zonen-speichern">speichern</button>
      </div>
      <p class="zonen-info" id="zonen-info"></p>
      <p class="zonen-status" id="zonen-status" role="status"></p>
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

  <section id="doku" class="bereich" hidden>
    <div class="doku-kopf" id="doku-kopf" hidden>
      <button class="voll-knopf doku-plus" id="doku-hinzufuegen" type="button">+ hinzufügen</button>
      <input type="file" id="doku-datei" accept="image/*,video/*" multiple hidden>
      <p class="doku-hinweis">Foto aufnehmen, aus der Mediathek wählen oder eine Datei hochladen. Neue Einträge sehen zunächst nur Admins.</p>
    </div>
    <ul class="doku-uploads" id="doku-uploads"></ul>
    <div id="doku-liste"><p class="hinweis">Lade …</p></div>
  </section>

  <div class="doku-gross" id="doku-gross" hidden role="dialog" aria-modal="true" aria-label="Doku-Eintrag">
    <button class="dg-zu" id="dg-zu" type="button" aria-label="Schließen">×</button>
    <button class="dg-pfeil dg-zurueck" id="dg-zurueck" type="button" aria-label="Vorheriger Eintrag">‹</button>
    <button class="dg-pfeil dg-vor" id="dg-vor" type="button" aria-label="Nächster Eintrag">›</button>
    <div class="dg-medium" id="dg-medium"></div>
    <div class="dg-info">
      <p class="dg-titel" id="dg-titel"></p>
      <p class="dg-notiz" id="dg-notiz"></p>
      <div class="dg-admin" id="dg-admin" hidden>
        <label class="dg-feld">Notiz<textarea id="dg-notiz-feld" rows="2" maxlength="2000" placeholder="z. B. Fundament betoniert"></textarea></label>
        <label class="dg-feld dg-zeit">Aufnahmezeit<input type="datetime-local" id="dg-zeit" step="60"></label>
        <label class="schalter"><input type="checkbox" id="dg-frei"> für Bauherr sichtbar</label>
        <div class="dg-knoepfe">
          <a class="text-knopf" id="dg-download">herunterladen</a>
          <button class="text-knopf dg-loeschen" id="dg-loeschen" type="button">löschen</button>
          <button class="voll-knopf" id="dg-speichern" type="button">speichern</button>
        </div>
        <p class="zonen-status" id="dg-status" role="status"></p>
      </div>
    </div>
  </div>

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
<script src="doku.js?v=<?= h($v) ?>"></script>
<script src="pwa.js?v=<?= h($v) ?>"></script>
</body>
</html>
