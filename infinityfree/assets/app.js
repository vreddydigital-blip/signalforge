/* ==========================================================================
   SignalForge — dashboard front end
   --------------------------------------------------------------------------
   Plain ES2017, no build step, no dependencies. Served from /assets/app.js.

   Talks to the existing PHP API on the SAME ORIGIN it was served from. Every
   request is a relative path ("api/index.php"), so the app works on any host
   without configuration.

   API contract (unchanged, already tested):
     GET  api/index.php?route=auth.me
     POST api/index.php?route=auth.register   {name,email,password}
     POST api/index.php?route=auth.login      {email,password}
     POST api/index.php?route=auth.logout
     GET  api/index.php?route=experiments.list
     POST api/index.php?route=experiments.create
     GET  api/index.php?route=v2.dashboard&input={"range":"30d"}

   POSTs require the header  X-Requested-With: signalforge  (CSRF guard).
   Success: {data: ...}   Failure: {error: {message, code}}
   ========================================================================== */

(function () {
  'use strict';

  // ---------------------------------------------------------------- config
  var API = 'api/index.php';

  // ---------------------------------------------------------------- state
  var state = {
    user: null,
    experiments: [],
    dashboard: null,
    range: '30d',
    view: 'experiments',
    authMode: 'login',   // 'login' | 'register'
    busy: false
  };

  // ---------------------------------------------------------------- dom
  function $(id) { return document.getElementById(id); }

  var el = {
    authScreen: $('auth-screen'),
    authTitle: $('auth-title'),
    authSub: $('auth-sub'),
    authForm: $('auth-form'),
    authError: $('auth-error'),
    authSubmit: $('auth-submit'),
    authSwitchText: $('auth-switch-text'),
    authSwitchBtn: $('auth-switch-btn'),
    nameField: $('name-field'),
    fName: $('f-name'),
    fEmail: $('f-email'),
    fPassword: $('f-password'),

    app: $('app'),
    userName: $('user-name'),
    userPlan: $('user-plan'),
    logoutBtn: $('logout-btn'),

    viewExperiments: $('view-experiments'),
    viewResults: $('view-results'),
    expList: $('exp-list'),
    expEmpty: $('exp-empty'),
    expCount: $('exp-count'),
    newExpBtn: $('new-exp-btn'),
    emptyNewBtn: $('empty-new-btn'),

    statGrid: $('stat-grid'),
    chart: $('chart'),
    breakdownGrid: $('breakdown-grid'),

    modal: $('modal'),
    modalTitle: $('modal-title'),
    modalClose: $('modal-close'),
    modalCancel: $('modal-cancel'),
    expForm: $('exp-form'),
    expError: $('exp-error'),
    expSubmit: $('exp-submit'),
    eName: $('e-name'),
    eUrl: $('e-url'),
    eUrlCheck: $('e-url-check'),
    eUrlHint: $('e-url-hint'),
    eObjective: $('e-objective'),
    eInsight: $('e-insight'),
    eChange: $('e-change'),
    eReason: $('e-reason'),
    eHypothesis: $('e-hypothesis'),
    eGen: $('e-gen'),
    eHypHint: $('e-hyp-hint'),
    eKind: $('e-kind'),
    eFramework: $('e-framework'),
    eGoal: $('e-goal'),
    eAudience: $('e-audience'),
    eEvent: $('e-event'),
    eTraffic: $('e-traffic'),
    variantRows: $('variant-rows'),
    addVariant: $('add-variant'),

    toast: $('toast')
  };

  // ---------------------------------------------------------------- helpers
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function num(n) {
    if (n == null || isNaN(n)) return '0';
    return Number(n).toLocaleString('en-US');
  }

  function money(n) {
    var v = Number(n || 0);
    return '$' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  var toastTimer = null;
  function toast(msg, isError) {
    el.toast.textContent = msg;
    el.toast.className = 'toast' + (isError ? ' is-error' : '');
    el.toast.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.toast.hidden = true; }, 3600);
  }

  // ---------------------------------------------------------------- api
  /**
   * Call the API. Resolves with the `data` payload, rejects with an Error
   * carrying the server's message and HTTP status.
   */
  function api(route, opts) {
    opts = opts || {};
    var url = API + '?route=' + encodeURIComponent(route);

    if (opts.params) {
      Object.keys(opts.params).forEach(function (k) {
        var v = opts.params[k];
        url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(
          typeof v === 'object' ? JSON.stringify(v) : v
        );
      });
    }

    var init = {
      method: opts.method || 'GET',
      credentials: 'same-origin',   // send/receive the session cookie
      headers: { 'Accept': 'application/json' }
    };

    if (init.method === 'POST') {
      // The API rejects POSTs without this header (CSRF guard).
      init.headers['X-Requested-With'] = 'signalforge';
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(opts.body || {});
    }

    return fetch(url, init).then(function (res) {
      return res.text().then(function (text) {
        var payload = null;
        try { payload = text ? JSON.parse(text) : null; } catch (e) { payload = null; }

        if (!res.ok) {
          var msg = (payload && payload.error && payload.error.message)
            ? payload.error.message
            : 'Request failed (' + res.status + ')';
          var err = new Error(msg);
          err.status = res.status;
          err.code = payload && payload.error ? payload.error.code : null;
          throw err;
        }
        if (payload === null) {
          throw new Error('The server returned an unexpected response.');
        }
        return payload.data;
      });
    });
  }

  // ---------------------------------------------------------------- auth UI
  function setAuthMode(mode) {
    state.authMode = mode;
    var isLogin = mode === 'login';

    el.authTitle.textContent = isLogin ? 'Welcome back' : 'Create your account';
    el.authSub.textContent = isLogin
      ? 'Log in to your experimentation workspace.'
      : 'Start running experiments in under a minute.';
    el.authSubmit.textContent = isLogin ? 'Log in' : 'Create account';
    el.nameField.hidden = isLogin;
    el.fPassword.setAttribute('autocomplete', isLogin ? 'current-password' : 'new-password');
    el.authSwitchText.textContent = isLogin ? 'New to SignalForge?' : 'Already have an account?';
    el.authSwitchBtn.textContent = isLogin ? 'Create an account' : 'Log in';
    el.authError.hidden = true;
  }

  function showAuthError(msg) {
    el.authError.textContent = msg;
    el.authError.hidden = false;
  }

  function showAuth() {
    state.user = null;
    el.app.hidden = true;
    el.authScreen.hidden = false;
    setAuthMode('login');
    el.fEmail.value = '';
    el.fPassword.value = '';
    el.fName.value = '';
    el.fEmail.focus();
  }

  function showApp(user) {
    state.user = user;
    el.authScreen.hidden = true;
    el.app.hidden = false;
    el.userName.textContent = user.name || user.email;
    el.userPlan.textContent = user.plan || 'Free';
    switchView('experiments');
    loadExperiments();
  }

  // ---------------------------------------------------------------- auth actions
  el.authSwitchBtn.addEventListener('click', function () {
    setAuthMode(state.authMode === 'login' ? 'register' : 'login');
  });

  el.authForm.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (state.busy) return;

    var email = el.fEmail.value.trim();
    var password = el.fPassword.value;
    var name = el.fName.value.trim();

    if (!email || !password) {
      showAuthError('Enter your email and password.');
      return;
    }
    if (state.authMode === 'register') {
      if (!name) { showAuthError('Enter your name.'); return; }
      if (password.length < 8) { showAuthError('Password must be at least 8 characters.'); return; }
    }

    state.busy = true;
    el.authSubmit.disabled = true;
    el.authSubmit.textContent = state.authMode === 'login' ? 'Logging in…' : 'Creating account…';
    el.authError.hidden = true;

    var route = state.authMode === 'login' ? 'auth.login' : 'auth.register';
    var body = state.authMode === 'login'
      ? { email: email, password: password }
      : { name: name, email: email, password: password };

    api(route, { method: 'POST', body: body })
      .then(function (user) {
        el.fPassword.value = '';
        showApp(user);
        toast(state.authMode === 'login' ? 'Logged in.' : 'Account created.');
      })
      .catch(function (err) {
        showAuthError(err.message);
      })
      .then(function () {
        state.busy = false;
        el.authSubmit.disabled = false;
        el.authSubmit.textContent = state.authMode === 'login' ? 'Log in' : 'Create account';
      });
  });

  el.logoutBtn.addEventListener('click', function () {
    api('auth.logout', { method: 'POST' })
      .catch(function () { /* log out locally regardless */ })
      .then(function () {
        showAuth();
        toast('Logged out.');
      });
  });

  // ---------------------------------------------------------------- views
  function switchView(view) {
    state.view = view;

    Array.prototype.forEach.call(document.querySelectorAll('.tab'), function (t) {
      t.classList.toggle('is-active', t.getAttribute('data-view') === view);
    });

    el.viewExperiments.hidden = view !== 'experiments';
    el.viewResults.hidden = view !== 'results';

    if (view === 'results') loadDashboard();
  }

  Array.prototype.forEach.call(document.querySelectorAll('.tab'), function (t) {
    t.addEventListener('click', function () { switchView(t.getAttribute('data-view')); });
  });

  Array.prototype.forEach.call(document.querySelectorAll('.range-btn'), function (b) {
    b.addEventListener('click', function () {
      Array.prototype.forEach.call(document.querySelectorAll('.range-btn'), function (x) {
        x.classList.toggle('is-active', x === b);
      });
      state.range = b.getAttribute('data-range');
      loadDashboard();
    });
  });

  // ---------------------------------------------------------------- experiments
  function loadExperiments() {
    el.expList.innerHTML = '<div class="skeleton"></div><div class="skeleton"></div>';

    api('experiments.list')
      .then(function (list) {
        state.experiments = Array.isArray(list) ? list : [];
        renderExperiments();
      })
      .catch(function (err) {
        if (err.status === 401) { showAuth(); return; }
        el.expList.innerHTML = '';
        toast(err.message, true);
      });
  }

  function statusClass(status) {
    var s = String(status || '').toLowerCase();
    if (s === 'active' || s === 'running') return 'badge-active';
    if (s === 'paused') return 'badge-paused';
    if (s === 'completed') return 'badge-completed';
    return 'badge-draft';
  }

  function renderExperiments() {
    var list = state.experiments;

    el.expCount.textContent = list.length === 0
      ? 'No experiments yet'
      : list.length + (list.length === 1 ? ' experiment' : ' experiments');

    if (list.length === 0) {
      el.expList.innerHTML = '';
      el.expEmpty.hidden = false;
      return;
    }
    el.expEmpty.hidden = true;

    el.expList.innerHTML = list.map(function (e) {
      var variants = (e.variants || []).map(function (v) {
        return '' +
          '<div class="variant-row">' +
            '<div class="variant-label">' +
              '<span class="variant-swatch" style="background:' + esc(v.color || '#8d96a8') + '"></span>' +
              '<span class="variant-name">' + esc(v.name) + '</span>' +
              (v.isControl ? '<span class="variant-tag">control</span>' : '') +
            '</div>' +
            '<div class="variant-nums">' + num(v.visitors) + ' / ' + num(v.conversions) + '</div>' +
            '<div class="variant-nums variant-rate">' + Number(v.rate || 0).toFixed(2) + '%</div>' +
          '</div>';
      }).join('');

      return '' +
        '<article class="exp-card">' +
          '<div class="exp-card-head">' +
            '<div class="exp-name">' + esc(e.name) + '</div>' +
            '<span class="badge ' + statusClass(e.status) + '">' + esc(e.status) + '</span>' +
          '</div>' +
          (e.pageUrl
            ? '<span class="exp-url"><a href="' + esc(e.pageUrl) + '" target="_blank" rel="noopener noreferrer">' + esc(e.pageUrl) + '</a></span>'
            : '<span class="exp-url">No page URL set</span>') +
          '<div class="exp-meta">' +
            '<span>' + esc(e.kind) + '</span>' +
            '<span>' + esc(e.goal) + '</span>' +
            '<span>' + esc(e.traffic) + '% traffic</span>' +
            '<span>' + esc(e.updated) + '</span>' +
          '</div>' +
          '<div class="variant-list">' + variants + '</div>' +
        '</article>';
    }).join('');
  }

  // ---------------------------------------------------------------- dashboard
  function loadDashboard() {
    el.statGrid.innerHTML = '<div class="skeleton"></div><div class="skeleton"></div>' +
                            '<div class="skeleton"></div><div class="skeleton"></div>';
    el.chart.innerHTML = '<div class="chart-empty">Loading…</div>';
    el.breakdownGrid.innerHTML = '';

    api('v2.dashboard', { params: { input: { range: state.range } } })
      .then(function (d) {
        state.dashboard = d;
        renderDashboard(d);
      })
      .catch(function (err) {
        if (err.status === 401) { showAuth(); return; }
        el.statGrid.innerHTML = '';
        el.chart.innerHTML = '<div class="chart-empty">Could not load results: ' + esc(err.message) + '</div>';
        toast(err.message, true);
      });
  }

  function renderDashboard(d) {
    var t = d.totals || {};

    // ---- stat cards
    var uplift = t.bestUplift;
    var upliftClass = uplift == null ? '' : (uplift >= 0 ? 'is-good' : 'is-bad');
    var upliftText = uplift == null ? '—' : (uplift > 0 ? '+' : '') + uplift + '%';

    var stats = [
      { label: 'Experiments', value: num(t.experiments), sub: num(t.active) + ' active · ' + num(t.draft) + ' draft' },
      { label: 'Exposures', value: num(t.exposures), sub: 'visitors bucketed' },
      { label: 'Conversions', value: num(t.conversions), sub: num(t.conversionRate) + '% conversion rate' },
      { label: 'Revenue', value: money(t.revenue), sub: 'attributed' },
      { label: 'Best uplift', value: upliftText, sub: t.confidence ? t.confidence + '% confidence' : 'no data yet', cls: upliftClass }
    ];

    el.statGrid.innerHTML = stats.map(function (s) {
      return '' +
        '<div class="stat">' +
          '<div class="stat-label">' + esc(s.label) + '</div>' +
          '<div class="stat-value ' + (s.cls || '') + '">' + esc(s.value) + '</div>' +
          '<div class="stat-sub">' + esc(s.sub) + '</div>' +
        '</div>';
    }).join('');

    renderChart(d.series || []);
    renderBreakdowns(d.breakdowns || {});
  }

  // ---- grouped bar chart, hand-rolled SVG (no chart library)
  function renderChart(series) {
    if (!series.length) {
      el.chart.innerHTML = '<div class="chart-empty">No traffic recorded in this range yet.<br>' +
        'Exposures appear here once a visitor is bucketed into an active experiment.</div>';
      return;
    }

    var W = 900, H = 240;
    var padL = 44, padR = 12, padT = 12, padB = 30;
    var plotW = W - padL - padR;
    var plotH = H - padT - padB;

    var maxVal = 0;
    series.forEach(function (s) {
      maxVal = Math.max(maxVal, s.exposures || 0, s.conversions || 0);
    });
    if (maxVal === 0) maxVal = 1;

    // round the axis top to something readable
    var mag = Math.pow(10, Math.floor(Math.log10(maxVal)));
    var axisMax = Math.ceil(maxVal / mag) * mag;
    if (axisMax === maxVal) axisMax = maxVal + mag;

    var n = series.length;
    var slot = plotW / n;
    var barW = Math.max(2, Math.min(14, slot * 0.32));
    var gap = 2;

    var parts = [];
    parts.push('<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Exposures and conversions over time" preserveAspectRatio="none">');

    // horizontal grid + y labels
    var ticks = 4;
    for (var i = 0; i <= ticks; i++) {
      var y = padT + (plotH / ticks) * i;
      var val = axisMax - (axisMax / ticks) * i;
      parts.push('<line class="grid-line" x1="' + padL + '" y1="' + y.toFixed(1) + '" x2="' + (W - padR) + '" y2="' + y.toFixed(1) + '"/>');
      parts.push('<text class="axis-text" x="' + (padL - 7) + '" y="' + (y + 3).toFixed(1) + '" text-anchor="end">' + shortNum(val) + '</text>');
    }

    // bars
    series.forEach(function (s, idx) {
      var cx = padL + slot * idx + slot / 2;
      var expH = ((s.exposures || 0) / axisMax) * plotH;
      var convH = ((s.conversions || 0) / axisMax) * plotH;

      var xExp = cx - barW - gap / 2;
      var xConv = cx + gap / 2;

      parts.push('<rect class="bar-exp" x="' + xExp.toFixed(1) + '" y="' + (padT + plotH - expH).toFixed(1) +
        '" width="' + barW.toFixed(1) + '" height="' + Math.max(0, expH).toFixed(1) + '" rx="1.5">' +
        '<title>' + esc(s.day) + ' — ' + num(s.exposures) + ' exposures</title></rect>');

      parts.push('<rect class="bar-conv" x="' + xConv.toFixed(1) + '" y="' + (padT + plotH - convH).toFixed(1) +
        '" width="' + barW.toFixed(1) + '" height="' + Math.max(0, convH).toFixed(1) + '" rx="1.5">' +
        '<title>' + esc(s.day) + ' — ' + num(s.conversions) + ' conversions</title></rect>');

      // x labels — thin them out so they never collide
      var every = Math.ceil(n / 8);
      if (idx % every === 0 || idx === n - 1) {
        parts.push('<text class="axis-text" x="' + cx.toFixed(1) + '" y="' + (H - 10) +
          '" text-anchor="middle">' + esc(shortDay(s.day)) + '</text>');
      }
    });

    parts.push('</svg>');
    el.chart.innerHTML = parts.join('');
  }

  function shortNum(v) {
    if (v >= 1000000) return (v / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
    if (v >= 1000) return (v / 1000).toFixed(1).replace(/\.0$/, '') + 'k';
    return String(Math.round(v));
  }

  function shortDay(day) {
    // "2026-11-21" -> "Nov 21"
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(day || ''));
    if (!m) return String(day || '');
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[parseInt(m[2], 10) - 1] + ' ' + parseInt(m[3], 10);
  }

  // ---- breakdown cards
  function renderBreakdowns(bd) {
    var dims = [
      { key: 'device', title: 'Device' },
      { key: 'browser', title: 'Browser' },
      { key: 'country', title: 'Country' },
      { key: 'landingPage', title: 'Landing page' }
    ];

    el.breakdownGrid.innerHTML = dims.map(function (dim) {
      var rows = bd[dim.key] || [];
      var body;

      if (!rows.length) {
        body = '<div class="bd-empty">No data in this range.</div>';
      } else {
        var max = rows.reduce(function (m, r) { return Math.max(m, r.value || 0); }, 1);
        body = rows.map(function (r) {
          var pct = Math.round(((r.value || 0) / max) * 100);
          return '' +
            '<div class="bd-row">' +
              '<div class="bd-row-head">' +
                '<span class="bd-label" title="' + esc(r.label) + '">' + esc(r.label) + '</span>' +
                '<span class="bd-value">' + num(r.value) + '</span>' +
              '</div>' +
              '<div class="bd-track"><div class="bd-fill" style="width:' + pct + '%"></div></div>' +
            '</div>';
        }).join('');
      }

      return '<div class="bd-card"><div class="bd-title">' + esc(dim.title) + '</div>' + body + '</div>';
    }).join('');
  }

  // ---------------------------------------------------------------- modal
  function addVariantRow(value) {
    var row = document.createElement('div');
    row.className = 'variant-input-row';

    var idx = document.createElement('span');
    idx.className = 'variant-index';
    idx.textContent = String(el.variantRows.children.length + 1);

    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'variant-name-input';
    input.placeholder = 'Variant name';
    input.value = value || '';

    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'icon-btn';
    remove.setAttribute('aria-label', 'Remove variant');
    remove.textContent = '✕';
    remove.addEventListener('click', function () {
      if (el.variantRows.children.length <= 2) {
        toast('An experiment needs at least 2 variants.', true);
        return;
      }
      row.remove();
      renumberVariants();
    });

    row.appendChild(idx);
    row.appendChild(input);
    row.appendChild(remove);
    el.variantRows.appendChild(row);
  }

  function renumberVariants() {
    Array.prototype.forEach.call(el.variantRows.children, function (row, i) {
      row.querySelector('.variant-index').textContent = String(i + 1);
    });
  }

  function openModal() {
    el.expForm.reset();
    el.expError.hidden = true;
    el.variantRows.innerHTML = '';
    addVariantRow('Control');
    addVariantRow('Variant B');
    el.eTraffic.value = '100';
    setUrlState('idle');
    el.eHypHint.textContent = 'Fill in the three inputs above and press Generate to draft this.';
    el.modal.hidden = false;
    el.eName.focus();
  }

  // ------------------------------------------------------- page URL + hypothesis
  // The API stores pageUrl as an optional free-text field, so the UI validates
  // it here rather than relying on the server. "Check page" is a local sanity
  // check only - it does not fetch the URL (that would be blocked by CORS on
  // most sites and would leak the visitor's IP to a third party).
  function normaliseUrl(raw) {
    var v = String(raw || '').trim();
    if (!v) return '';
    if (!/^https?:\/\//i.test(v)) v = 'https://' + v;
    return v;
  }

  function urlProblem(raw) {
    var v = normaliseUrl(raw);
    if (!v) return 'Add the page where this experiment will run.';
    var parsed;
    try {
      parsed = new URL(v);
    } catch (e) {
      return 'That does not look like a valid URL.';
    }
    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
      return 'Use an http:// or https:// address.';
    }
    if (!parsed.hostname || parsed.hostname.indexOf('.') === -1) {
      return 'Add a full domain, e.g. https://yourwebsite.com/pricing';
    }
    return '';
  }

  function setUrlState(state, message) {
    el.eUrlHint.className = state === 'ok' ? 'field-ok' : (state === 'bad' ? 'field-bad' : 'field-hint');
    if (message) {
      el.eUrlHint.textContent = message;
    } else if (state === 'ok') {
      el.eUrlHint.textContent = 'Page URL looks valid and is ready for tracking.';
    } else {
      el.eUrlHint.textContent = 'The page where this experiment will run.';
    }
  }

  el.eUrlCheck.addEventListener('click', function () {
    var problem = urlProblem(el.eUrl.value);
    if (problem) {
      setUrlState('bad', problem);
      el.eUrl.focus();
      return;
    }
    el.eUrl.value = normaliseUrl(el.eUrl.value);
    setUrlState('ok');
  });

  el.eUrl.addEventListener('input', function () {
    setUrlState('idle');
  });

  // Builds a MECLABS-style hypothesis from whatever the user has filled in.
  // Every clause degrades gracefully, so a partial brief still produces a
  // usable sentence instead of a template full of blanks.
  //
  // The inputs are free text, so people write them in mixed forms: "Add a
  // comparison summary" (imperative) or "a comparison summary" (noun phrase).
  // Naively prefixing "changing " gives "changing add a comparison summary",
  // and prefixing "will improve " to a goal like "Increase signups" gives
  // "improve increase signups". Both are stripped here so the sentence reads
  // naturally whichever way the user typed it.
  var LEADING_VERB = /^(add|adding|change|changing|remove|removing|replace|replacing|move|moving|make|making|use|using|show|showing|simplify|simplifying|reduce|reducing|increase|increasing|improve|improving|test|testing|try|trying|create|creating|update|updating|redesign|redesigning|rewrite|rewriting|shorten|shortening|clarify|clarifying)\s+/i;

  function stripLeadingVerb(text) {
    return text.replace(LEADING_VERB, '');
  }

  function lowerFirst(text) {
    if (!text) return text;
    // Leave acronyms and proper nouns alone (e.g. "CTA", "PXL").
    if (text.length > 1 && text.charAt(1) === text.charAt(1).toUpperCase()) return text;
    return text.charAt(0).toLowerCase() + text.slice(1);
  }

  function buildHypothesis() {
    var insight = el.eInsight.value.trim();
    var change = el.eChange.value.trim();
    var reason = el.eReason.value.trim();
    var audience = el.eAudience.value.trim();
    var goal = el.eGoal.value.trim();

    if (!insight && !change && !reason) return '';

    var because = insight
      ? 'Because ' + lowerFirst(insight)
      : 'Because we see an opportunity on this page';

    // "changing <noun phrase>" reads correctly for both input styles.
    var changing = change
      ? 'changing ' + lowerFirst(stripLeadingVerb(change))
      : 'changing this experience';

    // Goals are usually written as outcomes ("Increase signups"), so they slot
    // in after "will improve" without their own verb. Both goal and audience
    // are required fields, but Generate can be pressed before they are filled,
    // so each clause is dropped rather than left with a blank in it.
    var goalText = goal ? lowerFirst(stripLeadingVerb(goal)) : '';
    var will = goalText ? 'will improve ' + goalText : 'will improve the primary goal';
    if (audience) will += ' for ' + lowerFirst(audience);

    var why = reason ? ' because ' + lowerFirst(reason) : '';

    return because + ', ' + changing + ' ' + will + why + '.';
  }

  el.eGen.addEventListener('click', function () {
    var text = buildHypothesis();
    if (!text) {
      el.eHypHint.className = 'field-bad';
      el.eHypHint.textContent = 'Add an observed insight, experience change or behavioral reason first.';
      return;
    }
    el.eHypothesis.value = text.slice(0, 240);
    el.eHypHint.className = 'field-hint';
    el.eHypHint.textContent = el.eHypothesis.value.length + '/240 characters.';
  });

  el.eHypothesis.addEventListener('input', function () {
    el.eHypHint.className = 'field-hint';
    el.eHypHint.textContent = el.eHypothesis.value.length + '/240 characters.';
  });

  function closeModal() {
    el.modal.hidden = true;
  }

  el.newExpBtn.addEventListener('click', openModal);
  el.emptyNewBtn.addEventListener('click', openModal);
  el.modalClose.addEventListener('click', closeModal);
  el.modalCancel.addEventListener('click', closeModal);
  el.addVariant.addEventListener('click', function () {
    if (el.variantRows.children.length >= 8) {
      toast('Up to 8 variants.', true);
      return;
    }
    addVariantRow('');
  });

  el.modal.addEventListener('click', function (ev) {
    if (ev.target === el.modal) closeModal();
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && !el.modal.hidden) closeModal();
  });

  el.expForm.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (state.busy) return;

    var name = el.eName.value.trim();
    var goal = el.eGoal.value.trim();
    var audience = el.eAudience.value.trim();
    var conversionEvent = el.eEvent.value.trim();
    var traffic = parseInt(el.eTraffic.value, 10);
    var pageUrl = normaliseUrl(el.eUrl.value);

    var variantNames = Array.prototype.map.call(
      el.variantRows.querySelectorAll('.variant-name-input'),
      function (i) { return i.value.trim(); }
    ).filter(function (v) { return v !== ''; });

    function bad(msg) {
      el.expError.textContent = msg;
      el.expError.hidden = false;
    }

    if (!name) return bad('Give the experiment a name.');
    if (!goal) return bad('Enter a goal.');
    if (!audience) return bad('Enter an audience.');
    if (!conversionEvent) return bad('Enter a conversion event.');
    if (isNaN(traffic) || traffic < 1 || traffic > 100) return bad('Traffic must be between 1 and 100.');
    if (variantNames.length < 2) return bad('Add at least 2 variants with names.');

    // The page URL is optional on the API, but an experiment without one has
    // nothing to target, so the UI asks for it. Validate before sending so the
    // user gets the message next to the field rather than as a server error.
    var urlIssue = urlProblem(el.eUrl.value);
    if (urlIssue) {
      setUrlState('bad', urlIssue);
      el.eUrl.focus();
      return bad(urlIssue);
    }
    el.eUrl.value = pageUrl;

    state.busy = true;
    el.expSubmit.disabled = true;
    el.expSubmit.textContent = 'Creating…';
    el.expError.hidden = true;

    api('experiments.create', {
      method: 'POST',
      body: {
        name: name,
        kind: el.eKind.value,
        goal: goal,
        framework: el.eFramework.value,
        audience: audience,
        conversionEvent: conversionEvent,
        traffic: traffic,
        variantNames: variantNames,
        pageUrl: pageUrl,
        objective: el.eObjective.value.trim(),
        hypothesis: el.eHypothesis.value.trim(),
        observedInsight: el.eInsight.value.trim(),
        experienceChange: el.eChange.value.trim(),
        behaviorReason: el.eReason.value.trim()
      }
    })
      .then(function () {
        closeModal();
        toast('Experiment created.');
        loadExperiments();
      })
      .catch(function (err) {
        bad(err.message);
      })
      .then(function () {
        state.busy = false;
        el.expSubmit.disabled = false;
        el.expSubmit.textContent = 'Create experiment';
      });
  });

  // ---------------------------------------------------------------- boot
  // The session is an httpOnly cookie, so a refresh cannot read it from JS.
  // Ask the server who we are; that is what keeps a reload logged in.
  api('auth.me')
    .then(function (user) {
      if (user) showApp(user);
      else showAuth();
    })
    .catch(function () {
      showAuth();
    });

})();
