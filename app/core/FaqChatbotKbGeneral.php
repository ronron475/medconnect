<?php
/**
 * General conversation knowledge pack (greetings, identity, small talk, clarification).
 */
final class FaqChatbotKbGeneral
{
    /** @return list<array<string, mixed>> */
    public static function scenarios(): array
    {
        return [
            [
                'key' => 'identity',
                'category' => 'general',
                'flow_key' => 'welcome',
                'weight' => 1.2,
                'patterns' => [
                    '/\b(who\s+are\s+you|what\s+are\s+you|are\s+you\s+(a\s+)?(bot|robot|ai|human)|ano\s+ka|sino\s+ka)\b/ui',
                ],
                'keywords' => ['who are you', 'what are you', 'are you a bot', 'sino ka'],
            ],
            [
                'key' => 'capabilities',
                'category' => 'general',
                'flow_key' => 'services',
                'weight' => 1.15,
                'patterns' => [
                    '/\b(what\s+can\s+you\s+do|how\s+can\s+you\s+help|ano\s+ang\s+kaya\s+mo|paano\s+ka\s+makabulig|your\s+features)\b/ui',
                ],
                'keywords' => ['what can you do', 'how can you help', 'features', 'capabilities'],
            ],
            [
                'key' => 'small_talk',
                'category' => 'general',
                'flow_key' => 'welcome',
                'weight' => 1.0,
                'patterns' => [
                    '/\b(how\s+are\s+you|kamusta\s+ka|kumusta\s+ka|musta\s+ka|are\s+you\s+ok)\b/ui',
                ],
                'keywords' => ['how are you', 'kamusta ka', 'musta ka'],
            ],
            [
                'key' => 'apology',
                'category' => 'general',
                'flow_key' => 'welcome',
                'weight' => 1.05,
                'patterns' => [
                    '/\b(sorry|i\s+apologize|pasensya|paumanhin|my\s+bad)\b/ui',
                ],
                'keywords' => ['sorry', 'pasensya', 'paumanhin'],
            ],
            [
                'key' => 'clarification',
                'category' => 'general',
                'flow_key' => 'clarify',
                'weight' => 1.1,
                'patterns' => [
                    '/\b(i\s+don\'?t\s+understand|indi\s+ko\s+maintindihan|ano\s+ang\s+ibig\s+sabihin|can\s+you\s+explain|explain\s+again|uliti|liwat)\b/ui',
                ],
                'keywords' => ["don't understand", 'explain', 'indi ko maintindihan', 'clarify'],
            ],
            [
                'key' => 'greeting',
                'category' => 'greetings',
                'flow_key' => 'welcome',
                'weight' => 1.0,
                'patterns' => [
                    '/^(hi|hello|hey|good\s+(morning|afternoon|evening)|kumusta|musta|maayong\s+(aga|hapon|gab-i))\b/ui',
                ],
                'keywords' => ['hello', 'hi', 'kumusta', 'maayong'],
            ],
            [
                'key' => 'thank_you',
                'category' => 'greetings',
                'flow_key' => 'gratitude',
                'weight' => 1.05,
                'patterns' => [
                    '/\b(thank\s*you|thanks|salamat(\s+gid)?|maraming\s+salamat|damo\s+nga\s+salamat)\b/ui',
                ],
                'keywords' => ['thank you', 'thanks', 'salamat'],
            ],
            [
                'key' => 'goodbye',
                'category' => 'greetings',
                'flow_key' => 'welcome',
                'weight' => 1.05,
                'patterns' => [
                    '/\b(goodbye|bye\b|see\s+you|paalam|hangtod\s+sa\s+liwat|kita\s+ta)\b/ui',
                ],
                'keywords' => ['goodbye', 'bye', 'paalam'],
            ],
            [
                'key' => 'introduction',
                'category' => 'general',
                'flow_key' => 'welcome',
                'weight' => 1.05,
                'patterns' => [
                    '/\b(my\s+name\s+is|i\s+am\s+\w+|ako\s+si|ngalan\s+ko)\b/ui',
                ],
                'keywords' => ['my name is', 'ako si', 'ngalan ko'],
            ],
        ];
    }

    /** @return array<string, array{en: list<string>, fil: list<string>, hil: list<string>}> */
    public static function responses(): array
    {
        return [
            'identity' => [
                'en' => [
                    '<p>I\'m the <strong>medConnect Assistant</strong> — a caring, rule-based helper for Bagó City Health services. I\'m not a doctor or a human therapist. I can guide you through appointments, accounts, and general health information safely.</p>',
                    '<p>I\'m medConnect\'s healthcare assistant chatbot. I use guided rules (not external AI) to help with services and supportive next steps — always encouraging professional care when needed.</p>',
                ],
                'fil' => [
                    '<p>Ako ang <strong>medConnect Assistant</strong> — gabay para sa City Health. Hindi ako doktor o therapist. Matutulungan kita sa appointments, account, at pangkalahatang impormasyon sa kalusugan.</p>',
                ],
                'hil' => [
                    '<p>Ako ang <strong>medConnect Assistant</strong> — bulig para sa City Health. Indi ako doktor ukon therapist. Matabangan ko ikaw sa appointments, account, kag general nga health guidance.</p>',
                ],
            ],
            'capabilities' => [
                'en' => [
                    '<p>I can help with: <strong>appointments</strong>, <strong>video consultation</strong>, <strong>AI Triage info</strong>, <strong>records</strong>, <strong>login/register/password</strong>, <strong>City Health contact</strong>, general wellness tips, and supportive listening. I never diagnose or prescribe. What do you need?</p>',
                ],
                'fil' => [
                    '<p>Matutulungan kita sa: appointments, video consult, AI Triage info, records, login/register/password, City Health contact, at supportive guidance. Hindi ako nagda-diagnose. Ano ang kailangan mo?</p>',
                ],
                'hil' => [
                    '<p>Matabangan ko ikaw sa: appointments, video konsultasyon, AI Triage info, records, login/register/password, City Health contact, kag supportive guidance. Indi ako nagadiagnose. Ano ang imo kinahanglan?</p>',
                ],
            ],
            'small_talk' => [
                'en' => [
                    '<p>I\'m here and ready to help. How are <em>you</em> feeling today — and is there anything about medConnect or your health I can guide you with?</p>',
                    '<p>Doing well, thank you for asking. I\'m focused on helping you. What would be useful right now?</p>',
                ],
                'fil' => [
                    '<p>Nandito ako at handang tumulong. Ikaw, kamusta — may maitutulong ba ako sa medConnect o kalusugan?</p>',
                ],
                'hil' => [
                    '<p>Diri ako kag ready magbulig. Ikaw, kumusta — may matabangan ko bala sa medConnect ukon kahimsog?</p>',
                ],
            ],
            'apology' => [
                'en' => [
                    '<p>No worries at all — thank you for saying that. We can start fresh. How can I help you with medConnect?</p>',
                ],
                'fil' => [
                    '<p>Walang problema — salamat. Magsimula tayo ulit. Paano kita matutulungan?</p>',
                ],
                'hil' => [
                    '<p>Wala problema — salamat. Magsugod liwat kita. Paano ko ikaw matabangan?</p>',
                ],
            ],
            'clarification' => [
                'en' => [
                    '<p>Happy to clarify. Tell me which part was unclear — booking, login, symptoms guidance, or something else — and I\'ll explain in simpler steps.</p>',
                    '<p>Of course. You can rephrase your question, or pick a topic: appointments, accounts, video consult, or how you\'re feeling.</p>',
                ],
                'fil' => [
                    '<p>Oo, ipapaliwanag ko. Aling parte ang malabo — booking, login, sintomas, o iba? Simpleng hakbang lang.</p>',
                ],
                'hil' => [
                    '<p>Sige, ipahayag ko. Ano nga parte ang indi klaro — booking, login, sintomas, ukon iba? Simple nga hakbang lang.</p>',
                ],
            ],
            'introduction' => [
                'en' => [
                    '<p>Nice to meet you. I\'m the medConnect Assistant. I\'m glad you\'re here — what would you like help with today?</p>',
                ],
                'fil' => [
                    '<p>Ikinagagalak kitang makilala. Ako ang medConnect Assistant. Ano ang maitutulong ko ngayon?</p>',
                ],
                'hil' => [
                    '<p>Nalipay ako makilala ka. Ako ang medConnect Assistant. Ano ang matabangan ko subong?</p>',
                ],
            ],
            'greeting' => [
                'en' => [
                    '<p>Hello! I\'m the medConnect Assistant. I can help with appointments, registration, login, video consultation, and City Health questions. How are you feeling today?</p>',
                    '<p>Hi there — welcome to medConnect. I\'m here to guide you calmly. What would you like help with?</p>',
                    '<p>Good day! Ask me about booking, accounts, wellness tips, or getting care through City Health.</p>',
                ],
                'fil' => [
                    '<p>Kumusta! Ako ang medConnect Assistant. Matutulungan kita sa appointments, rehistro, login, video consultation, at City Health. Ano ang maitutulong ko?</p>',
                ],
                'hil' => [
                    '<p>Maayong adlaw! Ako ang medConnect Assistant. Matabangan ko ikaw sa appointments, rehistro, login, video konsultasyon, kag City Health. Paano ko ikaw matabangan?</p>',
                    '<p>Kumusta! Welcome sa medConnect. Diri ako para giyahan ka sing malinong. Ano ang imo kinahanglan?</p>',
                ],
            ],
            'thank_you' => [
                'en' => [
                    '<p>You\'re very welcome. I\'m glad I could help. If you need booking, login, or support — just ask.</p>',
                    '<p>Happy to help. Take care, and I\'m here whenever you need medConnect guidance.</p>',
                ],
                'fil' => [
                    '<p>Walang anuman. Kung may kailangan pa — booking, login, o support — sabihin mo lang.</p>',
                ],
                'hil' => [
                    '<p>Wala sapayan. Kon may kinahanglan ka pa — booking, login, ukon support — silinga lang.</p>',
                ],
            ],
            'goodbye' => [
                'en' => [
                    '<p>Take care. If symptoms worsen or you feel unsafe, seek urgent care or call <strong>911</strong>. I\'m here anytime on medConnect.</p>',
                ],
                'fil' => [
                    '<p>Ingat. Kung lumala ang sintomas o hindi ka ligtas, tumawag sa <strong>911</strong>. Nandito ako sa medConnect.</p>',
                ],
                'hil' => [
                    '<p>Mag-ingat. Kon maglala ang sintomas ukon indi ka safe, tawagi ang <strong>911</strong>. Diri ako sa medConnect.</p>',
                ],
            ],
        ];
    }
}
