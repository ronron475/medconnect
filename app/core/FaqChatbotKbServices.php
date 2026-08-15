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
                    '/\b(otp|one\s*time\s*pin|verification\s*code|code\s+not\s+received|wala\s+(ko\s+)?otp|wala\s+nag-?abot\s+otp|wala\s+ako\s+nabaton\s+nga\s+otp)\b/ui',
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
                    '/\b(how\s+to\s+(log\s*in|sign\s*in)|cannot\s+log\s*in|can\'?t\s+log\s*in|indi\s+(ko\s+)?(ka\s*)?(login|makasulod)|wala\s+ko\s+ka\s*login|hindi\s+ako\s+makalogin)\b/ui',
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
            [
                'key' => 'cancel_appointment',
                'category' => 'appointments',
                'flow_key' => 'appointment',
                'weight' => 1.14,
                'patterns' => [
                    '/\b(cancel\s+(my\s+)?appointment|reschedule|can\s+i\s+cancel|i-?cancel|move\s+my\s+appointment)\b/ui',
                ],
                'keywords' => ['cancel', 'reschedule', 'i-cancel', 'move appointment'],
            ],
            [
                'key' => 'appointment_status',
                'category' => 'appointments',
                'flow_key' => 'appointment',
                'weight' => 1.12,
                'patterns' => [
                    '/\b(appointment\s+status|where\s+is\s+my\s+(consultation|appointment)|di\s+ko\s+makita\s+appointment|upcoming\s+appointment|missed\s+appointment)\b/ui',
                ],
                'keywords' => ['appointment status', 'upcoming appointment', 'where is my consultation'],
            ],
            [
                'key' => 'video_troubleshooting',
                'category' => 'video_consultation',
                'flow_key' => 'video',
                'weight' => 1.16,
                'patterns' => [
                    '/\b(video\s+call\s+not\s+working|doctor\s+(didn\'?t|did\s+not)\s+join|no\s+(audio|camera|microphone)|camera\s+(permission|doesn\'?t\s+work|indi\s+naga\s+work)|video\s+frozen|indi\s+naga\s+work\s+ang\s+video|indi\s+ko\s+(makita|mabatian|marinig)|wala\s+doctor\s+sa\s+video|can\'?t\s+hear\s+(the\s+)?doctor)\b/ui',
                ],
                'keywords' => ['video not working', 'doctor didn\'t join', 'no camera', 'no microphone'],
            ],
            [
                'key' => 'consultation_cost',
                'category' => 'access_barriers',
                'flow_key' => 'financial',
                'weight' => 1.14,
                'patterns' => [
                    '/\b(how\s+much|may\s+bayad|libre\s+ni|free\s+ni|consultation\s+fee|wala\s+ko\s+kwarta|mahal\??)\b/ui',
                ],
                'keywords' => ['how much', 'may bayad', 'libre', 'consultation fee', 'free ni'],
            ],
            [
                'key' => 'bhw_help',
                'category' => 'bhw',
                'flow_key' => 'services',
                'weight' => 1.18,
                'patterns' => [
                    '/\b(what\s+is\s+bhw|can\s+bhw\s+help|barangay\s+health\s+worker|\bbhw\b)\b/ui',
                ],
                'keywords' => ['bhw', 'barangay health worker', 'health worker'],
            ],
            [
                'key' => 'technical_support',
                'category' => 'technical',
                'flow_key' => 'services',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(website\s+not\s+loading|page\s+stuck|blank\s+page|button\s+not\s+working|loading\s+forever|error\s+message|dashboard\s+problem)\b/ui',
                ],
                'keywords' => ['website not loading', 'blank page', 'button not working', 'error message'],
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
                'en' => [
                    '<p>Use <strong>Sign In</strong> on the landing page with your account details. Forgot password? Use the reset link on the form. Still stuck? I can guide you to Contact / City Health support.</p>',
                    '<p>Cannot log in? Check email/phone and password, then try <strong>Sign In</strong> again. Use Forgot password if the password is wrong. I cannot unlock the account from chat.</p>',
                ],
                'fil' => [
                    '<p>Sa landing page, pindutin ang <strong>Sign In</strong>. Nakalimutan ang password → reset link. Problema pa → Contact support.</p>',
                    '<p>Hindi maka-login? Tingnan ang details, then Sign In. Forgot password kung mali ang password.</p>',
                ],
                'hil' => [
                    '<p>Sa landing page, pinduta ang <strong>Sign In</strong>. Nakalimtan ang password → reset link. Problema pa → Contact support.</p>',
                    '<p>Indi ka login? Tan-awa ang details, dayon Sign In. Forgot password kon mali ang password.</p>',
                ],
            ],
            'book_appointment' => [
                'en' => [
                    '<p>Sign in as a patient, open <strong>Appointments</strong>, and choose an available schedule. Urgent symptoms need ER/<strong>911</strong>, not a routine slot. I can guide steps here but cannot book for you in chat.</p>',
                    '<p>To book: Sign In → <strong>Appointments</strong> → pick a free slot. If this is an emergency, call <strong>911</strong> instead of waiting for a routine visit.</p>',
                    '<p>You can schedule a consultation after signing in. Look for Appointments on medConnect. I cannot complete the booking inside this chat.</p>',
                ],
                'fil' => [
                    '<p>Mag-login bilang patient → <strong>Appointments</strong> → pumili ng schedule. Emergency → <strong>911</strong>.</p>',
                    '<p>Para mag-book: Sign In, then Appointments. Hindi ko ito mabobook dito sa chat.</p>',
                ],
                'hil' => [
                    '<p>Mag-login bilang patient → <strong>Appointments</strong> → pilia ang schedule. Emergency → <strong>911</strong>.</p>',
                    '<p>Para mag-book: Sign In, dayon Appointments. Indi ko ini mabook diri sa chat.</p>',
                ],
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
                'en' => ['<p>After signing in, open <strong>Medical Records</strong> / Health Summary for available history. SOAP notes and diagnoses are written by your healthcare provider — the chatbot cannot change clinical records. Need a correction? Ask your clinic/provider.</p>'],
                'fil' => ['<p>Pagkatapos mag-login, buksan ang <strong>Medical Records</strong> / Health Summary. Ang SOAP at diagnosis ay mula sa provider — hindi ko ito mababago. Para sa correction, sa clinic/provider magtanong.</p>'],
                'hil' => ['<p>Pagkatapos mag-login, buksan ang <strong>Medical Records</strong> / Health Summary. Ang SOAP kag diagnosis halin sa provider — indi ko ini mabag-o.</p>'],
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
            'cancel_appointment' => [
                'en' => [
                    '<p>Yes — after you sign in, open <strong>Appointments</strong> / your scheduled visit to cancel or reschedule when the system still allows it. If the visit already started, contact City Health or your provider. I cannot cancel it from this chat.</p>',
                    '<p>You can usually cancel or move a booking from your signed-in Appointments page. Need a different time? Pick another available slot. Staff can also help if the page does not show the visit.</p>',
                ],
                'fil' => ['<p>Oo — mag-login, buksan ang <strong>Appointments</strong> para i-cancel o i-reschedule kung available pa. Hindi ko ito mae-edit dito sa chat.</p>'],
                'hil' => ['<p>Huo — mag-login, buksan ang <strong>Appointments</strong> para i-cancel ukon i-reschedule kon pwede pa. Indi ko ini ma-edit diri sa chat.</p>'],
            ],
            'appointment_status' => [
                'en' => ['<p>Sign in and open <strong>Appointments</strong> or your consultation list to see upcoming, missed, or completed visits. If a visit is missing, refresh the page or contact City Health support — I cannot look up a live schedule in chat.</p>'],
                'fil' => ['<p>Mag-login at buksan ang <strong>Appointments</strong> para sa upcoming o past visits. Kung wala, i-refresh o kontakin ang City Health.</p>'],
                'hil' => ['<p>Mag-login kag buksan ang <strong>Appointments</strong> para sa upcoming ukon past visits. Kon wala, i-refresh ukon contact City Health.</p>'],
            ],
            'video_troubleshooting' => [
                'en' => [
                    '<p>If video is not working: allow <strong>camera and microphone</strong> in the browser, use a supported browser, and check your internet. If the doctor has not joined yet, wait a moment or re-enter from Appointments. This chat does not start the video room.</p>',
                    '<p>No picture or sound? Check permissions, close extra tabs, and rejoin from your scheduled consultation. If the provider still does not appear, message City Health or try again when your connection is stable.</p>',
                ],
                'fil' => ['<p>Kung hindi gumagana ang video: payagan ang camera/microphone, suriin ang internet, at bumalik mula sa Appointments. Hindi ko masisimulan ang video dito.</p>'],
                'hil' => ['<p>Kon indi naga-andar ang video: pasugta ang camera/microphone, tan-awa ang internet, kag magbalik sa Appointments. Indi ko masugdan ang video diri.</p>'],
            ],
            'consultation_cost' => [
                'en' => [
                    '<p>I do not list fees in this chat because charges can depend on City Health Office policy and the service. For cost or whether a visit is free, please check <strong>Contact</strong> / City Health staff. medConnect is the booking and records portal — not a payment quote.</p>',
                    '<p>Asking if it is free or how much it costs? Please ask City Health Office through the Contact section. I will not invent a price here.</p>',
                ],
                'fil' => ['<p>Hindi ko ililista ang presyo dito. Para sa bayad o kung libre, tanungin ang City Health sa <strong>Contact</strong>. Hindi ako magbibigay ng haka-hakang fee.</p>'],
                'hil' => ['<p>Indi ko ilista ang presyo diri. Para sa bayad ukon kon libre, pamangkota ang City Health sa <strong>Contact</strong>. Indi ako maghimo sang fee.</p>'],
            ],
            'bhw_help' => [
                'en' => [
                    '<p>A <strong>Barangay Health Worker (BHW)</strong> in medConnect can help register or assist an existing patient, update contact details, help with appointment/triage intake, and make emergency referrals. BHWs <strong>cannot diagnose</strong>, prescribe, or override AI triage. Ask your barangay BHW or City Health if you need in-person help.</p>',
                ],
                'fil' => ['<p>Ang <strong>BHW</strong> ay makakatulong magrehistro o tulungan ang existing patient, appointment/triage intake, at emergency referral. <strong>Hindi sila nagda-diagnose</strong> at hindi nila mababago ang AI triage.</p>'],
                'hil' => ['<p>Ang <strong>BHW</strong> makabulig magrehistro ukon magbulig sa existing patient, appointment/triage, kag emergency referral. <strong>Indi sila nagadiagnose</strong> kag indi nila mabag-o ang AI triage.</p>'],
            ],
            'technical_support' => [
                'en' => [
                    '<p>If a page is stuck or blank: refresh, try another browser, and check your internet. Allow camera/mic if a video visit needs them. OTP/email delays — wait a minute and check spam. Still blocked? Use <strong>Contact</strong> / City Health support and describe the page and error.</p>',
                ],
                'fil' => ['<p>Kung nakasabit o blanko: i-refresh, ibang browser, suriin ang internet. OTP/email — hintay at spam folder. Problema pa → Contact / City Health.</p>'],
                'hil' => ['<p>Kon naga-stuck ukon blanko: i-refresh, iban nga browser, tan-awa ang internet. OTP/email — maghulat kag spam. Problema pa → Contact / City Health.</p>'],
            ],
            'doctor_clarify' => [
                'en' => [
                    '<p>Sure. Are you trying to <strong>book a new consultation</strong>, or <strong>join an appointment you already have</strong>?</p>',
                    '<p>I can help you see a doctor through medConnect. Do you want to book a new visit, or join one that is already scheduled?</p>',
                ],
                'fil' => [
                    '<p>Sige. Gusto mo bang <strong>mag-book ng bagong consultation</strong>, o <strong>sumali sa appointment na meron ka na</strong>?</p>',
                ],
                'hil' => [
                    '<p>Sige. Gusto mo mag-<strong>book sang bag-o nga consultation</strong>, ukon <strong>join sang appointment nga may ara ka na</strong>?</p>',
                ],
            ],
            'login_and_appointment' => [
                'en' => [
                    '<p>Let\'s fix <strong>Sign In</strong> first so you can reach your appointment: use the landing-page Sign In form (Forgot password if needed). After you are in, open <strong>Appointments</strong> for today\'s visit. I cannot log you in from this chat.</p>',
                ],
                'fil' => [
                    '<p>Ayusin muna ang <strong>Sign In</strong> para maabot ang appointment: landing page → Sign In (Forgot password kung kailangan). Pagkatapos, buksan ang <strong>Appointments</strong>.</p>',
                ],
                'hil' => [
                    '<p>Ayuhon ta anay ang <strong>Sign In</strong> agod maabot ang appointment: landing page → Sign In. Pagkatapos, buksan ang <strong>Appointments</strong>.</p>',
                ],
            ],
        ];
    }
}
