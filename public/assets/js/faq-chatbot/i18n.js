/**
 * medConnect FAQ Chatbot — Multilingual content (EN · FIL · HIL)
 */
(function (global) {
  'use strict';

  const LANG = global.McFaqLanguage?.LANG || { EN: 'en', FIL: 'fil', HIL: 'hil' };

  /** Display labels for detected emotion badges */
  const EMOTION_LABELS = {
    en: {
      happy: 'Happy', thankful: 'Thankful', relieved: 'Relieved', excited: 'Excited',
      curious: 'Curious', confused: 'Confused', frustrated: 'Frustrated', worried: 'Worried',
      anxious: 'Anxious', nervous: 'Nervous', sad: 'Sad', lonely: 'Lonely', afraid: 'Afraid',
      angry: 'Angry', disappointed: 'Disappointed', stressed: 'Stressed', tired: 'Tired',
      hopeless: 'Hopeless', panic: 'Urgent', emergency: 'Emergency',
      crying: 'Upset', pain: 'Pain', sick: 'Unwell', overwhelmed: 'Overwhelmed',
    },
    fil: {
      happy: 'Masaya', thankful: 'Pasalamat', relieved: 'Ginhawa', excited: 'Excited',
      curious: 'Curious', confused: 'Nalilito', frustrated: 'Frustrated', worried: 'Nag-aalala',
      anxious: 'Kinakabahan', nervous: 'Kinakabahan', sad: 'Malungkot', lonely: 'Nag-iisa',
      afraid: 'Natakot', angry: 'Galit', disappointed: 'Nadismaya', stressed: 'Stressed',
      tired: 'Pagod', hopeless: 'Walang pag-asa', panic: 'Kailangan ng tulong', emergency: 'Emergency',
      crying: 'Malungkot', pain: 'Sakit', sick: 'May sakit', overwhelmed: 'Overwhelmed',
    },
    hil: {
      happy: 'Masadya', thankful: 'Salamat', relieved: 'Ginhawa', excited: 'Excited',
      curious: 'Curious', confused: 'Nalibog', frustrated: 'Frustrated', worried: 'Nabalaka',
      anxious: 'Ginakulbaan', nervous: 'Kinakabahan', sad: 'Kasubo', lonely: 'Isa lang',
      afraid: 'Nahadlok', angry: 'Akig', disappointed: 'Nadismaya', stressed: 'Stressed',
      tired: 'Kapoy', hopeless: 'Wala paglaum', panic: 'Kinahanglan bulig', emergency: 'Emergency',
      crying: 'Kasubo', pain: 'Sakit', sick: 'May hilanat', overwhelmed: 'Overwhelmed',
    },
  };

  const UI_STRINGS = {
    en: {
      botName: 'medConnect Assistant',
      emergencyBadge: 'Medical Emergency',
      welcomeTitle: 'Welcome to medConnect!',
      welcomeLead: 'I\'m your virtual assistant for the <strong>City Health Office of Bago City</strong>.',
      welcomeTopics: 'I can help you with:',
      welcomeTopicList: ['Account Registration', 'Login Assistance', 'Appointment Booking', 'Video Consultation', 'Password Recovery', 'General Questions'],
      welcomeCta: 'How may I assist you today?',
      chooseTopic: 'Choose a topic below or type your question.',
      needHelp: 'Need additional help?',
      readyStart: 'Ready to get started?',
      resetNow: 'Would you like to reset now?',
      needSignIn: 'Need help signing in first?',
      bookConsult: 'Would you like to book a consultation?',
      needAccount: 'Need help accessing your account?',
      whatNext: 'What would you like to do next?',
      nonEmergency: 'For non-emergency inquiries during office hours.',
      tryTopics: 'Try one of these topics:',
      howCanHelp: 'How can I help you with medConnect?',
      policyFollowUp: 'I can help you with:',
      clarify: 'I\'m sorry, I couldn\'t quite understand your message. I can still help with account access, appointments, services, and City Health Office information.',
      lowConfidence: 'I\'d be happy to help. Could you explain your question a little differently?',
      openingSignIn: 'Opening the sign-in panel for you…',
      openingRegister: 'Taking you to patient registration…',
      openingForgot: 'Opening the password reset form…',
      openingRequirements: 'Opening registration requirements…',
      scrollingContact: 'Scrolling to the contact section…',
      restrictedBanner: 'Chat temporarily restricted —',
      restrictedRemaining: 's remaining',
      restrictedPlaceholder: 'Chat restricted — wait {n}s…',
      inputPlaceholder: 'Ask me anything about medConnect...',
      disclaimer: 'For non-emergency use only. I cannot diagnose or prescribe medication.',
      chars: '{n} / 500',
      crisisBadge: 'Safety Alert — Please Read',
      confusionPrompt: 'Please tell me what you need help with — for example, signing in, booking an appointment, or resetting your password.',
    },
    fil: {
      botName: 'medConnect Assistant',
      emergencyBadge: 'Medikal na Emergency',
      welcomeTitle: 'Maligayang pagdating sa medConnect!',
      welcomeLead: 'Ako ang inyong virtual assistant para sa <strong>City Health Office ng Bago City</strong>.',
      welcomeTopics: 'Maaari kitang tulungan sa:',
      welcomeTopicList: ['Pagrehistro ng Account', 'Tulong sa Pag-login', 'Pag-book ng Appointment', 'Video Consultation', 'Pag-recover ng Password', 'Pangkalahatang Tanong'],
      welcomeCta: 'Paano kita matutulungan ngayon?',
      chooseTopic: 'Pumili ng paksa sa ibaba o i-type ang iyong tanong.',
      needHelp: 'Kailangan pa ng tulong?',
      readyStart: 'Handa ka na bang magsimula?',
      resetNow: 'Gusto mo bang i-reset ngayon?',
      needSignIn: 'Kailangan mo bang mag-sign in muna?',
      bookConsult: 'Gusto mo bang mag-book ng konsultasyon?',
      needAccount: 'Kailangan mo ba ng tulong sa account?',
      whatNext: 'Ano ang gusto mong gawin susunod?',
      nonEmergency: 'Para sa mga hindi emergency na tanong sa oras ng opisina.',
      tryTopics: 'Subukan ang isa sa mga paksang ito:',
      howCanHelp: 'Paano kita matutulungan sa medConnect?',
      policyFollowUp: 'Maaari kitang tulungan sa:',
      clarify: 'Paumanhin, hindi ko lubos na naintindihan ang iyong mensahe. Maaari pa rin kitang tulungan sa account, appointment, serbisyo, at impormasyon ng City Health Office.',
      lowConfidence: 'Masaya akong tumulong. Maaari mo bang ipaliwanag ang iyong tanong nang kaunti?',
      openingSignIn: 'Binubuksan ang sign-in panel…',
      openingRegister: 'Dadalhin ka sa patient registration…',
      openingForgot: 'Binubuksan ang password reset form…',
      openingRequirements: 'Binubuksan ang mga requirement sa pagrehistro…',
      scrollingContact: 'Pumupunta sa contact section…',
      restrictedBanner: 'Pansamantalang restricted ang chat —',
      restrictedRemaining: 's natitira',
      restrictedPlaceholder: 'Restricted ang chat — maghintay ng {n}s…',
      inputPlaceholder: 'Magtanong tungkol sa medConnect…',
      disclaimer: 'Para lamang sa hindi emergency. Hindi ako makakapag-diagnose o mag-reseta ng gamot.',
      chars: '{n} / 500',
      crisisBadge: 'Babala sa Kaligtasan — Pakibasa',
      confusionPrompt: 'Sabihin mo kung ano ang kailangan mong tulong — halimbawa, pag-sign in, pag-book ng appointment, o pag-reset ng password.',
    },
    hil: {
      botName: 'medConnect Assistant',
      emergencyBadge: 'Medikal nga Emergency',
      welcomeTitle: 'Welcome sa medConnect!',
      welcomeLead: 'Ako ang inyo nga virtual assistant para sa <strong>City Health Office sang Bago City</strong>.',
      welcomeTopics: 'Makabulig ako sa:',
      welcomeTopicList: ['Pagrehistro sang Account', 'Bulig sa Login', 'Pag-book sang Appointment', 'Video Konsultasyon', 'Pag-recover sang Password', 'Mga Pangkalahatan nga Pamangkot'],
      welcomeCta: 'Paano ko ikaw matabangan subong?',
      chooseTopic: 'Pili sang topic sa idalom ukon i-type ang imo pamangkot.',
      needHelp: 'Kinahanglan pa sang bulig?',
      readyStart: 'Handa ka na mag-umpisa?',
      resetNow: 'Gusto mo i-reset subong?',
      needSignIn: 'Kinahanglan ka mag-sign in anay?',
      bookConsult: 'Gusto mo mag-book sang konsultasyon?',
      needAccount: 'Kinahanglan mo sang bulig sa account?',
      whatNext: 'Ano ang gusto mo himuon sunod?',
      nonEmergency: 'Para sa indi emergency nga mga pamangkot sa oras sang opisina.',
      tryTopics: 'Tilawi ang isa sa sini nga mga topic:',
      howCanHelp: 'Paano ko ikaw matabangan sa medConnect?',
      policyFollowUp: 'Makabulig ako sa:',
      clarify: 'Pasensya na, indi ko gid maintindihan ang imo mensahe. Matabangan ko gihapon ikaw sa account, appointment, serbisyo, kag impormasyon sang City Health Office.',
      lowConfidence: 'Malipayon ako nga makabulig. Pwede mo bala ipaliwanag ang imo pamangkot gamay?',
      openingSignIn: 'Ginabukas ang sign-in panel…',
      openingRegister: 'Gindadala ka sa patient registration…',
      openingForgot: 'Ginabukas ang password reset form…',
      openingRequirements: 'Ginabukas ang mga requirement sa pagrehistro…',
      scrollingContact: 'Nagascroll sa contact section…',
      restrictedBanner: 'Pansamantalang restricted ang chat —',
      restrictedRemaining: 's ang nabilin',
      restrictedPlaceholder: 'Restricted ang chat — hulat sang {n}s…',
      inputPlaceholder: 'Magpamangkot parte sa medConnect…',
      disclaimer: 'Para lang sa indi emergency. Indi ako makapag-diagnose ukon mag-reseta sang bulong.',
      chars: '{n} / 500',
      crisisBadge: 'Safety Alert — Palihog Basaha',
      confusionPrompt: 'Silinga kon ano ang imo kinahanglan nga bulig — pananglitan, pag-sign in, pag-book sang appointment, ukon pag-reset sang password.',
    },
  };

  const ACTION_LABELS = {
    en: {
      signIn: 'Sign In', createAccount: 'Create Account', resetPassword: 'Reset Password',
      bookAppointment: 'Book Appointment', videoConsult: 'Video Consultation', leaveMessage: 'Leave a Message',
      openSignIn: 'Open Sign In', forgotPassword: 'Forgot Password', contactSupport: 'Contact Support',
      startRegistration: 'Start Registration', viewRequirements: 'View Requirements',
      resetPasswordNow: 'Reset Password Now', officeHours: 'Office Hours', ourServices: 'Our Services',
      contactInfo: 'Contact Information', goContact: 'Go to Contact Section',
      contactCho: 'Contact CHO', contactChoNonEmergency: 'Contact CHO (Non-Emergency)',
      signInHelp: 'Sign In Help',
      callEmergency: 'Call Emergency (911)',
    },
    fil: {
      signIn: 'Mag-sign In', createAccount: 'Gumawa ng Account', resetPassword: 'I-reset ang Password',
      bookAppointment: 'Mag-book ng Appointment', videoConsult: 'Video Consultation', leaveMessage: 'Mag-iwan ng Mensahe',
      openSignIn: 'Buksan ang Sign In', forgotPassword: 'Nakalimutan ang Password', contactSupport: 'Makipag-ugnayan',
      startRegistration: 'Simulan ang Pagrehistro', viewRequirements: 'Tingnan ang Requirements',
      resetPasswordNow: 'I-reset ang Password Ngayon', officeHours: 'Oras ng Opisina', ourServices: 'Mga Serbisyo',
      contactInfo: 'Impormasyon sa Pakikipag-ugnayan', goContact: 'Pumunta sa Contact Section',
      contactCho: 'Makipag-ugnayan sa CHO', contactChoNonEmergency: 'Makipag-ugnayan sa CHO (Hindi Emergency)',
      signInHelp: 'Tulong sa Sign In',
      callEmergency: 'Tumawag sa Emergency (911)',
    },
    hil: {
      signIn: 'Mag-sign In', createAccount: 'Maghimo sang Account', resetPassword: 'I-reset ang Password',
      bookAppointment: 'Mag-book sang Appointment', videoConsult: 'Video Konsultasyon', leaveMessage: 'Magbilin sang Mensahe',
      openSignIn: 'Buksan ang Sign In', forgotPassword: 'Nakalimtan ang Password', contactSupport: 'Makig-ugnayan',
      startRegistration: 'Sugdan ang Pagrehistro', viewRequirements: 'Tan-awon ang Requirements',
      resetPasswordNow: 'I-reset ang Password Subong', officeHours: 'Oras sang Opisina', ourServices: 'Mga Serbisyo',
      contactInfo: 'Impormasyon sa Pakig-ugnayan', goContact: 'Lakat sa Contact Section',
      contactCho: 'Makig-ugnayan sa CHO', contactChoNonEmergency: 'Makig-ugnayan sa CHO (Indi Emergency)',
      signInHelp: 'Bulig sa Sign In',
      callEmergency: 'Tawagi ang Emergency (911)',
    },
  };

  const INFO_CARD = {
    en: {
      not_understood: { icon: '🤔', title: "I couldn't fully understand your message.", topicsLabel: 'Please try asking about:' },
      partial: { icon: '💬', title: 'I need a bit more detail.', topicsLabel: 'I can help with topics like:' },
    },
    fil: {
      not_understood: { icon: '🤔', title: 'Hindi ko lubos na naintindihan ang iyong mensahe.', topicsLabel: 'Subukang magtanong tungkol sa:' },
      partial: { icon: '💬', title: 'Kailangan ko ng kaunting detalye.', topicsLabel: 'Makakatulong ako sa mga topic tulad ng:' },
    },
    hil: {
      not_understood: { icon: '🤔', title: 'Indi ko gid maintindihan ang imo mensahe.', topicsLabel: 'Tilawi magpamangkot parte sa:' },
      partial: { icon: '💬', title: 'Kinahanglan ko sang gamay nga detalye.', topicsLabel: 'Makabulig ako sa mga topic pareho sang:' },
    },
  };

  const INFO_TOPICS = {
    en: ['Appointments', 'Registration', 'Login', 'Medical Records', 'Video Consultation'],
    fil: ['Appointments', 'Rehistrasyon', 'Login', 'Medical Records', 'Video Consultation'],
    hil: ['Appointments', 'Rehistrasyon', 'Login', 'Medical Records', 'Video Konsultasyon'],
  };

  const FOLLOW_ACTION_META = {
    en: {
      signin: { icon: '🔐', desc: 'Securely access your account.' },
      register: { icon: '📝', desc: 'Register as a new patient.' },
      reset: { icon: '🔑', desc: 'Recover your account access.' },
      appointment: { icon: '📅', desc: 'Schedule a consultation.' },
      video: { icon: '📹', desc: 'Learn about online visits.' },
      contact: { icon: '💬', desc: 'Contact the City Health Office.' },
      services: { icon: '🏥', desc: 'Explore available services.' },
      hours: { icon: '🕐', desc: 'View office hours.' },
      callEmergency: { icon: '📞', desc: 'Call 911 for immediate help.', danger: true },
      contactChoNonEmergency: { icon: '🏛️', desc: 'City Health Office (non-emergency).' },
      leaveMessage: { icon: '✉️', desc: 'Send a message to CHO staff.' },
      openSignIn: { icon: '🔐', desc: 'Open the sign-in panel.' },
      contactSupport: { icon: '💬', desc: 'Get help from support.' },
      createAccount: { icon: '📝', desc: 'Create a new patient account.' },
      signIn: { icon: '🔐', desc: 'Sign in to your account.' },
      bookAppointment: { icon: '📅', desc: 'Book a healthcare appointment.' },
      ourServices: { icon: '🏥', desc: 'See what medConnect offers.' },
      officeHours: { icon: '🕐', desc: 'Check when the office is open.' },
    },
    fil: {
      signin: { icon: '🔐', desc: 'Secure na pag-access sa account.' },
      register: { icon: '📝', desc: 'Magrehistro bilang bagong pasyente.' },
      reset: { icon: '🔑', desc: 'I-recover ang access sa account.' },
      appointment: { icon: '📅', desc: 'Mag-schedule ng konsultasyon.' },
      video: { icon: '📹', desc: 'Alamin ang online na konsultasyon.' },
      contact: { icon: '💬', desc: 'Makipag-ugnayan sa City Health Office.' },
      services: { icon: '🏥', desc: 'Tingnan ang mga available na serbisyo.' },
      hours: { icon: '🕐', desc: 'Tingnan ang oras ng opisina.' },
      callEmergency: { icon: '📞', desc: 'Tumawag sa 911 para sa agarang tulong.', danger: true },
      contactChoNonEmergency: { icon: '🏛️', desc: 'City Health Office (hindi emergency).' },
      leaveMessage: { icon: '✉️', desc: 'Magpadala ng mensahe sa CHO staff.' },
      openSignIn: { icon: '🔐', desc: 'Buksan ang sign-in panel.' },
      contactSupport: { icon: '💬', desc: 'Kumuha ng tulong mula sa support.' },
      createAccount: { icon: '📝', desc: 'Gumawa ng bagong patient account.' },
      signIn: { icon: '🔐', desc: 'Mag-sign in sa iyong account.' },
      bookAppointment: { icon: '📅', desc: 'Mag-book ng healthcare appointment.' },
      ourServices: { icon: '🏥', desc: 'Tingnan ang inaalok ng medConnect.' },
      officeHours: { icon: '🕐', desc: 'Tingnan kung kailan bukas ang opisina.' },
    },
    hil: {
      signin: { icon: '🔐', desc: 'Secure nga pag-access sa account.' },
      register: { icon: '📝', desc: 'Magrehistro bilang bag-o nga pasyente.' },
      reset: { icon: '🔑', desc: 'I-recover ang access sa account.' },
      appointment: { icon: '📅', desc: 'Mag-schedule sang konsultasyon.' },
      video: { icon: '📹', desc: 'Hibal-i ang online nga konsultasyon.' },
      contact: { icon: '💬', desc: 'Makig-ugnayan sa City Health Office.' },
      services: { icon: '🏥', desc: 'Tan-awon ang mga available nga serbisyo.' },
      hours: { icon: '🕐', desc: 'Tan-awon kon san-o bukas ang opisina.' },
      callEmergency: { icon: '📞', desc: 'Tawagi ang 911 para sa gilayon nga bulig.', danger: true },
      contactChoNonEmergency: { icon: '🏛️', desc: 'City Health Office (indi emergency).' },
      leaveMessage: { icon: '✉️', desc: 'Magpadala sang mensahe sa CHO staff.' },
      openSignIn: { icon: '🔐', desc: 'Buksan ang sign-in panel.' },
      contactSupport: { icon: '💬', desc: 'Kumuha sang bulig gikan sa support.' },
      createAccount: { icon: '📝', desc: 'Maghimo sang bag-o nga patient account.' },
      signIn: { icon: '🔐', desc: 'Mag-sign in sa imo account.' },
      bookAppointment: { icon: '📅', desc: 'Mag-book sang healthcare appointment.' },
      ourServices: { icon: '🏥', desc: 'Tan-awon ang ginatanyag sang medConnect.' },
      officeHours: { icon: '🕐', desc: 'Tan-awon kon san-o bukas ang opisina.' },
    },
  };

  const QUICK_ACTIONS = {
    en: [
      { icon: '🔐', title: 'Sign In', desc: 'Securely access your account.', flow: 'signin' },
      { icon: '📝', title: 'Create Account', desc: 'Register as a new patient.', flow: 'register' },
      { icon: '🔑', title: 'Reset Password', desc: 'Recover your account access.', flow: 'reset' },
      { icon: '📅', title: 'Book Appointment', desc: 'Schedule a consultation.', flow: 'appointment' },
      { icon: '📹', title: 'Video Consultation', desc: 'Learn about online visits.', flow: 'video' },
      { icon: '💬', title: 'Leave a Message', desc: 'Contact the City Health Office.', flow: 'contact' },
    ],
    fil: [
      { icon: '🔐', title: 'Mag-sign In', desc: 'Secure na pag-access sa account.', flow: 'signin' },
      { icon: '📝', title: 'Gumawa ng Account', desc: 'Magrehistro bilang bagong pasyente.', flow: 'register' },
      { icon: '🔑', title: 'I-reset ang Password', desc: 'I-recover ang access sa account.', flow: 'reset' },
      { icon: '📅', title: 'Mag-book ng Appointment', desc: 'Mag-schedule ng konsultasyon.', flow: 'appointment' },
      { icon: '📹', title: 'Video Consultation', desc: 'Alamin ang online na konsultasyon.', flow: 'video' },
      { icon: '💬', title: 'Mag-iwan ng Mensahe', desc: 'Makipag-ugnayan sa City Health Office.', flow: 'contact' },
    ],
    hil: [
      { icon: '🔐', title: 'Mag-sign In', desc: 'Secure nga pag-access sa account.', flow: 'signin' },
      { icon: '📝', title: 'Maghimo sang Account', desc: 'Magrehistro bilang bag-o nga pasyente.', flow: 'register' },
      { icon: '🔑', title: 'I-reset ang Password', desc: 'I-recover ang access sa account.', flow: 'reset' },
      { icon: '📅', title: 'Mag-book sang Appointment', desc: 'Mag-schedule sang konsultasyon.', flow: 'appointment' },
      { icon: '📹', title: 'Video Konsultasyon', desc: 'Hibal-i ang online nga konsultasyon.', flow: 'video' },
      { icon: '💬', title: 'Magbilin sang Mensahe', desc: 'Makig-ugnayan sa City Health Office.', flow: 'contact' },
    ],
  };

  const FLOW_LABELS = {
    en: {
      signin: 'How do I sign in?', register: 'How do I register?', reset: 'Forgot my password',
      appointment: 'How do I book an appointment?', appointment_status: 'Appointment status',
      video: 'Video consultation', records: 'Medical records', prescriptions: 'Prescriptions',
      notifications: 'Notifications', hours: 'Office hours', services: 'What services are available?',
      contact: 'Leave a message', welcome: 'Hello', clarify: 'Clarification',
    },
    fil: {
      signin: 'Paano mag-sign in?', register: 'Paano magrehistro?', reset: 'Nakalimutan ang password',
      appointment: 'Paano mag-book ng appointment?', appointment_status: 'Status ng appointment',
      video: 'Video consultation', records: 'Medical records', prescriptions: 'Mga reseta',
      notifications: 'Mga notification', hours: 'Oras ng opisina', services: 'Anong mga serbisyo ang available?',
      contact: 'Mag-iwan ng mensahe', welcome: 'Kumusta', clarify: 'Klaripikasyon',
    },
    hil: {
      signin: 'Paano mag-sign in?', register: 'Paano magrehistro?', reset: 'Nakalimtan ko ang password',
      appointment: 'Diin ko maka-book sang appointment?', appointment_status: 'Status sang appointment',
      video: 'Video konsultasyon', records: 'Medical records', prescriptions: 'Mga reseta',
      notifications: 'Mga notification', hours: 'Oras sang opisina', services: 'Ano ang mga serbisyo nga available?',
      contact: 'Magbilin sang mensahe', welcome: 'Kumusta', clarify: 'Klaripikasyon',
    },
  };

  /** @type {Record<string, Record<string, { html: string, followUp?: string|null, actions?: Array }>>} */
  const FLOWS = {
    signin: {
      en: {
        html: '<p><strong>To sign in to medConnect:</strong></p><ol><li>Click <strong>Sign In</strong> at the top-right of the home page.</li><li>Enter your registered <strong>email address</strong>.</li><li>Enter your <strong>password</strong>.</li><li>Click <strong>Sign In</strong> to access your dashboard.</li></ol><p>First-time users may be guided through a quick account setup after signing in.</p>',
        followUpKey: 'needHelp',
        actions: ['openSignIn', 'forgotPassword', 'createAccount', 'contactSupport'],
      },
      fil: {
        html: '<p><strong>Para mag-sign in sa medConnect:</strong></p><ol><li>I-click ang <strong>Sign In</strong> sa kanang itaas ng home page.</li><li>Ilagay ang iyong nakarehistrong <strong>email address</strong>.</li><li>Ilagay ang iyong <strong>password</strong>.</li><li>I-click ang <strong>Sign In</strong> para ma-access ang dashboard.</li></ol><p>Ang mga first-time user ay maaaring gabayan sa mabilis na account setup pagkatapos mag-sign in.</p>',
        followUpKey: 'needHelp',
        actions: ['openSignIn', 'forgotPassword', 'createAccount', 'contactSupport'],
      },
      hil: {
        html: '<p><strong>Para mag-sign in sa medConnect:</strong></p><ol><li>I-click ang <strong>Sign In</strong> sa tuo nga ibabaw sang home page.</li><li>Isulod ang imo nakarehistrong <strong>email address</strong>.</li><li>Isulod ang imo <strong>password</strong>.</li><li>I-click ang <strong>Sign In</strong> para ma-access ang dashboard.</li></ol><p>Ang mga first-time user pwede ma-guide sa mabilis nga account setup pagkatapos mag-sign in.</p>',
        followUpKey: 'needHelp',
        actions: ['openSignIn', 'forgotPassword', 'createAccount', 'contactSupport'],
      },
    },
    register: {
      en: {
        html: '<p><strong>How to create a patient account:</strong></p><ol><li>Click <strong>Create a patient account</strong> on the sign-in panel.</li><li><strong>Step 1:</strong> Verify your email with the 6-digit OTP.</li><li><strong>Step 2:</strong> Upload valid ID and residency details.</li><li><strong>Step 3:</strong> Complete the patient health form.</li><li>Sign in with your new email and password.</li></ol><p>Provider accounts are created by the system administrator.</p>',
        followUpKey: 'readyStart',
        actions: ['startRegistration', 'viewRequirements', 'signIn'],
      },
      fil: {
        html: '<p><strong>Paano gumawa ng patient account:</strong></p><ol><li>I-click ang <strong>Create a patient account</strong> sa sign-in panel.</li><li><strong>Hakbang 1:</strong> I-verify ang email gamit ang 6-digit OTP.</li><li><strong>Hakbang 2:</strong> I-upload ang valid ID at residency details.</li><li><strong>Hakbang 3:</strong> Kumpletuhin ang patient health form.</li><li>Mag-sign in gamit ang bagong email at password.</li></ol><p>Ang provider accounts ay ginagawa ng system administrator.</p>',
        followUpKey: 'readyStart',
        actions: ['startRegistration', 'viewRequirements', 'signIn'],
      },
      hil: {
        html: '<p><strong>Paano maghimo sang patient account:</strong></p><ol><li>I-click ang <strong>Create a patient account</strong> sa sign-in panel.</li><li><strong>Step 1:</strong> I-verify ang email gamit ang 6-digit OTP.</li><li><strong>Step 2:</strong> I-upload ang valid ID kag residency details.</li><li><strong>Step 3:</strong> Kompletoha ang patient health form.</li><li>Mag-sign in gamit ang bag-o nga email kag password.</li></ol><p>Ang provider accounts ginahimo sang system administrator.</p>',
        followUpKey: 'readyStart',
        actions: ['startRegistration', 'viewRequirements', 'signIn'],
      },
    },
    reset: {
      en: {
        html: '<p><strong>To reset your password:</strong></p><ol><li>Open <strong>Sign In</strong> and click <strong>Forgot password?</strong></li><li>Enter your registered email address.</li><li>Check your inbox for a one-time code (OTP).</li><li>Enter the OTP and set a new password.</li><li>Sign in with your new credentials.</li></ol>',
        followUpKey: 'resetNow',
        actions: ['resetPasswordNow', 'signIn', 'contactSupport'],
      },
      fil: {
        html: '<p><strong>Para i-reset ang password:</strong></p><ol><li>Buksan ang <strong>Sign In</strong> at i-click ang <strong>Forgot password?</strong></li><li>Ilagay ang nakarehistrong email address.</li><li>Tingnan ang inbox para sa one-time code (OTP).</li><li>Ilagay ang OTP at mag-set ng bagong password.</li><li>Mag-sign in gamit ang bagong credentials.</li></ol>',
        followUpKey: 'resetNow',
        actions: ['resetPasswordNow', 'signIn', 'contactSupport'],
      },
      hil: {
        html: '<p><strong>Para i-reset ang password:</strong></p><ol><li>Buksa ang <strong>Sign In</strong> kag i-click ang <strong>Forgot password?</strong></li><li>Isulod ang nakarehistrong email address.</li><li>Tan-awa ang inbox para sa one-time code (OTP).</li><li>Isulod ang OTP kag mag-set sang bag-o nga password.</li><li>Mag-sign in gamit ang bag-o nga credentials.</li></ol>',
        followUpKey: 'resetNow',
        actions: ['resetPasswordNow', 'signIn', 'contactSupport'],
      },
    },
    appointment: {
      en: {
        html: '<p><strong>How to book an appointment:</strong></p><ol><li><strong>Sign in</strong> to your patient account.</li><li>Go to your <strong>dashboard</strong> or consultation section.</li><li>Select an available <strong>provider schedule</strong> and time slot.</li><li>Confirm your appointment details.</li><li>You will receive a <strong>notification</strong> when confirmed.</li></ol>',
        followUpKey: 'needSignIn',
        actions: ['openSignIn', 'createAccount', 'officeHours'],
      },
      fil: {
        html: '<p><strong>Paano mag-book ng appointment:</strong></p><ol><li><strong>Mag-sign in</strong> sa patient account.</li><li>Pumunta sa <strong>dashboard</strong> o consultation section.</li><li>Pumili ng available na <strong>provider schedule</strong> at time slot.</li><li>Kumpirmahin ang appointment details.</li><li>Makakatanggap ka ng <strong>notification</strong> kapag nakumpirma.</li></ol>',
        followUpKey: 'needSignIn',
        actions: ['openSignIn', 'createAccount', 'officeHours'],
      },
      hil: {
        html: '<p><strong>Paano mag-book sang appointment:</strong></p><ol><li><strong>Mag-sign in</strong> sa patient account.</li><li>Lakat sa <strong>dashboard</strong> ukon consultation section.</li><li>Pili sang available nga <strong>provider schedule</strong> kag time slot.</li><li>Kumpirmaha ang appointment details.</li><li>Makabaton ka sang <strong>notification</strong> kon nakumpirma.</li></ol>',
        followUpKey: 'needSignIn',
        actions: ['openSignIn', 'createAccount', 'officeHours'],
      },
    },
    appointment_status: {
      en: {
        html: '<p><strong>Checking your appointment status:</strong></p><ol><li><strong>Sign in</strong> to your patient account.</li><li>Open your <strong>dashboard</strong> or appointments section.</li><li>View pending, confirmed, or completed appointments.</li><li>Check <strong>notifications</strong> for updates from your provider.</li></ol>',
        followUpKey: 'needAccount',
        actions: ['openSignIn', 'bookAppointment', 'contactSupport'],
      },
      fil: {
        html: '<p><strong>Paano tingnan ang status ng appointment:</strong></p><ol><li><strong>Mag-sign in</strong> sa patient account.</li><li>Buksan ang <strong>dashboard</strong> o appointments section.</li><li>Tingnan ang pending, confirmed, o completed na appointments.</li><li>Suriin ang <strong>notifications</strong> para sa updates mula sa provider.</li></ol>',
        followUpKey: 'needAccount',
        actions: ['openSignIn', 'bookAppointment', 'contactSupport'],
      },
      hil: {
        html: '<p><strong>Paano tan-awon ang status sang appointment:</strong></p><ol><li><strong>Mag-sign in</strong> sa patient account.</li><li>Bukas ang <strong>dashboard</strong> ukon appointments section.</li><li>Tan-awa ang pending, confirmed, ukon completed nga appointments.</li><li>Check ang <strong>notifications</strong> para sa updates halin sa provider.</li></ol>',
        followUpKey: 'needAccount',
        actions: ['openSignIn', 'bookAppointment', 'contactSupport'],
      },
    },
    video: {
      en: {
        html: '<p><strong>Video consultation on medConnect:</strong></p><ul><li>Secure, non-emergency online visits with licensed providers.</li><li>Join from your patient dashboard when your appointment is active.</li><li>Ensure a stable internet connection and a quiet environment.</li><li>Have your medical history and current symptoms ready.</li></ul><p>For emergencies, go to the nearest hospital immediately.</p>',
        followUpKey: 'bookConsult',
        actions: ['bookAppointment', 'openSignIn', 'ourServices'],
      },
      fil: {
        html: '<p><strong>Video consultation sa medConnect:</strong></p><ul><li>Secure at hindi emergency na online visits kasama ang licensed providers.</li><li>Sumali mula sa patient dashboard kapag active ang appointment.</li><li>Siguraduhing stable ang internet at tahimik ang paligid.</li><li>Ihanda ang medical history at kasalukuyang sintomas.</li></ul><p>Para sa emergency, pumunta agad sa pinakamalapit na ospital.</p>',
        followUpKey: 'bookConsult',
        actions: ['bookAppointment', 'openSignIn', 'ourServices'],
      },
      hil: {
        html: '<p><strong>Video konsultasyon sa medConnect:</strong></p><ul><li>Secure kag indi emergency nga online visits upod ang licensed providers.</li><li>Apil gikan sa patient dashboard kon active ang appointment.</li><li>Siguraduhon nga stable ang internet kag tahimik ang palibot.</li><li>Andami ang medical history kag subong nga sintomas.</li></ul><p>Para sa emergency, lakat dayon sa pinakamalapit nga hospital.</p>',
        followUpKey: 'bookConsult',
        actions: ['bookAppointment', 'openSignIn', 'ourServices'],
      },
    },
    records: {
      en: {
        html: '<p><strong>Accessing your medical records:</strong></p><ol><li>Sign in to your <strong>patient portal</strong>.</li><li>Open <strong>My Health</strong> or <strong>Medical Records</strong>.</li><li>View consultation history, prescriptions, and health summaries.</li></ol><p>Your records are stored securely and accessible only to authorized providers.</p>',
        followUpKey: 'needAccount',
        actions: ['openSignIn', 'createAccount'],
      },
      fil: {
        html: '<p><strong>Paano ma-access ang medical records:</strong></p><ol><li>Mag-sign in sa <strong>patient portal</strong>.</li><li>Buksan ang <strong>My Health</strong> o <strong>Medical Records</strong>.</li><li>Tingnan ang consultation history, prescriptions, at health summaries.</li></ol><p>Ang records ay ligtas na naka-imbak at accessible lamang sa authorized providers.</p>',
        followUpKey: 'needAccount',
        actions: ['openSignIn', 'createAccount'],
      },
      hil: {
        html: '<p><strong>Paano ma-access ang medical records:</strong></p><ol><li>Mag-sign in sa <strong>patient portal</strong>.</li><li>Bukas ang <strong>My Health</strong> ukon <strong>Medical Records</strong>.</li><li>Tan-awa ang consultation history, prescriptions, kag health summaries.</li></ol><p>Ang records ligtas nga gintipigan kag accessible lang sa authorized providers.</p>',
        followUpKey: 'needAccount',
        actions: ['openSignIn', 'createAccount'],
      },
    },
    prescriptions: {
      en: {
        html: '<p><strong>Viewing prescriptions on medConnect:</strong></p><ol><li>Sign in to your <strong>patient portal</strong>.</li><li>Open <strong>My Health</strong> or your consultation history.</li><li>View digital prescriptions shared by your provider after a consultation.</li></ol><p>I cannot prescribe medication or interpret prescriptions through chat.</p>',
        followUpKey: 'needAccount',
        actions: ['openSignIn', 'bookAppointment', 'contactSupport'],
      },
      fil: {
        html: '<p><strong>Paano tingnan ang prescriptions sa medConnect:</strong></p><ol><li>Mag-sign in sa <strong>patient portal</strong>.</li><li>Buksan ang <strong>My Health</strong> o consultation history.</li><li>Tingnan ang digital prescriptions mula sa provider pagkatapos ng konsultasyon.</li></ol><p>Hindi ako makakapag-reseta ng gamot o mag-interpret ng reseta sa chat.</p>',
        followUpKey: 'needAccount',
        actions: ['openSignIn', 'bookAppointment', 'contactSupport'],
      },
      hil: {
        html: '<p><strong>Paano tan-awon ang prescriptions sa medConnect:</strong></p><ol><li>Mag-sign in sa <strong>patient portal</strong>.</li><li>Bukas ang <strong>My Health</strong> ukon consultation history.</li><li>Tan-awa ang digital prescriptions halin sa provider pagkatapos sang konsultasyon.</li></ol><p>Indi ako makapag-reseta sang bulong ukon mag-interpret sang reseta sa chat.</p>',
        followUpKey: 'needAccount',
        actions: ['openSignIn', 'bookAppointment', 'contactSupport'],
      },
    },
    notifications: {
      en: {
        html: '<p><strong>Notifications on medConnect:</strong></p><ul><li>Appointment confirmations and reminders</li><li>Messages from your healthcare provider</li><li>Triage updates and follow-up alerts</li></ul><p>Manage notification preferences in <strong>Settings</strong> after signing in.</p>',
        actions: ['openSignIn', 'bookAppointment'],
      },
      fil: {
        html: '<p><strong>Mga notification sa medConnect:</strong></p><ul><li>Appointment confirmations at reminders</li><li>Mga mensahe mula sa healthcare provider</li><li>Triage updates at follow-up alerts</li></ul><p>Pamahalaan ang notification preferences sa <strong>Settings</strong> pagkatapos mag-sign in.</p>',
        actions: ['openSignIn', 'bookAppointment'],
      },
      hil: {
        html: '<p><strong>Mga notification sa medConnect:</strong></p><ul><li>Appointment confirmations kag reminders</li><li>Mga mensahe halin sa healthcare provider</li><li>Triage updates kag follow-up alerts</li></ul><p>Dumalaha ang notification preferences sa <strong>Settings</strong> pagkatapos mag-sign in.</p>',
        actions: ['openSignIn', 'bookAppointment'],
      },
    },
    hours: {
      en: {
        html: '<p><strong>City Health Office — Office Hours</strong></p><ul><li><strong>Days:</strong> Monday – Friday</li><li><strong>Hours:</strong> 8:00 AM – 5:00 PM</li><li><strong>Location:</strong> City Health Office, Bago City, Negros Occidental</li></ul><p>medConnect is available online outside office hours for account access; live support follows office hours.</p>',
        actions: ['contactInfo', 'goContact'],
      },
      fil: {
        html: '<p><strong>City Health Office — Oras ng Opisina</strong></p><ul><li><strong>Mga Araw:</strong> Lunes – Biyernes</li><li><strong>Oras:</strong> 8:00 AM – 5:00 PM</li><li><strong>Lokasyon:</strong> City Health Office, Bago City, Negros Occidental</li></ul><p>Available ang medConnect online kahit labas ng oras ng opisina para sa account access; ang live support ay sumusunod sa oras ng opisina.</p>',
        actions: ['contactInfo', 'goContact'],
      },
      hil: {
        html: '<p><strong>City Health Office — Oras sang Opisina</strong></p><ul><li><strong>Mga Adlaw:</strong> Lunes – Biyernes</li><li><strong>Oras:</strong> 8:00 AM – 5:00 PM</li><li><strong>Lokasyon:</strong> City Health Office, Bago City, Negros Occidental</li></ul><p>Available ang medConnect online bisan sa guwa sang oras sang opisina para sa account access; ang live support nagsunod sa oras sang opisina.</p>',
        actions: ['contactInfo', 'goContact'],
      },
    },
    services: {
      en: {
        html: '<p><strong>medConnect services include:</strong></p><ul><li>🩺 AI-Assisted Triage</li><li>📹 Medical Video Consultation</li><li>📋 Centralized Medical Records</li><li>💊 Digital Prescriptions</li><li>📅 Appointment Scheduling</li><li>🔔 Notifications &amp; Follow-ups</li></ul>',
        followUpKey: 'whatNext',
        actions: ['createAccount', 'signIn', 'bookAppointment'],
      },
      fil: {
        html: '<p><strong>Kasama sa mga serbisyo ng medConnect:</strong></p><ul><li>🩺 AI-Assisted Triage</li><li>📹 Medical Video Consultation</li><li>📋 Centralized Medical Records</li><li>💊 Digital Prescriptions</li><li>📅 Appointment Scheduling</li><li>🔔 Notifications &amp; Follow-ups</li></ul>',
        followUpKey: 'whatNext',
        actions: ['createAccount', 'signIn', 'bookAppointment'],
      },
      hil: {
        html: '<p><strong>Mga serbisyo sang medConnect:</strong></p><ul><li>🩺 AI-Assisted Triage</li><li>📹 Medical Video Consultation</li><li>📋 Centralized Medical Records</li><li>💊 Digital Prescriptions</li><li>📅 Appointment Scheduling</li><li>🔔 Notifications &amp; Follow-ups</li></ul>',
        followUpKey: 'whatNext',
        actions: ['createAccount', 'signIn', 'bookAppointment'],
      },
    },
    contact: {
      en: {
        html: '<p><strong>Contact the City Health Office</strong></p><ul><li><strong>Phone:</strong> (034) 445-8000</li><li><strong>Email:</strong> cho.bagocity@example.gov.ph</li><li><strong>Hours:</strong> Mon – Fri, 8:00 AM – 5:00 PM</li><li><strong>Address:</strong> City Health Office, Bago City, Negros Occidental</li></ul>',
        followUpKey: 'nonEmergency',
        actions: ['goContact', 'officeHours'],
      },
      fil: {
        html: '<p><strong>Makipag-ugnayan sa City Health Office</strong></p><ul><li><strong>Telepono:</strong> (034) 445-8000</li><li><strong>Email:</strong> cho.bagocity@example.gov.ph</li><li><strong>Oras:</strong> Lunes – Biyernes, 8:00 AM – 5:00 PM</li><li><strong>Address:</strong> City Health Office, Bago City, Negros Occidental</li></ul>',
        followUpKey: 'nonEmergency',
        actions: ['goContact', 'officeHours'],
      },
      hil: {
        html: '<p><strong>Makig-ugnayan sa City Health Office</strong></p><ul><li><strong>Telepono:</strong> (034) 445-8000</li><li><strong>Email:</strong> cho.bagocity@example.gov.ph</li><li><strong>Oras:</strong> Lunes – Biyernes, 8:00 AM – 5:00 PM</li><li><strong>Address:</strong> City Health Office, Bago City, Negros Occidental</li></ul>',
        followUpKey: 'nonEmergency',
        actions: ['goContact', 'officeHours'],
      },
    },
    policy: {
      en: {
        html: '<p>I\'m sorry, but I cannot provide medical advice. Please schedule a consultation with a healthcare provider through medConnect or visit the nearest City Health Office.</p><p>I can help with <strong>medConnect platform guidance</strong> only — I cannot diagnose conditions, prescribe medication, or interpret lab results.</p>',
        followUpKey: 'policyFollowUp',
        actions: ['signInHelp', 'bookAppointment', 'contactCho'],
      },
      fil: {
        html: '<p>Paumanhin, ngunit hindi ako makakapagbigay ng medical advice. Mangyaring mag-schedule ng konsultasyon sa healthcare provider sa pamamagitan ng medConnect o bumisita sa pinakamalapit na City Health Office.</p><p>Maaari lamang akong tumulong sa <strong>medConnect platform guidance</strong> — hindi ako makakapag-diagnose, mag-reseta ng gamot, o mag-interpret ng lab results.</p>',
        followUpKey: 'policyFollowUp',
        actions: ['signInHelp', 'bookAppointment', 'contactCho'],
      },
      hil: {
        html: '<p>Pasensya na, indi ako makapaghatag sang medical advice. Palihog mag-schedule sang konsultasyon sa healthcare provider paagi sa medConnect ukon bisitaha ang pinakamalapit nga City Health Office.</p><p>Makabulig lang ako sa <strong>medConnect platform guidance</strong> — indi ako makapag-diagnose, mag-reseta sang bulong, ukon mag-interpret sang lab results.</p>',
        followUpKey: 'policyFollowUp',
        actions: ['signInHelp', 'bookAppointment', 'contactCho'],
      },
    },
    unknown: {
      en: {
        html: '<p>I\'m sorry, I couldn\'t quite understand your message. I can still help you with <strong>appointments</strong>, <strong>registration</strong>, <strong>consultations</strong>, and other City Health Office services.</p>',
        followUpKey: 'tryTopics',
        actions: ['signIn', 'createAccount', 'bookAppointment', 'contactSupport'],
      },
      fil: {
        html: '<p>Paumanhin, hindi ko lubos na naintindihan ang iyong mensahe. Maaari pa rin kitang tulungan sa <strong>appointments</strong>, <strong>rehistrasyon</strong>, <strong>konsultasyon</strong>, at iba pang serbisyo ng City Health Office.</p>',
        followUpKey: 'tryTopics',
        actions: ['signIn', 'createAccount', 'bookAppointment', 'contactSupport'],
      },
      hil: {
        html: '<p>Pasensya na, indi ko gid maintindihan ang imo mensahe. Matabangan ko gihapon ikaw sa <strong>appointments</strong>, <strong>rehistrasyon</strong>, <strong>konsultasyon</strong>, kag iban pa nga serbisyo sang City Health Office.</p>',
        followUpKey: 'tryTopics',
        actions: ['signIn', 'createAccount', 'bookAppointment', 'contactSupport'],
      },
    },
    clarify: {
      en: { html: '<p>No worries — I\'m sorry I couldn\'t quite understand. Could you rephrase your question? I\'ll gladly walk you through it step by step.</p>', followUpKey: 'tryTopics', actions: ['signIn', 'bookAppointment', 'contactSupport'] },
      fil: { html: '<p>Walang problema — paumanhin, hindi ko lubos na naintindihan. Maaari mo bang i-rephrase ang tanong mo? Gagabayan kita nang hakbang-hakbang.</p>', followUpKey: 'tryTopics', actions: ['signIn', 'bookAppointment', 'contactSupport'] },
      hil: { html: '<p>Wala problema — pasensya na, indi ko gid maintindihan. Pwede mo bala i-rephrase ang imo pamangkot? Tuytuyan ko ikaw step-by-step.</p>', followUpKey: 'tryTopics', actions: ['signIn', 'bookAppointment', 'contactSupport'] },
    },
    emergency: {
      en: {
        html: '<div class="fcb-emergency-support"><p class="fcb-emergency-support__title">🚨 Emergency Support</p><p>I\'m really sorry you\'re going through something so difficult.</p><p><strong>Your message may describe a medical emergency.</strong> If you are in immediate danger, please contact <strong>local emergency services (911)</strong> or go to the nearest hospital right away.</p><p><strong>Do not wait</strong> for an online consultation. medConnect is for non-emergency care only.</p><p>When you\'re ready, I can also help you connect with City Health Office services.</p></div>',
        actions: ['callEmergency', 'contactChoNonEmergency', 'leaveMessage'],
      },
      fil: {
        html: '<div class="fcb-emergency-support"><p class="fcb-emergency-support__title">🚨 Emergency Support</p><p>Paumanhin na dumadaan ka sa napakahirap na sitwasyon.</p><p><strong>Maaaring medical emergency ang iyong mensahe.</strong> Kung nasa agarang panganib ka, mangyaring tumawag sa <strong>local emergency services (911)</strong> o pumunta agad sa pinakamalapit na ospital.</p><p><strong>Huwag maghintay</strong> ng online consultation. Ang medConnect ay para lamang sa hindi emergency na pangangalaga.</p><p>Kapag handa ka na, matutulungan kitang makipag-ugnayan sa mga serbisyo ng City Health Office.</p></div>',
        actions: ['callEmergency', 'contactChoNonEmergency', 'leaveMessage'],
      },
      hil: {
        html: '<div class="fcb-emergency-support"><p class="fcb-emergency-support__title">🚨 Emergency Support</p><p>Pasensya nga nagaguol ka sa sining kalisod.</p><p><strong>Posible nga medical emergency ang imo mensahe.</strong> Kon sa gilayon nga katalagman ka, palihog tawagi ang <strong>local emergency services (911)</strong> ukon lakat gilayon sa pinakamalapit nga hospital.</p><p><strong>Indi maghulat</strong> sang online consultation. Ang medConnect para lang sa indi emergency nga pag-atipan.</p><p>Kon handa ka na, matabangan ko ikaw makig-ugnayan sa mga serbisyo sang City Health Office.</p></div>',
        actions: ['callEmergency', 'contactChoNonEmergency', 'leaveMessage'],
      },
    },
    greeting: {
      en: {
        html: '<p>Hello! Welcome to <strong>medConnect</strong>.</p><p>I\'m here to help you with appointments, consultations, account assistance, medical records, and other City Health Office services.</p>',
        followUpKey: 'welcomeCta',
        actions: ['signIn', 'createAccount', 'bookAppointment', 'contactCho'],
      },
      fil: {
        html: '<p>Kumusta! Maligayang pagdating sa <strong>medConnect</strong>.</p><p>Nandito ako para tulungan ka sa appointments, consultations, account assistance, medical records, at iba pang serbisyo ng City Health Office.</p>',
        followUpKey: 'welcomeCta',
        actions: ['signIn', 'createAccount', 'bookAppointment', 'contactCho'],
      },
      hil: {
        html: '<p>Kumusta! Maayong pag-abot sa <strong>medConnect</strong>.</p><p>Diri ako para buligan ka sa appointments, consultations, account assistance, medical records, kag iban pa nga serbisyo sang City Health Office.</p>',
        followUpKey: 'welcomeCta',
        actions: ['signIn', 'createAccount', 'bookAppointment', 'contactCho'],
      },
    },
    greeting_return: {
      en: {
        html: '<p>Hello again! I\'m still here to help you with medConnect and City Health Office services.</p>',
        followUpKey: 'howCanHelp',
        actions: ['signIn', 'bookAppointment', 'contactCho'],
      },
      fil: {
        html: '<p>Kumusta ulit! Nandito pa rin ako para tulungan ka sa medConnect at City Health Office services.</p>',
        followUpKey: 'howCanHelp',
        actions: ['signIn', 'bookAppointment', 'contactCho'],
      },
      hil: {
        html: '<p>Kumusta liwat! Diri gihapon ako para buligan ka sa medConnect kag City Health Office services.</p>',
        followUpKey: 'howCanHelp',
        actions: ['signIn', 'bookAppointment', 'contactCho'],
      },
    },
    partial_clarify: {
      en: { followUpKey: 'tryTopics', actions: ['signIn', 'bookAppointment', 'contactSupport'] },
      fil: { followUpKey: 'tryTopics', actions: ['signIn', 'bookAppointment', 'contactSupport'] },
      hil: { followUpKey: 'tryTopics', actions: ['signIn', 'bookAppointment', 'contactSupport'] },
    },
    not_understood: {
      en: { followUpKey: 'tryTopics', actions: ['signIn', 'createAccount', 'bookAppointment', 'contactSupport'] },
      fil: { followUpKey: 'tryTopics', actions: ['signIn', 'createAccount', 'bookAppointment', 'contactSupport'] },
      hil: { followUpKey: 'tryTopics', actions: ['signIn', 'createAccount', 'bookAppointment', 'contactSupport'] },
    },
    reassurance: {
      en: {
        html: '<p>I understand why you\'re asking. 😊</p><p><strong>medConnect</strong> is designed to help patients access City Health Office services more easily — such as booking appointments, joining video consultations, viewing medical records, and receiving general healthcare guidance.</p><p>While I can answer questions and guide you through the system, <strong>I cannot replace a healthcare professional or provide medical diagnoses or treatment.</strong></p><p>If your concern requires medical attention, I can help you schedule an appointment or connect you with the appropriate healthcare service.</p>',
        followUpKey: 'welcomeCta',
        actions: ['bookAppointment', 'ourServices', 'signIn', 'contactCho'],
      },
      fil: {
        html: '<p>Naiintindihan ko kung bakit mo tinatanong. 😊</p><p>Ang <strong>medConnect</strong> ay idinisenyo para tulungan ang mga pasyente na mas madaling ma-access ang mga serbisyo ng City Health Office — tulad ng pag-book ng appointments, video consultations, pagtingin sa medical records, at pangkalahatang healthcare guidance.</p><p>Habang makakatulong ako sa mga tanong at gagabayan kita sa sistema, <strong>hindi ako makakapagpalit sa healthcare professional o makakapagbigay ng medical diagnosis o treatment.</strong></p><p>Kung kailangan ng medical attention ang iyong alalahanin, matutulungan kitang mag-schedule ng appointment o makipag-ugnayan sa naaangkop na healthcare service.</p>',
        followUpKey: 'welcomeCta',
        actions: ['bookAppointment', 'ourServices', 'signIn', 'contactCho'],
      },
      hil: {
        html: '<p>Naintiendihan ko kon ngaa ginapamangkot mo ini. 😊</p><p>Ang <strong>medConnect</strong> gindisenyo para matabangan ang mga pasyente nga mas mahapos ma-access ang mga serbisyo sang City Health Office — pareho sang pag-book sang appointments, video consultations, pagtan-aw sang medical records, kag pangkalahatang healthcare guidance.</p><p>Bisan makabulig ako sa mga pamangkot kag tuytuyan ka sa sistema, <strong>indi ako makapuli sa healthcare professional ukon makapaghatag sang medical diagnosis ukon treatment.</strong></p><p>Kon kinahanglan sang medical attention ang imo kabalaka, matabangan ko ikaw mag-schedule sang appointment ukon makig-ugnayan sa angay nga healthcare service.</p>',
        followUpKey: 'welcomeCta',
        actions: ['bookAppointment', 'ourServices', 'signIn', 'contactCho'],
      },
    },
    pain_sick: {
      en: {
        html: '<p>I\'m sorry you\'re not feeling well.</p><p>While I can\'t provide a medical diagnosis, I can help you <strong>schedule an appointment</strong> or <strong>consultation</strong> with a healthcare provider through medConnect.</p>',
        followUpKey: 'policyFollowUp',
        actions: ['bookAppointment', 'openSignIn', 'contactCho'],
      },
      fil: {
        html: '<p>Paumanhin na hindi ka maganda ang pakiramdam.</p><p>Habang hindi ako makakapagbigay ng medical diagnosis, matutulungan kitang <strong>mag-schedule ng appointment</strong> o <strong>konsultasyon</strong> sa healthcare provider sa pamamagitan ng medConnect.</p>',
        followUpKey: 'policyFollowUp',
        actions: ['bookAppointment', 'openSignIn', 'contactCho'],
      },
      hil: {
        html: '<p>Pasensya nga indi maayo ang imo pamatyag.</p><p>Bisan indi ako makapaghatag sang medical diagnosis, matabangan ko ikaw nga <strong>mag-schedule sang appointment</strong> ukon <strong>konsultasyon</strong> sa healthcare provider paagi sa medConnect.</p>',
        followUpKey: 'policyFollowUp',
        actions: ['bookAppointment', 'openSignIn', 'contactCho'],
      },
    },
    welcome: {
      en: {
        html: '<p>Hello! 👋 I\'m here to help you navigate <strong>medConnect</strong> for the City Health Office of Bago City.</p>',
        followUpKey: 'chooseTopic',
        actions: ['signIn', 'createAccount', 'bookAppointment', 'contactCho'],
      },
      fil: {
        html: '<p>Kumusta! 👋 Nandito ako para tulungan kayong mag-navigate sa <strong>medConnect</strong> para sa City Health Office ng Bago City.</p>',
        followUpKey: 'chooseTopic',
        actions: ['signIn', 'createAccount', 'bookAppointment', 'contactCho'],
      },
      hil: {
        html: '<p>Kumusta! 👋 Diri ako para buligan kamo mag-navigate sa <strong>medConnect</strong> para sa City Health Office sang Bago City.</p>',
        followUpKey: 'chooseTopic',
        actions: ['signIn', 'createAccount', 'bookAppointment', 'contactCho'],
      },
    },
    gratitude: {
      en: {
        html: '<p>You\'re very welcome. I\'m glad I could help.</p><p>If you have more questions about medConnect or City Health Office services, feel free to ask.</p>',
        actions: ['bookAppointment', 'contactCho'],
      },
      fil: {
        html: '<p>Walang anuman. Natutuwa akong nakatulong.</p><p>Kung may iba ka pang tanong tungkol sa medConnect o City Health Office, huwag mag-atubiling magtanong.</p>',
        actions: ['bookAppointment', 'contactCho'],
      },
      hil: {
        html: '<p>Wala sang problema. Nalipay ako nga nakatbulig.</p><p>Kon may iban ka pa nga pamangkot parte sa medConnect ukon City Health Office, magpamangkot lang.</p>',
        actions: ['bookAppointment', 'contactCho'],
      },
    },
    happy: {
      en: {
        html: '<p>I\'m glad you\'re feeling better. If you need help with appointments, medical records, or other medConnect services, I\'m here to assist you.</p>',
        actions: ['bookAppointment', 'openSignIn', 'contactCho'],
      },
      fil: {
        html: '<p>Natutuwa akong bumuti ang pakiramdam mo. Kung kailangan mo ng tulong sa appointments, medical records, o iba pang serbisyo ng medConnect, nandito ako.</p>',
        actions: ['bookAppointment', 'openSignIn', 'contactCho'],
      },
      hil: {
        html: '<p>Nalipay ako nga maayo ang imo pamatyag. Kon kinahanglan mo sang bulig sa appointments, medical records, ukon iban nga serbisyo sang medConnect, diri ako.</p>',
        actions: ['bookAppointment', 'openSignIn', 'contactCho'],
      },
    },
    relieved: {
      en: {
        html: '<p>I\'m glad that helped. If you need anything else about medConnect or City Health Office services, just let me know.</p>',
        actions: ['bookAppointment', 'contactCho'],
      },
      fil: {
        html: '<p>Natutuwa akong nakatulong. Kung may kailangan ka pa tungkol sa medConnect o City Health Office, sabihin mo lang.</p>',
        actions: ['bookAppointment', 'contactCho'],
      },
      hil: {
        html: '<p>Nalipay ako nga nakatbulig. Kon may kinahanglan ka pa parte sa medConnect ukon City Health Office, silinga lang.</p>',
        actions: ['bookAppointment', 'contactCho'],
      },
    },
    distress_support: {
      en: {
        html: '<p>I\'m sorry to hear you\'re feeling this way.</p><p>If your concern is related to your health, I recommend scheduling a consultation with one of our healthcare providers through medConnect. I\'m here to help you with the appointment process.</p>',
        followUpKey: 'policyFollowUp',
        actions: ['bookAppointment', 'openSignIn', 'contactCho'],
      },
      fil: {
        html: '<p>Paumanhin na nararamdaman mo iyon.</p><p>Kung may kaugnayan sa iyong kalusugan ang iyong alalahanin, inirerekomenda kong mag-schedule ng konsultasyon sa healthcare provider sa pamamagitan ng medConnect. Nandito ako para tulungan ka sa proseso ng appointment.</p>',
        followUpKey: 'policyFollowUp',
        actions: ['bookAppointment', 'openSignIn', 'contactCho'],
      },
      hil: {
        html: '<p>Pasensya na nga nabatyagan mo sina.</p><p>Kon may kaangtan sa imo kahimsog ang imo kabalaka, ginasugyot ko nga mag-schedule sang konsultasyon sa healthcare provider paagi sa medConnect. Diri ako para buligan ka sa proseso sang appointment.</p>',
        followUpKey: 'policyFollowUp',
        actions: ['bookAppointment', 'openSignIn', 'contactCho'],
      },
    },
    crisis: {
      en: {
        html: '<div class="fcb-emergency-support fcb-emergency-support--crisis"><p class="fcb-emergency-support__title">🚨 Emergency Support</p><p>I\'m really sorry you\'re going through something so difficult.</p><p>Your message suggests you may need <strong>immediate support</strong>. If you\'re in immediate danger or think you may act on these thoughts, please contact <strong>emergency services (911)</strong> or the <strong>National Center for Mental Health Hopeline: 1553</strong> immediately, or go to the nearest hospital.</p><p>If possible, reach out to someone you trust — a family member, friend, or caregiver — and let them know how you\'re feeling.</p><p>I\'m a medConnect FAQ assistant and cannot provide counseling, but help is available right now. When you\'re ready, I can also help you connect with City Health Office services.</p></div>',
        actions: ['callEmergency', 'contactChoNonEmergency', 'leaveMessage'],
      },
      fil: {
        html: '<div class="fcb-emergency-support fcb-emergency-support--crisis"><p class="fcb-emergency-support__title">🚨 Emergency Support</p><p>Paumanhin na dumadaan ka sa napakahirap na sitwasyon.</p><p>Ang iyong mensahe ay nagmumungkahi na maaaring kailangan mo ng <strong>agarang suporta</strong>. Kung nasa agarang panganib ka o iniisip mong gawin ang mga ito, mangyaring tumawag agad sa <strong>emergency services (911)</strong> o sa <strong>National Center for Mental Health Hopeline: 1553</strong>, o pumunta sa pinakamalapit na ospital.</p><p>Kung maaari, makipag-ugnayan sa mapagkakatiwalaang tao — miyembro ng pamilya, kaibigan, o caregiver — at sabihin sa kanila ang iyong nararamdaman.</p><p>Ako ay medConnect FAQ assistant at hindi makakapagbigay ng counseling, ngunit may tulong na available ngayon. Kapag handa ka na, matutulungan kitang makipag-ugnayan sa mga serbisyo ng City Health Office.</p></div>',
        actions: ['callEmergency', 'contactChoNonEmergency', 'leaveMessage'],
      },
      hil: {
        html: '<div class="fcb-emergency-support fcb-emergency-support--crisis"><p class="fcb-emergency-support__title">🚨 Emergency Support</p><p>Pasensya nga nagaguol ka sa sining kalisod.</p><p>Ang imo mensahe nagapahibalo nga posible nga kinahanglan mo sang <strong>gilayon nga suporta</strong>. Kon sa gilayon nga katalagman ka ukon nagahunahuna ka nga himuon ini, palihog tawagi dayon ang <strong>emergency services (911)</strong> ukon ang <strong>National Center for Mental Health Hopeline: 1553</strong>, ukon lakat sa pinakamalapit nga hospital.</p><p>Kon mahimo, makig-ugnayan sa masaligan nga tawo — pamilya, abyan, ukon caregiver — kag isugid sa ila ang imo nabatyagan.</p><p>Ako ang medConnect FAQ assistant kag indi makapaghatag sang counseling, pero may bulig nga available subong. Kon handa ka na, matabangan ko ikaw makig-ugnayan sa mga serbisyo sang City Health Office.</p></div>',
        actions: ['callEmergency', 'contactChoNonEmergency', 'leaveMessage'],
      },
    },
  };

  /**
   * Empathetic opening lines prepended before FAQ content.
   * Uses professional language — never "I feel..." or claiming human emotions.
   */
  const EMOTION_ALIASES = {
    gratitude: 'thankful', happiness: 'happy', relief: 'relieved',
    frustration: 'frustrated', worry: 'worried', anxiety: 'anxious',
    confusion: 'confused', fear: 'afraid', sadness: 'sad', distress: 'anxious',
  };

  const EMPATHY = {
    en: {
      happy: { _default: 'I\'m glad you\'re feeling better. If you need help with appointments, medical records, or other medConnect services, I\'m here to assist you.' },
      thankful: { _default: 'You\'re very welcome. I\'m glad I could help. If you have more questions about medConnect, feel free to ask.' },
      relieved: { _default: 'I\'m glad that helped. Let me know if you need anything else about medConnect services.' },
      excited: { _default: 'That\'s great to hear. I can help you get started with medConnect services whenever you\'re ready.' },
      curious: { _default: 'Good question. Let me guide you through the information you need.', reassurance: 'I understand your question. Let me explain how medConnect can assist you.' },
      confused: { _default: 'No problem. I\'ll explain it step by step in a simple way.', clarify: 'No problem. I\'ll explain it step by step in a simple way.' },
      frustrated: {
        _default: 'I\'m sorry you\'re experiencing that. Let\'s solve it together.',
        reset: 'I\'m sorry you\'re experiencing that. Don\'t worry — I\'ll help you recover your account.',
        signin: 'I\'m sorry you\'re experiencing that. Don\'t worry — let me guide you through signing in.',
        appointment: 'I understand that can be frustrating. Let me help you with the appointment process.',
      },
      worried: {
        _default: 'I understand your concern. If your concern is health-related, I can help you schedule an appointment with a healthcare provider.',
        appointment: 'I understand that waiting for healthcare services can feel stressful. Let me help you with the appointment process.',
      },
      anxious: { _default: 'I\'m sorry you\'re feeling this way. If your concern is about your health, I can help you book a consultation.' },
      nervous: { _default: 'I understand. Take your time — I\'ll guide you step by step through the medConnect service you need.' },
      sad: { _default: 'I\'m sorry to hear you\'re feeling this way. If your concern is related to your health, I can help you connect with a healthcare provider.' },
      lonely: { _default: 'Thank you for sharing that. If your concern is affecting your health or well-being, I can help you access available healthcare services.' },
      afraid: { _default: 'I understand your concern. If this is a medical concern, I can help you arrange a consultation.' },
      angry: { _default: 'I\'m sorry for the inconvenience. I\'ll do my best to help resolve your concern.' },
      disappointed: { _default: 'I\'m sorry for the inconvenience. Let me help you find the right solution.' },
      stressed: { _default: 'I\'m sorry you\'re feeling overwhelmed. If your concern is health-related, I can help you schedule an appointment.' },
      tired: { _default: 'I\'m sorry you\'re feeling tired. If your symptoms continue or worsen, consider scheduling a consultation with a healthcare provider.' },
      hopeless: { _default: 'I\'m sorry you\'re going through this. If your concern is health-related, please consider speaking with a healthcare provider through medConnect.' },
      panic: { _default: 'I\'m here to help. Tell me what healthcare service you need, and I\'ll guide you step by step.' },
      crying: { _default: 'I\'m sorry you\'re feeling this way. If your concern is related to your health or well-being, I can help you connect with the appropriate healthcare services.' },
      pain: { _default: 'I\'m sorry you\'re not feeling well. While I can\'t provide a medical diagnosis, I can help you schedule an appointment or consultation with a healthcare provider.' },
      sick: { _default: 'I\'m sorry you\'re not feeling well. While I can\'t provide a medical diagnosis, I can help you schedule an appointment or consultation with a healthcare provider.' },
      overwhelmed: { _default: 'I\'m sorry you\'re feeling overwhelmed. If your concern is health-related, I can help you schedule an appointment or guide you to the right service.' },
    },
    fil: {
      happy: { _default: 'Natutuwa akong bumuti ang pakiramdam mo. Kung kailangan mo ng tulong sa appointments, medical records, o iba pang serbisyo ng medConnect, nandito ako.' },
      thankful: { _default: 'Walang anuman. Natutuwa akong nakatulong. Kung may iba ka pang tanong tungkol sa medConnect, magtanong lang.' },
      relieved: { _default: 'Natutuwa akong nakatulong. Sabihin mo lang kung may kailangan ka pa.' },
      excited: { _default: 'Magandang marinig iyon. Matutulungan kitang magsimula sa mga serbisyo ng medConnect.' },
      curious: { _default: 'Magandang tanong. Gagabayan kita sa impormasyong kailangan mo.', reassurance: 'Naiintindihan ko ang iyong tanong. Ipapaliwanag ko kung paano makakatulong ang medConnect.' },
      confused: { _default: 'Walang problema. Ipapaliwanag ko ito nang simple at hakbang-hakbang.', clarify: 'Walang problema. Ipapaliwanag ko ito nang simple at hakbang-hakbang.' },
      frustrated: {
        _default: 'Paumanhin sa abala. Lutasin natin ito nang sama-sama.',
        reset: 'Paumanhin sa abala. Tutulungan kitang i-recover ang account mo.',
        signin: 'Paumanhin sa abala. Gagabayan kita sa pag-sign in.',
        appointment: 'Naiintindihan ko na nakakadismaya iyon. Tutulungan kita sa proseso ng appointment.',
      },
      worried: {
        _default: 'Naiintindihan ko ang iyong alalahanin. Kung may kaugnayan sa kalusugan, matutulungan kitang mag-schedule ng appointment.',
        appointment: 'Naiintindihan ko na maaaring nakakabahala ang paghihintay. Tutulungan kita sa proseso ng appointment.',
      },
      anxious: { _default: 'Paumanhin na nararamdaman mo iyon. Kung may kaugnayan sa kalusugan, matutulungan kitang mag-book ng konsultasyon.' },
      nervous: { _default: 'Naiintindihan ko. Gagabayan kita nang hakbang-hakbang sa serbisyong kailangan mo.' },
      sad: { _default: 'Paumanhin na nararamdaman mo iyon. Kung may kaugnayan sa kalusugan, matutulungan kitang makipag-ugnayan sa healthcare provider.' },
      lonely: { _default: 'Salamat sa pagbabahagi. Kung naaapektuhan ang iyong kalusugan, matutulungan kitang ma-access ang mga serbisyong pangkalusugan.' },
      afraid: { _default: 'Naiintindihan ko ang iyong alalahanin. Kung medikal ito, matutulungan kitang mag-ayos ng konsultasyon.' },
      angry: { _default: 'Paumanhin sa abala. Gagawin ko ang makakaya para matulungan ka.' },
      disappointed: { _default: 'Paumanhin sa abala. Tutulungan kitang makahanap ng tamang solusyon.' },
      stressed: { _default: 'Paumanhin na overwhelmed ka. Kung may kaugnayan sa kalusugan, matutulungan kitang mag-schedule ng appointment.' },
      tired: { _default: 'Paumanhin sa pagod mo. Kung magpatuloy ang sintomas, isaalang-alang ang konsultasyon sa healthcare provider.' },
      hopeless: { _default: 'Paumanhin sa pinagdadaanan mo. Kung may kaugnayan sa kalusugan, isaalang-alang ang pakikipag-usap sa healthcare provider sa medConnect.' },
      panic: { _default: 'Nandito ako para tumulong. Sabihin mo ang serbisyong kailangan mo at gagabayan kita nang hakbang-hakbang.' },
      crying: { _default: 'Paumanhin na nararamdaman mo iyon. Kung may kaugnayan sa kalusugan o kapakanan, matutulungan kitang makipag-ugnayan sa naaangkop na healthcare services.' },
      pain: { _default: 'Paumanhin na hindi ka maganda ang pakiramdam. Habang hindi ako makakapag-diagnose, matutulungan kitang mag-schedule ng appointment o konsultasyon.' },
      sick: { _default: 'Paumanhin na hindi ka maganda ang pakiramdam. Habang hindi ako makakapag-diagnose, matutulungan kitang mag-schedule ng appointment o konsultasyon.' },
      overwhelmed: { _default: 'Paumanhin na overwhelmed ka. Kung may kaugnayan sa kalusugan, matutulungan kitang mag-schedule ng appointment o gabayan sa tamang serbisyo.' },
    },
    hil: {
      happy: { _default: 'Nalipay ako nga maayo ang imo pamatyag. Kon kinahanglan mo sang bulig sa appointments, medical records, ukon iban nga serbisyo sang medConnect, diri ako.' },
      thankful: { _default: 'Wala sang problema. Nalipay ako nga nakatbulig. Kon may iban ka pa nga pamangkot parte sa medConnect, magpamangkot lang.' },
      relieved: { _default: 'Nalipay ako nga nakatbulig. Silinga lang kon may kinahanglan ka pa.' },
      excited: { _default: 'Maayo nga mabatian sina. Matabangan ko ikaw magsugod sa mga serbisyo sang medConnect.' },
      curious: { _default: 'Maayo nga pamangkot. Tuytuyan ko ikaw sa impormasyon nga imo kinahanglan.', reassurance: 'Naintiendihan ko ang imo pamangkot. Ipaliwanag ko kon paano makabulig ang medConnect.' },
      confused: { _default: 'Wala problema. Ipaliwanag ko ini nga simple kag step-by-step.', clarify: 'Wala problema. Ipaliwanag ko ini nga simple kag step-by-step.' },
      frustrated: {
        _default: 'Pasensya sa sini. Lutason naton ini nga mag-ubanay.',
        reset: 'Pasensya sa sini. Tabangan ko ikaw nga ma-recover ang imo account.',
        signin: 'Pasensya sa sini. Tuytuyan ko ikaw sa pag-sign in.',
        appointment: 'Naintiendihan ko nga makapadismaya sina. Tatabangan ko ikaw sa proseso sang appointment.',
      },
      worried: {
        _default: 'Naintiendihan ko ang imo kabalaka. Kon may kaangtan sa kahimsog, matabangan ko ikaw mag-schedule sang appointment.',
        appointment: 'Naintiendihan ko nga makastress ang paghulat. Tatabangan ko ikaw sa proseso sang appointment.',
      },
      anxious: { _default: 'Pasensya nga nabatyagan mo sina. Kon parte sa kahimsog, matabangan ko ikaw mag-book sang konsultasyon.' },
      nervous: { _default: 'Naintiendihan ko. Tuytuyan ko ikaw step-by-step sa serbisyo nga imo kinahanglan.' },
      sad: { _default: 'Pasensya nga nabatyagan mo sina. Kon may kaangtan sa kahimsog, matabangan ko ikaw makig-ugnayan sa healthcare provider.' },
      lonely: { _default: 'Salamat sa pagpaambit. Kon naaapektuhan ang imo kahimsog, matabangan ko ikaw ma-access ang mga healthcare services.' },
      afraid: { _default: 'Naintiendihan ko ang imo kabalaka. Kon medikal ini, matabangan ko ikaw mag-ayos sang konsultasyon.' },
      angry: { _default: 'Pasensya sa abala. Himuon ko ang akon makaya para matabangan ka.' },
      disappointed: { _default: 'Pasensya sa abala. Tatabangan ko ikaw makita ang husto nga solusyon.' },
      stressed: { _default: 'Pasensya nga overwhelmed ka. Kon may kaangtan sa kahimsog, matabangan ko ikaw mag-schedule sang appointment.' },
      tired: { _default: 'Pasensya sa imo kapoy. Kon magpadayon ang sintomas, hunahunaa ang konsultasyon sa healthcare provider.' },
      hopeless: { _default: 'Pasensya sa imo gina-agi. Kon may kaangtan sa kahimsog, hunahunaa ang paghambal sa healthcare provider paagi sa medConnect.' },
      panic: { _default: 'Diri ako para buligan. Silinga kon ano nga healthcare service ang imo kinahanglan kag tuytuyan ko ikaw step-by-step.' },
      crying: { _default: 'Pasensya nga nabatyagan mo sina. Kon may kaangtan sa kahimsog ukon kahimtangan, matabangan ko ikaw makig-ugnayan sa angay nga healthcare services.' },
      pain: { _default: 'Pasensya nga indi maayo ang imo pamatyag. Bisan indi ako makapag-diagnose, matabangan ko ikaw mag-schedule sang appointment ukon konsultasyon.' },
      sick: { _default: 'Pasensya nga indi maayo ang imo pamatyag. Bisan indi ako makapag-diagnose, matabangan ko ikaw mag-schedule sang appointment ukon konsultasyon.' },
      overwhelmed: { _default: 'Pasensya nga overwhelmed ka. Kon may kaangtan sa kahimsog, matabangan ko ikaw mag-schedule sang appointment ukon tuytuyan sa husto nga serbisyo.' },
    },
  };

  const ACTION_MAP = {
    signIn: { action: 'flow', target: 'signin' },
    createAccount: { action: 'flow', target: 'register' },
    bookAppointment: { action: 'flow', target: 'appointment' },
    resetPassword: { action: 'flow', target: 'reset' },
    videoConsult: { action: 'flow', target: 'video' },
    leaveMessage: { action: 'flow', target: 'contact' },
    openSignIn: { action: 'openSignIn', primary: true },
    forgotPassword: { action: 'flow', target: 'reset' },
    contactSupport: { action: 'flow', target: 'contact' },
    startRegistration: { action: 'openRegister', primary: true },
    viewRequirements: { action: 'openRequirements' },
    resetPasswordNow: { action: 'openForgot', primary: true },
    officeHours: { action: 'flow', target: 'hours' },
    ourServices: { action: 'flow', target: 'services' },
    contactInfo: { action: 'flow', target: 'contact', primary: true },
    goContact: { action: 'scrollContact', primary: true },
    contactCho: { action: 'flow', target: 'contact', primary: true },
    contactChoNonEmergency: { action: 'flow', target: 'contact' },
    signInHelp: { action: 'flow', target: 'signin' },
    callEmergency: { action: 'callEmergency', primary: true },
  };

  const MODERATION = {
    en: {
      badge: 'Respectful Communication Required',
      body: '<p>Please use respectful language.</p><p>I\'m the official <strong>medConnect Assistant</strong> and can only assist with City Health Office and medConnect services such as:</p><ul><li>Account Registration</li><li>Login Assistance</li><li>Password Reset</li><li>Appointment Booking</li><li>Video Consultation</li><li>Medical Records</li><li>Office Information</li></ul><p>Please ask a healthcare service-related question.</p>',
      restrictedBadge: 'Chat Temporarily Restricted',
      restrictedBody: '<p>Your recent messages violate our community guidelines.</p><p>Please wait <strong data-fcb-cooldown>{n}</strong> seconds before sending another message.</p>',
      spam: '<p>I couldn\'t understand that message. It may be spam or random text.</p><p>Please type a clear question about medConnect or City Health Office services.</p>',
      actions: ['signIn', 'createAccount', 'bookAppointment', 'resetPassword', 'leaveMessage'],
    },
    fil: {
      badge: 'Kinakailangan ang Magalang na Pakikipag-usap',
      body: '<p>Mangyaring gumamit ng magalang na pananalita.</p><p>Ako ang opisyal na <strong>medConnect Assistant</strong> at makakatulong lamang sa mga serbisyo ng City Health Office at medConnect tulad ng:</p><ul><li>Pagrehistro ng Account</li><li>Tulong sa Login</li><li>Password Reset</li><li>Pag-book ng Appointment</li><li>Video Consultation</li><li>Medical Records</li><li>Impormasyon ng Opisina</li></ul><p>Mangyaring magtanong tungkol sa mga serbisyo ng healthcare.</p>',
      restrictedBadge: 'Pansamantalang Restricted ang Chat',
      restrictedBody: '<p>Ang iyong mga kamakailang mensahe ay lumabag sa aming community guidelines.</p><p>Mangyaring maghintay ng <strong data-fcb-cooldown>{n}</strong> segundo bago magpadala ng isa pang mensahe.</p>',
      spam: '<p>Hindi ko naintindihan ang mensaheng iyon. Maaaring spam o random na text ito.</p><p>Mangyaring mag-type ng malinaw na tanong tungkol sa medConnect o City Health Office.</p>',
      actions: ['signIn', 'createAccount', 'bookAppointment', 'resetPassword', 'leaveMessage'],
    },
    hil: {
      badge: 'Kinahanglan ang Respeto sa Pagpakig-istorya',
      body: '<p>Palihog gamita ang respetado nga pulong.</p><p>Ako ang opisyal nga <strong>medConnect Assistant</strong> kag makabulig lang sa mga serbisyo sang City Health Office kag medConnect pareho sang:</p><ul><li>Pagrehistro sang Account</li><li>Bulig sa Login</li><li>Password Reset</li><li>Pag-book sang Appointment</li><li>Video Konsultasyon</li><li>Medical Records</li><li>Impormasyon sang Opisina</li></ul><p>Palihog magpamangkot parte sa healthcare services.</p>',
      restrictedBadge: 'Pansamantalang Restricted ang Chat',
      restrictedBody: '<p>Ang imo mga bag-o nga mensahe naglapas sa amon community guidelines.</p><p>Palihog hulat sang <strong data-fcb-cooldown>{n}</strong> segundo antes magpadala sang isa pa ka mensahe.</p>',
      spam: '<p>Indi ko maintindihan ang mensahe. Posible nga spam ukon random nga text.</p><p>Palihog mag-type sang malinaw nga pamangkot parte sa medConnect ukon City Health Office.</p>',
      actions: ['signIn', 'createAccount', 'bookAppointment', 'resetPassword', 'leaveMessage'],
    },
  };

  function normLang(lang) {
    if (lang === LANG.FIL || lang === LANG.HIL) return lang;
    return LANG.EN;
  }

  function t(lang, key, vars) {
    const L = normLang(lang);
    let str = (UI_STRINGS[L] && UI_STRINGS[L][key]) || UI_STRINGS.en[key] || key;
    if (vars) {
      Object.keys(vars).forEach((k) => {
        str = str.replace(new RegExp(`\\{${k}\\}`, 'g'), String(vars[k]));
      });
    }
    return str;
  }

  function label(lang, key) {
    const L = normLang(lang);
    return (ACTION_LABELS[L] && ACTION_LABELS[L][key]) || ACTION_LABELS.en[key] || key;
  }

  function buildActions(lang, keys) {
    return (keys || []).map((key) => {
      const def = ACTION_MAP[key];
      if (!def) return null;
      const act = { key, label: label(lang, key), action: def.action };
      if (def.target) act.target = def.target;
      if (def.primary) act.primary = true;
      return act;
    }).filter(Boolean);
  }

  function getInfoCardCopy(lang, variant) {
    const L = normLang(lang);
    const pack = INFO_CARD[L] || INFO_CARD.en;
    return pack[variant] || pack.not_understood;
  }

  function getInfoCardTopics(lang) {
    const L = normLang(lang);
    return INFO_TOPICS[L] || INFO_TOPICS.en;
  }

  function getFollowActionMeta(lang, act) {
    const L = normLang(lang);
    const pack = FOLLOW_ACTION_META[L] || FOLLOW_ACTION_META.en;
    if (act.key && pack[act.key]) return pack[act.key];
    if (act.target && pack[act.target]) return pack[act.target];
    if (act.action === 'openSignIn') return pack.openSignIn || { icon: '🔐', desc: '' };
    if (act.action === 'openRegister') return pack.register || { icon: '📝', desc: '' };
    if (act.action === 'scrollContact') return pack.contact || { icon: '💬', desc: '' };
    return { icon: '›', desc: '' };
  }

  function getQuickActions(lang) {
    const L = normLang(lang);
    return QUICK_ACTIONS[L] || QUICK_ACTIONS.en;
  }

  function getFlowLabel(lang, flowKey) {
    const L = normLang(lang);
    return (FLOW_LABELS[L] && FLOW_LABELS[L][flowKey]) || FLOW_LABELS.en[flowKey] || flowKey;
  }

  function getFlow(flowKey, lang) {
    const L = normLang(lang);
    const pack = FLOWS[flowKey];
    if (!pack) return getFlow('unknown', L);
    const flow = pack[L] || pack.en;
    const ui = UI_STRINGS[L] || UI_STRINGS.en;
    return {
      html: flow.html,
      followUp: flow.followUpKey ? ui[flow.followUpKey] : (flow.followUp !== undefined ? flow.followUp : null),
      actions: buildActions(L, flow.actions),
    };
  }

  function getModerationContent(lang) {
    const L = normLang(lang);
    const m = MODERATION[L] || MODERATION.en;
    return {
      html: `<div class="fcb-mod-badge" role="alert"><span aria-hidden="true">⚠</span> ${m.badge}</div>${m.body}`,
      actions: buildActions(L, m.actions),
    };
  }

  function getRestrictedContent(lang, seconds) {
    const L = normLang(lang);
    const m = MODERATION[L] || MODERATION.en;
    return {
      html: `<div class="fcb-mod-badge fcb-mod-badge--restricted" role="alert"><span aria-hidden="true">⚠</span> ${m.restrictedBadge}</div>${m.restrictedBody.replace('{n}', String(seconds))}`,
    };
  }

  function getSpamContent(lang) {
    const L = normLang(lang);
    const m = MODERATION[L] || MODERATION.en;
    return {
      html: m.spam,
      followUp: t(L, 'howCanHelp'),
      actions: buildActions(L, ['signIn', 'createAccount', 'contactCho']),
    };
  }

  function getWelcomeStrings(lang) {
    const L = normLang(lang);
    const ui = UI_STRINGS[L] || UI_STRINGS.en;
    return {
      title: ui.welcomeTitle,
      lead: ui.welcomeLead,
      topicsLabel: ui.welcomeTopics,
      topicList: ui.welcomeTopicList,
      cta: ui.welcomeCta,
    };
  }

  /**
   * @param {string} lang
   * @param {string} emotion
   * @param {string|null} flowKey
   * @returns {string}
   */
  function getEmotionLabel(lang, emotion) {
    if (!emotion) return '';
    const L = normLang(lang);
    const key = EMOTION_ALIASES[emotion] || emotion;
    return (EMOTION_LABELS[L] && EMOTION_LABELS[L][key]) || (EMOTION_LABELS.en && EMOTION_LABELS.en[key]) || key;
  }

  function getEmpathyPrefix(lang, emotion, flowKey) {
    if (!emotion) return '';
    const key = EMOTION_ALIASES[emotion] || emotion;
    const L = normLang(lang);
    const pack = (EMPATHY[L] && EMPATHY[L][key]) || (EMPATHY.en && EMPATHY.en[key]);
    if (!pack) return '';
    const line = pack[flowKey] || pack._default || '';
    if (!line) return '';
    const Emotions = global.McFaqEmotions;
    const icon = Emotions ? Emotions.getEmotionIcon(key) : '';
    const tone = Emotions ? Emotions.getEmotionTone(key) : 'neutral';
    const iconHtml = icon ? `<span class="fcb-empathy__icon" aria-hidden="true">${icon}</span>` : '';
    return `<div class="fcb-empathy fcb-empathy--${tone}" role="note">${iconHtml}<p class="fcb-empathy__text">${line}</p></div>`;
  }

  global.McFaqI18n = {
    LANG,
    t,
    label,
    getQuickActions,
    getFlowLabel,
    getFlow,
    getModerationContent,
    getRestrictedContent,
    getSpamContent,
    getWelcomeStrings,
    getEmpathyPrefix,
    getEmotionLabel,
    getInfoCardCopy,
    getInfoCardTopics,
    getFollowActionMeta,
    normLang,
  };
})(window);
