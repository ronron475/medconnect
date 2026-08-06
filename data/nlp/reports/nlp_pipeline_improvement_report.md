# NLP Pipeline Improvement Report

Generated: 2026-08-06 (audit pass 2)

## Issues Found

- Chat shorthand (`d nko`) not expanded before entity extraction
- `chronic_disease_detected` self-validation always failed when chronic disease was not mentioned
- Orphaned CDS CSVs (`duration_patterns`, `pain_scale`, `risk_factors`) not used at runtime
- No pipeline debug trace for misclassification diagnosis
- Breathing emergencies missed when shorthand prevented `indi ko kaginhawa` phrase match
- `clinical_reasoning_rules.csv` loaded but not used in reason generation
- **Compound symptom over-matching** — "sore throat" falsely matched Throat Swelling / Sore Throat with Fever
- **Short red-flag patterns** — "PE" matched inside "persistent" causing false EMERGENCY
- **Missing `corrected_words` output** in structured triage response
- **Python service preferred** over PHP rule engine despite CDS being PHP-native
- **Red-flag scan after spell correction** — "fainted" → "fainting" prevented loss-of-consciousness match

## Fixes Applied

- Hiligaynon chat shorthand normalization (`d nko` → `indi ko`, `kaginahawa` → `kaginhawa`)
- `ClinicalTriageEngine` preprocess chain: normalize → misspellings (with correction log)
- Breathing emergency pattern scan (`scanBreathingEmergencyPatterns`) before classification
- `TriageSelfValidationEngine` chronic_disease_detected logic fix
- Expanded life-threat and breathing priority patterns
- `NlpFeaturePatternsLoader` wires duration/temperature/pain/risk CSVs
- `NlpPipelineDebug` step trace (`MEDCONNECT_NLP_DEBUG=1` or `debug=1` on demo API)
- `hiligaynon_chat_shorthand.csv` dataset added
- `ClinicalReasoningRulesLoader` integrated into emergency reason text
- **`SymptomKnowledgeBase::compoundQualifiersSatisfied`** — blocks single-token matches on multi-word symptoms
- **`ClinicalTriageEngine::filterContextualSymptomMatches`** — post-filter for pregnancy/fever/swelling qualifiers
- **Mild severity modifier** in `scoreFromKb` when complaint contains mild/slight
- **`MedicalMisspellingsLoader::applyCorrectionsWithLog`** — returns `corrected_words` array
- **`MedicalAssessmentEngine`** — PHP NLP default (`MEDCONNECT_PHP_NLP_ONLY=1`)
- **Word-boundary red-flag matching** for patterns ≤3 chars (PE, etc.)
- **Pre-correction red-flag scan** preserves `fainted` before `fainting` normalization
- Loss of consciousness patterns expanded: `fainted`, `fainting`, `i fainted`

## Datasets Wired

| Dataset | Loader |
|---------|--------|
| `hiligaynon_chat_shorthand.csv` | `MedicalMisspellingsLoader` |
| `duration_patterns.csv` | `NlpFeaturePatternsLoader` → `ClinicalFeatureExtractors` |
| `temperature_patterns.csv` | `NlpFeaturePatternsLoader` → `ClinicalFeatureExtractors` |
| `pain_scale.csv` | `NlpFeaturePatternsLoader` → `ClinicalFeatureExtractors` |
| `risk_factors.csv` | `NlpFeaturePatternsLoader` → `ClinicalFeatureExtractors` |
| `clinical_reasoning_rules.csv` | `ClinicalReasoningRulesLoader` → `buildReason` |
| `medical_misspellings.csv` | `fainted` → `fainting` verb form |
| `red_flags_library.json` | Loss of consciousness patterns expanded |
| `emergency_red_flags.csv` | `fainted` / `i fainted` patterns |

## Rules Added or Corrected

- Breathing emergency scan in `ClinicalTriageEngine`
- Chat shorthand patterns in `HiligaynonTextNormalizer`
- Life-threat regex: `indi ko kaginhawa`, `(indi|dili|wala) + ginhawa` context
- Rule priority: `emergency_red_flags` overrides `individual_symptoms`
- Self-validation `chronic_disease_detected` boolean fix
- Compound symptom context gating (no keyword-only triage)
- Mild complaint down-scoring

## Test Cases (Before → After)

| Input | Normalized | Before | After | Pass |
|-------|------------|--------|-------|------|
| `sakit kag d nko kaginhawa` | `sakit kag indi ko kaginhawa` | NON-URGENT | **EMERGENCY** | ✓ |
| `I can't breathe` | (unchanged) | EMERGENCY | EMERGENCY | ✓ |
| `medicine refill` | (unchanged) | NON-URGENT | NON-URGENT | ✓ |
| `chest pain with difficulty breathing` | (unchanged) | EMERGENCY | EMERGENCY | ✓ |
| `Budlay ginhwa ko.` | `budlay ginhawa ko` | EMERGENCY | EMERGENCY | ✓ |
| `I have sore throat.` | (unchanged) | EMERGENCY | **NON-URGENT** | ✓ |
| `Persistent vomiting` | (unchanged) | EMERGENCY | **URGENT** | ✓ |
| `I fainted.` | `i fainting` (corrected) | NON-URGENT | **EMERGENCY** | ✓ |

**Gold validation:** 41/41 (100%)  
**Master validation (100 cases):** 99/100 (99%)

## Debug Mode

- Environment: `MEDCONNECT_NLP_DEBUG=1`
- Demo API: POST `debug=1` to `cds_triage_demo.php`
- Response key: `pipeline_debug` (steps: normalization → entity extraction → classification)
- CLI spot check: `php scripts/dev/test_single_triage.php "your complaint"`
