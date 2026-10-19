/* App-Verhalten am Homescreen: Runterziehen zum Aktualisieren, Nachladen beim Zurueckkehren */
(function () {
  'use strict';

  var SCHWELLE = 80;   // so weit ziehen (px), bis aktualisiert wird

  // Zuletzt gewaehlten Reiter nach dem Neuladen wieder oeffnen
  function merken(r) { try { sessionStorage.setItem('zr-reiter', r); } catch (e) {} }
  document.addEventListener('reiter', function (ev) { merken(ev.detail); });
  try {
    var r = sessionStorage.getItem('zr-reiter');
    if (r && r !== 'bilder') {
      var warten = setInterval(function () {      // Doku-Reiter erscheint erst nach dem Laden
        var b = document.querySelector('[data-reiter="' + r + '"]');
        if (b && !b.hidden) { clearInterval(warten); b.click(); }
      }, 150);
      setTimeout(function () { clearInterval(warten); }, 5000);
    }
  } catch (e) {}

  // Anzeige oben
  var anz = document.createElement('div');
  anz.className = 'ptr';
  anz.setAttribute('aria-hidden', 'true');
  anz.innerHTML = '<span class="ptr-kreis">↓</span>';
  document.body.appendChild(anz);

  var start = null, dy = 0, aktiv = false;

  function gesperrt(ziel) {
    // nicht beim Zeichnen von Bereichen, in der Grossansicht, auf Reglern und Leisten
    return document.body.classList.contains('bearbeiten') ||
      document.body.classList.contains('dg-offen') ||
      (ziel.closest && ziel.closest('input, textarea, select, video, .leiste, .zonen-ebene'));
  }

  document.addEventListener('touchstart', function (ev) {
    if (ev.touches.length !== 1 || window.scrollY > 0 || gesperrt(ev.target)) { start = null; return; }
    start = { x: ev.touches[0].clientX, y: ev.touches[0].clientY };
    dy = 0;
    aktiv = false;
  }, { passive: true });

  document.addEventListener('touchmove', function (ev) {
    if (!start) return;
    var x = ev.touches[0].clientX - start.x;
    dy = ev.touches[0].clientY - start.y;
    if (!aktiv && (dy < 10 || Math.abs(x) > dy)) {   // seitlich oder nach oben: kein Aktualisieren
      if (Math.abs(x) > 10 || dy < -10) start = null;
      return;
    }
    if (window.scrollY > 0) { start = null; anz.classList.remove('sichtbar'); return; }
    aktiv = true;
    var weg = Math.min(dy * 0.5, SCHWELLE * 1.2);
    anz.classList.add('sichtbar');
    anz.style.transform = 'translate(-50%, ' + weg + 'px)';
    anz.classList.toggle('bereit', dy * 0.5 >= SCHWELLE * 0.75);
  }, { passive: true });

  document.addEventListener('touchend', function () {
    if (!start || !aktiv) { start = null; return; }
    start = null;
    if (anz.classList.contains('bereit')) {
      anz.classList.add('laedt');
      anz.style.transform = 'translate(-50%, ' + (SCHWELLE * 0.6) + 'px)';
      setTimeout(function () { location.reload(); }, 150);
    } else {
      anz.classList.remove('sichtbar', 'bereit');
      anz.style.transform = '';
    }
  });

  // Nach einer Weile im Hintergrund (App gewechselt, Handy gesperrt): neu laden
  var weg = 0;
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { weg = Date.now(); return; }
    if (weg && Date.now() - weg > 2 * 60 * 1000 &&
        !document.body.classList.contains('bearbeiten') && !document.querySelector('.du-zeile:not(.fertig):not(.fehler)')) {
      location.reload();
    }
  });
})();
