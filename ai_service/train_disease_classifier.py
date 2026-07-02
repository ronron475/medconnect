"""Train XGBoost symptom-to-disease classifier from archive_source/dataset.csv."""

from __future__ import annotations

import csv
import json
import sys
from pathlib import Path

_ARCHIVE_DIR = Path(__file__).resolve().parent.parent / "data" / "nlp" / "archive_source"
_MODEL_DIR = Path(__file__).resolve().parent / "models"
_DATASET = _ARCHIVE_DIR / "dataset.csv"
_MODEL_FILE = _MODEL_DIR / "disease_classifier.joblib"
_META_FILE = _MODEL_DIR / "disease_classifier_meta.json"


def normalize_symptom(raw: str) -> str:
    cleaned = (raw or "").strip().lower().replace(" ", "_")
    while "__" in cleaned:
        cleaned = cleaned.replace("__", "_")
    return cleaned.strip("_")


def load_training_rows() -> tuple[list[dict[str, int]], list[str], list[str]]:
    if not _DATASET.is_file():
        raise FileNotFoundError(f"Dataset not found: {_DATASET}")

    symptom_columns: set[str] = set()
    raw_rows: list[tuple[str, set[str]]] = []

    with _DATASET.open(encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        for row in reader:
            disease = (row.get("Disease") or "").strip()
            if not disease:
                continue
            symptoms: set[str] = set()
            for key, value in row.items():
                if not key.startswith("Symptom_"):
                    continue
                symptom = normalize_symptom(value)
                if symptom:
                    symptoms.add(symptom)
                    symptom_columns.add(symptom)
            if symptoms:
                raw_rows.append((disease, symptoms))

    # Deduplicate identical disease + symptom sets
    unique: dict[tuple[str, tuple[str, ...]], tuple[str, set[str]]] = {}
    for disease, symptoms in raw_rows:
        signature = (disease, tuple(sorted(symptoms)))
        unique[signature] = (disease, symptoms)

    columns = sorted(symptom_columns)
    feature_rows: list[dict[str, int]] = []
    labels: list[str] = []
    for disease, symptoms in unique.values():
        feature_rows.append({col: int(col in symptoms) for col in columns})
        labels.append(disease)

    return feature_rows, labels, columns


def train_and_save() -> dict:
    try:
        import joblib
        import pandas as pd
        from sklearn.metrics import accuracy_score, classification_report
        from sklearn.model_selection import train_test_split
        from sklearn.preprocessing import LabelEncoder
        from xgboost import XGBClassifier
    except ImportError as exc:
        raise SystemExit(
            "Missing packages. Run: pip install scikit-learn xgboost joblib pandas\n"
            f"Detail: {exc}"
        ) from exc

    feature_rows, labels, columns = load_training_rows()
    if len(feature_rows) < 10:
        raise SystemExit("Not enough training rows.")

    frame_x = pd.DataFrame(feature_rows, columns=columns)
    label_encoder = LabelEncoder()
    encoded_labels = label_encoder.fit_transform(labels)

    x_train, x_test, y_train, y_test = train_test_split(
        frame_x,
        encoded_labels,
        test_size=0.2,
        random_state=42,
        stratify=encoded_labels,
    )

    model = XGBClassifier(
        n_estimators=200,
        max_depth=6,
        learning_rate=0.1,
        subsample=0.9,
        colsample_bytree=0.9,
        objective="multi:softprob",
        eval_metric="mlogloss",
        random_state=42,
        n_jobs=-1,
    )
    model.fit(x_train, y_train)

    predictions = model.predict(x_test)
    accuracy = float(accuracy_score(y_test, predictions))
    report = classification_report(
        label_encoder.inverse_transform(y_test),
        label_encoder.inverse_transform(predictions),
        zero_division=0,
    )

    _MODEL_DIR.mkdir(parents=True, exist_ok=True)
    joblib.dump({"model": model, "label_encoder": label_encoder}, _MODEL_FILE)

    meta = {
        "symptom_columns": columns,
        "disease_count": len(label_encoder.classes_),
        "disease_labels": list(label_encoder.classes_),
        "training_rows": len(feature_rows),
        "test_accuracy": round(accuracy * 100, 2),
        "model": "xgboost",
        "dataset": str(_DATASET.relative_to(_ARCHIVE_DIR.parent.parent)),
    }
    _META_FILE.write_text(json.dumps(meta, indent=2), encoding="utf-8")

    return {
        "model_path": str(_MODEL_FILE),
        "meta_path": str(_META_FILE),
        "accuracy_percent": meta["test_accuracy"],
        "training_rows": meta["training_rows"],
        "disease_count": meta["disease_count"],
        "symptom_count": len(columns),
        "report": report,
    }


def main() -> None:
    print("Training medConnect disease classifier...")
    print(f"Dataset: {_DATASET}")
    result = train_and_save()
    print(f"Saved model: {result['model_path']}")
    print(f"Symptoms: {result['symptom_count']} | Diseases: {result['disease_count']}")
    print(f"Training rows (deduped): {result['training_rows']}")
    print(f"Hold-out accuracy: {result['accuracy_percent']}%")
    print()
    print(result["report"])


if __name__ == "__main__":
    main()
