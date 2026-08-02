<?php
declare(strict_types=1);

/**
 * Catalog of medConnect NLP datasets under data/nlp/ for admin dashboards.
 */

function nlp_inventory_count_csv(string $path): int
{
    if (!is_readable($path)) {
        return 0;
    }
    $count = 0;
    $handle = fopen($path, 'r');
    if ($handle === false) {
        return 0;
    }
    $first = true;
    while (fgets($handle) !== false) {
        if ($first) {
            $first = false;
            continue;
        }
        $count++;
    }
    fclose($handle);
    return $count;
}

function nlp_inventory_count_json_entries(string $path): int
{
    if (!is_readable($path)) {
        return 0;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return 0;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return 0;
    }
    if (array_is_list($decoded)) {
        return count($decoded);
    }
    $total = 0;
    foreach ($decoded as $value) {
        if (is_array($value)) {
            $total += count($value);
        }
    }
    return $total > 0 ? $total : count($decoded);
}

function nlp_inventory_glob_count(string $pattern, string $base = BASE_PATH): int
{
    $total = 0;
    foreach (glob($base . $pattern) ?: [] as $file) {
        $total += nlp_inventory_count_csv($file);
    }
    return $total;
}

/** @return list<array{id:string,label:string,category:string,path:string,rows:int,status:string,description:string}> */
function nlp_inventory_catalog(): array
{
    $nlp = BASE_PATH . '/data/nlp';
    $items = [
        ['id' => 'symptoms', 'label' => 'Symptoms (master)', 'category' => 'Clinical Reference', 'path' => '/data/nlp/symptoms.csv', 'description' => 'Comprehensive symptom database for registration and triage NLP.'],
        ['id' => 'symptoms_parts', 'label' => 'Symptoms (ICD-10 parts)', 'category' => 'Clinical Reference', 'path' => '/data/nlp/symptoms/symptoms_part_*.csv', 'description' => 'Partitioned symptom export files.', 'glob' => true],
        ['id' => 'conditions', 'label' => 'Medical Conditions', 'category' => 'Clinical Reference', 'path' => '/data/nlp/medical_conditions.csv', 'description' => 'ICD-10-CM conditions for fuzzy matching and validation.'],
        ['id' => 'conditions_parts', 'label' => 'Conditions (ICD-10 parts)', 'category' => 'Clinical Reference', 'path' => '/data/nlp/icd10/medical_conditions_part_*.csv', 'description' => 'Partitioned condition export files.', 'glob' => true],
        ['id' => 'allergies', 'label' => 'Allergies', 'category' => 'Clinical Reference', 'path' => '/data/nlp/allergies.csv', 'description' => 'Medication, food, and environmental allergy terms.'],
        ['id' => 'dictionary', 'label' => 'Medical Dictionary', 'category' => 'Translation', 'path' => '/data/nlp/medical_dictionary.csv', 'description' => 'Local Hiligaynon/Filipino → English medical term mapping.'],
        ['id' => 'misspellings', 'label' => 'Medical Misspellings', 'category' => 'Translation', 'path' => '/data/nlp/medical_misspellings.csv', 'description' => 'Common misspellings for fuzzy correction.'],
        ['id' => 'synonyms', 'label' => 'Symptom Synonyms', 'category' => 'Clinical Reference', 'path' => '/data/nlp/symptom_synonyms.csv', 'description' => 'Alternate symptom phrasing.'],
        ['id' => 'med_synonyms', 'label' => 'Medical Synonyms', 'category' => 'Clinical Reference', 'path' => '/data/nlp/medical_synonyms.csv', 'description' => 'General medical synonym map.'],
        ['id' => 'symptom_map', 'label' => 'Symptom → Condition Map', 'category' => 'Clinical Reference', 'path' => '/data/nlp/symptom_condition_map.csv', 'description' => 'Symptom-to-condition associations.'],
        ['id' => 'body_parts', 'label' => 'Body Parts', 'category' => 'Clinical Reference', 'path' => '/data/nlp/body_parts.csv', 'description' => 'Anatomical regions for pain/symptom localization.'],
        ['id' => 'body_pain', 'label' => 'Body Part Pain Symptoms', 'category' => 'Clinical Reference', 'path' => '/data/nlp/body_part_pain_symptoms.csv', 'description' => 'Localized pain symptom patterns.'],
        ['id' => 'emergency_flags', 'label' => 'Emergency Flags', 'category' => 'Triage', 'path' => '/data/nlp/emergency_flags.csv', 'description' => 'Red-flag phrases triggering emergency triage.'],
        ['id' => 'condition_severity', 'label' => 'Condition Triage Severity', 'category' => 'Triage', 'path' => '/data/nlp/condition_triage_severity.csv', 'description' => 'Condition-level severity overlays.'],
        ['id' => 'triage_rules_csv', 'label' => 'Triage Rules (CSV)', 'category' => 'Triage', 'path' => '/data/nlp/triage_rules.csv', 'description' => 'Hiligaynon/English pattern rules used by the NLP triage pipeline.'],
        ['id' => 'self_care', 'label' => 'Self-Care Remedies', 'category' => 'Clinical Reference', 'path' => '/data/nlp/self_care_remedies.csv', 'description' => 'Non-urgent self-care guidance phrases.'],
        ['id' => 'hil_dataset', 'label' => 'Hiligaynon Medical NLP Dataset', 'category' => 'Hiligaynon NLP', 'path' => '/data/nlp/hiligaynon_medical_nlp_dataset.csv', 'description' => 'Primary 10k+ Hiligaynon medical NLP training rows.'],
        ['id' => 'hil_kb', 'label' => 'Hiligaynon Knowledge Base', 'category' => 'Hiligaynon NLP', 'path' => '/data/nlp/hiligaynon_medical_knowledge_base.csv', 'description' => 'Master Hiligaynon medical knowledge base.'],
        ['id' => 'hil_symptoms', 'label' => 'Hiligaynon Symptoms', 'category' => 'Hiligaynon NLP', 'path' => '/data/nlp/hiligaynon_symptoms.csv', 'description' => 'Hiligaynon symptom vocabulary.'],
        ['id' => 'hil_complaints', 'label' => 'Hiligaynon Patient Complaints', 'category' => 'Hiligaynon NLP', 'path' => '/data/nlp/hiligaynon_patient_complaints.csv', 'description' => 'Realistic patient complaint phrases.'],
        ['id' => 'hil_pain', 'label' => 'Hiligaynon Pain Recognition', 'category' => 'Hiligaynon NLP', 'path' => '/data/nlp/hiligaynon_pain_recognition.csv', 'description' => 'Pain description recognition dataset.'],
        ['id' => 'hil_phrases', 'label' => 'Symptom Phrases', 'category' => 'Hiligaynon NLP', 'path' => '/data/nlp/symptom_phrases.csv', 'description' => 'Combined symptom phrase library.'],
        ['id' => 'hil_combinatorial', 'label' => 'Combinatorial Phrases', 'category' => 'Hiligaynon NLP', 'path' => '/data/nlp/hiligaynon_combinatorial_phrases.csv', 'description' => 'Auto-generated Hiligaynon phrase combinations.'],
        ['id' => 'hil_conditions', 'label' => 'Hiligaynon Conditions', 'category' => 'Hiligaynon NLP', 'path' => '/data/nlp/hiligaynon_conditions.csv', 'description' => 'Hiligaynon condition labels.'],
        ['id' => 'hil_expansion', 'label' => 'Hiligaynon NLP Expansion 2026', 'category' => 'Hiligaynon NLP', 'path' => '/data/nlp/hiligaynon_nlp_expansion_2026.csv', 'description' => '2026 vocabulary expansion batch.'],
        ['id' => 'hil_wv', 'label' => 'Western Visayas Expansion', 'category' => 'Hiligaynon NLP', 'path' => '/data/nlp/hiligaynon_wv_expansion.csv', 'description' => 'Regional dialect expansion terms.'],
        ['id' => 'hil_repro', 'label' => 'Reproductive Health Expansion', 'category' => 'Hiligaynon NLP', 'path' => '/data/nlp/hiligaynon_reproductive_expansion.csv', 'description' => 'Reproductive health phrase expansion.'],
        ['id' => 'hil_training', 'label' => 'Medical Training Batch', 'category' => 'Hiligaynon NLP', 'path' => '/data/nlp/hiligaynon_medical_training_batch_01.csv', 'description' => 'Phrase-level training data.'],
        ['id' => 'step6_exemplars', 'label' => 'Step-6 Triage Exemplars', 'category' => 'Triage', 'path' => '/data/nlp/step6_triage_exemplars.csv', 'description' => 'Triage workflow exemplar cases.'],
        ['id' => 'emotion_intent', 'label' => 'Emotion Intent Phrases', 'category' => 'FAQ Chatbot', 'path' => '/data/nlp/emotion_intent_phrases_full.csv', 'description' => 'FAQ chatbot emotion recognition phrases (EN/FIL/HIL).'],
        ['id' => 'faq_dict_json', 'label' => 'FAQ Translation Dictionary (JSON)', 'category' => 'FAQ Chatbot', 'path' => '/data/nlp/faq_chatbot_translation_dictionary.json', 'description' => 'Seed dictionary for FAQ chatbot NLP pipeline.', 'json' => true],
        ['id' => 'phrase_roots', 'label' => 'Phrase Engine — Symptom Roots', 'category' => 'Phrase Engine', 'path' => '/data/nlp/phrase_engine/symptom_roots.json', 'description' => 'Combinatorial phrase engine roots.', 'json' => true],
        ['id' => 'phrase_templates', 'label' => 'Phrase Engine — Templates', 'category' => 'Phrase Engine', 'path' => '/data/nlp/phrase_engine/templates.json', 'description' => 'Phrase generation templates.', 'json' => true],
        ['id' => 'phrase_rules', 'label' => 'Phrase Engine — Classification Rules', 'category' => 'Phrase Engine', 'path' => '/data/nlp/phrase_engine/classification_rules.json', 'description' => 'Phrase classification rules.', 'json' => true],
        ['id' => 'hil_lexicon', 'label' => 'Symptom Lexicon (JSON)', 'category' => 'Hiligaynon NLP', 'path' => '/data/nlp/hiligaynon_symptom_lexicon.json', 'description' => 'Admin-expandable symptom lexicon.', 'json' => true],
        ['id' => 'patient_cases', 'label' => 'Patient Cases (Training)', 'category' => 'Training', 'path' => '/data/nlp/training/patient_cases.csv', 'description' => 'ML training patient case scenarios.'],
        ['id' => 'patient_complaints_train', 'label' => 'Chief Complaint Scenarios', 'category' => 'Training', 'path' => '/data/nlp/training/patient_chief_complaint_scenarios.csv', 'description' => 'Chief complaint training scenarios.'],
        ['id' => 'patient_hil_train', 'label' => 'Hiligaynon Complaint Scenarios', 'category' => 'Training', 'path' => '/data/nlp/training/patient_hiligaynon_complaint_scenarios.csv', 'description' => 'Hiligaynon complaint training set.'],
        ['id' => 'patient_realistic', 'label' => 'Realistic Patient Scenarios', 'category' => 'Training', 'path' => '/data/nlp/training/patient_realistic_scenarios.csv', 'description' => 'Realistic end-to-end patient scenarios.'],
        ['id' => 'patient_typing', 'label' => 'Patient Typing Dictionary', 'category' => 'Training', 'path' => '/data/nlp/patient_typing_dictionary_2026.csv', 'description' => 'Patient free-text typing patterns.'],
    ];

    $catalog = [];
    foreach ($items as $item) {
        $path = (string) $item['path'];
        $rows = 0;
        $status = 'missing';

        if (!empty($item['glob'])) {
            $rows = nlp_inventory_glob_count($path);
            $status = $rows > 0 ? 'loaded' : 'missing';
        } elseif (!empty($item['json'])) {
            $file = BASE_PATH . $path;
            $rows = nlp_inventory_count_json_entries($file);
            $status = is_readable($file) ? ($rows > 0 ? 'loaded' : 'empty') : 'missing';
        } else {
            $file = BASE_PATH . $path;
            $rows = nlp_inventory_count_csv($file);
            $status = is_readable($file) ? ($rows > 0 ? 'loaded' : 'empty') : 'missing';
        }

        $catalog[] = [
            'id' => $item['id'],
            'label' => $item['label'],
            'category' => $item['category'],
            'path' => $path,
            'rows' => $rows,
            'status' => $status,
            'description' => $item['description'],
        ];
    }

    return $catalog;
}

/** @return array{total_rows:int,total_datasets:int,loaded:int,missing:int,categories:array<string,int>} */
function nlp_inventory_summary(array $catalog): array
{
    $categories = [];
    $totalRows = 0;
    $loaded = 0;
    $missing = 0;
    foreach ($catalog as $row) {
        $cat = (string) $row['category'];
        $categories[$cat] = ($categories[$cat] ?? 0) + 1;
        $totalRows += (int) $row['rows'];
        if ($row['status'] === 'loaded') {
            $loaded++;
        } elseif ($row['status'] === 'missing') {
            $missing++;
        }
    }

    return [
        'total_rows' => $totalRows,
        'total_datasets' => count($catalog),
        'loaded' => $loaded,
        'missing' => $missing,
        'categories' => $categories,
    ];
}

/** @return array<string, mixed> */
function nlp_inventory_mysql_stats(PDO $pdo): array
{
    require_once BASE_PATH . '/app/core/TriageRulesLoader.php';

    $stats = [
        'translation_dictionary' => 0,
        'medical_terms' => 0,
        'conversation_history' => 0,
        'triage_rules_db' => 0,
        'csv_triage_rules' => count(TriageRulesLoader::rules()),
    ];

    try {
        require_once BASE_PATH . '/app/includes/faq_chatbot_schema.php';
        faq_chatbot_ensure_schema($pdo);
        $stats['translation_dictionary'] = (int) $pdo->query('SELECT COUNT(*) FROM translation_dictionary')->fetchColumn();
        $stats['medical_terms'] = (int) $pdo->query('SELECT COUNT(*) FROM medical_terms')->fetchColumn();
        $stats['conversation_history'] = (int) $pdo->query('SELECT COUNT(*) FROM conversation_history')->fetchColumn();
        $stats['triage_rules_db'] = (int) $pdo->query('SELECT COUNT(*) FROM triage_rules')->fetchColumn();
    } catch (Throwable $e) {
        // Schema/seed may fail in some environments; page should still render catalog.
    }

    return $stats;
}
