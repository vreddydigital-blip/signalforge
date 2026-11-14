(function () {
  "use strict";
  var script = document.currentScript;
  if (!script) return;
  var project = script.getAttribute("data-project");
  if (!project) return;
  var src = script.src || "";
  var idx = src.indexOf("tracker.js");
  if (idx < 0) return;
  var base = src.slice(0, idx); // folder that contains tracker.js and api/

  // "?i=1" asks InfinityFree to skip its browser-check page for these background requests.
  function endpoint(action) { return base + "api/track.php?i=1&a=" + action; }

  var KEY = "sf_visitor_id";
  function uid() {
    try { if (window.crypto && crypto.randomUUID) return crypto.randomUUID().replace(/-/g, ""); } catch (e) {}
    var s = "";
    for (var i = 0; i < 32; i++) s += Math.floor(Math.random() * 16).toString(16);
    return s;
  }
  var visitorId;
  var returning = false;
  try {
    visitorId = localStorage.getItem(KEY);
    returning = Boolean(visitorId);
    if (!visitorId) { visitorId = uid(); localStorage.setItem(KEY, visitorId); }
  } catch (e) { visitorId = uid(); }

  // Non-identifying context used for segment readouts (device, paid click, repeat visit).
  var device = /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent || "") ? "Mobile" : "Desktop";
  var paid = false;
  try {
    var params = new URLSearchParams(location.search);
    var medium = (params.get("utm_medium") || "").toLowerCase();
    paid = Boolean(params.get("gclid") || params.get("fbclid") || params.get("msclkid")) || /^(cpc|ppc|paid|paidsearch|paid_social|display|banner)$/.test(medium);
  } catch (e) {}

  function send(action, data) {
    data.project = project;
    data.visitorId = visitorId;
    return fetch(endpoint(action), {
      method: "POST",
      mode: "cors",
      keepalive: true,
      headers: { "Content-Type": "text/plain" },
      body: JSON.stringify(data)
    }).then(function (r) { return r.json(); }).then(function (r) { return r.data; });
  }

  var api = {
    visitorId: visitorId,
    assignments: [],
    // Record a conversion for every experiment this visitor was exposed to.
    track: function (eventName) {
      return send("convert", { event: eventName || "conversion" }).catch(function () {});
    },
    getVariant: function (experimentId) {
      for (var i = 0; i < api.assignments.length; i++) {
        if (api.assignments[i].experimentId === experimentId) return api.assignments[i];
      }
      return null;
    }
  };
  window.SignalForge = api;

  function apply(a) {
    var root = document.documentElement;
    root.classList.add("sf-exp-" + a.experimentId);
    root.classList.add("sf-exp-" + a.experimentId + "-v" + a.variantId);
    root.setAttribute("data-sf-exp-" + a.experimentId, a.variantName);
    if (a.css) {
      var style = document.createElement("style");
      style.setAttribute("data-signalforge", String(a.experimentId));
      style.textContent = a.css;
      (document.head || root).appendChild(style);
    }
    if (a.js) {
      try { (new Function(a.js))(); } catch (e) { console.warn("[SignalForge] variant script failed", e); }
    }
  }

  send("decide", { url: location.href, device: device, paid: paid, returning: returning }).then(function (res) {
    api.assignments = (res && res.assignments) || [];
    api.assignments.forEach(apply);
    document.dispatchEvent(new CustomEvent("signalforge:ready", { detail: api }));
  }).catch(function () {});

  // Any element with data-sf-conversion counts as a conversion when clicked.
  document.addEventListener("click", function (e) {
    var el = e.target && e.target.closest ? e.target.closest("[data-sf-conversion]") : null;
    if (el) api.track(el.getAttribute("data-sf-conversion") || "conversion");
  }, true);
})();
