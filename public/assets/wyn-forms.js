/*!
 * wyn-forms.js: optional helper for forms that post to Wyn Forms.
 * Add data-wyn-form to a <form>. It then:
 *   - adds the hidden honeypot field (wyn-hp) if it's missing
 *   - records how long the visitor spent on the page (wyn-elapsed), which catches instant bot posts
 *   - with data-wyn-ajax: sends without leaving the page and shows the result
 * Works without this file too; it only makes spam filtering stronger and submits smoother.
 *
 * This file alone is MIT licensed, so you can embed it on any website.
 */
(function () {
  'use strict';
  var wynStart = Date.now();

  function wynHidden(form, name, value) {
    var input = form.querySelector('input[name="' + name + '"]');
    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      form.appendChild(input);
    }
    input.value = value;
    return input;
  }

  function wynHoneypot(form) {
    if (form.querySelector('[name="wyn-hp"]')) return;
    var wrap = document.createElement('div');
    wrap.setAttribute('aria-hidden', 'true');
    wrap.style.cssText = 'position:absolute!important;left:-10000px!important;width:1px;height:1px;overflow:hidden';
    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'wyn-hp';
    input.tabIndex = -1;
    input.autocomplete = 'off';
    wrap.appendChild(input);
    form.appendChild(wrap);
  }

  function wynStatus(form, text, ok) {
    var box = form.querySelector('[data-wyn-status]');
    if (!box) {
      box = document.createElement('p');
      box.setAttribute('data-wyn-status', '');
      box.setAttribute('role', 'status');
      form.appendChild(box);
    }
    box.textContent = text;
    box.setAttribute('data-wyn-ok', ok ? 'true' : 'false');
  }

  function wynInit(form) {
    wynHoneypot(form);
    form.addEventListener('submit', function (event) {
      wynHidden(form, 'wyn-elapsed', ((Date.now() - wynStart) / 1000).toFixed(1));
      if (!form.hasAttribute('data-wyn-ajax') || !window.fetch) return;

      event.preventDefault();
      var button = form.querySelector('[type="submit"]');
      if (button) button.disabled = true;
      wynStatus(form, form.getAttribute('data-wyn-sending') || 'Sending…', true);

      fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } })
        .then(function (res) { return res.json().catch(function () { return { ok: false, message: 'Something went wrong. Try again.' }; }); })
        .then(function (data) {
          if (data.ok) {
            form.reset();
            wynStatus(form, form.getAttribute('data-wyn-success') || data.message, true);
            form.dispatchEvent(new CustomEvent('wyn:success', { detail: data }));
          } else {
            wynStatus(form, data.message || 'Something went wrong. Try again.', false);
            form.dispatchEvent(new CustomEvent('wyn:error', { detail: data }));
          }
        })
        .catch(function () { wynStatus(form, 'Couldn’t reach the server. Check your connection and try again.', false); })
        .then(function () { if (button) button.disabled = false; });
    });
  }

  function wynBoot() {
    var forms = document.querySelectorAll('form[data-wyn-form]');
    for (var i = 0; i < forms.length; i++) wynInit(forms[i]);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', wynBoot);
  else wynBoot();
})();
