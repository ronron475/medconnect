/**
 * Patient-facing triage UI language (English / Hiligaynon / Tagalog).
 * Presentation only — never changes triage classification or medical logic.
 */
(function (global) {
  'use strict';

  var LANG = { EN: 'en', FIL: 'fil', HIL: 'hil' };
  var STORAGE_LANG = 'mc_patient_ui_lang';
  var STORAGE_SOURCE = 'mc_patient_ui_lang_source';
  var NAMES = { en: 'English', hil: 'Hiligaynon', fil: 'Tagalog' };

  var STRINGS = {
    en: {
      language: 'Language',
      i_understand: 'I understand',
      choose_another_time: 'Choose another time',
      eyebrow_non_urgent: 'NON-URGENT',
      eyebrow_urgent: 'URGENT',
      eyebrow_emergency: 'EMERGENCY',
      title_non_urgent: 'Regular Check-up Recommended',
      title_urgent: 'Prompt Medical Attention Recommended',
      title_urgent_consult: 'Urgent Consultation Recommended',
      title_emergency: 'Seek Emergency Care Immediately',
      ai_assessment_line: 'AI Assessment: {level}',
      instruction_non_urgent: 'Review the assessment, then tap “Submit Patient Complaint” to continue.',
      instruction_urgent: 'Review the assessment, then tap “Submit Patient Complaint” to continue.',
      instruction_urgent_book: 'Choose a doctor with an open slot today to book your visit.',
      instruction_emergency: 'Go to the nearest hospital or emergency department now.',
      safety_non_urgent: 'Seek urgent care if symptoms suddenly worsen.',
      safety_urgent: 'Seek ER care if symptoms suddenly worsen.',
      safety_emergency: 'Do not wait for online care tips or a video consultation.',
      msg_non_urgent: 'AI Assessment: NON-URGENT',
      msg_urgent: 'AI Assessment: URGENT',
      msg_emergency: 'AI Assessment: EMERGENCY',
      slots_heading: 'Doctors available today',
      slots_heading_recommended: 'Recommended — Earliest Available',
      slots_heading_others: 'Other Available Doctors',
      slots_loading: 'Loading doctors with open slots today…',
      slots_empty: 'No video slots left today. Contact the health office or try again tomorrow. If symptoms worsen, go to the ER.',
      slots_load_fail: 'Could not load doctor times. Use “Choose another time”.',
      slots_network: 'Network error loading slots. Use “Choose another time”.',
      slots_earliest: 'Earliest: {time}',
      slots_today: 'Today, {time}',
      slots_today_video: 'Today · Video · {range}',
      slots_book: 'Book',
      slots_missing_complaint: 'Missing health concern. Close and submit again, or use Choose another time.',
      slots_confirm: 'Book video with {name} at {time}?',
      slots_booking: 'Booking…',
      slots_book_fail: 'Could not book. Try another doctor or Choose another time.',
      slots_emergency: 'Emergency care required — video booking is not available.',
      slots_incomplete: 'Could not complete booking.',
      slots_booked: 'Appointment booked. Redirecting…',
      slots_book_network: 'Network error. Please try again.',
      doctor: 'Doctor',
      submit_complaint: 'Submit patient complaint',
      submit_review: 'Submit patient complaint',
      submitting: 'Submitting…',
      assessing: 'Assessing urgency…',
      err_analyze: 'Could not analyze your complaint. Please try again.',
      err_timeout: 'Analysis timed out. Please try again.',
      err_network: 'Network error. Please try again.',
      err_submit: 'Could not submit. Please try again.',
      err_triage_level: 'Could not determine triage level. Please try again.',
      err_min_chars: 'Please describe your symptoms.',
      err_empty: 'Please describe your symptoms or concern.',
      err_locked: 'Your patient complaint is not available. Please contact the health office.',
      ok_submitted: 'Submitted for provider review.',
      em_submit: 'Emergency symptoms detected. Seek emergency care.',
      urg_submit: 'Please book an urgent consultation.',
      click_again_continue: 'Review the assessment, then tap “Submit Patient Complaint” to continue.',
      ai_preliminary: 'AI Assessment: {level}',
    },
    fil: {
      language: 'Wika',
      i_understand: 'Naiintindihan ko',
      choose_another_time: 'Pumili ng ibang oras',
      eyebrow_non_urgent: 'NON-URGENT',
      eyebrow_urgent: 'URGENT',
      eyebrow_emergency: 'EMERGENCY',
      title_non_urgent: 'Inirerekomenda ang Regular na Check-up',
      title_urgent: 'Inirerekomenda ang Madaliang Pangangalagang Medikal',
      title_urgent_consult: 'Inirerekomenda ang Urgent Consultation',
      title_emergency: 'Magpagamot agad sa Emergency',
      ai_assessment_line: 'AI Assessment: {level}',
      instruction_non_urgent: 'Suriin ang assessment, pagkatapos pindutin ang “Submit Patient Complaint” para magpatuloy.',
      instruction_urgent: 'Suriin ang assessment, pagkatapos pindutin ang “Submit Patient Complaint” para magpatuloy.',
      instruction_urgent_book: 'Pumili ng doktor na may bakanteng oras ngayon para mag-book.',
      instruction_emergency: 'Pumunta agad sa pinakamalapit na ospital o emergency department.',
      safety_non_urgent: 'Magpagamot nang apurahan kung biglang lumala ang sintomas.',
      safety_urgent: 'Pumunta sa ER kung biglang lumala ang sintomas.',
      safety_emergency: 'Huwag maghintay ng online care tips o video consultation.',
      msg_non_urgent: 'AI Assessment: NON-URGENT',
      msg_urgent: 'AI Assessment: URGENT',
      msg_emergency: 'AI Assessment: EMERGENCY',
      slots_heading: 'Mga doktor na available ngayon',
      slots_heading_recommended: 'Inirerekomenda — Pinakamaaga',
      slots_heading_others: 'Iba Pang Available na Doktor',
      slots_loading: 'Kinukuha ang mga doktor na may bakanteng oras ngayon…',
      slots_empty: 'Wala nang video slot ngayong araw. Makipag-ugnayan sa health office o subukan bukas. Kung lumala ang sintomas, pumunta sa ER.',
      slots_load_fail: 'Hindi makuha ang oras ng doktor. Gamitin ang “Pumili ng ibang oras”.',
      slots_network: 'May problema sa network. Gamitin ang “Pumili ng ibang oras”.',
      slots_earliest: 'Pinakamaaga: {time}',
      slots_today: 'Ngayon, {time}',
      slots_today_video: 'Ngayon · Video · {range}',
      slots_book: 'I-book',
      slots_missing_complaint: 'Walang health concern. Isara at i-submit muli, o pumili ng ibang oras.',
      slots_confirm: 'I-book ang video kay {name} sa {time}?',
      slots_booking: 'Binubook…',
      slots_book_fail: 'Hindi na-book. Subukan ang ibang doktor o pumili ng ibang oras.',
      slots_emergency: 'Kailangan ng emergency care — hindi available ang video booking.',
      slots_incomplete: 'Hindi natapos ang pag-book.',
      slots_booked: 'Na-book na ang appointment. Nililipat…',
      slots_book_network: 'May problema sa network. Pakisubukan muli.',
      doctor: 'Doktor',
      submit_complaint: 'Submit patient complaint',
      submit_review: 'Submit patient complaint',
      submitting: 'Isinusumite…',
      assessing: 'Tinitingnan ang urgency…',
      err_analyze: 'Hindi ma-analyze ang iyong complaint. Pakisubukan muli.',
      err_timeout: 'Nag-timeout ang pagsusuri. Pakisubukan muli.',
      err_network: 'May problema sa network. Pakisubukan muli.',
      err_submit: 'Hindi ma-submit. Pakisubukan muli.',
      err_triage_level: 'Hindi matukoy ang antas ng triage. Pakisubukan muli.',
      err_min_chars: 'Magdagdag pa ng detalye (hindi bababa sa 10 character).',
      err_empty: 'Ilarawan ang iyong sintomas o concern.',
      err_locked: 'Hindi available ang iyong patient complaint. Makipag-ugnayan sa health office.',
      ok_submitted: 'Naisumite na para sa review ng provider.',
      em_submit: 'May natukoy na emergency na sintomas. Magpagamot agad.',
      urg_submit: 'Mangyaring mag-book ng urgent consultation.',
      click_again_continue: 'Suriin ang assessment, pagkatapos pindutin ang “Submit Patient Complaint” para magpatuloy.',
      ai_preliminary: 'AI Assessment: {level}',
    },
    hil: {
      language: 'Lengwahe',
      i_understand: 'Nakaintindi ako',
      choose_another_time: 'Pili-a ang iban nga oras',
      eyebrow_non_urgent: 'NON-URGENT',
      eyebrow_urgent: 'URGENT',
      eyebrow_emergency: 'EMERGENCY',
      title_non_urgent: 'Ginarekomenda ang Regular nga Check-up',
      title_urgent: 'Kinahanglan ang Madali nga Pag-atipan',
      title_urgent_consult: 'Ginarekomenda ang Urgent Consultation',
      title_emergency: 'Magpangita Dayon sang Emergency Care',
      ai_assessment_line: 'AI Assessment: {level}',
      instruction_non_urgent: 'Tan-awa ang assessment, dayon i-tap ang “Submit Patient Complaint” para magpadayon.',
      instruction_urgent: 'Tan-awa ang assessment, dayon i-tap ang “Submit Patient Complaint” para magpadayon.',
      instruction_urgent_book: 'Pili-a ang doktor nga may bakante nga oras subong para mag-book.',
      instruction_emergency: 'Kadto dayon sa pinakamalapit nga hospital ukon emergency department.',
      safety_non_urgent: 'Magpangita urgent nga pag-atipan kon bigla maglala ang sintomas.',
      safety_urgent: 'Magkadto sa ER kon bigla maglala ang sintomas.',
      safety_emergency: 'Indi maghulat sang online care tips ukon video consultation.',
      msg_non_urgent: 'AI Assessment: NON-URGENT',
      msg_urgent: 'AI Assessment: URGENT',
      msg_emergency: 'AI Assessment: EMERGENCY',
      slots_heading: 'Mga doktor nga available subong',
      slots_heading_recommended: 'Ginarekomenda — Pinakaaga',
      slots_heading_others: 'Iban nga Available nga Doktor',
      slots_loading: 'Ginakuha ang mga doktor nga may bakante nga oras subong…',
      slots_empty: 'Wala na video slot subong. Mag-contact sa health office ukon tilawi buwas. Kon maglala ang sintomas, kadto sa ER.',
      slots_load_fail: 'Indi makita ang oras sang doktor. Gamita ang “Pili-a ang iban nga oras”.',
      slots_network: 'May problema sa network. Gamita ang “Pili-a ang iban nga oras”.',
      slots_earliest: 'Pinakaaga: {time}',
      slots_today: 'Subong, {time}',
      slots_today_video: 'Subong · Video · {range}',
      slots_book: 'I-book',
      slots_missing_complaint: 'Wala health concern. Isira kag i-submit liwat, ukon pili-a ang iban nga oras.',
      slots_confirm: 'I-book ang video kay {name} sa {time}?',
      slots_booking: 'Ginabook…',
      slots_book_fail: 'Indi ma-book. Tilawi ang iban nga doktor ukon pili-a ang iban nga oras.',
      slots_emergency: 'Kinahanglan ang emergency care — indi available ang video booking.',
      slots_incomplete: 'Indi matapos ang pag-book.',
      slots_booked: 'Na-book na ang appointment. Ginatransfer…',
      slots_book_network: 'May problema sa network. Palihog tilawi liwat.',
      doctor: 'Doktor',
      submit_complaint: 'Submit patient complaint',
      submit_review: 'Submit patient complaint',
      submitting: 'Gina-submit…',
      assessing: 'Ginausisa ang urgency…',
      err_analyze: 'Indi ma-analyze ang imo complaint. Palihog tilawi liwat.',
      err_timeout: 'Nag-timeout ang pag-usisa. Palihog tilawi liwat.',
      err_network: 'May problema sa network. Palihog tilawi liwat.',
      err_submit: 'Indi ma-submit. Palihog tilawi liwat.',
      err_triage_level: 'Indi matukoy ang lebel sang triage. Palihog tilawi liwat.',
      err_min_chars: 'Palihog magdugang detalye (indi cubos sa 10 ka character).',
      err_empty: 'Ilarawan ang imo sintomas ukon concern.',
      err_locked: 'Indi available ang imo patient complaint. Mag-contact sa health office.',
      ok_submitted: 'Naisumite na para sa review sang provider.',
      em_submit: 'May emergency nga sintomas. Magpangita dayon sang pag-atipan.',
      urg_submit: 'Palihog mag-book sang urgent consultation.',
      click_again_continue: 'Tan-awa ang assessment, dayon i-tap ang “Submit Patient Complaint” para magpadayon.',
      ai_preliminary: 'AI Assessment: {level}',
    },
  };

  function normalizeLang(raw) {
    var v = String(raw || '').trim().toLowerCase();
    if (v === 'fil' || v === 'filipino' || v === 'tagalog' || v === 'tl') return LANG.FIL;
    if (v === 'hil' || v === 'hiligaynon' || v === 'ilonggo' || v === 'hiligaynon/ilonggo') return LANG.HIL;
    if (v === 'en' || v === 'english') return LANG.EN;
    return '';
  }

  function readStore(key) {
    try { return global.localStorage.getItem(key) || ''; } catch (e) { return ''; }
  }

  function writeStore(key, value) {
    try { global.localStorage.setItem(key, value); } catch (e) { /* ignore */ }
  }

  function currentLang() {
    return normalizeLang(readStore(STORAGE_LANG)) || LANG.EN;
  }

  function isManual() {
    return readStore(STORAGE_SOURCE) === 'manual';
  }

  function setLang(lang, source) {
    var next = normalizeLang(lang) || LANG.EN;
    writeStore(STORAGE_LANG, next);
    writeStore(STORAGE_SOURCE, source === 'manual' ? 'manual' : 'auto');
    applyPageCopy(next);
    global.dispatchEvent(new CustomEvent('medconnect:patient-ui-lang', { detail: { lang: next, source: source || 'auto' } }));
    return next;
  }

  function t(key, vars, lang) {
    var code = normalizeLang(lang) || currentLang();
    var table = STRINGS[code] || STRINGS.en;
    var text = table[key] || STRINGS.en[key] || key;
    if (vars && typeof vars === 'object') {
      Object.keys(vars).forEach(function (name) {
        text = text.replace(new RegExp('\\{' + name + '\\}', 'g'), String(vars[name] == null ? '' : vars[name]));
      });
    }
    return text;
  }

  function score(text, patterns) {
    var n = 0;
    for (var i = 0; i < patterns.length; i++) {
      if (patterns[i].test(text)) n += 1;
    }
    return n;
  }

  /**
   * UI language from the patient's own wording. Separate from medical classification.
   */
  function detectFromText(text) {
    var raw = String(text || '').trim().toLowerCase();
    if (!raw) return { lang: currentLang(), confidence: 0 };

    var hil = score(raw, [
      /\b(ginasakit|ginahilo|ginakulbaan|gahubag|gasakit|gaubo|gahabok)\b/,
      /\b(hilanat|lingin|nahilo|akon|imo|iya|gid|kag|sang|indi|subong|kalibanga)\b/,
      /\bsakit\s+ulo\s+ko\b/,
      /\bmasakit\s+akon\b/,
      /\bmay\s+hilanat\b/,
      /\bginahilo\s+ko\b/,
    ]);
    var fil = score(raw, [
      /\bmasakit\s+ang\b/,
      /\b(nahihilo|lagnat|mayroon|yung|naman|kasi|hindi)\b/,
      /\bmasakit\s+(ulo|tiyan|dibdib)\s+ko\b/,
      /\bnahihilo\s+ako\b/,
      /\bmay\s+lagnat\b/,
      /\bako\b/,
    ]);
    var en = score(raw, [
      /\b(my|i|i'm|im|have|has|hurts|hurt|pain|fever|dizzy|dizziness|headache)\b/,
      /\bmy\s+head\b/,
      /\bi\s+have\b/,
      /\bi\s+feel\b/,
    ]);

    // Short local complaints: "sakit ulo ko" is Hiligaynon; "masakit ang ulo ko" is Filipino.
    if (/\bsakit\s+\w+\s+ko\b/.test(raw) && !/\bmasakit\b/.test(raw) && !/\bang\b/.test(raw)) {
      hil += 2;
    }
    if (/\bmasakit\s+ang\b/.test(raw) || /\bnahihilo\s+ako\b/.test(raw)) {
      fil += 2;
    }

    var best = LANG.EN;
    var bestScore = en;
    if (fil > bestScore) { best = LANG.FIL; bestScore = fil; }
    if (hil > bestScore) { best = LANG.HIL; bestScore = hil; }
    if (bestScore <= 0) return { lang: currentLang(), confidence: 0 };
    return { lang: best, confidence: bestScore };
  }

  function langFromApi(payload) {
    if (!payload || typeof payload !== 'object') return '';
    var raw = payload.detected_language
      || (payload.summary && payload.summary.detected_language)
      || (payload.assessment && payload.assessment.detected_language)
      || '';
    var mapped = normalizeLang(raw);
    if (mapped) return mapped;
    if (String(raw).toLowerCase() === 'mixed' || String(raw).toLowerCase() === 'unknown') return '';
    return '';
  }

  /**
   * Resolve display language for a new triage result.
   * Prefer API detection, then complaint wording, so defaults match what the patient used.
   * Manual dropdown changes still update live via setLang(..., 'manual').
   */
  function resolveForComplaint(complaint, apiPayload) {
    var fromApi = langFromApi(apiPayload);
    if (fromApi) return setLang(fromApi, 'auto');
    var detected = detectFromText(complaint);
    if (detected.confidence > 0) return setLang(detected.lang, 'auto');
    return currentLang();
  }

  function applyPageCopy(lang) {
    var code = normalizeLang(lang) || currentLang();
    document.querySelectorAll('[data-i18n]').forEach(function (el) {
      var key = el.getAttribute('data-i18n');
      if (!key) return;
      var attr = el.getAttribute('data-i18n-attr');
      var value = t(key, null, code);
      if (attr) {
        el.setAttribute(attr, value);
      } else {
        el.textContent = value;
      }
    });
    var submit = document.getElementById('pdashSymptomsReviewSubmit');
    if (submit) {
      submit.dataset.defaultLabel = 'Submit patient complaint';
      if (!submit.disabled) submit.textContent = 'Submit patient complaint';
    }
    var langSelect = document.getElementById('mcPatientUrgencyLang');
    if (langSelect) {
      langSelect.setAttribute('aria-label', t('language', null, code));
      langSelect.value = code;
    }
  }

  function bindSelector(selectEl) {
    if (!selectEl || selectEl._mcLangBound) return;
    selectEl._mcLangBound = true;
    selectEl.disabled = false;
    selectEl.removeAttribute('disabled');
    selectEl.value = currentLang();
    selectEl.addEventListener('change', function () {
      var next = normalizeLang(selectEl.value) || LANG.EN;
      selectEl.value = next;
      setLang(next, 'manual');
    });
  }

  global.McPatientTriageI18n = {
    LANG: LANG,
    NAMES: NAMES,
    t: t,
    current: currentLang,
    set: setLang,
    isManual: isManual,
    detectFromText: detectFromText,
    langFromApi: langFromApi,
    resolveForComplaint: resolveForComplaint,
    applyPageCopy: applyPageCopy,
    bindSelector: bindSelector,
    normalizeLang: normalizeLang,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { applyPageCopy(currentLang()); });
  } else {
    applyPageCopy(currentLang());
  }
})(window);
