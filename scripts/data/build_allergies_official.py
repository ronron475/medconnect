#!/usr/bin/env python3
"""
Build clinically recognized allergy reference dataset (no fictional terms).
Sources: FDA major food allergens, common drug allergy nomenclature, environmental/insect/latex/chemical standards.
"""

from __future__ import annotations

import csv
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT_DIR = ROOT / "data" / "nlp" / "allergies"
NLP_EXPORT = ROOT / "data" / "nlp" / "allergies.csv"
ROWS_PER_PART = 2000

# (standardized_name, category) — expanded from clinical references only
ENTRIES: list[tuple[str, str]] = []

def add(category: str, names: list[str]) -> None:
    for n in names:
        ENTRIES.append((n.strip(), category))


# --- Food (FDA Big 9 + common clinically recognized) ---
FOOD = [
    "Milk", "Cow milk", "Goat milk", "Sheep milk", "Casein", "Whey", "Lactose",
    "Egg", "Egg white", "Egg yolk", "Chicken egg",
    "Fish", "Atlantic salmon", "Pacific cod", "Tuna", "Haddock", "Halibut", "Sardine", "Anchovy", "Bass", "Trout",
    "Shellfish", "Shrimp", "Crab", "Lobster", "Scallop", "Mussel", "Oyster", "Clam", "Squid", "Octopus", "Crayfish",
    "Tree nut", "Almond", "Walnut", "Cashew", "Pistachio", "Pecan", "Hazelnut", "Brazil nut", "Macadamia nut", "Pine nut", "Chestnut",
    "Peanut", "Soy", "Soybean", "Wheat", "Gluten", "Barley", "Rye", "Oat", "Spelt", "Kamut",
    "Sesame", "Sesame seed", "Mustard", "Mustard seed", "Celery", "Lupin",
    "Corn", "Rice", "Buckwheat", "Quinoa", "Amaranth", "Millet",
    "Beef", "Pork", "Lamb", "Chicken", "Turkey", "Duck",
    "Apple", "Peach", "Pear", "Plum", "Cherry", "Apricot", "Nectarine",
    "Strawberry", "Blueberry", "Raspberry", "Blackberry", "Grape", "Orange", "Lemon", "Lime", "Grapefruit",
    "Kiwi", "Banana", "Pineapple", "Mango", "Papaya", "Avocado", "Tomato", "Potato", "Carrot",
    "Onion", "Garlic", "Bell pepper", "Cucumber", "Melon", "Watermelon", "Coconut",
    "Chocolate", "Cocoa", "Coffee", "Tea", "Honey", "Yeast", "Baker yeast", "Brewer yeast",
    "Sunflower seed", "Pumpkin seed", "Poppy seed", "Flax seed", "Chia seed",
    "Sulfite", "Food coloring", "Monosodium glutamate", "Carrageenan", "Guar gum", "Xanthan gum",
    "Gelatin", "Bovine gelatin", "Porcine gelatin", "Lecithin", "Soy lecithin",
]
add("food", FOOD)

# --- Medication (clinically recognized drug allergies — full names, no bare abbreviations) ---
MEDICATION = [
    "Penicillin", "Amoxicillin", "Ampicillin", "Amoxicillin and clavulanate", "Piperacillin",
    "Piperacillin and tazobactam", "Cephalexin", "Cefazolin", "Cefuroxime", "Ceftriaxone", "Cefepime",
    "Cefdinir", "Cefaclor", "Cephalosporin", "Carbapenem", "Meropenem", "Imipenem", "Aztreonam",
    "Vancomycin", "Clindamycin", "Metronidazole", "Nitrofurantoin",
    "Trimethoprim", "Trimethoprim and sulfamethoxazole", "Sulfamethoxazole", "Sulfonamide antibiotic",
    "Sulfasalazine", "Dapsone", "Gentamicin", "Tobramycin", "Amikacin", "Streptomycin", "Neomycin",
    "Azithromycin", "Clarithromycin", "Erythromycin", "Doxycycline", "Tetracycline", "Minocycline",
    "Ciprofloxacin", "Levofloxacin", "Moxifloxacin", "Fluoroquinolone antibiotic",
    "Rifampin", "Isoniazid", "Linezolid", "Daptomycin",
    "Aspirin", "Acetylsalicylic acid", "Ibuprofen", "Naproxen", "Ketoprofen", "Diclofenac",
    "Indomethacin", "Meloxicam", "Celecoxib", "Nonsteroidal anti-inflammatory drug",
    "Acetaminophen", "Paracetamol",
    "Morphine", "Codeine", "Hydrocodone", "Oxycodone", "Fentanyl", "Tramadol", "Meperidine", "Opioid",
    "Lidocaine", "Bupivacaine", "Procaine", "Benzocaine", "Local anesthetic",
    "Insulin", "Insulin human", "Insulin lispro", "Insulin aspart", "Insulin glargine",
    "Metformin", "Warfarin", "Heparin", "Enoxaparin", "Rivaroxaban", "Apixaban", "Clopidogrel",
    "Atorvastatin", "Simvastatin", "Rosuvastatin", "Pravastatin",
    "Lisinopril", "Enalapril", "Captopril", "Losartan", "Valsartan", "Olmesartan", "Angiotensin-converting enzyme inhibitor",
    "Amlodipine", "Nifedipine", "Diltiazem", "Verapamil", "Metoprolol", "Atenolol", "Propranolol",
    "Hydrochlorothiazide", "Furosemide", "Spironolactone", "Chlorthalidone",
    "Albuterol", "Salbutamol", "Salmeterol", "Fluticasone", "Budesonide", "Montelukast",
    "Prednisone", "Prednisolone", "Dexamethasone", "Methylprednisolone", "Hydrocortisone",
    "Methotrexate", "Azathioprine", "Mycophenolate mofetil", "Cyclosporine", "Tacrolimus",
    "Allopurinol", "Colchicine", "Probenecid",
    "Phenytoin", "Carbamazepine", "Valproic acid", "Lamotrigine", "Levetiracetam", "Phenobarbital",
    "Haloperidol", "Risperidone", "Olanzapine", "Quetiapine", "Aripiprazole", "Lithium carbonate",
    "Sertraline", "Fluoxetine", "Paroxetine", "Citalopram", "Escitalopram", "Venlafaxine", "Duloxetine",
    "Amitriptyline", "Nortriptyline", "Imipramine",
    "Iodinated contrast media", "Gadolinium contrast media", "Radiocontrast media",
    "Vaccine component", "Egg protein vaccine", "Gelatin vaccine", "Thimerosal",
    "Neomycin topical", "Bacitracin topical", "Polymyxin B",
    "Chlorhexidine", "Povidone-iodine", "Formaldehyde releaser",
    "Heparin-induced thrombocytopenia antibody",
    "Biologic agent", "Infliximab", "Adalimumab", "Etanercept", "Rituximab", "Trastuzumab",
    "Chemotherapy agent", "Cisplatin", "Carboplatin", "Oxaliplatin", "Paclitaxel", "Docetaxel",
    "Doxorubicin", "Cyclophosphamide", "Ifosfamide", "Bleomycin", "Vincristine",
    "5-Fluorouracil", "Capecitabine", "Methotrexate chemotherapy",
    "Antiseizure medication", "Antibiotic", "Antifungal agent", "Antiviral agent",
    "Statin medication", "Proton pump inhibitor", "Omeprazole", "Esomeprazole", "Lansoprazole", "Pantoprazole",
    "Ranitidine", "Famotidine", "Cimetidine",
    "Diphenhydramine", "Chlorpheniramine", "Cetirizine", "Loratadine", "Fexofenadine", "Hydroxyzine",
    "Epinephrine", "Norepinephrine",
]
add("medication", MEDICATION)

# --- Environmental ---
ENVIRONMENTAL = [
    "Pollen", "Grass pollen", "Timothy grass pollen", "Bermuda grass pollen", "Ryegrass pollen",
    "Kentucky bluegrass pollen", "Orchard grass pollen", "Sweet vernal grass pollen",
    "Tree pollen", "Oak pollen", "Birch pollen", "Alder pollen", "Hazel pollen", "Ash pollen",
    "Maple pollen", "Cedar pollen", "Mountain cedar pollen", "Pine pollen", "Elm pollen", "Cottonwood pollen",
    "Weed pollen", "Ragweed pollen", "Common ragweed pollen", "Giant ragweed pollen", "Mugwort pollen",
    "English plantain pollen", "Lamb quarters pollen", "Pigweed pollen", "Sagebrush pollen",
    "House dust mite", "Dermatophagoides pteronyssinus", "Dermatophagoides farinae",
    "Storage mite", "Blomia tropicalis",
    "Mold", "Alternaria alternata", "Aspergillus fumigatus", "Cladosporium herbarum", "Penicillium chrysogenum",
    "Helminthosporium halodes", "Epicoccum nigrum", "Fusarium", "Rhizopus",
    "Animal dander", "Cat dander", "Dog dander", "Horse dander", "Rodent urine protein",
    "Cockroach", "German cockroach", "American cockroach",
    "Feather", "Down feather", "Pigeon feather",
    "Indoor allergen", "Outdoor allergen",
]
add("environmental", ENVIRONMENTAL)

# --- Insect ---
INSECT = [
    "Honeybee venom", "Yellow jacket venom", "Wasp venom", "Hornet venom", "Paper wasp venom",
    "White-faced hornet venom", "European hornet venom",
    "Fire ant venom", "Imported fire ant venom", "Solenopsis invicta venom",
    "Mosquito bite allergy", "Horsefly bite allergy",
    "Caterpillar allergen", "Silk moth allergen",
    "Hymenoptera venom", "Venom immunotherapy",
]
add("insect", INSECT)

# --- Latex ---
LATEX = [
    "Natural rubber latex", "Latex", "Hevea brasiliensis latex",
    "Latex glove", "Latex balloon", "Latex catheter",
]
add("latex", LATEX)

# --- Chemical / contact ---
CHEMICAL = [
    "Nickel", "Cobalt", "Chromium", "Gold", "Palladium", "Mercury", "Titanium",
    "Formaldehyde", "Glutaraldehyde", "Isothiazolinone", "Methylisothiazolinone",
    "Paraben", "Methylparaben", "Propylparaben",
    "Fragrance mix", "Balsam of Peru", "Lanolin", "Wool alcohol",
    "Paraphenylenediamine", "Hair dye chemical",
    "Latex chemical accelerator", "Carba mix", "Thiuram mix",
    "Epoxy resin", "Acrylate", "Methacrylate", "Ethyl cyanoacrylate",
    "Latex chemical", "Rubber chemical", "Colophony", "Rosin",
    "Benzalkonium chloride", "Chlorhexidine gluconate",
    "Topical antibiotic", "Neomycin sulfate", "Bacitracin",
    "Sunscreen chemical", "Benzophenone", "Octocrylene",
    "Lidocaine patch adhesive", "Medical tape adhesive",
    "Iodine", "Povidone-iodine", "Chlorine", "Latex-adjacent chemical sensitizer",
]


def expand_variants() -> None:
    """Add systematic clinically used variants without inventing novel allergens."""
    extra: list[tuple[str, str]] = []
    for name, cat in list(ENTRIES):
        if cat == "medication" and "allergy" not in name.lower():
            extra.append((f"{name} allergy", cat))
        if cat == "food" and "allergy" not in name.lower() and "pollen" not in name.lower():
            if name not in {"Gluten", "Lactose", "Casein", "Whey"}:
                extra.append((f"{name} allergy", cat))
    ENTRIES.extend(extra)


add("chemical", CHEMICAL)
expand_variants()

ABBREV_PATTERN = re.compile(
    r"^(NSAID|ACE|ARB|IV|IM|PO|PRN|BID|TID|QID|CM|PCS|ER|OR|ICU|HIV|AIDS|TB|UTI|GERD|COPD|DM|HTN)$",
    re.I,
)

NON_MEDICAL = {
    "none", "unknown", "n/a", "na", "wala", "other", "misc", "miscellaneous", "test", "sample",
    "allergy", "allergies", "condition", "medical", "patient",
}


def normalize_name(name: str) -> str:
    name = re.sub(r"\s+", " ", name).strip()
    if name.isupper() and len(name) > 4:
        name = name.title()
    return name


def build_rows() -> list[dict[str, str]]:
    seen: set[str] = set()
    rows: list[dict[str, str]] = []
    for name, category in ENTRIES:
        name = normalize_name(name)
        if len(name) < 3:
            continue
        key = name.lower()
        if key in seen or key in NON_MEDICAL:
            continue
        if ABBREV_PATTERN.match(name):
            continue
        seen.add(key)
        rows.append(
            {
                "allergy_name": name,
                "category": category,
                "search_name": key,
                "source": "clinical_reference_compendium",
            }
        )
    rows.sort(key=lambda r: (r["category"], r["search_name"]))
    for i, row in enumerate(rows, start=1):
        row["allergy_id"] = str(i)
    return rows


def write_outputs(rows: list[dict[str, str]]) -> None:
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    fields = ["allergy_id", "allergy_name", "category", "search_name", "source"]
    part = 1
    for offset in range(0, len(rows), ROWS_PER_PART):
        chunk = rows[offset : offset + ROWS_PER_PART]
        path = OUT_DIR / f"allergies_part_{part:02d}.csv"
        with path.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=fields)
            writer.writeheader()
            writer.writerows(chunk)
        part += 1

    with NLP_EXPORT.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=["allergy_id", "allergy_name", "category", "search_name", "source"])
        writer.writeheader()
        writer.writerows(rows)


def main() -> None:
    rows = build_rows()
    write_outputs(rows)
    print(f"Built {len(rows)} allergy records")
    print(f"Export: {NLP_EXPORT}")


if __name__ == "__main__":
    main()
