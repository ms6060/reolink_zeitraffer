/* Zeitraffer-Galerie */
(function () {
  'use strict';

  var TOKEN = document.body.getAttribute('data-token');
  var CSRF = document.body.getAttribute('data-csrf');   // nur bei Admins gesetzt
  var $ = function (id) { return document.getElementById(id); };
  var WT = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

  var daten = null;          // Antwort von daten.php
  var tagListe = [];         // neueste zuerst
  var tag = null;            // aktueller Tag
  var sichtbar = [];         // Zeiten des Tages nach Filter
  var index = 0;
  var timer = null;
  var cache = {};
  var zeigeOriginal = false; // Admin: Originale statt verpixelter Fassung
  var bearbeiten = null;     // Admin: {art, zonen:[...]} waehrend des Einzeichnens

  function url(params) {
    var q = TOKEN ? ['k=' + encodeURIComponent(TOKEN)] : [];
    for (var k in params) q.push(k + '=' + encodeURIComponent(params[k]));
    return 'datei.php?' + q.join('&');
  }
  function bildUrl(typ, t, z) {
    var p = { typ: typ, tag: t, zeit: z };
    if (zeigeOriginal || bearbeiten) p.original = '1';
    else {
      if (daten && daten.zv) p.v = daten.zv;  // neue Bereiche = neue Adresse, kein alter Cache
      if (istAusnahme(t, z)) p.a = '1';        // Ausnahme = eigene Adresse
    }
    return url(p);
  }

  function tagText(t) {
    var p = t.split('-');
    var d = new Date(+p[0], +p[1] - 1, +p[2]);
    return WT[d.getDay()] + ' ' + p[2] + '.' + p[1] + '.' + p[0];
  }
  function zeitText(z) { return z.slice(0, 2) + ':' + z.slice(2, 4); }
  function stunde(z) { return parseInt(z.slice(0, 2), 10); }

  function laden(alleBild) {
    return fetch('daten.php' + (TOKEN ? '?k=' + encodeURIComponent(TOKEN) : ''), { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) {
        var warAmEnde = tag === tagListe[0] && index === sichtbar.length - 1 && !timer;
        daten = d;
        if (CSRF && d.rolle === 'admin') $('admin-leiste').hidden = false;
        tagListe = Object.keys(d.tage).sort().reverse();
        standAnzeigen();
        tageAuffuellen();
        if (!tagListe.length) { $('leer').hidden = false; return; }
        $('leer').hidden = true;
        if (alleBild || warAmEnde || tagListe.indexOf(tag) < 0) {
          tagWaehlen(tagListe[0], 'ende');
        } else {
          tagWaehlen(tag, 'behalten');
        }
        videosAnzeigen();
      })
      .catch(function () { $('stand').textContent = 'Daten konnten nicht geladen werden.'; });
  }

  function standAnzeigen() {
    if (!tagListe.length) { $('stand').textContent = 'Noch keine Bilder'; return; }
    var t = tagListe[0], z = daten.tage[t];
    $('stand').textContent = 'Letztes Bild: ' + tagText(t) + ', ' + zeitText(z[z.length - 1]) + ' Uhr';
  }

  function tageAuffuellen() {
    var sel = $('tag');
    sel.innerHTML = '';
    tagListe.forEach(function (t) {
      var o = document.createElement('option');
      o.value = t;
      o.textContent = tagText(t) + ' · ' + daten.tage[t].length + ' Bilder';
      sel.appendChild(o);
    });
  }

  function filtern() {
    var alle = daten.tage[tag] || [];
    if ($('nacht').checked) return alle.slice();
    return alle.filter(function (z) { var h = stunde(z); return h >= daten.tag_von && h < daten.tag_bis; });
  }

  // modus: 'ende' = letztes Bild, 'anfang' = erstes, 'behalten' = gleiche Zeit wie vorher
  function tagWaehlen(t, modus) {
    var vorher = sichtbar[index];
    tag = t;
    $('tag').value = t;
    sichtbar = filtern();
    if (modus === 'anfang') index = 0;
    else if (modus === 'behalten' && vorher) {
      var i = sichtbar.indexOf(vorher);
      index = i >= 0 ? i : Math.max(0, sichtbar.length - 1);
    } else index = Math.max(0, sichtbar.length - 1);
    var ti = tagListe.indexOf(t);
    $('tag-vor').disabled = ti <= 0;
    $('tag-zurueck').disabled = ti >= tagListe.length - 1;
    $('regler').max = Math.max(0, sichtbar.length - 1);
    leisteBauen();
    zeigen(index);
  }

  function leisteBauen() {
    var l = $('leiste');
    l.innerHTML = '';
    sichtbar.forEach(function (z, i) {
      var b = document.createElement('button');
      b.className = 'mini' + (CSRF && istAusnahme(tag, z) ? ' ausnahme' : '');
      b.title = zeitText(z) + ' Uhr';
      var img = document.createElement('img');
      img.loading = 'lazy';
      img.alt = zeitText(z);
      img.src = bildUrl('klein', tag, z);
      var s = document.createElement('span');
      s.textContent = zeitText(z);
      b.appendChild(img);
      b.appendChild(s);
      b.addEventListener('click', function () { anhalten(); zeigen(i); });
      l.appendChild(b);
    });
  }

  function vorladen(i) {
    var z = sichtbar[i];
    if (!z) return null;
    var u = bildUrl('mittel', tag, z);
    if (!cache[u]) { cache[u] = new Image(); cache[u].src = u; }
    return cache[u];
  }

  function zeigen(i) {
    if (!sichtbar.length) {
      $('hauptbild').removeAttribute('src');
      $('zeitmarke').textContent = 'Keine Bilder in dieser Auswahl';
      return;
    }
    index = Math.max(0, Math.min(i, sichtbar.length - 1));
    var z = sichtbar[index];
    $('hauptbild').src = bildUrl('mittel', tag, z);
    $('hauptbild').alt = tagText(tag) + ', ' + zeitText(z) + ' Uhr';
    $('zeitmarke').textContent = tagText(tag) + ', ' + zeitText(z) + ' Uhr';
    $('original').href = bildUrl('bild', tag, z);
    $('regler').value = index;
    var minis = $('leiste').children;
    for (var k = 0; k < minis.length; k++) minis[k].classList.toggle('aktiv', k === index);
    if (minis[index]) {
      var l = $('leiste'), m = minis[index];
      l.scrollLeft = m.offsetLeft - l.clientWidth / 2 + m.clientWidth / 2;
    }
    vorladen(index + 1); vorladen(index + 2);
    if (bearbeiten) ebeneAusrichten();
    if (CSRF) ausnahmeAnzeigen();
  }

  function abspielen() {
    if (timer) return anhalten();
    if (bearbeiten) return;
    if (index >= sichtbar.length - 1) zeigen(0);
    $('play').textContent = '❚❚';
    $('play').setAttribute('aria-label', 'Anhalten');
    var tick = function () {
      if (index >= sichtbar.length - 1) return anhalten();
      var naechstes = vorladen(index + 1);
      if (naechstes && !naechstes.complete) return; // warten bis geladen
      zeigen(index + 1);
    };
    timer = setInterval(tick, 1000 / parseInt($('tempo').value, 10));
  }
  function anhalten() {
    clearInterval(timer); timer = null;
    $('play').textContent = '▶';
    $('play').setAttribute('aria-label', 'Abspielen');
  }

  function videoName(n) {
    return n === 'gesamt' ? 'Gesamter Zeitraum' : 'Tag ' + tagText(n.slice(4));
  }
  function videosAnzeigen() {
    var liste = $('video-liste');
    var aktuell = $('video').getAttribute('data-name');
    liste.innerHTML = '';
    if (!daten.videos.length) {
      liste.innerHTML = '<p class="hinweis">Videos werden jeden Abend nach Ende der Tagaufnahmen erstellt. ' +
        'Nach einer Änderung der verpixelten Bereiche dauert es einige Minuten, bis sie neu erstellt sind.</p>';
      $('video').removeAttribute('src');
      $('video-titel').innerHTML = '';
      return;
    }
    daten.videos.forEach(function (v) {
      var b = document.createElement('button');
      b.className = 'video-eintrag';
      b.setAttribute('data-name', v.name);
      b.innerHTML = '<span class="v-name"></span><span class="v-info"></span>';
      b.querySelector('.v-name').textContent = videoName(v.name);
      b.querySelector('.v-info').textContent = (v.groesse / 1048576).toFixed(1) + ' MB';
      b.addEventListener('click', function () { videoWaehlen(v.name, true); });
      liste.appendChild(b);
    });
    if (!aktuell || !daten.videos.some(function (v) { return v.name === aktuell; })) {
      videoWaehlen(daten.videos[0].name, false);
    } else markieren(aktuell);
  }
  function markieren(name) {
    var e = $('video-liste').children;
    for (var i = 0; i < e.length; i++) e[i].classList.toggle('aktiv', e[i].getAttribute('data-name') === name);
  }
  function videoWaehlen(name, start) {
    var v = $('video');
    var stand = (daten.videos.filter(function (x) { return x.name === name; })[0] || {}).stand || '';
    v.setAttribute('data-name', name);
    v.src = url({ typ: 'video', name: name }) + '&s=' + encodeURIComponent(stand);
    var dl = url({ typ: 'video', name: name, download: '1' });
    $('video-titel').innerHTML = '<span></span> <a download>Herunterladen</a>';
    $('video-titel').querySelector('span').textContent = videoName(name);
    $('video-titel').querySelector('a').href = dl;
    markieren(name);
    if (start) v.play().catch(function () {});
  }

  // ------------------------------------------------------------------ Admin: Bereiche verpixeln

  function neuZeichnen() {
    // Hauptbild und Vorschauleiste mit der passenden Fassung neu laden
    cache = {};
    leisteBauen();
    zeigen(index);
  }

  // Ebene genau ueber den sichtbaren Bildinhalt legen (Bild ist per object-fit eingepasst)
  function ebeneAusrichten() {
    var img = $('hauptbild'), e = $('zonen-ebene');
    var bw = img.clientWidth, bh = img.clientHeight;
    var nw = img.naturalWidth || 16, nh = img.naturalHeight || 9;
    var f = Math.min(bw / nw, bh / nh);
    var w = nw * f, h = nh * f;
    e.style.left = (img.offsetLeft + (bw - w) / 2) + 'px';
    e.style.top = (img.offsetTop + (bh - h) / 2) + 'px';
    e.style.width = w + 'px';
    e.style.height = h + 'px';
  }

  var SVG = 'http://www.w3.org/2000/svg';

  function zonenZeichnen() {
    var e = $('zonen-ebene');
    e.innerHTML = '';
    var svg = document.createElementNS(SVG, 'svg');
    svg.setAttribute('viewBox', '0 0 1000 1000');
    svg.setAttribute('preserveAspectRatio', 'none');
    svg.setAttribute('class', 'zonen-svg');
    var muster = document.createElementNS(SVG, 'pattern');
    muster.setAttribute('id', 'schraffur');
    muster.setAttribute('patternUnits', 'userSpaceOnUse');
    muster.setAttribute('width', '16'); muster.setAttribute('height', '16');
    muster.setAttribute('patternTransform', 'rotate(45)');
    muster.innerHTML = '<rect width="8" height="16" fill="rgba(255,255,255,.35)"/><rect x="8" width="8" height="16" fill="rgba(0,0,0,.3)"/>';
    var defs = document.createElementNS(SVG, 'defs');
    defs.appendChild(muster);
    svg.appendChild(defs);
    bearbeiten.zonen.forEach(function (z) {
      var pg = document.createElementNS(SVG, 'polygon');
      pg.setAttribute('points', z.p.map(function (q) { return (q[0] * 1000) + ',' + (q[1] * 1000); }).join(' '));
      pg.setAttribute('class', 'zone-flaeche');
      svg.appendChild(pg);
    });
    var linie = document.createElementNS(SVG, 'polyline');
    linie.setAttribute('class', 'zone-linie');
    linie.setAttribute('id', 'zonen-linie');
    svg.appendChild(linie);
    e.appendChild(svg);
    bearbeiten.zonen.forEach(function (z, i) {
      // Loeschknopf an der rechten oberen Ecke des Bereichs
      var maxX = 0, minY = 1;
      z.p.forEach(function (q) { if (q[0] > maxX) maxX = q[0]; if (q[1] < minY) minY = q[1]; });
      var x = document.createElement('button');
      x.className = 'zone-x';
      x.type = 'button';
      x.textContent = '×';
      x.style.left = (Math.min(0.98, Math.max(0.02, maxX)) * 100) + '%';
      x.style.top = (Math.min(0.98, Math.max(0.03, minY)) * 100) + '%';
      x.setAttribute('aria-label', 'Bereich entfernen');
      x.addEventListener('pointerdown', function (ev) { ev.stopPropagation(); });
      x.addEventListener('click', function (ev) {
        ev.stopPropagation();
        bearbeiten.zonen.splice(i, 1);
        zonenZeichnen();
      });
      e.appendChild(x);
    });
    $('zonen-status').textContent = bearbeiten.zonen.length
      ? bearbeiten.zonen.length + (bearbeiten.zonen.length === 1 ? ' Bereich' : ' Bereiche')
      : 'Noch keine Bereiche.';
  }

  function bearbeitenStarten() {
    anhalten();
    var z0 = sichtbar[index];
    if (!z0) return;
    fetch('zonen.php?tag=' + encodeURIComponent(tag) + '&zeit=' + encodeURIComponent(z0), { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) {
        bearbeiten = { art: d.art, tag: tag, zeit: z0, zonen: d.zonen.map(function (z) { return { p: z.p.slice() }; }) };
        var info = '';
        if (d.ansicht && d.referenz) {
          var r = d.referenz.split('/');
          info = (d.anzahl_ansichten > 1 ? 'Kameraposition ' + d.ansicht + ', ' : '') +
            'Referenzbild ' + tagText(r[0]) + ', ' + zeitText(r[1]) + ' Uhr.';
          if (d.lage === 'ohne') info += ' Für dieses Bild ist die Lage noch nicht bestimmt.';
          else if (d.lage === 'alt') info += ' Lage unsicher (vom vorherigen Bild übernommen), bitte prüfen.';
        } else {
          info = 'Dieses Bild wird beim Speichern zum Referenzbild.';
        }
        $('zonen-info').textContent = info;
        $('zonen-art').value = d.art;
        $('admin-leiste').hidden = true;
        $('zonen-leiste').hidden = false;
        $('zonen-ebene').hidden = false;
        document.body.classList.add('bearbeiten');
        neuZeichnen();
        ebeneAusrichten();
        zonenZeichnen();
      })
      .catch(function () { alert('Bereiche konnten nicht geladen werden. Bitte Seite neu laden.'); });
  }

  function bearbeitenBeenden() {
    bearbeiten = null;
    $('zonen-ebene').hidden = true;
    $('zonen-ebene').innerHTML = '';
    $('zonen-leiste').hidden = true;
    $('admin-leiste').hidden = false;
    document.body.classList.remove('bearbeiten');
    neuZeichnen();
  }

  function post(adresse, body) {
    return fetch(adresse, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
      body: body ? JSON.stringify(body) : '{}'
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok || !j.ok) throw new Error(j.fehler || ('Fehler ' + r.status));
        return j;
      });
    });
  }

  function speichern(neu) {
    if (neu === true && !confirm('Dieses Bild als neue Kameraposition speichern? ' +
        'Nur nötig, wenn die Kamera bewusst neu ausgerichtet wurde. Ältere Bilder behalten ihre bisherigen Bereiche.')) return;
    var knopf = $('zonen-speichern');
    knopf.disabled = true;
    $('zonen-neu').disabled = true;
    $('zonen-status').textContent = 'Speichere …';
    post('zonen.php?aktion=speichern', {
      art: $('zonen-art').value, zonen: bearbeiten.zonen,
      tag: bearbeiten.tag, zeit: bearbeiten.zeit, neu: neu === true
    })
      .then(function (d) {
        daten.zv = d.version;
        var gesamt = d.anzahl_bilder;
        var schritt = function (ab) {
          $('zonen-status').textContent = 'Bilder werden verpixelt: ' + ab + ' von ' + gesamt +
            '. Du kannst die Seite auch schließen, der Rest wird beim ersten Ansehen verpixelt.';
          return post('zonen.php?aktion=neu&ab=' + ab).then(function (r) {
            if (r.fertig < r.gesamt) return schritt(r.fertig);
          });
        };
        return schritt(0);
      })
      .then(function () {
        knopf.disabled = false;
        $('zonen-neu').disabled = false;
        bearbeitenBeenden();
        zeigeOriginal = false;
        $('original-zeigen').checked = false;
        neuZeichnen();
        $('zonen-status').textContent = '';
      })
      .catch(function (err) {
        knopf.disabled = false;
        $('zonen-neu').disabled = false;
        $('zonen-status').textContent = 'Nicht gespeichert: ' + err.message;
      });
  }

  // Bereich zeichnen (Maus, Stift, Finger): Freihand-Linie oder Rechteck
  function werkzeug() {
    var w = document.querySelector('input[name=werkzeug]:checked');
    return w ? w.value : 'frei';
  }
  (function () {
    var e = $('zonen-ebene'), punkte = null;
    function pos(ev) {
      var r = e.getBoundingClientRect();
      return [
        Math.max(0, Math.min(1, (ev.clientX - r.left) / r.width)),
        Math.max(0, Math.min(1, (ev.clientY - r.top) / r.height))
      ];
    }
    function rechteck(a, b) {
      var x1 = Math.min(a[0], b[0]), x2 = Math.max(a[0], b[0]);
      var y1 = Math.min(a[1], b[1]), y2 = Math.max(a[1], b[1]);
      return [[x1, y1], [x2, y1], [x2, y2], [x1, y2]];
    }
    function vorschau(liste, schliessen) {
      var l = $('zonen-linie');
      if (!l) return;
      var pts = liste.concat(schliessen && liste.length > 2 ? [liste[0]] : []);
      l.setAttribute('points', pts.map(function (q) { return (q[0] * 1000) + ',' + (q[1] * 1000); }).join(' '));
    }
    e.addEventListener('pointerdown', function (ev) {
      if (!bearbeiten || ev.button > 0) return;
      punkte = [pos(ev)];
      e.setPointerCapture(ev.pointerId);
      ev.preventDefault();
    });
    e.addEventListener('pointermove', function (ev) {
      if (!punkte) return;
      var p = pos(ev);
      if (werkzeug() === 'rechteck') {
        punkte = [punkte[0], p];
        vorschau(rechteck(punkte[0], p), true);
      } else {
        var l = punkte[punkte.length - 1];
        if (Math.abs(p[0] - l[0]) + Math.abs(p[1] - l[1]) > 0.004) punkte.push(p); // Linie ausduennen
        vorschau(punkte, false);
      }
    });
    function ende() {
      if (!punkte) return;
      var liste = werkzeug() === 'rechteck'
        ? (punkte.length > 1 ? rechteck(punkte[0], punkte[1]) : [])
        : punkte;
      punkte = null;
      if (liste.length >= 3) {
        var xs = liste.map(function (q) { return q[0]; }), ys = liste.map(function (q) { return q[1]; });
        if (Math.max.apply(null, xs) - Math.min.apply(null, xs) > 0.015 &&
            Math.max.apply(null, ys) - Math.min.apply(null, ys) > 0.015) {
          bearbeiten.zonen.push({ p: liste.slice(0, 400) });
        }
      }
      zonenZeichnen();
    }
    e.addEventListener('pointerup', ende);
    e.addEventListener('pointercancel', function () { punkte = null; zonenZeichnen(); });
  })();

  // ------------------------------------------------------------------ Admin: Ausnahme pro Bild

  function istAusnahme(t, z) { return !!(daten && daten.ausnahmen && daten.ausnahmen.indexOf(t + '/' + z) >= 0); }

  function ausnahmeAnzeigen() {
    var z = sichtbar[index];
    var k = $('ausnahme');
    if (!k) return;
    k.checked = !!z && istAusnahme(tag, z);
    k.disabled = !z;
  }

  if (CSRF) {
    $('original-zeigen').addEventListener('change', function () { zeigeOriginal = this.checked; neuZeichnen(); });
    $('zonen-bearbeiten').addEventListener('click', bearbeitenStarten);
    $('zonen-abbrechen').addEventListener('click', bearbeitenBeenden);
    $('zonen-speichern').addEventListener('click', function () { speichern(false); });
    $('zonen-neu').addEventListener('click', function () { speichern(true); });
    $('ausnahme').addEventListener('change', function () {
      var k = this, z = sichtbar[index], an = k.checked;
      k.disabled = true;
      post('zonen.php?aktion=ausnahme', { tag: tag, zeit: z, an: an })
        .then(function (d) { daten.ausnahmen = d.ausnahmen; neuZeichnen(); })
        .catch(function (err) { k.checked = !an; alert('Nicht gespeichert: ' + err.message); })
        .then(function () { k.disabled = false; });
    });
    $('hauptbild').addEventListener('load', function () { if (bearbeiten) ebeneAusrichten(); });
    window.addEventListener('resize', function () { if (bearbeiten) ebeneAusrichten(); });
  }

  // Ereignisse
  document.querySelectorAll('[data-reiter]').forEach(function (b) {
    b.addEventListener('click', function () {
      var ziel = b.getAttribute('data-reiter');
      document.querySelectorAll('[data-reiter]').forEach(function (x) {
        var an = x === b;
        x.classList.toggle('aktiv', an);
        x.setAttribute('aria-selected', an ? 'true' : 'false');
      });
      $('bilder').hidden = ziel !== 'bilder';
      $('videos').hidden = ziel !== 'videos';
      if (ziel !== 'bilder') anhalten(); else $('video').pause();
    });
  });
  $('tag').addEventListener('change', function () { anhalten(); tagWaehlen(this.value, 'anfang'); });
  $('tag-zurueck').addEventListener('click', function () {
    var i = tagListe.indexOf(tag); if (i < tagListe.length - 1) { anhalten(); tagWaehlen(tagListe[i + 1], 'anfang'); }
  });
  $('tag-vor').addEventListener('click', function () {
    var i = tagListe.indexOf(tag); if (i > 0) { anhalten(); tagWaehlen(tagListe[i - 1], 'anfang'); }
  });
  $('regler').addEventListener('input', function () { anhalten(); zeigen(parseInt(this.value, 10)); });
  $('play').addEventListener('click', abspielen);
  $('tempo').addEventListener('change', function () { if (timer) { anhalten(); abspielen(); } });
  $('nacht').addEventListener('change', function () { anhalten(); tagWaehlen(tag, 'behalten'); });
  document.addEventListener('keydown', function (e) {
    if (bearbeiten || $('bilder').hidden || /INPUT|SELECT|TEXTAREA/.test(e.target.tagName) && e.target.type !== 'range') return;
    if (e.key === 'ArrowLeft') { anhalten(); zeigen(index - 1); e.preventDefault(); }
    else if (e.key === 'ArrowRight') { anhalten(); zeigen(index + 1); e.preventDefault(); }
    else if (e.key === ' ') { abspielen(); e.preventDefault(); }
  });

  laden(true);
  setInterval(function () { if (!document.hidden) laden(false); }, 5 * 60 * 1000);
})();
