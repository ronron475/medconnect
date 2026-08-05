# medConnect NLP Ecosystem Audit

Generated: 2026-08-05T14:35:21.864804+00:00

## Executive Summary

- **Data files scanned:** 131
- **JSON symptoms (runtime KB):** 305
- **JSON red flags:** 75
- **Contextual reasoning rules:** 6
- **Symptom combination rows:** 7388
- **Validation sets:** 10
- **PHP engines:** 10 core files
- **Python mirrors:** 5 files

### Dataset Wiring

| Status | Count |
|--------|------:|
| loaded | 41 |
| orphaned | 4 |
| reference | 86 |

## Knowledge Base Gaps

- Symptoms missing Hiligaynon terms: **0**
- Symptoms missing Filipino terms: **0**
- Duplicate symptom IDs: **0**
- High-weight symptoms without context rule: **30**

## Combination Conflicts

- None detected in sample

## Recommendations

- Add clinical_context_rules.json entries for high-weight symptoms lacking contextual reasoning
- Review 4 orphaned legacy datasets — bridge or document as reference-only
- Keep contextual reasoning as primary classifier — never single-keyword triage
- Run evaluate_triage_validation.php --gold after every KB change
- Use chief_complaint_examples.csv + validation sets for continuous QA

## Clinical Decision Priority (Runtime)

1. emergency_red_flags
2. airway
3. breathing
4. circulation
5. neurological
6. severe_bleeding
7. pregnancy_emergency
8. poisoning
9. burns
10. trauma
11. high_risk_patient
12. symptom_combination
13. clinical_context
14. duration
15. temperature
16. pain_scale
17. risk_factors
18. individual_symptoms
19. administrative_request
20. confidence_score
