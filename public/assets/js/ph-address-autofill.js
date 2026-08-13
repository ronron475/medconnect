/**
 * Philippine address auto-fill from OCR text.
 * Matches Region / Province / City / Barangay against ph-json datasets
 * and cascades the registration dropdowns.
 */
(function (global) {
  'use strict';

  const base = (typeof global.APP_BASE !== 'undefined') ? global.APP_BASE : '';
  const JSON_BASE = base + '/philippine-address-selector-main/ph-json/';

  const cache = { region: null, province: null, city: null, barangay: null, bagoPuroks: null };

  const FUZZY_BARANGAY_MAX_DIST = 2;
  const STREET_REVIEW_THRESHOLD = 0.65;

  const GEO_NOISE_RE = /\b(city|of|oe|bgo|bago|negros|occidental|occid|occ|oriental|philippines|ph|neg|province|region)\b/i;
  const PUROK_PREFIX_RE = /^(purok|sitio|brgy\.?|barangay|blk\.?|block|phase|subd\.?|subdivision)\b/i;
  /** Stop purok capture before city/province/barangay tokens (PhilID order). */
  const PUROK_STOP_RE = /\b(city|of|oe|bago|bgo|negros|occidental|occid|occ|oriental|philippines|province|region|municipality)\b/i;

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

  /**
   * Remove city/province reference phrases from a single address segment.
   * Used BEFORE word-level geo-stripping so multi-word phrases like
   * "OF BAGO NEG" or "CITY OF BAGO" are caught as a unit.
   * Does NOT touch legitimate barangay/purok names containing "Bago".
   */
  function stripBagoCityFragments(segment) {
    let s = String(segment || '');
    // Order matters: longest/most-specific patterns first.
    const patterns = [
      /,?\s*city\s+of\s+bago,?\s*negros\s+occ(?:id|idental)?\.?/gi,
      /,?\s*bago\s+city,?\s*negros\s+occ(?:id|idental)?\.?/gi,
      /,?\s*city\s+of\s+bago/gi,
      /,?\s*bago\s+city/gi,
      /,?\s*negros\s+occ(?:id|idental)?\.?/gi,
      /\bof\s+bago\s+neg(?:ros)?\.?\b/gi,
      /\bof\s+bago\b/gi,
      /\bcity\s+oe\s+bago\b/gi,   // common OCR variant
      /\bc1ty\s+of\s+bago\b/gi,  // OCR 1->i confusion
      /\bocc(?:id|idental)\b/gi,
    ];
    for (const re of patterns) {
      s = s.replace(re, '');
    }
    return s.replace(/[,\s]+$/g, '').replace(/^[,\s]+/, '').replace(/\s+/g, ' ').trim();
  }

  /** Keep only the purok/sitio phrase; drop barangay / city / province that OCR glued on. */
  function trimPurokPhrase(phrase, barangayName) {
    let s = String(phrase || '').replace(/\s+/g, ' ').trim();
    if (!s) return '';

    s = stripBagoCityFragments(s);

    if (barangayName) {
      const bn = norm(barangayName);
      if (bn) {
        const re = new RegExp('\\b' + bn.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b', 'ig');
        s = s.replace(re, ' ');
      }
    }

    const stop = s.search(PUROK_STOP_RE);
    if (stop > 0) s = s.slice(0, stop);

    s = s
      .replace(GEO_NOISE_RE, ' ')
      .replace(/\s+/g, ' ')
      .replace(/[,\s]+$/g, '')
      .replace(/^[,\s]+/, '')
      .trim();

    // Prefer "PUROK NAME" only (first 1–3 tokens after the prefix).
    const m = s.match(/^(purok|sitio|brgy\.?|barangay|blk\.?|block)\s+(.+)$/i);
    if (m) {
      const rest = m[2].split(/\s+/).filter(Boolean).slice(0, 3).join(' ');
      if (rest) return toOfficialCase(`${m[1]} ${rest}`);
    }

    return toOfficialCase(s);
  }

  function preprocessRawAddress(raw) {
    let s = String(raw || '')
      .replace(/\r?\n/g, ', ')
      .replace(/,\s*,+/g, ',')
      .replace(/\s+/g, ' ')
      .trim();

    // Strip city-reference phrases inside each comma-separated segment FIRST
    // so they don't contaminate the street/purok/barangay components.
    s = s.split(',').map((seg) => {
      const stripped = stripBagoCityFragments(seg);
      return stripped;
    }).filter(Boolean).join(', ');

    // Correct block/unit spacing (e.g. "1 B", "5 A" -> "1-B", "5-A")
    s = s.replace(/\b(\d+)\s+([A-Za-z])\b/g, (m, num, letter) => `${num}-${letter.toUpperCase()}`);

    // Correct specific Bago City barangay spacing/hyphens
    s = s
      .replace(/\blag\s+asan\b/gi, 'LAG-ASAN')
      .replace(/\bma\s+ao\b/gi, 'MA-AO')
      .replace(/\bdon\s+jorge\s+l\s+araneta\b/gi, 'DON JORGE L. ARANETA');

    return s;
  }

  function normalizeOcrAddress(raw) {
    let s = String(raw || '')
      .replace(/,\s*,+/g, ',')
      .replace(/\s+/g, ' ')
      .trim();

    let n = norm(s);
    n = n
      .replace(/\bcity\s+oe\b/g, 'city of')
      .replace(/\bcity\s+of\s+bgo\b/g, 'city of bago')
      .replace(/\bcity\s+occidental\b/g, 'city of bago')
      .replace(/\bbgo\b/g, 'bago')
      .replace(/\bgo\s*,\s*negros\b/g, 'bago negros')
      .replace(/\bgo\s+negros\b/g, 'bago negros')
      .replace(/\bcity\s+of\s+bago\b/g, 'city of bago')
      .replace(/\bil\s*jan\b/g, 'ilijan')
      .replace(/\bil\s*jn\b/g, 'ilijan')
      .replace(/\bil\s*jsn\b/g, 'ilijan')
      .replace(/\bil\s*ian\b/g, 'ilijan')
      .replace(/\bnegros\s+occ\.?\b/g, 'negros occidental')
      .replace(/\bneg\s+occ\b/g, 'negros occidental')
      .replace(/\bnegros\s+occidental\b/g, 'negros occidental');
    if (n.includes('negros') && !n.includes('negros occidental') && !n.includes('negros oriental')) {
      n = n.replace(/\bnegros\b/g, 'negros occidental');
    }
    n = n
      .replace(/\s+/g, ' ')
      .trim();

    return n;
  }

  function toOfficialCase(segment) {
    return String(segment || '')
      .replace(/\s+/g, ' ')
      .trim()
      .toUpperCase();
  }

  function isGeoNoiseSegment(segment) {
    const t = norm(segment);
    if (!t || t.length < 2) return true;
    if (/^(city|of|oe|bgo|bago|negros|occidental|occid|occ|oriental|philippines|ph|neg|province|region)$/.test(t)) {
      return true;
    }
    if (/^city\s+of(\s+bago)?$/.test(t)) return true;
    if (/^city\s+(oe|of)\s+b(ago|go)$/.test(t)) return true;
    if (/^city\s+occidental$/.test(t)) return true;
    if (/^negros(\s+occ(idental)?)?$/.test(t)) return true;
    if (/^go$/.test(t)) return true;
    if (/\b(bago|bgo)\b/.test(t) && /\b(city|negros|occidental|of|oe)\b/.test(t)) return true;
    return false;
  }

  function extractLocalityFromAddress(addressRaw, parts) {
    const cleanedRaw = String(addressRaw || '').replace(/\r?\n/g, ', ');
    const segments = cleanedRaw
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean);

    const targetWords = [];
    if (parts.barangay?.brgy_name) {
      targetWords.push(...norm(parts.barangay.brgy_name).split(' '));
    }
    if (parts.city?.city_name) {
      targetWords.push(...norm(parts.city.city_name).split(' '));
    }
    if (parts.province?.province_name) {
      targetWords.push(...norm(parts.province.province_name).split(' '));
    }
    const noise = ['city', 'of', 'oe', '0f', 'province', 'region', 'philippines', 'ph', 'negros', 'occidental', 'oriental', 'bago', 'bgo'];
    targetWords.push(...noise);

    const ocrClean = (w) => norm(w).replace(/[1l|]/g, 'i').replace(/0/g, 'o');

    function isGeoNoiseWord(w) {
      return /^(city|of|oe|0f|bgo|bago|negros|occidental|occid|occ|oriental|philippines|ph|neg|province|region|go|cty|ctty|c1ty)$/.test(w);
    }

    const STRUCTURAL_WORDS = /^(purok|sitio|brgy\.?|barangay|blk\.?|block|phase|subd\.?|subdivision|street|st\.?|house|lot|no\.?)$/i;

    function isStructuralOnly(words) {
      return words.every(w => {
        const wn = norm(w);
        return !wn || STRUCTURAL_WORDS.test(wn) || /^[0-9\-\#\/]+$/.test(wn);
      });
    }

    function shouldRemoveWord(word) {
      const wn = norm(word);
      const cleanedWord = ocrClean(wn);
      if (!wn || wn.length < 2) return false;
      if (isGeoNoiseWord(wn)) return true;

      for (const target of targetWords) {
        const tn = norm(target);
        if (!tn || tn.length < 2) continue;

        if (wn === tn || cleanedWord === ocrClean(tn)) return true;

        if (tn.length >= 4) {
          const cleanedTarget = ocrClean(tn);
          const dist = levenshtein(cleanedWord, cleanedTarget);
          const maxAllowedDist = tn.length <= 5 ? 1 : (tn.length <= 7 ? 2 : 3);
          if (dist <= maxAllowedDist) return true;
        }
      }
      return false;
    }

    const kept = [];
    for (const seg of segments) {
      const words = seg.split(/[\s\-]+/);
      const cleanWords = [];
      const removedWords = [];
      for (const word of words) {
        if (!shouldRemoveWord(word)) {
          cleanWords.push(word);
        } else {
          removedWords.push(word);
        }
      }

      if (removedWords.length > 0) {
        if (cleanWords.length > 0 && isStructuralOnly(cleanWords)) {
          kept.push(seg);
          continue;
        }
      }

      const cleanedSeg = cleanWords.join(' ').trim();
      if (cleanedSeg && !isGeoNoiseSegment(cleanedSeg)) {
        kept.push(cleanedSeg);
      }
    }

    const uniqueKept = [];
    for (const seg of kept) {
      const cleanSeg = toOfficialCase(seg);
      let isDuplicate = false;
      for (const existing of uniqueKept) {
        if (cleanSeg === existing) {
          isDuplicate = true;
          break;
        }
        if (cleanSeg.length >= 6 && existing.length >= 6) {
          if (levenshtein(compact(cleanSeg), compact(existing)) <= 2) {
            isDuplicate = true;
            break;
          }
        }
      }
      if (!isDuplicate) {
        uniqueKept.push(cleanSeg);
      }
    }

    if (uniqueKept.length) {
      return toOfficialCase(uniqueKept.join(', '));
    }

    const purokMatch = normalizeOcrAddress(addressRaw).match(
      /\b(purok|sitio|brgy\.?|barangay|blk\.?|block)\s+[a-z0-9][a-z0-9\s\-]{1,40}/i
    );
    if (purokMatch) {
      return toOfficialCase(purokMatch[0].replace(GEO_NOISE_RE, '').trim());
    }

    return '';
  }

  async function loadBagoPurokCatalog() {
    if (cache.bagoPuroks) return cache.bagoPuroks;
    try {
      const res = await fetch(base + '/app/api/geo/bago_puroks.php');
      if (res.ok) {
        cache.bagoPuroks = await res.json();
        return cache.bagoPuroks;
      }
    } catch (_) { /* optional catalog */ }
    return null;
  }

  function resolveBarangayCatalogName(barangayName, catalog) {
    if (!barangayName || !catalog) return barangayName;
    const aliases = catalog.aliases || {};
    const bn = String(barangayName).trim();
    return aliases[bn] || bn;
  }

  function matchPurokInBarangay(addressNorm, barangayName, catalog) {
    const catalogName = resolveBarangayCatalogName(barangayName, catalog);
    const named = catalog?.barangays?.[catalogName]?.named || [];

    let best = null;
    let bestDist = Infinity;

    for (const label of named) {
      const ln = norm(label);
      const lc = compact(ln);
      if (!ln) continue;
      if (addressNorm.includes(ln)) {
        return { label: toOfficialCase(label), confidence: 0.95, source: 'catalog-exact' };
      }
      const dist = levenshtein(lc, compact(addressNorm));
      if (dist < bestDist) {
        bestDist = dist;
        best = label;
      }
    }

    if (best && bestDist <= 3) {
      return { label: toOfficialCase(best), confidence: 0.82, source: 'catalog-fuzzy' };
    }

    // Capture purok name only up to a comma / geo keyword so we do not swallow
    // barangay + "CITY OF BAGO, NEGROS OCCIDENTAL" (which produced "OCCID").
    const extracted = addressNorm.match(
      /\b(purok|sitio|brgy\.?|barangay|blk\.?|block)\s+([a-z0-9][a-z0-9\-]*(?:\s+[a-z0-9][a-z0-9\-]*){0,2})/i
    );
    if (extracted) {
      const phrase = trimPurokPhrase(`${extracted[1]} ${extracted[2]}`, barangayName);
      if (phrase.length >= 4) {
        return { label: phrase, confidence: 0.88, source: 'extracted' };
      }
    }

    return null;
  }

  /**
   * PhilID-style street line:
   *   PUROK BALATONG, ILIJAN, CITY OF BAGO, NEGROS OCCIDENTAL
   */
  function formatOfficialStreet(locality, parts) {
    const segments = [];
    const barangayName = parts.barangay?.brgy_name || '';
    const barangayNorm = norm(barangayName);

    const localityParts = String(locality || '')
      .split(',')
      .map((seg) => stripBagoCityFragments(seg).replace(/\s+/g, ' ').trim())
      .filter(Boolean)
      .map((seg) => {
        if (PUROK_PREFIX_RE.test(seg)) {
          return trimPurokPhrase(seg, barangayName);
        }
        // House / street line: drop glued barangay + geo noise only.
        let s = seg;
        if (barangayNorm) {
          s = s.replace(new RegExp('\\b' + barangayNorm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b', 'ig'), ' ');
        }
        s = s.replace(GEO_NOISE_RE, ' ').replace(/\s+/g, ' ').trim();
        return toOfficialCase(s);
      })
      .filter((seg) => seg && (!barangayNorm || norm(seg) !== barangayNorm));

    for (const seg of localityParts) {
      if (!segments.some((existing) => norm(existing) === norm(seg))) {
        segments.push(seg);
      }
    }

    if (barangayName) {
      segments.push(toOfficialCase(barangayName));
    }

    // Match PhilID wording: "CITY OF BAGO" (not "BAGO CITY").
    if (parts.city && isBagoCityRecord(parts.city)) {
      segments.push('CITY OF BAGO');
    } else if (parts.city?.city_name) {
      const cn = norm(parts.city.city_name);
      if (cn.endsWith(' city') && !cn.startsWith('city of ')) {
        segments.push(toOfficialCase('CITY OF ' + parts.city.city_name.replace(/\s+city$/i, '')));
      } else {
        segments.push(toOfficialCase(parts.city.city_name));
      }
    }

    if (parts.province?.province_name) {
      const pn = norm(parts.province.province_name);
      segments.push(
        pn.includes('negros') && !pn.includes('oriental')
          ? 'NEGROS OCCIDENTAL'
          : toOfficialCase(parts.province.province_name)
      );
    } else if (parts.city && isBagoCityRecord(parts.city)) {
      segments.push('NEGROS OCCIDENTAL');
    }

    return segments.join(', ');
  }

  function computeStreetConfidence(parts, barangayMeta, purokMatch, locality) {
    let score = 0;
    if (locality && locality.length >= 3) score += 0.35;
    if (parts.city && isBagoCityRecord(parts.city)) score += 0.25;
    if (parts.province) score += 0.15;
    if (barangayMeta?.record) {
      score += barangayMeta.distance === 0 ? 0.2 : (barangayMeta.distance <= FUZZY_BARANGAY_MAX_DIST ? 0.12 : 0);
    }
    if (purokMatch?.confidence) score += purokMatch.confidence * 0.2;
    return Math.min(1, score);
  }

  function normalizeStreetAddress(addressRaw, parts, barangayMeta, catalog) {
    const addressNorm = normalizeOcrAddress(addressRaw);
    const purokMatch = parts.barangay
      ? matchPurokInBarangay(addressNorm, parts.barangay.brgy_name, catalog)
      : matchPurokInBarangay(addressNorm, '', catalog);

    let locality = purokMatch?.label || extractLocalityFromAddress(addressRaw, parts);
    if (!locality) {
      locality = extractLocalityFromAddress(addressRaw, { ...parts, barangay: null });
    }

    // Keep a short house/street prefix before purok when present on the same line.
    // e.g. "TORRES STREET PUROK KAPAYAPAS ..." → "TORRES STREET, PUROK KAPAYAPAS"
    if (purokMatch?.label) {
      const prefixMatch = addressNorm.match(
        /^([a-z0-9][a-z0-9\s\-\/]{1,40}?)\s+\b(purok|sitio|blk\.?|block)\b/
      );
      if (prefixMatch) {
        const prefix = toOfficialCase(
          prefixMatch[1]
            .replace(GEO_NOISE_RE, ' ')
            .replace(/\s+/g, ' ')
            .trim()
        );
        if (
          prefix
          && prefix.length >= 3
          && !norm(purokMatch.label).includes(norm(prefix))
          && !PUROK_STOP_RE.test(norm(prefix))
        ) {
          locality = `${prefix}, ${purokMatch.label}`;
        }
      }
    }

    const street = formatOfficialStreet(locality, parts);
    const confidence = computeStreetConfidence(parts, barangayMeta, purokMatch, locality);
    const needsReview = !street
      || confidence < STREET_REVIEW_THRESHOLD
      || (barangayMeta && barangayMeta.distance > FUZZY_BARANGAY_MAX_DIST)
      || (barangayMeta && barangayMeta.ambiguous);

    return {
      street,
      locality,
      confidence,
      needsReview,
      purokMatch,
      normalized: true,
    };
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
    if (!pool.length) return { record: null, distance: 99, ambiguous: false };

    // Bago City is "064502"
    const isBago = pool.length > 0 && pool[0].city_code === '064502';
    const maxDist = isBago ? 3 : FUZZY_BARANGAY_MAX_DIST;

    // Helper to clean common OCR characters: 1/l/| -> i, 0 -> o
    const ocrClean = (w) => norm(w).replace(/[1l|]/g, 'i').replace(/0/g, 'o');

    for (const b of pool) {
      const bn = norm(b.brgy_name);
      const bc = compact(b.brgy_name);
      if (bn && addressNorm.includes(bn)) return { record: b, distance: 0, ambiguous: false };
      if (bc.length >= 4 && addressCompact.includes(bc)) return { record: b, distance: 0, ambiguous: false };

      // Try cleaned exact match
      const cleanedBc = ocrClean(b.brgy_name);
      if (cleanedBc.length >= 4 && ocrClean(addressNorm).includes(cleanedBc)) {
        return { record: b, distance: 0, ambiguous: false };
      }
    }

    let best = null;
    let bestDist = maxDist + 1;
    let ties = 0;

    const tokens = addressNorm.split(/[,\s\n]+/).filter((t) => t.length >= 4);
    for (const b of pool) {
      const bc = compact(b.brgy_name);
      const cleanedBc = ocrClean(bc);
      for (const token of tokens) {
        const cleanedToken = ocrClean(token);
        if (cleanedToken === cleanedBc) {
          return { record: b, distance: 0, ambiguous: false };
        }
        const dist = levenshtein(cleanedToken, cleanedBc);
        if (dist < bestDist) {
          bestDist = dist;
          best = b;
          ties = 1;
        } else if (dist === bestDist && best && b.brgy_code !== best.brgy_code) {
          ties++;
        }
      }
    }

    if (best && bestDist <= maxDist) {
      return { record: best, distance: bestDist, ambiguous: ties > 1 };
    }

    // Try sliding window on cleaned strings
    best = null;
    bestDist = maxDist + 1;
    ties = 0;
    const cleanedAddressCompact = ocrClean(addressCompact);
    for (const b of pool) {
      const bc = compact(b.brgy_name);
      const cleanedBc = ocrClean(bc);
      if (cleanedBc.length < 4) continue;
      for (let i = 0; i <= cleanedAddressCompact.length - cleanedBc.length + 1; i++) {
        const slice = cleanedAddressCompact.slice(i, i + cleanedBc.length);
        const dist = levenshtein(slice, cleanedBc);
        if (dist < bestDist) {
          bestDist = dist;
          best = b;
          ties = 1;
        } else if (dist === bestDist && best && b.brgy_code !== best.brgy_code) {
          ties++;
        }
      }
    }

    if (best && bestDist <= maxDist) {
      return { record: best, distance: bestDist, ambiguous: ties > 1 };
    }

    return { record: null, distance: 99, ambiguous: false };
  }

  function extractStreet(addressRaw, parts) {
    return formatOfficialStreet(extractLocalityFromAddress(addressRaw, parts), parts);
  }

  function parseAddress(addressRaw, datasets) {
    addressRaw = preprocessRawAddress(addressRaw);
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
    const barangayMeta = city
      ? findBarangay(barangays, city.city_code, addressNorm, addressCompact)
      : { record: null, distance: 99, ambiguous: false };
    const barangay = barangayMeta.record;
    const parts = { region, province, city, barangay };
    const street = extractStreet(addressRaw, parts);

    return { region, province, city, barangay, barangayMeta, street, addressNorm, parts };
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

    addressRaw = preprocessRawAddress(addressRaw);

    await ensureRegionOptions();

    const [regions, provinces, cities, barangays] = await Promise.all([
      loadJson('region'),
      loadJson('province'),
      loadJson('city'),
      loadJson('barangay'),
    ]);

    const parsed = parseAddress(addressRaw, { regions, provinces, cities, barangays });
    const catalog = await loadBagoPurokCatalog();
    const selectedBarangayEl = document.getElementById('barangay-text');
    const selectedBarangayName = selectedBarangayEl?.value?.trim() || parsed.barangay?.brgy_name || '';
    const partsForStreet = {
      ...parsed.parts,
      barangay: parsed.barangay || (selectedBarangayName
        ? barangays.find((b) => norm(b.brgy_name) === norm(selectedBarangayName))
        : null),
    };
    const streetResult = normalizeStreetAddress(
      addressRaw,
      partsForStreet,
      parsed.barangayMeta,
      catalog
    );

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
      const streetVal = streetResult.street || parsed.street || addressRaw;
      if (streetEl && streetVal) {
        streetEl.value = streetVal;
        streetEl.dataset.ocrNormalized = streetResult.normalized ? '1' : '0';
        streetEl.dataset.ocrStreetConfidence = String(streetResult.confidence || 0);
        streetEl.dataset.ocrStreetReview = streetResult.needsReview ? '1' : '0';
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

    return {
      filled,
      matched,
      parsed,
      streetResult,
      streetConfidence: streetResult.confidence,
      streetNeedsReview: streetResult.needsReview,
      isBagoResident: isBagoCityRecord(parsed.city),
    };
  }

  global.PhAddressAutofill = {
    fillFromText,
    parseAddress,
    loadJson,
    normalizeOcrAddress,
    normalizeStreetAddress,
  };
})(window);
