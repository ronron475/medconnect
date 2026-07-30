/**
 * Hiligaynon → English bridge for FAQ chatbot NLP (emotion, intent, FAQ).
 * User sees Hiligaynon replies; matching runs on English gloss.
 */
(function (global) {
  'use strict';

  const HIL_PHRASES = [
    ['buot ko nga magpakamatay', 'i want to commit suicide'],
    ['gusto ko na lang mamatay', 'i want to die'],
    ['indi ko na gusto mabuhi', 'i do not want to live anymore'],
    ['wala na ako paglaum sa kinabuhi', 'hopeless no reason to live'],
    ['patyon ko ang kaugalingon', 'kill myself'],
    ['buot ko mamatay', 'i want to die'],
    ['gusto ko mamatay', 'i want to die'],
    ['indi ko gusto mabuhi', 'i do not want to live'],
    ['wala ko gusto mabuhi', 'i do not want to live'],
    ['going to die na ako', 'going to die'],
    ['mamatay na lang ako', 'going to die'],
    ['mamatay na ako', 'going to die'],
    ['nabalaka gid ako subong', 'i am very worried today'],
    ['ginakulbaan gid ako', 'i am very anxious'],
    ['nahadlok gid ako subong', 'i am very scared today'],
    ['kasubo gid ako subong', 'i feel very sad today'],
    ['kapoy gid ako subong', 'i am so tired today'],
    ['stressed gid ako', 'i am so stressed'],
    ['grabeng stress ko', 'very stressed'],
    ['wala na ako gana', 'overwhelmed tired'],
    ['naga hibi ako', 'crying sad'],
    ['naga hilib ako', 'crying sad'],
    ['nabalaka gid ako', 'i am very worried'],
    ['ginakulbaan ako', 'i am anxious'],
    ['nahadlok gid ako', 'i am very scared'],
    ['kasubo ako subong', 'i feel sad today'],
    ['kapoy gid ako', 'i am so tired'],
    ['nalibog gid ako', 'i am very confused'],
    ['frustrated gid ako', 'i am very frustrated'],
    ['isa lang ako', 'lonely'],
    ['wala ako makigstorya', 'lonely'],
    ['indi ko maintindihan', 'i do not understand'],
    ['indi ko ma intiendihan', 'i do not understand'],
    ['libog gid ako', 'confused'],
    ['paano mag register sa medconnect', 'how to register medconnect'],
    ['paano magrehistro sa medconnect', 'how to register medconnect'],
    ['paano mag register', 'how to register'],
    ['paano magrehistro', 'how to register'],
    ['paano mag sign in', 'how to sign in'],
    ['paano mag login', 'how to login'],
    ['paano mag log in', 'how to login'],
    ['paano mag book sang appointment', 'how to book appointment'],
    ['paano mag book', 'how to book appointment'],
    ['paano mag schedule', 'how to schedule appointment'],
    ['paano mag konsulta online', 'how to online consultation'],
    ['paano mag konsulta', 'how to consultation'],
    ['paano mag video call', 'how to video consultation'],
    ['paano mag reset sang password', 'how to reset password'],
    ['paano i reset ang password', 'how to reset password'],
    ['gusto ko mag konsulta', 'i want consultation'],
    ['gusto ko mag book', 'i want book appointment'],
    ['kinahanglan ko mag konsulta', 'i need consultation'],
    ['kinahanglan ko appointment', 'i need appointment'],
    ['nakalimtan ko ang password', 'i forgot my password'],
    ['nakalimtan ko password', 'i forgot my password'],
    ['nakalimtan ko ang akon password', 'i forgot my password'],
    ['forgot ko password', 'i forgot my password'],
    ['mag book sang appointment', 'book appointment'],
    ['mag schedule sang appointment', 'schedule appointment'],
    ['status sang appointment', 'appointment status'],
    ['video konsultasyon', 'video consultation'],
    ['online konsultasyon', 'online consultation'],
    ['medical record', 'medical records'],
    ['medical history', 'medical records'],
    ['health summary', 'health summary records'],
    ['digital prescription', 'prescription'],
    ['reseta ko', 'my prescription'],
    ['notification ko', 'notifications'],
    ['office hours', 'office hours'],
    ['oras sang opisina', 'office hours'],
    ['contact support', 'contact support'],
    ['tawag sa support', 'contact support'],
    ['city health office', 'city health office services'],
    ['nabalaka ako', 'i am worried'],
    ['nahadlok ako', 'i am scared'],
    ['kasubo ako', 'i am sad'],
    ['kapoy ako', 'i am tired'],
    ['akig ako', 'i am angry'],
    ['nalibog ako', 'i am confused'],
    ['masadya ako', 'i am happy'],
    ['salamat gid', 'thank you very much'],
    ['damo nga salamat', 'thank you very much'],
    ['salamat guid', 'thank you'],
    ['buligi ko palihog', 'help me please'],
    ['kinahanglan ko bulig', 'i need help'],
    ['buligi ko', 'help me'],
    ['tabangi ko', 'help me'],
    ['masakit ang lawas ko', 'body pain sick'],
    ['masakit ang lawas', 'body pain sick'],
    ['may sakit ako', 'i am sick'],
    ['may hilanat ako', 'i have fever sick'],
    ['may lagnat ako', 'i have fever sick'],
    ['sakit ulo ko', 'headache'],
    ['sakit ulo', 'headache'],
    ['sakit tiyan', 'stomach pain'],
    ['sakit ang dughan', 'chest pain'],
    ['sakit dughan', 'chest pain'],
    ['indi makahinga', 'cannot breathe difficulty breathing'],
    ['indi makaginhawa', 'cannot breathe difficulty breathing'],
    ['grabeng pagdugo', 'severe bleeding'],
    ['wala siya malay', 'unconscious'],
    ['nawad an malay', 'unconscious'],
    ['wala paglaum', 'hopeless'],
    ['wala na paglaum', 'hopeless'],
    ['kumusta', 'hello greeting'],
    ['musta', 'hello greeting'],
    ['maayong aga', 'good morning greeting'],
    ['maayong hapon', 'good afternoon greeting'],
  ];

  const HIL_TOKENS = {
    nabalaka: 'worried',
    kabalaka: 'worried',
    ginakulbaan: 'anxious',
    kulba: 'anxious',
    nahadlok: 'scared afraid',
    takot: 'afraid',
    kasubo: 'sad',
    subo: 'sad',
    kapoy: 'tired',
    pagod: 'tired',
    akig: 'angry',
    badtrip: 'frustrated',
    nalibog: 'confused',
    libog: 'confused',
    masadya: 'happy',
    salamat: 'thankful',
    bulig: 'help',
    buligi: 'help me',
    tabangi: 'help me',
    rehistro: 'register',
    magrehistro: 'register',
    konsultasyon: 'consultation',
    konsulta: 'consultation',
    telemedicine: 'telemedicine',
    appointment: 'appointment',
    schedule: 'schedule',
    password: 'password',
    nakalimtan: 'forgot',
    nakalimot: 'forgot',
    reseta: 'prescription',
    bulong: 'medicine prescription',
    record: 'medical records',
    hilanat: 'fever sick',
    lagnat: 'fever sick',
    masakit: 'pain sick',
    sakit: 'pain',
    dughan: 'chest',
    tiyan: 'stomach',
    ulo: 'headache',
    ubo: 'cough sick',
    sipon: 'cold sick',
    lawas: 'body',
    doktor: 'doctor',
    pasyente: 'patient',
    opisina: 'office',
    oras: 'hours schedule',
    tawag: 'call contact',
    subong: 'today',
    yadto: 'yesterday',
    buwas: 'tomorrow',
    pwede: 'can',
    puwede: 'can',
    gusto: 'want',
    kinahanglan: 'need',
    ko: 'i',
    ako: 'i',
    imo: 'you',
    indi: 'not',
    wala: 'none',
    paano: 'how',
    diin: 'where',
    ano: 'what',
    san-o: 'when',
    sano: 'when',
    kon: 'if',
    nga: '',
    gid: '',
    guid: '',
    sang: '',
    kag: '',
    man: '',
    lang: '',
    palihog: 'please',
    medconnect: 'medconnect',
  };

  const HIL_MARKERS = /\b(gid|guid|sang|kag|indi|nabalaka|nahadlok|kasubo|kapoy|nalibog|buligi|tabangi|diin|subong|kon|nga|halin|amo|maayong|kumusta|musta|palihog|lawas|dughan|hilanat|nakalimtan|rehistro|konsultasyon)\b/i;

  function normalize(text) {
    return String(text || '')
      .toLowerCase()
      .replace(/[^\w\s\u00C0-\u024F'-]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function looksHiligaynon(text) {
    const t = normalize(text);
    if (!t) return false;
    const hits = (t.match(HIL_MARKERS) || []).length;
    return hits >= 2 || (hits >= 1 && /\b(paano|ano)\b/i.test(t));
  }

  function hilToEnglish(text) {
    let work = normalize(text);
    if (!work) return '';
    HIL_PHRASES.forEach(([hil, en]) => {
      if (work.includes(hil)) work = work.split(hil).join(en);
    });
    const parts = work.split(/\s+/).filter(Boolean);
    const out = parts.map((tok) => (HIL_TOKENS[tok] !== undefined ? HIL_TOKENS[tok] : tok)).filter((w) => w !== '');
    return out.join(' ').replace(/\s+/g, ' ').trim();
  }

  /**
   * @param {string} text
   * @param {string} lang from McFaqLanguage
   * @returns {{ replyLang: string, nlpText: string, englishGloss: string, isHiligaynon: boolean }}
   */
  function prepare(text, lang) {
    const original = String(text || '').trim();
    const L = lang || 'en';
    const isHil = L === 'hil' || looksHiligaynon(original);
    if (!isHil && L !== 'fil') {
      return { replyLang: L, nlpText: original, englishGloss: '', isHiligaynon: false };
    }
    const englishGloss = isHil ? hilToEnglish(original) : original;
    const nlpText = englishGloss || original;
    return {
      replyLang: isHil ? 'hil' : 'fil',
      nlpText,
      englishGloss: isHil ? englishGloss : '',
      isHiligaynon: isHil,
    };
  }

  function bilingualEmpathyHtml(canonical, englishLine) {
    const hilMap = {
      worried: 'Nakaintindi ko sang imo kabalaka.',
      sad: 'Pasensya nga amo sini ang imo nabatyagan.',
      fearful: 'Natural lang mahadlok — diri ako para suportahan ikaw.',
      angry: 'Pasensya sa abala — tabangan ta ka.',
      frustrated: 'Pasensya sa abala — tuytuyan ta ini.',
      lonely: 'Indi ka isa lang — diri ako para buligan ka.',
      tired: 'Mabug-at gid — magpahuway lang anay.',
      confused: 'Wala problema — ipahayag ko sing malinaw.',
      happy: 'Maayo nga mabatian!',
    };
    const hil = hilMap[canonical] || 'Diri ako para buligan ka.';
    const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return `<p class="fcb-bilingual-lead"><span lang="hil">${esc(hil)}</span> <span lang="en"><em>${esc(englishLine)}</em></span></p>`;
  }

  global.McFaqHilBridge = {
    prepare,
    hilToEnglish,
    looksHiligaynon,
    bilingualEmpathyHtml,
  };
})(window);
