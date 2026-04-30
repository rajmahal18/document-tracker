(function () {
  const pwd = document.getElementById('password');
  const hint = document.getElementById('capsHint');
  if (!pwd || !hint) return;

  const syncCapsHint = function (e) {
    const isOn = !!(e && typeof e.getModifierState === 'function' && e.getModifierState('CapsLock'));
    hint.hidden = !isOn;
  };

  ['keydown', 'keyup'].forEach(function (evt) {
    pwd.addEventListener(evt, syncCapsHint);
  });
  pwd.addEventListener('blur', function () { hint.hidden = true; });
})();
