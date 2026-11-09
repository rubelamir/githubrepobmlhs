/**
 * Accounting module UI helpers (loaded only on dist/pages/accounts/*).
 *
 * - <form data-acc-confirm="message"> asks for confirmation in a Bootstrap modal.
 *   Optional: data-acc-confirm-title, data-acc-confirm-ok, data-acc-confirm-variant (danger|primary|success).
 * - Every form is locked after the first submit so a double click cannot post twice.
 *   The server still enforces idempotency; this only spares the user a confusing error.
 */
(() => {
  'use strict';

  let modal = null;
  let pending = null;

  const buildModal = () => {
    const wrapper = document.createElement('div');
    wrapper.className = 'modal fade';
    wrapper.tabIndex = -1;
    wrapper.setAttribute('aria-hidden', 'true');
    wrapper.innerHTML = `
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" data-acc-modal-title></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="বন্ধ করুন"></button>
          </div>
          <div class="modal-body" data-acc-modal-body></div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">বাতিল</button>
            <button type="button" class="btn" data-acc-modal-ok></button>
          </div>
        </div>
      </div>`;
    document.body.append(wrapper);
    wrapper.querySelector('[data-acc-modal-ok]').addEventListener('click', () => {
      const current = pending;
      pending = null;
      modal.hide();
      if (current) {
        current.form.dataset.accConfirmed = '1';
        current.form.requestSubmit(current.submitter || undefined);
      }
    });
    wrapper.addEventListener('hidden.bs.modal', () => {
      pending = null;
    });

    return { element: wrapper, instance: new bootstrap.Modal(wrapper) };
  };

  const confirmSubmit = (form, submitter) => {
    if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
      return window.confirm(form.dataset.accConfirm);
    }
    if (!modal) {
      const built = buildModal();
      modal = built.instance;
      modal.element = built.element;
    }
    const variant = form.dataset.accConfirmVariant || 'primary';
    modal.element.querySelector('[data-acc-modal-title]').textContent = form.dataset.accConfirmTitle || 'নিশ্চিত করুন';
    modal.element.querySelector('[data-acc-modal-body]').textContent = form.dataset.accConfirm;
    const ok = modal.element.querySelector('[data-acc-modal-ok]');
    ok.className = `btn btn-${variant}`;
    ok.textContent = form.dataset.accConfirmOk || 'হ্যাঁ, নিশ্চিত';
    pending = { form, submitter };
    modal.show();

    return false;
  };

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') {
      return;
    }
    if (form.dataset.accSubmitting === '1') {
      event.preventDefault();
      return;
    }
    if (form.dataset.accConfirm && form.dataset.accConfirmed !== '1') {
      if (!confirmSubmit(form, event.submitter)) {
        event.preventDefault();
        return;
      }
    }
    form.dataset.accSubmitting = '1';
    // Disable after the browser has captured the submitter's name/value.
    setTimeout(() => {
      form.querySelectorAll('button[type="submit"], button:not([type])').forEach((button) => {
        button.disabled = true;
      });
    }, 0);
  });

  // bfcache restores a locked form when the user navigates back.
  window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-acc-submitting="1"]').forEach((form) => {
      delete form.dataset.accSubmitting;
      delete form.dataset.accConfirmed;
      form.querySelectorAll('button').forEach((button) => {
        button.disabled = false;
      });
    });
  });
})();
