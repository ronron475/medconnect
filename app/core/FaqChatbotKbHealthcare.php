<?php
/**
 * Healthcare information + emergency knowledge pack (no diagnosis / no prescriptions).
 */
final class FaqChatbotKbHealthcare
{
    /** @return list<array<string, mixed>> */
    public static function scenarios(): array
    {
        return [
            [
                'key' => 'emergency_redirect',
                'category' => 'emergency',
                'flow_key' => 'emergency',
                'weight' => 1.35,
                'patterns' => [
                    '/\b(can\'?t\s+breathe|cannot\s+breathe|chest\s+pain|heart\s+attack|stroke|unconscious|severe\s+bleeding|heavy\s+bleeding|seizure|poisoning|anaphyla|severe\s+allerg)\b/ui',
                    '/\b(indi\s+makahinga|indi\s+makaginhawa|sakit\s+(ang\s+)?dughan|gapalanakit\s+dughan|grabeng\s+dugo|lason|nalason)\b/ui',
                ],
                'keywords' => ['911', 'emergency', 'er', 'katalagman', 'stroke', 'seizure'],
            ],
            [
                'key' => 'first_aid',
                'category' => 'healthcare',
                'flow_key' => 'policy',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(first\s*aid|firstaid|paano\s+mag\s*first\s*aid|emergency\s+aid|bandage|burn\s+care)\b/ui',
                ],
                'keywords' => ['first aid', 'firstaid', 'bandage'],
            ],
            [
                'key' => 'healthy_lifestyle',
                'category' => 'healthcare',
                'flow_key' => 'policy',
                'weight' => 1.05,
                'patterns' => [
                    '/\b(healthy\s+lifestyle|healthy\s+habits|mag\s*healthy|prevention|preventive\s+care)\b/ui',
                ],
                'keywords' => ['healthy lifestyle', 'prevention', 'preventive'],
            ],
            [
                'key' => 'nutrition',
                'category' => 'healthcare',
                'flow_key' => 'policy',
                'weight' => 1.08,
                'patterns' => [
                    '/\b(nutrition|diet|healthy\s+food|what\s+to\s+eat|pagkaon|nutrisyon)\b/ui',
                ],
                'keywords' => ['nutrition', 'diet', 'healthy food', 'pagkaon'],
            ],
            [
                'key' => 'exercise',
                'category' => 'healthcare',
                'flow_key' => 'policy',
                'weight' => 1.05,
                'patterns' => [
                    '/\b(exercise|workout|physical\s+activity|ehersisyo|mag\s*exercise)\b/ui',
                ],
                'keywords' => ['exercise', 'workout', 'ehersisyo'],
            ],
            [
                'key' => 'vaccinations',
                'category' => 'healthcare',
                'flow_key' => 'services',
                'weight' => 1.12,
                'patterns' => [
                    '/\b(vaccinations?|vaccines?|immuniz(?:e|ation|ations)?|bakuna|booster)\b/ui',
                ],
                'keywords' => ['vaccine', 'vaccination', 'vaccinations', 'bakuna', 'immunization'],
            ],
            [
                'key' => 'pregnancy',
                'category' => 'healthcare',
                'flow_key' => 'services',
                'weight' => 1.25,
                'patterns' => [
                    '/\b(pregnan(?:t|cy)|buntis|prenatal\s+check|expecting|maternity)\b/ui',
                ],
                'keywords' => ['pregnant', 'pregnancy', 'buntis', 'prenatal'],
            ],
            [
                'key' => 'womens_health',
                'category' => 'healthcare',
                'flow_key' => 'services',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(women\'?s\s+health|maternal|ob\s*-?\s*gyne|pap\s*smear|reproductive\s+health)\b/ui',
                ],
                'keywords' => ["women's health", 'maternal', 'ob-gyne'],
            ],
            [
                'key' => 'childrens_health',
                'category' => 'healthcare',
                'flow_key' => 'services',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(child(ren)?\'?s\s+health|pediatric|baby\s+health|bata|child\s+care|well[\s-]?baby)\b/ui',
                ],
                'keywords' => ['children', 'pediatric', 'baby health', 'well baby'],
            ],
            [
                'key' => 'senior_health',
                'category' => 'healthcare',
                'flow_key' => 'services',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(senior\s+health|elderly|geriatric|senior\s+citizen|tiguwang|elderly\s+care)\b/ui',
                ],
                'keywords' => ['senior', 'elderly', 'geriatric', 'senior citizen'],
            ],
            [
                'key' => 'common_illness',
                'category' => 'healthcare',
                'flow_key' => 'pain_sick',
                'weight' => 1.05,
                'patterns' => [
                    '/\b(common\s+cold|flu|sipon|ubo|lagnat|hilanat|dengue|hypertension|diabetes)\b/ui',
                ],
                'keywords' => ['cold', 'flu', 'sipon', 'dengue', 'diabetes', 'hypertension'],
            ],
            [
                'key' => 'worry_symptoms',
                'category' => 'symptoms',
                'flow_key' => 'pain_sick',
                'weight' => 1.12,
                'patterns' => [
                    '/\b(worried\s+about\s+(my\s+)?symptom|ginasakit|gasakit|budlay\s+gid\s+pamatyagon)\b/ui',
                ],
                'keywords' => ['ginasakit', 'worried about symptoms', 'pamatyagon'],
            ],
            [
                'key' => 'symptoms_general',
                'category' => 'symptoms',
                'flow_key' => 'pain_sick',
                'weight' => 1.05,
                'patterns' => [
                    '/\b(headache|sakit\s+ulo|ginasakit\s+ulo|fever|hilanat|lagnat|stomach\s+ache|sakit\s+tiyan|symptom)\b/ui',
                ],
                'keywords' => ['sakit ulo', 'headache', 'symptom', 'masakit', 'hilanat'],
            ],
            [
                'key' => 'health_education',
                'category' => 'health_education',
                'flow_key' => 'policy',
                'weight' => 1.0,
                'patterns' => [
                    '/\b(what\s+is\s+medconnect|about\s+medconnect|ano\s+ang\s+medconnect|health\s+tip)\b/ui',
                ],
                'keywords' => ['medconnect', 'health tip', 'education'],
            ],
        ];
    }

    /** @return array<string, array{en: list<string>, fil: list<string>, hil: list<string>}> */
    public static function responses(): array
    {
        return [
            'emergency_redirect' => [
                'en' => ['<p><strong>This may be urgent.</strong> Call <strong>911</strong> or go to the nearest ER now. Do not wait for chat. I cannot treat emergencies online.</p>'],
                'fil' => ['<p><strong>Maaaring emergency ito.</strong> Tumawag sa <strong>911</strong> o pumunta sa ER ngayon.</p>'],
                'hil' => ['<p><strong>Basi emergency ini.</strong> Tawagi ang <strong>911</strong> ukon kadto sa ER dayon. Indi maghulat sa chat.</p>'],
            ],
            'first_aid' => [
                'en' => ['<p>For basic first aid: ensure scene safety, call for help if severe, control bleeding with firm pressure, and do not move someone with a possible neck/spine injury. This is general guidance only — for serious injuries call <strong>911</strong>. City Health can teach first-aid basics in person.</p>'],
                'fil' => ['<p>Basic first aid: tiyakin ang kaligtasan, tumawag kung malala, pigilan ang pagdurugo nang mahigpit. Hindi ito training certificate — emergency → <strong>911</strong>.</p>'],
                'hil' => ['<p>Basic first aid: seguruhon ang safety, tawag kon grabe, pugan ang dugo. General guidance lang — emergency → <strong>911</strong>.</p>'],
            ],
            'healthy_lifestyle' => [
                'en' => ['<p>Helpful habits: regular sleep, balanced meals, movement you enjoy, less tobacco/alcohol, and routine checkups. Preventive care through City Health can catch issues early — I can guide booking on medConnect.</p>'],
                'fil' => ['<p>Mabuting gawi: sapat na tulog, balanseng pagkain, ehersisyo, less bisyo, at regular checkups sa City Health.</p>'],
                'hil' => ['<p>Maayo nga gawi: sapat nga tulog, balanced nga pagkaon, movement, less bisyo, kag regular checkups sa City Health.</p>'],
            ],
            'nutrition' => [
                'en' => ['<p>General nutrition tips: more vegetables/fruits, enough water, limit sugary drinks, and include protein. Special diets (diabetes, pregnancy, allergies) need a licensed professional — I cannot prescribe meal plans.</p>'],
                'fil' => ['<p>General tip: gulay/prutas, tubig, bawasan ang softdrinks, may protein. Special diet → konsultahin ang propesyonal.</p>'],
                'hil' => ['<p>General tip: utan/prutas, tubig, less softdrinks, may protein. Special diet → magpa-check sa propesyonal.</p>'],
            ],
            'exercise' => [
                'en' => ['<p>Most adults benefit from regular movement — walking counts. Start gently if you\'re new or have conditions, and ask a provider before intense programs. Stop and seek care for chest pain or severe shortness of breath during activity.</p>'],
                'fil' => ['<p>Makakatulong ang regular na lakad. Mag-umpisa nang banayad; magtanong sa provider bago mag-intense. Chest pain → huminto at humingi ng tulong.</p>'],
                'hil' => ['<p>Makabulig ang regular nga walk. Magsugod sing mahinay; pamangkota ang provider antes intense. Chest pain → mag-untat kag mangayo bulig.</p>'],
            ],
            'vaccinations' => [
                'en' => ['<p>Vaccines help prevent serious illness. For schedules (children, pregnancy, seniors, boosters), please ask City Health or book through medConnect — recommendations depend on age and history. I cannot prescribe vaccines here.</p>'],
                'fil' => ['<p>Nakakatulong ang bakuna. Para sa schedule, itanong sa City Health o mag-book sa medConnect — depende sa edad at history.</p>'],
                'hil' => ['<p>Makabulig ang bakuna. Para sa schedule, pamangkota ang City Health ukon mag-book sa medConnect.</p>'],
            ],
            'womens_health' => [
                'en' => ['<p>Women\'s health services may include maternal care, reproductive health counseling, and screenings via City Health. For personal concerns, please book a consult — I share general info only and never diagnose.</p>'],
                'fil' => ['<p>May maternal/reproductive services ang City Health. Para sa personal na usapin, mag-book ng consult — general info lang ang maibibigay ko.</p>'],
                'hil' => ['<p>May maternal/reproductive services ang City Health. Para sa personal nga concern, mag-book sang consult.</p>'],
            ],
            'childrens_health' => [
                'en' => ['<p>Children\'s health often includes growth checks, vaccines, and sick visits. For fever in infants or breathing trouble, seek urgent care. I can guide you toward booking — I cannot diagnose your child.</p>'],
                'fil' => ['<p>Kasama sa child health ang checkups at bakuna. Para sa sanggol na may lagnat o hirap huminga, agarang care. Hindi ako nagda-diagnose.</p>'],
                'hil' => ['<p>Parte sang child health ang checkups kag bakuna. Para sa baby nga may hilanat ukon budlay magginhawa, agarang care.</p>'],
            ],
            'senior_health' => [
                'en' => ['<p>Senior care focuses on chronic disease follow-up, mobility, vaccines, and medication safety with a provider. Regular checkups help. Use medConnect to schedule when available — bring a companion if helpful.</p>'],
                'fil' => ['<p>Mahalaga ang regular checkup ng seniors para sa chronic conditions at vaccines. Mag-schedule sa medConnect kung available.</p>'],
                'hil' => ['<p>Importante ang regular checkup sang seniors. Mag-schedule sa medConnect kon available.</p>'],
            ],
            'pregnancy' => [
                'en' => ['<p>Pregnancy care is important early. Please book prenatal services with City Health/medConnect rather than relying on chat. Seek urgent care for severe pain, heavy bleeding, or reduced fetal movement. I cannot provide prenatal diagnosis online.</p>'],
                'fil' => ['<p>Mahalaga ang prenatal care. Mag-book sa City Health/medConnect. Emergency signs (grabeng sakit, mabigat na dugo) → agarang care.</p>'],
                'hil' => ['<p>Importante ang prenatal care. Mag-book sa City Health/medConnect. Emergency signs → agarang care.</p>'],
            ],
            'common_illness' => [
                'en' => ['<p>For common illnesses, rest and fluids may help mild cases, but worsening fever, breathing difficulty, or dehydration needs a provider. I cannot diagnose dengue/flu/diabetes here — please book an assessment when concerned.</p>'],
                'fil' => ['<p>Para sa karaniwang sakit, pahinga at tubig ay makakatulong sa banayad na kaso. Kung lumala, magpatingin — hindi ako nagda-diagnose.</p>'],
                'hil' => ['<p>Para sa ordinaryo nga sakit, pahulay kag tubig makabulig sa mild cases. Kon maglala, magpa-check — indi ako nagadiagnose.</p>'],
            ],
            'worry_symptoms' => [
                'en' => [
                    '<p>It\'s natural to worry about symptoms. I can help you book an appointment or video consult. <strong>I cannot diagnose.</strong> Severe chest pain, trouble breathing, or fainting → call <strong>911</strong>.</p>',
                ],
                'fil' => ['<p>Natural mag-alala sa sintomas. Matutulungan kitang mag-book — <strong>hindi ako makakapag-diagnose</strong>. Malala → <strong>911</strong>.</p>'],
                'hil' => [
                    '<p>Natural ang kabalaka sa sintomas. Matabangan ko mag-book — <strong>indi ako makadiagnose</strong>. Grabe → <strong>911</strong>.</p>',
                ],
            ],
            'symptoms_general' => [
                'en' => ['<p>I\'m sorry you\'re not feeling well. Rest and fluids may help mild illness, but only a provider can evaluate symptoms. Want help booking on medConnect?</p>'],
                'fil' => ['<p>Paumanhin sa hindi magandang pakiramdam. Provider ang makakapag-evaluate. Gusto mo bang tulungan kitang mag-book?</p>'],
                'hil' => ['<p>Pasensya nga indi ka maayo. Provider ang makasusi. Gusto mo matabangan ko mag-book sa medConnect?</p>'],
            ],
            'health_education' => [
                'en' => ['<p>medConnect helps residents connect with City Health — appointments, records, and consultations. For personal medical advice, see a licensed provider. I share general guidance only and never diagnose.</p>'],
                'fil' => ['<p>Ang medConnect ay tumutulong makipag-ugnayan sa City Health. Para sa personal na payo, magpatingin sa lisensyadong provider.</p>'],
                'hil' => ['<p>Ang medConnect nagabulig makakonekta sa City Health. Para sa personal nga advice, magpa-check sa licensed provider.</p>'],
            ],
        ];
    }
}
