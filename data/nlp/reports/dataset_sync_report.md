# NLP Dataset Synchronization Report

Generated: 2026-08-06T13:14:34+08:00

## Summary

| Metric | Value |
|--------|-------|
| Datasets analyzed | 43 |
| Datasets missing | 0 |
| Runtime-loaded datasets | 25 |
| Canonical concepts | 305 |
| Registered aliases | 5335 |
| Gold validation | 0/0 (0%) |

## Sync Actions

- Exported medical_concepts_registry.csv (305 concepts)

## Dataset Catalog

| File | Status | Rows | Loader |
|------|--------|------|--------|
| `medical_symptoms.csv` | reference | 8128 | reference |
| `medical_conditions.csv` | reference | 74260 | reference |
| `symptom_synonyms.csv` | reference | 20000 | reference |
| `symptom_synonyms_expanded.csv` | reference | 45175 | reference |
| `hiligaynon_medical_terms.csv` | loaded | 14939 | SymptomKnowledgeBase CSV boosts |
| `filipino_medical_terms.csv` | loaded | 8264 | SymptomKnowledgeBase CSV boosts |
| `english_medical_terms.csv` | reference | 10012 | reference |
| `body_parts.csv` | reference | 204 | reference |
| `body_parts_cds.csv` | reference | 204 | reference |
| `pain_scale.csv` | loaded | 42 | NlpFeaturePatternsLoader |
| `duration_patterns.csv` | loaded | 500 | NlpFeaturePatternsLoader |
| `temperature_patterns.csv` | loaded | 300 | NlpFeaturePatternsLoader |
| `risk_factors.csv` | loaded | 32 | NlpFeaturePatternsLoader |
| `chronic_conditions.csv` | loaded | 19 | NlpFeaturePatternsLoader |
| `emergency_red_flags.csv` | loaded | 638 | ClinicalTriageEngine / EmergencyFlagsLoader |
| `emergency_flags.csv` | loaded | 200 | ClinicalTriageEngine / EmergencyFlagsLoader |
| `urgent_conditions.csv` | reference | 500 | reference |
| `non_urgent_conditions.csv` | reference | 500 | reference |
| `negation_words.csv` | loaded | 500 | NegationDetector |
| `medical_abbreviations.csv` | loaded | 20 | MedicalMisspellingsLoader |
| `medical_misspellings.csv` | loaded | 10928 | MedicalMisspellingsLoader |
| `misspellings.csv` | loaded | 10927 | MedicalMisspellingsLoader |
| `symptom_combinations.csv` | loaded | 7388 | ClinicalTriageEngine |
| `triage_rules.csv` | loaded | 821 | TriageRulesLoader |
| `triage_rules_cds.csv` | loaded | 836 | TriageRulesLoader |
| `medical_entities.csv` | reference | 5023 | reference |
| `confidence_rules.csv` | reference | 5 | reference |
| `clinical_reasoning_rules.csv` | loaded | 25 | ClinicalReasoningRulesLoader |
| `symptom_weights.csv` | reference | 30 | reference |
| `severity_scores.csv` | reference | 3 | reference |
| `chief_complaint_examples.csv` | reference | 10522 | reference |
| `translation_dictionary.csv` | loaded | 14321 | MedicalDictionary / MedicalTranslator |
| `medical_phrases.csv` | loaded | 3181 | MedicalDictionary / MedicalTranslator |
| `common_patient_sentences.csv` | reference | 10522 | reference |
| `canonical_symptom_aliases.csv` | loaded | 48 | MedicalConceptRegistry |
| `hiligaynon_chat_shorthand.csv` | loaded | 17 | MedicalMisspellingsLoader |
| `symptom_phrases.csv` | reference | 11855 | reference |
| `symptom_phrases_seed.csv` | reference | 2500 | reference |
| `condition_triage_severity.csv` | reference | 182 | reference |
| `symptom_knowledge_base.json` | loaded | 305 | SymptomKnowledgeBase |
| `red_flags_library.json` | loaded | 75 | SymptomKnowledgeBase |
| `clinical_context_rules.json` | loaded | 0 | ClinicalContextReasoningEngine |
