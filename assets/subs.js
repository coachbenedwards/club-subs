/* Club Subs — sign-up and account blocks. No framework. */
(function () {
  var cfg = window.CS_SUBS || {};
  var W = cfg.words || {};

  function post(path, data) {
    return fetch(cfg.rest + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
      body: JSON.stringify(data)
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); });
  }
  function money(p) { return '£' + (p / 100).toFixed(2).replace(/\.00$/, ''); }
  function ordinal(n) { var s = ['th', 'st', 'nd', 'rd'], v = n % 100; return n + (s[(v - 20) % 10] || s[v] || s[0]); }

  /* ---- sign-up ---- */
  document.querySelectorAll('[data-cs-signup]').forEach(function (root) {
    var form = root.querySelector('form'), plan = root.querySelector('#cs_plan'), note = root.querySelector('[data-cs-note]'), err = root.querySelector('[data-cs-err]'), btn = form.querySelector('button[type=submit]');
    var label = btn.textContent;
    plan.addEventListener('change', function () {
      var o = plan.options[plan.selectedIndex];
      if (!o || !o.value) { note.hidden = true; return; }
      var first = parseInt(o.dataset.first || '0', 10), amt = parseInt(o.dataset.amount, 10), day = parseInt(o.dataset.day, 10), count = parseInt(o.dataset.count, 10);
      var lines = [];
      if (first >= 100) lines.push((W.first_note || '').replace('{amount}', money(first)));
      lines.push((W.next_note || '').replace('{amount}', money(amt)).replace('{day}', ordinal(day)).replace('{count}', count));
      note.textContent = lines.join(' ');
      note.hidden = false;
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      err.hidden = true;
      var need = ['#cs_plan', '#cs_child_first', '#cs_child_last', '#cs_parent_name', '#cs_email'].some(function (s) { return !form.querySelector(s).value.trim(); }) || !form.querySelector('#cs_consent').checked;
      if (need) { err.textContent = W.need; err.hidden = false; return; }
      btn.disabled = true; btn.textContent = W.loading;
      post('signup', {
        plan: plan.value, page: cfg.page,
        child_first: form.querySelector('#cs_child_first').value, child_last: form.querySelector('#cs_child_last').value, child_dob: form.querySelector('#cs_dob').value,
        parent_name: form.querySelector('#cs_parent_name').value, email: form.querySelector('#cs_email').value, phone: form.querySelector('#cs_phone').value, consent: 1
      }).then(function (r) {
        if (r.ok && r.body && r.body.url) { window.location.href = r.body.url; return; }
        err.textContent = (r.body && r.body.message) || W.failed; err.hidden = false; btn.disabled = false; btn.textContent = label;
      }).catch(function () { err.textContent = W.failed; err.hidden = false; btn.disabled = false; btn.textContent = label; });
    });
  });

  /* ---- account ---- */
  document.querySelectorAll('[data-cs-account]').forEach(function (root) {
    var link = root.querySelector('[data-cs-link]');
    if (link) {
      link.addEventListener('submit', function (e) {
        e.preventDefault();
        var b = link.querySelector('button'); b.disabled = true;
        post('account/link', { email: link.querySelector('#cs_acct_email').value, page: cfg.page }).then(function () {
          link.querySelector('[data-cs-sent]').hidden = false;
        });
      });
    }
    root.querySelectorAll('[data-cs-cancel]').forEach(function (b) {
      b.addEventListener('click', function () {
        var card = b.closest('.cs-card'), msg = card.querySelector('[data-cs-msg]');
        // The viewer never shows confirm(); make the button a two-tap.
        if (!b.dataset.armed) { b.dataset.armed = '1'; b.textContent = (W.cancel_sure || '').replace('{child}', b.dataset.child); b.classList.add('cs-armed'); return; }
        b.disabled = true;
        post('account/cancel', { member: card.dataset.member, token: card.dataset.token }).then(function (r) {
          msg.textContent = r.ok ? W.cancelled : ((r.body && r.body.message) || 'Could not cancel.');
          msg.hidden = false; if (r.ok) b.remove();
        });
      });
    });
  });
})();
