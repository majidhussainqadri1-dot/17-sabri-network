(() => {
  'use strict';
  let lastModalTrigger = null;
  const focusable = root => [...root.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')]
    .filter(el => !el.hidden && el.getAttribute('aria-hidden') !== 'true' && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
  const modal = () => document.getElementById('sntp-modal');
  const restoreModalFocus = () => {
    const target = lastModalTrigger;
    lastModalTrigger = null;
    if (target && document.contains(target) && typeof target.focus === 'function') target.focus({preventScroll:true});
  };
  const focusModal = () => {
    const node = modal();
    if (!node) return;
    const dialog = node.querySelector('[role="dialog"]') || node;
    if (!dialog.hasAttribute('aria-modal')) dialog.setAttribute('aria-modal','true');
    const items = focusable(dialog);
    (items[0] || dialog).focus?.({preventScroll:true});
  };

  // The two-plan UI creates #sntp-modal dynamically. Capture the invoking control
  // before its click handler creates the dialog, and restore that focus after every
  // close path (close button, backdrop, Escape or programmatic successful submit).
  document.addEventListener('click', event => {
    const node = modal();
    if (!node) {
      const opener = event.target.closest?.('button,a[href],[role="button"],[tabindex]:not([tabindex="-1"])');
      if (opener) {
        lastModalTrigger = opener;
        window.setTimeout(focusModal, 0);
      }
      return;
    }
    if (event.target.closest?.('[data-sntp-close]')) window.setTimeout(restoreModalFocus, 0);
  }, true);

  document.addEventListener('keydown', event => {
    const node = modal();
    if (!node) return;
    if (event.key === 'Escape') {
      window.setTimeout(restoreModalFocus, 0);
      return;
    }
    if (event.key !== 'Tab') return;
    const dialog = node.querySelector('[role="dialog"]') || node;
    const items = focusable(dialog);
    if (!items.length) {
      event.preventDefault();
      dialog.focus?.();
      return;
    }
    const first = items[0], last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault(); last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault(); first.focus();
    }
  }, true);

  const syncSearchA11y = root => {
    (root || document).querySelectorAll?.('[aria-controls="snm-message-search-panel"]').forEach(trigger => {
      if (!trigger.hasAttribute('aria-expanded')) trigger.setAttribute('aria-expanded','false');
    });
  };
  syncSearchA11y(document);
  const observer = new MutationObserver(records => records.forEach(record => {
    record.addedNodes.forEach(node => {
      if (node.nodeType === 1) syncSearchA11y(node);
    });
    record.removedNodes.forEach(node => {
      if (node.nodeType !== 1) return;
      if (node.id === 'sntp-modal' || node.querySelector?.('#sntp-modal')) window.setTimeout(restoreModalFocus, 0);
    });
  }));
  observer.observe(document.documentElement, {subtree:true, childList:true});
})();
