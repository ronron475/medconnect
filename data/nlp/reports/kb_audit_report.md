# NLP Knowledge Base Audit Report

Generated: 2026-08-05T14:35:09.987085+00:00

## Summary

| Metric | Count |
|--------|------:|
| JSON symptoms | 305 |
| JSON red flags | 75 |
| Combination rows | 7388 |
| Unique combination pairs | 7388 |
| Emergency CSV real patterns | 754 |
| Emergency CSV padding rows | 0 |

## Gaps vs Targets

- All primary targets met.

## Conflicts


## Recommendations

- Expand symptom_knowledge_base.json to 250+ weighted symptoms (runtime scoring source).
- Expand red_flags_library.json to 50+ structured red flags with mild_exclusions.
- Replace generator padding (caseN) in emergency_red_flags.csv with real multilingual phrases.
- Add clinically distinct symptom combination pairs aligned to JSON symptom ids.
- Ensure every JSON symptom has Hiligaynon and Filipino terms for equal multilingual coverage.
- Resolve combination pairs with conflicting classifications before deployment.
