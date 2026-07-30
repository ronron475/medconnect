<?php
/**
 * Built-in Hiligaynon ↔ English dictionary seed (imported into translation_dictionary + medical_terms).
 * Extend via MySQL admin or data/nlp/faq_chatbot_translation_dictionary.json
 */
final class FaqChatbotDictionarySeed
{
    /**
     * @return list<array{source: string, target: string, category: string, phrase: bool, priority: int}>
     */
    public static function entries(): array
    {
        $out = [];
        foreach (self::phrases() as $row) {
            $out[] = $row;
        }
        foreach (self::tokens() as $hil => $meta) {
            $out[] = [
                'source'   => $hil,
                'target'   => $meta['en'],
                'category' => $meta['cat'],
                'phrase'   => false,
                'priority' => $meta['p'] ?? 0,
            ];
        }
        foreach (self::typoAliases() as $typo => $canonical) {
            if (!isset(self::tokenMap()[$canonical])) {
                continue;
            }
            $meta = self::tokenMap()[$canonical];
            $out[] = [
                'source'   => $typo,
                'target'   => $meta['en'],
                'category' => 'typo',
                'phrase'   => false,
                'priority' => -1,
            ];
        }
        return $out;
    }

    /** @return array<string, array{en: string, cat: string, p?: int}> */
    private static function tokenMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        foreach (self::tokens() as $hil => $meta) {
            $map[$hil] = $meta;
        }
        return $map;
    }

    /** @return array<string, string> typo => canonical hil */
    private static function typoAliases(): array
    {
        return [
            'hilnat' => 'hilanat',
            'hilant' => 'hilanat',
            'uboe' => 'ubo',
            'uboh' => 'ubo',
            'sipon' => 'sipon',
            'sip-on' => 'sipon',
            'doktr' => 'doktor',
            'doktor' => 'doktor',
            'duhan' => 'dughan',
            'dugan' => 'dughan',
            'gasakit' => 'masakit',
            'gsakit' => 'masakit',
            'nahilo' => 'nahilo',
            'nalipong' => 'nalipong',
            'ginakapoy' => 'kapoy',
            'ginakulbaan' => 'ginakulbaan',
        ];
    }

    /** @return list<array{source: string, target: string, category: string, phrase: bool, priority: int}> */
    private static function phrases(): array
    {
        $raw = [
            ['nalipong gid ko kag gasakit akon dughan', 'i feel dizzy and my chest hurts', 'symptom', 90],
            ['may hilanat ko kag ubo', 'i have fever and cough', 'symptom', 90],
            ['gasakit akon dughan', 'my chest hurts chest pain', 'symptom', 85],
            ['indi ako makaginhawa', 'cannot breathe difficulty breathing', 'emergency', 95],
            ['buot ko nga magpakamatay', 'i want to commit suicide', 'crisis', 100],
            ['paano mag register sa medconnect', 'how to register medconnect', 'appointment', 50],
            ['paano mag book sang appointment', 'how to book appointment', 'appointment', 50],
            ['nakalimtan ko ang password', 'i forgot my password', 'login', 50],
            ['gusto ko mag video konsulta', 'i want video consultation', 'consultation', 50],
            ['kinahanglan ko bulig', 'i need help', 'general', 40],
            ['salamat gid', 'thank you very much', 'greeting', 30],
            ['maayong aga', 'good morning greeting', 'greeting', 30],
        ];
        $list = [];
        foreach ($raw as [$s, $t, $c, $p]) {
            $list[] = ['source' => $s, 'target' => $t, 'category' => $c, 'phrase' => true, 'priority' => $p];
        }
        return $list;
    }

    /** @return array<string, array{en: string, cat: string, p?: int}> */
    private static function tokens(): array
    {
        return [
            // body
            'dughan' => ['en' => 'chest', 'cat' => 'body', 'p' => 5],
            'ulo' => ['en' => 'head', 'cat' => 'body', 'p' => 5],
            'tiyan' => ['en' => 'stomach abdomen', 'cat' => 'body', 'p' => 5],
            'lawas' => ['en' => 'body', 'cat' => 'body', 'p' => 4],
            'kamot' => ['en' => 'hand', 'cat' => 'body', 'p' => 3],
            'tiil' => ['en' => 'foot leg', 'cat' => 'body', 'p' => 3],
            'mata' => ['en' => 'eye', 'cat' => 'body', 'p' => 3],
            'ngipon' => ['en' => 'teeth', 'cat' => 'body', 'p' => 2],
            'tutunlan' => ['en' => 'throat', 'cat' => 'body', 'p' => 3],
            'likod' => ['en' => 'back', 'cat' => 'body', 'p' => 3],
            // symptoms
            'gasakit' => ['en' => 'pain hurts', 'cat' => 'symptom', 'p' => 6],
            'masakit' => ['en' => 'pain hurts', 'cat' => 'symptom', 'p' => 6],
            'sakit' => ['en' => 'pain sick', 'cat' => 'symptom', 'p' => 5],
            'hilanat' => ['en' => 'fever', 'cat' => 'symptom', 'p' => 6],
            'lagnat' => ['en' => 'fever', 'cat' => 'symptom', 'p' => 5],
            'ubo' => ['en' => 'cough', 'cat' => 'symptom', 'p' => 6],
            'sipon' => ['en' => 'cold runny nose', 'cat' => 'symptom', 'p' => 5],
            'sip-on' => ['en' => 'cold runny nose', 'cat' => 'symptom', 'p' => 5],
            'nahilo' => ['en' => 'dizzy', 'cat' => 'symptom', 'p' => 5],
            'nalipong' => ['en' => 'dizzy confused', 'cat' => 'symptom', 'p' => 5],
            'gahubag' => ['en' => 'swelling', 'cat' => 'symptom', 'p' => 4],
            'hubag' => ['en' => 'swelling', 'cat' => 'symptom', 'p' => 4],
            'pula' => ['en' => 'red rash', 'cat' => 'symptom', 'p' => 2],
            'gasuka' => ['en' => 'vomiting nausea', 'cat' => 'symptom', 'p' => 4],
            'kalibanga' => ['en' => 'diarrhea', 'cat' => 'symptom', 'p' => 4],
            // emotions
            'nahadlok' => ['en' => 'scared afraid fearful', 'cat' => 'emotion', 'p' => 6],
            'nabalaka' => ['en' => 'worried anxious', 'cat' => 'emotion', 'p' => 6],
            'kabalaka' => ['en' => 'worried', 'cat' => 'emotion', 'p' => 5],
            'ginakulbaan' => ['en' => 'anxious nervous', 'cat' => 'emotion', 'p' => 6],
            'kasubo' => ['en' => 'sad depressed', 'cat' => 'emotion', 'p' => 6],
            'kapoy' => ['en' => 'tired exhausted', 'cat' => 'emotion', 'p' => 5],
            'ginakapoy' => ['en' => 'tired exhausted', 'cat' => 'emotion', 'p' => 5],
            'akig' => ['en' => 'angry', 'cat' => 'emotion', 'p' => 5],
            'nalibog' => ['en' => 'confused', 'cat' => 'emotion', 'p' => 5],
            'libog' => ['en' => 'confused', 'cat' => 'emotion', 'p' => 4],
            'masadya' => ['en' => 'happy', 'cat' => 'emotion', 'p' => 5],
            'malipayon' => ['en' => 'happy calm', 'cat' => 'emotion', 'p' => 4],
            'wala paglaum' => ['en' => 'hopeless depressed', 'cat' => 'emotion', 'p' => 6],
            'isa lang' => ['en' => 'lonely', 'cat' => 'emotion', 'p' => 5],
            'nalain' => ['en' => 'feel bad sad', 'cat' => 'emotion', 'p' => 5],
            'pamatyag' => ['en' => 'feeling', 'cat' => 'emotion', 'p' => 3],
            // healthcare
            'doktor' => ['en' => 'doctor', 'cat' => 'healthcare', 'p' => 6],
            'nars' => ['en' => 'nurse', 'cat' => 'healthcare', 'p' => 4],
            'bulong' => ['en' => 'medicine drug', 'cat' => 'healthcare', 'p' => 6],
            'reseta' => ['en' => 'prescription', 'cat' => 'healthcare', 'p' => 6],
            'ospital' => ['en' => 'hospital', 'cat' => 'healthcare', 'p' => 5],
            'konsultasyon' => ['en' => 'consultation', 'cat' => 'healthcare', 'p' => 6],
            'konsulta' => ['en' => 'consultation', 'cat' => 'healthcare', 'p' => 5],
            'pasyente' => ['en' => 'patient', 'cat' => 'healthcare', 'p' => 4],
            'appointment' => ['en' => 'appointment', 'cat' => 'healthcare', 'p' => 5],
            'rehistro' => ['en' => 'register registration', 'cat' => 'healthcare', 'p' => 5],
            'triage' => ['en' => 'triage', 'cat' => 'healthcare', 'p' => 4],
            // common
            'salamat' => ['en' => 'thank you', 'cat' => 'greeting', 'p' => 5],
            'palihog' => ['en' => 'please', 'cat' => 'greeting', 'p' => 4],
            'kumusta' => ['en' => 'hello how are you', 'cat' => 'greeting', 'p' => 5],
            'musta' => ['en' => 'hello how are you', 'cat' => 'greeting', 'p' => 4],
            'buligi' => ['en' => 'help me', 'cat' => 'general', 'p' => 5],
            'tabangi' => ['en' => 'help me', 'cat' => 'general', 'p' => 5],
            'paano' => ['en' => 'how', 'cat' => 'general', 'p' => 3],
            'diin' => ['en' => 'where', 'cat' => 'general', 'p' => 3],
            'ano' => ['en' => 'what', 'cat' => 'general', 'p' => 3],
            'san-o' => ['en' => 'when', 'cat' => 'general', 'p' => 2],
            'nga' => ['en' => '', 'cat' => 'particle', 'p' => 0],
            'gid' => ['en' => 'very', 'cat' => 'particle', 'p' => 0],
            'guid' => ['en' => 'very', 'cat' => 'particle', 'p' => 0],
            'sang' => ['en' => '', 'cat' => 'particle', 'p' => 0],
            'kag' => ['en' => 'and', 'cat' => 'particle', 'p' => 0],
            'akon' => ['en' => 'my', 'cat' => 'particle', 'p' => 1],
            'ko' => ['en' => 'i my', 'cat' => 'particle', 'p' => 1],
            'ako' => ['en' => 'i', 'cat' => 'particle', 'p' => 1],
            'imo' => ['en' => 'your', 'cat' => 'particle', 'p' => 1],
            'indi' => ['en' => 'not no', 'cat' => 'particle', 'p' => 2],
            'wala' => ['en' => 'none no', 'cat' => 'particle', 'p' => 2],
            'subong' => ['en' => 'today now', 'cat' => 'time', 'p' => 2],
            'emergency' => ['en' => 'emergency', 'cat' => 'emergency', 'p' => 8],
            'dugo' => ['en' => 'bleeding blood', 'cat' => 'emergency', 'p' => 6],
            'malay' => ['en' => 'consciousness', 'cat' => 'emergency', 'p' => 5],
        ];
    }
}
