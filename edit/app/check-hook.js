// Loaded first on the page the editor checks after a change goes live: it records the page
// engine's errors, so a page that doesn't draw is noticed (and the change put back).
(function () {
  var rec = (window.__tsCheck = { errors: [] });
  var orig = console.error;
  console.error = function () {
    try {
      var t = Array.prototype.map.call(arguments, function (a) { return a && a.message ? a.message : String(a); }).join(' ');
      if (/dc-runtime|render error|is not defined|Cannot read|undefined is not/i.test(t)) rec.errors.push(t.slice(0, 300));
    } catch (e) { /* */ }
    return orig.apply(console, arguments);
  };
  window.addEventListener('error', function (e) { if (e && e.message) rec.errors.push('error: ' + e.message); });
  window.addEventListener('unhandledrejection', function (e) {
    var r = e && e.reason;
    var m = r && r.message ? r.message : String(r);
    if (!/play\(\)|NotAllowedError|AbortError|The operation was aborted|Failed to fetch|Load failed|NetworkError/i.test(m)) rec.errors.push('rejection: ' + m);
  });
})();
