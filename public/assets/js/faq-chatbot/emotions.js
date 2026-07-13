/**
 * medConnect FAQ Chatbot — Emotion Recognition Engine
 * Dataset + pattern matching · EN · Filipino · Hiligaynon
 */
(function (global) {
  'use strict';

  const Dataset = global.McFaqEmotionDataset;

  /** Canonical emotion labels (20 supported intents) */
  const EMOTION = Object.freeze({
    HAPPY: 'happy',
    THANKFUL: 'thankful',
    RELIEVED: 'relieved',
    EXCITED: 'excited',
    CURIOUS: 'curious',
    CONFUSED: 'confused',
    FRUSTRATED: 'frustrated',
    WORRIED: 'worried',
    ANXIOUS: 'anxious',
    NERVOUS: 'nervous',
    SAD: 'sad',
    LONELY: 'lonely',
    AFRAID: 'afraid',
    ANGRY: 'angry',
    DISAPPOINTED: 'disappointed',
    STRESSED: 'stressed',
    TIRED: 'tired',
    HOPELESS: 'hopeless',
    PANIC: 'panic',
    EMERGENCY: 'emergency',
    CRYING: 'crying',
    PAIN: 'pain',
    SICK: 'sick',
    OVERWHELMED: 'overwhelmed',
    // Legacy aliases
    GRATITUDE: 'thankful',
    HAPPINESS: 'happy',
    RELIEF: 'relieved',
    FRUSTRATION: 'frustrated',
    WORRY: 'worried',
    ANXIETY: 'anxious',
    CONFUSION: 'confused',
    FEAR: 'afraid',
    SADNESS: 'sad',
    DISTRESS: 'anxious',
  });

  /** Emoji icons for each emotion (user badges + bot empathy) */
  const EMOTION_ICONS = Object.freeze({
    happy: '😊',
    thankful: '🙏',
    relieved: '😌',
    excited: '🎉',
    curious: '🤔',
    confused: '😕',
    frustrated: '😤',
    worried: '😟',
    anxious: '😰',
    nervous: '😬',
    sad: '😢',
    lonely: '🥺',
    afraid: '😨',
    angry: '😠',
    disappointed: '😞',
    stressed: '😫',
    tired: '😴',
    hopeless: '💔',
    panic: '🆘',
    emergency: '🚨',
    crying: '😭',
    pain: '🤕',
    sick: '🤒',
    overwhelmed: '😥',
    gratitude: '🙏',
    happiness: '😊',
    relief: '😌',
    frustration: '😤',
    worry: '😟',
    anxiety: '😰',
    confusion: '😕',
    fear: '😨',
    sadness: '😢',
    distress: '😰',
  });

  const EMOTION_TONE = Object.freeze({
    positive: ['happy', 'thankful', 'relieved', 'excited', 'curious', 'gratitude', 'happiness', 'relief'],
    negative: [
      'frustrated', 'worried', 'anxious', 'nervous', 'sad', 'lonely', 'afraid',
      'angry', 'disappointed', 'stressed', 'tired', 'hopeless', 'crying', 'pain',
      'sick', 'overwhelmed',
      'frustration', 'worry', 'anxiety', 'fear', 'sadness', 'distress',
    ],
    crisis: ['panic', 'emergency'],
    neutral: ['confused', 'confusion'],
  });

  function getEmotionIcon(emotion) {
    if (!emotion) return '';
    const key = normalizeEmotionKey(emotion) || emotion;
    return EMOTION_ICONS[key] || EMOTION_ICONS[emotion] || '💬';
  }

  function getEmotionTone(emotion) {
    if (!emotion) return 'neutral';
    const key = normalizeEmotionKey(emotion) || emotion;
    if (EMOTION_TONE.crisis.includes(key)) return 'crisis';
    if (EMOTION_TONE.positive.includes(key)) return 'positive';
    if (EMOTION_TONE.negative.includes(key)) return 'negative';
    return 'neutral';
  }

  const PRIORITY = (Dataset && Dataset.EMOTION_PRIORITY) || [
    'emergency', 'panic', 'hopeless', 'afraid', 'angry', 'frustrated', 'anxious',
    'nervous', 'worried', 'stressed', 'overwhelmed', 'pain', 'sick', 'tired',
    'sad', 'crying', 'lonely', 'disappointed',
    'confused', 'curious', 'excited', 'relieved', 'thankful', 'happy',
  ];

  const STANDALONE = {
    thankful: /^(thank\s*you|thanks|thank\s*u|salamat|salamat\s+guid|salamat\s+kaayo|maraming\s+salamat|ty|tysm|thank\s+you\s+gid)[\s!.?]*$/i,
    happy: /^(i'?m\s+happy|happy|masaya\s+ako|masadya\s+ko|lipay\s+ko|okay\s+na\s+ko)[\s!.?]*$/i,
    confused: /^(i'?m\s+confused|confused|nalilito\s+ako|nalibog\s+ako|indi\s+ko\s+masabtan|hindi\s+ko\s+maintindihan|wala\s+ko\s+kaintindi)[\s!.?]*$/i,
    relieved: /^(relieved|okay\s+na|maayo\s+na|buti\s+na\s+lang)[\s!.?]*$/i,
  };

  const SELF_HARM_PATTERNS = [
    /\bsuicid/i,
    /\bkill\s+myself\b/i,
    /\bend\s+my\s+life\b/i,
    /\bwant\s+to\s+die\b/i,
    /\bwish\s+i\s+(was|were)\s+dead\b/i,
    /\bself[\s-]?harm\b/i,
    /\bhurt\s+myself\b/i,
    /\bcut\s+myself\b/i,
    /\bdon'?t\s+want\s+to\s+live\b/i,
    /\bno\s+reason\s+to\s+live\b/i,
    /\btake\s+my\s+(own\s+)?life\b/i,
    /\bend\s+it\s+all\b/i,
    // Filipino — "gusto ko nang/ng/na mamatay" (I want to die)
    /\bgusto\s+ko\s+(?:ng|nang|na)\s+mamatay\b/i,
    /\bgusto\s+kong\s+mamatay\b/i,
    /\bgusto\s+ko\s+mamatay\b/i,
    /\bgusto\s+ko\s+ng\s+magpakamatay\b/i,
    /\bgusto\s+ko\s+(?:ng|nang)\s+magpakamatay\b/i,
    /\bmagpakamatay\s+na\s+ako\b/i,
    /\bmamatay\s+na\s+(?:ako|sana)\b/i,
    /\bpatayin\s+ko\s+(?:ang\s+)?sarili\b/i,
    /\bsaktan\s+ko\s+(?:ang\s+)?sarili\b/i,
    /\bwala\s+na\s+akong\s+gustong\s+mabuhay\b/i,
    /\bwala\s+na\s+akong\s+paglaum\b/i,
    /\bbuot\s+ko\s+(?:mag)?pakamatay\b/i,
    /\bbuot\s+ko\s+mamatay\b/i,
    /\bpatyon\s+ko\s+(?:ang\s+)?kaugalingon\b/i,
    /\bpatyon\s+ko\b/i,
    /\bmagpakamatay\b/i,
    /\bindi\s+ko\s+gusto\s+mabuhi\b/i,
    /\bwala\s+ko\s+gusto\s+mabuhi\b/i,
    /\bayaw\s+ko\s+mabuhay\b/i,
    /\b(hindi|di)\s+ko\s+gustong?\s+mabuhay\b/i,
    /\b(hindi|di)\s+ko\s+nais\s+mabuhay\b/i,
    /\bwala\s+na\s+(?:ako|ko)y?\s+pag-?laum\b/i,
    /\bwala\s+na\s+akong\s+pag-asa\b/i,
    /\bwalang\s+silbi\s+ang\s+buhay\b/i,
    /\bburden\s+(?:ako|sa)\b/i,
    /\bdon'?t\s+want\s+to\s+(?:live|exist)\b/i,
    /\bi\s+want\s+to\s+(?:die|end\s+it)\b/i,
  ];

  /** Normalize Filipino particles / spacing for crisis matching */
  function normalizeCrisisText(text) {
    return String(text || '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9\s]/g, ' ')
      .replace(/\bnang\b/g, 'ng')
      .replace(/\s+/g, ' ')
      .trim();
  }

  const EMERGENCY_PATTERNS = [
    /\bcan'?t\s+breathe\b/i, /\bchest\s+pain\b/i, /\bbleeding\b/i, /\bcollapsed\b/i,
    /\bheart\s+attack\b/i, /\bstroke\b/i, /\bunconscious\b/i, /\bseizure\b/i,
    /\bchoking\b/i, /\boverdose\b/i, /\bpoisoning\b/i, /\bnot\s+breathing\b/i,
    /\bpassed\s+out\b/i, /\bmedical\s+emergency\b/i,
    /\bhirap\s+huminga\b/i, /\bmasakit\s+ang\s+dibdib\b/i, /\bgrabe\s+ang\s+pagdurugo\b/i,
    /\bnawalan\s+ng\s+malay\b/i, /\bnagaginhawa\s+ko\s+budlay\b/i,
    /\bginabatyag\s+ko\s+sakit\s+sa\s+dughan\b/i, /\bgrabe\s+nga\s+pagdugo\b/i,
    /\bwala\s+siya\s+sang\s+panimuot\b/i, /\bnagakombulsyon\b/i,
    /\bindi\s+makaginhawa\b/i, /\bindi\s+makahinga\b/i, /\bsakit\s+ang\s+dibdib\b/i,
  ];

  const FLOW_HINTS = [
    { flow: 'reset', keys: ['password', 'forgot', 'reset', 'kalimtan', 'nakalimtan', 'nakalimutan'] },
    { flow: 'signin', keys: ['login', 'sign in', 'signin', 'log in', 'maka-login', 'maka login', 'mag-login'] },
    { flow: 'appointment', keys: ['appointment', 'book', 'schedule', 'konsultasyon', 'consultation', 'consult', 'konsulta'] },
    { flow: 'appointment_status', keys: ['appointment status', 'status sang appointment', 'status ng appointment', 'waiting'] },
    { flow: 'register', keys: ['register', 'account', 'rehistro'] },
    { flow: 'contact', keys: ['contact', 'help', 'support', 'tawag', 'buligi'] },
  ];

  /** Boost patterns when dataset misses edge cases */
  const BOOST_RULES = [
    { emotion: EMOTION.FRUSTRATED, re: /\b(frustrat|annoyed|irritat|kapoy\s+na\s+ko\s+sini)\b/i, w: 2 },
    { emotion: EMOTION.ANGRY, re: /\b(angry|galit|akig|badtrip)\b/i, w: 2 },
    { emotion: EMOTION.WORRIED, re: /\b(worri|concerned|nabalaka|kabalaka)\b/i, w: 2 },
    { emotion: EMOTION.ANXIOUS, re: /\b(anxious|anxiety|ginakulbaan)\b/i, w: 2 },
    { emotion: EMOTION.PANIC, re: /\b(panic|ginapanik|buligi\s+ko)\b/i, w: 2.5 },
    { emotion: EMOTION.SAD, re: /\b(sad|lungkot|kasubo|subo)\b/i, w: 2 },
    { emotion: EMOTION.TIRED, re: /\b(tired|pagod|kapoy|wala\s+na\s+(ko\s+)?kusog)\b/i, w: 2 },
    { emotion: EMOTION.STRESSED, re: /\b(stress|stressed|overwhelm|grabeng\s+stress)\b/i, w: 2 },
    { emotion: EMOTION.LONELY, re: /\b(lonely|nag-iisa|isa\s+lang|wala\s+(ako|ko)\s+(makakausap|maistoryahan))\b/i, w: 2 },
    { emotion: EMOTION.THANKFUL, re: /\b(salamat|thank\s*you|thanks|maraming\s+salamat)\b/i, w: 2.5 },
    { emotion: EMOTION.HOPELESS, re: /\b(hopeless|walang\s+pag-asa|wala\s+paglaum|wala\s+na\s+solusyon)\b/i, w: 2 },
    { emotion: EMOTION.DISAPPOINTED, re: /\b(disappoint|nadismaya|dismaya)\b/i, w: 2 },
    { emotion: EMOTION.NERVOUS, re: /\b(nervous|kinakabahan|kabado|kulba)\b/i, w: 2 },
    { emotion: EMOTION.PAIN, re: /\b(sakit\s+ulo|headache|masakit)\b/i, w: 2.5 },
    { emotion: EMOTION.SICK, re: /\b(hilanat|lagnat|fever|sick|sipon|ubo|gasuka)\b/i, w: 2.5 },
    { emotion: EMOTION.CRYING, re: /\b(crying|umiiyak|naga\s*hilib)\b/i, w: 2 },
    { emotion: EMOTION.OVERWHELMED, re: /\b(overwhelm|overwhelmed|daw\s+wala\s+na\s+ko\s+gana|wala\s+na\s+akong\s+gana)\b/i, w: 2 },
    { emotion: EMOTION.HOPELESS, re: /\b(ayaw\s+ko\s+mabuhay|hopeless|walang\s+pag-asa|wala\s+paglaum)\b/i, w: 3 },
  ];

  /** Short panic phrases that must not match inside longer questions */
  const GENERIC_PANIC_PHRASES = new Set([
    'help', 'help me', 'need help', 'need help now', 'buligi ko', 'help now',
  ]);

  function isWeakPanicMatch(phrase, fullNorm) {
    if (!GENERIC_PANIC_PHRASES.has(phrase)) return false;
    const wordCount = fullNorm.split(/\s+/).filter(Boolean).length;
    return wordCount > 4;
  }

  function isQuestionLike(text) {
    const raw = String(text || '').trim();
    return /\?/.test(raw) || /^(are|is|can|will|do|does|how|what|why|could|would|should)\b/i.test(raw);
  }

  function normalize(text) {
    return String(text || '')
      .toLowerCase()
      .replace(/[^a-z0-9\s]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function isSelfHarmCrisis(text) {
    const raw = String(text || '');
    if (SELF_HARM_PATTERNS.some((re) => re.test(raw))) return true;
    const norm = normalizeCrisisText(raw);
    return SELF_HARM_PATTERNS.some((re) => re.test(norm));
  }

  function isMedicalEmergency(text) {
    const raw = String(text || '');
    if (EMERGENCY_PATTERNS.some((re) => re.test(raw))) return true;
    if (isQuestionLike(raw)) return false;
    const Intent = global.McFaqIntent;
    if (Intent && Intent.isReassuranceQuestion(raw)) return false;
    const scores = scoreFromDataset(raw);
    return (scores[EMOTION.EMERGENCY] || 0) >= 4;
  }

  function inferFlowFromText(text) {
    const q = String(text || '').toLowerCase();
    for (const hint of FLOW_HINTS) {
      if (hint.keys.some((k) => q.includes(k))) return hint.flow;
    }
    return null;
  }

  /** @type {{ byWord: Record<string, number[]>, phrases: Array }} */
  let phraseIndex = null;

  function buildPhraseIndex() {
    if (phraseIndex || !Dataset || !Dataset.PHRASES) return;
    const byWord = {};
    const phrases = Dataset.PHRASES;
    for (let i = 0; i < phrases.length; i++) {
      const norm = normalize(phrases[i].p);
      if (!norm) continue;
      const words = norm.split(' ').filter((w) => w.length >= 3);
      const seen = new Set();
      for (const w of words) {
        if (seen.has(w)) continue;
        seen.add(w);
        if (!byWord[w]) byWord[w] = [];
        byWord[w].push(i);
      }
      // Also index short emergency tokens
      if (norm.length <= 12 && !words.length) {
        const w = norm;
        if (!byWord[w]) byWord[w] = [];
        byWord[w].push(i);
      }
    }
    phraseIndex = { byWord, phrases };
  }

  function getCandidateIndices(norm) {
    buildPhraseIndex();
    if (!phraseIndex) return null;
    const words = norm.split(' ').filter(Boolean);
    const set = new Set();
    for (const w of words) {
      const list = phraseIndex.byWord[w];
      if (list) list.forEach((i) => set.add(i));
      if (w.length >= 4) {
        const sub = w.slice(0, 4);
        const subList = phraseIndex.byWord[sub];
        if (subList) subList.forEach((i) => set.add(i));
      }
    }
    // Short messages: scan high-priority emotions only (fallback cap)
    if (set.size === 0 && norm.length <= 40) {
      return null;
    }
    return set;
  }

  /**
   * @param {string} text
   * @returns {Record<string, number>}
   */
  function scoreFromDataset(text) {
    const norm = normalize(text);
    const scores = {};
    if (!norm || !Dataset || !Dataset.PHRASES) return scores;

    buildPhraseIndex();
    const phrases = phraseIndex ? phraseIndex.phrases : Dataset.PHRASES;
    const candidates = getCandidateIndices(norm);

    const scan = (i) => {
      const entry = phrases[i];
      const phrase = normalize(entry.p);
      if (!phrase || phrase.length < 2) return;
      if (entry.e === EMOTION.PANIC && isWeakPanicMatch(phrase, norm)) return;
      if (entry.e === EMOTION.EMERGENCY && phrase.length < 10 && norm.split(/\s+/).length > 5) return;
      if (norm.includes(phrase)) {
        const boost = Math.min(phrase.length, 28) * 0.35;
        scores[entry.e] = (scores[entry.e] || 0) + boost + 1;
      }
    };

    if (candidates && candidates.size > 0) {
      candidates.forEach(scan);
    } else {
      // Fallback: full scan capped for very short unknown inputs
      const limit = Math.min(phrases.length, 800);
      for (let i = 0; i < limit; i++) scan(i);
    }

    return scores;
  }

  function pickPrimary(scores) {
    let best = null;
    let bestScore = 0;
    for (const emotion of PRIORITY) {
      const s = scores[emotion] || 0;
      if (s > bestScore) {
        bestScore = s;
        best = emotion;
      }
    }
    return { primary: bestScore >= 1.2 ? best : null, score: bestScore };
  }

  function detectStandalone(raw) {
    for (const [emotion, re] of Object.entries(STANDALONE)) {
      if (re.test(raw)) return emotion;
    }
    return null;
  }

  /**
   * @param {string} text
   * @returns {{
   *   primary: string|null,
   *   score: number,
   *   standalone: string|null,
   *   inferredFlow: string|null,
   *   scores: Record<string, number>
   * }}
   */
  function analyze(text, options = {}) {
    const raw = String(text || '').trim();
    if (!raw) {
      return { primary: null, score: 0, standalone: null, inferredFlow: null, scores: {} };
    }

    const intent = options.intent || null;
    const isReassurance = intent === 'reassurance'
      || (global.McFaqIntent && global.McFaqIntent.isReassuranceQuestion(raw));

    const standalone = detectStandalone(raw);
    if (standalone) {
      return {
        primary: standalone,
        score: 3,
        standalone,
        inferredFlow: standalone === EMOTION.CONFUSED ? 'clarify' : null,
        scores: { [standalone]: 3 },
      };
    }

    const scores = scoreFromDataset(raw);

    for (const rule of BOOST_RULES) {
      if (rule.re.test(raw)) {
        scores[rule.emotion] = (scores[rule.emotion] || 0) + rule.w;
      }
    }

    if (isReassurance || isQuestionLike(raw)) {
      delete scores[EMOTION.PANIC];
      delete scores[EMOTION.EMERGENCY];
      scores[EMOTION.CURIOUS] = (scores[EMOTION.CURIOUS] || 0) + 2;
      if (/\b(sure|trust|safe|reliable|sigurado)\b/i.test(raw)) {
        scores[EMOTION.WORRIED] = (scores[EMOTION.WORRIED] || 0) + 1;
      }
    }

    const { primary, score } = pickPrimary(scores);
    const inferredFlow = inferFlowFromText(raw);

    return { primary, score, standalone: null, inferredFlow, scores };
  }

  /**
   * @param {string|null} intent
   * @param {{ primary: string|null, inferredFlow: string|null }} emotion
   * @returns {string|null}
   */
  function resolveFlow(intent, emotion) {
    if (intent && intent !== 'unknown' && intent !== 'welcome') return intent;
    if (emotion.inferredFlow) return emotion.inferredFlow;

    const e = emotion.primary;
    if (e === EMOTION.CONFUSED || e === EMOTION.CURIOUS) return 'clarify';
    if (e === EMOTION.THANKFUL) return 'gratitude';
    if (e === EMOTION.HAPPY || e === EMOTION.RELIEVED || e === EMOTION.EXCITED) return e;
    if ([EMOTION.PANIC, EMOTION.HOPELESS, EMOTION.ANXIOUS, EMOTION.NERVOUS,
      EMOTION.WORRIED, EMOTION.STRESSED, EMOTION.OVERWHELMED, EMOTION.SAD,
      EMOTION.CRYING, EMOTION.LONELY, EMOTION.AFRAID].includes(e)) {
      return 'distress_support';
    }
    if (e === EMOTION.PAIN || e === EMOTION.SICK) return 'pain_sick';
    return intent;
  }

  function normalizeEmotionKey(key) {
    if (!key) return null;
    const aliases = {
      gratitude: EMOTION.THANKFUL,
      happiness: EMOTION.HAPPY,
      relief: EMOTION.RELIEVED,
      frustration: EMOTION.FRUSTRATED,
      worry: EMOTION.WORRIED,
      anxiety: EMOTION.ANXIOUS,
      confusion: EMOTION.CONFUSED,
      fear: EMOTION.AFRAID,
      sadness: EMOTION.SAD,
      distress: EMOTION.ANXIOUS,
    };
    return aliases[key] || key;
  }

  global.McFaqEmotions = {
    EMOTION,
    EMOTION_ICONS,
    PRIORITY,
    getEmotionIcon,
    getEmotionTone,
    isSelfHarmCrisis,
    isMedicalEmergency,
    analyze,
    resolveFlow,
    inferFlowFromText,
    normalizeEmotionKey,
    scoreFromDataset,
  };
})(window);
