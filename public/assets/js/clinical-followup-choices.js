/**
 * Phase A — structured follow-up choice chips.
 * Writes extractor-known strings into the existing followup_answer field only.
 * Does not send structured_answer payloads.
 */
(function (global) {
  'use strict';

  function normalizeLang(lang) {
    var q = String(lang || '').toLowerCase();
    if (q.indexOf('tagalog') !== -1 || q.indexOf('filipino') !== -1) return 'tagalog';
    if (q.indexOf('hiligaynon') !== -1 || q.indexOf('ilonggo') !== -1) return 'hiligaynon';
    if (q.indexOf('english') !== -1) return 'english';
    return 'english';
  }

  function isPainScaleText(text) {
    var q = String(text || '');
    return /1\s*(tubtob|to|hanggang|-|–|—)\s*10/i.test(q)
      || /0\s*(tubtob|to|hanggang|-|–|—)\s*10/i.test(q)
      || /scale\s*(of|nga)?\s*[01]/i.test(q)
      || /[01]\s*(out of|\/)\s*10/i.test(q)
      || /gaano\s+kasakit/i.test(q)
      || /how\s+(bad|severe)\b/i.test(q)
      || /pain\s*score/i.test(q);
  }

  /**
   * Mirror ClinicalFollowUpAnswerValidator::expectedKind → UI control kind.
   * Universal from question_id (not complaint-specific).
   */
  function resolveControlKind(questionId, questionText) {
    var qid = String(questionId || '').toUpperCase().trim();
    var base = qid.indexOf('__') !== -1 ? qid.split('__')[0] : qid;

    var freeTextIds = {
      ASSOCIATED_DETAIL: 1,
      DIZZINESS_TYPE: 1,
      COUGH_TYPE: 1,
      URINARY_DETAIL: 1,
      UNWELL_WHAT: 1,
      FEVER_CONFIRM: 1
    };
    if (freeTextIds[base] || base.indexOf('URINARY') === 0) {
      return 'free_text';
    }
    if (base === 'PAIN_SEVERITY' || (base.indexOf('SEVERITY') !== -1 && base !== '' && base !== 'BREATHING_SEVERITY')) {
      return 'pain';
    }
    if (base === 'ONSET') {
      return 'onset';
    }
    if (base === 'DURATION' || base.indexOf('DURATION') !== -1) {
      return 'duration';
    }
    if (
      base.indexOf('LOCATION') !== -1
      || base.indexOf('WHERE') !== -1
      || base.indexOf('SITE') !== -1
      || base.indexOf('LATERALITY') !== -1
      || base === 'PAIN_LOCATION'
      || base === 'SPECIFIC_LOCATION'
      || base === 'SKIN_SITE'
      || base === 'NOSE_PAIN_WHERE'
      || base === 'EYE_LATERALITY'
    ) {
      return 'location';
    }
    if (
      base === 'BREATHING_SEVERITY'
      || base === 'ASSOCIATED_SYMPTOMS'
      || base.indexOf('NEURO') !== -1
      || base.indexOf('BLEEDING') !== -1
      || base.indexOf('BREATHING') !== -1
      || base.indexOf('CHEST') !== -1
      || base.indexOf('VISION') !== -1
      || base.indexOf('ABDOMINAL') !== -1
      || base.indexOf('FINDING_') === 0
      || qid.indexOf('__') !== -1
    ) {
      return 'yes_no';
    }
    if (!base && isPainScaleText(questionText)) {
      return 'pain';
    }
    return 'free_text';
  }

  function L(lang, en, tl, hil) {
    if (lang === 'tagalog') return tl;
    if (lang === 'hiligaynon') return hil;
    return en;
  }

  /** Display label + canonical followup_answer value extractors already understand. */
  function yesNoChoices(lang) {
    return [
      { id: 'yes', label: L(lang, 'Yes', 'Oo', 'Oo'), value: L(lang, 'yes', 'oo', 'oo') },
      { id: 'no', label: L(lang, 'No', 'Hindi', 'Indi'), value: L(lang, 'no', 'hindi', 'indi') },
      { id: 'unsure', label: L(lang, 'Not sure', 'Hindi ako sure', 'Indi ko sure'), value: L(lang, 'not sure', 'hindi ako sure', 'indi ko sure') },
      { id: 'other', label: L(lang, 'Other', 'Iba pa', 'Iban pa'), value: '', other: true }
    ];
  }

  function onsetChoices(lang) {
    return [
      { id: 'today', label: L(lang, 'Today', 'Ngayon', 'Subong'), value: L(lang, 'today', 'ngayon', 'subong') },
      { id: 'yesterday', label: L(lang, 'Yesterday', 'Kahapon', 'Gahapon'), value: L(lang, 'yesterday', 'kahapon', 'gahapon') },
      { id: 'earlier', label: L(lang, 'Earlier', 'Kanina', 'Kanina'), value: L(lang, 'earlier', 'kanina', 'kanina') },
      { id: 'sudden', label: L(lang, 'Sudden', 'Biglaan', 'Gulpi'), value: L(lang, 'sudden', 'bigla', 'gulpi') },
      { id: 'gradual', label: L(lang, 'Gradual', 'Unti-unti', 'Hinay-hinay'), value: L(lang, 'gradual', 'unti-unti', 'hinay-hinay') },
      { id: 'date', label: L(lang, 'Specific date', 'Tiyak na petsa', 'Espesipiko nga petsa'), value: '', date: true },
      { id: 'other', label: L(lang, 'Other', 'Iba pa', 'Iban pa'), value: '', other: true }
    ];
  }

  function durationChoices(lang) {
    return [
      { id: 'today', label: L(lang, 'Today', 'Ngayon', 'Subong'), value: L(lang, 'today', 'ngayon', 'subong') },
      { id: 'yesterday', label: L(lang, 'Since yesterday', 'Mula kahapon', 'Halin gahapon'), value: L(lang, 'since yesterday', 'kahapon', 'halin gahapon') },
      { id: '2d', label: L(lang, '2 days', '2 araw', '2 ka adlaw'), value: L(lang, '2 days', '2 araw', '2 ka adlaw') },
      { id: '3d', label: L(lang, '3 days', '3 araw', '3 ka adlaw'), value: L(lang, '3 days', '3 araw', '3 ka adlaw') },
      { id: '1w', label: L(lang, '1 week', '1 linggo', '1 ka semana'), value: L(lang, '1 week', '1 linggo', 'isa ka semana') },
      { id: '2w', label: L(lang, '2 weeks', '2 linggo', '2 ka semana'), value: L(lang, '2 weeks', '2 linggo', '2 ka semana') },
      { id: '1m', label: L(lang, '1 month', '1 buwan', '1 ka bulan'), value: L(lang, '1 month', '1 buwan', 'isa ka bulan') },
      { id: 'other', label: L(lang, 'Other', 'Iba pa', 'Iban pa'), value: '', other: true }
    ];
  }

  function locationChoices(lang) {
    return [
      { id: 'head', label: L(lang, 'Head', 'Ulo', 'Ulo'), value: L(lang, 'head', 'ulo', 'ulo') },
      { id: 'eye', label: L(lang, 'Eye', 'Mata', 'Mata'), value: L(lang, 'eye', 'mata', 'mata') },
      { id: 'ear', label: L(lang, 'Ear', 'Tainga', 'Dalunggan'), value: L(lang, 'ear', 'tainga', 'dalunggan') },
      { id: 'throat', label: L(lang, 'Throat', 'Lalamunan', 'Tutunlan'), value: L(lang, 'throat', 'lalamunan', 'tutunlan') },
      { id: 'chest', label: L(lang, 'Chest', 'Dibdib', 'Dughan'), value: L(lang, 'chest', 'dibdib', 'dughan') },
      { id: 'abdomen', label: L(lang, 'Abdomen', 'Tiyan', 'Tiyan'), value: L(lang, 'abdomen', 'tiyan', 'tiyan') },
      { id: 'back', label: L(lang, 'Back', 'Likod', 'Likod'), value: L(lang, 'back', 'likod', 'likod') },
      { id: 'neck', label: L(lang, 'Neck', 'Leeg', 'Liog'), value: L(lang, 'neck', 'leeg', 'liog') },
      { id: 'arm', label: L(lang, 'Arm', 'Brasos', 'Kamot'), value: L(lang, 'arm', 'braso', 'kamot') },
      { id: 'hand', label: L(lang, 'Hand', 'Kamay', 'Kamot'), value: L(lang, 'hand', 'kamay', 'kamot') },
      { id: 'leg', label: L(lang, 'Leg', 'Binti', 'Batiis'), value: L(lang, 'leg', 'binti', 'batiis') },
      { id: 'foot', label: L(lang, 'Foot', 'Paa', 'Tiil'), value: L(lang, 'foot', 'paa', 'tiil') },
      { id: 'other', label: L(lang, 'Other', 'Iba pa', 'Iban pa'), value: '', other: true }
    ];
  }

  function choicesForKind(kind, lang) {
    if (kind === 'yes_no') return yesNoChoices(lang);
    if (kind === 'onset') return onsetChoices(lang);
    if (kind === 'duration') return durationChoices(lang);
    if (kind === 'location') return locationChoices(lang);
    return [];
  }

  function helperText(kind, lang) {
    lang = normalizeLang(lang);
    if (kind === 'pain') {
      return L(lang,
        'Tap a number from 1 to 10, or type your answer (for example: 5, 7/10, or “grabe”).',
        'Pindutin ang numero 1 hanggang 10, o i-type ang sagot (hal. 5, 7/10).',
        'Pinduta ang numero 1 tubtob 10, ukon i-type ang sabat (hal. 5, 7/10).'
      );
    }
    return '';
  }

  /** End-cap captions for the 1–10 pain scale (same normalizeLang as helpers/chips). */
  function painScaleLabels(lang) {
    lang = normalizeLang(lang);
    return {
      start: L(lang, '1 = very mild', '1 = napakagaan', '1 = mahinay gid'),
      end: L(lang, '10 = worst pain', '10 = pinakamalalang sakit', '10 = pinakagrabe')
    };
  }

  function applyPainScaleLabels(scaleEl, lang) {
    if (!scaleEl) return;
    var labels = painScaleLabels(lang);
    var nodes = scaleEl.querySelectorAll('.pdash-followup__scale-label');
    if (nodes[0]) nodes[0].textContent = labels.start;
    if (nodes[1]) nodes[1].textContent = labels.end;
  }

  function clear(container) {
    if (!container) return;
    container.innerHTML = '';
    container.hidden = true;
    container.removeAttribute('data-kind');
  }

  /**
   * @param {HTMLElement} container
   * @param {{ kind: string, lang: string, answerEl: HTMLTextAreaElement|HTMLInputElement, onSelect?: Function }} opts
   */
  function render(container, opts) {
    opts = opts || {};
    var kind = String(opts.kind || 'free_text');
    // Keep 1–10 pain scale in page HTML. Do not paint yes/no, onset, duration, or location chips.
    clear(container);
    return kind;
  }

  global.McFollowupChoices = {
    normalizeLang: normalizeLang,
    isPainScaleText: isPainScaleText,
    resolveControlKind: resolveControlKind,
    helperText: helperText,
    painScaleLabels: painScaleLabels,
    applyPainScaleLabels: applyPainScaleLabels,
    render: render,
    clear: clear
  };
})(typeof window !== 'undefined' ? window : this);
