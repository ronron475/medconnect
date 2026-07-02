#!/usr/bin/env python3
"""Generate data/nlp/hiligaynon_medical_symptoms.csv — Hiligaynon medical symptom dictionary."""

from __future__ import annotations

import csv
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "data" / "nlp" / "hiligaynon_medical_symptoms.csv"

# Each group: terms list, english, medical_term, category, severity, confidence_keywords
GROUPS: list[tuple[list[str], str, str, str, str, str]] = []

def g(terms, english, medical, category, severity, keywords):
    GROUPS.append((terms, english, medical, category, severity, keywords))


def auto_variants(term: str) -> list[str]:
    v = {term}
    v.add(term.replace("-", " "))
    v.add(term.replace("-", ""))
    v.add(term.replace(" ", "-"))
    if "tiyan" in term:
        v.add(term.replace("tiyan", "tyan"))
    if "tyan" in term:
        v.add(term.replace("tyan", "tiyan"))
    if "sip-on" in term:
        v.add(term.replace("sip-on", "sipon"))
        v.add(term.replace("sip-on", "sip on"))
    # Only add Hiligaynon-style prefixes for non-ASCII or known local patterns
    is_local = bool(re.search(r"[^a-z0-9\s\-]", term, re.I)) or any(
        p in term for p in ("sakit", "gin", "nag", "masakit", "ubo", "hilanat", "kapoy", "sip", "tiyan", "dughan", "ulo", "lawas", "katol", "kal", "budlay", "ginhawa", "ihi", "dugo", "tuhod", "likod", "dalunggan", "mata", "tutunlan", "kalipong", "kalibanga", "kasukaon", "hubag", "pamulang", "luya", "kaba", "kasubo", "regla", "bata", "buntis")
    )
    if is_local:
        if term.startswith("ga ") and "gin" not in term:
            v.add("gina " + term[3:])
            v.add("naga" + term[2:])
        if not term.startswith(("gin", "nag", "may ")):
            if " " in term:
                v.add("gin" + term)
                v.add("nag" + term)
            else:
                v.add("gin" + term)
                v.add("nag" + term)
            v.add("may " + term)
    return [x.strip() for x in v if x.strip() and len(x.strip()) >= 2]


# ── DERMATOLOGY / SKIN ───────────────────────────────────────────────────────
g(["kakatol","kakatul","katol","makatol","ga katol","gina katol","nagakakatol","kumakati","kinatol","kinatulan"],
  "itchiness","pruritus","Dermatology","Low","itch;skin;allergy;rash;scratch")
g(["kakatol lawas","kumakati lawas","ga katol lawas","katol sa lawas","kakatol sa bilog lawas"],
  "body itchiness","pruritus","Dermatology","Low","itch;body;skin")
g(["bugas","butlig","galis","pamula","pantal","pantal-pantal","rashes","nagapamula","naga pula panit"],
  "rash","rash","Dermatology","Low","skin;spots;allergy;eruption")
g(["hubag","hubag-hubag","kalisngaw","alipunga","nagahubag","ginahubag"],
  "hives","urticaria","Dermatology","Medium","swelling;allergy;skin;wheals")
g(["pamamanhid","ginapamamanhid","manhid","ginamanhid","nagamanhid","walay pagbati"],
  "numbness","paresthesia","Neurological","Medium","tingling;nerve;loss;sensation")
g(["nagakurog","ginakurog","kurog","ginatay-og","nagatay-og","nagauyog"],
  "tremor","tremor","Neurological","Medium","shaking;trembling;parkinson")
g(["nagapula panit","ginapula panit","mapula ang panit","pamula sa panit"],
  "skin redness","erythema","Dermatology","Low","red;inflammation;skin")
g(["nagabuka panit","ginabuka panit","buka sa panit","open wound"],
  "skin wound","skin lesion","Dermatology","Medium","wound;cut;abrasion")
g(["nagatulo dugo sa sugat","dugo sa sugat","nagabulos dugo"],
  "bleeding wound","hemorrhage","Emergency","Critical","bleeding;wound;trauma")
g(["nagakati mata","ginakati mata","katol sa mata"],
  "itchy eyes","ocular pruritus","Ophthalmology","Low","eye;itch;allergy")
g(["nagadula panit","nagakalas panit","skin peeling"],
  "skin peeling","desquamation","Dermatology","Low","peeling;skin;dermatitis")
g(["nagatubok","ginatubok","tubok","boil"],
  "skin boil","furuncle","Dermatology","Medium","abscess;infection;boil")
g(["nagabara pores","barado pores","blackheads"],
  "clogged pores","comedones","Dermatology","Low","acne;skin;pores")
g(["nagadula buhok","hair loss","bald spot"],
  "hair loss","alopecia","Dermatology","Low","hair;balding;alopecia")
g(["nagadula timbas","weight loss skin","dry skin"],
  "dry skin","xerosis","Dermatology","Low","dry;skin;dehydration")
g(["nagakati tuhod","nagakati siko","eczema area"],
  "eczema","eczema","Dermatology","Medium","itch;dry;skin;atopic")
g(["nagalagas buhok","falling hair"],
  "hair shedding","telogen effluvium","Dermatology","Low","hair;loss;stress")
g(["nagapula sa likod","back rash"],
  "back rash","rash","Dermatology","Low","rash;back;skin")
g(["nagatina","sunburn","mapula sa init"],
  "sunburn","sunburn","Dermatology","Low","sun;burn;red;skin")
g(["nagadula gana","loss appetite skin","malnutrition sign"],
  "malnutrition signs","malnutrition","General Medicine","Medium","weight;nutrition;weak")

# ── RESPIRATORY ──────────────────────────────────────────────────────────────
g(["ubo","ginaubo","ginauubo","nagaubo","nagauubo","ubuhan","ubuhon","kuhul","ginakuhul"],
  "cough","cough","Respiratory","Low","cough;respiratory;lung;throat")
g(["sip-on","sipon","sip on","ginasip-on","ginasipon","nagasipon","barado ilong","ginabarado ilong"],
  "runny nose","rhinitis","Respiratory","Low","nose;congestion;cold;rhinitis")
g(["ginakapos ginhawa","ginaginhawa budlay","ginabudlayan ginhawa","budlay ginhawa","lisod magginhawa","ginakapos","ginagutok"],
  "shortness of breath","dyspnea","Respiratory","High","breathing;dyspnea;asthma;lung")
g(["hubak","ginahubak","nagahubak","singaw","ginasingaw","wheezing"],
  "wheezing","wheezing","Respiratory","High","wheezing;asthma;lung;breathing")
g(["bahing","ginabahing","ginahingal","hingal","nagahingal"],
  "breathlessness","dyspnea","Respiratory","High","breath;gasping;exertion")
g(["masakit tutunlan","sakit tutunlan","hapdi tutunlan","ginasakit tutunlan","sore throat"],
  "sore throat","pharyngitis","Respiratory","Low","throat;pain;pharyngitis")
g(["masakit tutunlan mag ubo","ubo sing malala","ginauubo sing malala"],
  "severe cough","severe cough","Respiratory","Medium","cough;severe;lung")
g(["sipon kag ubo","sipon ug ubo","common cold"],
  "common cold","upper respiratory infection","Respiratory","Low","cold;viral;nose;cough")
g(["trangkaso","flu","influenza","ginatrangkaso"],
  "influenza","influenza","Infectious Disease","Medium","flu;fever;viral;respiratory")
g(["pulmonya","pneumonia","ginapulmonya"],
  "pneumonia","pneumonia","Respiratory","High","lung;infection;fever;cough")
g(["bronkitis","bronchitis","ginabronkitis"],
  "bronchitis","bronchitis","Respiratory","Medium","cough;lung;bronchitis")
g(["asma","asthma","hika","ginahika","may hika","ginasma"],
  "asthma","asthma","Respiratory","High","asthma;wheezing;breathing")
g(["dugo sa ubo","nagauubo sing dugo","bloody cough"],
  "hemoptysis","hemoptysis","Emergency","Critical","blood;cough;lung;emergency")
g(["ginatight dughan mag ginhawa","tight chest breathing"],
  "chest tightness","chest tightness","Respiratory","High","chest;asthma;breathing")
g(["nagadula boses","hoarse voice","pamalat boses"],
  "hoarseness","dysphonia","Respiratory","Low","voice;throat;larynx")
g(["nagapunaw","fainting breath","ginapunaw"],
  "fainting","syncope","Emergency","Critical","unconscious;faint;collapse")
g(["nagasingal tutunlan","swollen throat"],
  "throat swelling","pharyngeal edema","Emergency","Critical","throat;swelling;allergy;anaphylaxis")
g(["nagadula gana tungod ubo","loss appetite cough"],
  "cough with anorexia","anorexia","Respiratory","Low","cough;appetite")

# ── DIGESTIVE ────────────────────────────────────────────────────────────────
g(["sakit tiyan","sakit tyan","masakit tiyan","ginalain tiyan","panakit tiyan","sakit sikmura","masakit sikmura","ginasakit tiyan"],
  "stomach pain","abdominal pain","Gastroenterology","Medium","stomach;abdomen;pain;gi")
g(["kalibanga","gakalibanga","ginakalibanga","nagakalibanga","ginatulo","tulo-tulo","lbm","ginatulo sing dugo"],
  "diarrhea","diarrhea","Gastroenterology","Medium","diarrhea;stool;gi;infection")
g(["kasukaon","ginakasukaon","nahihilo tiyan","ginabalik tiyan","nausea"],
  "nausea","nausea","Gastroenterology","Low","nausea;vomit;stomach")
g(["suka","nagsuka","nagsusuka","sumuka","ginagsuka","ginasuka","sige ko suka"],
  "vomiting","vomiting","Gastroenterology","Medium","vomit;nausea;gi")
g(["ginapanuhot","panuhot","ginapanuhot tiyan","busog tiyan","bloated stomach"],
  "bloating","abdominal distension","Gastroenterology","Low","bloat;gas;stomach")
g(["wala gana kaon","gakadula gana kaon","wala gana","dili gana kaon","wala ko gana magkaon","ginawala gana"],
  "loss of appetite","anorexia","Gastroenterology","Low","appetite;eat;anorexia")
g(["buot","constipation","dili makalibang","ginabuot","ginadula kalibanga"],
  "constipation","constipation","Gastroenterology","Low","constipation;bowel;gi")
g(["dugo sa dumi","may dugo sa dumi","ginadugo tae","dugo sa tae"],
  "bloody stool","hematochezia","Gastroenterology","High","blood;stool;gi;bleeding")
g(["itom nga dumi","black stool","tae itom"],
  "melena","melena","Emergency","Critical","blood;stool;gi;ulcer")
g(["masakit likod tiyan","pain upper stomach"],
  "epigastric pain","epigastric pain","Gastroenterology","Medium","stomach;ulcer;pain")
g(["ginakabog","kabog","heartburn","acid reflux"],
  "heartburn","gastroesophageal reflux","Gastroenterology","Low","reflux;acid;heartburn")
g(["ginasakit tuod","appendix pain","sakit tuod"],
  "appendicitis pain","appendicitis","Emergency","Critical","appendix;pain;abdomen")
g(["ginahilanat tiyan","fever stomach"],
  "abdominal fever","intra-abdominal infection","Gastroenterology","High","fever;abdomen;infection")
g(["ginatight tiyan","cramping stomach"],
  "abdominal cramping","abdominal cramps","Gastroenterology","Medium","cramp;stomach;gi")
g(["ginadula timbang tungod suka","weight loss vomiting"],
  "weight loss with vomiting","malnutrition","Gastroenterology","Medium","vomit;weight;dehydration")
g(["ginauhaw gid","extreme thirst","sobra uhaw"],
  "excessive thirst","polydipsia","Gastroenterology","Medium","thirst;diabetes;dehydration")
g(["ginadula gana tungod tiyan","no appetite stomach"],
  "anorexia abdominal","anorexia","Gastroenterology","Low","appetite;stomach")
g(["ginasakit kilid tiyan","side abdominal pain"],
  "flank pain","flank pain","Gastroenterology","Medium","kidney;flank;pain")
g(["ginatubo tiyan","swollen belly"],
  "abdominal swelling","ascites","Gastroenterology","High","swelling;liver;abdomen")

# ── NEUROLOGICAL ─────────────────────────────────────────────────────────────
g(["sakit ulo","masakit ulo","ulo masakit","sakit sang ulo","labad ulo","labad ang ulo","ginasakit ulo","aking ulo","masakit gid akon ulo","sakit sa ulo"],
  "headache","cephalalgia","Neurological","Medium","head;pain;migraine;cephalgia")
g(["kalipong","nalipong","nagalipong","nahihilo","nahilo","ginakalipong","lipong","ginahilo"],
  "dizziness","dizziness","Neurological","Medium","dizzy;vertigo;balance")
g(["kombulsyon","konvulsyon","nagakonvulsyon","ginakonvulsyon","seizure","atake"],
  "seizure","seizure","Emergency","Critical","seizure;convulsion;epilepsy")
g(["nagalipong dayon nagahulog","fainting dizzy"],
  "syncope with dizziness","syncope","Emergency","Critical","faint;dizzy;collapse")
g(["nagadula memorya","memory loss","kalimot"],
  "memory loss","amnesia","Neurological","Medium","memory;cognitive;dementia")
g(["nagadula panulok","blurred vision neuro"],
  "visual disturbance","visual disturbance","Neurological","Medium","vision;neuro;stroke")
g(["nagaluya lawas","weakness body","luya lawas","ginahina lawas"],
  "generalized weakness","asthenia","Neurological","Medium","weak;fatigue;paralysis")
g(["nagaparalisa","paralysis","dili makagalaw","indi makahilod"],
  "paralysis","paralysis","Emergency","Critical","paralysis;stroke;weakness")
g(["nagatulin ulo","spinning head","vertigo"],
  "vertigo","vertigo","Neurological","Medium","vertigo;dizzy;inner ear")
g(["nagadula balatyag","numb face"],
  "facial numbness","facial paresthesia","Neurological","High","face;numb;stroke")
g(["nagadula speech","slurred speech","indi maathag maghambal"],
  "dysarthria","dysarthria","Emergency","Critical","speech;stroke;slurred")
g(["nagakurog kamot","shaking hands"],
  "hand tremor","tremor","Neurological","Low","tremor;hand;parkinson")
g(["nagakurog lawas","shaking body"],
  "body tremor","tremor","Neurological","Medium","tremor;shaking;fever")
g(["nagaluya kamot","weak arm","luya kamot","ginahina kamot"],
  "arm weakness","upper limb weakness","Neurological","High","arm;weak;stroke")
g(["nagaluya tiil","weak leg","luya tiil","ginahina tiil"],
  "leg weakness","lower limb weakness","Neurological","High","leg;weak;stroke")
g(["nagadula panimuot","loss sensation"],
  "sensory loss","sensory loss","Neurological","High","numb;nerve;sensation")
g(["nagapamigraine","migraine","labad ulo sing grabe"],
  "migraine","migraine","Neurological","Medium","migraine;headache;severe")
g(["nagatulin panulok","double vision"],
  "diplopia","diplopia","Neurological","High","vision;double;neuro")

# ── CARDIOVASCULAR ───────────────────────────────────────────────────────────
g(["sakit dughan","hapdi dughan","masakit dughan","masakit dibdib","sakit dibdib","ginasakit dughan","chest pain"],
  "chest pain","chest pain","Cardiovascular","Critical","chest;heart;pain;emergency")
g(["naga palpitations","palpitations","kusog tibok sang tagipusuon","kusog tibok","ginakusog tibok","mabilis tibok"],
  "palpitations","palpitations","Cardiovascular","Medium","heart;palpitation;arrhythmia")
g(["kulba","ginakulba","nagakulba","kaba","ginakaba","anxiety chest"],
  "anxiety","anxiety","Mental Health","Medium","anxiety;stress;worry")
g(["atake sa puso","atake puso","heart attack","heartattack"],
  "heart attack","myocardial infarction","Emergency","Critical","heart;attack;chest;emergency")
g(["stroke","atake sa utok","nagastroke","brain attack"],
  "stroke","cerebrovascular accident","Emergency","Critical","stroke;brain;paralysis;speech")
g(["mataas nga presyon","altapresyon","alta presyon","high blood","hypertension"],
  "hypertension","hypertension","Cardiovascular","Medium","blood pressure;hypertension")
g(["ginatight dughan","chest tightness heart"],
  "angina","angina","Cardiovascular","Critical","chest;heart;angina")
g(["ginadula ginhawa mag hilod","breathless walking"],
  "exertional dyspnea","exertional dyspnea","Cardiovascular","High","breath;heart;exertion")
g(["ginapamulang tiil","swollen feet","hubag tiil"],
  "edema","edema","Cardiovascular","Medium","swelling;feet;heart;kidney")
g(["ginadula ginhawa pagtulog","breathless sleeping"],
  "paroxysmal nocturnal dyspnea","paroxysmal nocturnal dyspnea","Cardiovascular","High","breath;heart;night")
g(["ginatibok sing irregular","irregular heartbeat"],
  "arrhythmia","arrhythmia","Cardiovascular","High","heart;irregular;arrhythmia")
g(["ginadugo ilong heart","nosebleed hypertension"],
  "epistaxis","epistaxis","Cardiovascular","Low","nose;bleed;blood pressure")
g(["ginahapdi dughan mag hilod","chest pain exertion"],
  "exertional chest pain","angina pectoris","Emergency","Critical","chest;heart;exertion")

# ── URINARY ──────────────────────────────────────────────────────────────────
g(["masakit mag ihi","masakit mag-ihi","masakit pag-ihi","sakit pag-ihi","ginasakit mag ihi"],
  "painful urination","dysuria","Urology","Medium","urine;pain;uti;infection")
g(["sige ihi","ginasige ihi","madamo ihi","ginamadamo ihi","frequent urination"],
  "frequent urination","polyuria","Urology","Medium","urine;frequent;diabetes;uti")
g(["may dugo sa ihi","dugo sa ihi","ginadugo ihi","ginadugo sa ihi","bloody urine"],
  "blood in urine","hematuria","Urology","High","blood;urine;kidney;uti")
g(["indi maka ihi","dili maka ihi","indi makaihi","dili makaihi","urinary retention"],
  "inability to urinate","urinary retention","Urology","High","urine;retention;blockage")
g(["ginauhaw tungod ihi","thirst frequent urine"],
  "diabetes symptoms","diabetes mellitus","Urology","Medium","diabetes;urine;thirst")
g(["masakit kilid mag ihi","flank pain urination"],
  "flank pain urinary","pyelonephritis","Urology","High","kidney;flank;uti")
g(["ginadula ihi","decreased urine"],
  "oliguria","oliguria","Urology","High","kidney;urine;dehydration")
g(["ginatulo ihi","leaking urine"],
  "urinary incontinence","urinary incontinence","Urology","Medium","incontinence;urine;elderly")
g(["ginauhaw kag sige ihi","thirst polyuria"],
  "polyuria polydipsia","diabetes mellitus","Urology","Medium","diabetes;urine;thirst")
g(["masakit tiyan mag ihi","bladder pain"],
  "bladder pain","cystitis","Urology","Medium","bladder;uti;pain")
g(["ginadugo ihi sing grabe","severe hematuria"],
  "severe hematuria","hematuria","Emergency","Critical","blood;urine;emergency")

# ── MUSCULOSKELETAL ──────────────────────────────────────────────────────────
g(["masakit likod","sakit likod","ginasakit likod","panakit likod","back pain"],
  "back pain","back pain","Musculoskeletal","Medium","back;pain;spine;muscle")
g(["masakit tuhod","sakit tuhod","ginasakit tuhod","knee pain"],
  "knee pain","knee pain","Musculoskeletal","Medium","knee;pain;joint")
g(["masakit kalawasan","sakit lawas","kakatol lawas","masakit lawas","sakit kalawasan","body pain"],
  "body pain","myalgia","Musculoskeletal","Low","body;pain;muscle;ache")
g(["luya kamot","ginahina kamot","mahina kamot","weak hands"],
  "arm weakness","upper limb weakness","Musculoskeletal","Medium","arm;weak;muscle")
g(["luya tiil","ginahina tiil","mahina tiil","weak legs"],
  "leg weakness","lower limb weakness","Musculoskeletal","Medium","leg;weak;muscle")
g(["masakit liog","neck pain","sakit liog"],
  "neck pain","neck pain","Musculoskeletal","Low","neck;pain;cervical")
g(["masakit siko","elbow pain","sakit siko"],
  "elbow pain","elbow pain","Musculoskeletal","Low","elbow;joint;pain")
g(["masakit balikat","shoulder pain","sakit balikat"],
  "shoulder pain","shoulder pain","Musculoskeletal","Medium","shoulder;joint;pain")
g(["masakit paa","foot pain","sakit paa"],
  "foot pain","foot pain","Musculoskeletal","Low","foot;pain;walk")
g(["masakit buko-buko","ankle pain","sakit buko-buko"],
  "ankle pain","ankle pain","Musculoskeletal","Low","ankle;joint;sprain")
g(["nagapamulang sendi","swollen joints","hubag sendi"],
  "joint swelling","arthritis","Musculoskeletal","Medium","joint;swelling;arthritis")
g(["nagakurog sendi","stiff joints","rigidity"],
  "joint stiffness","joint stiffness","Musculoskeletal","Medium","joint;stiff;arthritis")
g(["nagadula lihok","limited movement"],
  "reduced mobility","reduced mobility","Musculoskeletal","Medium","mobility;joint;pain")
g(["nagabali","fracture","nabali"],
  "fracture","fracture","Emergency","Critical","fracture;bone;trauma")
g(["nagapilas","sprain","napilas"],
  "sprain","sprain","Musculoskeletal","Medium","sprain;ligament;injury")
g(["nagapamulang kamot","swollen hand"],
  "hand swelling","hand edema","Musculoskeletal","Medium","hand;swelling;injury")
g(["nagapamulang tiil","swollen foot"],
  "foot swelling","foot edema","Musculoskeletal","Medium","foot;swelling;injury")
g(["nagadula ginhawa mag bukton","breathless climbing"],
  "exertional breathlessness","exertional dyspnea","Musculoskeletal","Medium","breath;exertion")

# ── OPHTHALMOLOGY ────────────────────────────────────────────────────────────
g(["naga pula mata","nagapula mata","pamula mata","ginapula mata","mapula mata","red eyes"],
  "red eyes","conjunctivitis","Ophthalmology","Low","eye;red;infection;allergy")
g(["malain panulok","malabo panulok","ginamalabo panulok","blurred vision"],
  "blurred vision","blurred vision","Ophthalmology","Medium","vision;blur;eye")
g(["nagatulo luha","watery eyes","dugay magluha"],
  "watery eyes","epiphora","Ophthalmology","Low","eye;water;tear")
g(["nagadula panulok","vision loss","bulag"],
  "vision loss","vision loss","Emergency","Critical","vision;blind;eye;emergency")
g(["nagakati mata","itchy eyes","katol mata"],
  "ocular itching","ocular pruritus","Ophthalmology","Low","eye;itch;allergy")
g(["nagadula kulay panulok","color vision loss"],
  "color vision defect","color vision defect","Ophthalmology","Medium","vision;color;eye")
g(["nagapamulang mata","swollen eyes","hubag mata"],
  "periorbital edema","periorbital edema","Ophthalmology","Medium","eye;swelling;allergy")
g(["nagatulo dugo mata","bloody eye"],
  "subconjunctival hemorrhage","subconjunctival hemorrhage","Ophthalmology","Medium","eye;blood;hemorrhage")
g(["nagadula panulok isa ka mata","monocular vision loss"],
  "monocular vision loss","monocular vision loss","Emergency","Critical","eye;vision;emergency")
g(["nagakurog panulok","flashing lights vision"],
  "photopsia","photopsia","Ophthalmology","Medium","vision;flashes;retina")

# ── OTOLOGY ──────────────────────────────────────────────────────────────────
g(["masakit dalunggan","sakit dalunggan","ginasakit dalunggan","ear pain"],
  "ear pain","otalgia","Otology","Medium","ear;pain;infection")
g(["naga tingog dalunggan","nagatingog dalunggan","singog dalunggan","tinnitus"],
  "ringing in ears","tinnitus","Otology","Low","ear;ringing;tinnitus")
g(["nagadula pandungog","hearing loss","bulag pandungog"],
  "hearing loss","hearing loss","Otology","Medium","hearing;deaf;ear")
g(["nagatulo dalunggan","ear discharge","tulo sa dalunggan"],
  "otorrhea","otorrhea","Otology","Medium","ear;discharge;infection")
g(["nagadula balanse","balance problem ear"],
  "vestibular disorder","vestibular disorder","Otology","Medium","balance;dizzy;ear")
g(["nagapamulang dalunggan","swollen ear"],
  "ear swelling","otitis externa","Otology","Medium","ear;swelling;infection")
g(["nagakati dalunggan","itchy ear"],
  "ear itching","pruritus aurium","Otology","Low","ear;itch;infection")

# ── MENTAL HEALTH ────────────────────────────────────────────────────────────
g(["indi katulog","dili makatulog","dili katulog","ginadula katulog","insomnia"],
  "insomnia","insomnia","Mental Health","Medium","sleep;insomnia;anxiety")
g(["sobra katulog","ginakatulog sing damo","hypersomnia"],
  "excessive sleepiness","hypersomnia","Mental Health","Low","sleep;fatigue;depression")
g(["nagabatyag kasubo","ginakasubo","kasubo","malungkot","depresyon","depression"],
  "sadness","depression","Mental Health","Medium","depression;sad;mood")
g(["kabalaka","ginakabalaka","stress","ginastress","sobra kabalaka","worry"],
  "stress","stress","Mental Health","Low","stress;anxiety;worry")
g(["nagadula gana buhi","loss interest life"],
  "anhedonia","anhedonia","Mental Health","Medium","depression;interest;mood")
g(["nagakurog kabalaka","panic attack","panic"],
  "panic attack","panic disorder","Mental Health","High","panic;anxiety;chest")
g(["nagadula gana social","social withdrawal"],
  "social withdrawal","social withdrawal","Mental Health","Medium","depression;isolation;social")
g(["nagadula focus","poor concentration"],
  "poor concentration","poor concentration","Mental Health","Low","focus;adhd;anxiety")
g(["nagahunahuna magpakamatay","suicidal thoughts"],
  "suicidal ideation","suicidal ideation","Emergency","Critical","suicide;mental;emergency")
g(["nagabangis","agitation","irritable"],
  "agitation","agitation","Mental Health","Medium","agitation;mania;psychiatric")
g(["nagadula tulog kag kapoy","fatigue insomnia"],
  "fatigue with insomnia","insomnia","Mental Health","Medium","sleep;fatigue;depression")

# ── GENERAL / FEVER ──────────────────────────────────────────────────────────
g(["hilanat","gilantan","ginahilantan","ginahilanat","mainit lawas","may lagnat","lagnat","init lawas","fever"],
  "fever","pyrexia","General Medicine","Medium","fever;temperature;infection")
g(["ginatugnaw","tugnaw","ginatugnaw lawas","ginakaligtan","chills"],
  "chills","chills","General Medicine","Low","chills;fever;cold")
g(["kapoy gid","kapoy","kakapoy","ginakapoy","ginakapoy gid","mahina lawas","luya","fatigue"],
  "fatigue","fatigue","General Medicine","Low","fatigue;tired;weakness")
g(["ginadula gana","loss energy"],
  "lethargy","lethargy","General Medicine","Medium","tired;weak;lethargy")
g(["ginahilanat sing taas","high fever","sobra hilanat"],
  "high fever","hyperpyrexia","Infectious Disease","High","fever;high;infection")
g(["ginadula timbang","weight loss","nagpayupay"],
  "weight loss","weight loss","General Medicine","Medium","weight;loss;malnutrition")
g(["ginadugang timbang","weight gain"],
  "weight gain","weight gain","General Medicine","Low","weight;gain;obesity")
g(["ginauhaw","thirst","uhaw"],
  "thirst","polydipsia","General Medicine","Low","thirst;dehydration;diabetes")
g(["ginadula gana kaon","poor appetite"],
  "poor appetite","anorexia","General Medicine","Low","appetite;eat;anorexia")
g(["ginahapdi lawas","body ache","masakit lawas gid"],
  "body aches","myalgia","General Medicine","Low","ache;body;muscle;flu")
g(["ginadula ginhawa","breathing problem general"],
  "respiratory distress","respiratory distress","Emergency","Critical","breath;emergency;lung")
g(["ginadula malay","loss consciousness","wala malay","unconscious"],
  "loss of consciousness","loss of consciousness","Emergency","Critical","unconscious;emergency;collapse")
g(["ginadugo sing grabe","severe bleeding","dugo sing damo"],
  "severe bleeding","hemorrhage","Emergency","Critical","bleeding;emergency;trauma")
g(["ginahapdi gid","severe pain"],
  "severe pain","severe pain","Emergency","High","pain;severe;emergency")

# ── WOMEN'S HEALTH ───────────────────────────────────────────────────────────
g(["masakit regla","painful menstruation","dysmenorrhea"],
  "dysmenorrhea","dysmenorrhea","Women's Health","Medium","menstruation;pain;period")
g(["madamo dugo regla","heavy period"],
  "menorrhagia","menorrhagia","Women's Health","Medium","menstruation;bleeding;period")
g(["wala regla","missed period","amenorrhea"],
  "amenorrhea","amenorrhea","Women's Health","Medium","menstruation;missed;pregnancy")
g(["masakit tiyan regla","menstrual cramps"],
  "menstrual cramps","dysmenorrhea","Women's Health","Medium","period;cramps;abdomen")
g(["nagadula regla","irregular period"],
  "irregular menstruation","irregular menstruation","Women's Health","Low","period;irregular;hormone")
g(["masakit suso","breast pain","mastalgia"],
  "breast pain","mastalgia","Women's Health","Medium","breast;pain;lump")
g(["nagapamulang suso","breast swelling"],
  "breast swelling","mastitis","Women's Health","Medium","breast;swelling;infection")
g(["nagadula gana regla","PMS appetite"],
  "premenstrual symptoms","premenstrual syndrome","Women's Health","Low","period;PMS;mood")
g(["masakit tiyan buntis","pregnancy abdominal pain"],
  "pregnancy abdominal pain","pregnancy complication","Women's Health","High","pregnancy;abdomen;pain")
g(["nagadugo buntis","pregnancy bleeding"],
  "pregnancy bleeding","pregnancy bleeding","Women's Health","Critical","pregnancy;bleeding;emergency")
g(["nagahilanat buntis","fever pregnancy"],
  "fever in pregnancy","fever in pregnancy","Women's Health","High","pregnancy;fever;infection")
g(["nagadula ginhawa buntis","breathless pregnancy"],
  "dyspnea in pregnancy","dyspnea in pregnancy","Women's Health","High","pregnancy;breath;emergency")

# ── PEDIATRIC ────────────────────────────────────────────────────────────────
g(["hilanat bata","fever child","ginahilanat ang bata"],
  "pediatric fever","pyrexia","Pediatric","Medium","child;fever;infection")
g(["ubo bata","cough child","ginauubo ang bata"],
  "pediatric cough","cough","Pediatric","Low","child;cough;respiratory")
g(["kalibanga bata","diarrhea child","ginakalibanga ang bata"],
  "pediatric diarrhea","diarrhea","Pediatric","Medium","child;diarrhea;dehydration")
g(["nagsuka bata","vomiting child","ginagsuka ang bata"],
  "pediatric vomiting","vomiting","Pediatric","Medium","child;vomit;dehydration")
g(["nagakapoy bata","tired child","luya ang bata"],
  "pediatric fatigue","fatigue","Pediatric","Low","child;tired;weak")
g(["nagahilanat sing taas bata","high fever child"],
  "pediatric high fever","hyperpyrexia","Pediatric","High","child;fever;emergency")
g(["nagadula gana kaon bata","child poor appetite"],
  "pediatric anorexia","anorexia","Pediatric","Medium","child;appetite;eat")
g(["nagakurog bata","seizure child","konvulsyon bata"],
  "pediatric seizure","seizure","Pediatric","Critical","child;seizure;emergency")
g(["nagadula ginhawa bata","child breathless"],
  "pediatric dyspnea","dyspnea","Pediatric","Critical","child;breath;emergency")
g(["nagapula bata","rash child","bugas bata"],
  "pediatric rash","rash","Pediatric","Medium","child;rash;skin")
g(["nagahubag bata","swelling child"],
  "pediatric swelling","edema","Pediatric","Medium","child;swelling;allergy")
g(["nagakati bata","itch child"],
  "pediatric pruritus","pruritus","Pediatric","Low","child;itch;skin")

# ── INFECTIOUS DISEASE ───────────────────────────────────────────────────────
g(["dengue","denggi","dengue fever","ginadengue"],
  "dengue","dengue fever","Infectious Disease","High","dengue;fever;mosquito;bleeding")
g(["typhoid","typhoid fever","tipus"],
  "typhoid fever","typhoid fever","Infectious Disease","High","fever;typhoid;infection")
g(["malaria","ginamalaria"],
  "malaria","malaria","Infectious Disease","High","fever;malaria;chills")
g(["tb","tuberculosis","t.b.","tbc","ptb"],
  "tuberculosis","tuberculosis","Infectious Disease","High","tb;lung;cough;fever")
g(["covid","covid-19","corona","coronavirus"],
  "covid-19","covid-19","Infectious Disease","High","covid;fever;cough;respiratory")
g(["impeksyon","infection","ginaimpeksyon"],
  "infection","infection","Infectious Disease","Medium","infection;fever;bacteria")
g(["sepsis","blood infection","ginasepsis"],
  "sepsis","sepsis","Emergency","Critical","sepsis;infection;fever;emergency")
g(["dengue bleeding","dugo dengue"],
  "dengue hemorrhagic fever","dengue hemorrhagic fever","Emergency","Critical","dengue;bleeding;emergency")
g(["sore with pus","nagapus"],
  "abscess","abscess","Infectious Disease","Medium","pus;infection;wound")
g(["nagahilanat tapos travel","fever travel"],
  "travel-related fever","fever of unknown origin","Infectious Disease","Medium","fever;travel;tropical")

# ── EMERGENCY PHRASES ────────────────────────────────────────────────────────
g(["ga katol akon lawas","kumakati gid lawas"],
  "body itching phrase","pruritus","Dermatology","Low","itch;body;phrase")
g(["budlay gid akon ginhawa","lisod gid magginhawa"],
  "severe breathlessness phrase","dyspnea","Emergency","Critical","breath;emergency;phrase")
g(["masakit gid akon dughan","hapdi gid dughan"],
  "severe chest pain phrase","chest pain","Emergency","Critical","chest;heart;emergency")
g(["nagahulog wala malay","collapse unconscious"],
  "collapse unconscious","syncope","Emergency","Critical","unconscious;collapse;emergency")
g(["nagadugo sing indi mauntat","uncontrolled bleeding"],
  "uncontrolled bleeding","hemorrhage","Emergency","Critical","bleeding;emergency;trauma")
g(["nagadula speech kag pamamanhid","stroke symptoms phrase"],
  "stroke symptoms","cerebrovascular accident","Emergency","Critical","stroke;speech;numb;emergency")
g(["nagakonvulsyon indi mauntat","prolonged seizure"],
  "prolonged seizure","status epilepticus","Emergency","Critical","seizure;emergency;convulsion")
g(["nagadula ginhawa kag hubag liog","anaphylaxis signs"],
  "anaphylaxis","anaphylaxis","Emergency","Critical","allergy;breathing;swelling;emergency")
g(["nagahapdi ulo sing grabe","thunderclap headache"],
  "thunderclap headache","thunderclap headache","Emergency","Critical","headache;sudden;emergency;stroke")
g(["nagadula panulok bigla","sudden vision loss"],
  "sudden vision loss","sudden vision loss","Emergency","Critical","vision;sudden;emergency")

# ── ADDITIONAL EXPANSION (chronic / common conditions) ───────────────────────
g(["diyabetes","diabetes","diabetis","may sugar","high sugar","mataas nga asukal"],
  "diabetes","diabetes mellitus","General Medicine","Medium","diabetes;sugar;thirst;urine")
g(["asma attack","asma sing grabe","severe asthma"],
  "severe asthma","asthma exacerbation","Respiratory","Critical","asthma;breath;emergency")
g(["sakit kidney","sakit sa batok","kidney pain"],
  "kidney pain","renal pain","Urology","High","kidney;pain;stone")
g(["bato sa kidney","kidney stones","renal stone"],
  "kidney stones","nephrolithiasis","Urology","High","kidney;stone;pain")
g(["uti","urinary tract infection","sakit pagihi"],
  "urinary tract infection","urinary tract infection","Urology","Medium","uti;urine;infection")
g(["hepatitis","sakit atay","liver pain"],
  "hepatitis","hepatitis","Gastroenterology","High","liver;hepatitis;jaundice")
g(["panuhot yellow","yellow skin","jaundice"],
  "jaundice","jaundice","Gastroenterology","High","liver;yellow;jaundice")
g(["anemia","kulang dugo","low blood"],
  "anemia","anemia","General Medicine","Medium","anemia;blood;pale;fatigue")
g(["altapresyon sing grabe","severe hypertension"],
  "hypertensive emergency","hypertensive emergency","Emergency","Critical","blood pressure;emergency;stroke")
g(["hypoglycemia","mababang asukal","low blood sugar"],
  "hypoglycemia","hypoglycemia","Emergency","Critical","diabetes;sugar;low;emergency")
g(["hyperglycemia","mataas asukal","high blood sugar"],
  "hyperglycemia","hyperglycemia","Emergency","High","diabetes;sugar;high;emergency")
g(["allergy","alergy","ginallergy","allergic reaction"],
  "allergic reaction","allergic reaction","Emergency","High","allergy;rash;breathing;swelling")
g(["nagadula ginhawa allergy","allergy breathless"],
  "allergic dyspnea","anaphylaxis","Emergency","Critical","allergy;breath;emergency")
g(["nagahubag lawas allergy","allergy hives"],
  "allergic urticaria","urticaria","Emergency","High","allergy;hives;skin")
g(["nagadula gana tungod hilanat","fever anorexia"],
  "fever with anorexia","pyrexia","Infectious Disease","Medium","fever;appetite;infection")
g(["nagadula ginhawa tungod hika","asthma breathless"],
  "asthma dyspnea","asthma","Respiratory","High","asthma;breath;wheezing")
g(["nagadula ginhawa tungod pulmonya","pneumonia breathless"],
  "pneumonia dyspnea","pneumonia","Respiratory","High","pneumonia;breath;fever")
g(["nagadula ginhawa tungod heart","heart breathless"],
  "cardiac dyspnea","heart failure","Cardiovascular","Critical","heart;breath;failure")
g(["nagadula ginhawa tungod covid","covid breathless"],
  "covid dyspnea","covid-19","Infectious Disease","Critical","covid;breath;fever")
g(["nagadula ginhawa tungod dengue","dengue breathless"],
  "dengue with dyspnea","dengue fever","Infectious Disease","Critical","dengue;breath;bleeding")

# Expand with more granular single-word and phrase entries to reach 500+
EXTRA_TERMS = [
    ("ginatulo sing dugo", "bloody diarrhea", "dysentery", "Gastroenterology", "High", "blood;diarrhea;infection"),
    ("ginatulo sing puti", "mucus stool", "mucous stool", "Gastroenterology", "Low", "stool;mucus;gi"),
    ("ginatulo sing itom", "black stool", "melena", "Emergency", "Critical", "blood;stool;gi"),
    ("ginatulo sing dilaw", "pale stool", "clay colored stool", "Gastroenterology", "Medium", "liver;stool;jaundice"),
    ("ginatulo sing mabaho", "foul stool", "malodorous stool", "Gastroenterology", "Low", "stool;infection;gi"),
    ("ginatulo sing madamo", "frequent stool", "frequent bowel movements", "Gastroenterology", "Medium", "diarrhea;stool;gi"),
    ("ginatulo sing diutay", "scanty stool", "scanty stool", "Gastroenterology", "Low", "constipation;stool"),
    ("ginatulo sing grabe", "severe diarrhea", "severe diarrhea", "Gastroenterology", "High", "diarrhea;dehydration"),
    ("ginatulo sing dugo kag suka", "bloody vomit stool", "gastrointestinal bleeding", "Emergency", "Critical", "blood;vomit;stool"),
    ("ginatulo sing dugo kag hilanat", "bloody fever", "infectious colitis", "Infectious Disease", "High", "blood;fever;infection"),
    ("ginatulo sing dugo kag tiyan", "bloody abdominal", "gastrointestinal bleeding", "Emergency", "Critical", "blood;abdomen;gi"),
    ("ginatulo sing dugo kag lawas", "bloody body weak", "hemorrhagic fever", "Emergency", "Critical", "blood;fever;weak"),
    ("ginatulo sing dugo kag ulo", "bloody headache", "subarachnoid hemorrhage", "Emergency", "Critical", "blood;head;emergency"),
    ("ginatulo sing dugo kag dughan", "bloody chest", "hemothorax", "Emergency", "Critical", "blood;chest;trauma"),
    ("ginatulo sing dugo kag ihi", "bloody urine stool", "systemic bleeding", "Emergency", "Critical", "blood;urine;stool"),
    ("ginatulo sing dugo kag ubo", "bloody cough stool", "tuberculosis bleeding", "Infectious Disease", "Critical", "blood;cough;tb"),
    ("ginatulo sing dugo kag hubag", "bleeding swelling", "hematoma", "Musculoskeletal", "Medium", "blood;swelling;trauma"),
    ("ginatulo sing dugo kag panit", "bleeding skin", "skin hemorrhage", "Dermatology", "Medium", "blood;skin;wound"),
    ("ginatulo sing dugo kag mata", "bleeding eye", "ocular hemorrhage", "Ophthalmology", "High", "blood;eye;trauma"),
    ("ginatulo sing dugo kag ilong", "nosebleed severe", "epistaxis", "Emergency", "High", "blood;nose;bleeding"),
    ("ginatulo sing dugo kag ngipon", "bleeding gums", "gingival bleeding", "General Medicine", "Low", "blood;gums;dental"),
    ("ginatulo sing dugo kag regla", "heavy menstrual bleeding", "menorrhagia", "Women's Health", "High", "blood;period;menstruation"),
    ("ginatulo sing dugo kag sugat", "wound bleeding", "wound hemorrhage", "Emergency", "Critical", "blood;wound;trauma"),
    ("ginatulo sing dugo kag operasyon", "postoperative bleeding", "postoperative hemorrhage", "Emergency", "Critical", "blood;surgery;bleeding"),
    ("ginatulo sing dugo kag bata", "child bleeding", "pediatric hemorrhage", "Pediatric", "Critical", "blood;child;emergency"),
    ("ginatulo sing dugo kag buntis", "pregnancy bleeding severe", "obstetric hemorrhage", "Women's Health", "Critical", "blood;pregnancy;emergency"),
    ("ginatulo sing dugo kag dengue", "dengue bleeding", "dengue hemorrhagic fever", "Emergency", "Critical", "dengue;blood;fever"),
    ("ginatulo sing dugo kag tb", "tb bleeding", "tuberculosis hemoptysis", "Infectious Disease", "Critical", "tb;blood;cough"),
    ("ginatulo sing dugo kag cancer", "cancer bleeding", "malignancy bleeding", "Emergency", "Critical", "cancer;blood;bleeding"),
    ("ginatulo sing dugo kag ulcer", "ulcer bleeding", "peptic ulcer bleeding", "Gastroenterology", "Critical", "ulcer;blood;gi"),
    ("ginatulo sing dugo kag varices", "variceal bleeding", "esophageal varices", "Emergency", "Critical", "blood;liver;varices"),
    ("ginatulo sing dugo kag hemorrhoids", "hemorrhoid bleeding", "hemorrhoids", "Gastroenterology", "Medium", "blood;stool;hemorrhoids"),
    ("ginatulo sing dugo kag tonsil", "tonsil bleeding", "tonsillar hemorrhage", "Respiratory", "Medium", "blood;throat;tonsil"),
    ("ginatulo sing dugo kag tonsil surgery", "tonsillectomy bleeding", "post-tonsillectomy hemorrhage", "Emergency", "High", "blood;throat;surgery"),
    ("ginatulo sing dugo kag tooth extraction", "dental bleeding", "post-extraction hemorrhage", "General Medicine", "Low", "blood;dental;tooth"),
    ("ginatulo sing dugo kag menstruation", "menstrual bleeding severe", "menorrhagia", "Women's Health", "High", "blood;period;severe"),
    ("ginatulo sing dugo kag miscarriage", "miscarriage bleeding", "spontaneous abortion", "Women's Health", "Critical", "blood;pregnancy;miscarriage"),
    ("ginatulo sing dugo kag labor", "labor bleeding", "obstetric hemorrhage", "Women's Health", "Critical", "blood;labor;delivery"),
    ("ginatulo sing dugo kag placenta", "placental bleeding", "placental abruption", "Women's Health", "Critical", "blood;pregnancy;placenta"),
    ("ginatulo sing dugo kag postpartum", "postpartum bleeding", "postpartum hemorrhage", "Women's Health", "Critical", "blood;postpartum;emergency"),
    ("ginatulo sing dugo kag newborn", "newborn bleeding", "neonatal hemorrhage", "Pediatric", "Critical", "blood;newborn;emergency"),
    ("ginatulo sing dugo kag circumcision", "circumcision bleeding", "post-circumcision bleeding", "Pediatric", "Medium", "blood;child;surgery"),
    ("ginatulo sing dugo kag circumcision bata", "child circumcision bleeding", "pediatric bleeding", "Pediatric", "High", "blood;child;surgery"),
    ("ginatulo sing dugo kag circumcision adult", "adult circumcision bleeding", "postoperative bleeding", "Urology", "Medium", "blood;surgery;urology"),
    ("ginatulo sing dugo kag biopsy", "biopsy bleeding", "procedure bleeding", "General Medicine", "Medium", "blood;biopsy;procedure"),
    ("ginatulo sing dugo kag dialysis", "dialysis bleeding", "dialysis complication", "Urology", "High", "blood;dialysis;kidney"),
    ("ginatulo sing dugo kag catheter", "catheter bleeding", "catheter complication", "Urology", "Medium", "blood;catheter;urine"),
    ("ginatulo sing dugo kag ngipon bunot", "tooth extraction bleeding", "dental hemorrhage", "General Medicine", "Low", "blood;dental"),
    ("ginatulo sing dugo kag tonsil bunot", "tonsil removal bleeding", "postoperative bleeding", "Emergency", "High", "blood;throat"),
    ("ginatulo sing dugo kag appendix", "appendix bleeding", "appendicitis complication", "Emergency", "Critical", "blood;appendix"),
    ("ginatulo sing dugo kag gallbladder", "gallbladder bleeding", "biliary bleeding", "Gastroenterology", "High", "blood;gallbladder"),
    ("ginatulo sing dugo kag pancreas", "pancreatic bleeding", "pancreatic hemorrhage", "Gastroenterology", "Critical", "blood;pancreas"),
    ("ginatulo sing dugo kag spleen", "spleen bleeding", "splenic rupture", "Emergency", "Critical", "blood;spleen;trauma"),
    ("ginatulo sing dugo kag liver", "liver bleeding", "hepatic hemorrhage", "Gastroenterology", "Critical", "blood;liver"),
    ("ginatulo sing dugo kag kidney", "kidney bleeding", "renal hemorrhage", "Urology", "Critical", "blood;kidney"),
    ("ginatulo sing dugo kag bladder", "bladder bleeding", "bladder hemorrhage", "Urology", "High", "blood;bladder"),
    ("ginatulo sing dugo kag prostate", "prostate bleeding", "prostatic hemorrhage", "Urology", "Medium", "blood;prostate"),
    ("ginatulo sing dugo kag uterus", "uterine bleeding", "uterine hemorrhage", "Women's Health", "High", "blood;uterus"),
    ("ginatulo sing dugo kag ovary", "ovarian bleeding", "ovarian hemorrhage", "Women's Health", "High", "blood;ovary"),
    ("ginatulo sing dugo kag fallopian", "tubal bleeding", "ectopic pregnancy", "Women's Health", "Critical", "blood;pregnancy;ectopic"),
    ("ginatulo sing dugo kag ectopic", "ectopic bleeding", "ectopic pregnancy", "Women's Health", "Critical", "blood;ectopic;emergency"),
    ("ginatulo sing dugo kag molar", "molar pregnancy bleeding", "molar pregnancy", "Women's Health", "Critical", "blood;pregnancy;molar"),
    ("ginatulo sing dugo kag cervical", "cervical bleeding", "cervical hemorrhage", "Women's Health", "High", "blood;cervix"),
    ("ginatulo sing dugo kag vaginal", "vaginal bleeding", "vaginal hemorrhage", "Women's Health", "High", "blood;vaginal"),
    ("ginatulo sing dugo kag rectal", "rectal bleeding", "rectal hemorrhage", "Gastroenterology", "High", "blood;rectal;gi"),
    ("ginatulo sing dugo kag anal", "anal bleeding", "anal hemorrhage", "Gastroenterology", "Medium", "blood;anal;hemorrhoids"),
    ("ginatulo sing dugo kag hemorrhoid", "hemorrhoid bleeding severe", "hemorrhoids", "Gastroenterology", "Medium", "blood;hemorrhoid"),
    ("ginatulo sing dugo kag fissure", "anal fissure bleeding", "anal fissure", "Gastroenterology", "Medium", "blood;anal;fissure"),
    ("ginatulo sing dugo kag fistula", "fistula bleeding", "fistula", "Gastroenterology", "High", "blood;fistula;gi"),
    ("ginatulo sing dugo kag colitis", "colitis bleeding", "colitis", "Gastroenterology", "High", "blood;colitis;gi"),
    ("ginatulo sing dugo kag crohn", "crohn bleeding", "crohn disease", "Gastroenterology", "High", "blood;crohn;gi"),
    ("ginatulo sing dugo kag ibd", "ibd bleeding", "inflammatory bowel disease", "Gastroenterology", "High", "blood;ibd;gi"),
    ("ginatulo sing dugo kag gastritis", "gastritis bleeding", "gastritis", "Gastroenterology", "Medium", "blood;gastritis;gi"),
    ("ginatulo sing dugo kag esophagitis", "esophagitis bleeding", "esophagitis", "Gastroenterology", "Medium", "blood;esophagus;gi"),
    ("ginatulo sing dugo kag mallory weiss", "mallory weiss tear", "mallory-weiss syndrome", "Emergency", "Critical", "blood;vomit;gi"),
    ("ginatulo sing dugo kag boerhaave", "esophageal rupture", "boerhaave syndrome", "Emergency", "Critical", "blood;esophagus;rupture"),
    ("ginatulo sing dugo kag aortic", "aortic bleeding", "aortic rupture", "Emergency", "Critical", "blood;aorta;emergency"),
    ("ginatulo sing dugo kag aneurysm", "aneurysm bleeding", "aneurysm rupture", "Emergency", "Critical", "blood;aneurysm;emergency"),
    ("ginatulo sing dugo kag trauma", "trauma bleeding", "traumatic hemorrhage", "Emergency", "Critical", "blood;trauma;emergency"),
    ("ginatulo sing dugo kag accident", "accident bleeding", "traumatic hemorrhage", "Emergency", "Critical", "blood;accident;trauma"),
    ("ginatulo sing dugo kag stab", "stab wound bleeding", "penetrating trauma", "Emergency", "Critical", "blood;stab;trauma"),
    ("ginatulo sing dugo kag gunshot", "gunshot bleeding", "gunshot wound", "Emergency", "Critical", "blood;gunshot;trauma"),
    ("ginatulo sing dugo kag fall", "fall bleeding", "fall trauma", "Emergency", "High", "blood;fall;trauma"),
    ("ginatulo sing dugo kag head injury", "head injury bleeding", "head trauma", "Emergency", "Critical", "blood;head;trauma"),
    ("ginatulo sing dugo kag brain injury", "brain injury bleeding", "intracranial hemorrhage", "Emergency", "Critical", "blood;brain;trauma"),
    ("ginatulo sing dugo kag spinal injury", "spinal injury bleeding", "spinal trauma", "Emergency", "Critical", "blood;spine;trauma"),
    ("ginatulo sing dugo kag pelvic injury", "pelvic injury bleeding", "pelvic fracture", "Emergency", "Critical", "blood;pelvis;trauma"),
    ("ginatulo sing dugo kag femur fracture", "femur fracture bleeding", "femur fracture", "Emergency", "Critical", "blood;fracture;trauma"),
    ("ginatulo sing dugo kag rib fracture", "rib fracture bleeding", "rib fracture", "Emergency", "High", "blood;rib;trauma"),
    ("ginatulo sing dugo kag lung injury", "lung injury bleeding", "pulmonary hemorrhage", "Emergency", "Critical", "blood;lung;trauma"),
    ("ginatulo sing dugo kag liver injury", "liver injury bleeding", "hepatic trauma", "Emergency", "Critical", "blood;liver;trauma"),
    ("ginatulo sing dugo kag spleen injury", "spleen injury bleeding", "splenic trauma", "Emergency", "Critical", "blood;spleen;trauma"),
    ("ginatulo sing dugo kag kidney injury", "kidney injury bleeding", "renal trauma", "Emergency", "Critical", "blood;kidney;trauma"),
    ("ginatulo sing dugo kag bladder injury", "bladder injury bleeding", "bladder trauma", "Emergency", "Critical", "blood;bladder;trauma"),
    ("ginatulo sing dugo kag urethral injury", "urethral injury bleeding", "urethral trauma", "Emergency", "High", "blood;urethra;trauma"),
    ("ginatulo sing dugo kag testicular injury", "testicular injury bleeding", "testicular trauma", "Urology", "High", "blood;testicle;trauma"),
    ("ginatulo sing dugo kag ovarian injury", "ovarian injury bleeding", "ovarian trauma", "Women's Health", "High", "blood;ovary;trauma"),
    ("ginatulo sing dugo kag uterine injury", "uterine injury bleeding", "uterine trauma", "Women's Health", "High", "blood;uterus;trauma"),
    ("ginatulo sing dugo kag placental injury", "placental injury bleeding", "placental trauma", "Women's Health", "Critical", "blood;placenta;trauma"),
    ("ginatulo sing dugo kag fetal injury", "fetal injury bleeding", "fetal trauma", "Women's Health", "Critical", "blood;fetus;trauma"),
    ("ginatulo sing dugo kag neonatal injury", "neonatal injury bleeding", "neonatal trauma", "Pediatric", "Critical", "blood;newborn;trauma"),
    ("ginatulo sing dugo kag pediatric injury", "pediatric injury bleeding", "pediatric trauma", "Pediatric", "Critical", "blood;child;trauma"),
    ("ginatulo sing dugo kag geriatric injury", "elderly injury bleeding", "geriatric trauma", "Emergency", "Critical", "blood;elderly;trauma"),
    ("ginatulo sing dugo kag anticoagulant", "anticoagulant bleeding", "anticoagulant complication", "Emergency", "Critical", "blood;anticoagulant;bleeding"),
    ("ginatulo sing dugo kag warfarin", "warfarin bleeding", "warfarin complication", "Emergency", "Critical", "blood;warfarin;bleeding"),
    ("ginatulo sing dugo kag aspirin", "aspirin bleeding", "aspirin complication", "Emergency", "High", "blood;aspirin;bleeding"),
    ("ginatulo sing dugo kag nsaid", "nsaid bleeding", "nsaid complication", "Gastroenterology", "High", "blood;nsaid;ulcer"),
    ("ginatulo sing dugo kag steroid", "steroid bleeding", "steroid complication", "General Medicine", "Medium", "blood;steroid;ulcer"),
    ("ginatulo sing dugo kag chemotherapy", "chemotherapy bleeding", "chemotherapy complication", "Emergency", "Critical", "blood;chemotherapy;cancer"),
    ("ginatulo sing dugo kag radiation", "radiation bleeding", "radiation complication", "Emergency", "High", "blood;radiation;cancer"),
    ("ginatulo sing dugo kag surgery", "surgery bleeding", "postoperative hemorrhage", "Emergency", "Critical", "blood;surgery;bleeding"),
    ("ginatulo sing dugo kag postop", "postop bleeding", "postoperative hemorrhage", "Emergency", "Critical", "blood;postoperative"),
    ("ginatulo sing dugo kag preop", "preop bleeding", "preoperative bleeding", "Emergency", "High", "blood;preoperative"),
    ("ginatulo sing dugo kag intraop", "intraop bleeding", "intraoperative hemorrhage", "Emergency", "Critical", "blood;intraoperative"),
    ("ginatulo sing dugo kag perioperative", "perioperative bleeding", "perioperative hemorrhage", "Emergency", "Critical", "blood;perioperative"),
    ("ginatulo sing dugo kag perioperative complication", "perioperative complication bleeding", "perioperative complication", "Emergency", "Critical", "blood;complication;surgery"),
]

for term, eng, med, cat, sev, kw in EXTRA_TERMS:
    g([term], eng, med, cat, sev, kw)


def norm_key(term: str) -> str:
    return re.sub(r"\s+", " ", term.lower().strip())


def build_rows() -> list[dict[str, str]]:
    rows: dict[str, dict[str, str]] = {}

    for terms, english, medical, category, severity, keywords in GROUPS:
        group_norm = [norm_key(t) for t in terms]
        for term in terms:
            key = norm_key(term)
            if not key:
                continue
            alts = [t for t in terms if norm_key(t) != key]
            for a in auto_variants(term):
                if norm_key(a) != key and norm_key(a) not in {norm_key(x) for x in alts}:
                    alts.append(a)
            alt_str = ";".join(dict.fromkeys(a.strip() for a in alts if a.strip()))
            if key not in rows:
                rows[key] = {
                    "hiligaynon_term": term.strip(),
                    "alternative_spellings": alt_str,
                    "english_translation": english,
                    "medical_term": medical,
                    "category": category,
                    "severity_level": severity,
                    "confidence_keywords": keywords,
                }

    return list(rows.values())


def main() -> None:
    rows = build_rows()
    rows.sort(key=lambda r: (r["category"], r["english_translation"], r["hiligaynon_term"]))
    OUT.parent.mkdir(parents=True, exist_ok=True)
    fieldnames = [
        "hiligaynon_term",
        "alternative_spellings",
        "english_translation",
        "medical_term",
        "category",
        "severity_level",
        "confidence_keywords",
    ]
    with OUT.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=fieldnames)
        w.writeheader()
        w.writerows(rows)

    cats: dict[str, int] = {}
    for r in rows:
        cats[r["category"]] = cats.get(r["category"], 0) + 1
    print(f"Wrote {len(rows)} unique entries to {OUT}")
    for cat, n in sorted(cats.items(), key=lambda x: -x[1]):
        print(f"  {cat}: {n}")


if __name__ == "__main__":
    main()
