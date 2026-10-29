/* Wyn Forms dashboard: copy buttons, select-all, confirmations. Every page works without it. */
(() => {
  'use strict';
  const wynAll = (s, el = document) => [...el.querySelectorAll(s)];

  const wynCopy = async (button, text) => {
    try {
      await navigator.clipboard.writeText(text);
      const label = button.textContent;
      button.textContent = 'Copied';
      setTimeout(() => { button.textContent = label; }, 1400);
    } catch {
      button.textContent = 'Press Ctrl+C';
    }
  };

  wynAll('[data-wyn-copy]').forEach((b) => b.addEventListener('click', () => {
    const target = document.querySelector(b.dataset.wynCopy);
    target.select?.();
    wynCopy(b, target.value ?? target.textContent);
  }));
  wynAll('[data-wyn-copy-next]').forEach((b) => b.addEventListener('click', () => {
    wynCopy(b, b.nextElementSibling.textContent);
  }));

  wynAll('[data-wyn-select-all]').forEach((all) => all.addEventListener('change', () => {
    wynAll('input[name="ids[]"]').forEach((box) => { box.checked = all.checked; });
  }));

  wynAll('form[data-wyn-confirm]').forEach((f) => f.addEventListener('submit', (e) => {
    if (!confirm(f.dataset.wynConfirm)) e.preventDefault();
  }));
})();
