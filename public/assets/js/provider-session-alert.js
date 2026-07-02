(function () {
  'use strict';

  const modal = document.getElementById('providerSessionAlert');
  const messageEl = document.getElementById('providerSessionAlertMessage');
  const closeBtn = document.getElementById('providerSessionAlertClose');
  const okBtn = document.getElementById('providerSessionAlertOk');

  if (!modal || !messageEl) return;

  function closeProviderSessionAlert() {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  window.openProviderSessionAlert = function openProviderSessionAlert(message) {
    messageEl.textContent = message || 'This session cannot be opened right now.';
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    okBtn?.focus();
  };

  document.querySelectorAll('.queue-open-session-blocked').forEach((btn) => {
    btn.addEventListener('click', () => {
      openProviderSessionAlert(btn.dataset.reason || 'This session cannot be opened right now.');
    });
  });

  closeBtn?.addEventListener('click', closeProviderSessionAlert);
  okBtn?.addEventListener('click', closeProviderSessionAlert);
  modal.addEventListener('click', (event) => {
    if (event.target === modal) closeProviderSessionAlert();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal.classList.contains('is-open')) {
      closeProviderSessionAlert();
    }
  });
})();
