/**
 * medConnect FAQ Chatbot — Natural conversation helpers
 * Greetings · pain/sick · varied phrasing · closing lines
 */
(function (global) {
  'use strict';

  const GREETING_PATTERNS = [
    /^(hi|hello|hey|helo|hola)[\s!.?]*$/i,
    /^(good\s+morning|good\s+afternoon|good\s+evening|good\s+day)[\s!.?]*$/i,
    /^(kamusta|kumusta|musta|kumsta)[\s!.?]*$/i,
    /^(maayong\s+aga|maayong\s+hapon|maayong\s+gab[-i]?|maayong\s+adlaw)[\s!.?]*$/i,
    /^(magandang\s+umaga|magandang\s+hapon|magandang\s+gabi)[\s!.?]*$/i,
  ];

  /** Physical symptoms — not chest emergency (handled separately) */
  const PAIN_SICK_PATTERNS = [
    /\bsakit\s+ulo\b/i,
    /\bsakit\s+ang\s+ulo\b/i,
    /\bsakit\s+ulo\s+ko\b/i,
    /\bmay\s+hilanat\b/i,
    /\bmay\s+lagnat\b/i,
    /\bmay\s+hilanat\s+ko\b/i,
    /\bmay\s+lagnat\s+ako\b/i,
    /\bgasuka\s+ko\b/i,
    /\bnagsusuka\b/i,
    /\bnag\s+susuka\b/i,
    /\bi'?m\s+sick\b/i,
    /\bi\s+feel\s+sick\b/i,
    /\bnot\s+feeling\s+well\b/i,
    /\bmay\s+sakit\s+ako\b/i,
    /\bmasama\s+ang\s+pakiramdam\b/i,
    /\bmasakit\s+ang\s+tiyan\b/i,
    /\bsakit\s+lawas\s+ko\b/i,
    /\bsakit\s+lawas\b/i,
    /\bmay\s+sipon\b/i,
    /\bmay\s+ubo\b/i,
    /\bcough\b/i,
    /\bfever\b/i,
    /\bheadache\b/i,
    /\btummy\s+ache\b/i,
    /\bstomach\s+ache\b/i,
    /\bmasakit\s+ang\s+likod\b/i,
  ];

  const CHEST_EMERGENCY = [
    /\bsakit\s+(ang\s+)?dibdib\b/i,
    /\bmasakit\s+ang\s+dibdib\b/i,
    /\bchest\s+pain\b/i,
    /\bheart\s+attack\b/i,
    /\bginabatyag\s+ko\s+sakit\s+sa\s+dughan\b/i,
  ];

  const CLOSINGS = {
    en: [
      'Is there anything else I can help you with today?',
      'Would you like me to guide you through booking an appointment?',
      'Would you like help accessing your medical records?',
      'Can I assist you with another City Health Office service?',
      'Feel free to ask if you need help with anything else.',
    ],
    fil: [
      'May iba pa ba akong matutulungan sa iyo ngayon?',
      'Gusto mo bang gabayan kita sa pag-book ng appointment?',
      'Kailangan mo ba ng tulong sa pag-access ng medical records mo?',
      'Maaari ba kitang tulungan sa iba pang serbisyo ng City Health Office?',
      'Magtanong lang kung may kailangan ka pa.',
    ],
    hil: [
      'May iban pa bala nga matabangan ko ikaw subong?',
      'Gusto mo bala nga tuytuyan ko ikaw sa pag-book sang appointment?',
      'Kinahanglan mo bala sang bulig sa pag-access sang imo medical records?',
      'Matabangan ko bala ikaw sa iban pa nga serbisyo sang City Health Office?',
      'Magpamangkot lang kon may kinahanglan ka pa.',
    ],
  };

  const UNKNOWN_POOL = {
    en: [
      '<p>I\'m sorry, I couldn\'t quite understand your message. I can still help you with <strong>appointments</strong>, <strong>registration</strong>, <strong>consultations</strong>, and other City Health Office services.</p>',
      '<p>I\'d be happy to help. Could you explain your question a little differently? I can assist with medConnect accounts, appointments, and healthcare services.</p>',
      '<p>I couldn\'t find information related to that, but I\'m here to guide you with appointments, account access, video consultations, and other medConnect services.</p>',
    ],
    fil: [
      '<p>Paumanhin, hindi ko lubos na naintindihan ang iyong mensahe. Maaari pa rin kitang tulungan sa <strong>appointments</strong>, <strong>rehistrasyon</strong>, <strong>konsultasyon</strong>, at iba pang serbisyo ng City Health Office.</p>',
      '<p>Masaya akong tumulong. Maaari mo bang ipaliwanag ang iyong tanong nang kaunti? Makakatulong ako sa medConnect accounts, appointments, at healthcare services.</p>',
      '<p>Wala akong nakitang impormasyon tungkol diyan, ngunit nandito ako para gabayan ka sa appointments, account access, video consultations, at iba pang serbisyo ng medConnect.</p>',
    ],
    hil: [
      '<p>Pasensya na, indi ko gid maintindihan ang imo mensahe. Matabangan ko gihapon ikaw sa <strong>appointments</strong>, <strong>rehistrasyon</strong>, <strong>konsultasyon</strong>, kag iban pa nga serbisyo sang City Health Office.</p>',
      '<p>Malipayon ako nga makabulig. Pwede mo bala ipaliwanag ang imo pamangkot gamay? Makabulig ako sa medConnect accounts, appointments, kag healthcare services.</p>',
      '<p>Wala ako nakita nga impormasyon parte sina, pero diri ako para tuytuyan ka sa appointments, account access, video consultations, kag iban pa nga serbisyo sang medConnect.</p>',
    ],
  };

  const CLARIFY_POOL = {
    en: [
      '<p>No worries — I\'m sorry I couldn\'t quite understand. Could you rephrase your question? I\'ll gladly walk you through it step by step.</p>',
      '<p>I\'d be happy to help. Could you explain your question a little differently? I can guide you with medConnect and City Health Office services.</p>',
    ],
    fil: [
      '<p>Walang problema — paumanhin, hindi ko lubos na naintindihan. Maaari mo bang i-rephrase ang tanong mo? Gagabayan kita nang hakbang-hakbang.</p>',
      '<p>Masaya akong tumulong. Maaari mo bang ipaliwanag ang tanong mo nang kaunti? Makakatulong ako sa medConnect at City Health Office services.</p>',
    ],
    hil: [
      '<p>Wala problema — pasensya na, indi ko gid maintindihan. Pwede mo bala i-rephrase ang imo pamangkot? Tuytuyan ko ikaw step-by-step.</p>',
      '<p>Malipayon ako nga makabulig. Pwede mo bala ipaliwanag ang imo pamangkot gamay? Makabulig ako sa medConnect kag City Health Office services.</p>',
    ],
  };

  let turnCounter = 0;

  function hashSeed(seed) {
    const s = String(seed || '');
    let h = turnCounter;
    for (let i = 0; i < s.length; i++) {
      h = ((h << 5) - h) + s.charCodeAt(i);
      h |= 0;
    }
    return Math.abs(h);
  }

  function pickFromPool(pool, lang, seed) {
    const L = pool[lang] ? lang : 'en';
    const items = pool[L] || pool.en;
    return items[hashSeed(seed) % items.length];
  }

  function isGreeting(text) {
    const raw = String(text || '').trim();
    if (!raw || raw.length > 48) return false;
    return GREETING_PATTERNS.some((re) => re.test(raw));
  }

  function isPainOrSick(text) {
    const raw = String(text || '');
    if (!raw) return false;
    if (CHEST_EMERGENCY.some((re) => re.test(raw))) return false;
    const Emotions = global.McFaqEmotions;
    if (Emotions && Emotions.isMedicalEmergency && Emotions.isMedicalEmergency(raw)) return false;
    return PAIN_SICK_PATTERNS.some((re) => re.test(raw));
  }

  function getClosing(lang, seed) {
    const L = CLOSINGS[lang] ? lang : 'en';
    const items = CLOSINGS[L];
    turnCounter += 1;
    return items[hashSeed(seed || turnCounter) % items.length];
  }

  function getUnknownHtml(lang, seed) {
    turnCounter += 1;
    return pickFromPool(UNKNOWN_POOL, lang, seed);
  }

  function getClarifyHtml(lang, seed) {
    turnCounter += 1;
    return pickFromPool(CLARIFY_POOL, lang, seed);
  }

  global.McFaqConversation = {
    isGreeting,
    isPainOrSick,
    getClosing,
    getUnknownHtml,
    getClarifyHtml,
  };
})(window);
