<?php
/**
 * medConnect services, accounts, and navigation knowledge pack.
 */
final class FaqChatbotKbServices
{
    /** @return list<array<string, mixed>> */
    public static function scenarios(): array
    {
        return [
            [
                'key' => 'password_reset',
                'category' => 'accounts',
                'flow_key' => 'reset',
                'weight' => 1.15,
                'patterns' => [
                    '/\b(forgot\s+(my\s+)?password|reset\s+(my\s+)?password|nakalimtan.*(password)|i-?reset\s+.*password)\b/ui',
                ],
                'keywords' => ['forgot password', 'reset password', 'nakalimtan', 'password'],
            ],
            [
                'key' => 'otp_verification',
                'category' => 'accounts',
                'flow_key' => 'signin',
                'weight' => 1.15,
                'patterns' => [
                    '/\b(otp|one\s*time\s*pin|verification\s*code|code\s+not\s+received|wala\s+ako\s+nabaton\s+nga\s+otp)\b/ui',
                ],
                'keywords' => ['otp', 'verification code', 'one time pin'],
            ],
            [
                'key' => 'email_verification',
                'category' => 'accounts',
                'flow_key' => 'register',
                'weight' => 1.12,
                'patterns' => [
                    '/\b(verify\s+(my\s+)?email|email\s+verification|confirm\s+email|indi\s+verified|not\s+verified)\b/ui',
                ],
                'keywords' => ['verify email', 'email verification', 'confirm email'],
            ],
            [
                'key' => 'account_recovery',
                'category' => 'accounts',
                'flow_key' => 'reset',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(account\s+recovery|recover\s+(my\s+)?account|locked\s+out|cannot\s+access\s+account)\b/ui',
                ],
                'keywords' => ['account recovery', 'recover account', 'locked out'],
            ],
            [
                'key' => 'profile_update',
                'category' => 'accounts',
                'flow_key' => 'services',
                'weight' => 1.08,
                'patterns' => [
                    '/\b(update\s+(my\s+)?profile|edit\s+profile|change\s+(my\s+)?(name|address|contact)|i-update\s+ang\s+profile)\b/ui',
                ],
                'keywords' => ['update profile', 'edit profile', 'change address'],
            ],
            [
                'key' => 'registration_help',
                'category' => 'accounts',
                'flow_key' => 'register',
                'weight' => 1.12,
                'patterns' => [
                    '/\b(how\s+to\s+register|create\s+(an\s+)?account|magrehistro|mag\s*register|sign\s*up)\b/ui',
                ],
                'keywords' => ['register', 'registration', 'rehistro', 'sign up'],
            ],
            [
                'key' => 'login_help',
                'category' => 'accounts',
                'flow_key' => 'signin',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(how\s+to\s+(log\s*in|sign\s*in)|cannot\s+log\s*in|can\'?t\s+log\s*in|indi\s+(ko\s+)?makasulod)\b/ui',
                ],
                'keywords' => ['login', 'sign in', 'log in', 'sulod'],
            ],
            [
                'key' => 'book_appointment',
                'category' => 'appointments',
                'flow_key' => 'appointment',
                'weight' => 1.12,
                'patterns' => [
                    '/\b(book\s+(an\s+)?appointment|mag-?book|schedule\s+(a\s+)?visit|gusto\s+ko\s+mag\s*book)\b/ui',
                ],
                'keywords' => ['appointment', 'book', 'magbook', 'schedule'],
            ],
            [
                'key' => 'video_consult',
                'category' => 'video_consultation',
                'flow_key' => 'video',
                'weight' => 1.12,
                'patterns' => [
                    '/\b(video\s+(consult|call|konsulta)|telemedicine|online\s+consult|konsultasyon\s+online)\b/ui',
                ],
                'keywords' => ['video', 'telemedicine', 'online consult', 'video konsultasyon'],
            ],
            [
                'key' => 'ai_triage_info',
                'category' => 'services',
                'flow_key' => 'services',
                'weight' => 1.15,
                'patterns' => [
                    '/\b(ai\s+triage|triage|symptom\s+checker|paano\s+ang\s+triage|what\s+is\s+triage)\b/ui',
                ],
                'keywords' => ['ai triage', 'triage', 'symptom checker'],
            ],
            [
                'key' => 'medical_records',
                'category' => 'services',
                'flow_key' => 'records',
                'weight' => 1.12,
                'patterns' => [
                    '/\b(medical\s+record|health\s+summary|medical\s+history|emr|akong\s+record)\b/ui',
                ],
                'keywords' => ['medical record', 'health summary', 'records', 'emr'],
            ],
            [
                'key' => 'referrals',
                'category' => 'services',
                'flow_key' => 'services',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(referral|referrals|i-refer|pa-refer|referral\s+letter)\b/ui',
                ],
                'keywords' => ['referral', 'referrals', 'pa-refer'],
            ],
            [
                'key' => 'digital_prescriptions',
                'category' => 'services',
                'flow_key' => 'prescriptions',
                'weight' => 1.12,
                'patterns' => [
                    '/\b(digital\s+prescription|e-?prescription|reseta|my\s+prescription)\b/ui',
                ],
                'keywords' => ['prescription', 'reseta', 'digital prescription'],
            ],
            [
                'key' => 'followup_consult',
                'category' => 'appointments',
                'flow_key' => 'appointment',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(follow[\s-]?up\s+consult|followup|balik\s+consult|sunod\s+nga\s+consult)\b/ui',
                ],
                'keywords' => ['follow-up', 'followup', 'balik consult'],
            ],
            [
                'key' => 'doctor_schedules',
                'category' => 'schedules',
                'flow_key' => 'hours',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(doctor\s+schedule|doktor\s+schedule|available\s+doctor|oras\s+sang\s+doktor)\b/ui',
                ],
                'keywords' => ['doctor schedule', 'available doctor', 'oras sang doktor'],
            ],
            [
                'key' => 'clinic_schedules',
                'category' => 'schedules',
                'flow_key' => 'hours',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(clinic\s+schedule|office\s+hours|oras\s+sang\s+(opisina|clinic)|bukas\s+ba|open\s+hours)\b/ui',
                ],
                'keywords' => ['clinic schedule', 'office hours', 'oras sang opisina'],
            ],
            [
                'key' => 'announcements',
                'category' => 'services',
                'flow_key' => 'services',
                'weight' => 1.08,
                'patterns' => [
                    '/\b(announcement|announcements|balita|latest\s+news|health\s+advisory)\b/ui',
                ],
                'keywords' => ['announcement', 'announcements', 'balita', 'advisory'],
            ],
            [
                'key' => 'notifications_help',
                'category' => 'services',
                'flow_key' => 'services',
                'weight' => 1.08,
                'patterns' => [
                    '/\b(notification|notifications|alerts|wala\s+ako\s+notification)\b/ui',
                ],
                'keywords' => ['notification', 'notifications', 'alerts'],
            ],
            [
                'key' => 'contact_cho',
                'category' => 'contact',
                'flow_key' => 'contact',
                'weight' => 1.12,
                'patterns' => [
                    '/\b(city\s+health\s+office|contact\s+(cho|city\s+health)|tawag\s+sa\s+(cho|city)|diin\s+ang\s+city\s+health)\b/ui',
                ],
                'keywords' => ['city health', 'contact support', 'cho'],
            ],
            [
                'key' => 'navigation_help',
                'category' => 'navigation',
                'flow_key' => 'services',
                'weight' => 1.05,
                'patterns' => [
                    '/\b(how\s+do\s+i\s+use|where\s+(do\s+i|can\s+i)|paano\s+gamiton|diin\s+ko\s+makita|navigate|which\s+page)\b/ui',
                ],
                'keywords' => ['how to use', 'paano gamiton', 'where is', 'navigation'],
            ],
        ];
    }

    /** @return array<string, array{en: list<string>, fil: list<string>, hil: list<string>}> */
    public static function responses(): array
    {
        return [
            'password_reset' => [
                'en' => ['<p>On the Sign In card, open <strong>Forgot password</strong>, enter the requested details, then follow the email/link. Check spam if needed. Still stuck? Contact City Health support via the Contact section.</p>'],
                'fil' => ['<p>Sa Sign In, pindutin ang <strong>Forgot password</strong>, sundin ang email/link. Tingnan ang spam. Problema pa → Contact / City Health support.</p>'],
                'hil' => ['<p>Sa Sign In, pinduta ang <strong>Forgot password</strong>, sundon ang email/link. Tan-awa ang spam. Problema pa → Contact / City Health support.</p>'],
            ],
            'otp_verification' => [
                'en' => ['<p>OTP codes are time-limited. Wait a minute, check spam/SMS, then request a new code if available. Make sure your email/phone in the form is correct. If it still fails, use Contact support — I cannot generate OTP codes in chat.</p>'],
                'fil' => ['<p>May expiry ang OTP. Maghintay, tingnan ang spam/SMS, then request new code. Tama ba ang email/phone? Hindi ako makakapagbigay ng OTP dito.</p>'],
                'hil' => ['<p>May expiry ang OTP. Maghulat, tan-awa ang spam/SMS, dayon request new code. Indi ako makahatag sang OTP diri sa chat.</p>'],
            ],
            'email_verification' => [
                'en' => ['<p>Open the verification email and tap the confirm link. If missing, check spam or request a new verification email from the registration/login flow. Verified email helps secure your medConnect account.</p>'],
                'fil' => ['<p>Buksan ang verification email at i-confirm. Kung wala, tingnan ang spam o mag-request ulit mula sa register/login.</p>'],
                'hil' => ['<p>Buksan ang verification email kag i-confirm. Kon wala, tan-awa ang spam ukon mag-request liwat.</p>'],
            ],
            'account_recovery' => [
                'en' => ['<p>Try Forgot password first. If you still cannot access your account, contact City Health/support with your registered details for assisted recovery. For safety, I cannot reset accounts directly in chat.</p>'],
                'fil' => ['<p>Subukan muna ang Forgot password. Kung hindi pa rin, kontakin ang City Health/support. Hindi ako makakapag-reset ng account dito.</p>'],
                'hil' => ['<p>Tilawi anay ang Forgot password. Kon indi pa, contact City Health/support. Indi ako maka-reset sang account diri.</p>'],
            ],
            'profile_update' => [
                'en' => ['<p>After signing in, open your <strong>Profile</strong> / account settings to update contact details and personal info. Save changes carefully. Some identity fields may require verification by staff.</p>'],
                'fil' => ['<p>Pagkatapos mag-login, pumunta sa <strong>Profile</strong> para i-update ang details. I-save nang maingat.</p>'],
                'hil' => ['<p>Pagkatapos mag-login, kadto sa <strong>Profile</strong> para i-update ang details. I-save sing maayo.</p>'],
            ],
            'registration_help' => [
                'en' => ['<p>To register: open <strong>Sign In</strong>, choose create/register account, complete required fields, then verify if prompted. After that you can book appointments when available.</p>'],
                'fil' => ['<p>Magrehistro: <strong>Sign In</strong> → register, kumpletuhin ang fields, i-verify kung kailangan, tapos puwede nang mag-book.</p>'],
                'hil' => ['<p>Magrehistro: <strong>Sign In</strong> → register, kompletoha ang fields, i-verify kon kinahanglan, dayon pwede mag-book.</p>'],
            ],
            'login_help' => [
                'en' => ['<p>Use <strong>Sign In</strong> on the landing page with your account details. Forgot password? Use the reset link on the form. Still stuck? I can guide you to Contact / City Health support.</p>'],
                'fil' => ['<p>Sa landing page, pindutin ang <strong>Sign In</strong>. Nakalimutan ang password → reset link. Problema pa → Contact support.</p>'],
                'hil' => ['<p>Sa landing page, pinduta ang <strong>Sign In</strong>. Nakalimtan ang password → reset link. Problema pa → Contact support.</p>'],
            ],
            'book_appointment' => [
                'en' => ['<p>Sign in as a patient, open <strong>Appointments</strong>, and choose an available schedule. Urgent symptoms need ER/<strong>911</strong>, not a routine slot. I can guide steps here but cannot book for you in chat.</p>'],
                'fil' => ['<p>Mag-login bilang patient → <strong>Appointments</strong> → pumili ng schedule. Emergency → <strong>911</strong>.</p>'],
                'hil' => ['<p>Mag-login bilang patient → <strong>Appointments</strong> → pilia ang schedule. Emergency → <strong>911</strong>.</p>'],
            ],
            'video_consult' => [
                'en' => ['<p>Video consultation lets you meet a provider online after signing in. Check Appointments/Consultation for scheduled video visits and use a stable connection. It does not replace emergency care.</p>'],
                'fil' => ['<p>Ang video consultation ay online consult pagkatapos mag-login. Tingnan ang Appointments/Consultation. Hindi kapalit ng emergency care.</p>'],
                'hil' => ['<p>Ang video konsultasyon online nga consult pagkatapos mag-login. Tan-awa ang Appointments/Consultation. Indi bulos sang emergency care.</p>'],
            ],
            'ai_triage_info' => [
                'en' => ['<p><strong>AI Triage</strong> in medConnect helps organize your symptoms for care prioritization. It is a decision-support tool — <strong>not a diagnosis</strong> and not a substitute for emergency care. For chest pain, severe breathing trouble, or fainting, call <strong>911</strong>.</p>'],
                'fil' => ['<p>Ang <strong>AI Triage</strong> tumutulong mag-ayos ng sintomas para sa prioritization — <strong>hindi diagnosis</strong>. Emergency → <strong>911</strong>.</p>'],
                'hil' => ['<p>Ang <strong>AI Triage</strong> nagabulig mag-ayos sang sintomas — <strong>indi diagnosis</strong>. Emergency → <strong>911</strong>.</p>'],
            ],
            'medical_records' => [
                'en' => ['<p>After signing in, open <strong>Medical Records</strong> / Health Summary to view available history. Records depend on what providers have entered. Need a correction? Ask your clinic/provider.</p>'],
                'fil' => ['<p>Pagkatapos mag-login, buksan ang <strong>Medical Records</strong> / Health Summary. Depende ito sa na-encode ng provider.</p>'],
                'hil' => ['<p>Pagkatapos mag-login, buksan ang <strong>Medical Records</strong> / Health Summary. Depende ini sa na-encode sang provider.</p>'],
            ],
            'referrals' => [
                'en' => ['<p>Referrals are usually created by your provider when you need another service or facility. Check your appointments/records after a visit, or ask City Health staff. I cannot issue referrals in chat.</p>'],
                'fil' => ['<p>Ang referral ay karaniwang galing sa provider. Tingnan ang records/appointments o itanong sa City Health. Hindi ako makakapag-issue ng referral dito.</p>'],
                'hil' => ['<p>Ang referral halin sa provider. Tan-awa ang records/appointments ukon pamangkota ang City Health. Indi ako maka-issue sang referral diri.</p>'],
            ],
            'digital_prescriptions' => [
                'en' => ['<p>Digital prescriptions appear in your account after a provider issues them. Sign in and check Prescriptions/Records. I cannot prescribe medication — only licensed providers can.</p>'],
                'fil' => ['<p>Lalabas ang digital prescription pagkatapos i-issue ng provider. Mag-login at tingnan ang Prescriptions/Records. Hindi ako nagre-reseta.</p>'],
                'hil' => ['<p>Makita ang digital prescription pagkatapos i-issue sang provider. Mag-login kag tan-awa ang Prescriptions/Records. Indi ako nagapreskribar.</p>'],
            ],
            'followup_consult' => [
                'en' => ['<p>For follow-up consultations, sign in and book another appointment (or use a follow-up slot if shown). Bring notes about what changed since your last visit.</p>'],
                'fil' => ['<p>Para sa follow-up, mag-login at mag-book ulit. Magdala ng notes kung ano ang nagbago.</p>'],
                'hil' => ['<p>Para sa follow-up, mag-login kag mag-book liwat. Magdala notes kon ano ang nagbag-o.</p>'],
            ],
            'doctor_schedules' => [
                'en' => ['<p>Doctor availability appears in the booking calendar after you sign in. Schedules can change — if you need a specific provider, check Appointments or ask City Health.</p>'],
                'fil' => ['<p>Makikita ang availability ng doktor sa booking calendar pagkatapos mag-login. Maaaring magbago ang schedule.</p>'],
                'hil' => ['<p>Makita ang availability sang doktor sa booking calendar pagkatapos mag-login. Mahimo magbag-o ang schedule.</p>'],
            ],
            'clinic_schedules' => [
                'en' => ['<p>Clinic/office hours are listed on the landing page Contact section and may also appear in announcements. For holiday changes, check Latest Announcements or call City Health.</p>'],
                'fil' => ['<p>Ang office hours ay nasa Contact section at minsan sa announcements. Para sa holiday changes, tingnan ang announcements o tumawag sa City Health.</p>'],
                'hil' => ['<p>Ang office hours yara sa Contact section kag kon kaisa sa announcements. Para sa holiday changes, tan-awa ang announcements.</p>'],
            ],
            'announcements' => [
                'en' => ['<p>Open <strong>Latest Announcements</strong> on the landing page for health advisories and City Health updates. Sign in for account-related notices when available.</p>'],
                'fil' => ['<p>Buksan ang <strong>Latest Announcements</strong> sa landing page para sa advisories at updates.</p>'],
                'hil' => ['<p>Buksan ang <strong>Latest Announcements</strong> sa landing page para sa advisories kag updates.</p>'],
            ],
            'notifications_help' => [
                'en' => ['<p>After signing in, check the notifications/bell icon for appointment reminders and system alerts. Also verify notification settings and that your contact email is correct.</p>'],
                'fil' => ['<p>Pagkatapos mag-login, tingnan ang notifications/bell. I-check din ang settings at email.</p>'],
                'hil' => ['<p>Pagkatapos mag-login, tan-awa ang notifications/bell. I-check man ang settings kag email.</p>'],
            ],
            'contact_cho' => [
                'en' => ['<p>For City Health Office questions, use the <strong>Contact</strong> section on this page or sign in for support options. Life-threatening emergencies → call <strong>911</strong> first.</p>'],
                'fil' => ['<p>Para sa City Health, tingnan ang <strong>Contact</strong> section. Emergency → <strong>911</strong>.</p>'],
                'hil' => ['<p>Para sa City Health, tan-awa ang <strong>Contact</strong> section. Emergency → <strong>911</strong>.</p>'],
            ],
            'navigation_help' => [
                'en' => ['<p>Quick map: <strong>Sign In</strong> (account), <strong>Appointments</strong> (book), <strong>Video</strong> consult when offered, <strong>Announcements</strong>, and <strong>Contact</strong> for City Health. Tell me which page you need.</p>'],
                'fil' => ['<p>Gabay: <strong>Sign In</strong>, <strong>Appointments</strong>, <strong>Video</strong>, <strong>Announcements</strong>, <strong>Contact</strong>. Sabihin kung alin ang kailangan mo.</p>'],
                'hil' => ['<p>Mapa: <strong>Sign In</strong>, <strong>Appointments</strong>, <strong>Video</strong>, <strong>Announcements</strong>, <strong>Contact</strong>. Silinga kon ano ang imo kinahanglan.</p>'],
            ],
        ];
    }
}
