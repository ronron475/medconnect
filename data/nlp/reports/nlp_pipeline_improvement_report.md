# NLP Pipeline Improvement Report

Generated: 2026-08-06

## Issues Found

- Chat shorthand (`d nko`) not expanded before entity extraction
- `chronic_disease_detected` self-validation always failed when chronic disease was not mentioned
- Orphaned CDS CSVs (`duration_patterns`, `pain_scale`, `risk_factors`) not used at runtime
- No pipeline debug trace for misclassification diagnosis
- Breathing emergencies missed when shorthand prevented `indi ko kaginhawa` phrase match
- `clinical_reasoning_rules.csv` loaded but not used in reason generation

## Fixes Applied

- Hiligaynon chat shorthand normalization (`d nko` → `indi ko`, `kaginahawa` → `kaginhawa`)
- `ClinicalTriageEngine` preprocess chain: normalize → misspellings
- Breathing emergency pattern scan (`scanBreathingEmergencyPatterns`) before classification
- `TriageSelfValidationEngine` chronic_disease_detected logic fix
- Expanded life-threat and breathing priority patterns
- `NlpFeaturePatternsLoader` wires duration/temperature/pain/risk CSVs
- `NlpPipelineDebug` step trace (`MEDCONNECT_NLP_DEBUG=1` or `debug=1` on demo API)
- `hiligaynon_chat_shorthand.csv` dataset added
- `ClinicalReasoningRulesLoader` integrated into emergency reason text
- Gold validation cases GOLD0026–GOLD0029 added

## Datasets Wired

| Dataset | Loader |
|---------|--------|
| `hiligaynon_chat_shorthand.csv` | `MedicalMisspellingsLoader` |
| `duration_patterns.csv` | `NlpFeaturePatternsLoader` → `ClinicalFeatureExtractors` |
| `temperature_patterns.csv` | `NlpFeaturePatternsLoader` → `ClinicalFeatureExtractors` |
| `pain_scale.csv` | `NlpFeaturePatternsLoader` → `ClinicalFeatureExtractors` |
| `risk_factors.csv` | `NlpFeaturePatternsLoader` → `ClinicalFeatureExtractors` |
| `clinical_reasoning_rules.csv` | `ClinicalReasoningRulesLoader` → `buildReason` |

## Rules Added or Corrected

- Breathing emergency scan in `ClinicalTriageEngine`
- Chat shorthand patterns in `HiligaynonTextNormalizer`
- Life-threat regex: `indi ko kaginhawa`, `(indi|dili|wala) + ginhawa` context
- Rule priority: `emergency_red_flags` overrides `individual_symptoms`
- Self-validation `chronic_disease_detected` boolean fix

## Test Cases (Before → After)

| Input | Normalized | Before | After | Pass |
|-------|------------|--------|-------|------|
| `sakit kag d nko kaginhawa` | `sakit kag indi ko kaginhawa` | NON-URGENT | **EMERGENCY** | ✓ |
| `I can't breathe` | (unchanged) | EMERGENCY | EMERGENCY | ✓ |
| `medicine refill` | (unchanged) | NON-URGENT | NON-URGENT | ✓ |
| `chest pain with difficulty breathing` | (unchanged) | EMERGENCY | EMERGENCY | ✓ |
| `Budlay ginhwa ko.` | `budlay ginhawa ko` | EMERGENCY | EMERGENCY | ✓ |

**Gold validation:** 29/29 (100%)

## Debug Mode

- Environment: `MEDCONNECT_NLP_DEBUG=1`
- Demo API: POST `debug=1` to `cds_triage_demo.php`
- Response key: `pipeline_debug` (steps: normalization → entity extraction → classification)
- CLI spot check: `php scripts/dev/test_single_triage.php "your complaint"`
