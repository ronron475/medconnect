/**
 * medConnect FAQ Chatbot — Intent classification & urgency assessment
 * Runs before emotion badges and emergency routing.
 */
(function (global) {
  'use strict';

  const INTENT = Object.freeze({
    GREETING: 'greeting',
    GENERAL_QUESTION: 'general_question',
    REASSURANCE: 'reassurance',
    FAQ: 'faq',
    APPOINTMENT: 'appointment',
    REGISTRATION: 'registration',
    LOGIN: 'login',
    MEDICAL_INFO: 'medical_info',
    TECHNICAL: 'technical',
    FEEDBACK: 'feedback',
    EMOTIONAL_SUPPORT: 'emotional_support',
    CRISIS: 'crisis',
    MEDICAL_EMERGENCY: 'medical_emergency',
    OFF_TOPIC: 'off_topic',
  });

  const URGENCY = Object.freeze({
    NONE: 'none',
    LOW: 'low',
    HIGH: 'high',
    CRITICAL: 'critical',
  });

  /** Trust / capability questions — NOT emergencies */
  const REASSURANCE_PATTERNS = [
    /\bare\s+you\s+sure\b/i,
    /\b(is\s+this|is\s+it|can\s+this|can\s+it|will\s+this|does\s+this|will\s+it)\s+(really\s+)?(work|help)\b/i,
    /\b(are\s+you|can\s+you)\s+sure\s+(this|it|the\s+system|medconnect)\b/i,
    /\bwill\s+(medconnect|this\s+system|the\s+system)\s+help\b/i,
    /\bcan\s+(medconnect|this\s+system|the\s+system)\s+help\b/i,
    /\b(is\s+medconnect|is\s+this\s+system|is\s+this)\s+(safe|reliable|trustworthy|legit|real)\b/i,
    /\bdo\s+you\s+(really\s+)?work\b/i,
    /\bhow\s+(can|does)\s+(this|it|medconnect)\s+help\b/i,
    /\bwhat\s+can\s+(you|this|medconnect)\s+do\b/i,
    /\bsigurado\s+(ka|ba)\b/i,
    /\bmaabuligan\b/i,
    /\bmatinabang\b/i,
    /\btuod\s+gid\b.*\b(bulig|tabang)\b/i,
    /\bsystem\s+help\s+me\b/i,
    /\bhelp\s+me\s+(with|understand|use)\b/i,
  ];

  const QUESTION_STARTERS = /^(are|is|can|will|do|does|how|what|why|when|where|who|could|would|should)\b/i;

  const ENGINE_INTENT_MAP = {
    signin: INTENT.LOGIN,
    register: INTENT.REGISTRATION,
    reset: INTENT.LOGIN,
    appointment: INTENT.APPOINTMENT,
    appointment_status: INTENT.APPOINTMENT,
    video: INTENT.APPOINTMENT,
    records: INTENT.MEDICAL_INFO,
    prescriptions: INTENT.MEDICAL_INFO,
    notifications: INTENT.TECHNICAL,
    hours: INTENT.FAQ,
    services: INTENT.FAQ,
    contact: INTENT.FAQ,
    welcome: INTENT.GREETING,
  };

  /** Emotions that must not appear as user badges unless urgency is critical */
  const CRISIS_EMOTIONS = ['panic', 'emergency', 'hopeless'];

  function isReassuranceQuestion(text) {
    const raw = String(text || '').trim();
    if (!raw) return false;
    if (REASSURANCE_PATTERNS.some((re) => re.test(raw))) return true;
    if (QUESTION_STARTERS.test(raw) && /\b(help|work|trust|sure|safe|reliable)\b/i.test(raw)) {
      return true;
    }
    return false;
  }

  /**
   * @param {string} text
   * @returns {{ intent: string, urgency: string, isQuestion: boolean }}
   */
  function classify(text) {
    const raw = String(text || '').trim();
    const Emotions = global.McFaqEmotions;
    const Engine = global.McFaqEngine;
    const Conversation = global.McFaqConversation;

    const isQuestion = /\?/.test(raw) || QUESTION_STARTERS.test(raw);

    if (Emotions && Emotions.isSelfHarmCrisis(raw)) {
      return { intent: INTENT.CRISIS, urgency: URGENCY.CRITICAL, isQuestion };
    }

    if (Emotions && Emotions.isMedicalEmergency(raw)) {
      return { intent: INTENT.MEDICAL_EMERGENCY, urgency: URGENCY.CRITICAL, isQuestion };
    }

    if (Engine && Engine.isEmergency(raw)) {
      return { intent: INTENT.MEDICAL_EMERGENCY, urgency: URGENCY.CRITICAL, isQuestion };
    }

    if (Conversation && Conversation.isGreeting(raw)) {
      return { intent: INTENT.GREETING, urgency: URGENCY.NONE, isQuestion };
    }

    if (isReassuranceQuestion(raw)) {
      return { intent: INTENT.REASSURANCE, urgency: URGENCY.NONE, isQuestion };
    }

    if (Engine) {
      const matched = Engine.matchIntent(raw);
      if (matched && matched !== 'unknown') {
        const intent = ENGINE_INTENT_MAP[matched] || INTENT.FAQ;
        return { intent, urgency: URGENCY.NONE, isQuestion, flowKey: matched };
      }
    }

    if (Engine && Engine.isMedicalAdviceRequest(raw)) {
      return { intent: INTENT.MEDICAL_INFO, urgency: URGENCY.LOW, isQuestion };
    }

    return { intent: INTENT.GENERAL_QUESTION, urgency: URGENCY.NONE, isQuestion };
  }

  /**
   * Badge emotion for user message — suppress false urgency labels.
   * @param {string|null} emotion
   * @param {{ intent: string, urgency: string }} classification
   * @returns {string|null}
   */
  function getDisplayEmotion(emotion, classification) {
    if (!emotion || !classification) return null;
    const { intent, urgency } = classification;

    if (urgency === URGENCY.CRITICAL) return emotion;
    if (intent === INTENT.CRISIS || intent === INTENT.MEDICAL_EMERGENCY) return emotion;

    if (CRISIS_EMOTIONS.includes(emotion)) return null;

    if (intent === INTENT.REASSURANCE) {
      if (['curious', 'worried', 'anxious', 'nervous', 'confused'].includes(emotion)) return emotion;
      return 'curious';
    }

    return emotion;
  }

  /**
   * Suggested emotion when reassurance intent detected.
   */
  function getReassuranceEmotion() {
    return 'curious';
  }

  global.McFaqIntent = {
    INTENT,
    URGENCY,
    classify,
    isReassuranceQuestion,
    getDisplayEmotion,
    getReassuranceEmotion,
  };
})(window);
