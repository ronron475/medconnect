# CDS Knowledge Base Expansion v2

Incremental enhancement of the rule-based Clinical Decision Support System (CDSS).
**Does not replace** the PHP/Python architecture or convert to machine learning.

## What Was Enhanced

| Component | Before | After (run expand script) |
|-----------|--------|---------------------------|
| JSON symptoms (`symptom_knowledge_base.json`) | 33 | ~300+ weighted symptoms |
| JSON red flags (`red_flags_library.json`) | 14 | 75 structured red flags |
| Symptom combinations (`symptom_combinations.csv`) | ~83 unique pairs | 2500+ unique clinical pairs |
| Emergency CSV patterns | Many generator padding rows | Real multilingual patterns from JSON |
| Hiligaynon terms | Formal only | + slang, code-switching, misspellings |
| Clinical reasoning rules | 5 templates | 25 templates |
| Rule priority (self-validation) | 15 steps | 18 steps (+ poisoning, burns, trauma) |

## Scripts

```powershell
cd c:\xampp\htdocs\medconnect

# 1. Expand knowledge base (backs up JSON to .json.bak)
python scripts/data/expand_clinical_kb_v2.py

# 2. Audit duplicates, conflicts, gaps
python scripts/data/audit_nlp_knowledge_base.py

# 3. QA validation report (start with gold, then increase limit)
php scripts/dev/triage_qa_report.php --gold
php scripts/dev/triage_qa_report.php --limit=200
```

Reports written to `data/nlp/reports/`:
- `kb_audit_report.json` / `.md`
- `triage_qa_report.json`

## Architecture Preserved

- PHP `ClinicalTriageEngine` remains the primary rule engine
- Python `clinical_triage_engine.py` mirrors PHP
- All knowledge is CSV/JSON — add rows without code changes
- ML disease classifier unchanged (reference only, not triage)
- Existing loaders: `SymptomKnowledgeBase`, `NegationDetector`, `ClinicalFeatureExtractors`

## Rule Priority Order (Self-Validation)

1. Emergency Red Flags  
2. Airway  
3. Breathing  
4. Circulation  
5. Neurological Deficits  
6. Severe Bleeding  
7. Pregnancy Emergencies  
8. Poisoning  
9. Burns  
10. Trauma  
11. High-Risk Patients  
12. Symptom Combination  
13. Duration  
14. Temperature  
15. Pain Scale  
16. Individual Symptoms  
17. Administrative Requests  
18. Confidence Score  

## Continuous Improvement

When `TriageSelfValidationEngine::suggestKnowledgeExpansion()` detects unknown phrases,
add suggested terms to the appropriate CSV (never edit core PHP):

- `hiligaynon_medical_terms.csv`
- `filipino_medical_terms.csv`
- `english_medical_terms.csv`
- `misspellings.csv`
- `symptom_combinations.csv`
- `emergency_red_flags.csv`
- `chief_complaint_examples.csv`

Then re-run `expand_clinical_kb_v2.py` or add JSON entries manually for weighted symptoms.

## Deployment Reminder

Local changes do **not** auto-sync to Railway. After KB updates:

```powershell
git add data/nlp app/core/TriageSelfValidationEngine.php ai_service/triage_self_validation.py scripts/
git commit -m "Expand CDS knowledge base v2"
git push origin main
railway up
```
