/* Club Subs — grassroots squad lists. Every word comes from the block's attributes (cfg.words). */
(function () {
  'use strict';
  var root = document.querySelector('[data-cs-squads]');
  if (!root) return;
  var cfg = JSON.parse(root.getAttribute('data-cs-squads'));
  var W = cfg.words;
  // On a site with the Eastwood FC account, coaches sign in with it (same as the app and My tickets).
  var sb = cfg.fan && window.supabase ? window.supabase.createClient(cfg.fan.supabase, cfg.fan.anon, { auth: { persistSession: true, autoRefreshToken: true, flowType: 'pkce' } }) : null;
  function tok() { return sb ? sb.auth.getSession().then(function (r) { return r.data.session ? r.data.session.access_token : null; }) : Promise.resolve(null); }
  var st = { me: null, team: null, teamDraft: null, view: 'loading', edit: null, flash: '', sent: false, err: '', adding: false, armed: 0, justAdded: 0 };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function w(k, vars) { var s = W[k] || ''; Object.keys(vars || {}).forEach(function (v) { s = s.split('{' + v + '}').join(vars[v]); }); return esc(s); }
  function api(path, body) {
    var o = { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-CS-Coach': '1' } };
    if (body) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(body); }
    return tok().then(function (t) {
      if (t) o.headers.Authorization = 'Bearer ' + t;
      return fetch(cfg.rest + path, o);
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) { var e = new Error(j.message || 'error'); e.status = r.status; e.code = j.code; throw e; } return j; });
    });
  }
  var plus = '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10 4v12M4 10h12"/></svg>';
  var chev = '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M8 5l5 5-5 5"/></svg>';
  var back = '<svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 5l-5 5 5 5"/></svg>';
  var tick = '<svg width="22" height="22" viewBox="0 0 22 22" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="9"/><path d="M7 11.5l2.8 2.8L15 9"/></svg>';

  function hero(title, sub, extra) {
    return '<section class="cs-sq-hero"><div class="cs-sq-in"><div class="cs-sq-hero-text"><h1 class="cs-sq-h1">' + title + '</h1>' + (sub ? '<p class="cs-sq-lede">' + sub + '</p>' : '') + '</div>' + (extra || '') + '</div></section>';
  }
  function who() {
    return '<p class="cs-sq-who">' + w('signed_in', { email: st.me.email }) + ' · <button type="button" class="cs-sq-link" data-a="out">' + w('sign_out') + '</button></p>';
  }
  function field(id, label, type, val, hint, ph) {
    return '<label class="cs-sq-l" for="' + id + '">' + label + '</label><input class="cs-sq-i" id="' + id + '" name="' + id + '" type="' + type + '" value="' + esc(val) + '"' + (ph ? ' placeholder="' + ph + '"' : '') + '>' + (hint ? '<p class="cs-sq-hint">' + hint + '</p>' : '');
  }

  function vSignin() {
    if (sb) {
      var ff = '<form class="cs-sq-card cs-sq-signin" data-f="signin" novalidate><h2 class="cs-sq-h2">' + w('signin_title') + '</h2>' +
        '<p class="cs-sq-hint">' + w('fan_note') + '</p>' +
        field('cs-sq-email', w('email'), 'email', '', '', 'you@example.com') + field('cs-sq-pw', w('password'), 'password', '') +
        (st.err ? '<p class="cs-sq-err" role="alert">' + esc(st.err) + '</p>' : '') +
        '<button class="cs-sq-btn" type="submit">' + w('signin_fan') + '</button>' +
        '<p class="cs-sq-hint"><a href="' + esc(cfg.fan.account) + '">' + w('create') + '</a> · <a href="' + esc(cfg.fan.account) + '">' + w('forgot') + '</a></p></form>';
      return hero(w('title'), w('lede'), ff);
    }
    var form = '<form class="cs-sq-card cs-sq-signin" data-f="signin" novalidate><h2 class="cs-sq-h2">' + w('signin_title') + '</h2>' +
      (st.sent ? '<p class="cs-sq-note" role="status">' + w('sent') + '</p>' :
        field('cs-sq-email', w('email'), 'email', '', '', 'you@example.com') +
        '<button class="cs-sq-btn" type="submit">' + w('signin_button') + '</button><p class="cs-sq-hint">' + w('signin_note') + '</p>') +
      (st.err ? '<p class="cs-sq-err" role="alert">' + esc(st.err) + '</p>' : '') + '</form>';
    return hero(w('title'), w('lede'), form);
  }

  function vVerify() {
    var form = '<form class="cs-sq-card cs-sq-signin" data-f="verify" novalidate><h2 class="cs-sq-h2">' + w('verify_title') + '</h2>' +
      (st.sent ? '<p class="cs-sq-note" role="status">' + w('sent') + '</p>' :
        '<p>' + w('verify_line', { email: st.fanEmail || '' }) + '</p><button class="cs-sq-btn" type="submit">' + w('verify_button') + '</button>') +
      (st.err ? '<p class="cs-sq-err" role="alert">' + esc(st.err) + '</p>' : '') +
      '<p class="cs-sq-hint"><button type="button" class="cs-sq-link" data-a="out">' + w('sign_out') + '</button></p></form>';
    return hero(w('title'), w('lede'), form);
  }

  function vTeam() {
    var t = st.teamDraft || st.team || { name: '', age: '', coaches: [] };
    var coaches = t.coaches.length ? t.coaches : [{ name: '', email: st.me.email, mobile: '' }];
    var ages = '<option value="">' + w('choose') + '</option>' + cfg.ages.map(function (a) { return '<option' + (a === t.age ? ' selected' : '') + '>' + esc(a) + '</option>'; }).join('');
    var rows = coaches.map(function (c, i) {
      return '<div class="cs-sq-coach"><div>' + field('cn' + i, w('coach_name'), 'text', c.name) + '</div><div>' + field('ce' + i, w('coach_email'), 'email', c.email) + '</div><div>' + field('cm' + i, w('coach_mobile'), 'tel', c.mobile) + '</div></div>';
    }).join('');
    return hero(w('team_title'), '', '') +
      '<div class="cs-sq-in cs-sq-main">' + who() +
      '<form data-f="team" class="cs-sq-stack" novalidate>' +
      '<div class="cs-sq-card"><h2 class="cs-sq-h2">' + w('team_box') + '</h2><div class="cs-sq-two"><div>' + field('tn', w('team_name'), 'text', t.name) + '</div><div><label class="cs-sq-l" for="ta">' + w('age_group') + '</label><select class="cs-sq-i" id="ta">' + ages + '</select></div></div></div>' +
      '<div class="cs-sq-card"><h2 class="cs-sq-h2">' + w('coaches_box') + '</h2>' + rows +
      '<button type="button" class="cs-sq-add" data-a="coach">' + plus + w('add_coach') + '</button></div>' +
      (st.err ? '<p class="cs-sq-err" role="alert">' + esc(st.err) + '</p>' : '') +
      '<div class="cs-sq-actions">' + (st.team ? '<button type="button" class="cs-sq-btn cs-sq-quiet" data-a="cancel-team">' + back + w('back') + '</button>' : '<span></span>') +
      '<button class="cs-sq-btn" type="submit">' + (st.team ? w('team_save') : w('team_next')) + '</button></div></form></div>';
  }

  function playerForm() {
    var p = st.edit || {};
    var armed = st.armed && st.armed === p.id;
    return '<form data-f="player" class="cs-sq-card cs-sq-form" novalidate>' +
      '<button type="button" class="cs-sq-back" data-a="close">' + back + (p.id ? w('back') : w('done')) + '</button>' +
      '<h2 class="cs-sq-h2">' + (p.id ? w('edit_title') : w('add_title')) + '</h2>' +
      field('pf', w('first'), 'text', p.first) + field('pl', w('last'), 'text', p.last) +
      field('pd', w('dob'), 'text', p.dob, '', w('dob_hint')) + field('pp', w('parent'), 'text', p.parent) +
      field('pe', w('parent_email'), 'email', p.email, w('parent_email_hint')) + field('pm', w('parent_mobile'), 'tel', p.mobile) +
      (st.err ? '<p class="cs-sq-err" role="alert">' + esc(st.err) + '</p>' : '') +
      '<button class="cs-sq-btn" type="submit">' + w('save') + '</button>' +
      (p.id ? '<button type="button" class="cs-sq-btn cs-sq-quiet' + (armed ? ' cs-sq-armed' : '') + '" data-a="remove">' + (armed ? w('remove_sure', { name: p.first }) : w('remove')) + '</button>' : '') +
      '</form>';
  }

  function vPlayers() {
    var t = st.team, n = t.players.length;
    var list = t.players.map(function (p) {
      var name = esc(p.first + ' ' + p.last);
      return '<button type="button" class="cs-sq-row' + (p.id === st.justAdded ? ' cs-sq-new' : '') + '" data-a="open" data-id="' + p.id + '"><span class="cs-sq-name">' + name +
        (p.id === st.justAdded ? ' <small>· ' + w('just_added') + '</small>' : '') + '</span><span class="cs-sq-sub">' + w('born') + ' ' + esc(p.dob) + '</span><span class="cs-sq-sub">' + esc(p.parent) + '</span>' + chev + '</button>';
    }).join('');
    var addBtn = '<button type="button" class="cs-sq-btn cs-sq-addbtn" data-a="new">' + plus + w('add_button') + '</button>';
    return hero(w('players_title'), esc(t.name) + (t.age ? ' · ' + esc(t.age) : '') + ' · ' + w('players_lede'), addBtn) +
      '<div class="cs-sq-in cs-sq-main">' + who() +
      (st.flash ? '<p class="cs-sq-flash" role="status">' + tick + '<span>' + st.flash + '</span></p>' : '') +
      '<div class="cs-sq-grid' + (st.adding ? ' cs-sq-adding' : '') + '"><div class="cs-sq-list">' +
      '<div class="cs-sq-listhead"><h2 class="cs-sq-h2">' + w('squad_box') + '</h2><span class="cs-sq-count">' + (n === 1 ? w('count_one') : w('count', { n: n })) + '</span></div>' +
      (n ? list + '<p class="cs-sq-hint">' + w('tap_hint') + '</p>' : '<p class="cs-sq-empty">' + w('empty') + '</p>') +
      '<button type="button" class="cs-sq-link cs-sq-editteam" data-a="team">' + w('edit_team') + '</button></div>' +
      playerForm() + '</div></div>';
  }

  function render() {
    var html = st.view === 'loading' ? '<div class="cs-sq-in cs-sq-main"><p class="cs-sq-hint">…</p></div>'
      : st.view === 'signin' ? vSignin() : st.view === 'verify' ? vVerify() : st.view === 'team' ? vTeam() : vPlayers();
    root.innerHTML = html;
  }

  function load() {
    api('me').then(function (me) {
      st.me = me; st.team = me.teams[0] || null;
      st.view = st.team ? 'players' : 'team'; render();
    }).catch(function (e) {
      if (e.code === 'cs_coach_verify') { st.fanEmail = e.message; st.view = 'verify'; } else { st.view = 'signin'; }
      render();
    });
  }
  function val(id) { var el = root.querySelector('#' + id); return el ? el.value.trim() : ''; }

  root.addEventListener('click', function (e) {
    var b = e.target.closest('[data-a]'); if (!b) return;
    var a = b.getAttribute('data-a');
    if (a !== 'remove') st.armed = 0;
    if (a === 'out') { (sb ? sb.auth.signOut() : Promise.resolve()).then(function () { return api('out', {}); }).catch(function () {}).then(function () { st = { view: 'signin', err: '', sent: false }; render(); }); return; }
    if (a === 'coach') { var d = readTeam(); if (!d.coaches.length) d.coaches.push({ name: '', email: st.me.email, mobile: '' }); d.coaches.push({ name: '', email: '', mobile: '' }); st.teamDraft = d; render(); return; }
    if (a === 'team') { st.err = ''; st.flash = ''; st.teamDraft = null; st.view = 'team'; render(); return; }
    if (a === 'cancel-team') { st.err = ''; st.teamDraft = null; st.view = 'players'; render(); return; }
    if (a === 'new') { st.edit = null; st.err = ''; st.adding = true; st.flash = ''; render(); focusFirst(); return; }
    if (a === 'close') { st.edit = null; st.err = ''; st.adding = false; render(); return; }
    if (a === 'open') { var id = +b.getAttribute('data-id'); st.edit = st.team.players.filter(function (p) { return p.id === id; })[0]; st.err = ''; st.adding = true; st.flash = ''; render(); return; }
    if (a === 'remove') {
      var p = st.edit; if (!p) return;
      if (st.armed !== p.id) { st.armed = p.id; render(); return; }
      api('player/remove', { id: p.id }).then(function () {
        st.team.players = st.team.players.filter(function (x) { return x.id !== p.id; });
        st.flash = w('removed', { name: p.first + ' ' + p.last }); st.edit = null; st.adding = false; st.armed = 0; render();
      }).catch(function () { st.err = W.error; render(); });
    }
  });


  function readTeam() {
    var out = [], i = 0;
    while (root.querySelector('#cn' + i)) {
      var c = { name: val('cn' + i), email: val('ce' + i), mobile: val('cm' + i) };
      if (c.name || c.email || c.mobile) out.push(c);
      i++;
    }
    return { name: val('tn'), age: val('ta'), coaches: out };
  }
  function focusFirst() { var i = root.querySelector('#pf'); if (i) i.focus(); }

  root.addEventListener('submit', function (e) {
    e.preventDefault();
    var f = e.target.getAttribute('data-f');
    var btns = e.target.querySelectorAll('button'); btns.forEach(function (x) { x.disabled = true; });
    if (f === 'signin' && sb) {
      sb.auth.signInWithPassword({ email: val('cs-sq-email'), password: root.querySelector('#cs-sq-pw').value })
        .then(function (r) { if (r.error) throw r.error; st.err = ''; st.view = 'loading'; render(); load(); })
        .catch(function (x) { st.err = x.message; render(); });
      return;
    }
    if (f === 'verify') {
      api('link', { page: cfg.page, words: { mail_subject: W.mail_subject, mail_body: W.mail_body } })
        .then(function () { st.sent = true; st.err = ''; render(); })
        .catch(function () { st.err = W.error; render(); });
      return;
    }
    if (f === 'signin') {
      api('link', { email: val('cs-sq-email'), page: cfg.page, words: { mail_subject: W.mail_subject, mail_body: W.mail_body } })
        .then(function () { st.sent = true; st.err = ''; render(); })
        .catch(function (x) { st.err = x.message; render(); });
    }
    if (f === 'team') {
      var d = readTeam();
      api('team', { id: st.team && st.team.id ? st.team.id : 0, name: d.name, age: d.age, coaches: d.coaches })
        .then(function (t) { st.team = t; st.teamDraft = null; st.err = ''; st.view = 'players'; st.flash = ''; render(); })
        .catch(function (x) { st.teamDraft = d; st.err = x.message === 'error' ? W.error : x.message; render(); });
    }
    if (f === 'player') {
      var p = { team: st.team.id, id: st.edit ? st.edit.id : 0, first: val('pf'), last: val('pl'), dob: val('pd'), parent: val('pp'), email: val('pe'), mobile: val('pm') };
      if (!p.first || !p.last || !p.dob) { st.err = W.need; st.edit = p; render(); return; }
      if (!/^\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4}$/.test(p.dob)) { st.err = W.bad_date; st.edit = p; render(); return; }
      var then = p.id ? 'close' : 'again'; // a new player keeps the form open, ready for the next one
      api('player', p).then(function (saved) {
        var i = -1; st.team.players.forEach(function (x, k) { if (x.id === saved.id) i = k; });
        if (i >= 0) st.team.players[i] = saved; else st.team.players.push(saved);
        st.team.players.sort(function (a, b) { return (a.first + a.last).localeCompare(b.first + b.last); });
        st.justAdded = saved.id; st.err = '';
        var n = st.team.players.length;
        st.flash = w('saved', { name: saved.first + ' ' + saved.last }) + ' · ' + (n === 1 ? w('count_one') : w('count', { n: n }));
        st.edit = null; if (then === 'close') st.adding = false; render();
        if (then === 'again') focusFirst(); else window.scrollTo({ top: root.getBoundingClientRect().top + window.scrollY - 20, behavior: 'smooth' });
      }).catch(function (x) { st.err = x.code === 'cs_player_need' ? W.need : W.error; st.edit = p; render(); });
    }
  });

  if (/[?&]expired=1/.test(location.search)) st.err = W.expired;
  load();
})();
