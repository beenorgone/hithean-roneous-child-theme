(function () {
  'use strict';

  var frame = document.getElementById('yh-signup-frame');
  var instruction = document.querySelector('.yh-form-instruction strong');
  if (!frame) return;

  document.querySelectorAll('.yh-partner a[href="#partnership-contact"]').forEach(function (link) {
    link.addEventListener('click', function () {
      if (Array.isArray(window.dataLayer)) {
        window.dataLayer.push({ event: 'yh_partner_cta_click', section: (link.closest('section') || {}).id || 'product', intent: link.getAttribute('data-yh-intent') || 'general' });
      }
    });
  });

  // The existing IVAR signup endpoint only accepts the allowlisted src=hithean.
  // Show the chosen intent as an instruction for its note field; do not pretend
  // a cross-origin iframe can be prefilled from this page.
  document.querySelectorAll('[data-yh-intent]').forEach(function (link) {
    link.addEventListener('click', function () {
      if (instruction) {
        instruction.textContent = 'Yeast Hero — ' + link.getAttribute('data-yh-intent');
      }
    });
  });

  window.addEventListener('message', function (event) {
    if (event.origin !== 'https://ivarvietnam.com' || event.source !== frame.contentWindow) return;
    var height = event.data && event.data.ivarCrmSignupHeight;
    if (typeof height !== 'number' || !Number.isFinite(height)) return;
    frame.style.height = Math.max(520, Math.min(1600, Math.ceil(height) + 12)) + 'px';
  });
})();
