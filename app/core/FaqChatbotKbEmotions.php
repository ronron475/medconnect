<?php
/**
 * Emotional support + crisis knowledge pack (PHP only, non-clinical).
 */
final class FaqChatbotKbEmotions
{
    /** @return list<array<string, mixed>> */
    public static function scenarios(): array
    {
        return [
            [
                'key' => 'crisis_hopeless',
                'category' => 'crisis',
                'flow_key' => 'crisis',
                'weight' => 1.4,
                'patterns' => [
                    '/\b(want\s+to\s+die|kill\s+myself|suicide|end\s+my\s+life|no\s+reason\s+to\s+live|self[\s-]?harm|cut\s+myself)\b/ui',
                    '/\b(ayaw\s+ko\s+mabuhay|indi\s+ko\s+gusto\s+mabuhi|wala\s+(na\s+)?paglaum|magpakamatay|gusto\s+ko\s+mamatay)\b/ui',
                    '/\b(di\s+ko\s+na\s+kaya|wala\s+na\s+pulos|mag.?untat|gusto\s+ko\s+mawala|mas\s+maayo\s+pa\s+siguro\s+kung\s+wala\s+na\s+ko)\b/ui',
                ],
                'keywords' => ['hopeless', 'wala paglaum', 'suicide', 'self harm'],
            ],
            [
                'key' => 'panic_support',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.15,
                'patterns' => [
                    '/\b(panic\s+attack|having\s+a\s+panic|ginapanik|panicking|i\s+can\'?t\s+calm\s+down)\b/ui',
                ],
                'keywords' => ['panic', 'ginapanik', 'panicking'],
            ],
            [
                'key' => 'depression_support',
                'category' => 'mental_health',
                'flow_key' => 'distress_support',
                'weight' => 1.2,
                'patterns' => [
                    '/\b(depress(ed|ion)?|feeling\s+empty|no\s+interest|wala\s+gana\s+sa\s+tanan|bug-at\s+gid\s+ang\s+pamatyag)\b/ui',
                ],
                'keywords' => ['depressed', 'depression', 'feeling empty'],
            ],
            [
                'key' => 'burnout_support',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.12,
                'patterns' => [
                    '/\b(burnout|burned\s+out|burnt\s+out|kapoy\s+na\s+gid|drained|emotionally\s+exhausted)\b/ui',
                ],
                'keywords' => ['burnout', 'burned out', 'emotionally exhausted'],
            ],
            [
                'key' => 'school_stress',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.12,
                'patterns' => [
                    '/\b(school\s+stress|exam\s+stress|academic\s+stress|thesis|assignment\s+stress|stress\s+sa\s+eskwela|stress\s+sa\s+skwela)\b/ui',
                ],
                'keywords' => ['school stress', 'exam', 'thesis', 'eskwela', 'skwela'],
            ],
            [
                'key' => 'work_stress',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.12,
                'patterns' => [
                    '/\b(work\s+stress|job\s+stress|stressed\s+at\s+work|boss|overtime|stress\s+sa\s+obra|stress\s+sa\s+work)\b/ui',
                ],
                'keywords' => ['work stress', 'job stress', 'obra', 'overtime'],
            ],
            [
                'key' => 'relationship_problems',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(relationship\s+problem|break\s*up|broke\s+up|girlfriend|boyfriend|asawa|bana|problema\s+sa\s+relasyon)\b/ui',
                ],
                'keywords' => ['relationship', 'breakup', 'broke up', 'relasyon'],
            ],
            [
                'key' => 'homesickness',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(homesick|homesickness|miss\s+(my\s+)?(home|family|parents)|nahidlaw|miss\s+ko\s+ang\s+balay)\b/ui',
                ],
                'keywords' => ['homesick', 'homesickness', 'nahidlaw', 'miss home'],
            ],
            [
                'key' => 'low_motivation',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.08,
                'patterns' => [
                    '/\b(no\s+motivation|low\s+motivation|unmotivated|wala\s+(na\s+)?gana|indi\s+ko\s+gusto\s+maghimo)\b/ui',
                ],
                'keywords' => ['no motivation', 'low motivation', 'wala gana', 'unmotivated'],
            ],
            [
                'key' => 'grief_support',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.15,
                'patterns' => [
                    '/\b(grief|grieving|passed\s+away|died|namatay|naglubong|loss\s+of\s+(a\s+)?(loved\s+one|family))\b/ui',
                ],
                'keywords' => ['grief', 'grieving', 'passed away', 'namatay'],
            ],
            [
                'key' => 'crying_support',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.08,
                'patterns' => [
                    '/\b(i\s+am\s+crying|can\'?t\s+stop\s+crying|naga\s*hibi|naga\s*hilib|umiiyak)\b/ui',
                ],
                'keywords' => ['crying', 'naga hibi', 'naga hilib'],
            ],
            [
                'key' => 'financial_barrier',
                'category' => 'financial',
                'flow_key' => 'financial',
                'weight' => 1.2,
                'patterns' => [
                    '/\b(no\s+money|i\s+have\s+no\s+money|cannot\s+afford|can\'?t\s+afford|walang\s+pera|wala\s+(ko|ako)\s+kwarta|wala\s+kwarta|financial\s+problem|too\s+expensive|im\s+broke)\b/ui',
                ],
                'keywords' => ['wala ko kwarta', 'no money', 'cannot afford', 'financial'],
            ],
            [
                'key' => 'afraid_of_doctor',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.35,
                'patterns' => [
                    '/\b(afraid\s+of\s+(seeing\s+a\s+)?(the\s+)?doctor|scared\s+of\s+(the\s+)?(doctor|hospital)|fear\s+of\s+(doctors?|hospitals?)|nahadlok.{0,24}(doktor|ospital|hospital))\b/ui',
                ],
                'keywords' => ['afraid of doctor', 'nahadlok ko sa doktor', 'fear of hospital'],
            ],
            [
                'key' => 'family_problems',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(family\s+problem|problema\s+sa\s+(pamilya|familia)|away\s+sa\s+balay)\b/ui',
                ],
                'keywords' => ['family problem', 'problema sa pamilya'],
            ],
            [
                'key' => 'need_to_talk',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(need\s+someone\s+to\s+talk|just\s+need\s+to\s+talk|lonely|nag-iisa|isa\s+lang|wala\s+(ko|ako)\s+(makigstorya|maistoryahan))\b/ui',
                ],
                'keywords' => ['someone to talk', 'lonely', 'isa lang', 'need to talk'],
            ],
            [
                'key' => 'cant_sleep',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.12,
                'patterns' => [
                    '/\b(can\'?t\s+sleep|cannot\s+sleep|insomni|indi\s+(ko\s+)?ka\s*tulog|indi\s+ko\s+katulog|daw\s+indi\s+ko\s+ka\s*tulog)\b/ui',
                ],
                'keywords' => ["can't sleep", 'indi ko ka tulog', 'insomnia'],
            ],
            [
                'key' => 'stress_support',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.08,
                'patterns' => [
                    '/\b(so\s+stressed|stressed\s+out|grabeng\s+stress|stressed\s+gid|feeling\s+overwhelm|overwhelmed)\b/ui',
                ],
                'keywords' => ['stress', 'stressed', 'overwhelmed'],
            ],
            [
                'key' => 'anxiety_support',
                'category' => 'mental_health',
                'flow_key' => 'distress_support',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(anxiety|anxious|ginakulbaan|kulba|kinakabahan|kabado)\b/ui',
                ],
                'keywords' => ['anxiety', 'anxious', 'ginakulbaan'],
            ],
            [
                'key' => 'fear_support',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.05,
                'patterns' => [
                    '/\b(i\s+am\s+(so\s+)?(scared|afraid)|nahadlok\s+(gid\s+)?(ko|ako)|natakot\s+(ako|ko))\b(?!.*\b(doktor|doctor|ospital|hospital)\b)/ui',
                ],
                'keywords' => ['scared', 'afraid', 'nahadlok', 'fear'],
            ],
            [
                'key' => 'sadness_support',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.05,
                'patterns' => [
                    '/\b(i\s+(feel\s+)?sad|feeling\s+down|malungkot|kasubo|budlay\s+gid\s+pamatyagon)\b/ui',
                ],
                'keywords' => ['sad', 'kasubo', 'malungkot', 'budlay pamatyagon'],
            ],
            [
                'key' => 'uncertainty_support',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.08,
                'patterns' => [
                    '/\b(not\s+sure|uncertain|indi\s+ko\s+kabalo|indi\s+ko\s+bal-an|wala\s+ko\s+kasabot|wala\s+ko\s+kaintindi|hindi\s+ko\s+alam)\b/ui',
                ],
                'keywords' => ['not sure', 'indi ko kabalo', 'wala ko kasabot', 'uncertain'],
            ],
            [
                'key' => 'mixed_feelings',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(mixed\s+feelings|magkahalong|conflicted|pero\s+nahadlok|pero\s+kapoy|pero\s+sad|but\s+scared|but\s+afraid|but\s+tired)\b/ui',
                ],
                'keywords' => ['mixed feelings', 'pero nahadlok', 'but scared', 'magkahalong'],
            ],
            [
                'key' => 'embarrassment_support',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.06,
                'patterns' => [
                    '/\b(embarrass|nahihiya|nahuya|hiya\s+ko|ashamed|shame)\b/ui',
                ],
                'keywords' => ['embarrassed', 'nahuya', 'ashamed', 'nahihiya'],
            ],
            [
                'key' => 'boredom_support',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.05,
                'patterns' => [
                    '/\b(bored|boring|wala\s+gana|wala\s+ko\s+gana|walang\s+gana)\b/ui',
                ],
                'keywords' => ['bored', 'wala gana', 'boring'],
            ],
            [
                'key' => 'irritation_support',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.08,
                'patterns' => [
                    '/\b(irritat|so\s+annoying|lain\s+gid|lain\s+gid\s+ya|nainis|nakainis)\b/ui',
                ],
                'keywords' => ['irritated', 'lain gid', 'annoying', 'nainis'],
            ],
            [
                'key' => 'guilt_support',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.08,
                'patterns' => [
                    '/\b(guilty|guilt|may\s+guilt|kasalanan\s+ko|nagkasala)\b/ui',
                ],
                'keywords' => ['guilty', 'guilt', 'kasalanan', 'may guilt'],
            ],
            [
                'key' => 'shame_support',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.08,
                'patterns' => [
                    '/\b(ashamed|shame|nakahuya|nahuya\s+gid|huya\s+gid)\b/ui',
                ],
                'keywords' => ['ashamed', 'shame', 'nahuya', 'nakahuya'],
            ],
            [
                'key' => 'jealousy_support',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.06,
                'patterns' => [
                    '/\b(jealous|jealousy|naiinggit|inggit|selos)\b/ui',
                ],
                'keywords' => ['jealous', 'naiinggit', 'selos', 'inggit'],
            ],
            [
                'key' => 'social_anxiety',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(social\s+anxiety|scared\s+of\s+people|nahadlok\s+sa\s+(mga\s+)?tao|nahuya\s+sa\s+(mga\s+)?tao)\b/ui',
                ],
                'keywords' => ['social anxiety', 'scared of people', 'nahadlok sa tao'],
            ],
            [
                'key' => 'exam_anxiety',
                'category' => 'emotional_support',
                'flow_key' => 'distress_support',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(exam\s+anxiety|test\s+anxiety|kinabahan\s+sa\s+exam|kulba\s+sa\s+exam|stress\s+sa\s+exam)\b/ui',
                ],
                'keywords' => ['exam anxiety', 'kinabahan sa exam', 'test anxiety'],
            ],
            [
                'key' => 'mental_wellness',
                'category' => 'mental_health',
                'flow_key' => 'distress_support',
                'weight' => 1.05,
                'patterns' => [
                    '/\b(mental\s+health|mental\s+wellness|self[\s-]?care|mindfulness|emotional\s+wellness)\b/ui',
                ],
                'keywords' => ['mental health', 'mental wellness', 'self care'],
            ],
        ];
    }

    /** @return array<string, array{en: list<string>, fil: list<string>, hil: list<string>}> */
    public static function responses(): array
    {
        $crisis = [
            'en' => ['<p><strong>You matter.</strong> If you feel hopeless or unsafe, please reach trusted people and call <strong>911</strong> or Hopeline <strong>1553</strong> now. I\'m a caring medConnect assistant — not a crisis counselor. Real help is available.</p>'],
            'fil' => ['<p><strong>Mahalaga ka.</strong> Kung nasa panganib, tumawag sa <strong>911</strong> o Hopeline <strong>1553</strong>. Assistant lang ako — may taong makakatulong ngayon.</p>'],
            'hil' => ['<p><strong>Importante ka.</strong> Kon indi ka safe, tawagi ang <strong>911</strong> ukon Hopeline <strong>1553</strong>. Assistant lang ako — may mga tawo nga makabulig subong.</p>'],
        ];

        return [
            'crisis_hopeless' => $crisis,
            'panic_support' => [
                'en' => ['<p>I\'m with you. Try slow breaths: in 4, hold 4, out 6. If this may be a medical emergency (chest pain, can\'t breathe), call <strong>911</strong>. For emotional crisis: Hopeline <strong>1553</strong>.</p>'],
                'fil' => ['<p>Nandito ako. Huminga: 4-4-6. Emergency → <strong>911</strong>. Crisis → Hopeline <strong>1553</strong>.</p>'],
                'hil' => ['<p>Diri ako. Maghinay: 4-4-6. Emergency → <strong>911</strong>. Crisis → Hopeline <strong>1553</strong>.</p>'],
            ],
            'depression_support' => [
                'en' => [
                    '<p>I\'m sorry you\'re carrying this heaviness. You\'re not alone. I\'m not a therapist, but connecting with City Health or a mental health professional can help. If you feel unsafe, call <strong>911</strong> or Hopeline <strong>1553</strong>. I can also guide you to book a consult on medConnect.</p>',
                ],
                'fil' => [
                    '<p>Paumanhin sa mabigat na pakiramdam. Hindi ka nag-iisa. Hindi ako therapist — makakatulong ang City Health o propesyonal. Kung hindi ligtas, <strong>911</strong> / Hopeline <strong>1553</strong>.</p>',
                ],
                'hil' => [
                    '<p>Pasensya sa mabug-at nga pamatyag. Indi ka isa. Indi ako therapist — makabulig ang City Health ukon propesyonal. Kon indi safe, <strong>911</strong> / Hopeline <strong>1553</strong>.</p>',
                ],
            ],
            'burnout_support' => [
                'en' => ['<p>Burnout is a signal to slow down, not a personal failure. Rest when you can, set one small boundary today, and consider talking with a provider if exhaustion affects your health. I can help you explore a medConnect appointment when you\'re ready.</p>'],
                'fil' => ['<p>Ang burnout senyales na kailangan magpahinga. Magtakda ng maliit na boundary ngayon. Kung apektado ang kalusugan, magpa-consult — matutulungan kitang mag-book.</p>'],
                'hil' => ['<p>Ang burnout senyas nga kinahanglan magpahulay. Magbutang sang gamay nga boundary subong. Kon apektado ang kahimsog, magpa-consult — matabangan ko mag-book.</p>'],
            ],
            'school_stress' => [
                'en' => ['<p>School pressure is real. Break tasks into tiny steps, rest your eyes, and ask for help early. If stress turns into panic or hopelessness, please reach Hopeline <strong>1553</strong> or a trusted adult. I can guide medConnect booking if health symptoms appear.</p>'],
                'fil' => ['<p>Real ang pressure sa eskwela. Hatiin ang gawain, magpahinga, humingi ng tulong. Kung panic o hopeless, Hopeline <strong>1553</strong>.</p>'],
                'hil' => ['<p>Real ang pressure sa eskwela. Bahinon ang buluhaton, magpahulay, mangayo bulig. Kon panic ukon hopeless, Hopeline <strong>1553</strong>.</p>'],
            ],
            'work_stress' => [
                'en' => ['<p>Work stress can affect sleep and mood. Pause for a short breath, then plan one doable next step. If stress harms your health, a City Health consult via medConnect may help — I\'m not a workplace counselor, but I can guide care access.</p>'],
                'fil' => ['<p>Nakakaapekto ang work stress sa tulog at mood. Huminga muna, isa-isang hakbang. Kung apektado ang kalusugan, mag-consult sa City Health.</p>'],
                'hil' => ['<p>Ang work stress maka-apekto sa tulog kag mood. Magginhawa anay, isa ka hakbang. Kon apektado ang kahimsog, mag-consult sa City Health.</p>'],
            ],
            'relationship_problems' => [
                'en' => ['<p>Relationship pain is hard. I can listen and guide you to healthcare or City Health support, but I\'m not a relationship counselor. If you feel unsafe, leave the situation if possible and call <strong>911</strong> or trusted help.</p>'],
                'fil' => ['<p>Mahirap ang problema sa relasyon. Hindi ako counselor, pero matutulungan kita sa healthcare access. Kung hindi ligtas, <strong>911</strong>.</p>'],
                'hil' => ['<p>Budlay ang problema sa relasyon. Indi ako counselor, pero matabangan ko sa healthcare access. Kon indi safe, <strong>911</strong>.</p>'],
            ],
            'homesickness' => [
                'en' => ['<p>Missing home is a tender kind of stress. Stay connected with loved ones when you can, keep a small routine, and be kind to yourself. If sadness becomes overwhelming, Hopeline <strong>1553</strong> or a consult can help.</p>'],
                'fil' => ['<p>Natural ang homesick. Makipag-ugnayan sa pamilya, magkaroon ng routine. Kung sobra ang lungkot, Hopeline <strong>1553</strong>.</p>'],
                'hil' => ['<p>Natural ang nahidlaw sa balay. Maghambal sa pamilya, may routine. Kon sobra ang kasubo, Hopeline <strong>1553</strong>.</p>'],
            ],
            'low_motivation' => [
                'en' => ['<p>Low motivation happens — especially when you\'re tired or stressed. Try one tiny action (water, short walk, message a friend). If this lasts and affects daily life, consider booking a consult. I can guide medConnect steps.</p>'],
                'fil' => ['<p>Normal ang mababang gana minsan. Subukan ang maliit na aksyon. Kung matagal, mag-consult — gagabayan kita sa medConnect.</p>'],
                'hil' => ['<p>Normal ang wala gana kon kaisa. Tilawi ang gamay nga aksyon. Kon magdugay, mag-consult — ginagiyahan ko ikaw sa medConnect.</p>'],
            ],
            'grief_support' => [
                'en' => ['<p>I\'m truly sorry for your loss. Grief has no fixed timeline. Lean on trusted people, and seek professional support if grief feels unbearable. Hopeline <strong>1553</strong> can help; I can also guide healthcare access on medConnect.</p>'],
                'fil' => ['<p>Taos-pusong pakikiramay. Walang takdang oras ang lungkot. Humingi ng tulong sa pinagkakatiwalaan o Hopeline <strong>1553</strong>.</p>'],
                'hil' => ['<p>Nagakaluoy ako. Wala takdo nga oras ang kaguol. Mangayo bulig sa trusted people ukon Hopeline <strong>1553</strong>.</p>'],
            ],
            'crying_support' => [
                'en' => ['<p>It\'s okay to cry — your feelings are valid. I\'m here with you in this chat. When you\'re ready, we can find a small next step, or you can call Hopeline <strong>1553</strong> if you need a human voice now.</p>'],
                'fil' => ['<p>Okay umiyak — valid ang nararamdaman mo. Nandito ako. Kung kailangan ng tao, Hopeline <strong>1553</strong>.</p>'],
                'hil' => ['<p>Okay maghibi — valid ang imo nabatyagan. Diri ako. Kon kinahanglan sang tawo, Hopeline <strong>1553</strong>.</p>'],
            ],
            'financial_barrier' => [
                'en' => [
                    '<p>Worrying about cost is valid. City Health often has public services — ask about available programs. I can guide contact or booking on medConnect. Don\'t delay emergency care: call <strong>911</strong> if in danger.</p>',
                    '<p>Not having money for a checkup is stressful. You still deserve care. Inquire at City Health about public clinics. I can help with next steps on medConnect.</p>',
                ],
                'fil' => ['<p>Valid ang alalahanin sa gastos. Maraming pampublikong serbisyo ang City Health. Matutulungan kitang mag-inquire. Emergency → <strong>911</strong>.</p>'],
                'hil' => [
                    '<p>Valid ang kabalaka sa kwarta. May pampubliko nga serbisyo ang City Health. Matabangan ko ikaw mag-inquire sa medConnect. Emergency → <strong>911</strong>.</p>',
                    '<p>Wala kwarta para magpa-check up — mabudlay gid. Deserve mo gihapon sang care. Pamangkota ang City Health parte sa public clinics.</p>',
                ],
            ],
            'afraid_of_doctor' => [
                'en' => [
                    '<p>Many people feel nervous about doctors or hospitals — you\'re not alone. Providers are there to help. You can start with a short visit or video consult on medConnect and tell staff you feel anxious.</p>',
                ],
                'fil' => ['<p>Marami ang natatakot sa doktor/ospital — hindi ka nag-iisa. Puwedeng magsimula sa maikling visit o video consult sa medConnect.</p>'],
                'hil' => [
                    '<p>Damo ang nahadlok sa doktor/ospital — indi ka isa. Pwede ka magsugod sa mubo nga visit ukon video konsultasyon sa medConnect.</p>',
                ],
            ],
            'family_problems' => [
                'en' => ['<p>Family stress can weigh on health. I\'m sorry. I can help with healthcare access; for safety or counseling needs, reach trusted people, City Health, or Hopeline <strong>1553</strong>.</p>'],
                'fil' => ['<p>Nakakaapekto ang problema sa pamilya. Matutulungan kita sa medConnect; kung may panganib, Hopeline <strong>1553</strong>.</p>'],
                'hil' => ['<p>Ang problema sa pamilya makabug-at. Matabangan ko sa medConnect; kon may katalagman, Hopeline <strong>1553</strong>.</p>'],
            ],
            'need_to_talk' => [
                'en' => [
                    '<p>I\'m glad you reached out. I can listen and guide medConnect services, though I\'m an automated assistant. For a real person urgently, call Hopeline <strong>1553</strong> or visit City Health.</p>',
                ],
                'fil' => ['<p>Salamat sa pagsagot. Makikinig ako, pero automated assistant lang ako. Kung kailangan ng tao: Hopeline <strong>1553</strong>.</p>'],
                'hil' => ['<p>Nalipay ako nga nag-abot ka. Makapamati ako, pero automated assistant lang ako. Kinahanglan sang tawo: Hopeline <strong>1553</strong>.</p>'],
            ],
            'cant_sleep' => [
                'en' => ['<p>Trouble sleeping is common when stressed. Try a quieter wind-down and less late caffeine. If it continues, book a consult — I cannot diagnose sleep disorders. Severe symptoms → <strong>911</strong>.</p>'],
                'fil' => ['<p>Mahirap matulog kung stressed. Bawasan ang kape sa gabi. Kung tuloy-tuloy, mag-book — hindi ako nagda-diagnose.</p>'],
                'hil' => ['<p>Budlay gid kon indi ka katulog. Less caffeine sa gab-i. Kon magpadayon, mag-book sa medConnect — indi ako makadiagnose.</p>'],
            ],
            'stress_support' => [
                'en' => [
                    '<p>I hear that things feel heavy. You\'re not alone — take a slow breath, then one small next step. I can guide appointments or video consult on medConnect. I cannot diagnose, but care is available.</p>',
                ],
                'fil' => ['<p>Naririnig ko na mabigat. Hindi ka nag-iisa. Huminga, maliit na hakbang. Matutulungan kitang mag-book sa medConnect.</p>'],
                'hil' => [
                    '<p>Nabatian ko nga mabug-at. Indi ka isa. Maghinay magginhawa, gamay nga hakbang. Matabangan ko mag-book sa medConnect.</p>',
                ],
            ],
            'anxiety_support' => [
                'en' => ['<p>Anxiety can feel scary. Try grounding: 5 things you see, 4 you touch, 3 you hear. When ready, book a consult. Urgent distress → <strong>911</strong> / Hopeline <strong>1553</strong>.</p>'],
                'fil' => ['<p>Natural ang kabahan. Grounding: 5-4-3. Mag-book kapag handa. Urgent → <strong>911</strong> / <strong>1553</strong>.</p>'],
                'hil' => ['<p>Natural ang ginakulbaan. Grounding: 5-4-3. Mag-book kon ready. Urgent → <strong>911</strong> / <strong>1553</strong>.</p>'],
            ],
            'fear_support' => [
                'en' => ['<p>It\'s okay to feel afraid. I\'m here to guide you gently — booking a visit or learning City Health options. You set the pace.</p>'],
                'fil' => ['<p>Okay lang matakot. Nandito ako para gabayan ka nang mahinahon.</p>'],
                'hil' => ['<p>Okay lang mahadlok. Diri ako para giyahan ka — ikaw ang magdesisyon sang tempo.</p>'],
            ],
            'sadness_support' => [
                'en' => [
                    '<p>I\'m sorry things feel hard. Thank you for sharing. You deserve support — Hopeline <strong>1553</strong> or a medConnect consult can help when you\'re ready.</p>',
                ],
                'fil' => ['<p>Paumanhin na mabigat. Karapat-dapat kang suportahan — Hopeline <strong>1553</strong> o consult sa medConnect.</p>'],
                'hil' => [
                    '<p>Pasensya nga budlay ang pamatyagon. May kinahanglan ka nga support — Hopeline <strong>1553</strong> ukon consult sa medConnect.</p>',
                ],
            ],
            'mental_wellness' => [
                'en' => ['<p>Mental wellness matters as much as physical health: sleep, movement, connection, and asking for help early. For personal concerns, please consult a qualified professional. I can guide medConnect booking — I don\'t provide therapy.</p>'],
                'fil' => ['<p>Mahalaga ang mental wellness: tulog, galaw, koneksyon, at humingi ng tulong. Para sa personal na usapin, magpatingin sa propesyonal.</p>'],
                'hil' => ['<p>Importante ang mental wellness: tulog, movement, koneksyon, kag mangayo bulig. Para sa personal nga concern, magpa-check sa propesyonal.</p>'],
            ],
            'uncertainty_support' => [
                'en' => ['<p>It\'s okay not to know everything right away. I can explain medConnect step by step — no pressure, and you set the pace.</p>'],
                'fil' => ['<p>Okay lang hindi alam lahat agad. Ipapaliwanag ko ang medConnect hakbang-hakbang — walang pressure.</p>'],
                'hil' => ['<p>Okay lang indi mahibal-an tanan dayon. Ipahayag ko ang medConnect pahuway-pahuway — wala pressure.</p>'],
            ],
            'mixed_feelings' => [
                'en' => ['<p>Mixed feelings make sense — you can feel more than one thing at once. Take a breath. When you\'re ready, we can find one small next step or connect you with care on medConnect.</p>'],
                'fil' => ['<p>Natural ang magkahalong damdamin. Huminga muna. Kapag handa ka, makakahanap tayo ng maliit na hakbang o care sa medConnect.</p>'],
                'hil' => ['<p>Natural ang magkahalong pamatyag. Magginhawa anay. Kon ready ka, makita ta ang gamay nga sunod nga hakbang ukon care sa medConnect.</p>'],
            ],
            'embarrassment_support' => [
                'en' => ['<p>Many people feel shy or embarrassed about health topics — that\'s normal. This is a safe space to ask questions. I\'m an automated assistant, not a judge, and City Health staff are trained to help respectfully.</p>'],
                'fil' => ['<p>Marami ang nahihiya sa health topics — normal iyan. Safe space ito para magtanong. Hindi ako humuhusga — ang City Health staff ay trained na tumulong nang may respeto.</p>'],
                'hil' => ['<p>Damo ang mahuya sa health topics — normal sina. Safe space ini para magpamangkot. Indi ako maghuhukom — ang City Health staff trained nga magbulig sing may respeto.</p>'],
            ],
            'boredom_support' => [
                'en' => ['<p>Low motivation happens, especially when you\'re tired or stressed. A short walk, water, or rest can help. If low mood lasts and affects daily life, consider a consult — I can guide medConnect booking.</p>'],
                'fil' => ['<p>Normal ang mababang gana minsan. Subukan ang maliit na pahinga. Kung matagal, mag-consult — matutulungan kitang mag-book.</p>'],
                'hil' => ['<p>Normal ang wala gana kon kaisa. Tilawi ang gamay nga pahuway. Kon magdugay, mag-consult — matabangan ko mag-book.</p>'],
            ],
            'irritation_support' => [
                'en' => ['<p>I hear that something feels irritating right now. Let\'s tackle one thing at a time — I can help with practical medConnect steps when you\'re ready.</p>'],
                'fil' => ['<p>Naririnig ko na nakakainis ngayon. Isa-isang hakbang lang — matutulungan kitang sa praktikal na medConnect steps.</p>'],
                'hil' => ['<p>Nabatian ko nga makalain subong. Isa ka hakbang lang — matabangan ko sa praktikal nga medConnect steps kon ready ka.</p>'],
            ],
            'guilt_support' => [
                'en' => ['<p>Guilt can feel heavy — you\'re not alone in that. I\'m not a counselor, but I can guide you to healthcare or City Health support when you\'re ready.</p>'],
                'fil' => ['<p>Mabigat ang guilt — hindi ka nag-iisa. Hindi ako counselor, pero matutulungan kitang sa healthcare o City Health.</p>'],
                'hil' => ['<p>Mabug-at ang guilt — indi ka isa. Indi ako counselor, pero matabangan ko sa healthcare ukon City Health.</p>'],
            ],
            'shame_support' => [
                'en' => ['<p>Shame is hard, especially around health. Many people feel this way — City Health staff are trained to help respectfully. I can guide medConnect booking without judgment.</p>'],
                'fil' => ['<p>Mahirap ang shame lalo na sa health. Marami ang nakakaramdam nito — ang City Health staff ay trained na tumulong nang may respeto.</p>'],
                'hil' => ['<p>Budlay ang shame lalo na sa health. Damo ang amo sini — ang City Health staff trained nga magbulig sing may respeto.</p>'],
            ],
            'jealousy_support' => [
                'en' => ['<p>Jealous or envious feelings are valid. If they\'re affecting your mood or health, talking with a provider via medConnect may help — I can guide the steps.</p>'],
                'fil' => ['<p>Valid ang jealousy o inggit. Kung naaapektuhan ang mood o kalusugan, makakatulong ang consult sa medConnect.</p>'],
                'hil' => ['<p>Valid ang jealousy ukon inggit. Kon maka-apekto sa mood ukon kahimsog, makabulig ang consult sa medConnect.</p>'],
            ],
            'social_anxiety' => [
                'en' => ['<p>Feeling nervous around others is common. You can start with a short video consult on medConnect if that feels easier — you set the pace.</p>'],
                'fil' => ['<p>Normal ang kabahan sa ibang tao. Puwede kang magsimula sa maikling video consult sa medConnect — ikaw ang magdesisyon ng tempo.</p>'],
                'hil' => ['<p>Normal ang kabahan sa iban nga tawo. Pwede ka magsugod sa mubo nga video consult sa medConnect — ikaw ang magdesisyon sang tempo.</p>'],
            ],
            'exam_anxiety' => [
                'en' => ['<p>Exam stress is real. Break study into small chunks, rest, and ask for help early. If anxiety feels overwhelming, Hopeline <strong>1553</strong> or a consult can help.</p>'],
                'fil' => ['<p>Real ang exam stress. Hatiin ang pag-aaral, magpahinga, humingi ng tulong. Kung sobra ang anxiety, Hopeline <strong>1553</strong>.</p>'],
                'hil' => ['<p>Real ang exam stress. Bahinon ang pagtuon, magpahulay, mangayo bulig. Kon sobra ang anxiety, Hopeline <strong>1553</strong>.</p>'],
            ],
        ];
    }
}
