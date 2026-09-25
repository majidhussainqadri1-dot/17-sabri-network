(() => {
  'use strict';
  let lastModalTrigger = null;
  let lastInteractiveTrigger = null;
  const focusable = root => [...root.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[contenteditable="true"],[tabindex]:not([tabindex="-1"])')]
    .filter(el => !el.hidden && el.getAttribute('aria-hidden') !== 'true' && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
  const modal = () => document.getElementById('sn-two-plan-modal');
  const sntpModal = () => document.getElementById('sntp-modal');
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
    const interactive = event.target.closest?.('button,a[href],input,select,textarea,[role="button"],[tabindex]:not([tabindex="-1"])');
    if (interactive && !interactive.closest?.('#sntp-modal,#sn-two-plan-modal')) lastInteractiveTrigger = interactive;
    const opener = event.target.closest?.('[data-sn-modal]');
    if (opener) {
      lastModalTrigger = opener;
      window.setTimeout(focusModal, 0);
    }
    if (event.target.closest?.('[data-sn-close-modal],[data-sntp-close]')) window.setTimeout(restoreModalFocus, 0);
  }, true);

  document.addEventListener('keydown', event => {
    const node = modal();
    const alternate = sntpModal();
    if ((!node || node.hidden) && !alternate) return;
    if (event.key === 'Escape') {
      window.setTimeout(restoreModalFocus, 0);
      return;
    }
    if (!node || node.hidden || event.key !== 'Tab') return;
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
  const observer = new MutationObserver(records => records.forEach(record => {
    record.addedNodes.forEach(node => {
      if (node.nodeType !== 1) return;
      syncSearchA11y(node);
      if (node.id === 'sntp-modal' || node.querySelector?.('#sntp-modal')) {
        if (!lastModalTrigger && lastInteractiveTrigger && document.contains(lastInteractiveTrigger)) lastModalTrigger = lastInteractiveTrigger;
      }
    });
    record.removedNodes.forEach(node => {
      if (node.nodeType !== 1) return;
      if (node.id === 'sntp-modal' || node.querySelector?.('#sntp-modal')) window.setTimeout(restoreModalFocus, 0);
    });
  }));
  observer.observe(document.documentElement, {subtree:true, childList:true});
})();
