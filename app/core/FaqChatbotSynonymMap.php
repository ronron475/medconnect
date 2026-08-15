<?php
/**
 * Rule-based synonym groups for the FAQ chatbot (PHP only, no ML).
 * Used during token expansion so mixed-language and informal wording still match.
 */
final class FaqChatbotSynonymMap
{
    /**
     * Canonical token → related tokens added to the match bag.
     *
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        return [
            'appointment' => [
                'booking', 'book', 'schedule', 'consultation', 'checkup', 'pakonsulta',
                'magpakonsulta', 'visita', 'consult', 'konsulta',
            ],
            'book' => ['appointment', 'booking', 'schedule', 'checkup'],
            'consultation' => ['appointment', 'book', 'checkup', 'konsulta'],
            'konsulta' => ['appointment', 'consultation'],
            'doctor' => ['doc', 'dok', 'doktor', 'physician', 'provider', 'medico'],
            'doc' => ['doctor'],
            'dok' => ['doctor'],
            'doktor' => ['doctor'],
            'help' => ['assist', 'assistance', 'support', 'bulig', 'buligi', 'tabang', 'tabangi', 'tulong', 'tulungan'],
            'bulig' => ['help'],
            'buligi' => ['help'],
            'tabang' => ['help'],
            'tabangi' => ['help'],
            'scared' => ['afraid', 'fear', 'nahadlok', 'natatakot'],
            'nahadlok' => ['scared', 'afraid'],
            'sad' => ['kasubo', 'malungkot', 'nasubo'],
            'kasubo' => ['sad'],
            'malungkot' => ['sad'],
            'angry' => ['akig', 'galit'],
            'akig' => ['angry'],
            'galit' => ['angry'],
            'lonely' => ['isa'],
            'crying' => ['hibi', 'hilib'],
            'hibi' => ['crying'],
            'panic' => ['ginapanik'],
            'tired' => ['kapoy', 'pagod'],
            'kapoy' => ['tired'],
            'overwhelmed' => ['sobra'],
            'guilty' => ['kasalanan', 'basol'],
            'kasalanan' => ['guilty'],
            'homesick' => ['nahidlaw'],
            'nahidlaw' => ['homesick'],
            'jealous' => ['inggit', 'selos'],
            'inggit' => ['jealous'],
            'disappointed' => ['nadismaya', 'dismaya'],
            'nadismaya' => ['disappointed'],
            'embarrassed' => ['nahuya', 'hiya'],
            'nahuya' => ['embarrassed'],
            'ashamed' => ['huya'],
            'worried' => ['concerned', 'anxious', 'nervous', 'nabalaka', 'balaka', 'nag-aalala'],
            'nabalaka' => ['worried'],
            'kulbaan' => ['anxious', 'nervous', 'ginakulbaan'],
            'ginakulbaan' => ['anxious', 'nervous'],
            'headache' => ['ulo', 'head', 'headache'],
            'ulo' => ['headache', 'head'],
            'head' => ['headache', 'ulo'],
            'tummy' => ['stomach', 'tiyan', 'abdomen'],
            'stomach' => ['tiyan', 'tummy', 'abdomen'],
            'tiyan' => ['stomach', 'tummy'],
            'dizzy' => ['dizziness', 'nahilo', 'malipong', 'faint', 'fainting'],
            'nahilo' => ['dizzy'],
            'malipong' => ['dizzy', 'faint'],
            'password' => ['pass', 'passwd'],
            'login' => ['signin', 'sulod', 'logon'],
            'register' => ['signup', 'rehistro', 'registration'],
            'otp' => ['code', 'pin', 'verification'],
            'video' => ['telemedicine', 'videocall', 'call'],
            'camera' => ['cam', 'webcam'],
            'microphone' => ['mic', 'audio', 'speaker'],
            'emergency' => ['urgent', '911'],
            'fever' => ['hilanat', 'lagnat'],
            'hilanat' => ['fever', 'lagnat'],
            'cough' => ['ubo'],
            'ubo' => ['cough'],
            'colds' => ['sipon'],
            'sipon' => ['colds'],
            'prescription' => ['reseta', 'gamot', 'medicine'],
            'reseta' => ['prescription'],
            'record' => ['records', 'emr', 'history', 'soap'],
            'privacy' => ['confidential', 'security'],
            'hours' => ['oras', 'bukas', 'schedule'],
            'bukas' => ['open', 'hours'],
            'bhw' => ['worker'],
            'frustrated' => ['nakakainis', 'irritation'],
            'thanks' => ['salamat'],
            'salamat' => ['thanks'],
            'logout' => ['signout'],
            'profile' => ['account'],
            'weak' => ['kapoy', 'luya'],
            'throat' => ['tutunlan'],
            'tutunlan' => ['throat'],
            'eye' => ['mata'],
            'mata' => ['eye'],
            'back' => ['likod'],
            'likod' => ['back'],
            'chest' => ['dughan', 'dibdib'],
            'dughan' => ['chest'],
            'breathing' => ['ginhawa'],
            'ginhawa' => ['breathing'],
            'rehistro' => ['register'],
            'vaccine' => ['bakuna', 'vaccination', 'booster'],
            'bakuna' => ['vaccine'],
            'pregnant' => ['buntis', 'prenatal', 'pregnancy'],
            'buntis' => ['pregnant', 'prenatal'],
            'child' => ['bata', 'baby', 'anak'],
            'bata' => ['child', 'anak'],
            'referral' => ['refer'],
            'announcement' => ['balita', 'advisory'],
            'notification' => ['paalala', 'abiso', 'reminder'],
        ];
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    public static function expand(array $tokens): array
    {
        $map = self::groups();
        $out = $tokens;
        foreach ($tokens as $tok) {
            foreach ($map[$tok] ?? [] as $extra) {
                $out[] = $extra;
            }
        }
        return array_values(array_unique($out));
    }
}
