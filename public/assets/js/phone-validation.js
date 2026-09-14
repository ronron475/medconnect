/**
 * medConnect — Philippine mobile + email validation (client-side).
 * Canonical mobile: 09XXXXXXXXX. Also accepts +639 / 639 / 9XXXXXXXXX and normalizes.
 */
(function () {
  'use strict';

  var PHONE_PATTERN = /^09\d{9}$/;
  var MAX_LENGTH = 11;
  var GMAIL_PATTERN = /^[A-Za-z0-9._%+\-]+@gmail\.com$/i;
  var EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  var ERRORS = {
    phoneRequired: 'Contact number is required.',
    phoneLength: 'Phone number must be exactly 11 digits (e.g. 09171234567).',
    phoneFormat: 'Please enter a valid Philippine mobile number.',
    phoneDigits: 'Phone number must contain digits only.',
    emailRequired: 'Email address is required.',
    emailInvalid: 'Please enter a valid email address.',
    gmailInvalid: 'Please enter a valid Gmail address.',
  };

  function digitsOnly(value) {
    return String(value || '').replace(/\D/g, '');
  }

  /** Normalize to 09XXXXXXXXX when possible. */
  function canonicalPhone(value) {
    var digits = digitsOnly(value);
    if (/^639\d{9}$/.test(digits)) {
      return '0' + digits.slice(2);
    }
    if (/^9\d{9}$/.test(digits) && digits.length === 10) {
      return '0' + digits;
    }
    return digits;
  }

  function validatePhone(value, required) {
    if (required === undefined) required = true;
    var raw = String(value || '').trim();
    if (!raw) return required ? ERRORS.phoneRequired : '';
    var canonical = canonicalPhone(raw);
    if (!canonical) return ERRORS.phoneDigits;
    if (!/^\d+$/.test(canonical)) return ERRORS.phoneDigits;
    if (canonical.length !== MAX_LENGTH) return ERRORS.phoneLength;
    if (!PHONE_PATTERN.test(canonical)) return ERRORS.phoneFormat;
    return '';
  }

  function isValidPhone(value) {
    return validatePhone(value, true) === '';
  }

  function normalizeEmail(value) {
    return String(value || '').trim().toLowerCase();
  }

  function validateEmail(value, required) {
    if (required === undefined) required = true;
    var email = String(value || '').trim();
    if (!email) return required ? ERRORS.emailRequired : '';
    if (/\s/.test(email) || !EMAIL_PATTERN.test(email)) return ERRORS.emailInvalid;
    return '';
  }

  function isValidEmail(value) {
    return validateEmail(value, true) === '';
  }

  function validateGmail(value, required) {
    if (required === undefined) required = true;
    var email = String(value || '').trim();
    if (!email) return required ? ERRORS.emailRequired : '';
    if (!isValidEmail(email) || !GMAIL_PATTERN.test(email)) return ERRORS.gmailInvalid;
    return '';
  }

  function isValidGmail(value) {
    return validateGmail(value, true) === '';
  }

  function findErrorEl(input) {
    if (!input) return null;
    var errId = input.getAttribute('aria-describedby') || (input.id ? input.id + '-error' : '');
    if (errId) {
      var byId = document.getElementById(errId.split(/\s+/)[0]);
      if (byId) return byId;
    }
    var next = input.nextElementSibling;
    if (next && (next.classList.contains('field-error') || next.classList.contains('mc-field__error') || next.classList.contains('invalid-feedback'))) {
      return next;
    }
    var wrap = input.closest('.mc-field, .form-group, .bhw-field, .setup-group, .ps-field');
    if (wrap) {
      return wrap.querySelector('.field-error, .mc-field__error, .invalid-feedback, [data-field-error]');
    }
    return null;
  }

  function setInputError(input, message) {
    if (!input) return;
    var errEl = findErrorEl(input);
    if (errEl) {
      errEl.textContent = message || '';
      errEl.hidden = !message;
      errEl.style.display = message ? '' : 'none';
    }
    input.classList.toggle('invalid', !!message);
    input.classList.toggle('is-invalid', !!message);
    input.setAttribute('aria-invalid', message ? 'true' : 'false');
  }

  function bindPhoneInput(input, options) {
    if (!input || input.dataset.phoneBound === '1') return;
    options = options || {};
    input.dataset.phoneBound = '1';
    input.setAttribute('inputmode', 'numeric');
    input.setAttribute('autocomplete', 'tel');
    if (!input.getAttribute('type') || input.getAttribute('type') === 'text') {
      input.setAttribute('type', 'tel');
    }
    // Allow typing/paste of +639 then normalize; display stays 11-digit 09…
    input.setAttribute('maxlength', '16');
    input.setAttribute('pattern', '^(09|\\+639|639)?\\d{0,11}$');

    function applyNormalized() {
      var canonical = canonicalPhone(input.value).slice(0, MAX_LENGTH);
      if (input.value !== canonical) {
        input.value = canonical;
      }
      return canonical;
    }

    input.addEventListener('input', function () {
      var digits = digitsOnly(input.value);
      // Live-normalize complete +639 / 639 forms
      if (/^639\d{9}$/.test(digits) || (/^9\d{9}$/.test(digits) && digits.length === 10) || /^09\d{0,9}$/.test(digits)) {
        applyNormalized();
      } else if (digits.length > MAX_LENGTH + 1) {
        applyNormalized();
      } else {
        // Keep only digits and optional leading + while typing international
        var cleaned = String(input.value || '').replace(/[^\d+]/g, '');
        if (cleaned.indexOf('+') > 0) cleaned = cleaned.replace(/\+/g, '');
        if (input.value !== cleaned) input.value = cleaned;
      }
      if (options.onInput) options.onInput(canonicalPhone(input.value));
      if (options.liveValidate) {
        var err = canonicalPhone(input.value).length === 0 ? '' : validatePhone(input.value, false);
        setInputError(input, err);
        if (options.errorEl) options.errorEl.textContent = err;
      }
    });

    input.addEventListener('blur', function () {
      applyNormalized();
      var err = validatePhone(input.value, input.required || options.required);
      setInputError(input, err);
      if (options.errorEl) options.errorEl.textContent = err;
      if (options.onBlur) options.onBlur(err);
    });

    input.addEventListener('paste', function (e) {
      e.preventDefault();
      var pasted = (e.clipboardData || window.clipboardData).getData('text') || '';
      input.value = canonicalPhone(pasted).slice(0, MAX_LENGTH);
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });
  }

  function bindEmailInput(input, options) {
    if (!input || input.dataset.emailBound === '1') return;
    options = options || {};
    input.dataset.emailBound = '1';
    if (!input.getAttribute('type') || input.getAttribute('type') === 'text') {
      input.setAttribute('type', 'email');
    }
    input.setAttribute('autocomplete', input.getAttribute('autocomplete') || 'email');
    input.setAttribute('spellcheck', 'false');

    function check() {
      var gmailOnly = options.gmailOnly || input.getAttribute('data-email-gmail') === '1';
      var err = gmailOnly
        ? validateGmail(input.value, input.required || options.required)
        : validateEmail(input.value, input.required || options.required);
      setInputError(input, err);
      if (options.errorEl) options.errorEl.textContent = err;
      return err;
    }

    input.addEventListener('blur', check);
    input.addEventListener('input', function () {
      // Strip leading/trailing spaces as user types (not mid-local-part)
      var v = String(input.value || '');
      var trimmed = v.replace(/^\s+/, '');
      if (trimmed !== v) input.value = trimmed;
      if (options.liveValidate) check();
    });
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
    'input[name="mobile_number"]',
  ].join(',');

  var EMAIL_SELECTORS = [
    '[data-email-input]',
    'input[type="email"]:not([readonly])',
    'input[name="email"]:not([readonly])',
  ].join(',');

  function bindAllPhoneInputs(root) {
    root = root || document;
    root.querySelectorAll(PHONE_SELECTORS).forEach(function (el) {
      if (el.tagName !== 'INPUT' || el.readOnly || el.disabled) return;
      bindPhoneInput(el, { errorEl: findErrorEl(el), required: el.required });
    });
  }

  function bindAllEmailInputs(root) {
    root = root || document;
    root.querySelectorAll(EMAIL_SELECTORS).forEach(function (el) {
      if (el.tagName !== 'INPUT' || el.readOnly || el.disabled) return;
      bindEmailInput(el, {
        errorEl: findErrorEl(el),
        required: el.required,
        gmailOnly: el.getAttribute('data-email-gmail') === '1',
      });
    });
  }

  function bindAll(root) {
    bindAllPhoneInputs(root);
    bindAllEmailInputs(root);
  }

  /** Validate a form's bound phone/email fields; returns false if any invalid. */
  function validateForm(form) {
    if (!form) return true;
    var ok = true;
    form.querySelectorAll(PHONE_SELECTORS).forEach(function (el) {
      if (el.tagName !== 'INPUT' || el.readOnly || el.disabled) return;
      if (!el.required && !String(el.value || '').trim()) {
        setInputError(el, '');
        return;
      }
      var err = validatePhone(el.value, el.required);
      setInputError(el, err);
      if (err) ok = false;
      else el.value = canonicalPhone(el.value);
    });
    form.querySelectorAll(EMAIL_SELECTORS).forEach(function (el) {
      if (el.tagName !== 'INPUT' || el.readOnly || el.disabled) return;
      if (!el.required && !String(el.value || '').trim()) {
        setInputError(el, '');
        return;
      }
      var gmailOnly = el.getAttribute('data-email-gmail') === '1';
      var err = gmailOnly ? validateGmail(el.value, el.required) : validateEmail(el.value, el.required);
      setInputError(el, err);
      if (err) ok = false;
      else el.value = normalizeEmail(el.value);
    });
    return ok;
  }

  var api = {
    PHONE_PATTERN: PHONE_PATTERN,
    MAX_LENGTH: MAX_LENGTH,
    ERRORS: ERRORS,
    normalizeDigits: digitsOnly,
    canonicalPhone: canonicalPhone,
    validatePhone: validatePhone,
    isValidPhone: isValidPhone,
    normalizeEmail: normalizeEmail,
    validateEmail: validateEmail,
    isValidEmail: isValidEmail,
    validateGmail: validateGmail,
    isValidGmail: isValidGmail,
    bindPhoneInput: bindPhoneInput,
    bindEmailInput: bindEmailInput,
    bindAllPhoneInputs: bindAllPhoneInputs,
    bindAllEmailInputs: bindAllEmailInputs,
    bindAll: bindAll,
    validateForm: validateForm,
    setInputError: setInputError,
  };

  window.MCPhoneValidation = api;
  window.MCContactValidation = api;

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.getAttribute('data-mc-skip-contact-validate') === '1') return;
    if (!form.querySelector(PHONE_SELECTORS) && !form.querySelector(EMAIL_SELECTORS)) return;
    if (!validateForm(form)) {
      e.preventDefault();
      e.stopPropagation();
      var firstInvalid = form.querySelector('.invalid, .is-invalid');
      if (firstInvalid && typeof firstInvalid.focus === 'function') {
        try { firstInvalid.focus(); } catch (err) {}
      }
    }
  }, true);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { bindAll(); });
  } else {
    bindAll();
  }
})();
