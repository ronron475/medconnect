/**
 * Philippine National ID OCR — client preprocessing, extraction, and auto-fill.
 */
(function (global) {
  'use strict';

  const CACHE_PREFIX = 'medconnect_ocr_extract_';
  const OCR_FIELD_IDS = ['first-name', 'middle-name', 'last-name', 'dob', 'national-id', 'street-address'];
  const FILL_ORDER = [
    { id: 'first-name', key: 'first_name' },
    { id: 'middle-name', key: 'middle_name' },
    { id: 'last-name', key: 'last_name' },
    { id: 'dob', key: 'date_of_birth' },
    { id: 'national-id', key: 'national_id' },
  ];
  const LOW_CONFIDENCE_THRESHOLD = 0.72;
  const PROGRESS_STEPS = [
    'Reading your National ID...',
    'Extracting personal information...',
    'Validating residency...',
    'Almost done...',
  ];
  const WAITING_PLACEHOLDER = 'Waiting for OCR extraction...';
  const MANUAL_PLACEHOLDER = 'Unable to extract. Please enter manually.';

  let processing = false;
  let progressTimer = null;

  function fileCacheKey(file) {
    return CACHE_PREFIX + [file.name, file.size, file.lastModified].join('_');
  }

  function readCache(file) {
    try {
      const raw = sessionStorage.getItem(fileCacheKey(file));
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }

  function writeCache(file, data) {
    try {
      sessionStorage.setItem(fileCacheKey(file), JSON.stringify(data));
    } catch { /* quota */ }
  }

  function clearCache(file) {
    try {
      sessionStorage.removeItem(fileCacheKey(file));
    } catch { /* ignore */ }
  }

  /**
   * Client-side image preprocessing: scale, grayscale, contrast, sharpen.
   * @returns {Promise<Blob|null>}
   */
  async function preprocessImage(file) {
    if (!file.type.startsWith('image/')) {
      return null;
    }

    const bitmap = await createImageBitmap(file);
    const targetW = Math.min(1800, Math.max(1000, bitmap.width));
    const scale = targetW / bitmap.width;
    const targetH = Math.round(bitmap.height * scale);

    const canvas = document.createElement('canvas');
    canvas.width = targetW;
    canvas.height = targetH;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    if (!ctx) {
      bitmap.close();
      return null;
    }

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, targetW, targetH);
    ctx.drawImage(bitmap, 0, 0, targetW, targetH);
    bitmap.close();

    const imageData = ctx.getImageData(0, 0, targetW, targetH);
    const d = imageData.data;

    for (let i = 0; i < d.length; i += 4) {
      const gray = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
      const contrast = Math.min(255, Math.max(0, (gray - 128) * 1.35 + 128));
      d[i] = d[i + 1] = d[i + 2] = contrast;
    }
    ctx.putImageData(imageData, 0, 0);

    return new Promise((resolve) => {
      canvas.toBlob((blob) => resolve(blob), 'image/jpeg', 0.88);
    });
  }

  function startProgress(onUpdate) {
    let idx = 0;
    onUpdate(PROGRESS_STEPS[0]);
    progressTimer = window.setInterval(() => {
      idx = Math.min(idx + 1, PROGRESS_STEPS.length - 1);
      onUpdate(PROGRESS_STEPS[idx]);
    }, 1400);
  }

  function stopProgress() {
    if (progressTimer) {
      window.clearInterval(progressTimer);
      progressTimer = null;
    }
  }

  async function extractFromImage(file, options = {}) {
    if (processing) {
      return { success: false, message: 'OCR is already processing. Please wait.' };
    }

    const cached = readCache(file);
    if (cached && !options.force) {
      return { ...cached, client_cached: true };
    }

    processing = true;
    const base = (typeof global.APP_BASE !== 'undefined') ? global.APP_BASE : '';

    try {
      const fd = new FormData();
      fd.append('ocr_mode', 'extract');
      // Send the original image — server handles EXIF, rotation, and multi-pass OCR.
      fd.append('national_id_image', file, file.name);

      const res = await fetch(base + '/app/controllers/patient/process_id_ocr.php', {
        method: 'POST',
        body: fd,
      });

      if (!res.ok) {
        const raw = await res.text().catch(() => '');
        return {
          success: false,
          message: "We couldn't accurately read your National ID. Please upload a clearer photo taken in good lighting.",
          detail: raw.slice(0, 200),
        };
      }

      const data = await res.json();
      if (data.success && !data.low_confidence) {
        writeCache(file, data);
      } else if (!data.success) {
        clearCache(file);
      }
      return data;
    } catch (err) {
      console.error('OCR extract error:', err);
      return {
        success: false,
        message: "We couldn't accurately read your National ID. Please upload a clearer photo taken in good lighting.",
      };
    } finally {
      processing = false;
    }
  }

  function clearAllClientCache() {
    try {
      Object.keys(sessionStorage).forEach((key) => {
        if (key.startsWith(CACHE_PREFIX)) sessionStorage.removeItem(key);
      });
    } catch { /* ignore */ }
  }

  function clearFieldVisualState(el) {
    if (!el) return;
    el.classList.remove(
      'ocr-autofilled',
      'ocr-autofilled-pulse',
      'ocr-field-verified',
      'ocr-field-low-conf',
      'ocr-field-edited',
      'ocr-field-fill-anim'
    );
    delete el.dataset.ocrSourceValue;
    delete el.dataset.ocrConfidence;
    const wrap = el.closest('[data-ocr-field]') || el.closest('.form-group');
    if (wrap) {
      wrap.classList.remove('ocr-field--verified', 'ocr-field--low-conf', 'ocr-field--edited', 'ocr-field--empty');
    }
    const badge = document.getElementById('badge-' + el.id);
    if (badge) {
      badge.hidden = true;
      badge.textContent = '';
      badge.className = 'ocr-field-badge';
      badge.removeAttribute('title');
    }
  }

  function setFieldBadge(id, state) {
    const badge = document.getElementById('badge-' + id);
    const el = document.getElementById(id);
    const wrap = el ? (el.closest('[data-ocr-field]') || el.closest('.form-group')) : null;
    if (wrap) {
      wrap.classList.remove('ocr-field--verified', 'ocr-field--low-conf', 'ocr-field--edited', 'ocr-field--empty');
    }
    if (el) {
      el.classList.remove('ocr-field-verified', 'ocr-field-low-conf', 'ocr-field-edited');
    }
    if (!badge) return;

    if (state === 'verified') {
      badge.hidden = false;
      badge.textContent = 'OCR Verified';
      badge.className = 'ocr-field-badge ocr-field-badge--verified';
      badge.removeAttribute('title');
      if (el) el.classList.add('ocr-field-verified');
      if (wrap) wrap.classList.add('ocr-field--verified');
    } else if (state === 'low') {
      badge.hidden = false;
      badge.innerHTML = '<span aria-hidden="true">⚠</span> Review';
      badge.className = 'ocr-field-badge ocr-field-badge--low';
      badge.title = 'This field may contain OCR recognition errors. Please verify.';
      if (el) el.classList.add('ocr-field-low-conf');
      if (wrap) wrap.classList.add('ocr-field--low-conf');
    } else if (state === 'edited') {
      badge.hidden = false;
      badge.textContent = 'Edited';
      badge.className = 'ocr-field-badge ocr-field-badge--edited';
      badge.removeAttribute('title');
      if (el) el.classList.add('ocr-field-edited');
      if (wrap) wrap.classList.add('ocr-field--edited');
    } else if (state === 'empty') {
      badge.hidden = false;
      badge.textContent = 'Manual entry';
      badge.className = 'ocr-field-badge ocr-field-badge--empty';
      badge.removeAttribute('title');
      if (wrap) wrap.classList.add('ocr-field--empty');
    } else {
      badge.hidden = true;
      badge.textContent = '';
      badge.className = 'ocr-field-badge';
      badge.removeAttribute('title');
    }
  }

  function lockOcrFields() {
    OCR_FIELD_IDS.forEach((id) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.value = '';
      delete el.dataset.ocrUnlocked;
      el.readOnly = true;
      el.setAttribute('readonly', 'readonly');
      el.classList.add('ocr-gated');
      el.setAttribute('autocomplete', 'off');
      if (el.type !== 'date') el.placeholder = WAITING_PLACEHOLDER;
      clearFieldVisualState(el);
    });
    const age = document.getElementById('age');
    if (age) age.value = '';
  }

  function unlockOcrFields() {
    OCR_FIELD_IDS.forEach((id) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.readOnly = false;
      el.removeAttribute('readonly');
      el.classList.remove('ocr-gated');
      el.dataset.ocrUnlocked = '1';
    });
  }

  function sleep(ms) {
    return new Promise((resolve) => window.setTimeout(resolve, ms));
  }

  function guardAgainstBrowserAutofill() {
    if (processing) return;
    OCR_FIELD_IDS.forEach((id) => {
      const el = document.getElementById(id);
      if (!el) return;
      if (!el.dataset.ocrUnlocked) {
        if (el.value) el.value = '';
        el.readOnly = true;
        el.setAttribute('readonly', 'readonly');
        el.classList.add('ocr-gated');
      }
    });
  }

  function highlightField(el) {
    if (!el) return;
    el.classList.add('ocr-autofilled');
    el.classList.add('ocr-autofilled-pulse');
    el.classList.add('ocr-field-fill-anim');
    window.setTimeout(() => {
      el.classList.remove('ocr-autofilled-pulse');
      el.classList.remove('ocr-field-fill-anim');
    }, 1600);
  }

  function fieldConfidence(field) {
    if (!field || typeof field !== 'object') return null;
    const c = Number(field.confidence);
    return Number.isFinite(c) ? c : null;
  }

  function markManualEntryNeeded(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.readOnly = false;
    el.removeAttribute('readonly');
    el.classList.remove('ocr-gated');
    el.dataset.ocrUnlocked = '1';
    if (el.type !== 'date') el.placeholder = MANUAL_PLACEHOLDER;
    setFieldBadge(id, 'empty');
  }

  async function setFieldValue(id, value, confidence, animate) {
    const el = document.getElementById(id);
    if (!el || value == null || String(value).trim() === '') return false;
    el.readOnly = false;
    el.removeAttribute('readonly');
    el.classList.remove('ocr-gated');
    el.dataset.ocrUnlocked = '1';
    const trimmed = String(value).trim();
    el.dataset.ocrSourceValue = trimmed;
    if (confidence != null) el.dataset.ocrConfidence = String(confidence);
    if (animate) {
      el.classList.add('ocr-field-fill-anim');
      await sleep(40);
    }
    el.value = trimmed;
    if (el.type !== 'date') el.placeholder = '';
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    highlightField(el);
    const low = confidence != null && confidence < LOW_CONFIDENCE_THRESHOLD;
    setFieldBadge(id, low ? 'low' : 'verified');
    return true;
  }

  function renderExtractionPreview() {
    const panel = document.getElementById('ocr-extract-preview');
    const list  = document.getElementById('ocr-extract-list');
    if (panel) panel.hidden = true;
    if (list) list.innerHTML = '';
  }

  async function applyAutofill(data) {
    if (!data || !data.extracted) return { filled: 0, reviewCount: 0, missingCount: 0 };

    unlockOcrFields();
    global.__ocrAutofillActive = true;

    const ex = data.extracted;
    let filled = 0;
    let reviewCount = 0;
    let missingCount = 0;
    let addressMatched = {};
    let isBagoResident = null;

    for (const item of FILL_ORDER) {
      const field = ex[item.key];
      const value = field && field.value != null ? String(field.value).trim() : '';
      const conf = fieldConfidence(field);
      if (value) {
        const ok = await setFieldValue(item.id, value, conf, true);
        if (ok) {
          filled++;
          if (conf != null && conf < LOW_CONFIDENCE_THRESHOLD) reviewCount++;
          await sleep(280);
        }
      } else {
        markManualEntryNeeded(item.id);
        if (item.id !== 'middle-name') missingCount++;
      }
    }

    if (ex.address?.value && global.PhAddressAutofill) {
      try {
        const addrResult = await global.PhAddressAutofill.fillFromText(ex.address.value);
        filled += addrResult.filled || 0;
        addressMatched = addrResult.matched || {};
        if (typeof addrResult.isBagoResident === 'boolean') {
          isBagoResident = addrResult.isBagoResident;
        }
        const street = document.getElementById('street-address');
        if (street && street.value) {
          street.dataset.ocrSourceValue = street.value;
          const addrConf = fieldConfidence(ex.address);
          if (addrConf != null) street.dataset.ocrConfidence = String(addrConf);
          const low = addrConf != null && addrConf < LOW_CONFIDENCE_THRESHOLD;
          setFieldBadge('street-address', low ? 'low' : 'verified');
          if (low) reviewCount++;
          highlightField(street);
        } else {
          markManualEntryNeeded('street-address');
          missingCount++;
        }
      } catch (err) {
        console.error('Address autofill error:', err);
        const street = document.getElementById('street-address');
        if (street) {
          street.readOnly = false;
          street.removeAttribute('readonly');
          street.classList.remove('ocr-gated');
          street.dataset.ocrUnlocked = '1';
          street.dataset.ocrSourceValue = String(ex.address.value).trim();
          street.value = String(ex.address.value).trim();
          street.dispatchEvent(new Event('input', { bubbles: true }));
          highlightField(street);
          const addrConf = fieldConfidence(ex.address);
          const low = addrConf != null && addrConf < LOW_CONFIDENCE_THRESHOLD;
          setFieldBadge('street-address', low ? 'low' : 'verified');
          if (low) reviewCount++;
          filled++;
          addressMatched.street = ex.address.value;
        }
      }
    } else {
      markManualEntryNeeded('street-address');
      missingCount++;
    }

    const dobEl = document.getElementById('dob');
    if (dobEl && dobEl.value) {
      dobEl.dispatchEvent(new Event('change', { bubbles: true }));
    }

    renderExtractionPreview();
    window.setTimeout(() => { global.__ocrAutofillActive = false; }, 350);
    return { filled, reviewCount, missingCount, addressMatched, isBagoResident };
  }

  function markFieldEdited(id) {
    const el = document.getElementById(id);
    if (!el || !el.dataset.ocrUnlocked) return;
    if (global.__ocrAutofillActive) return;
    const source = el.dataset.ocrSourceValue;
    if (source == null) {
      if (el.value && String(el.value).trim() !== '') setFieldBadge(id, 'edited');
      return;
    }
    if (String(el.value).trim() !== String(source).trim()) {
      setFieldBadge(id, 'edited');
    } else {
      const conf = Number(el.dataset.ocrConfidence);
      const low = Number.isFinite(conf) && conf < LOW_CONFIDENCE_THRESHOLD;
      setFieldBadge(id, low ? 'low' : 'verified');
    }
  }

  function resetOcrFieldLock() {
    OCR_FIELD_IDS.forEach((id) => {
      const el = document.getElementById(id);
      if (!el) return;
      delete el.dataset.ocrUnlocked;
      clearFieldVisualState(el);
    });
    lockOcrFields();
  }

  function resetExtractionPreview() {
    const panel = document.getElementById('ocr-extract-preview');
    const list  = document.getElementById('ocr-extract-list');
    if (panel) panel.hidden = true;
    if (list) list.innerHTML = '';
    document.querySelectorAll('.ocr-autofilled').forEach((el) => {
      el.classList.remove('ocr-autofilled', 'ocr-autofilled-pulse', 'ocr-field-fill-anim');
    });
  }

  global.NationalIdOcr = {
    extractFromImage,
    applyAutofill,
    resetExtractionPreview,
    preprocessImage,
    startProgress,
    stopProgress,
    readCache,
    clearCache,
    clearAllClientCache,
    lockOcrFields,
    unlockOcrFields,
    resetOcrFieldLock,
    guardAgainstBrowserAutofill,
    markFieldEdited,
    setFieldBadge,
    isProcessing: () => processing,
    PROGRESS_STEPS,
    OCR_FIELD_IDS,
  };
})(window);
