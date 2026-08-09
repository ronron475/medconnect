<?php
/**
 * Real-life situational knowledge pack: weather, connectivity, transport, privacy, access barriers.
 * PHP only — no external AI.
 */
final class FaqChatbotKbSituations
{
    /** @return list<array<string, mixed>> */
    public static function scenarios(): array
    {
        return [
            [
                'key' => 'weather_barrier',
                'category' => 'access_barriers',
                'flow_key' => 'distress_support',
                'weight' => 1.12,
                'patterns' => [
                    '/\b(gaulan|grabe\s+ang\s+ulan|grabe\s+nga\s+ulan|baha|storm|bad\s+weather|ulan\s+pa)\b/ui',
                    '/\b(indi\s+ko\s+makaguwa|basi\s+indi\s+ko\s+makakadto).{0,40}(ulan|gaulan|baha)\b/ui',
                ],
                'keywords' => ['gaulan', 'grabe ang ulan', 'baha', 'bad weather', 'makaguwa'],
            ],
            [
                'key' => 'signal_internet_problem',
                'category' => 'connectivity',
                'flow_key' => 'video',
                'weight' => 1.15,
                'patterns' => [
                    '/\b(wala\s+signal|nadula\s+signal|gadula.{0,16}signal|hinay\s+signal|weak\s+signal)\b/ui',
                    '/\b(wala\s+internet|no\s+internet|putol.{0,12}connection|unstable\s+connection)\b/ui',
                    '/\b(di\s+ko\s+ka.?video|indi\s+ko\s+maka.?video|can\'?t\s+video\s+call|ga.?lag|laggy\s+video)\b/ui',
                    '/\b(wala\s+ko\s+kabati|cannot\s+hear|audio\s+problem|mic\s+not\s+working)\b/ui',
                ],
                'keywords' => ['wala signal', 'nadula signal', 'gadula signal', 'hinay signal', 'wala internet', 'ga lag', 'video call', 'wala ko kabati'],
            ],
            [
                'key' => 'transport_barrier',
                'category' => 'access_barriers',
                'flow_key' => 'distress_support',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(wala\s+ko\s+masakyan|no\s+transport|cannot\s+get\s+there)\b/ui',
                    '/\b(layo\s+amon|layo\s+ang|far\s+from|budlay\s+magkadto|hard\s+to\s+reach)\b/ui',
                    '/\b(wala\s+ko\s+pamasahe|wala\s+kwarta\s+pangpamasahe|no\s+fare\s+money)\b/ui',
                    '/\b(indi\s+ko\s+makakadto\s+sa\s+(health\s+center|ospital|clinic))\b/ui',
                ],
                'keywords' => ['wala ko masakyan', 'layo amon', 'budlay magkadto', 'pamasahe', 'makakadto'],
            ],
            [
                'key' => 'financial_access',
                'category' => 'financial',
                'flow_key' => 'financial',
                'weight' => 1.14,
                'patterns' => [
                    '/\b(wala\s+ko\s+budget|no\s+budget|cannot\s+pay|indi\s+ko\s+kaya\s+magbayad)\b/ui',
                    '/\b(may\s+libre\s+nga\s+consultation|free\s+consultation|libre\s+nga)\b/ui',
                    '/\b(kung\s+wala\s+kwarta|if\s+no\s+money|paano\s+ko\s+kung\s+wala)\b/ui',
                    '/\b(wala\s+ko\s+kwarta\s+pangpamasahe|wala\s+kwarta\s+pang)\b/ui',
                ],
                'keywords' => ['wala budget', 'libre nga consultation', 'wala kwarta', 'cannot pay', 'magbayad'],
            ],
            [
                'key' => 'privacy_security',
                'category' => 'privacy',
                'flow_key' => 'policy',
                'weight' => 1.12,
                'patterns' => [
                    '/\b(masaligan\s+ni\s+bala|safe\s+bala|is\s+this\s+safe|sigurado\s+bala)\b/ui',
                    '/\b(confidential\s+bala|private\s+bala|makita\s+bala\s+ni\s+sang\s+iban)\b/ui',
                    '/\b(data\s+privacy|my\s+information\s+safe|secure\s+bala)\b/ui',
                    '/\b(tinuod\s+bala\s+ni|is\s+this\s+real|legit\s+bala)\b/ui',
                ],
                'keywords' => ['masaligan', 'safe bala', 'confidential', 'privacy', 'makita sang iban', 'tinuod bala'],
            ],
            [
                'key' => 'system_trust',
                'category' => 'privacy',
                'flow_key' => 'policy',
                'weight' => 1.08,
                'patterns' => [
                    '/\b(paano\s+kung\s+mag.?error|what\s+if\s+error|system\s+error|bug|not\s+working)\b/ui',
                    '/\b(nadula\s+akon\s+appointment|lost\s+my\s+appointment|appointment\s+missing)\b/ui',
                    '/\b(di\s+ko\s+makita\s+ang\s+doctor|cannot\s+see\s+doctor|doctor\s+not\s+showing)\b/ui',
                ],
                'keywords' => ['mag error', 'nadula appointment', 'makita ang doctor', 'system error'],
            ],
            [
                'key' => 'loneliness_no_one',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(wala\s+gid\s+ko\s+may\s+kastorya|wala\s+ko\s+may\s+maistoryahan|no\s+one\s+to\s+talk)\b/ui',
                    '/\b(wala\s+ko\s+sang\s+kaistoryahan|isa\s+lang\s+gid)\b/ui',
                ],
                'keywords' => ['wala ko may kastorya', 'wala may maistoryahan', 'no one to talk'],
            ],
            [
                'key' => 'uncertainty_worry',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.08,
                'patterns' => [
                    '/\b(paano\s+na\s+lang\s+ni|paano\s+na\s+ni|what\s+now|ano\s+na\s+himuon)\b/ui',
                    '/\b(ngaa\s+amo\s+ni|nga\s+amo\s+ni|why\s+is\s+this\s+happening)\b/ui',
                    '/\b(wala\s+ko\s+idea|no\s+idea|di\s+ko\s+alam)\b/ui',
                ],
                'keywords' => ['paano na', 'ano na himuon', 'wala ko idea', 'ngaa amo ni'],
            ],
            [
                'key' => 'serious_distress',
                'category' => 'crisis',
                'flow_key' => 'crisis',
                'weight' => 1.35,
                'patterns' => [
                    '/\b(di\s+ko\s+na\s+kaya|dili\s+ko\s+na\s+kaya|can\'?t\s+take\s+it\s+anymore|i\s+can\'?t\s+do\s+this)\b/ui',
                    '/\b(kapoy\s+na\s+gid\s+ko\s+sa\s+tanan|tired\s+of\s+everything)\b/ui',
                    '/\b(gusto\s+ko\s+na\s+mag.?untat|want\s+to\s+stop\s+living|wala\s+na\s+pulos)\b/ui',
                    '/\b(mabuhi\s+pa\s+ko(\s+ni\s+ayhan)?|will\s+i\s+still\s+live)\b/ui',
                    '/\b(gusto\s+ko\s+mawala|mas\s+maayo\s+pa\s+siguro\s+kung\s+wala\s+na\s+ko)\b/ui',
                ],
                'keywords' => ['di ko na kaya', 'kapoy na gid sa tanan', 'wala na pulos', 'mabuhi pa ko', 'mag untat'],
            ],
            [
                'key' => 'checkup_anxiety',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.12,
                'patterns' => [
                    '/\b(gakulbaan\s+ko\s+magpa.?check|ginakulbaan\s+ko\s+magpa|kulbaan\s+ko\s+magpa)\b/ui',
                    '/\b(anxious\s+about\s+checkup|scared\s+to\s+get\s+checked)\b/ui',
                ],
                'keywords' => ['gakulbaan magpa check', 'kulbaan magpa check', 'anxious checkup'],
            ],
            [
                'key' => 'short_help_request',
                'category' => 'general',
                'flow_key' => 'contact',
                'weight' => 1.05,
                'patterns' => [
                    '/^(help|buligi\s+ko|tabangi\s+ko|tabangi\s+ko\s+bi|buligi\s+ko\s+bi|tabang)\s*[!.?]*$/ui',
                    '/^(ano\s+na|ano\s+na\s+ni)\s*[?.!]*$/ui',
                ],
                'keywords' => ['help', 'buligi ko', 'tabangi ko', 'ano na'],
            ],
            [
                'key' => 'reassurance_okay',
                'category' => 'general',
                'flow_key' => 'welcome',
                'weight' => 1.0,
                'patterns' => [
                    '/\b(tani\s+okay\s+lang|sana\s+okay|hopefully\s+okay|okay\s+lang\s+man)\b/ui',
                    '/^(okay\s+lang|ok\s+lang)\s*[!.?]*$/ui',
                ],
                'keywords' => ['tani okay lang', 'okay lang man'],
            ],
        ];
    }

    /** @return array<string, array{en: list<string>, fil: list<string>, hil: list<string>}> */
    public static function responses(): array
    {
        return [
            'weather_barrier' => [
                'en' => [
                    '<p>Bad weather can make healthcare harder — that\'s a real barrier. If you can\'t travel safely, consider a <strong>video consultation</strong> on medConnect when signal allows, or contact City Health when the weather improves. For emergencies, call <strong>911</strong> — don\'t wait on chat.</p>',
                    '<p>Rain and floods are tough. Stay safe first. When you can connect, medConnect video consult may help if going out is risky — I can guide booking steps.</p>',
                ],
                'fil' => [
                    '<p>Mahirap magpunta kapag masama ang panahon. Kung hindi ligtas, subukan ang <strong>video consultation</strong> sa medConnect kapag may signal, o tumawag sa City Health. Emergency → <strong>911</strong>.</p>',
                ],
                'hil' => [
                    '<p>Budlay magkadto kon grabe ang ulan. Stay safe anay. Kon makaconnect, pwede ang <strong>video consultation</strong> sa medConnect — matabangan ko ang booking. Emergency → <strong>911</strong>.</p>',
                    '<p>Nabudlayan ka gid subong kay gaulan. Kon indi ka maka-connect sa consultation, tan-awon ta ang available options kon maayo na ang panahon.</p>',
                ],
            ],
            'signal_internet_problem' => [
                'en' => [
                    '<p>Weak signal or internet can block video visits — that happens a lot. Try moving to a spot with better signal, restart your phone, or use chat/phone options through City Health. You can also book an in-person visit when connectivity is better.</p>',
                    '<p>Slow or dropping connection is frustrating. For video consult: better signal area, close other apps, or ask City Health about phone/in-person alternatives. I can still help with account and booking steps here.</p>',
                ],
                'fil' => [
                    '<p>Mahina ang signal o internet — common yan. Subukan mas malakas na signal, o magtanong sa City Health tungkol sa phone/in-person na opsyon.</p>',
                ],
                'hil' => [
                    '<p>Budlay kon wala signal ukon hinay internet — common sina. Tilawi ang lugar nga mas maayo ang signal, ukon pamangkota ang City Health parte sa phone/in-person options. Matabangan ko gihapon sa account kag booking diri.</p>',
                    '<p>Nabudlayan ka gid subong kay indi maayo ang signal. Kon indi ka maka-video call, may iban pa nga paagi — City Health ukon in-person visit kon makakadto ka.</p>',
                ],
            ],
            'transport_barrier' => [
                'en' => [
                    '<p>Distance and transport are real obstacles. Video consultation on medConnect may help when travel is hard. Ask City Health about nearby options or public programs — cost should not block emergency care (<strong>911</strong>).</p>',
                ],
                'fil' => [
                    '<p>Malayo at mahirap ang transportasyon. Puwedeng video consultation sa medConnect. Magtanong sa City Health tungkol sa malapit na opsyon. Emergency → <strong>911</strong>.</p>',
                ],
                'hil' => [
                    '<p>Layo kag budlay ang transport — real barrier sina. Pwede ang video consultation sa medConnect kon budlay magkadto. Pamangkota ang City Health parte sa malapit nga options. Emergency → <strong>911</strong>.</p>',
                    '<p>Gets ko — wala masakyan ukon wala pamasahe. Tan-awon ta ang video consult ukon City Health programs nga mas accessible.</p>',
                ],
            ],
            'financial_access' => [
                'en' => [
                    '<p>Worrying about money is valid. City Health often has public services — ask about available programs or free/low-cost options. I can guide medConnect booking; don\'t delay emergency care: <strong>911</strong>.</p>',
                    '<p>No budget for a checkup is stressful. You still deserve care. Inquire at City Health about public clinics and what\'s covered — I\'ll help with next steps on medConnect.</p>',
                ],
                'fil' => [
                    '<p>Valid ang alalahanin sa gastos. May pampublikong serbisyo ang City Health — magtanong. Matutulungan kitang mag-book sa medConnect. Emergency → <strong>911</strong>.</p>',
                ],
                'hil' => [
                    '<p>Valid ang kabalaka sa kwarta. May pampubliko nga serbisyo ang City Health — pamangkota parte sa libre ukon low-cost options. Matabangan ko sa medConnect. Emergency → <strong>911</strong>.</p>',
                    '<p>Wala budget — mabudlay gid. Deserve mo gihapon sang care. Pamangkota ang City Health; ginagiyahan ko ikaw sa sunod nga steps.</p>',
                ],
            ],
            'privacy_security' => [
                'en' => [
                    '<p>Your privacy matters. medConnect uses secure sign-in and City Health policies to protect health information. I\'m an FAQ assistant — for full privacy details, see City Health or your account privacy settings. I don\'t share your chat with other users.</p>',
                    '<p>It\'s smart to ask if this is safe. medConnect is an official City Health Office platform. Your medical records are accessed only through your account — not visible to other patients.</p>',
                ],
                'fil' => [
                    '<p>Mahalaga ang privacy mo. medConnect ay secure sign-in at City Health policies. Para sa buong detalye, tingnan ang City Health o privacy settings.</p>',
                ],
                'hil' => [
                    '<p>Importante ang imo privacy. Secure ang medConnect sign-in kag City Health policies. Para sa full details, tan-awa ang City Health ukon privacy settings. Indi ko ginapakita ang imo chat sa iban nga users.</p>',
                    '<p>Maayo nga nagpamangkot ka kon safe bala. Official platform ini sang City Health Office — ang imo records accessible lang paagi sa imo account.</p>',
                ],
            ],
            'system_trust' => [
                'en' => [
                    '<p>Sorry if something went wrong. For a missing appointment or doctor not showing, contact City Health support through medConnect messages or call the office. I can guide you to contact options — I cannot fix system errors directly.</p>',
                ],
                'fil' => [
                    '<p>Pasensya kung may error. Para sa nawawalang appointment, kontakin ang City Health support sa medConnect o tawagan ang opisina.</p>',
                ],
                'hil' => [
                    '<p>Pasensya kon may error. Para sa nadula nga appointment ukon indi makita ang doctor, kontaka ang City Health support sa medConnect messages ukon tawaga ang opisina.</p>',
                ],
            ],
            'loneliness_no_one' => [
                'en' => [
                    '<p>Thank you for sharing that — feeling alone is hard. I\'m an automated assistant, not a replacement for human connection. For urgent emotional support: Hopeline <strong>1553</strong>. I can help with healthcare access on medConnect when you\'re ready.</p>',
                ],
                'fil' => [
                    '<p>Salamat sa pagbabahagi — mahirap ang pag-iisa. Para sa urgent support: Hopeline <strong>1553</strong>. Matutulungan kitang sa healthcare access sa medConnect.</p>',
                ],
                'hil' => [
                    '<p>Salamat sa pagpaambit — budlay kon wala may kaistoryahan. Para sa urgent support: Hopeline <strong>1553</strong>. Matabangan ko sa healthcare access sa medConnect kon ready ka.</p>',
                ],
            ],
            'uncertainty_worry' => [
                'en' => [
                    '<p>I hear the worry in “what now?” — let\'s take one small step. Tell me if this is about symptoms, booking, money, signal, or something else, and we\'ll go slowly together.</p>',
                    '<p>Okay, aton ni hinay-hinayon. Sige, buligan ta ikaw mahibaluan ang sunod nga himuon — one step at a time.</p>',
                ],
                'fil' => [
                    '<p>Naririnig ko ang kabalaka — isa-isang hakbang lang. Sabihin kung symptoms, booking, pera, o signal, at gagabayan kita.</p>',
                ],
                'hil' => [
                    '<p>Nabatian ko ang kabalaka — isa ka hakbang lang anay. Silinga kon symptoms, booking, kwarta, ukon signal, kag tuytuyan ta ini.</p>',
                    '<p>Okay, aton ni hinay-hinayon. Sige, buligan ta ikaw mahibaluan kon ano ang sunod nga himuon.</p>',
                ],
            ],
            'serious_distress' => [
                'en' => [
                    '<p><strong>I\'m really sorry you\'re going through this.</strong> If you feel unsafe or may harm yourself, please contact <strong>911</strong> or Hopeline <strong>1553</strong> now, or reach someone you trust. I\'m a medConnect assistant — not a therapist — but real help is available.</p>',
                ],
                'fil' => [
                    '<p><strong>Paumanhin sa pinagdadaanan mo.</strong> Kung nasa panganib, tawagan ang <strong>911</strong> o Hopeline <strong>1553</strong>, o makipag-ugnayan sa pinagkakatiwalaan mong tao.</p>',
                ],
                'hil' => [
                    '<p><strong>Pasensya gid sa imo gina-agi.</strong> Kon indi ka safe, tawagi ang <strong>911</strong> ukon Hopeline <strong>1553</strong> subong, ukon makigstorya sa trusted person. Assistant lang ako — may tawo nga makabulig.</p>',
                ],
            ],
            'checkup_anxiety' => [
                'en' => [
                    '<p>Many people feel nervous before a checkup — you\'re not alone. You can tell staff you feel anxious; short visits or video consult on medConnect may feel easier.</p>',
                ],
                'fil' => [
                    '<p>Marami ang kinakabahan bago magpa-check up. Sabihin sa staff na kinakabahan ka; video consult sa medConnect pwede mas madali.</p>',
                ],
                'hil' => [
                    '<p>Damo ang ginakulbaan antes magpa-check up — indi ka isa. Silinga sa staff nga ginakulbaan ka; video consult sa medConnect pwede mas sayon.</p>',
                ],
            ],
            'short_help_request' => [
                'en' => [
                    '<p>I\'m here — what do you need help with? Appointments, login, how you feel, video consult, or City Health contact?</p>',
                ],
                'fil' => [
                    '<p>Nandito ako — ano ang kailangan mo? Appointment, login, nararamdaman, video consult, o City Health?</p>',
                ],
                'hil' => [
                    '<p>Diri ako — ano ang imo kinahanglan? Appointment, login, pamatyag, video consult, ukon City Health contact?</p>',
                ],
            ],
            'reassurance_okay' => [
                'en' => [
                    '<p>I hope things feel a bit more okay soon. I\'m here if you need help with medConnect or City Health services.</p>',
                ],
                'fil' => [
                    '<p>Sana maging okay. Nandito ako kung kailangan mo ng tulong sa medConnect o City Health.</p>',
                ],
                'hil' => [
                    '<p>Hopefully maayo na gid. Diri ako kon kinahanglan mo sang bulig sa medConnect ukon City Health.</p>',
                ],
            ],
            'multi_access_barriers' => [
                'en' => [
                    '<p>That sounds like a lot at once — weather, connection, money, or travel can all block care. Let\'s sort it one piece at a time: video consult when signal allows, City Health programs for cost, and safe travel when weather improves. For emergencies, <strong>911</strong>.</p>',
                ],
                'fil' => [
                    '<p>Mukhang maraming problema sabay-sabay — panahon, signal, pera, o transport. Isa-isa lang: video consult, City Health programs, at safe travel. Emergency → <strong>911</strong>.</p>',
                ],
                'hil' => [
                    '<p>Daw damo nga problema sabay-sabay — panahon, signal, kwarta, ukon transport. Isa-isa lang: video consult kon may signal, City Health programs sa kwarta, kag safe travel kon maayo na ang panahon. Emergency → <strong>911</strong>.</p>',
                    '<p>Nabudlayan ka gid subong — gaulan, wala signal, wala kwarta. Hinay-hinay lang ta: tan-awon ta ang options sa medConnect kag City Health para makita paano ka makakuha sang care.</p>',
                ],
            ],
        ];
    }
}
