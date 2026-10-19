/* Foto- und Videodoku: Hinzufuegen (Admins), Zeitleiste, Grossansicht */
(function () {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };
  var TOKEN = document.body.getAttribute('data-token') || '';
  var CSRF = document.body.getAttribute('data-csrf');
  var WT = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
  var STUECK = 2 * 1024 * 1024;        // Upload in 2-MB-Teilen
  var FOTO_MAX = 2560;                  // laengste Seite nach dem Verkleinern

  var eintraege = [];
  var istAdmin = false;
  var offen = -1;                       // Index in der Grossansicht

  // ---------------------------------------------------------------- Hilfen

  function adresse(params) {
    var q = TOKEN ? ['k=' + encodeURIComponent(TOKEN)] : [];
    for (var k in params) q.push(k + '=' + encodeURIComponent(params[k]));
    return 'doku.php' + (q.length ? '?' + q.join('&') : '');
  }
  function zwei(n) { return (n < 10 ? '0' : '') + n; }
  function lokalIso(d) {
    return d.getFullYear() + '-' + zwei(d.getMonth() + 1) + '-' + zwei(d.getDate()) + 'T' +
      zwei(d.getHours()) + ':' + zwei(d.getMinutes()) + ':' + zwei(d.getSeconds());
  }
  function tagText(iso) {
    var d = new Date(+iso.slice(0, 4), +iso.slice(5, 7) - 1, +iso.slice(8, 10));
    return WT[d.getDay()] + ' ' + iso.slice(8, 10) + '.' + iso.slice(5, 7) + '.' + iso.slice(0, 4);
  }
  function uhr(iso) { return iso.slice(11, 16); }
  function dauerText(s) {
    s = Math.round(s || 0);
    return s ? Math.floor(s / 60) + ':' + zwei(s % 60) : '';
  }
  function mb(b) { return (b / 1048576).toFixed(b < 10485760 ? 1 : 0) + ' MB'; }

  function post(aktion, params, body, typ) {
    params = params || {};
    params.aktion = aktion;
    return fetch(adresse(params), {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': typ || 'application/json', 'X-CSRF': CSRF || '' },
      body: body
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        j._status = r.status;
        if (!r.ok && r.status !== 409) throw new Error(j.fehler || ('Fehler ' + r.status));
        return j;
      });
    });
  }

  // ---------------------------------------------------------------- Liste

  function laden() {
    return fetch(adresse({}), { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) {
        eintraege = d.eintraege || [];
        istAdmin = !!d.admin && !!CSRF;
        $('reiter-doku').hidden = !istAdmin && !eintraege.length;
        $('doku-kopf').hidden = !istAdmin;
        zeichnen();
      })
      .catch(function () { $('doku-liste').innerHTML = '<p class="hinweis">Doku konnte nicht geladen werden.</p>'; });
  }

  function zeichnen() {
    var l = $('doku-liste');
    l.innerHTML = '';
    if (!eintraege.length) {
      l.innerHTML = '<p class="hinweis">Noch keine Fotos oder Videos.</p>';
      return;
    }
    var tag = null, gitter = null;
    eintraege.forEach(function (e, i) {
      var t = e.zeit.slice(0, 10);
      if (t !== tag) {
        tag = t;
        var h = document.createElement('h2');
        h.className = 'doku-tag';
        h.textContent = tagText(t);
        l.appendChild(h);
        gitter = document.createElement('div');
        gitter.className = 'doku-gitter';
        l.appendChild(gitter);
      }
      var k = document.createElement('button');
      k.type = 'button';
      k.className = 'doku-karte' + (e.typ === 'video' ? ' ist-video' : '') + (istAdmin && !e.frei ? ' privat' : '');
      var bild = document.createElement('div');
      bild.className = 'dk-bild';
      if (e.typ === 'foto' || e.vorschau) {
        var img = document.createElement('img');
        img.loading = 'lazy';
        img.alt = (e.typ === 'video' ? 'Video ' : 'Foto ') + uhr(e.zeit);
        img.src = adresse({ aktion: 'datei', id: e.id, g: 'klein' });
        bild.appendChild(img);
      }
      if (e.typ === 'video') {
        var v = document.createElement('span');
        v.className = 'dk-video';
        v.textContent = '▶ ' + dauerText(e.dauer);
        bild.appendChild(v);
      }
      if (istAdmin) {
        var f = document.createElement('span');
        f.className = 'dk-frei';
        f.textContent = e.frei ? 'sichtbar' : 'nur admin';
        bild.appendChild(f);
      }
      k.appendChild(bild);
      var unter = document.createElement('div');
      unter.className = 'dk-text';
      var z = document.createElement('span');
      z.className = 'dk-zeit';
      z.textContent = uhr(e.zeit) + ' Uhr' + (istAdmin && e.von ? ' · ' + e.von : '');
      unter.appendChild(z);
      if (e.notiz) {
        var n = document.createElement('span');
        n.className = 'dk-notiz';
        n.textContent = e.notiz;
        unter.appendChild(n);
      }
      k.appendChild(unter);
      k.addEventListener('click', function () { oeffnen(i); });
      gitter.appendChild(k);
    });
  }

  // ---------------------------------------------------------------- Grossansicht

  function oeffnen(i) {
    if (i < 0 || i >= eintraege.length) return;
    offen = i;
    var e = eintraege[i];
    var m = $('dg-medium');
    m.innerHTML = '';
    if (e.typ === 'video') {
      var v = document.createElement('video');
      v.controls = true;
      v.playsInline = true;
      v.preload = 'metadata';
      if (e.vorschau) v.poster = adresse({ aktion: 'datei', id: e.id, g: 'mittel' });
      v.src = adresse({ aktion: 'datei', id: e.id, g: 'voll' });
      m.appendChild(v);
    } else {
      var img = document.createElement('img');
      img.alt = 'Foto ' + tagText(e.zeit) + ', ' + uhr(e.zeit);
      img.src = adresse({ aktion: 'datei', id: e.id, g: 'mittel' });
      m.appendChild(img);
    }
    $('dg-titel').textContent = tagText(e.zeit) + ', ' + uhr(e.zeit) + ' Uhr' + (istAdmin && e.von ? ' · ' + e.von : '');
    $('dg-notiz').textContent = e.notiz || '';
    $('dg-notiz').hidden = istAdmin || !e.notiz;
    $('dg-admin').hidden = !istAdmin;
    if (istAdmin) {
      $('dg-notiz-feld').value = e.notiz || '';
      $('dg-zeit').value = e.zeit.slice(0, 16);
      $('dg-frei').checked = !!e.frei;
      $('dg-download').href = adresse({ aktion: 'datei', id: e.id, g: 'voll', download: '1' });
      $('dg-status').textContent = '';
    }
    $('dg-zurueck').disabled = i <= 0;
    $('dg-vor').disabled = i >= eintraege.length - 1;
    $('doku-gross').hidden = false;
    document.body.classList.add('dg-offen');
  }

  function schliessen() {
    var v = $('dg-medium').querySelector('video');
    if (v) v.pause();
    $('dg-medium').innerHTML = '';
    $('doku-gross').hidden = true;
    document.body.classList.remove('dg-offen');
    offen = -1;
  }

  $('dg-zu').addEventListener('click', schliessen);
  $('dg-zurueck').addEventListener('click', function () { oeffnen(offen - 1); });
  $('dg-vor').addEventListener('click', function () { oeffnen(offen + 1); });
  $('doku-gross').addEventListener('click', function (ev) { if (ev.target === this) schliessen(); });
  document.addEventListener('keydown', function (ev) {
    if (offen < 0 || /INPUT|TEXTAREA/.test(ev.target.tagName)) return;
    if (ev.key === 'Escape') schliessen();
    else if (ev.key === 'ArrowLeft') oeffnen(offen - 1);
    else if (ev.key === 'ArrowRight') oeffnen(offen + 1);
  });
  // Wischen am Handy
  (function () {
    var x0 = null;
    $('dg-medium').addEventListener('touchstart', function (ev) { x0 = ev.touches[0].clientX; }, { passive: true });
    $('dg-medium').addEventListener('touchend', function (ev) {
      if (x0 === null) return;
      var dx = ev.changedTouches[0].clientX - x0;
      x0 = null;
      if (Math.abs(dx) > 60) oeffnen(offen + (dx < 0 ? 1 : -1));
    });
  })();

  $('dg-speichern').addEventListener('click', function () {
    var e = eintraege[offen];
    if (!e) return;
    var knopf = this;
    knopf.disabled = true;
    $('dg-status').textContent = 'Speichere …';
    post('aendern', {}, JSON.stringify({
      id: e.id, notiz: $('dg-notiz-feld').value, frei: $('dg-frei').checked,
      zeit: $('dg-zeit').value ? $('dg-zeit').value + ':00' : ''
    })).then(function (d) {
      var id = d.eintrag.id;
      return laden().then(function () {
        var i = eintraege.map(function (x) { return x.id; }).indexOf(id);
        oeffnen(i);
        $('dg-status').textContent = 'Gespeichert.';
      });
    }).catch(function (err) {
      $('dg-status').textContent = 'Nicht gespeichert: ' + err.message;
    }).then(function () { knopf.disabled = false; });
  });

  $('dg-loeschen').addEventListener('click', function () {
    var e = eintraege[offen];
    if (!e || !confirm('Diesen Eintrag endgültig löschen?')) return;
    post('loeschen', {}, JSON.stringify({ id: e.id }))
      .then(function () { schliessen(); return laden(); })
      .catch(function (err) { $('dg-status').textContent = 'Nicht gelöscht: ' + err.message; });
  });

  // ---------------------------------------------------------------- Hinzufuegen

  // Aufnahmedatum aus den EXIF-Daten eines JPEG (DateTimeOriginal), sonst null
  function exifDatum(file) {
    return file.slice(0, 256 * 1024).arrayBuffer().then(function (buf) {
      var v = new DataView(buf);
      if (v.byteLength < 4 || v.getUint16(0) !== 0xFFD8) return null;
      var p = 2;
      while (p + 4 < v.byteLength) {
        var marke = v.getUint16(p), laenge = v.getUint16(p + 2);
        if (marke === 0xFFE1 && v.getUint32(p + 4) === 0x45786966) { // "Exif"
          var t = p + 10, le = v.getUint16(t) === 0x4949;
          var u16 = function (o) { return v.getUint16(t + o, le); };
          var u32 = function (o) { return v.getUint32(t + o, le); };
          var suche = function (ifd, tagNr) {
            var n = u16(ifd);
            for (var i = 0; i < n; i++) {
              var e = ifd + 2 + i * 12;
              if (u16(e) === tagNr) return e;
            }
            return null;
          };
          var ifd0 = u32(4);
          var exifZeiger = suche(ifd0, 0x8769);
          var ziel = exifZeiger !== null ? suche(u32(exifZeiger + 8), 0x9003) : null;
          if (ziel === null) ziel = suche(ifd0, 0x0132);
          if (ziel === null) return null;
          var off = u32(ziel + 8), s = '';
          for (var j = 0; j < 19; j++) s += String.fromCharCode(v.getUint8(t + off + j));
          var m = /^(\d{4}):(\d{2}):(\d{2}) (\d{2}):(\d{2}):(\d{2})$/.exec(s);
          return m ? m[1] + '-' + m[2] + '-' + m[3] + 'T' + m[4] + ':' + m[5] + ':' + m[6] : null;
        }
        if ((marke & 0xFF00) !== 0xFF00) break;
        p += 2 + laenge;
      }
      return null;
    }).catch(function () { return null; });
  }

  // Foto im Browser dekodieren (auch HEIC am iPhone), richtig drehen, verkleinern, als JPEG
  function fotoVerkleinern(file) {
    var url = URL.createObjectURL(file);
    return new Promise(function (ok, fehler) {
      var img = new Image();
      img.onload = function () {
        var w = img.naturalWidth, h = img.naturalHeight, f = Math.min(1, FOTO_MAX / Math.max(w, h));
        var c = document.createElement('canvas');
        c.width = Math.round(w * f);
        c.height = Math.round(h * f);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        c.toBlob(function (b) { b ? ok(b) : fehler(new Error('Umwandlung fehlgeschlagen')); }, 'image/jpeg', 0.86);
      };
      img.onerror = function () {
        URL.revokeObjectURL(url);
        fehler(new Error('Dieses Bildformat kann der Browser nicht öffnen (z. B. HEIC am PC). Bitte als JPEG speichern.'));
      };
      img.src = url;
    });
  }

  // Vorschaubild und Dauer eines Videos
  function videoVorschau(file) {
    var url = URL.createObjectURL(file);
    return new Promise(function (ok) {
      var v = document.createElement('video'), fertig = false, dauer = 0;
      var ende = function (blob) {
        if (fertig) return;
        fertig = true;
        URL.revokeObjectURL(url);
        ok({ bild: blob, dauer: dauer });
      };
      setTimeout(function () { ende(null); }, 10000);
      v.muted = true;
      v.playsInline = true;
      v.preload = 'auto';
      v.onloadedmetadata = function () {
        dauer = isFinite(v.duration) ? v.duration : 0;
        v.currentTime = Math.min(1, dauer / 2 || 0.1);
      };
      v.onseeked = function () {
        try {
          var f = Math.min(1, 1280 / (v.videoWidth || 1280));
          var c = document.createElement('canvas');
          c.width = Math.round((v.videoWidth || 1280) * f);
          c.height = Math.round((v.videoHeight || 720) * f);
          c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
          c.toBlob(function (b) { ende(b); }, 'image/jpeg', 0.82);
        } catch (e) { ende(null); }
      };
      v.onerror = function () { ende(null); };
      v.src = url;
    });
  }

  function videoMime(file) {
    if (file.type) return file.type;
    var e = (file.name.split('.').pop() || '').toLowerCase();
    return { mov: 'video/quicktime', mp4: 'video/mp4', m4v: 'video/x-m4v', webm: 'video/webm', '3gp': 'video/3gpp' }[e] || '';
  }

  function istVideo(file) {
    return /^video\//.test(file.type) || /\.(mov|mp4|m4v|webm|3gp)$/i.test(file.name);
  }

  function zeile(file) {
    var li = document.createElement('li');
    li.className = 'du-zeile';
    li.innerHTML = '<span class="du-name"></span><span class="du-info"></span><span class="du-balken"><span></span></span>';
    li.querySelector('.du-name').textContent = file.name || (istVideo(file) ? 'Video' : 'Foto');
    $('doku-uploads').appendChild(li);
    return {
      info: function (t) { li.querySelector('.du-info').textContent = t; },
      anteil: function (a) { li.querySelector('.du-balken span').style.width = Math.round(a * 100) + '%'; },
      fehler: function (t) { li.classList.add('fehler'); li.querySelector('.du-info').textContent = t; },
      fertig: function () {
        li.classList.add('fertig');
        li.querySelector('.du-info').textContent = 'fertig';
        setTimeout(function () { li.remove(); }, 4000);
      }
    };
  }

  function teileSenden(id, blob, fortschritt) {
    var offset = 0, versuche = 0;
    function weiter() {
      var ende = Math.min(blob.size, offset + STUECK);
      var fertig = ende >= blob.size;
      return post('teil', { id: id, offset: offset, fertig: fertig ? '1' : '0' }, blob.slice(offset, ende), 'application/octet-stream')
        .then(function (r) {
          if (r._status === 409) {                    // Server hat einen anderen Stand: dort weitermachen
            if (typeof r.vorhanden !== 'number' || ++versuche > 5) throw new Error('Upload aus dem Tritt');
            offset = r.vorhanden;
            return weiter();
          }
          versuche = 0;
          offset = ende;
          fortschritt(offset / blob.size);
          if (!fertig) return weiter();
        }, function (err) {                            // Netzwerk weg (Funkloch): kurz warten, nochmal
          if (!(err instanceof TypeError) || ++versuche > 5) throw err;
          return new Promise(function (r) { setTimeout(r, 2000 * versuche); }).then(weiter);
        });
    }
    return weiter();
  }

  function hochladen(file) {
    var z = zeile(file);
    var video = istVideo(file);
    var zeit = lokalIso(new Date(file.lastModified || Date.now()));
    var daten, vorschau = null, dauer = 0;
    z.info('wird vorbereitet …');
    var vorbereitung = video
      ? videoVorschau(file).then(function (r) { vorschau = r.bild; dauer = r.dauer; daten = file; })
      : exifDatum(file).then(function (d) { if (d) zeit = d; return fotoVerkleinern(file); }).then(function (b) { daten = b; });
    return vorbereitung.then(function () {
      var mime = video ? videoMime(file) : 'image/jpeg';
      z.info(mb(daten.size));
      return post('start', {}, JSON.stringify({ typ: video ? 'video' : 'foto', mime: mime, groesse: daten.size, zeit: zeit, dauer: dauer }));
    }).then(function (r) {
      return teileSenden(r.id, daten, z.anteil).then(function () {
        if (vorschau) return post('vorschau', { id: r.id }, vorschau, 'image/jpeg');
      });
    }).then(function () {
      z.anteil(1);
      z.fertig();
    }).catch(function (err) {
      z.fehler(err.message || 'Fehler');
    });
  }

  if (CSRF) {
    $('doku-hinzufuegen').addEventListener('click', function () { $('doku-datei').click(); });
    $('doku-datei').addEventListener('change', function () {
      var dateien = Array.prototype.slice.call(this.files || []);
      this.value = '';
      // nacheinander hochladen (schont die mobile Verbindung)
      dateien.reduce(function (kette, f) { return kette.then(function () { return hochladen(f); }); }, Promise.resolve())
        .then(laden);
    });
  }

  document.addEventListener('reiter', function (ev) { if (ev.detail === 'doku') laden(); });
  laden();
})();
