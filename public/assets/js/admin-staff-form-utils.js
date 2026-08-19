/**
 * MedConnect Admin — shared form utilities (password, validation, loading)
 */
(function (global) {
  'use strict';

  var EYE_OPEN = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
  var EYE_OFF = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

  function analyzePassword(password, minLength) {
    minLength = minLength || 12;
    var rules = {
      length: password.length >= minLength,
      upper: /[A-Z]/.test(password),
      lower: /[a-z]/.test(password),
      number: /[0-9]/.test(password),
      special: /[^A-Za-z0-9]/.test(password),
    };
    var met = Object.keys(rules).filter(function (k) { return rules[k]; }).length;
    var label = 'Weak';
    var level = 'weak';
    if (met >= 5) { label = 'Strong'; level = 'strong'; }
    else if (met >= 4) { label = 'Good'; level = 'good'; }
    else if (met >= 3) { label = 'Medium'; level = 'fair'; }
    return { rules: rules, label: label, level: level, met: met };
  }

  function setFieldError(input, message) {
    if (!input) return;
    var field = input.closest('.mc-field');
    var err = field ? field.querySelector('.mc-field__error') : null;
    input.classList.toggle('is-invalid', !!message);
    if (err) {
      err.textContent = message || '';
      err.classList.toggle('is-visible', !!message);
    }
  }

  function wrapPasswordInput(input, options) {
    if (!input || input.dataset.mcPwWrapped) return;
    options = options || {};
    var minLength = options.minLength || 12;
    var showStrength = options.showStrength !== false;
    var showToggle = options.showToggle !== false;

    input.dataset.mcPwWrapped = '1';
    var field = input.closest('.mc-field') || input.parentElement;

    if (showToggle) {
      var wrap = document.createElement('div');
      wrap.className = 'mc-password-wrap';
      input.parentNode.insertBefore(wrap, input);
      wrap.appendChild(input);

      var toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'mc-password-toggle';
      toggle.setAttribute('aria-label', 'Show password');
      toggle.innerHTML = EYE_OPEN;
      wrap.appendChild(toggle);

      toggle.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        toggle.innerHTML = show ? EYE_OFF : EYE_OPEN;
        toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      });
    }

    if (!showStrength) return;

    var strength = document.createElement('div');
    strength.className = 'mc-password-strength';
    strength.innerHTML = '<span class="mc-password-strength__label">Strength</span><div class="mc-password-strength__bars"><span class="mc-password-strength__bar"></span><span class="mc-password-strength__bar"></span><span class="mc-password-strength__bar"></span><span class="mc-password-strength__bar"></span><span class="mc-password-strength__bar"></span></div><span class="mc-password-strength__text"></span>';
    field.appendChild(strength);

    var rulesEl = document.createElement('ul');
    rulesEl.className = 'mc-password-rules';
    rulesEl.innerHTML = [
      '<li data-rule="length">Min. ' + minLength + ' characters</li>',
      '<li data-rule="upper">Uppercase letter</li>',
      '<li data-rule="lower">Lowercase letter</li>',
      '<li data-rule="number">Number</li>',
      '<li data-rule="special">Special character</li>',
    ].join('');
    field.appendChild(rulesEl);

    function refreshStrength() {
      var val = input.value || '';
      var analysis = analyzePassword(val, minLength);
      strength.className = 'mc-password-strength is-' + analysis.level;
      strength.querySelector('.mc-password-strength__text').textContent = val ? analysis.label : '';
      rulesEl.querySelectorAll('[data-rule]').forEach(function (li) {
        var key = li.getAttribute('data-rule');
        li.classList.toggle('is-met', !!analysis.rules[key]);
      });
    }

    input.addEventListener('input', refreshStrength);
    refreshStrength();
  }

  /** Collect form values including disabled fields (disabled inputs are omitted from FormData). */
  function buildFormData(form) {
    var fd = new FormData();
    if (!form) return fd;
    form.querySelectorAll('input, select, textarea').forEach(function (el) {
      if (!el.name || el.type === 'file' || el.type === 'button' || el.type === 'submit') return;
      if (el.type === 'checkbox') {
        if (el.checked) fd.append(el.name, el.value || '1');
        return;
      }
      if (el.type === 'radio') {
        if (el.checked) fd.append(el.name, el.value);
        return;
      }
      fd.append(el.name, el.value);
    });
    return fd;
  }

  function clearFieldErrors(form) {
    if (!form) return;
    form.querySelectorAll('.mc-field__input.is-invalid, input.is-invalid, select.is-invalid, textarea.is-invalid').forEach(function (el) {
      setFieldError(el, '');
    });
  }

  function bindFieldErrorClear(form) {
    if (!form || form.dataset.mcFieldClearBound === '1') return;
    form.dataset.mcFieldClearBound = '1';
    form.addEventListener('input', function (e) {
      var el = e.target;
      if (el && el.classList && el.classList.contains('is-invalid')) {
        setFieldError(el, '');
      }
    });
    form.addEventListener('change', function (e) {
      var el = e.target;
      if (el && el.classList && el.classList.contains('is-invalid')) {
        setFieldError(el, '');
      }
    });
  }

  function initPasswordConfirm(passwordInput, confirmInput) {
    if (!passwordInput || !confirmInput) return;

    var field = confirmInput.closest('.mc-field');
    var matchEl = document.createElement('div');
    matchEl.className = 'mc-password-match';
    matchEl.setAttribute('aria-live', 'polite');
    if (field) field.appendChild(matchEl);

    wrapPasswordInput(confirmInput, {
      minLength: parseInt(passwordInput.getAttribute('minlength') || '12', 10),
      showStrength: false,
    });

    function refreshMatch() {
      var p = passwordInput.value;
      var c = confirmInput.value;
      matchEl.className = 'mc-password-match';
      if (!c) return;
      if (p === c) {
        matchEl.classList.add('is-match');
        matchEl.textContent = '✔ Passwords match';
        setFieldError(confirmInput, '');
      } else {
        matchEl.classList.add('is-mismatch');
        matchEl.textContent = '✖ Passwords do not match';
        setFieldError(confirmInput, 'Passwords do not match.');
      }
    }

    passwordInput.addEventListener('input', refreshMatch);
    confirmInput.addEventListener('input', refreshMatch);
  }

  function passwordsMatch(passwordInput, confirmInput) {
    if (!confirmInput) return true;
    return passwordInput.value === confirmInput.value && passwordInput.value.length > 0;
  }

  function validatePasswordStrength(password, minLength) {
    var a = analyzePassword(password, minLength || 12);
    return a.met >= 5;
  }

  function validateEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || '').trim());
  }

  function validatePhone(value) {
    var digits = String(value || '').replace(/\D/g, '');
    if (!digits) return false;
    if (digits.length !== 11) return false;
    return /^09\d{9}$/.test(digits);
  }

  function phoneErrorMessage(value) {
    var digits = String(value || '').replace(/\D/g, '');
    if (!digits) return 'Contact number is required.';
    if (!/^\d+$/.test(digits)) return 'Phone number must contain digits only.';
    if (digits.length !== 11) return 'Phone number must be exactly 11 digits (e.g. 09171234567).';
    if (!/^09\d{9}$/.test(digits)) return 'Enter a valid Philippine mobile number starting with 09.';
    return '';
  }

  function setFormLoading(form, loading, submitBtn, loadingText) {
    if (!form) return;
    form.classList.toggle('is-loading', loading);
    form.querySelectorAll('input, select, textarea, button').forEach(function (el) {
      if (el.type === 'hidden') return;
      el.disabled = loading;
    });
    if (submitBtn) {
      submitBtn.classList.toggle('is-loading', loading);
      if (loading) {
        submitBtn.dataset.prevText = submitBtn.textContent;
        submitBtn.innerHTML = '<span class="mc-btn-spinner" aria-hidden="true"></span>' + (loadingText || 'Processing...');
      } else if (submitBtn.dataset.prevText) {
        submitBtn.textContent = submitBtn.dataset.prevText;
        submitBtn.classList.remove('is-loading');
      }
    }
  }

  function showFormAlert(el, message, type) {
    if (!el) return;
    el.textContent = message || '';
    el.className = 'mc-form-alert mc-form-alert--' + (type || 'error') + (message ? ' is-visible' : '');
  }

  function fileInputLabel(input) {
    if (!input || !input.files || !input.files[0]) return 'No file chosen';
    return input.files[0].name;
  }

  function syncFileInputDisplay(input) {
    if (!input) return;
    var nameEl = input.parentElement && input.parentElement.querySelector('.mc-file-upload__name');
    if (!nameEl) return;
    var empty = !input.files || !input.files.length;
    nameEl.textContent = fileInputLabel(input);
    nameEl.classList.toggle('is-empty', empty);
  }

  function enhanceFileInput(input) {
    if (!input || input.type !== 'file' || input.dataset.mcFileWrapped) return;
    input.dataset.mcFileWrapped = '1';
    input.classList.remove('mc-field__input');
    input.classList.add('mc-file-upload__input');

    var wrap = document.createElement('div');
    wrap.className = 'mc-file-upload';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var control = document.createElement('div');
    control.className = 'mc-file-upload__control';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'mc-file-upload__btn';
    btn.textContent = 'Choose File';

    var name = document.createElement('span');
    name.className = 'mc-file-upload__name is-empty';
    name.textContent = 'No file chosen';

    control.appendChild(btn);
    control.appendChild(name);
    wrap.appendChild(control);

    btn.addEventListener('click', function () {
      input.click();
    });

    input.addEventListener('change', function () {
      syncFileInputDisplay(input);
    });
  }

  function enhanceFileInputsIn(root) {
    if (!root) return;
    root.querySelectorAll('input[type="file"].mc-field__input, input[type="file"].mc-file-upload__input').forEach(enhanceFileInput);
    if (root.tagName === 'FORM' && !root.dataset.mcFileResetBound) {
      root.dataset.mcFileResetBound = '1';
      root.addEventListener('reset', function () {
        window.setTimeout(function () {
          root.querySelectorAll('.mc-file-upload__input').forEach(syncFileInputDisplay);
        }, 0);
      });
    }
  }

  global.MCStaffForm = {
    wrapPasswordInput: wrapPasswordInput,
    initPasswordConfirm: initPasswordConfirm,
    passwordsMatch: passwordsMatch,
    validatePasswordStrength: validatePasswordStrength,
    validateEmail: validateEmail,
    validatePhone: validatePhone,
    analyzePassword: analyzePassword,
    setFieldError: setFieldError,
    setFormLoading: setFormLoading,
    showFormAlert: showFormAlert,
    buildFormData: buildFormData,
    clearFieldErrors: clearFieldErrors,
    bindFieldErrorClear: bindFieldErrorClear,
    enhanceFileInput: enhanceFileInput,
    enhanceFileInputsIn: enhanceFileInputsIn,
    syncFileInputDisplay: syncFileInputDisplay,
  };

  var staffModalLockCount = 0;
  var staffModalScrollY = 0;

  function isStaffModalOpen(modal) {
    if (!modal) return false;
    var display = (modal.style && modal.style.display) || '';
    if (display === 'none') return false;
    if (display === 'flex' || display === 'block') return true;
    return window.getComputedStyle(modal).display !== 'none';
  }

  function lockStaffModalScroll() {
    if (staffModalLockCount === 0) {
      staffModalScrollY = window.scrollY || window.pageYOffset || 0;
      document.documentElement.classList.add('mc-staff-modal-open');
      document.body.classList.add('mc-staff-modal-open');
      document.body.style.top = '-' + staffModalScrollY + 'px';
    }
    staffModalLockCount += 1;
  }

  function unlockStaffModalScroll() {
    staffModalLockCount = Math.max(0, staffModalLockCount - 1);
    if (staffModalLockCount === 0) {
      document.documentElement.classList.remove('mc-staff-modal-open');
      document.body.classList.remove('mc-staff-modal-open');
      document.body.style.top = '';
      window.scrollTo(0, staffModalScrollY);
    }
  }

  function bindStaffModalShell(modal) {
    if (!modal || modal.dataset.mcStaffShellBound === '1') return;
    modal.dataset.mcStaffShellBound = '1';
    var locked = false;

    function sync() {
      var open = isStaffModalOpen(modal);
      if (open && !locked) {
        lockStaffModalScroll();
        locked = true;
        var scroller = modal.querySelector('.admin-modal-body, form.mc-staff-form');
        if (scroller) scroller.scrollTop = 0;
      } else if (!open && locked) {
        unlockStaffModalScroll();
        locked = false;
      }
    }

    var observer = new MutationObserver(sync);
    observer.observe(modal, { attributes: true, attributeFilter: ['style', 'class'] });
    sync();
  }

  function bindAllStaffModals() {
    document.querySelectorAll('.mc-staff-modal').forEach(bindStaffModalShell);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindAllStaffModals);
  } else {
    bindAllStaffModals();
  }
})(window);
