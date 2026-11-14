// Served at /tracker.js. Plain ES5 so it runs on any customer site.
// NOTE: no backticks or ${} inside this string.
export const trackerScript = String.raw`(function () {
  "use strict";
  var script = document.currentScript;
  if (!script) return;
  var project = script.getAttribute("data-project");
  if (!project) return;
  var base;
  try { base = new URL(script.src).origin; } catch (e) { return; }

  var KEY = "sf_visitor_id";
  function uid() {
    try { if (window.crypto && crypto.randomUUID) return crypto.randomUUID().replace(/-/g, ""); } catch (e) {}
    var s = "";
    for (var i = 0; i < 32; i++) s += Math.floor(Math.random() * 16).toString(16);
    return s;
  }
  var visitorId;
  try {
    visitorId = localStorage.getItem(KEY);
    if (!visitorId) { visitorId = uid(); localStorage.setItem(KEY, visitorId); }
  } catch (e) { visitorId = uid(); }

  function send(path, data) {
    data.project = project;
    data.visitorId = visitorId;
    return fetch(base + path, {
      method: "POST",
      mode: "cors",
      keepalive: true,
      headers: { "Content-Type": "text/plain" },
      body: JSON.stringify(data)
    }).then(function (r) { return r.json(); });
  }

  var api = {
    visitorId: visitorId,
    assignments: [],
    // Record a conversion for every experiment this visitor was exposed to.
    track: function (eventName) {
      return send("/api/t/convert", { event: eventName || "conversion" }).catch(function () {});
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

  send("/api/t/decide", { url: location.href }).then(function (res) {
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
`;
