/**
 * MedConnect — Philippine mobile phone validation (11 digits, 09XXXXXXXXX).
 */
(function () {
  'use strict';

  var PHONE_PATTERN = /^09\d{9}$/;
  var MAX_LENGTH = 11;

  var ERRORS = {
    required: 'Contact number is required.',
    length: 'Contact number must be exactly 11 digits.',
    format: 'Enter a valid Philippine mobile number starting with 09.',
    digits: 'Phone number must contain digits only.',
  };

  function normalizeDigits(value) {
    return String(value || '').replace(/\D/g, '');
  }

  function validatePhone(value) {
    var digits = normalizeDigits(value);
    if (!digits) return ERRORS.required;
    if (!/^\d+$/.test(digits)) return ERRORS.digits;
    if (digits.length !== MAX_LENGTH) return ERRORS.length;
    if (!PHONE_PATTERN.test(digits)) return ERRORS.format;
    return '';
  }

  function bindPhoneInput(input, options) {
    if (!input || input.dataset.phoneBound === '1') return;
    options = options || {};
    input.dataset.phoneBound = '1';
    input.setAttribute('inputmode', 'numeric');
    input.setAttribute('maxlength', String(MAX_LENGTH));
    input.setAttribute('pattern', '09[0-9]{9}');
    input.setAttribute('autocomplete', 'tel');
    if (!input.getAttribute('type') || input.getAttribute('type') === 'text') {
      input.setAttribute('type', 'tel');
    }

    input.addEventListener('input', function () {
      var digits = normalizeDigits(input.value).slice(0, MAX_LENGTH);
      if (input.value !== digits) {
        input.value = digits;
      }
      if (options.onInput) options.onInput(digits);
      if (options.liveValidate && options.errorEl) {
        var err = digits.length === 0 ? '' : validatePhone(digits);
        options.errorEl.textContent = err;
        input.classList.toggle('invalid', !!err);
      }
    });

    input.addEventListener('keypress', function (e) {
      if (e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return;
      if (!/\d/.test(e.key)) e.preventDefault();
      if (normalizeDigits(input.value).length >= MAX_LENGTH) e.preventDefault();
    });

    input.addEventListener('paste', function (e) {
      e.preventDefault();
      var pasted = (e.clipboardData || window.clipboardData).getData('text') || '';
      input.value = normalizeDigits(pasted).slice(0, MAX_LENGTH);
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });

    if (options.errorEl) {
      input.addEventListener('blur', function () {
        var err = validatePhone(input.value);
        options.errorEl.textContent = err;
        input.classList.toggle('invalid', !!err);
      });
    }
  }

  var PHONE_SELECTORS = [
    '[data-phone-input]',
    'input[type="tel"]',
    '#contact-number',
    '#contact_number',
    '#phone',
    '#pf_phone',
    '#bhwPhone',
    '#reg_contact',
    '#reg_ec_phone',
    '#f_contact',
    'input[name="contact_number"]',
    'input[name="phone"]',
    'input[name="emergency_contact_phone"]',
  ].join(',');

  function bindAllPhoneInputs(root) {
    root = root || document;
    root.querySelectorAll(PHONE_SELECTORS).forEach(function (el) {
      if (el.tagName !== 'INPUT') return;
      var errId = el.getAttribute('aria-describedby') || (el.id ? el.id + '-error' : '');
      var errEl = errId ? document.getElementById(errId) : null;
      bindPhoneInput(el, { errorEl: errEl });
    });
  }

  window.MCPhoneValidation = {
    PHONE_PATTERN: PHONE_PATTERN,
    MAX_LENGTH: MAX_LENGTH,
    ERRORS: ERRORS,
    normalizeDigits: normalizeDigits,
    validatePhone: validatePhone,
    bindPhoneInput: bindPhoneInput,
    bindAllPhoneInputs: bindAllPhoneInputs,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { bindAllPhoneInputs(); });
  } else {
    bindAllPhoneInputs();
  }
})();
