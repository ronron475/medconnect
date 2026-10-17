/**
 * Patient account interface language (English / Filipino / Hiligaynon).
 * Presentation only. Does not translate stored clinical text.
 */
(function (global) {
  'use strict';

  var STRINGS = {
    en: {
      nav_dashboard: 'Dashboard',
      nav_identity: 'My Identity',
      nav_health_summary: 'Health Summary',
      nav_my_sessions: 'My Sessions',
      nav_book: 'Book Consultation',
      nav_my_health: 'My Health',
      nav_messages: 'Messages',
      nav_settings: 'Settings',
      nav_logout: 'Logout',
      page_consult_detail: 'Consultation Details',
      role_patient: 'Role: PATIENT',
      badge_verified: 'VERIFIED',
      badge_pending: 'PENDING',
      theme: 'Theme',
      theme_system: 'System Default',
      theme_light: 'Light Mode',
      theme_dark: 'Dark Mode',
      account: 'Account',
      security_prefs: 'Security & preferences',
      security_prefs_text: 'Update your password, privacy choices, alerts, and signed-in devices in one place.',
      secure_account: 'Secure account',
      active_session: '{n} active session',
      active_sessions: '{n} active sessions',
      search_settings: 'Search',
      search_empty: 'No matching settings.',
      group_account: 'Account',
      group_connections: 'Connections',
      group_preferences: 'Preferences',
      group_security: 'Security',
      nav_profile: 'Profile & preferences',
      nav_notifications: 'Notifications',
      nav_devices: 'Devices & sessions',
      nav_data_privacy: 'Data privacy',
      profile_title: 'Profile & preferences',
      profile_sub: 'Your account email and recent sign-in details.',
      password_lead: 'Keep your account secure by using a strong, unique password.',
      save_changes: 'Save changes',
      language: 'Language',
      language_help: 'Your choice is saved to this account and applied immediately.',
      save_language: 'Save Language',
      language_saved: 'Language saved.',
      language_save_fail: 'Could not save language.',
      tab_security: 'Account Security',
      tab_security_desc: 'Password & login',
      tab_privacy: 'Privacy',
      tab_privacy_desc: 'Data sharing',
      tab_notifications: 'Notifications',
      tab_notifications_desc: 'Alerts & email',
      tab_sessions: 'Sessions',
      tab_sessions_desc: 'Devices & access',
      last_login: 'Last login',
      password_changed: 'Password changed',
      account_email: 'Account email',
      not_recorded: 'Not recorded',
      not_changed: 'Not changed since registration',
      change_password: 'Change password',
      password_hint: 'Use at least 12 characters with uppercase, lowercase, a number, and a special character.',
      current_password: 'Current password',
      new_password: 'New password',
      confirm_password: 'Confirm new password',
      ph_current: 'Enter current password',
      ph_new: 'Enter at least 12 characters',
      ph_confirm: 'Re-enter new password',
      password_strength: 'Password strength',
      pw_weak: 'Weak',
      pw_fair: 'Fair',
      pw_good: 'Good',
      pw_strong: 'Strong',
      pw_vstrong: 'Very Strong',
      req_len: 'At least 12 characters',
      req_upper: 'One uppercase letter',
      req_lower: 'One lowercase letter',
      req_digit: 'One number',
      req_special: 'One special character',
      update_password: 'Update Password',
      privacy_title: 'Privacy Preferences',
      privacy_sub: 'Control how your medical data is shared within the medConnect care network.',
      privacy_records: 'Provider access to medical records',
      privacy_records_sub: 'Allow authorized healthcare providers to view your permanent profile and care records during consultations.',
      privacy_bhw_rx: 'Barangay BHW prescription access',
      privacy_bhw_rx_sub: 'Allow authorized Barangay Health Workers assigned to your barangay to view your prescription details for barangay healthcare assistance. You can turn this off at any time.',
      privacy_emergency: 'Emergency access consent',
      privacy_emergency_sub: 'Allow emergency clinical staff to access critical health information when medically necessary.',
      privacy_ack: 'Data privacy acknowledgment',
      privacy_ack_sub: 'I understand how medConnect collects, stores, and protects my personal health information.',
      save_privacy: 'Save Privacy Preferences',
      notif_title: 'Notification Settings',
      notif_sub: 'Choose which alerts you receive in-app and by email.',
      delivery: 'Delivery channels',
      in_app: 'In-app notifications',
      in_app_sub: 'Bell alerts inside medConnect.',
      email_notif: 'Email notifications',
      email_notif_sub: 'Sent to your registered email when available.',
      alert_types: 'Alert types',
      appt_rem: 'Appointment reminders',
      appt_rem_sub: 'Before scheduled visits',
      consult_upd: 'Consultation updates',
      consult_upd_sub: 'Join links & status changes',
      follow_rem: 'Follow-up reminders',
      follow_rem_sub: 'Provider action items',
      rx_notif: 'Prescription notifications',
      rx_notif_sub: 'New or updated prescriptions',
      sys_ann: 'System announcements',
      sys_ann_sub: 'Platform news & maintenance',
      save_notif: 'Save Notification Settings',
      active_sessions_title: 'Active Sessions',
      active_sessions_sub: 'Devices signed in to your account in the last 7 days.',
      logout_all: 'Logout All Devices',
      no_sessions: 'No active sessions recorded in the last 7 days.',
      this_device: 'This device',
      end_session: 'End session',
      known_devices: 'Known Devices',
      known_devices_sub: 'Browsers and devices previously used with this account.',
      passwords_match: 'Passwords match',
      passwords_mismatch: 'Passwords do not match'
    },
    fil: {
      nav_dashboard: 'Pangunahing Pahina',
      nav_identity: 'Aking Pagkakakilanlan',
      nav_health_summary: 'Buod ng Kalusugan',
      nav_my_sessions: 'Aking mga Sesyon',
      nav_book: 'Mag-book ng Konsultasyon',
      nav_my_health: 'Aking Kalusugan',
      nav_messages: 'Mga Mensahe',
      nav_settings: 'Mga Setting',
      nav_logout: 'Mag-logout',
      page_consult_detail: 'Detalye ng Konsultasyon',
      role_patient: 'Tungkulin: PASYENTE',
      badge_verified: 'BERIPIKADO',
      badge_pending: 'NAKABINBIN',
      theme: 'Tema',
      theme_system: 'Default ng sistema',
      theme_light: 'Maliwanag',
      theme_dark: 'Madilim',
      account: 'Account',
      security_prefs: 'Seguridad at mga kagustuhan',
      security_prefs_text: 'I-update ang password, privacy, mga alerto, at naka-sign in na device sa isang lugar.',
      secure_account: 'Ligtas na account',
      active_session: '{n} aktibong session',
      active_sessions: '{n} aktibong session',
      search_settings: 'Maghanap',
      search_empty: 'Walang katugmang setting.',
      group_account: 'Account',
      group_connections: 'Mga koneksyon',
      group_preferences: 'Mga kagustuhan',
      group_security: 'Seguridad',
      nav_profile: 'Profile at mga kagustuhan',
      nav_notifications: 'Mga abiso',
      nav_devices: 'Mga device at session',
      nav_data_privacy: 'Data privacy',
      profile_title: 'Profile at mga kagustuhan',
      profile_sub: 'Email ng account at mga detalye ng kamakailang pag-sign in.',
      password_lead: 'Panatilihing ligtas ang account gamit ang malakas at natatanging password.',
      save_changes: 'I-save ang mga pagbabago',
      language: 'Wika',
      language_help: 'Nase-save ang napili sa account at agad itong inilalapat.',
      save_language: 'I-save ang Wika',
      language_saved: 'Na-save ang wika.',
      language_save_fail: 'Hindi ma-save ang wika.',
      tab_security: 'Seguridad ng Account',
      tab_security_desc: 'Password at pag-login',
      tab_privacy: 'Privacy',
      tab_privacy_desc: 'Pagbabahagi ng datos',
      tab_notifications: 'Mga Abiso',
      tab_notifications_desc: 'Mga alerto at email',
      tab_sessions: 'Mga Session',
      tab_sessions_desc: 'Mga device at access',
      last_login: 'Huling pag-login',
      password_changed: 'Pinalitan ang password',
      account_email: 'Email ng account',
      not_recorded: 'Walang tala',
      not_changed: 'Hindi pa napapalitan mula nang magparehistro',
      change_password: 'Palitan ang Password',
      password_hint: 'Gumamit ng hindi bababa sa 12 character na may malaking titik, maliit na titik, numero, at espesyal na character.',
      current_password: 'Kasalukuyang password',
      new_password: 'Bagong password',
      confirm_password: 'Kumpirmahin ang bagong password',
      ph_current: 'Ilagay ang kasalukuyang password',
      ph_new: 'Maglagay ng hindi bababa sa 12 character',
      ph_confirm: 'Ilagay muli ang bagong password',
      password_strength: 'Lakas ng password',
      pw_weak: 'Mahina',
      pw_fair: 'Katamtaman',
      pw_good: 'Ayos',
      pw_strong: 'Malakas',
      pw_vstrong: 'Napakalakas',
      req_len: 'Hindi bababa sa 12 character',
      req_upper: 'Isang malaking titik',
      req_lower: 'Isang maliit na titik',
      req_digit: 'Isang numero',
      req_special: 'Isang espesyal na character',
      update_password: 'I-update ang Password',
      privacy_title: 'Mga Kagustuhan sa Privacy',
      privacy_sub: 'Kontrolin kung paano ibinabahagi ang iyong medical data sa medConnect.',
      privacy_records: 'Access ng provider sa medical records',
      privacy_records_sub: 'Payagan ang awtorisadong provider na tingnan ang profile at care records habang may konsultasyon.',
      privacy_bhw_rx: 'Access ng BHW sa reseta',
      privacy_bhw_rx_sub: 'Payagan ang awtorisadong BHW na nakatalaga sa iyong barangay na makita ang detalye ng iyong reseta para sa tulong sa kalusugan sa barangay. Maaari mo itong patayin anumang oras.',
      privacy_emergency: 'Pahintulot sa emergency access',
      privacy_emergency_sub: 'Payagan ang emergency staff na ma-access ang mahalagang impormasyon sa kalusugan kung kinakailangan.',
      privacy_ack: 'Pagkilala sa data privacy',
      privacy_ack_sub: 'Nauunawaan ko kung paano kinokolekta, iniimbak, at pinoprotektahan ng medConnect ang aking personal na impormasyon sa kalusugan.',
      save_privacy: 'I-save ang Privacy',
      notif_title: 'Mga Setting ng Abiso',
      notif_sub: 'Piliin kung aling mga alerto ang matatanggap mo sa app at sa email.',
      delivery: 'Paraan ng pagpapadala',
      in_app: 'Mga abiso sa app',
      in_app_sub: 'Mga alerto sa bell sa loob ng medConnect.',
      email_notif: 'Mga abiso sa email',
      email_notif_sub: 'Ipinapadala sa nakarehistrong email kapag available.',
      alert_types: 'Mga uri ng alerto',
      appt_rem: 'Mga paalala sa appointment',
      appt_rem_sub: 'Bago ang nakatakdang bisita',
      consult_upd: 'Mga update sa konsultasyon',
      consult_upd_sub: 'Link sa pagsali at pagbabago ng status',
      follow_rem: 'Mga paalala sa follow-up',
      follow_rem_sub: 'Mga dapat gawin mula sa provider',
      rx_notif: 'Mga abiso sa reseta',
      rx_notif_sub: 'Bago o na-update na reseta',
      sys_ann: 'Mga anunsyo ng sistema',
      sys_ann_sub: 'Balita at maintenance ng platform',
      save_notif: 'I-save ang mga Abiso',
      active_sessions_title: 'Mga Aktibong Session',
      active_sessions_sub: 'Mga device na naka-sign in sa account sa nakaraang 7 araw.',
      logout_all: 'I-logout ang Lahat ng Device',
      no_sessions: 'Walang naitalang aktibong session sa nakaraang 7 araw.',
      this_device: 'Device na ito',
      end_session: 'Tapusin ang session',
      known_devices: 'Mga Kilalang Device',
      known_devices_sub: 'Mga browser at device na nagamit na sa account na ito.',
      passwords_match: 'Magkatugma ang mga password',
      passwords_mismatch: 'Hindi magkatugma ang mga password'
    },
    hil: {
      nav_dashboard: 'Pangunahon nga Pahina',
      nav_identity: 'Akon nga Identidad',
      nav_health_summary: 'Kabilugan sang Panglawas',
      nav_my_sessions: 'Akon nga mga Sesyon',
      nav_book: 'Mag-book sang Konsultasyon',
      nav_my_health: 'Akon nga Panglawas',
      nav_messages: 'Mga Mensahe',
      nav_settings: 'Mga Setting',
      nav_logout: 'Mag-logout',
      page_consult_detail: 'Detalye sang Konsultasyon',
      role_patient: 'Katungdanan: PASYENTE',
      badge_verified: 'NAPAMATUD-AN',
      badge_pending: 'NAGAHULAT',
      theme: 'Tema',
      theme_system: 'Default sang sistema',
      theme_light: 'Masanag',
      theme_dark: 'Madulom',
      account: 'Account',
      security_prefs: 'Seguridad kag mga gusto',
      security_prefs_text: 'I-update ang password, privacy, mga alerto, kag naka-sign in nga device sa isa ka lugar.',
      secure_account: 'Segurado nga account',
      active_session: '{n} aktibo nga session',
      active_sessions: '{n} aktibo nga session',
      search_settings: 'Pangita',
      search_empty: 'Wala sang katugma nga setting.',
      group_account: 'Account',
      group_connections: 'Mga koneksyon',
      group_preferences: 'Mga gusto',
      group_security: 'Seguridad',
      nav_profile: 'Profile kag mga gusto',
      nav_notifications: 'Mga abiso',
      nav_devices: 'Mga device kag session',
      nav_data_privacy: 'Data privacy',
      profile_title: 'Profile kag mga gusto',
      profile_sub: 'Email sang account kag mga detalye sang ulihi nga pag-sign in.',
      password_lead: 'Padayuna nga segurado ang account gamit ang mabaskog kag lahi nga password.',
      save_changes: 'I-save ang mga pagbag-o',
      language: 'Lengwahe',
      language_help: 'Gina-save ang napili sa account kag dayon ini ginabutang.',
      save_language: 'I-save ang Lengwahe',
      language_saved: 'Na-save ang lengwahe.',
      language_save_fail: 'Indi ma-save ang lengwahe.',
      tab_security: 'Seguridad sang Account',
      tab_security_desc: 'Password kag pag-login',
      tab_privacy: 'Privacy',
      tab_privacy_desc: 'Pag-ambit sang datos',
      tab_notifications: 'Mga Abiso',
      tab_notifications_desc: 'Mga alerto kag email',
      tab_sessions: 'Mga Session',
      tab_sessions_desc: 'Mga device kag access',
      last_login: 'Ulihi nga pag-login',
      password_changed: 'Gin-islan ang password',
      account_email: 'Email sang account',
      not_recorded: 'Wala rekord',
      not_changed: 'Wala pa na-islan halin sang pagparehistro',
      change_password: 'Islan ang Password',
      password_hint: 'Gamita ang indi magnubo sa 12 ka character nga may daku nga letra, gamay nga letra, numero, kag espesyal nga character.',
      current_password: 'Karon nga password',
      new_password: 'Bag-o nga password',
      confirm_password: 'Kumpirmaha ang bag-o nga password',
      ph_current: 'Ibutang ang karon nga password',
      ph_new: 'Ibutang ang indi magnubo sa 12 ka character',
      ph_confirm: 'Ibutang liwat ang bag-o nga password',
      password_strength: 'Kabaskog sang password',
      pw_weak: 'Maluya',
      pw_fair: 'Tunga-tunga',
      pw_good: 'Maayo',
      pw_strong: 'Mabaskog',
      pw_vstrong: 'Tama kabaskog',
      req_len: 'Indi magnubo sa 12 ka character',
      req_upper: 'Isa ka daku nga letra',
      req_lower: 'Isa ka gamay nga letra',
      req_digit: 'Isa ka numero',
      req_special: 'Isa ka espesyal nga character',
      update_password: 'I-update ang Password',
      privacy_title: 'Mga Gusto sa Privacy',
      privacy_sub: 'Kontrola kon paano ginapaambit ang imo medical data sa medConnect.',
      privacy_records: 'Access sang provider sa medical records',
      privacy_records_sub: 'Pasugti ang awtorisado nga provider nga tan-awon ang profile kag care records sa konsultasyon.',
      privacy_bhw_rx: 'Access sang BHW sa reseta',
      privacy_bhw_rx_sub: 'Pasugti ang awtorisado nga BHW nga nakatoka sa imo barangay nga makita ang detalye sang imo reseta para sa bulig sa panglawas sa barangay. Mahimo mo ini i-off bisan san-o.',
      privacy_emergency: 'Pahintulot sa emergency access',
      privacy_emergency_sub: 'Pasugti ang emergency staff nga ma-access ang importante nga impormasyon kon kinahanglan.',
      privacy_ack: 'Pagkilala sa data privacy',
      privacy_ack_sub: 'Naintiendihan ko kon paano ginatipon, ginatipigan, kag ginaprotektahan sang medConnect ang akon personal nga impormasyon sa panglawas.',
      save_privacy: 'I-save ang Privacy',
      notif_title: 'Mga Setting sang Abiso',
      notif_sub: 'Pilia kon ano nga mga alerto ang imo mabaton sa app kag sa email.',
      delivery: 'Paagi sang pagpadala',
      in_app: 'Mga abiso sa app',
      in_app_sub: 'Mga alerto sa bell sa sulod sang medConnect.',
      email_notif: 'Mga abiso sa email',
      email_notif_sub: 'Ginapadala sa nakarehistro nga email kon available.',
      alert_types: 'Mga klase sang alerto',
      appt_rem: 'Mga pahinumdom sa appointment',
      appt_rem_sub: 'Antes sang natakda nga bisita',
      consult_upd: 'Mga update sa konsultasyon',
      consult_upd_sub: 'Link sa pagsulod kag pagbag-o sang status',
      follow_rem: 'Mga pahinumdom sa follow-up',
      follow_rem_sub: 'Mga dapat himuon halin sa provider',
      rx_notif: 'Mga abiso sa reseta',
      rx_notif_sub: 'Bag-o ukon na-update nga reseta',
      sys_ann: 'Mga anunsyo sang sistema',
      sys_ann_sub: 'Balita kag maintenance sang platform',
      save_notif: 'I-save ang mga Abiso',
      active_sessions_title: 'Mga Aktibo nga Session',
      active_sessions_sub: 'Mga device nga naka-sign in sa account sa nagligad nga 7 ka adlaw.',
      logout_all: 'I-logout ang Tanan nga Device',
      no_sessions: 'Wala narekord nga aktibo nga session sa nagligad nga 7 ka adlaw.',
      this_device: 'Ini nga device',
      end_session: 'Tapusa ang session',
      known_devices: 'Mga Kilala nga Device',
      known_devices_sub: 'Mga browser kag device nga nagamit na sa sini nga account.',
      passwords_match: 'Magkatugma ang mga password',
      passwords_mismatch: 'Indi magkatugma ang mga password'
    }
  };

  function normalize(lang) {
    var value = String(lang || '').trim().toLowerCase();
    if (value === 'fil' || value === 'tl' || value === 'tagalog' || value === 'filipino') return 'fil';
    if (value === 'hil' || value === 'hiligaynon' || value === 'ilonggo') return 'hil';
    return 'en';
  }

  function t(key, vars, lang) {
    var code = normalize(lang || current());
    var table = STRINGS[code] || STRINGS.en;
    var text = table[key] || STRINGS.en[key] || key;
    if (vars) {
      Object.keys(vars).forEach(function (name) {
        text = text.replace(new RegExp('\\{' + name + '\\}', 'g'), String(vars[name] == null ? '' : vars[name]));
      });
    }
    return text;
  }

  function current() {
    return normalize(document.documentElement.lang || 'en');
  }

  function apply(lang) {
    var code = normalize(lang);
    document.documentElement.lang = code;
    document.querySelectorAll('[data-ui-i18n]').forEach(function (el) {
      var key = el.getAttribute('data-ui-i18n');
      if (!key) return;
      var vars = null;
      if (el.hasAttribute('data-ui-count')) {
        var count = Number(el.getAttribute('data-ui-count'));
        key = count === 1 ? 'active_session' : 'active_sessions';
        vars = { n: String(count) };
      }
      var text = t(key, vars, code);
      var attr = el.getAttribute('data-ui-i18n-attr');
      if (attr) {
        el.setAttribute(attr, text);
        return;
      }
      el.textContent = text;
    });
    document.dispatchEvent(new CustomEvent('mc-ui-language', { detail: { lang: code } }));
  }

  function save(lang) {
    var code = normalize(lang);
    var root = document.getElementById('patientSettingsRoot');
    var csrf = (root && root.dataset.csrf) || (document.body && document.body.dataset.csrf) || '';
    var base = global.APP_BASE || (root && root.dataset.assetBase) || '';
    if (!csrf) return Promise.resolve();
    var fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('language', code);
    return fetch(base + '/app/api/language/save.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    }).then(function (res) { return res.json(); }).then(function (data) {
      var alertEl = document.getElementById('ptsLanguageAlert');
      if (!alertEl) return data;
      alertEl.hidden = false;
      alertEl.textContent = (data && data.success) ? t('language_saved', null, code) : ((data && data.message) || t('language_save_fail', null, code));
      alertEl.className = 'pts-alert pts-alert--' + ((data && data.success) ? 'success' : 'error');
      return data;
    }).catch(function () {
      var alertEl = document.getElementById('ptsLanguageAlert');
      if (alertEl) {
        alertEl.hidden = false;
        alertEl.textContent = t('language_save_fail', null, code);
        alertEl.className = 'pts-alert pts-alert--error';
      }
    });
  }

  function bindLiveSelect() {
    var select = document.getElementById('ptsLanguage');
    if (!select || select._mcUiBound) return;
    select._mcUiBound = true;
    select.addEventListener('change', function () {
      apply(select.value);
      save(select.value);
    });
  }

  function boot() {
    apply(current());
    bindLiveSelect();
  }

  global.McPatientUiI18n = { t: t, apply: apply, save: save, normalize: normalize };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window);
