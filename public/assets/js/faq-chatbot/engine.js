/**
 * medConnect FAQ Chatbot — FAQ Engine, policies & emergency detection
 * Multilingual: English · Filipino · Hiligaynon
 */
(function (global) {
  'use strict';

  const I18n = global.McFaqI18n;
  const BOT_NAME = 'medConnect Assistant';

  const EMERGENCY_PATTERNS = [
    /\bchest\s+pain\b/i, /\bheart\s+attack\b/i, /\bstroke\b/i,
    /\bsevere\s+bleeding\b/i,
    /\bunconscious\b/i, /\bpassed\s+out\b/i, /\bcan'?t\s+breathe\b/i,
    /\bdifficulty\s+breathing\b/i, /\btrouble\s+breathing\b/i, /\bnot\s+breathing\b/i,
    /\bchoking\b/i, /\bseizure\b/i, /\boverdose\b/i, /\bpoisoning\b/i, /\bmedical\s+emergency\b/i,
    // Filipino
    /\bmasakit\s+ang\s+dibdib\b/i, /\bhirap\s+huminga\b/i, /\bdi\s+makahinga\b/i,
    /\bpagdurugo\b/i, /\bmalubhang\s+dugo\b/i, /\bwalang\s+malay\b/i,
    /\bnawalan\s+ng\s+malay\b/i, /\blason\b/i, /\bnalason\b/i,
    // Hiligaynon
    /\bsakit\s+ang\s+dibdib\b/i, /\bindi\s+makaginhawa\b/i, /\bindi\s+makahinga\b/i,
    /\bgrabeng\s+dugo\b/i, /\bwala\s+malay\b/i, /\bnawad-an\s+malay\b/i,
    /\blason\b/i, /\bnalason\b/i,
  ];

  const MEDICAL_ADVICE_PATTERNS = [
    /\bdiagnos/i, /\bprescrib/i, /\bwhat\s+medicine\b/i, /\bwhat\s+medication\b/i,
    /\blab\s+result/i, /\bblood\s+test\b/i, /\binterpret\s+my\b/i,
    /\bshould\s+i\s+take\b/i, /\bis\s+it\s+cancer\b/i, /\bam\s+i\s+sick\b/i,
    /\btreatment\s+for\b/i, /\bdosage\b/i,
    /\banong\s+gamot\b/i, /\bano\s+ang\s+gamot\b/i, /\breseta\b/i,
    /\bano\s+ang\s+bulong\b/i, /\bano\s+ang\s+tambal\b/i,
  ];

  const KEYWORD_HINTS = [
    {
      keys: [
        'sign in', 'signin', 'login', 'log in', 'sulod', 'mag login', 'mag-login', 'mag-sign in',
        'paano mag login', 'paano mag-sign in', 'diin mag login', 'indi ako maka-login',
        'maka-login', 'hindi ako maka-login', 'maka login', 'paano mag sign in',
      ],
      target: 'signin',
    },
    {
      keys: [
        'register', 'create account', 'sign up', 'signup', 'rehistro', 'magrehistro',
        'paano magrehistro', 'paano mag register', 'gumawa ng account', 'maghimo sang account',
        'bagong account', 'new account',
      ],
      target: 'register',
    },
    {
      keys: [
        'password', 'reset', 'forgot', 'kalimtan', 'nakalimtan', 'nakalimutan', 'nakalimot',
        'forgot ko', 'reset password', 'mag-reset', 'i-reset',
      ],
      target: 'reset',
    },
    {
      keys: [
        'appointment', 'book', 'schedule', 'consultation schedule', 'mag-book',
        'maka-book', 'mag book', 'paano mag-book', 'diin maka-book', 'appointment status',
        'status sang appointment', 'status ng appointment',
        'consult', 'consultation', 'konsultasyon', 'konsulta', 'mag-konsulta', 'mag konsulta',
        'want to consult', 'need to consult', 'need consultation', 'want consultation',
        'gusto mag-consult', 'gusto mag consult', 'kailangan mag-consult',
      ],
      target: 'appointment',
    },
    {
      keys: ['appointment status', 'status ng appointment', 'status sang appointment', 'na-confirm', 'nakumpirma'],
      target: 'appointment_status',
    },
    {
      keys: [
        'video', 'call', 'online consult', 'telemedicine', 'video consult', 'video konsultasyon',
        'video consultation', 'online consultation',
      ],
      target: 'video',
    },
    {
      keys: ['record', 'medical history', 'medical record', 'emr', 'my health', 'health summary'],
      target: 'records',
    },
    {
      keys: ['prescription', 'reseta', 'receipt', 'gamot ko', 'bulong ko', 'digital prescription'],
      target: 'prescriptions',
    },
    {
      keys: ['notification', 'alert', 'remind', 'paalala', 'abiso'],
      target: 'notifications',
    },
    {
      keys: ['hour', 'open', 'close', 'schedule office', 'oras', 'office hours', 'bukas', 'sarado'],
      target: 'hours',
    },
    {
      keys: ['service', 'feature', 'what can', 'available', 'serbisyo', 'features'],
      target: 'services',
    },
    {
      keys: ['contact', 'message', 'phone', 'email', 'call', 'tawag', 'address', 'mensahe', 'mensahe'],
      target: 'contact',
    },
    {
      keys: ['help', 'menu', 'hi', 'hello', 'kumusta', 'start', 'good morning', 'good afternoon'],
      target: 'welcome',
    },
  ];

  function isEmergency(text) {
    return EMERGENCY_PATTERNS.some((re) => re.test(text));
  }

  function isMedicalAdviceRequest(text) {
    return MEDICAL_ADVICE_PATTERNS.some((re) => re.test(text));
  }

  function matchIntent(text) {
    const q = text.toLowerCase().trim();
    if (!q) return null;

    // Consultation intent — "i want to consult", "need konsulta", etc.
    if (
      /\b(?:want|need|gusto|kailangan|pwede|puwede)\b.*\b(?:consult|konsulta)/i.test(q)
      || /\b(?:mag-?)?consult(?:ation|a)?\b/i.test(q)
      || /\bkonsulta(?:syon)?\b/i.test(q)
    ) {
      return /\bvideo\b/i.test(q) ? 'video' : 'appointment';
    }

    // Check appointment_status before generic appointment
    const statusEntry = KEYWORD_HINTS.find((e) => e.target === 'appointment_status');
    if (statusEntry && statusEntry.keys.some((k) => q.includes(k))) {
      return 'appointment_status';
    }

    for (const entry of KEYWORD_HINTS) {
      if (entry.target === 'appointment_status') continue;
      if (entry.keys.some((k) => q.includes(k))) return entry.target;
    }
    return 'unknown';
  }

  function getFlow(key, lang) {
    if (I18n) return I18n.getFlow(key, lang);
    return { html: '<p>Service information is temporarily unavailable.</p>', followUp: null, actions: [] };
  }

  function getFlowLabel(flowKey, lang) {
    if (I18n) return I18n.getFlowLabel(lang, flowKey);
    return flowKey;
  }

  function getQuickActions(lang) {
    if (I18n) return I18n.getQuickActions(lang);
    return [];
  }

  global.McFaqEngine = {
    BOT_NAME,
    isEmergency,
    isMedicalAdviceRequest,
    matchIntent,
    getFlow,
    getFlowLabel,
    getQuickActions,
    KEYWORD_HINTS,
  };
})(window);
