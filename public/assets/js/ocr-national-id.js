/**
 * Philippine National ID OCR — client preprocessing, extraction, and auto-fill.
 */
(function (global) {
  'use strict';

  const CACHE_PREFIX = 'medconnect_ocr_extract_';
  const PROGRESS_STEPS = [
    'Reading National ID...',
    'Detecting text...',
    'Extracting information...',
    'Almost done...',
  ];

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
      const preprocessed = await preprocessImage(file);
      const uploadBlob = preprocessed || file;
      const uploadName = preprocessed ? 'national_id_preprocessed.jpg' : file.name;

      const fd = new FormData();
      fd.append('ocr_mode', 'extract');
      fd.append('national_id_image', uploadBlob, uploadName);

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

  function highlightField(el) {
    if (!el) return;
    el.classList.add('ocr-autofilled');
    el.classList.add('ocr-autofilled-pulse');
    window.setTimeout(() => el.classList.remove('ocr-autofilled-pulse'), 1600);
  }

  function setFieldValue(id, value) {
    const el = document.getElementById(id);
    if (!el || value == null || String(value).trim() === '') return false;
    el.value = String(value).trim();
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    highlightField(el);
    return true;
  }

  function renderExtractionPreview(extracted, addressMatched) {
    const panel = document.getElementById('ocr-extract-preview');
    const list  = document.getElementById('ocr-extract-list');
    if (!panel || !list) return;

    const labels = {
      first_name: 'First Name',
      middle_name: 'Middle Name',
      last_name: 'Last Name',
      date_of_birth: 'Birth Date',
      national_id: 'National ID Number',
      region: 'Region',
      province: 'Province',
      city: 'City / Municipality',
      barangay: 'Barangay',
      street: 'Street / House',
      address: 'Full Address (OCR)',
    };

    const items = { ...extracted };
    if (addressMatched) {
      ['region', 'province', 'city', 'barangay', 'street'].forEach((key) => {
        if (addressMatched[key]) {
          items[key] = { value: addressMatched[key], confidence: 0.85, source: 'parsed' };
        }
      });
    }

    list.innerHTML = '';
    Object.keys(labels).forEach((key) => {
      const field = items[key] || {};
      const val = (field.value || '').trim();
      const conf = typeof field.confidence === 'number' ? Math.round(field.confidence * 100) : null;
      const li = document.createElement('li');
      li.className = 'ocr-extract-item' + (val ? ' filled' : ' empty');
      li.innerHTML = `
        <span class="ocr-extract-label">${labels[key]}</span>
        <span class="ocr-extract-value">${val || '—'}</span>
        ${conf != null ? `<span class="ocr-extract-conf">${conf}%</span>` : ''}
      `;
      list.appendChild(li);
    });

    panel.hidden = false;
  }

  async function applyAutofill(data) {
    if (!data || !data.extracted) return { filled: 0 };

    const ex = data.extracted;
    let filled = 0;
    let addressMatched = {};

    if (setFieldValue('first-name', ex.first_name?.value)) filled++;
    if (setFieldValue('middle-name', ex.middle_name?.value)) filled++;
    if (setFieldValue('last-name', ex.last_name?.value)) filled++;
    if (setFieldValue('dob', ex.date_of_birth?.value)) filled++;
    if (setFieldValue('national-id', ex.national_id?.value)) filled++;

    if (ex.address?.value && global.PhAddressAutofill) {
      try {
        const addrResult = await global.PhAddressAutofill.fillFromText(ex.address.value);
        filled += addrResult.filled || 0;
        addressMatched = addrResult.matched || {};
      } catch (err) {
        console.error('Address autofill error:', err);
        const street = document.getElementById('street-address');
        if (street) {
          street.value = ex.address.value;
          street.dispatchEvent(new Event('input', { bubbles: true }));
          highlightField(street);
          filled++;
          addressMatched.street = ex.address.value;
        }
      }
    }

    const dobEl = document.getElementById('dob');
    if (dobEl && dobEl.value) {
      dobEl.dispatchEvent(new Event('change', { bubbles: true }));
    }

    renderExtractionPreview(ex, addressMatched);
    return { filled, addressMatched };
  }

  function resetExtractionPreview() {
    const panel = document.getElementById('ocr-extract-preview');
    const list  = document.getElementById('ocr-extract-list');
    if (panel) panel.hidden = true;
    if (list) list.innerHTML = '';
    document.querySelectorAll('.ocr-autofilled').forEach((el) => {
      el.classList.remove('ocr-autofilled', 'ocr-autofilled-pulse');
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
    isProcessing: () => processing,
    PROGRESS_STEPS,
  };
})(window);
