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
    ['okay lang ko', 'okay i am fine relieved'],
    ['okay lang', 'okay fine relieved'],
    ['kapoy ko', 'i am tired exhausted'],
    ['ginakapoy ko', 'i am getting tired exhausted'],
    ['nasubo ko', 'i am sad feeling down'],
    ['malipayon ko', 'i am happy glad'],
    ['nahadlok ko', 'i am scared afraid'],
    ['kulbaan ko', 'i am anxious nervous'],
    ['nabudlayan ko', 'i am frustrated struggling'],
    ['na stress ko', 'i am stressed'],
    ['na-stress ko', 'i am stressed'],
    ['akig ko', 'i am angry'],
    ['lain gid ya', 'this is frustrating annoying'],
    ['lain gid', 'frustrated annoying'],
    ['indi ko kabalo', 'i do not know confused uncertain'],
    ['indi ko bal an', 'i do not know confused uncertain'],
    ['wala ko kasabot', 'i do not understand confused'],
    ['wala ko kaintindi', 'i do not understand confused'],
    ['ano ni', 'what is this confused'],
    ['ano ini', 'what is this confused'],
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
    ['gaulan pa daan', 'raining bad weather cannot go out'],
    ['grabe ang ulan', 'heavy rain bad weather'],
    ['indi ko makaguwa', 'cannot go outside bad weather'],
    ['wala signal gid', 'no signal connectivity problem'],
    ['nadula signal', 'lost signal connectivity problem'],
    ['gadula signal ko', 'signal dropping connectivity problem'],
    ['hinay signal', 'weak signal connectivity problem'],
    ['wala internet', 'no internet connectivity problem'],
    ['putol-putol ang connection', 'intermittent connection connectivity problem'],
    ['ga lag ang video', 'video lag connectivity problem'],
    ['di ko ka-video call', 'cannot video call connectivity problem'],
    ['wala ko kabati', 'cannot hear audio connectivity problem'],
    ['wala ko masakyan', 'no transportation cannot get ride'],
    ['layo amon', 'far away transportation distance problem'],
    ['budlay magkadto', 'hard to get there transportation problem'],
    ['wala ko pamasahe', 'no fare money transportation financial'],
    ['wala ko budget', 'no budget financial problem'],
    ['indi ko kaya magbayad', 'cannot afford pay financial problem'],
    ['masaligan ni bala', 'is this trustworthy safe reliable'],
    ['safe bala ni', 'is this safe secure'],
    ['confidential bala ni', 'is this confidential privacy'],
    ['makita bala ni sang iban', 'can others see this privacy'],
    ['tinuod bala ni', 'is this real true trustworthy'],
    ['diin ko makadto', 'where should i go navigation confused'],
    ['ano ubrahon ko', 'what should i do confused'],
    ['tabangi ko bi', 'help me please'],
    ['buligi ko bi', 'help me please'],
    ['indi ko kasabat', 'i do not understand confused'],
    ['gakulbaan ko magpa-check up', 'anxious about checkup scared of doctor'],
    ['di ko na kaya', 'cannot take it anymore hopeless distressed'],
    ['wala na pulos', 'nothing left hopeless'],
    ['wala gid ko may kastorya', 'lonely no one to talk to'],
    ['paano na lang ni', 'what now worried uncertain'],
    ['gaulan kag wala signal', 'raining and no signal weather connectivity problems'],
    ['hays', 'sigh sad tired'],
    ['hay naku', 'oh no sigh sad worried'],
    ['tani okay lang', 'hopefully okay relieved hopeful'],
    ['naguol ko', 'i am grieving sad'],
    ['naghuoy ko', 'i am weary sad tired'],
    ['nagkasubo ko', 'i am sad grieving'],
    ['subo gid ko', 'i am very sad'],
    ['badtrip ko', 'frustrated badtrip'],
    ['naglibog ko', 'i am confused'],
    ['kinabahan ko', 'i am nervous anxious'],
    ['sobra stress ko', 'very stressed overwhelmed'],
    ['nahuya gid ko', 'very embarrassed ashamed'],
    ['may guilt ko', 'guilty feeling guilt'],
    ['naiinggit ko', 'jealous envious'],
    ['miss ko ang pamilya', 'miss my family lonely homesick'],
    ['buligi ko daw', 'help me please panic'],
    ['kinahanglan ko sang dulungan', 'need help urgent distress'],
    ['gasakit lawas ko', 'body pain sick'],
    ['may sipon ko', 'have cold sick cough'],
    ['may ubo ko', 'have cough sick'],
    ['budlay ginhawa', 'difficulty breathing worried'],
    ['indi ko kaginhawa', 'cannot breathe difficulty breathing'],
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
    malipayon: 'happy glad',
    nasubo: 'sad',
    nabudlay: 'frustrated struggling',
    nabudlayan: 'frustrated struggling',
    naguol: 'grieving sad',
    naghuoy: 'weary sad tired',
    nagkasubo: 'sad grieving',
    naglibog: 'confused',
    kinabahan: 'nervous anxious',
    nahuya: 'embarrassed ashamed',
    naiinggit: 'jealous',
    selos: 'jealous',
    inggit: 'jealous',
    gasuka: 'nauseous sick',
    sipon: 'cold sick cough',
    ginhawa: 'breathing',
    kaginhawa: 'breathing',
    dulungan: 'help support',
    grabe: 'intense surprised stressed',
    bwesit: 'frustrated annoyed',
    yawa: 'frustrated annoyed',
    kasabot: 'understand',
    kaintindi: 'understand',
    'bal-an': 'know',
    kabalo: 'know',
    lain: 'bad wrong frustrating',
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

  const HIL_MARKERS = /\b(gid|guid|sang|kag|indi|nabalaka|nahadlok|kasubo|kapoy|nalibog|buligi|tabangi|diin|subong|kon|nga|halin|amo|maayong|kumusta|musta|palihog|lawas|dughan|hilanat|nakalimtan|rehistro|konsultasyon|nasubo|malipayon|kulbaan|kasabot|kabalo|nabudlay|gaulan|signal|internet|pamasahe|masaligan|gakulbaan|ginakapoy)\b/i;

  const SHORTHAND_MAP = [
    ['sakit kag d nko kaginhawa', 'sakit kag indi ko kaginhawa'],
    ['d nako kaginhawa', 'indi ko kaginhawa'],
    ['d nko kaginhawa', 'indi ko kaginhawa'],
    ['budlay ginhwa', 'budlay ginhawa'],
    ['d nako', 'indi ko'],
    ['d nko', 'indi ko'],
    ['d ko', 'indi ko'],
    ['wla ko', 'wala ko'],
    ['wla', 'wala'],
    ['dko', 'indi ko'],
    ['ndi ko', 'indi ko'],
    ['ginhwa', 'ginhawa'],
  ];

  function expandShorthand(text) {
    let work = String(text || '').toLowerCase();
    SHORTHAND_MAP.forEach(([from, to]) => {
      if (work.includes(from)) work = work.split(from).join(to);
    });
    return work;
  }

  function normalize(text) {
    return expandShorthand(text)
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
      uncertain: 'Wala problema — ipahayag ko sing malinaw agod makadesisyon ka.',
      mixed: 'Natural ang magkahalong pamatyag — dula lang, suportado lang ta.',
      embarrassed: 'Okay lang mahuya — diri ako nga wala hatol.',
      grief: 'Nagakaluoy gid ako sa imo gina-agi.',
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
