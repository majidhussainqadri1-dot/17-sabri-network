(() => {
  'use strict';
  let lastModalTrigger = null;
  const focusable = root => [...root.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')]
    .filter(el => !el.hidden && el.getAttribute('aria-hidden') !== 'true' && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
  const modal = () => document.getElementById('sn-two-plan-modal');
  const restoreModalFocus = () => {
    const target = lastModalTrigger;
    lastModalTrigger = null;
    if (target && document.contains(target) && typeof target.focus === 'function') target.focus({preventScroll:true});
  };
  const focusModal = () => {
    const node = modal();
    if (!node || node.hidden) return;
    if (!node.hasAttribute('aria-modal')) node.setAttribute('aria-modal','true');
    if (!node.hasAttribute('role')) node.setAttribute('role','dialog');
    const items = focusable(node);
    (items[0] || node).focus?.({preventScroll:true});
  };

  document.addEventListener('click', event => {
    const opener = event.target.closest?.('[data-sn-modal]');
    if (opener) {
      lastModalTrigger = opener;
      window.setTimeout(focusModal, 0);
    }
    if (event.target.closest?.('[data-sn-close-modal]')) window.setTimeout(restoreModalFocus, 0);
  }, true);

  document.addEventListener('keydown', event => {
    const node = modal();
    if (!node || node.hidden) return;
    if (event.key === 'Escape') {
      window.setTimeout(restoreModalFocus, 0);
      return;
    }
    if (event.key !== 'Tab') return;
    const items = focusable(node);
    if (!items.length) {
      event.preventDefault();
      node.focus?.();
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
  const observer = new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
    if (node.nodeType === 1) syncSearchA11y(node);
  })));
  observer.observe(document.documentElement, {subtree:true, childList:true});
})();
