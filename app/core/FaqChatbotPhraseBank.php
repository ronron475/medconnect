<?php
/**
 * Large natural-language variation bank (EN / FIL / HIL / mixed / incomplete).
 * Indexed by conversational intent id. Rule-based only.
 */
final class FaqChatbotPhraseBank
{
    /**
     * @return list<string>
     */
    public static function forIntent(string $id): array
    {
        return match ($id) {
            'greeting' => self::greeting(),
            'help_general' => self::helpGeneral(),
            'uncertainty' => self::uncertainty(),
            'login' => self::login(),
            'logout' => self::logout(),
            'register' => self::register(),
            'password' => self::password(),
            'otp' => self::otp(),
            'profile' => self::profile(),
            'book' => self::book(),
            'need_doctor' => self::needDoctor(),
            'cancel' => self::cancel(),
            'appt_status' => self::apptStatus(),
            'video' => self::video(),
            'video_trouble' => self::videoTrouble(),
            'symptoms' => self::symptoms(),
            'emotion' => self::emotionFear(),
            'emotion_anxiety' => self::emotionAnxiety(),
            'emotion_sad' => self::emotionSad(),
            'emotion_lonely' => self::emotionLonely(),
            'emotion_anger' => self::emotionAnger(),
            'emotion_panic' => self::emotionPanic(),
            'emotion_tired' => self::emotionTired(),
            'emotion_overwhelmed' => self::emotionOverwhelmed(),
            'emotion_crying' => self::emotionCrying(),
            'emotion_embarrass' => self::emotionEmbarrass(),
            'emotion_grief' => self::emotionGrief(),
            'emotion_sleep' => self::emotionSleep(),
            'emotion_doctor_fear' => self::emotionDoctorFear(),
            'emotion_talk' => self::emotionTalk(),
            'emotion_hope' => self::emotionHope(),
            'emotion_guilt' => self::emotionGuilt(),
            'emotion_shame' => self::emotionShame(),
            'emotion_jealous' => self::emotionJealous(),
            'emotion_bored' => self::emotionBored(),
            'emotion_mixed' => self::emotionMixed(),
            'emotion_social' => self::emotionSocial(),
            'emotion_exam' => self::emotionExam(),
            'emotion_homesick' => self::emotionHomesick(),
            'emotion_disappoint' => self::emotionDisappoint(),
            'frustration' => self::frustration(),
            'emergency' => self::emergency(),
            'thanks' => self::thanks(),
            'goodbye' => self::goodbye(),
            'money' => self::money(),
            'bhw' => self::bhw(),
            'records' => self::records(),
            'privacy' => self::privacy(),
            'tech' => self::tech(),
            'transport' => self::transport(),
            'prescriptions' => self::prescriptions(),
            'hours' => self::hours(),
            'contact' => self::contact(),
            'triage' => self::triage(),
            'followup' => self::followup(),
            'email_verify' => self::emailVerify(),
            'locked' => self::locked(),
            'vaccines' => self::vaccines(),
            'pregnancy' => self::pregnancy(),
            'announcements' => self::announcements(),
            'notifications' => self::notifications(),
            'referrals' => self::referrals(),
            'nutrition' => self::nutrition(),
            'kids' => self::kids(),
            'when_consult' => self::whenConsult(),
            default => [],
        };
    }

    /** @return list<string> */
    private static function greeting(): array
    {
        return [
            'hi', 'hello', 'hey', 'helo', 'hiya', 'yo', 'good morning', 'good afternoon',
            'good evening', 'good day', 'hello po', 'hi po', 'hey po', 'kumusta', 'musta',
            'kamusta', 'maayong aga', 'maayong hapon', 'maayong gab-i', 'maayong adlaw',
            'magandang umaga', 'magandang hapon', 'magandang gabi', 'hello medconnect',
            'hi assistant', 'hello assistant', 'hey there', 'good morning po',
            'maayong aga sa imo', 'kumusta ka', 'musta na', 'hello gid',
        ];
    }

    /** @return list<string> */
    private static function helpGeneral(): array
    {
        $out = [
            'help', 'help me', 'please help', 'please help me', 'can you help me',
            'i need help', 'i need assistance', 'assist me', 'support please',
            'what can you do', 'who are you', 'what is medconnect', 'explain medconnect',
            'im confused', "i'm confused", 'where should i start', 'how to use this',
            'buligi ko', 'buligi bi ako', 'buligi ako', 'tabangi ko', 'kinahanglan ko bulig',
            'tulungan mo ako', 'tulong', 'tabang',
            'can you help me kay wala ko kabalo', 'confused ko', 'nalibog ko',
            'pwede ka magbulig', 'palihog buligi ako', 'need assistance',
            'how can you help', 'ano ang serbisyo', 'ano ang services',
            'giyahi ko', 'tuytuyan mo ako', 'walk me through',
        ];
        foreach (['help', 'bulig', 'tabang', 'tulong'] as $h) {
            $out[] = $h . ' pls';
            $out[] = $h . ' please';
            $out[] = 'need ' . $h;
        }
        return $out;
    }

    /** @return list<string> */
    private static function login(): array
    {
        $cores = ['login', 'log in', 'sign in', 'sulod', 'signin'];
        $neg = [
            'cannot', "can't", 'cant', 'indi ko ka', 'wala ko ka', 'hindi ako maka',
            'di ko maka', 'indi mag', 'indi ako', 'hindi', 'di ako', 'wont', "won't",
        ];
        $out = [
            'indi ko ka login', 'indi ko ka login sa account', 'wala ko ka login',
            'indi mag login', 'indi ko makasulod', 'hindi ako makalogin',
            'login not working', 'login problem', 'sign in not working',
            'invalid credentials', 'incorrect password', 'account locked',
            'cannot access my account', 'indi mag open ang login',
            'login error', 'sign in error', 'account ko indi ma open',
            'di ako maka sign in', 'hindi gumagana login', 'locked account',
            'duplicate login', 'wrong email login',
            'indi ko ka login kay nalipat password', 'cannot login sa medconnect',
            'sign in button dead', 'login page error',
        ];
        foreach ($neg as $n) {
            foreach ($cores as $c) {
                $out[] = trim($n . ' ' . $c);
                $out[] = trim($n . ' ' . $c . ' sa account');
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function register(): array
    {
        return [
            'register', 'sign up', 'create account', 'new patient', 'how to register',
            'who can register', 'paano mag register', 'paano magrehistro', 'gusto ko magrehistro',
            'indi ko ka register', 'registration failed', 'registration error',
            'already registered', 'duplicate account', 'existing patient',
            'national id', 'philid', 'ocr', 'identity verification',
            'registration requirements', 'address barangay', 'personal information register',
            'indi ko ka register', 'hindi ako makapagregister', 'sign up failed',
            'ocr failed', 'national id not reading', 'philid di magbasa',
            'barangay address error', 'existing patient na ako', 'may account na ako',
            'paano maghimo sang account', 'gusto ko mag sign up',
        ];
    }

    /** @return list<string> */
    private static function password(): array
    {
        $out = [
            'forgot password', 'reset password', 'change password', 'nakalimtan password',
            'nakalimutan ko password ko', 'nakalimot ko password', 'nalipat ko password',
            'paano mag reset sang password', 'i forgot my password', 'lost password',
            'password not working', 'wrong password', 'mali password',
        ];
        foreach (['password', 'passwrod', 'pasword'] as $p) {
            $out[] = 'forgot ' . $p;
            $out[] = 'reset ' . $p;
            $out[] = 'nalipat ko ' . $p;
            $out[] = 'nakalimot ko ' . $p;
        }
        return $out;
    }

    /** @return list<string> */
    private static function otp(): array
    {
        return [
            'otp', 'wala otp', 'wala ko otp', 'wala ko otp paano ni', 'wala nag-abot otp',
            'otp not received', 'code not received', 'verification code',
            'wala nagabot otp', 'wala nagaabot otp', 'otp delayed', 'otp delayed email',
            'hindi dumating otp', 'di dumating ang otp', 'resend otp', 'new otp',
            'one time pin', 'email not received',
        ];
    }

    /** @return list<string> */
    private static function book(): array
    {
        $acts = [
            'book', 'booking', 'appointment', 'checkup', 'pa checkup', 'pacheckup',
            'pakonsulta', 'konsulta', 'consultation', 'magbook', 'mag book',
            'pa appointment', 'pa-appointment',
        ];
        $how = [
            'how to', 'how do i', 'how can i', 'paano', 'paano mag', 'paano ko', 'pano', 'pano mag',
            'diin ko', 'diin ko maka', 'diin ako', 'saan ako', 'saan ko', 'saan mag',
            'where do i', 'where can i', 'gusto ko mag', 'gusto ko magpa',
            'need to', 'want to', 'pwede ba mag', 'pwede ko mag', 'kinahanglan ko',
            'kailangan ko', 'can i', 'i want to',
        ];
        $out = [
            'i need a checkup',
            'i want an appointment', 'how can i book', 'where do i book',
            'diin ko maka pa check up', 'diin ko maka book', 'diin ko makapagkonsulta',
            'paano magpa appointment', 'paano magpakonsulta', 'gusto ko magpa checkup',
            'gusto ko magpakonsulta', 'gusto ko magpa doctor',
            'magpa appointment ko', 'gusto ko magbook', 'pwede ba magpa checkup',
            'can i talk to a doctor', 'schedule consultation', 'available doctor',
            'gusto ko mag book appointment', 'gusto ko mag book consultation',
            'diin ko mag book',
        ];
        foreach ($how as $h) {
            foreach ($acts as $a) {
                $out[] = trim($h . ' ' . $a);
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function needDoctor(): array
    {
        return [
            'doctor', 'doktor', 'doc', 'need doctor', 'need a doctor', 'i need a doctor',
            'i want to consult a doctor', 'want doctor', 'gusto ko doktor',
            'kinahanglan ko doktor', 'doctor pls', 'doctor please', 'need doc',
            'gusto ko magpa doctor', 'magpa doctor ko',
        ];
    }

    /** @return list<string> */
    private static function cancel(): array
    {
        return [
            'cancel appointment', 'can i cancel', 'reschedule', 'change appointment',
            'i-cancel', 'cancel ko appointment', 'move my appointment',
            'i-reschedule', 'lipat appointment', 'usbon ang appointment',
            'cancel ko bi', 'indi na ko kadto', 'cannot attend appointment',
            'move to another day', 'change date', 'change time',
            'i-cancel ang appointment', 'resched ko', 'lipat sa iban nga adlaw',
        ];
    }

    /** @return list<string> */
    private static function apptStatus(): array
    {
        return [
            'may appointment ko', 'may appointment ako', 'where is my appointment',
            'diin ko makita appointment ko', 'san-o appointment ko',
            'appointment status', 'upcoming appointment', 'missed appointment',
            'appointment confirmation', 'appointment reminder',
            'may appointment ko pero wala doctor', 'di ko makita appointment',
            'where is my consultation',
            'san-o ang akon appointment', 'ano status sang appointment',
            'confirmed na bala', 'wala ko confirmation', 'may reminder bala',
            'nakalimtan ko ang oras', 'what time is my appointment',
            'did i miss my appointment', 'napasagana ko appointment',
        ];
    }

    /** @return list<string> */
    private static function video(): array
    {
        return [
            'video consultation', 'video consult', 'join consultation', 'telemedicine',
            'how video consultation works', 'gusto ko mag video call doctor',
            'how do i talk to a doctor', 'online consult', 'join video',
            'paano mag video consult', 'paano magjoin', 'join my appointment',
            'start video', 'enter video room', 'online nga konsultasyon',
            'teleconsult', 'virtual consult',
        ];
    }

    /** @return list<string> */
    private static function videoTrouble(): array
    {
        return [
            'video not working', 'video call not working', 'indi naga work ang video call',
            'camera doesnt work', "camera doesn't work", 'camera not working',
            'camera indi naga work', 'my camera isnt working', "my camera isn't working",
            'cannot hear the doctor', "can't hear doctor", 'cannot hear doctor',
            'i cannot hear the doctor', 'indi ko mabatian', 'indi ko mabatian ang doktor',
            'indi ko marinig ang doktor', 'no audio', 'no microphone', 'no camera',
            'indi ko makita', 'indi ko makita doctor', 'indi ko makita ang doktor',
            'wala doctor sa video', 'doctor not appearing', 'cannot see doctor',
            'doctor didnt join', "doctor didn't join", 'microphone permission',
            'camera permission', 'video frozen', 'connection problem',
            'speaker not working', 'cannot speak', 'indi ko kahambal',
            'echo sa video', 'lag ang video', 'hinay ang internet sa video',
            'black screen', 'no picture', 'no sound', 'mic not working',
            'allow camera', 'allow microphone', 'permission denied camera',
        ];
    }

    /** @return list<string> */
    private static function symptoms(): array
    {
        $out = [
            'my head hurts', 'headache', 'head ache', 'my head feels heavy',
            'my tummy hurts', 'stomach pain', 'abdominal pain', 'i feel like fainting',
            'i feel dizzy', 'my chest feels tight', 'hard to breathe mild',
            'my body feels weak', 'my eyes hurt', 'eye pain', 'my throat hurts', 'sore throat',
            'fever', 'cough', 'colds', 'vomiting', 'diarrhea', 'body pain', 'back pain',
            'toothache', 'rash', 'nausea', 'masakit ulo ko', 'sakit ulo ko',
            'masakit akon ulo', 'sakit akon ulo', 'sakit tiyan ko', 'masakit tiyan ko',
            'daw malipong ko', 'nahilo ko', 'ginahilanat ko', 'may hilanat ko',
            'ginauubo ko', 'ginaubo ko', 'sakit akon dughan mild',
            'lagnat', 'hilanat', 'ubo', 'sipon', 'masakit ulo ko need doctor',
            'need doctor because my head hurts',
            'my head feels heavy', 'head feels tight', 'pounding head',
            'i feel weak', 'lawas ko kapoy', 'kapoy ang lawas',
            'masakit mata ko', 'sakit tutunlan', 'sakit ngipon',
            'nagsusuka ko', 'gasuka ko', 'may sipon ako', 'may ubo ako',
            'masakit ang ulo ko', 'masakit ang tiyan ko', 'nahihilo ako',
            'masakit ang lalamunan', 'masakit ang mata ko', 'nanlalambot ako',
            'hindi ako maganda ang pakiramdam', 'not feeling well',
            'sakit ulo ko need checkup', 'ulo ko bug-at', 'bug-at ang ulo',
            'ginakulbaan ko kag sakit ulo',
        ];
        foreach (['ulo', 'tiyan', 'dughan', 'likod', 'lawas', 'tutunlan', 'mata', 'tuhod', 'paa', 'ngipon', 'ilong', 'tenga'] as $part) {
            foreach (['sakit', 'masakit', 'ga sakit', 'nagasakit', 'ginasakit', 'nag sakit'] as $v) {
                $out[] = "$v akon $part";
                $out[] = "$v $part ko";
                $out[] = "$v ang $part";
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function emotionFear(): array
    {
        $out = [
            'i am scared', "i'm scared", 'im scared', 'i am afraid', 'im afraid', "i'm afraid",
            'so scared', 'very scared', 'scared', 'afraid', 'fear', 'i feel scared',
            'nahadlok ko', 'nahadlok gid ko', 'nahadlok ako', 'nahadlok gid ako',
            'nahadlok ko gid', 'ginahadlok ko', 'hadlok ko', 'takot ako', 'natatakot ako',
            'natatakot na ako', 'kinatatakutan ko', 'scared gid ko', 'grabe ang hadlok ko',
            'nahadlok ko kay sakit akon ulo', 'nahadlok ko kay grabe sakit ulo ko',
            'nahadlok ko because sakit akon ulo', 'masakit ulo ko kag nahadlok ko',
            'scared about my symptoms', 'i am so scared', 'nahadlok ko subong', 'hadlok gid',
            'nahadlok gid ako', 'grabe ko kahadlok', 'daw nahadlok ko', 'fearful',
            'i feel afraid', 'so afraid', 'takot na takot ako', 'hadlok ako maghambal',
        ];
        foreach (['nahadlok', 'natatakot', 'scared', 'afraid'] as $w) {
            $out[] = $w . ' ko';
            $out[] = $w . ' ako';
            $out[] = $w . ' gid';
            $out[] = 'grabe ' . $w;
        }
        return $out;
    }

    /** @return list<string> */
    private static function emotionAnxiety(): array
    {
        return [
            'anxious', 'anxiety', 'i am anxious', "i'm anxious", 'i feel anxious',
            'nervous', 'i am nervous', 'i feel nervous', 'worried', 'i am worried', "i'm worried",
            'ginakulbaan ko', 'kulbaan gid ko', 'kulbaan ko', 'grabe ang kulba ko',
            'kinakabahan ako', 'kinakabahan gid ako', 'nag-aalala ako', 'nabalaka ko',
            'nabalaka gid ko', 'balaka ko', 'ginakabalaka ko', 'kabado ako',
            'dili ko mapanatag', 'indi ko mapanatag', 'my heart is racing',
            'i cannot calm down', 'indi ko mahinay', 'overthinking',
            'ginakulbaan gid ko', 'kulbaan ako magpa checkup',
            'gakulbaan ko', 'ginakulbaan gid ako', 'kulba gid', 'grabe ang kaba ko',
            'i keep worrying', 'cannot stop worrying', 'overthink gid', 'naga-overthink ko',
            'naga kurog ko', 'ginakurog ko', 'daw indi ko mapahuway ang hunahuna',
            'worried about my health', 'im worried about my health', "i'm worried about my health",
            'nabalaka gid', 'nagabalaka ko', 'balaka gid',
        ];
    }

    /** @return list<string> */
    private static function emotionSad(): array
    {
        return [
            'sad', 'i am sad', "i'm sad", 'im sad', 'i feel sad', 'feeling down',
            'malungkot ako', 'malungkot', 'kasubo', 'nasubo ko', 'nasubo ako',
            'subo gid', 'budlay pamatyagon', 'budlay gid pamatyagon',
            'heartbroken', 'broken heart', 'wala laman', 'empty inside',
            'naguol ako', 'nagkasubo ko', 'malain gid pamatyag',
            'i feel empty', 'down na ko', 'lungkot',
            'subo ako', 'kasubo gid', 'nasubo gid ko', 'grabe kasubo',
            'feeling blue', 'i feel down', 'wala na ko kalipay', 'indi ko malipay',
            'naguol gid', 'nagahuoy ako', 'malain ang pamatyag ko',
        ];
    }

    /** @return list<string> */
    private static function emotionLonely(): array
    {
        return [
            'lonely', 'i am lonely', "i'm lonely", 'i feel lonely', 'alone',
            'nag-iisa ako', 'isa lang ko', 'wala ko kaistorya', 'wala ko maistoryahan',
            'wala ko may kastorya', 'need someone to talk to', 'nobody to talk to',
            'wala upod', 'nagaisahan ako', 'wala ko sang kaistoryahan',
            'feel so alone', 'i feel alone', 'wala may naga-upod sa akon',
            'lonely gid', 'isa lang gid ko', 'wala ko may kastorya subong',
        ];
    }

    /** @return list<string> */
    private static function emotionAnger(): array
    {
        return [
            'i am angry', "i'm angry", 'angry', 'so angry', 'galit ako', 'akig ko',
            'akig gid ko', 'nagalit ako', 'grabe ka akig', 'i am mad',
            'init ulo ko', 'nasakitan ako sa inis',
            'galit na galit ako', 'akig gid ako', 'nagaakig ko', 'so mad',
            'i feel angry', 'init gid ulo ko', 'nainis kag akig', 'yawa ka inis',
            'grabe ka galit', 'akig ako sa sistema',
        ];
    }

    /** @return list<string> */
    private static function emotionPanic(): array
    {
        return [
            'panic', 'panic attack', 'i am panicking', "i'm panicking", 'im panicking',
            'ginapanik ko', 'naga panic ko', 'cannot calm down', 'i cant calm down',
            'grabe panic ko', 'having a panic attack', 'panic gid',
            'ginapanik ako', 'naga-panic ako', 'panic na ko', 'i feel panic',
            'heart racing panic', 'cannot breathe from panic', 'kulba kag panic',
        ];
    }

    /** @return list<string> */
    private static function emotionTired(): array
    {
        return [
            'i am tired', "i'm tired", 'so tired', 'exhausted', 'kapoy na ko',
            'kapoy na ko gid', 'ginakapoy ko', 'pagod na ako', 'wala na kusog',
            'burnout', 'burned out', 'emotionally exhausted', 'drained',
            'kapoy na ko sa tanan',
            'kapoy na gid ko', 'pagod na pagod ako', 'so exhausted',
            'wala na ko kusog', 'drained na ko', 'kapoy gid', 'emotionally drained',
            'too tired to function', 'ginakapoy na gid',
        ];
    }

    /** @return list<string> */
    private static function emotionOverwhelmed(): array
    {
        return [
            'overwhelmed', 'i am overwhelmed', 'too much', 'sobra na',
            'daw wala na ko gana', 'wala na ko gana sa tanan', 'sobra nga problema',
            'i cannot take it', 'grabe ka dami', 'mabug-at gid',
            'overwhelmed na ko', 'sobra na gid', 'too much for me',
            'grabe ka damo problema', 'mabug-at ang dughan ko',
            'i am so overwhelmed', 'daw malunod ko sa problema',
        ];
    }

    /** @return list<string> */
    private static function emotionCrying(): array
    {
        return [
            'i am crying', "i'm crying", 'crying', 'cannot stop crying', "can't stop crying",
            'naga hibi ko', 'nagahibi ko', 'naga hilib ko', 'umiiyak ako',
            'gailuha ko', 'gabuhai ako',
            'naga hibi ako', 'nagahilib ko', 'cannot stop crying', 'luha ko',
            'gahibi ko', 'umiiyak na ako', 'i keep crying', 'naga-iluha ko',
        ];
    }

    /** @return list<string> */
    private static function emotionEmbarrass(): array
    {
        return [
            'embarrassed', 'i am embarrassed', 'nahuya ko', 'nahihiya ako', 'hiya ko',
            'ashamed', 'shame', 'nakahuya', 'nahuya ako magpa doctor',
            'hiya gid', 'nahuya gid ko', 'embarrassing', 'i feel embarrassed',
            'mahiya ako maghambal', 'nahuya ko magpa checkup',
        ];
    }

    /** @return list<string> */
    private static function emotionGrief(): array
    {
        return [
            'grief', 'grieving', 'someone passed away', 'namatay', 'naglubong',
            'i lost someone', 'namatay ang palangga ko', 'in mourning',
            'namatay ang pamilya ko', 'nagakaluoy ko', 'i am grieving',
            'loss of a loved one', 'naglubong kami', 'namatay siya',
        ];
    }

    /** @return list<string> */
    private static function emotionSleep(): array
    {
        return [
            'cannot sleep', "can't sleep", 'i cannot sleep', 'insomnia',
            'indi ko katulog', 'indi ko ka tulog', 'wala ko katulog',
            'hindi ako makatulog',
            'cannot fall asleep', 'wala ko katulog gab-i', 'puyat gid',
            'insomnia ko', 'budlay katulog', 'indi gid ko katulog',
        ];
    }

    /** @return list<string> */
    private static function emotionDoctorFear(): array
    {
        return [
            'afraid of the doctor', 'scared of the doctor', 'nahadlok ko sa doktor',
            'nahadlok sa hospital', 'fear of hospital', 'scared of hospital',
            'nahadlok ko magpatingin', 'takot ako sa doktor',
            'nahadlok ko magpakonsulta', 'natatakot ako magpatingin',
            'scared to see a doctor', 'afraid of hospital', 'hadlok sa clinic',
            'nahadlok ko sa ospital', 'takot ako magpa checkup',
        ];
    }

    /** @return list<string> */
    private static function emotionTalk(): array
    {
        return [
            'need someone to talk', 'just need to talk', 'can i talk to someone',
            'wala ko makigstorya', 'need to talk', 'i need to talk',
            'can someone listen', 'i just need to talk', 'kinahanglan ko maghambal',
            'need to vent', 'pwede ko maghambal',
        ];
    }

    /** @return list<string> */
    private static function emotionHope(): array
    {
        return [
            'i feel better', 'i am hopeful', 'may paglaum', 'may pag-asa',
            'relieved', 'i am relieved', 'ginhawa na', 'okay na ko',
            'masadya ko', 'i am happy', 'malipayon ko',
            'okay na gid ko', 'nalipay na ko', 'i feel okay now', 'may paglaum na',
            'ginhawa na ang dughan ko', 'relieved na ko',
        ];
    }

    /** @return list<string> */
    private static function emotionGuilt(): array
    {
        return [
            'i feel guilty', 'guilty', 'i am guilty', 'may guilt ako',
            'kasalanan ko', 'akala ko kasalanan ko', 'nagkasala ko',
            'guilt gid', 'i blame myself', 'basol ko', 'gina-basol ko ang akon kaugalingon',
        ];
    }

    /** @return list<string> */
    private static function emotionShame(): array
    {
        return [
            'i feel ashamed', 'i am ashamed', 'ashamed of myself',
            'nakahuya gid', 'huya gid', 'nahuya gid ako sa akon kaugalingon',
            'shameful', 'i feel shame',
        ];
    }

    /** @return list<string> */
    private static function emotionJealous(): array
    {
        return [
            'i am jealous', 'jealous', 'i feel jealous', 'naiinggit ako',
            'inggit', 'selos', 'selos ako', 'naiinggit ko', 'inggit gid',
        ];
    }

    /** @return list<string> */
    private static function emotionBored(): array
    {
        return [
            'i am bored', 'bored', 'boring', 'wala gana', 'wala ko gana',
            'walang gana', 'wala na gana', 'indi ko gusto maghimo',
            'nothing to do', 'wala ko gana subong',
        ];
    }

    /** @return list<string> */
    private static function emotionMixed(): array
    {
        return [
            'mixed feelings', 'i have mixed feelings', 'magkahalong',
            'conflicted', 'pero nahadlok', 'pero kapoy', 'pero sad',
            'but scared', 'but afraid', 'okay lang pero', 'okay pero nahadlok',
            'masadya pero ginakulbaan', 'happy but scared',
        ];
    }

    /** @return list<string> */
    private static function emotionSocial(): array
    {
        return [
            'social anxiety', 'scared of people', 'nahadlok sa tao',
            'nahadlok sa mga tao', 'nahuya sa tao', 'afraid of people',
            'i get nervous around people', 'kulbaan ko sa tawo',
        ];
    }

    /** @return list<string> */
    private static function emotionExam(): array
    {
        return [
            'exam anxiety', 'test anxiety', 'kinabahan sa exam',
            'kulba sa exam', 'stress sa exam', 'nervous about exam',
            'scared of the test', 'kulbaan sa exam',
        ];
    }

    /** @return list<string> */
    private static function emotionHomesick(): array
    {
        return [
            'homesick', 'i am homesick', 'homesickness', 'nahidlaw ko',
            'nahidlaw sa balay', 'miss my family', 'miss home',
            'miss ko ang balay', 'nahidlaw ako sa pamilya',
        ];
    }

    /** @return list<string> */
    private static function emotionDisappoint(): array
    {
        return [
            'disappointed', 'i am disappointed', 'i feel disappointed',
            'nadismaya', 'nadismaya ako', 'dismaya', 'wala natuman',
            'indi natuman', 'let down', 'i feel let down',
        ];
    }

    /** @return list<string> */
    private static function emergency(): array
    {
        return [
            'severe chest pain', 'difficulty breathing', 'cannot breathe', "can't breathe",
            'unconscious', 'fainting', 'seizure', 'severe bleeding', 'stroke like symptoms',
            'sudden weakness', 'severe allergic reaction', 'poisoning', 'serious injury',
            'suicidal thoughts', 'self harm', 'severe confusion',
            'indi ko kaginhawa', 'indi ko makaginhawa', 'budlay mag ginhawa', 'budlay ginhawa',
            'wala ko malay', 'nahimatay ko', 'nag seizure', 'grabe gid ang pagdugo',
            'dugo gid', 'blue lips', 'facial drooping',
            'indi ko na ginhawa', 'grabe gid ang dughan', 'seizure ko',
            'nagkalipong kag nahimatay', 'stroke symptoms', 'slurred speech',
            'throat swelling', 'allergic shock', 'nalason', 'lason',
        ];
    }

    /** @return list<string> */
    private static function thanks(): array
    {
        return [
            'thanks', 'thank you', 'thank you so much', 'okay thanks', 'ok thanks',
            'got it', 'that is all', "that's all", 'salamat', 'salamat gid',
            'maraming salamat', 'damo nga salamat', 'okay salamat', 'ok salamat',
            'thanks a lot', 'thank u', 'ty', 'salamat ah', 'salamat guid',
            'okay that is all', 'done thanks', 'sige salamat',
        ];
    }

    /** @return list<string> */
    private static function uncertainty(): array
    {
        return [
            'what do i do', 'i dont know what to do', "i don't know what to do",
            'wala ko kabalo ano ubrahon', 'indi ko kabalo ano ubrahon',
            'ano ubrahon ko', 'ano dapat ubrahon ko', 'hindi ko alam ang gagawin',
            'di ko alam', 'lost ko', 'i am lost', 'where do i start',
            'ano sunod', 'what next', 'unsaon ni', 'paano ni',
        ];
    }

    /** @return list<string> */
    private static function logout(): array
    {
        return [
            'logout', 'log out', 'sign out', 'paano mag logout', 'how to logout',
            'gusto ko mag logout', 'exit account', 'sign out sa account',
        ];
    }

    /** @return list<string> */
    private static function profile(): array
    {
        return [
            'update profile', 'edit profile', 'change my name', 'change address',
            'change contact', 'update barangay', 'i-update ang profile',
            'wrong personal information', 'change phone number', 'change email',
            'paano mag update sang profile', 'edit my details',
        ];
    }

    /** @return list<string> */
    private static function frustration(): array
    {
        return [
            'this is frustrating', 'so frustrating', 'nakakainis', 'lain gid',
            'nainis ako', 'grabe ka slow', 'why is this so hard',
            'indi naga work bisan ano', 'kapoy na ko sini',
        ];
    }

    /** @return list<string> */
    private static function goodbye(): array
    {
        return [
            'bye', 'goodbye', 'see you', 'paalam', 'hangtod sa liwat', 'kita ta',
            'that is all bye', 'sige bye', 'okay bye',
        ];
    }

    /** @return list<string> */
    private static function money(): array
    {
        return [
            'how much', 'free ni', 'may bayad', 'libre ni', 'wala ko kwarta',
            'cannot afford', 'no money', 'too expensive', 'mahal bala',
            'consultation fee', 'libre ba', 'walang pera', 'broke',
            'indi ko kaya magbayad', 'affordable healthcare', 'may bayad bala',
            'pila ang bayad', 'pila ni', 'free consultation',
        ];
    }

    /** @return list<string> */
    private static function bhw(): array
    {
        return [
            'what is bhw', 'can bhw help me', 'bhw assistance', 'barangay health worker',
            'bhw help me register', 'bhw referral', 'bhw appointment',
            'ask bhw', 'pakig-istorya sa bhw', 'health worker sa barangay',
            'bhw magbulig', 'existing patient bhw',
        ];
    }

    /** @return list<string> */
    private static function records(): array
    {
        return [
            'medical record', 'soap notes', 'doctors notes', 'health summary',
            'consultation history', 'previous consultation', 'emr', 'my records',
            'diin ko makita records', 'where are my records', 'diagnosis notes',
            'treatment plan', 'provider notes', 'medical history',
            'tan-awon ang soap', 'health record ko',
        ];
    }

    /** @return list<string> */
    private static function privacy(): array
    {
        return [
            'privacy', 'who can see my records', 'data privacy', 'confidential',
            'account security', 'safe bala ni', 'masaligan ni bala',
            'unauthorized access', 'who can access my account',
            'confidentiality', 'data security', 'makita bala ni sang iban',
        ];
    }

    /** @return list<string> */
    private static function tech(): array
    {
        return [
            'website not loading', 'page stuck', 'loading forever', 'button not working',
            'blank page', 'error message', 'browser problem', 'mobile problem',
            'dashboard problem', 'site down', 'cannot open page',
            'indi mag load', 'naga hang', 'error sa page', 'app not working',
            'refresh not working', 'white screen',
        ];
    }

    /** @return list<string> */
    private static function transport(): array
    {
        return [
            'wala ko plete', 'malayo kami', 'indi ko kaadto', 'cannot travel',
            'no transportation', 'wala ko masakyan', 'layo amon', 'wala pamasahe',
            'cannot leave home', 'far from health center', 'budlay magkadto',
        ];
    }

    /** @return list<string> */
    private static function prescriptions(): array
    {
        return [
            'prescription', 'reseta', 'digital prescription', 'where is my prescription',
            'diin ang reseta', 'gamot ko', 'bulong ko', 'medicine list',
            'paano makita reseta', 'provider issued prescription',
        ];
    }

    /** @return list<string> */
    private static function hours(): array
    {
        return [
            'office hours', 'clinic hours', 'what time open', 'bukas', 'sarado',
            'oras sang opisina', 'anong oras bukas', 'doctor schedule',
            'available hours', 'kailan bukas', 'san-o bukas',
        ];
    }

    /** @return list<string> */
    private static function contact(): array
    {
        return [
            'contact city health', 'contact cho', 'phone number cho', 'tawag sa cho',
            'how to contact', 'city health office contact', 'leave a message',
            'email city health', 'address sang cho', 'diin ang city health',
        ];
    }

    /** @return list<string> */
    private static function triage(): array
    {
        return [
            'ai triage', 'what is ai triage', 'symptom checker', 'triage',
            'paano gamiton ang ai triage', 'triage info',
        ];
    }

    /** @return list<string> */
    private static function followup(): array
    {
        return [
            'follow up', 'followup', 'balik consult', 'sunod nga consultation',
            'follow up appointment', 'need follow up', 'paano mag follow up',
        ];
    }

    /** @return list<string> */
    private static function emailVerify(): array
    {
        return [
            'verify email', 'email verification', 'confirm email', 'indi verified',
            'not verified', 'verification email', 'wala verification email',
            'resend verification', 'i-verify ang email', 'confirm my email',
        ];
    }

    /** @return list<string> */
    private static function locked(): array
    {
        return [
            'account locked', 'locked out', 'cannot access account', 'account recovery',
            'recover my account', 'na-lock ang account', 'locked ang account ko',
            'too many attempts', 'account ko naka lock',
        ];
    }

    /** @return list<string> */
    private static function vaccines(): array
    {
        return [
            'vaccine', 'vaccination', 'bakuna', 'booster', 'immunization',
            'paano magpabakuna', 'vaccine schedule', 'child vaccine',
            'where to get vaccine', 'diin magpabakuna', 'bakuna sa city health',
        ];
    }

    /** @return list<string> */
    private static function pregnancy(): array
    {
        return [
            'pregnant', 'pregnancy', 'buntis', 'prenatal', 'prenatal checkup',
            'magpa prenatal', 'buntis ako', 'i am pregnant', 'maternity',
            'paano magpa prenatal', 'buntis checkup',
        ];
    }

    /** @return list<string> */
    private static function announcements(): array
    {
        return [
            'announcement', 'announcements', 'balita', 'health advisory',
            'latest news', 'latest announcements', 'ano ang balita',
            'may announcement', 'city health advisory',
        ];
    }

    /** @return list<string> */
    private static function notifications(): array
    {
        return [
            'notification', 'notifications', 'bell icon', 'reminders not coming',
            'wala notification', 'paalala', 'abiso', 'appointment reminder not received',
            'turn on notifications',
        ];
    }

    /** @return list<string> */
    private static function referrals(): array
    {
        return [
            'referral', 'referrals', 'pa-refer', 'i-refer', 'need referral',
            'referral letter', 'paano magpa referral', 'bhw referral',
        ];
    }

    /** @return list<string> */
    private static function nutrition(): array
    {
        return [
            'nutrition', 'what should i eat', 'diet', 'healthy food', 'pagkaon',
            'ano dapat kaonon', 'meal plan', 'diabetes diet',
        ];
    }

    /** @return list<string> */
    private static function kids(): array
    {
        return [
            'my child is sick', 'sakit ang bata', 'baby has fever', 'sanggol may hilanat',
            'child checkup', 'pedia', 'children health', 'bakuna sang bata',
            'masakit ang anak ko',
        ];
    }

    /** @return list<string> */
    private static function whenConsult(): array
    {
        return [
            'when should i see a doctor', 'should i consult', 'do i need a checkup',
            'kailan magpatingin', 'san-o magpakonsulta', 'kailangan ko na bala magpa doctor',
            'when to consult', 'dapat ko na magpa checkup',
        ];
    }
}
