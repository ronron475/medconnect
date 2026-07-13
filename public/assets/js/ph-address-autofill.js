/**
 * Philippine address auto-fill from OCR text.
 * Matches Region / Province / City / Barangay against ph-json datasets
 * and cascades the registration dropdowns.
 */
(function (global) {
  'use strict';

  const base = (typeof global.APP_BASE !== 'undefined') ? global.APP_BASE : '';
  const JSON_BASE = base + '/philippine-address-selector-main/ph-json/';

  const cache = { region: null, province: null, city: null, barangay: null };

  function norm(s) {
    return String(s || '')
      .toLowerCase()
      .replace(/[^a-z0-9\s]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function compact(s) {
    return norm(s).replace(/\s+/g, '');
  }

  function containsWord(haystack, word) {
    if (!word) return false;
    const w = String(word).trim();
    if (w.length < 2) return false;
    const escaped = w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return new RegExp('\\b' + escaped + '\\b', 'i').test(haystack);
  }

  function levenshtein(a, b) {
    if (a === b) return 0;
    const m = a.length;
    const n = b.length;
    if (!m) return n;
    if (!n) return m;
    const dp = Array.from({ length: m + 1 }, () => new Array(n + 1).fill(0));
    for (let i = 0; i <= m; i++) dp[i][0] = i;
    for (let j = 0; j <= n; j++) dp[0][j] = j;
    for (let i = 1; i <= m; i++) {
      for (let j = 1; j <= n; j++) {
        const cost = a[i - 1] === b[j - 1] ? 0 : 1;
        dp[i][j] = Math.min(dp[i - 1][j] + 1, dp[i][j - 1] + 1, dp[i - 1][j - 1] + cost);
      }
    }
    return dp[m][n];
  }

  async function loadJson(name) {
    if (cache[name]) return cache[name];
    const res = await fetch(JSON_BASE + name + '.json');
    if (!res.ok) throw new Error('Failed to load ' + name + '.json');
    cache[name] = await res.json();
    return cache[name];
  }

  function waitForOptions(selectEl, minCount, timeoutMs) {
    const min = minCount || 2;
    const timeout = timeoutMs || 10000;
    const start = Date.now();
    return new Promise((resolve) => {
      const tick = () => {
        if (selectEl && selectEl.options.length >= min) {
          resolve(true);
          return;
        }
        if (Date.now() - start >= timeout) {
          resolve(false);
          return;
        }
        setTimeout(tick, 80);
      };
      tick();
    });
  }

  function highlightSelect(id) {
    const sel = document.getElementById(id);
    if (!sel) return;
    const wrap = sel.closest('.input-wrap') || sel;
    wrap.classList.add('ocr-autofilled', 'ocr-autofilled-pulse');
    setTimeout(() => wrap.classList.remove('ocr-autofilled-pulse'), 1600);
  }

  async function setSelect(id, hiddenId, code) {
    const sel = document.getElementById(id);
    const hidden = document.getElementById(hiddenId);
    if (!sel || !code) return false;

    let matched = false;
    for (const opt of sel.options) {
      if (opt.value === code) {
        sel.value = code;
        matched = true;
        break;
      }
    }
    if (!matched) return false;

    if (hidden && sel.selectedIndex >= 0) {
      hidden.value = sel.options[sel.selectedIndex].text;
    }

    global.__ocrAutofillActive = true;
    sel.dispatchEvent(new Event('change', { bubbles: true }));
    highlightSelect(id);
    return true;
  }

  function normalizeOcrAddress(raw) {
    let s = norm(raw);
    s = s
      .replace(/\bbgo\b/g, 'bago')
      .replace(/\bgo\s*,\s*negros\b/g, 'bago negros')
      .replace(/\bgo\s+negros\b/g, 'bago negros')
      .replace(/\bcity\s+of\s+bgo\b/g, 'city of bago')
      .replace(/\bil\s*jan\b/g, 'ilijan')
      .replace(/\bil\s*jn\b/g, 'ilijan')
      .replace(/\bil\s*jsn\b/g, 'ilijan')
      .replace(/\bnegros\s+occ\b/g, 'negros occidental');
    return s;
  }

  function findCityByNameInAddress(cities, addressNorm) {
    let best = null;
    let bestLen = 0;
    for (const c of cities) {
      const full = norm(c.city_name);
      const short = full.replace(/\s+city$/, '').replace(/\s+municipality$/, '');
      for (const token of [full, short]) {
        if (token.length >= 4 && containsWord(addressNorm, token) && token.length > bestLen) {
          best = c;
          bestLen = token.length;
        }
      }
    }
    return best;
  }

  function isBagoCityRecord(city) {
    if (!city) return false;
    const n = norm(city.city_name);
    return n === 'bago city' || n === 'city of bago' || /\bbago\s+city\b/.test(n);
  }

  function hasStrongBagoSignal(addressNorm) {
    return (
      (containsWord(addressNorm, 'bago') || containsWord(addressNorm, 'bgo'))
      && containsWord(addressNorm, 'negros')
    ) || containsWord(addressNorm, 'binubuhan');
  }

  function detectBagoCity(addressNorm, cities, barangays) {
    if (hasStrongBagoSignal(addressNorm)) {
      return findBagoCity(cities);
    }
    if (/\b(bago|bgo)\b/.test(addressNorm)) {
      return findBagoCity(cities);
    }
    if (addressNorm.includes('negros') && /\bgo\b/.test(addressNorm)) {
      return findBagoCity(cities);
    }

    const bago = findBagoCity(cities);
    if (!bago || !barangays) return null;

    const pool = barangays.filter((b) => b.city_code === bago.city_code);
    const tokens = addressNorm.split(/[,\s]+/).filter((t) => t.length >= 4);

    for (const b of pool) {
      const bn = norm(b.brgy_name);
      const bc = compact(b.brgy_name);
      if (bn && addressNorm.includes(bn)) return bago;
      for (const token of tokens) {
        if (token === bc || levenshtein(token, bc) <= 2) return bago;
      }
    }
    return null;
  }

  function findBagoCity(cities) {
    return cities.find((c) => norm(c.city_name) === 'bago city')
      || cities.find((c) => norm(c.city_name).includes('bago') && norm(c.city_name).includes('city'))
      || null;
  }

  function findNegrosProvince(provinces, addressNorm) {
    if (!addressNorm.includes('negros')) return null;
    if (addressNorm.includes('oriental') && !addressNorm.includes('occidental')) {
      return provinces.find((p) => norm(p.province_name) === 'negros oriental') || null;
    }
    return provinces.find((p) => norm(p.province_name) === 'negros occidental') || null;
  }

  function provinceFirstToken(pn) {
    if (pn.startsWith('city of ')) return 'city of';
    return pn.split(' ')[0] || '';
  }

  function findProvince(provinces, addressNorm) {
    const negros = findNegrosProvince(provinces, addressNorm);
    if (negros) return negros;

    let best = null;
    let bestScore = Infinity;
    for (const p of provinces) {
      const pn = norm(p.province_name);
      if (!pn) continue;
      if (addressNorm.includes(pn)) return p;

      const token = provinceFirstToken(pn);
      // "BAGO CITY" must not fuzzy-match provinces like "City Of Manila".
      if (token === 'city' || token === 'city of') continue;

      const dist = levenshtein(compact(pn), compact(addressNorm.slice(Math.max(0, addressNorm.length - pn.length - 5))));
      if (addressNorm.includes(token) && dist < bestScore) {
        best = p;
        bestScore = dist;
      }
    }
    return best;
  }

  function findCity(cities, provinceCode, addressNorm, barangays) {
    const bago = detectBagoCity(addressNorm, cities, barangays);
    if (bago) return bago;

    const pool = provinceCode
      ? cities.filter((c) => c.province_code === provinceCode)
      : cities;

    for (const c of pool) {
      const full = norm(c.city_name);
      const short = full.replace(/\s+city$/, '').replace(/\s+municipality$/, '');
      if (containsWord(addressNorm, full) || containsWord(addressNorm, short)) return c;
      if (short.length >= 4 && containsWord(addressNorm, 'city of ' + short)) return c;
      if (short === 'bago' && (containsWord(addressNorm, 'bago') || containsWord(addressNorm, 'bgo'))) return c;
    }

    return null;
  }

  function findRegion(regions, province) {
    if (!province) return null;
    return regions.find((r) => r.region_code === province.region_code) || null;
  }

  function findBarangay(barangays, cityCode, addressNorm, addressCompact) {
    const pool = barangays.filter((b) => b.city_code === cityCode);
    if (!pool.length) return null;

    for (const b of pool) {
      const bn = norm(b.brgy_name);
      const bc = compact(b.brgy_name);
      if (bn && addressNorm.includes(bn)) return b;
      if (bc.length >= 4 && addressCompact.includes(bc)) return b;
    }

    const tokens = addressNorm.split(/[,\s]+/).filter((t) => t.length >= 4);
    for (const b of pool) {
      const bc = compact(b.brgy_name);
      for (const token of tokens) {
        if (token === bc || levenshtein(token, bc) <= 2) return b;
      }
    }

    let best = null;
    let bestDist = 3;
    for (const b of pool) {
      const bc = compact(b.brgy_name);
      if (bc.length < 4) continue;
      for (let i = 0; i <= addressCompact.length - bc.length + 1; i++) {
        const slice = addressCompact.slice(i, i + bc.length);
        const dist = levenshtein(slice, bc);
        if (dist < bestDist) {
          bestDist = dist;
          best = b;
        }
      }
    }
    return best;
  }

  function extractStreet(addressRaw, parts) {
    let street = addressRaw;
    const removeParts = [
      parts.barangay?.brgy_name,
      parts.city?.city_name,
      parts.province?.province_name,
      parts.region?.region_name,
      'city of',
      'negros occidental',
      'negros oriental',
    ].filter(Boolean);

    removeParts.forEach((part) => {
      const re = new RegExp(part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'ig');
      street = street.replace(re, ' ');
    });

    street = street
      .replace(/\s*,\s*/g, ', ')
      .replace(/\s+/g, ' ')
      .replace(/^[\s,]+|[\s,]+$/g, '')
      .trim();

    return street;
  }

  function parseAddress(addressRaw, datasets) {
    const addressNorm = normalizeOcrAddress(addressRaw);
    const addressCompact = compact(addressNorm);
    const { provinces, cities, regions, barangays } = datasets;

    let province = null;
    let city = detectBagoCity(addressNorm, cities, barangays);

    if (!city) {
      city = findCityByNameInAddress(cities, addressNorm);
    }

    if (city && hasStrongBagoSignal(addressNorm) && !isBagoCityRecord(city)) {
      city = findBagoCity(cities);
    }

    if (city) {
      province = provinces.find((p) => p.province_code === city.province_code) || null;
    }

    if (!province) province = findProvince(provinces, addressNorm);
    if (!city) city = findCity(cities, province?.province_code, addressNorm, barangays);
    if (!province && city) {
      province = provinces.find((p) => p.province_code === city.province_code) || null;
    }

    const region = findRegion(regions, province);
    const barangay = city ? findBarangay(barangays, city.city_code, addressNorm, addressCompact) : null;
    const street = extractStreet(addressRaw, { region, province, city, barangay });

    return { region, province, city, barangay, street, addressNorm };
  }

  async function ensureRegionOptions() {
    const sel = document.getElementById('region');
    if (!sel || sel.options.length > 1) return;
    const regions = await loadJson('region');
    clearSelectPlaceholder(sel, 'Choose Region');
    regions.forEach((r) => sel.append(new Option(r.region_name, r.region_code)));
    sel.disabled = false;
  }

  function clearSelectPlaceholder(sel, placeholder) {
    sel.innerHTML = `<option value="" disabled selected>${placeholder}</option>`;
  }

  async function fillFromText(addressRaw) {
    if (!addressRaw || !String(addressRaw).trim()) {
      return { filled: 0, matched: {} };
    }

    await ensureRegionOptions();

    const [regions, provinces, cities, barangays] = await Promise.all([
      loadJson('region'),
      loadJson('province'),
      loadJson('city'),
      loadJson('barangay'),
    ]);

    const parsed = parseAddress(addressRaw, { regions, provinces, cities, barangays });
    let filled = 0;
    const matched = {};

    try {
      global.__ocrAutofillActive = true;

      const selRegion = document.getElementById('region');
      if (parsed.region && selRegion) {
        if (selRegion.options.length < 2) {
          await waitForOptions(selRegion, 2);
        }
        if (await setSelect('region', 'region-text', parsed.region.region_code)) {
          matched.region = parsed.region.region_name;
          filled++;
          await waitForOptions(document.getElementById('province'), 2, 15000);
        }
      }

      if (parsed.province) {
        if (await setSelect('province', 'province-text', parsed.province.province_code)) {
          matched.province = parsed.province.province_name;
          filled++;
          await waitForOptions(document.getElementById('city'), 2, 15000);
        }
      }

      if (parsed.city) {
        if (await setSelect('city', 'city-text', parsed.city.city_code)) {
          matched.city = parsed.city.city_name;
          filled++;
          await waitForOptions(document.getElementById('barangay'), 2, 15000);
        }
      }

      if (parsed.barangay) {
        if (await setSelect('barangay', 'barangay-text', parsed.barangay.brgy_code)) {
          matched.barangay = parsed.barangay.brgy_name;
          filled++;
        }
      }

      const streetEl = document.getElementById('street-address');
      const streetVal = parsed.street || addressRaw;
      if (streetEl && streetVal) {
        streetEl.value = streetVal;
        streetEl.dispatchEvent(new Event('input', { bubbles: true }));
        const wrap = streetEl.closest('.input-wrap') || streetEl;
        wrap.classList.add('ocr-autofilled', 'ocr-autofilled-pulse');
        setTimeout(() => wrap.classList.remove('ocr-autofilled-pulse'), 1600);
        matched.street = streetVal;
        filled++;
      }
    } finally {
      setTimeout(() => { global.__ocrAutofillActive = false; }, 300);
    }

    return { filled, matched, parsed, isBagoResident: isBagoCityRecord(parsed.city) };
  }

  global.PhAddressAutofill = {
    fillFromText,
    parseAddress,
    loadJson,
  };
})(window);
