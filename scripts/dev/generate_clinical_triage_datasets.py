#!/usr/bin/env python3
"""Generate standards-based clinical triage datasets: emergency_flags.csv + triage_rules.csv."""

from __future__ import annotations

import csv
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
NLP = ROOT / "data" / "nlp"

EMERGENCY_FLAGS = [
    ("EF001", "Chest Pain", "masakit dughan ko", "chest pain", "cardiovascular", "cardiac", "EMERGENCY", "critical", "Acute chest pain may indicate myocardial ischemia or pulmonary embolism"),
    ("EF002", "Chest Pain", "sakit dughan", "chest pain", "cardiovascular", "cardiac", "EMERGENCY", "critical", "Chest pain requires immediate emergency evaluation"),
    ("EF003", "Respiratory Distress", "budlay magginhawa ko", "difficulty breathing", "respiratory", "breathing", "EMERGENCY", "critical", "Airway or respiratory compromise"),
    ("EF004", "Respiratory Distress", "indi ko makaginhawa", "cannot breathe", "respiratory", "breathing", "EMERGENCY", "critical", "Severe respiratory distress"),
    ("EF005", "Respiratory Distress", "dula ginhawa ko", "shortness of breath", "respiratory", "breathing", "EMERGENCY", "critical", "Hypoxia risk — emergency assessment required"),
    ("EF006", "Severe Bleeding", "grabe gid nagadugo", "severe bleeding", "general", "bleeding", "EMERGENCY", "critical", "Uncontrolled hemorrhage"),
    ("EF007", "Severe Bleeding", "indi mapunggan ang dugo", "uncontrolled bleeding", "general", "bleeding", "EMERGENCY", "critical", "Hemorrhage not controlled"),
    ("EF008", "Severe Bleeding", "nagdugo ulo ko", "head bleeding", "neurological", "bleeding", "EMERGENCY", "critical", "Head trauma with bleeding"),
    ("EF009", "Loss of Consciousness", "nadulaan ko malay", "loss of consciousness", "neurological", "consciousness", "EMERGENCY", "critical", "Altered consciousness — ABCs priority"),
    ("EF010", "Loss of Consciousness", "nagpunaw ko", "loss of consciousness", "neurological", "consciousness", "EMERGENCY", "critical", "Syncope or collapse"),
    ("EF011", "Stroke Symptoms", "daw indi ko makahambal", "speech difficulty", "neurological", "neurological", "EMERGENCY", "critical", "Possible acute stroke — FAST criteria"),
    ("EF012", "Stroke Symptoms", "daw indi ko makabaton sang kamot ko", "arm weakness", "neurological", "neurological", "EMERGENCY", "critical", "Focal neurological deficit"),
    ("EF013", "Seizure", "naguyam ko", "seizure", "neurological", "neurological", "EMERGENCY", "critical", "Active or recent seizure activity"),
    ("EF014", "Severe Allergic Reaction", "gahubag lawas ko kag budlay magginhawa", "anaphylaxis", "allergy", "allergy", "EMERGENCY", "critical", "Anaphylaxis with airway involvement"),
    ("EF015", "Suicidal Ideation", "gusto ko magpakamatay", "suicidal ideation", "mental_health", "psychiatric", "EMERGENCY", "critical", "Suicide risk — immediate safety assessment"),
    ("EF016", "Major Trauma", "nabunggo ko sa salakyan", "vehicle collision injury", "trauma", "trauma", "EMERGENCY", "critical", "High-energy trauma mechanism"),
    ("EF017", "Amputation", "nautod ari ko", "penile amputation", "trauma", "trauma", "EMERGENCY", "critical", "Major tissue loss — surgical emergency"),
    ("EF018", "Amputation", "nautod tudlo ko", "amputated finger", "trauma", "trauma", "EMERGENCY", "critical", "Traumatic amputation"),
    ("EF019", "Electrical Injury", "nakuryente ko kag nadulaan ko malay", "electrical injury with altered consciousness", "trauma", "trauma", "EMERGENCY", "critical", "Electrical injury with neurological compromise"),
    ("EF020", "Electrical Injury", "nakuryente gid ko", "electrical injury", "trauma", "trauma", "EMERGENCY", "critical", "Electrical injury — cardiac arrhythmia risk"),
    ("EF021", "Severe Burns", "nasunog lawas ko", "body burn", "dermatological", "burn", "EMERGENCY", "critical", "Major burn — fluid resuscitation may be required"),
    ("EF022", "Urinary Retention", "wala ko maka-ihi", "urinary retention", "urinary", "urinary", "EMERGENCY", "severe", "Acute urinary retention"),
    ("EF023", "Severe Head Injury", "nagdugo ulo ko pagkatapos natumba", "head bleeding after fall", "neurological", "trauma", "EMERGENCY", "critical", "Head injury with bleeding post fall"),
    ("EF024", "Choking", "wala ko maka-ginhawa tungod sa pagkaon", "choking", "respiratory", "breathing", "EMERGENCY", "critical", "Airway obstruction"),
    ("EF025", "Poisoning", "naka-inom ko sang lason", "poisoning", "general", "toxicology", "EMERGENCY", "critical", "Toxic ingestion"),
    # Expanded Western Visayas / clinical emergency coverage
    ("EF026", "Chest Pain", "hapdi dughan ko", "chest pain", "cardiovascular", "cardiac", "EMERGENCY", "critical", "Chest discomfort — rule out ACS"),
    ("EF027", "Chest Pain", "masakit dibdib ko kag dula ginhawa", "chest pain with shortness of breath", "cardiovascular", "cardiac", "EMERGENCY", "critical", "Chest pain with dyspnea"),
    ("EF028", "Chest Pain", "naga pitik dughan ko kag masakit", "chest pain with palpitations", "cardiovascular", "cardiac", "EMERGENCY", "critical", "Chest pain with arrhythmia symptoms"),
    ("EF029", "Respiratory Distress", "lisod gid magginhawa", "severe difficulty breathing", "respiratory", "breathing", "EMERGENCY", "critical", "Severe dyspnea"),
    ("EF030", "Respiratory Distress", "ginakapos gid ginhawa ko", "air hunger", "respiratory", "breathing", "EMERGENCY", "critical", "Air hunger — hypoxia risk"),
    ("EF031", "Respiratory Distress", "nagahubak kag budlay magginhawa", "wheezing with dyspnea", "respiratory", "breathing", "EMERGENCY", "critical", "Severe bronchospasm"),
    ("EF032", "Respiratory Distress", "nagasinghot blood", "coughing blood", "respiratory", "breathing", "EMERGENCY", "critical", "Hemoptysis"),
    ("EF033", "Respiratory Distress", "nagauba dugo", "coughing blood", "respiratory", "breathing", "EMERGENCY", "critical", "Hemoptysis"),
    ("EF034", "Severe Bleeding", "grabe nagadugo kamot ko", "severe hand bleeding", "general", "bleeding", "EMERGENCY", "critical", "Uncontrolled extremity hemorrhage"),
    ("EF035", "Severe Bleeding", "indi mapunggan dugo sa pilas", "uncontrolled wound bleeding", "general", "bleeding", "EMERGENCY", "critical", "Wound hemorrhage not controlled"),
    ("EF036", "Severe Bleeding", "grabe gid nagadugo bilat", "severe vaginal bleeding", "gynecologic", "bleeding", "EMERGENCY", "critical", "Massive vaginal hemorrhage"),
    ("EF037", "Severe Bleeding", "grabe gid nagadugo ari", "severe penile bleeding", "male_reproductive", "bleeding", "EMERGENCY", "critical", "Severe genital hemorrhage"),
    ("EF038", "Loss of Consciousness", "nalipong ko kag natumba", "syncope with fall", "neurological", "consciousness", "EMERGENCY", "critical", "Syncope with collapse"),
    ("EF039", "Loss of Consciousness", "wala ko malay", "unresponsive", "neurological", "consciousness", "EMERGENCY", "critical", "Unresponsive patient"),
    ("EF040", "Loss of Consciousness", "nahilo gid kag nagapunaw", "severe dizziness with fainting", "neurological", "consciousness", "EMERGENCY", "critical", "Presyncope progressing to syncope"),
    ("EF041", "Stroke Symptoms", "nawala gahambal ko", "sudden speech loss", "neurological", "neurological", "EMERGENCY", "critical", "Sudden aphasia — stroke alert"),
    ("EF042", "Stroke Symptoms", "naparalysis ang atubang ko", "facial paralysis", "neurological", "neurological", "EMERGENCY", "critical", "Facial droop — FAST stroke criteria"),
    ("EF043", "Stroke Symptoms", "kalit naluya ang kamot kag tiil", "sudden limb weakness", "neurological", "neurological", "EMERGENCY", "critical", "Sudden unilateral weakness"),
    ("EF044", "Stroke Symptoms", "kalit nabulag isa ka mata", "sudden vision loss one eye", "neurological", "neurological", "EMERGENCY", "critical", "Sudden monocular vision loss"),
    ("EF045", "Seizure", "nagakombulsyon ko", "convulsions", "neurological", "neurological", "EMERGENCY", "critical", "Active convulsions"),
    ("EF046", "Seizure", "atake sang bakukang", "seizure attack", "neurological", "neurological", "EMERGENCY", "critical", "Seizure activity (local slang)"),
    ("EF047", "Severe Allergic Reaction", "namaga dila ko kag budlay magginhawa", "tongue swelling with dyspnea", "allergy", "allergy", "EMERGENCY", "critical", "Angioedema with airway threat"),
    ("EF048", "Severe Allergic Reaction", "gahubag ngabil kag indi magginhawa", "lip swelling with breathing difficulty", "allergy", "allergy", "EMERGENCY", "critical", "Anaphylaxis airway involvement"),
    ("EF049", "Severe Allergic Reaction", "kalit gakatol tanan lawas kag naluya", "sudden whole-body itching with weakness", "allergy", "allergy", "EMERGENCY", "critical", "Systemic allergic reaction"),
    ("EF050", "Suicidal Ideation", "wala na ko sang paglaum kag gusto matapos", "hopelessness with suicidal intent", "mental_health", "psychiatric", "EMERGENCY", "critical", "Active suicidal ideation"),
    ("EF051", "Suicidal Ideation", "plano ko maghikog", "suicide plan", "mental_health", "psychiatric", "EMERGENCY", "critical", "Suicide plan disclosed"),
    ("EF052", "Major Trauma", "natumba sa taas nga lugar", "fall from height", "trauma", "trauma", "EMERGENCY", "critical", "High-energy fall mechanism"),
    ("EF053", "Major Trauma", "ginbunalan kag nawala malay", "assault with loss of consciousness", "trauma", "trauma", "EMERGENCY", "critical", "Trauma with altered consciousness"),
    ("EF054", "Major Trauma", "nabangga sang motorsiklo", "motorcycle collision", "trauma", "trauma", "EMERGENCY", "critical", "High-energy traffic injury"),
    ("EF055", "Amputation", "nabungtol ang tudlo ko", "finger avulsion", "trauma", "trauma", "EMERGENCY", "critical", "Traumatic digit loss"),
    ("EF056", "Amputation", "nautod ang kamot ko", "hand amputation", "trauma", "trauma", "EMERGENCY", "critical", "Major traumatic amputation"),
    ("EF057", "Electrical Injury", "nakuryente ko kag nagkulba dughan", "electrical injury with chest discomfort", "trauma", "trauma", "EMERGENCY", "critical", "Electrical injury — cardiac risk"),
    ("EF058", "Severe Burns", "nasunog dughan kag likod ko", "chest and back burns", "dermatological", "burn", "EMERGENCY", "critical", "Extensive burns of trunk"),
    ("EF059", "Severe Burns", "nasunog sang asido", "chemical burn", "dermatological", "burn", "EMERGENCY", "critical", "Chemical burn injury"),
    ("EF060", "Urinary Retention", "indi ko gid makaihi", "complete inability to urinate", "urinary", "urinary", "EMERGENCY", "severe", "Complete urinary retention"),
    ("EF061", "Severe Head Injury", "nabun-og ulo pagkatapos nabangga", "head bruise after collision", "neurological", "trauma", "EMERGENCY", "critical", "Head trauma after impact"),
    ("EF062", "Severe Head Injury", "nagsuka pagkatapos napukol ulo", "vomiting after head blow", "neurological", "trauma", "EMERGENCY", "critical", "Post-traumatic vomiting"),
    ("EF063", "Choking", "naipit sang bukog sa tutunlan", "bone stuck in throat", "respiratory", "breathing", "EMERGENCY", "critical", "Foreign body airway obstruction"),
    ("EF064", "Poisoning", "naka-inom ko sang kerosene", "kerosene ingestion", "general", "toxicology", "EMERGENCY", "critical", "Hydrocarbon ingestion"),
    ("EF065", "Poisoning", "naka-inom sang scrub / chlorine", "chlorine or cleaning agent ingestion", "general", "toxicology", "EMERGENCY", "critical", "Caustic/toxic ingestion"),
    ("EF066", "Pregnancy Emergency", "grabe sakit tiyan kag may dugo samtang buntis", "abdominal pain with bleeding in pregnancy", "gynecologic", "obstetric", "EMERGENCY", "critical", "Possible ectopic pregnancy or miscarriage complication"),
    ("EF067", "Pregnancy Emergency", "gadugo gid samtang buntis ko", "heavy bleeding while pregnant", "gynecologic", "obstetric", "EMERGENCY", "critical", "Obstetric hemorrhage"),
    ("EF068", "Pediatric Emergency", "indi magpukaw ang bata", "unresponsive child", "pediatric", "consciousness", "EMERGENCY", "critical", "Unresponsive pediatric patient"),
    ("EF069", "Pediatric Emergency", "indi magginhawa ang bata", "child not breathing", "pediatric", "breathing", "EMERGENCY", "critical", "Pediatric respiratory arrest risk"),
    ("EF070", "Severe Dehydration", "wala ko mag-ihi kag sobra nagsuka", "no urine output with severe vomiting", "general", "dehydration", "EMERGENCY", "severe", "Severe dehydration with oliguria"),
    ("EF071", "Severe Abdominal Pain", "grabe sakit tiyan kag gahubag", "severe abdominal pain with swelling", "gastrointestinal", "abdominal", "EMERGENCY", "critical", "Acute abdomen"),
    ("EF072", "Severe Abdominal Pain", "masakit tiyan kag nagapalamiit", "abdominal pain with rigidity", "gastrointestinal", "abdominal", "EMERGENCY", "critical", "Peritoneal signs — emergency surgery risk"),
    ("EF073", "Testicular Emergency", "kalit masakit gid itlog ko", "sudden severe testicular pain", "male_reproductive", "urologic", "EMERGENCY", "critical", "Possible testicular torsion"),
    ("EF074", "Eye Emergency", "kalit nabulag mata ko", "sudden blindness", "ophthalmology", "vision", "EMERGENCY", "critical", "Sudden vision loss"),
    ("EF075", "Eye Emergency", "nagsulod sang asido sa mata", "chemical in eye", "ophthalmology", "trauma", "EMERGENCY", "critical", "Ocular chemical burn"),
    # Batch 2 — more WV emergency phrasing / clinical red flags
    ("EF076", "Chest Pain", "pig-ot dughan ko", "chest tightness", "cardiovascular", "cardiac", "EMERGENCY", "critical", "Chest tightness may indicate ischemia"),
    ("EF077", "Chest Pain", "ginahapdi dughan kag naluya", "chest pain with weakness", "cardiovascular", "cardiac", "EMERGENCY", "critical", "Chest pain with systemic weakness"),
    ("EF078", "Chest Pain", "masakit dughan paglakat", "exertional chest pain", "cardiovascular", "cardiac", "EMERGENCY", "critical", "Exertional angina pattern"),
    ("EF079", "Respiratory Distress", "ginahingal gid ako", "severe breathlessness", "respiratory", "breathing", "EMERGENCY", "critical", "Severe air hunger"),
    ("EF080", "Respiratory Distress", "indi ko matapos ang ginhawa", "cannot complete a breath", "respiratory", "breathing", "EMERGENCY", "critical", "Critical dyspnea"),
    ("EF081", "Respiratory Distress", "nagabughat kag budlay magginhawa", "stridor with dyspnea", "respiratory", "breathing", "EMERGENCY", "critical", "Upper airway obstruction risk"),
    ("EF082", "Respiratory Distress", "asul na ang ngabil ko", "blue lips", "respiratory", "breathing", "EMERGENCY", "critical", "Cyanosis"),
    ("EF083", "Respiratory Distress", "asul na daliri ko", "blue fingers", "respiratory", "breathing", "EMERGENCY", "critical", "Peripheral cyanosis"),
    ("EF084", "Severe Bleeding", "nagaibus dugo sa ilong", "severe nosebleed", "general", "bleeding", "EMERGENCY", "critical", "Uncontrolled epistaxis"),
    ("EF085", "Severe Bleeding", "nagdugo sa baba kag indi mapunggan", "uncontrolled mouth bleeding", "general", "bleeding", "EMERGENCY", "critical", "Oropharyngeal hemorrhage"),
    ("EF086", "Severe Bleeding", "nagdugo sa lubot sing grabe", "severe rectal bleeding", "gastrointestinal", "bleeding", "EMERGENCY", "critical", "Lower GI hemorrhage"),
    ("EF087", "Severe Bleeding", "nagsuka sang dugo", "vomiting blood", "gastrointestinal", "bleeding", "EMERGENCY", "critical", "Hematemesis"),
    ("EF088", "Severe Bleeding", "itim ang tae kag naluya", "black stool with weakness", "gastrointestinal", "bleeding", "EMERGENCY", "critical", "Melena with hypovolemia signs"),
    ("EF089", "Loss of Consciousness", "kalit natumba ako", "sudden collapse", "neurological", "consciousness", "EMERGENCY", "critical", "Sudden collapse"),
    ("EF090", "Loss of Consciousness", "nagalipong kag indi makatindog", "dizziness and cannot stand", "neurological", "consciousness", "EMERGENCY", "critical", "Severe presyncope"),
    ("EF091", "Stroke Symptoms", "nabalyo ang guya ko", "facial asymmetry", "neurological", "neurological", "EMERGENCY", "critical", "Facial droop"),
    ("EF092", "Stroke Symptoms", "indi ko maalsa ang isa ka kamot", "cannot raise one arm", "neurological", "neurological", "EMERGENCY", "critical", "Arm drift — FAST"),
    ("EF093", "Stroke Symptoms", "nagalumay ang panulti ko", "slurred speech", "neurological", "neurological", "EMERGENCY", "critical", "Dysarthria / stroke alert"),
    ("EF094", "Stroke Symptoms", "kalit nadula panulok", "sudden vision loss", "neurological", "neurological", "EMERGENCY", "critical", "Acute visual loss"),
    ("EF095", "Stroke Symptoms", "kalit sakit ulo nga grabe gid", "sudden worst headache", "neurological", "neurological", "EMERGENCY", "critical", "Thunderclap headache pattern"),
    ("EF096", "Seizure", "nagaluyu kag nagakurog", "stiffening and shaking", "neurological", "neurological", "EMERGENCY", "critical", "Tonic-clonic activity"),
    ("EF097", "Seizure", "nagaibus bula sa baba sa atake", "foaming at mouth during attack", "neurological", "neurological", "EMERGENCY", "critical", "Seizure with autonomic signs"),
    ("EF098", "Severe Allergic Reaction", "kalit gahubag ang lawas kag naga pula", "sudden whole-body swelling and redness", "allergy", "allergy", "EMERGENCY", "critical", "Systemic allergic reaction"),
    ("EF099", "Severe Allergic Reaction", "ginakaluyahan kag gakatol tanan", "weakness with generalized itching", "allergy", "allergy", "EMERGENCY", "critical", "Anaphylaxis prodrome"),
    ("EF100", "Severe Allergic Reaction", "nagsipa sang medicine kag budlay magginhawa", "medicine reaction with dyspnea", "allergy", "allergy", "EMERGENCY", "critical", "Drug-induced anaphylaxis"),
    ("EF101", "Suicidal Ideation", "ginahunahuna ko maghikog", "thinking of suicide", "mental_health", "psychiatric", "EMERGENCY", "critical", "Suicidal ideation"),
    ("EF102", "Suicidal Ideation", "may plano ako magpakamatay", "has suicide plan", "mental_health", "psychiatric", "EMERGENCY", "critical", "Suicide plan"),
    ("EF103", "Suicidal Ideation", "mas maayo kung wala na ako", "wish to be dead", "mental_health", "psychiatric", "EMERGENCY", "critical", "Passive death wish — urgent safety assess"),
    ("EF104", "Major Trauma", "nabangga sang trak", "hit by truck", "trauma", "trauma", "EMERGENCY", "critical", "High-energy impact"),
    ("EF105", "Major Trauma", "natumba gikan sa bubong", "fell from roof", "trauma", "trauma", "EMERGENCY", "critical", "Fall from height"),
    ("EF106", "Major Trauma", "ginpusila / nabaekan", "gunshot or stab wound", "trauma", "trauma", "EMERGENCY", "critical", "Penetrating trauma"),
    ("EF107", "Major Trauma", "nabali liog ko", "broken neck", "trauma", "trauma", "EMERGENCY", "critical", "Possible cervical spine injury"),
    ("EF108", "Amputation", "natanggal ang tudlo ko", "finger severed", "trauma", "trauma", "EMERGENCY", "critical", "Traumatic amputation"),
    ("EF109", "Amputation", "natabunan sang makina ang kamot", "hand crushed by machine", "trauma", "trauma", "EMERGENCY", "critical", "Crush amputation risk"),
    ("EF110", "Electrical Injury", "nakuryente kag nagsuka", "electrical injury with vomiting", "trauma", "trauma", "EMERGENCY", "critical", "Electrical injury systemic effects"),
    ("EF111", "Severe Burns", "nasunog ang guya", "face burn", "dermatological", "burn", "EMERGENCY", "critical", "Facial burn — airway risk"),
    ("EF112", "Severe Burns", "nasunog madamo sang lawas", "extensive body burns", "dermatological", "burn", "EMERGENCY", "critical", "Large TBSA burn"),
    ("EF113", "Severe Burns", "nasunog sang init nga tubig ang bata", "scald burn in child", "pediatric", "burn", "EMERGENCY", "critical", "Pediatric scald"),
    ("EF114", "Urinary Retention", "busog pantog kag indi makaihi", "full bladder cannot void", "urinary", "urinary", "EMERGENCY", "severe", "Acute retention"),
    ("EF115", "Severe Head Injury", "nagdugo ilong kag dulunggan pagkatapos natumba", "ear and nose bleeding after fall", "neurological", "trauma", "EMERGENCY", "critical", "Possible basilar skull fracture"),
    ("EF116", "Severe Head Injury", "nagahubag ulo kag nagsuka-suka", "head swelling with vomiting", "neurological", "trauma", "EMERGENCY", "critical", "Raised ICP concern"),
    ("EF117", "Choking", "naipit pagkaon sa tutunlan", "food stuck in throat", "respiratory", "breathing", "EMERGENCY", "critical", "Airway foreign body"),
    ("EF118", "Choking", "indi makahambal tungod may naipit", "cannot speak due to choking", "respiratory", "breathing", "EMERGENCY", "critical", "Severe airway obstruction"),
    ("EF119", "Poisoning", "nainom sang rat poison", "rat poison ingestion", "general", "toxicology", "EMERGENCY", "critical", "Rodenticide poisoning"),
    ("EF120", "Poisoning", "nainom sang insecticide", "insecticide ingestion", "general", "toxicology", "EMERGENCY", "critical", "Pesticide poisoning"),
    ("EF121", "Poisoning", "nasinghot sang smoke kag nalipong", "smoke inhalation with syncope", "respiratory", "toxicology", "EMERGENCY", "critical", "Smoke inhalation"),
    ("EF122", "Pregnancy Emergency", "nagdugo kag grabe sakit tiyan buntis", "bleeding with severe abdominal pain pregnant", "gynecologic", "obstetric", "EMERGENCY", "critical", "Ectopic / obstetric emergency"),
    ("EF123", "Pregnancy Emergency", "indi ko mabatyagan ang bata maghulag", "no fetal movement", "gynecologic", "obstetric", "EMERGENCY", "critical", "Reduced fetal movement"),
    ("EF124", "Pregnancy Emergency", "nagaawas tubig samtang buntis", "water breaking", "gynecologic", "obstetric", "EMERGENCY", "critical", "PROM / labor emergency assessment"),
    ("EF125", "Pediatric Emergency", "ginahilanat ang bata kag naguyam", "febrile child with seizure", "pediatric", "neurological", "EMERGENCY", "critical", "Febrile seizure"),
    ("EF126", "Pediatric Emergency", "indi maginom ang bata kag sobra hinog", "child not drinking and very lethargic", "pediatric", "dehydration", "EMERGENCY", "critical", "Severe pediatric dehydration"),
    ("EF127", "Pediatric Emergency", "asul ang bata pagkatapos magubo", "child cyanosed after coughing", "pediatric", "breathing", "EMERGENCY", "critical", "Pediatric respiratory emergency"),
    ("EF128", "Severe Dehydration", "makauga bibig kag indi makapatingug", "very dry mouth cannot speak well", "general", "dehydration", "EMERGENCY", "severe", "Severe dehydration"),
    ("EF129", "Severe Dehydration", "naluya gid kag wala na ihi sang adlaw", "extreme weakness no urine all day", "general", "dehydration", "EMERGENCY", "severe", "Anuria with shock risk"),
    ("EF130", "Severe Abdominal Pain", "masakit tiyan kag nagaibot itim", "abdominal pain with black stool", "gastrointestinal", "abdominal", "EMERGENCY", "critical", "GI bleed with pain"),
    ("EF131", "Severe Abdominal Pain", "kalit sakit tiyan sa tuo nga baba", "sudden right lower abdominal pain", "gastrointestinal", "abdominal", "EMERGENCY", "critical", "Appendicitis pattern"),
    ("EF132", "Testicular Emergency", "gahubag kag kalit hapdi itlog", "sudden swelling and testicular pain", "male_reproductive", "urologic", "EMERGENCY", "critical", "Torsion suspicion"),
    ("EF133", "Eye Emergency", "nagsulod sinelas / asero sa mata", "metal or foreign body in eye", "ophthalmology", "trauma", "EMERGENCY", "critical", "Ocular foreign body"),
    ("EF134", "Eye Emergency", "nabusisi ang mata kag indi makita", "eye ruptured cannot see", "ophthalmology", "trauma", "EMERGENCY", "critical", "Globe injury"),
    ("EF135", "Cardiac Arrest Concern", "indi makabayaw dughan / wala tibok", "no pulse / not breathing", "cardiovascular", "cardiac", "EMERGENCY", "critical", "Possible cardiac arrest — start BLS"),
    ("EF136", "Severe Hypertension Crisis", "grabe sakit ulo kag alta 180", "severe headache with BP 180", "cardiovascular", "cardiac", "EMERGENCY", "critical", "Hypertensive emergency pattern"),
    ("EF137", "Diabetic Emergency", "asukal sobra taas kag nalipong", "very high sugar with dizziness", "endocrine", "metabolic", "EMERGENCY", "critical", "Hyperglycemic emergency"),
    ("EF138", "Diabetic Emergency", "asukal sobra baba kag nagakurog", "very low sugar with shaking", "endocrine", "metabolic", "EMERGENCY", "critical", "Hypoglycemia"),
    ("EF139", "Severe Infection", "ginahilanat 40 kag naluya gid", "fever 40 with severe weakness", "infectious", "sepsis", "EMERGENCY", "critical", "Possible sepsis"),
    ("EF140", "Severe Infection", "mapula nagahapdi ang pilas kag may bilog na linya", "red painful wound with streaking", "infectious", "infection", "EMERGENCY", "critical", "Spreading cellulitis / lymphangitis"),
    ("EF141", "Snake Bite", "gina-kagat sang man-og", "snake bite", "trauma", "toxicology", "EMERGENCY", "critical", "Venomous bite risk"),
    ("EF142", "Drowning", "nalumos / nakainom madamo tubig", "near drowning", "respiratory", "trauma", "EMERGENCY", "critical", "Submersion injury"),
    ("EF143", "Heat Emergency", "sobra kainit lawas kag nalipong sa init", "overheating with syncope", "general", "environmental", "EMERGENCY", "critical", "Heat stroke risk"),
    ("EF144", "Hypothermia", "ginaginhawa budlay kag sobra katugnaw lawas", "cold body with weak breathing", "general", "environmental", "EMERGENCY", "critical", "Severe hypothermia"),
    ("EF145", "Violent Agitation / Psych Emergency", "nagahimo kaugalingon nga kalainan kag delikado", "dangerous self-harm behavior", "mental_health", "psychiatric", "EMERGENCY", "critical", "Immediate safety risk"),
    ("EF146", "Neonatal Emergency", "indi maghuni / indi magginhawa ang bag-ong bata", "newborn not crying or breathing", "pediatric", "neonatal", "EMERGENCY", "critical", "Neonatal resuscitation need"),
    ("EF147", "Severe Allergic Reaction", "pagkaon allergy kag gahubag tutunlan", "food allergy with throat swelling", "allergy", "allergy", "EMERGENCY", "critical", "Food anaphylaxis"),
    ("EF148", "Stroke Symptoms", "kalit nalipong kag isa ka bahin sang lawas manhid", "sudden dizziness with one-sided numbness", "neurological", "neurological", "EMERGENCY", "critical", "Cerebrovascular syndrome"),
    ("EF149", "Respiratory Distress", "COVID / pneumonia kag oxygen mubo", "pneumonia with low oxygen", "respiratory", "breathing", "EMERGENCY", "critical", "Hypoxemic respiratory failure risk"),
    ("EF150", "Severe Bleeding", "pag-opera nagdugo liwat sing grabe", "post-op severe bleeding", "general", "bleeding", "EMERGENCY", "critical", "Postoperative hemorrhage"),
    # Batch 3 — more WV / clinical emergency phrases
    ("EF151", "Chest Pain", "daw may gahugot sa dughan", "squeezing chest sensation", "cardiovascular", "cardiac", "EMERGENCY", "critical", "Classic ischemic squeeze pattern"),
    ("EF152", "Chest Pain", "sakit dughan nagakalat sa abaga", "chest pain radiating to shoulder", "cardiovascular", "cardiac", "EMERGENCY", "critical", "Radiating chest pain"),
    ("EF153", "Chest Pain", "sakit dughan kag nagasinghot sing bug-at", "chest pain with heavy sweating", "cardiovascular", "cardiac", "EMERGENCY", "critical", "Diaphoresis with chest pain"),
    ("EF154", "Respiratory Distress", "ginahingal bisan magpahuway", "breathless at rest", "respiratory", "breathing", "EMERGENCY", "critical", "Rest dyspnea"),
    ("EF155", "Respiratory Distress", "nagagamit tiyan pagginhawa", "using abdominal muscles to breathe", "respiratory", "breathing", "EMERGENCY", "critical", "Accessory muscle use"),
    ("EF156", "Respiratory Distress", "indi makatulog tungod budlay ginhawa", "cannot sleep due to dyspnea", "respiratory", "breathing", "EMERGENCY", "critical", "Orthopnea / nocturnal dyspnea"),
    ("EF157", "Stroke Symptoms", "kalit indi makahambal maayo", "sudden inability to speak well", "neurological", "neurological", "EMERGENCY", "critical", "Aphasia / dysarthria onset"),
    ("EF158", "Stroke Symptoms", "nabag-o panan-aw kag nalipong", "sudden vision change with dizziness", "neurological", "neurological", "EMERGENCY", "critical", "Posterior circulation warning"),
    ("EF159", "Stroke Symptoms", "nabulol ang panulti kag naluya isa ka kamot", "garbled speech with one weak arm", "neurological", "neurological", "EMERGENCY", "critical", "FAST positive pattern"),
    ("EF160", "Seizure", "nagakagat dila kag nagaulo", "biting tongue during attack", "neurological", "neurological", "EMERGENCY", "critical", "Seizure with oral trauma"),
    ("EF161", "Seizure", "naguyam liwat-liwag wala pa manohod", "repeated seizures without recovery", "neurological", "neurological", "EMERGENCY", "critical", "Status epilepticus risk"),
    ("EF162", "Severe Bleeding", "nagaawas dugo sa tudlo kag indi mapunggan", "uncontrolled finger bleeding", "general", "bleeding", "EMERGENCY", "critical", "Arterial or uncontrolled bleed"),
    ("EF163", "Severe Bleeding", "nagdugo sa ngipon kag bug-os panit asa", "oral bleeding with pale skin", "general", "bleeding", "EMERGENCY", "critical", "Bleeding with hypovolemia signs"),
    ("EF164", "Severe Bleeding", "nagaibot dugo madamo", "passing large amount of blood in stool", "gastrointestinal", "bleeding", "EMERGENCY", "critical", "Hematochezia — massive GI bleed"),
    ("EF165", "Loss of Consciousness", "nadulaan malay pagkatapos sakit ulo", "lost consciousness after headache", "neurological", "consciousness", "EMERGENCY", "critical", "Secondary syncope / ICH concern"),
    ("EF166", "Loss of Consciousness", "nagpunaw sa init sang adlaw", "fainted in heat", "general", "consciousness", "EMERGENCY", "severe", "Heat-related syncope — assess severity"),
    ("EF167", "Severe Allergic Reaction", "kalit gahubag ngabil kag mata", "sudden lip and eye swelling", "allergy", "allergy", "EMERGENCY", "critical", "Angioedema"),
    ("EF168", "Severe Allergic Reaction", "nakakaon shrimps kag budlay magginhawa", "shrimp allergy with dyspnea", "allergy", "allergy", "EMERGENCY", "critical", "Food anaphylaxis"),
    ("EF169", "Severe Allergic Reaction", "ginkagat putakti kag ginahubag lawas", "wasp sting with body swelling", "allergy", "allergy", "EMERGENCY", "critical", "Insect venom anaphylaxis"),
    ("EF170", "Major Trauma", "nabangga motorsiklo kag indi makahulag", "motorcycle crash cannot move", "trauma", "trauma", "EMERGENCY", "critical", "High-energy MVC with paralysis concern"),
    ("EF171", "Major Trauma", "natumba sa kahoy kag sakit grabe sa likod", "fell from tree severe back pain", "trauma", "trauma", "EMERGENCY", "critical", "Fall from height spinal injury"),
    ("EF172", "Major Trauma", "nabunggo ulo kag nagsuka", "head impact then vomiting", "neurological", "trauma", "EMERGENCY", "critical", "Concussion / intracranial injury risk"),
    ("EF173", "Poisoning", "nainom bleach / chlorine", "bleach ingestion", "general", "toxicology", "EMERGENCY", "critical", "Caustic ingestion"),
    ("EF174", "Poisoning", "nainom kerosene / gasolina", "kerosene ingestion", "general", "toxicology", "EMERGENCY", "critical", "Hydrocarbon aspiration risk"),
    ("EF175", "Poisoning", "ginpa-inom lason sang iba", "forced poisoned by someone", "general", "toxicology", "EMERGENCY", "critical", "Intentional poisoning / assault"),
    ("EF176", "Pregnancy Emergency", "buntis kag kalit grabe sakit tuo tiyan", "pregnant sudden right abdominal pain", "gynecologic", "obstetric", "EMERGENCY", "critical", "Ectopic pregnancy pattern"),
    ("EF177", "Pregnancy Emergency", "buntis kag nagdugo madamo", "pregnant heavy bleeding", "gynecologic", "obstetric", "EMERGENCY", "critical", "Antepartum hemorrhage"),
    ("EF178", "Pregnancy Emergency", "nagakontraksyon mga bulan pa", "early strong contractions", "gynecologic", "obstetric", "EMERGENCY", "critical", "Preterm labor risk"),
    ("EF179", "Pediatric Emergency", "bata gahingal kag asul", "child breathless and blue", "pediatric", "breathing", "EMERGENCY", "critical", "Pediatric cyanosis"),
    ("EF180", "Pediatric Emergency", "bata indi magmata / sobra hinog", "child unresponsive lethargy", "pediatric", "consciousness", "EMERGENCY", "critical", "Altered LOC in child"),
    ("EF181", "Pediatric Emergency", "bata may tae dugo kag nagsuka", "child bloody stool and vomiting", "pediatric", "gastrointestinal", "EMERGENCY", "critical", "Pediatric GI emergency"),
    ("EF182", "Diabetic Emergency", "diabetis kag nagsuka kag ginahingal", "diabetic vomiting and dyspnea", "endocrine", "metabolic", "EMERGENCY", "critical", "Possible DKA"),
    ("EF183", "Diabetic Emergency", "asukal baba kag indi magmata", "low sugar unresponsive", "endocrine", "metabolic", "EMERGENCY", "critical", "Severe hypoglycemia"),
    ("EF184", "Severe Infection", "may pilas kag may nana nga itim / baho gid", "wound with black foul discharge", "infectious", "infection", "EMERGENCY", "critical", "Necrotizing infection concern"),
    ("EF185", "Severe Infection", "ginahilanat kag suga sa liog magtig-a", "fever with stiff neck", "infectious", "neurological", "EMERGENCY", "critical", "Meningitis pattern"),
    ("EF186", "Severe Infection", "sakit ihi kag ginahilanat gid kag naluya", "dysuria fever severe weakness", "infectious", "urinary", "EMERGENCY", "critical", "Possible urosepsis"),
    ("EF187", "Eye Emergency", "kalit nabuta ang isa ka mata", "sudden blindness one eye", "ophthalmology", "vision", "EMERGENCY", "critical", "Acute vision loss"),
    ("EF188", "Eye Emergency", "nagsulod chemical / paint sa mata", "chemical or paint in eye", "ophthalmology", "trauma", "EMERGENCY", "critical", "Chemical eye injury"),
    ("EF189", "Urinary Retention", "indi makaihi sang adlaw kag sakit pantog", "cannot void all day bladder pain", "urinary", "urinary", "EMERGENCY", "severe", "Acute urinary retention"),
    ("EF190", "Testicular Emergency", "kalit sakit itlog kag nagaubos", "sudden testicular pain elevation", "male_reproductive", "urologic", "EMERGENCY", "critical", "Torsion — time critical"),
    ("EF191", "Cardiac Concern", "nagatibok dughan madasig kag nalipong", "racing heartbeat with syncope", "cardiovascular", "cardiac", "EMERGENCY", "critical", "Arrhythmia with syncope"),
    ("EF192", "Cardiac Concern", "naghunong tibok / CPR ang kabataan", "pulse stopped CPR started", "cardiovascular", "cardiac", "EMERGENCY", "critical", "Cardiac arrest in progress"),
    ("EF193", "Violent Threat", "may armas kag buot makapatay", "armed and intent to kill", "mental_health", "psychiatric", "EMERGENCY", "critical", "Immediate safety / police emergency"),
    ("EF194", "Violent Threat", "ginaasikaso nga may plano maghikog karong adlaw", "active suicide plan today", "mental_health", "psychiatric", "EMERGENCY", "critical", "Imminent suicide risk"),
    ("EF195", "Bite / Envenomation", "ginkagat aso / iro kag nagdugo", "dog bite bleeding", "trauma", "infection", "EMERGENCY", "severe", "Animal bite — rabies / wound care"),
    ("EF196", "Bite / Envenomation", "ginkagat sawa kag nagahubag", "snake bite with swelling", "trauma", "toxicology", "EMERGENCY", "critical", "Venomous snakebite"),
    ("EF197", "Drowning", "nalumos sa dagat kag nagaubo tubig", "near-drowned coughing water", "respiratory", "trauma", "EMERGENCY", "critical", "Submersion — delayed pulmonary edema risk"),
    ("EF198", "Environmental", "ginainitan lawas 40+ kag wala singhot", "body heat 40+ no sweat", "general", "environmental", "EMERGENCY", "critical", "Heat stroke"),
    ("EF199", "Environmental", "nahuyog sa bugnaw tubig kag ginahingal", "fell in cold water dyspneic", "general", "environmental", "EMERGENCY", "critical", "Cold water immersion"),
    ("EF200", "Abdominal Emergency", "kalit sakit tiyan kag nagsakit gid sa tuo baba", "sudden RLQ abdominal pain", "gastrointestinal", "abdominal", "EMERGENCY", "critical", "Acute abdomen / appendicitis"),
]

TRIAGE_RULES_EXTRA = [
    ("kakatol bilat ko", "vaginal itching", "non_urgent", "mild", "female_reproductive", "Mild localized itching without systemic or red-flag features", "dermatological"),
    ("gakatol bilat ko", "vaginal itching", "non_urgent", "mild", "female_reproductive", "Mild pruritus — routine evaluation if persistent", "dermatological"),
    ("galagas buhok ko", "hair loss", "non_urgent", "mild", "dermatological", "Non-acute hair loss — routine consultation", "dermatological"),
    ("ubo ko", "cough", "non_urgent", "mild", "respiratory", "Mild isolated cough without red flags", "respiratory"),
    ("sakit ulo ko", "head pain", "non_urgent", "mild", "neurological", "Mild headache without neurological deficits", "neurological"),
    ("may nana sa bilat ko", "vaginal infection", "urgent", "moderate", "infection", "Purulent genital discharge suggests infection requiring timely assessment", "infection"),
    ("may nana sa ari ko", "penile infection", "urgent", "moderate", "infection", "Genital infection with discharge — urgent evaluation", "infection"),
    ("may nana akon mata", "eye infection", "urgent", "moderate", "infection", "Eye pain with purulent discharge suggests ocular infection", "infection"),
    ("gahabok itlog ko", "testicular swelling", "urgent", "moderate", "male_reproductive", "Testicular swelling requires urgent evaluation to exclude torsion", "male_reproductive"),
    ("gahubag itlog ko", "testicular swelling", "urgent", "moderate", "male_reproductive", "Scrotal swelling — urgent urological assessment", "male_reproductive"),
    ("gadugo bilat ko", "vaginal bleeding", "urgent", "moderate", "gynecologic", "Non-massive vaginal bleeding — urgent gynecologic assessment", "gynecologic"),
    ("gadugo ari ko", "penile bleeding", "urgent", "moderate", "male_reproductive", "Genital bleeding — urgent evaluation", "male_reproductive"),
    ("ginahilanat gid ko", "high fever", "urgent", "moderate", "general", "High fever — assess for systemic infection", "general"),
    ("ginahilanat ko kag gahika ko", "fever with cough", "urgent", "moderate", "respiratory", "Fever with respiratory symptoms — assess for systemic infection", "respiratory"),
    ("ginabaldom gid ko", "severe abdominal pain", "urgent", "moderate", "gastrointestinal", "Significant abdominal pain — urgent surgical/medical evaluation", "gastrointestinal"),
    ("alta presyon ko", "hypertension", "urgent", "moderate", "cardiovascular", "Elevated blood pressure symptoms — urgent monitoring", "cardiovascular"),
    ("masakit pag-ihi ko", "painful urination", "urgent", "moderate", "urinary", "Dysuria may indicate UTI — timely treatment needed", "urinary"),
    ("grabe gid nagadugo bilat ko", "severe vaginal bleeding", "emergency", "critical", "gynecologic", "Massive or uncontrolled bleeding — emergency care", "bleeding"),
    ("indi mapunggan ang dugo sa ari", "uncontrolled penile bleeding", "emergency", "critical", "bleeding", "Uncontrolled genital hemorrhage", "bleeding"),
    ("masakit dughan ko", "chest pain", "emergency", "critical", "cardiovascular", "Chest pain — rule out acute coronary syndrome", "cardiac"),
    ("budlay magginhawa ko", "difficulty breathing", "emergency", "critical", "respiratory", "Respiratory distress — emergency airway assessment", "breathing"),
    ("nagdugo ulo ko", "head bleeding", "emergency", "critical", "trauma", "Head trauma with bleeding", "trauma"),
    ("naguyam ko", "seizure", "emergency", "critical", "neurological", "Seizure activity — emergency neurological assessment", "neurological"),
    ("ginkagat sang ido ko", "dog bite", "urgent", "moderate", "infection", "Animal bite — wound care and rabies prophylaxis assessment", "infection"),
]

FLAG_FIELDS = ["flag_id", "flag_name", "hiligaynon_pattern", "english_pattern", "body_system", "category", "auto_triage", "severity", "clinical_rationale", "status"]
RULE_FIELDS = ["hiligaynon_pattern", "english_pattern", "triage_level", "severity", "medical_category", "reason", "body_system", "status"]


def main() -> None:
    NLP.mkdir(parents=True, exist_ok=True)

    with (NLP / "emergency_flags.csv").open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=FLAG_FIELDS)
        w.writeheader()
        for row in EMERGENCY_FLAGS:
            w.writerow({**dict(zip(FLAG_FIELDS[:9], row)), "status": "active"})

    existing_rules: dict[str, dict] = {}
    rules_path = NLP / "triage_rules.csv"
    if rules_path.is_file():
        with rules_path.open(encoding="utf-8", newline="") as f:
            for row in csv.DictReader(f):
                key = (row.get("hiligaynon_pattern") or "").lower()
                if key:
                    existing_rules[key] = row

    for hil, eng, tri, sev, cat, reason, body_sys in TRIAGE_RULES_EXTRA:
        key = hil.lower()
        if key not in existing_rules:
            existing_rules[key] = {
                "hiligaynon_pattern": hil,
                "english_pattern": eng,
                "triage_level": tri,
                "severity": sev,
                "medical_category": cat,
                "reason": reason,
                "body_system": body_sys,
                "status": "active",
            }

    with rules_path.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=RULE_FIELDS, extrasaction="ignore")
        w.writeheader()
        for row in sorted(existing_rules.values(), key=lambda r: (r.get("triage_level", ""), r.get("hiligaynon_pattern", ""))):
            if "body_system" not in row:
                row["body_system"] = row.get("medical_category", "general")
            if "status" not in row:
                row["status"] = "active"
            w.writerow(row)

    print(f"Wrote emergency_flags.csv ({len(EMERGENCY_FLAGS)} flags)")
    print(f"Wrote triage_rules.csv ({len(existing_rules)} rules)")


if __name__ == "__main__":
    main()
