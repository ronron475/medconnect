# medConnect NLP Ecosystem Audit

Complete audit and optimization guide for the rule-based Clinical Decision Support (CDS) NLP stack.

## Run audits

```bash
# Knowledge base duplicates, conflicts, coverage gaps
python scripts/data/audit_nlp_knowledge_base.py

# Full ecosystem: all datasets, engine wiring, validation sets
python scripts/data/audit_nlp_ecosystem.py

# Safe optimizations (sync multilingual terms, prune weak keywords)
python scripts/data/optimize_nlp_ecosystem.py

# PHP inventory summary
php scripts/dev/nlp_inventory_report.php

# Dataset synchronization (unified vocabulary)
php scripts/dev/sync_nlp_knowledge.php --sync --report --skip-gold

# Gold triage QA (must stay 100%)
php scripts/dev/evaluate_triage_validation.php --gold
```

## Reports

| Report | Path |
|--------|------|
| KB audit (JSON) | `data/nlp/reports/kb_audit_report.json` |
| KB audit (MD) | `data/nlp/reports/kb_audit_report.md` |
| Ecosystem audit (JSON) | `data/nlp/reports/ecosystem_audit.json` |
| Ecosystem audit (MD) | `data/nlp/reports/ecosystem_audit.md` |
| Dataset sync (JSON) | `data/nlp/reports/dataset_sync_report.json` |
| Dataset sync (MD) | `data/nlp/reports/dataset_sync_report.md` |
| Concept registry export | `data/nlp/medical_concepts_registry.csv` |

## Runtime architecture (preserved)

```
Chief complaint
  → Misspelling correction (misspellings.csv, medical_misspellings.csv, medical_abbreviations.csv)
  → Language detection (HiligaynonLanguageDetector)
  → Phrase translation (HiligaynonPhraseTranslator)
  → Canonical concept resolution (MedicalConceptRegistry — symptom_knowledge_base.json + canonical_symptom_aliases.csv)
  → Entity extraction (MedicalEntityExtractor + medical_entities.csv)
  → Symptom KB match (symptom_knowledge_base.json + hil/fil/medical_phrases CSV boosts)
  → Negation filter (negation_words.csv)
  → Feature extractors (duration, pain, temperature, risk, age)
  → Red flags (red_flags_library.json + emergency_red_flags.csv + emergency_flags.csv)
  → Context-gated red flags (ClinicalContextReasoningEngine)
  → Severity scoring + symptom_combinations.csv
  → Contextual clinical reasoning (clinical_context_rules.json)
  → Classification + confidence gating
  → Self-validation (TriageSelfValidationEngine)
  → Structured CDS output
```

## Clinical decision priority

1. Emergency red flags → 2. Airway → 3. Breathing → 4. Circulation → 5. Neurological → 6. Severe bleeding → 7. Pregnancy emergency → 8. Poisoning → 9. Burns → 10. Trauma → 11. High-risk patient → 12. Symptom combination → 13. **Clinical context** → 14. Duration → 15. Temperature → 16. Pain scale → 17. Risk factors → 18. Individual symptoms → 19. Administrative → 20. Confidence

## Key rules

- **Never classify from a single keyword** — use `ClinicalContextReasoningEngine`
- **Never diagnose or prescribe** — triage decision support only
- **Insufficient context** → `Needs Healthcare Provider Review`
- **Expand via CSV/JSON** — avoid PHP changes for new terms/rules where possible

## Demo

- CDS triage: `http://localhost/medconnect/public/nlp_cds_demo.php`
- Step 3 NLP: `http://localhost/medconnect/public/nlp_step3_demo.php`

## Datasets wired at runtime

See `ecosystem_audit.json` → `dataset_catalog` for per-file wiring status:

- **loaded** — consumed by PHP/Python engines
- **reference** — training, validation, or documentation
- **orphaned** — legacy (e.g. `triage_rules.csv`) not used by ClinicalTriageEngine v3

## Continuous QA

After any KB or rule change:

1. `php scripts/dev/sync_nlp_knowledge.php --sync --report --skip-gold`
2. `php scripts/dev/evaluate_triage_validation.php --gold`
3. `php scripts/dev/triage_qa_report.php --limit=200` (optional broader set)
4. Manual spot-check on `nlp_cds_demo.php` contextual chips
