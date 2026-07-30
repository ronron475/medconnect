"""Train XGBoost symptom-to-disease classifier.

Sources:
  - archive_source/dataset.csv (canonical symptom columns)
  - data/nlp/training/patient_cases.csv (train split — expanded NLP corpus)

Run:
    python ai_service/train_disease_classifier.py
    python ai_service/train_disease_classifier.py --source archive
    python ai_service/train_disease_classifier.py --source corpus --n-estimators 600
"""

from __future__ import annotations

import argparse
import csv
import json
from pathlib import Path

_ROOT = Path(__file__).resolve().parent.parent
_ARCHIVE_DIR = _ROOT / "data" / "nlp" / "archive_source"
_MODEL_DIR = Path(__file__).resolve().parent / "models"
_DATASET = _ARCHIVE_DIR / "dataset.csv"
_PATIENT_CASES = _ROOT / "data" / "nlp" / "training" / "patient_cases.csv"
_MODEL_FILE = _MODEL_DIR / "disease_classifier.joblib"
_META_FILE = _MODEL_DIR / "disease_classifier_meta.json"


def normalize_symptom(raw: str) -> str:
    cleaned = (raw or "").strip().lower().replace(" ", "_")
    while "__" in cleaned:
        cleaned = cleaned.replace("__", "_")
    return cleaned.strip("_")


def parse_symptom_keys(raw: str) -> set[str]:
    keys: set[str] = set()
    for part in (raw or "").split(";"):
        key = normalize_symptom(part)
        if key:
            keys.add(key)
    return keys


def load_archive_rows(dedupe: bool = True) -> tuple[list[tuple[str, set[str]]], set[str]]:
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

    if dedupe:
        unique: dict[tuple[str, tuple[str, ...]], tuple[str, set[str]]] = {}
        for disease, symptoms in raw_rows:
            signature = (disease, tuple(sorted(symptoms)))
            unique[signature] = (disease, symptoms)
        raw_rows = list(unique.values())

    return raw_rows, symptom_columns


def load_patient_case_rows(splits: set[str]) -> tuple[list[tuple[str, set[str]]], set[str]]:
    if not _PATIENT_CASES.is_file():
        raise FileNotFoundError(f"Missing {_PATIENT_CASES}")

    symptom_columns: set[str] = set()
    rows: list[tuple[str, set[str]]] = []

    with _PATIENT_CASES.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            split = (row.get("split") or "").strip()
            if split not in splits:
                continue
            disease = (row.get("disease") or "").strip()
            if not disease:
                continue
            symptoms = parse_symptom_keys(row.get("symptom_keys") or "")
            if not symptoms:
                continue
            symptom_columns.update(symptoms)
            rows.append((disease, symptoms))

    return rows, symptom_columns


def load_nlp_extracted_rows(
    splits: set[str],
    max_rows: int = 0,
    seed: int = 42,
) -> tuple[list[tuple[str, set[str]]], set[str]]:
    """Symptom vectors from analyze_transcript_for_ml (matches live inference)."""
    import random

    from analyzer import analyze_transcript_for_ml

    if not _PATIENT_CASES.is_file():
        raise FileNotFoundError(f"Missing {_PATIENT_CASES}")

    by_disease: dict[str, list[tuple[str, str]]] = {}
    with _PATIENT_CASES.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            split = (row.get("split") or "").strip()
            if split not in splits:
                continue
            disease = (row.get("disease") or "").strip()
            transcript = (row.get("transcript") or "").strip()
            if disease and transcript:
                by_disease.setdefault(disease, []).append((disease, transcript))

    pool: list[tuple[str, str]] = []
    rng = random.Random(seed)
    for disease, items in sorted(by_disease.items()):
        rng.shuffle(items)
        pool.extend(items)

    if max_rows > 0 and len(pool) > max_rows:
        rng.shuffle(pool)
        pool = pool[:max_rows]

    symptom_columns: set[str] = set()
    rows: list[tuple[str, set[str]]] = []
    for disease, transcript in pool:
        analysis = analyze_transcript_for_ml(transcript)
        keys = {normalize_symptom(k) for k in (analysis.get("model_symptom_keys") or [])}
        keys.discard("")
        if not keys:
            continue
        symptom_columns.update(keys)
        rows.append((disease, keys))

    return rows, symptom_columns


def pipeline_symptom_vector(symptom_keys: set[str]) -> set[str]:
    """Symptoms the live NLP layer reports for these keys.

    The runtime adds alias siblings (stomach/abdominal/belly pain) and inferred
    keys (nausea, high_fever), so training on raw archive vectors leaves the
    classifier predicting on a distribution it never saw.
    """
    from disease_predictor import extract_model_symptoms, symptom_phrase

    text = ", ".join(symptom_phrase(key) for key in sorted(symptom_keys))
    detected = set(extract_model_symptoms(text))
    return detected or set(symptom_keys)


def augment_rows(
    rows: list[tuple[str, set[str]]],
    dropout: bool,
) -> list[tuple[str, set[str]]]:
    """Render each vector through the live pipeline, plus missed-symptom variants."""
    seen: set[tuple[str, tuple[str, ...]]] = set()
    out: list[tuple[str, set[str]]] = []

    def push(disease: str, symptoms: set[str]) -> None:
        if not symptoms:
            return
        signature = (disease, tuple(sorted(symptoms)))
        if signature in seen:
            return
        seen.add(signature)
        out.append((disease, symptoms))

    for disease, symptoms in rows:
        push(disease, set(symptoms))
        push(disease, pipeline_symptom_vector(symptoms))
        if dropout and len(symptoms) > 3:
            for omitted in sorted(symptoms):
                partial = set(symptoms) - {omitted}
                push(disease, pipeline_symptom_vector(partial))

    return out


def rows_to_features(
    rows: list[tuple[str, set[str]]],
    columns: list[str],
) -> tuple[list[dict[str, int]], list[str]]:
    feature_rows: list[dict[str, int]] = []
    labels: list[str] = []
    for disease, symptoms in rows:
        feature_rows.append({col: int(col in symptoms) for col in columns})
        labels.append(disease)
    return feature_rows, labels


def cross_validate_archive(
    folds: int,
    n_estimators: int,
    max_depth: int,
    learning_rate: float,
    augment: bool,
) -> dict:
    """Stratified k-fold estimate; steadier than one 61-case hold-out."""
    import numpy as np
    import pandas as pd
    from sklearn.metrics import accuracy_score
    from sklearn.model_selection import StratifiedKFold
    from sklearn.preprocessing import LabelEncoder
    from xgboost import XGBClassifier

    base_rows, columns_set = load_archive_rows(dedupe=True)
    columns = sorted(columns_set)
    labels = [disease for disease, _ in base_rows]

    encoder = LabelEncoder()
    encoded = encoder.fit_transform(labels)

    # Some diseases have as few as 5 vectors, so cap folds to the rarest class.
    min_class = min(int((encoded == cls).sum()) for cls in set(encoded))
    folds = max(2, min(folds, min_class))

    splitter = StratifiedKFold(n_splits=folds, shuffle=True, random_state=42)
    raw_scores: list[float] = []
    rendered_scores: list[float] = []

    for train_idx, test_idx in splitter.split(np.zeros(len(base_rows)), encoded):
        fold_train = [base_rows[i] for i in train_idx]
        fold_test = [base_rows[i] for i in test_idx]
        if augment:
            fold_train = augment_rows(fold_train, dropout=False)

        train_features, train_labels = rows_to_features(fold_train, columns)
        model = XGBClassifier(
            n_estimators=n_estimators,
            max_depth=max_depth,
            learning_rate=learning_rate,
            subsample=0.9,
            colsample_bytree=0.9,
            objective="multi:softprob",
            eval_metric="mlogloss",
            random_state=42,
            n_jobs=-1,
        )
        model.fit(
            pd.DataFrame(train_features, columns=columns),
            encoder.transform(train_labels),
        )

        for view, scores in (("raw", raw_scores), ("rendered", rendered_scores)):
            cases = (
                fold_test
                if view == "raw"
                else [(d, pipeline_symptom_vector(s)) for d, s in fold_test]
            )
            features, case_labels = rows_to_features(cases, columns)
            predicted = model.predict(pd.DataFrame(features, columns=columns))
            scores.append(
                float(accuracy_score(encoder.transform(case_labels), predicted))
            )

    def summarize(scores: list[float]) -> dict:
        mean = sum(scores) / len(scores)
        spread = (sum((s - mean) ** 2 for s in scores) / len(scores)) ** 0.5
        return {
            "mean_percent": round(100 * mean, 2),
            "std_percent": round(100 * spread, 2),
            "fold_percents": [round(100 * s, 2) for s in scores],
        }

    return {
        "folds": folds,
        "base_vectors": len(base_rows),
        "raw_vectors": summarize(raw_scores),
        "pipeline_rendered": summarize(rendered_scores),
    }


def train_and_save(
    source: str,
    n_estimators: int,
    max_depth: int,
    learning_rate: float,
    archive_repeat: int,
    augment: bool = False,
    dropout: bool = False,
    balance: bool = False,
    nlp_corpus_max: int = 0,
) -> dict:
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

    all_columns: set[str] = set()
    train_rows: list[tuple[str, set[str]]] = []
    val_rows: list[tuple[str, set[str]]] = []
    archive_train_count = 0
    corpus_train_count = 0

    archive_rows, archive_cols = load_archive_rows(dedupe=True)
    all_columns.update(archive_cols)

    if source in ("archive", "both"):
        repeat = max(1, archive_repeat) if source == "both" else 1
        for _ in range(repeat):
            train_rows.extend(archive_rows)
        archive_train_count = len(archive_rows) * repeat

    if source in ("corpus", "both"):
        corpus_train, corpus_cols = load_patient_case_rows({"train"})
        corpus_val, val_cols = load_patient_case_rows({"val"})
        all_columns.update(corpus_cols)
        all_columns.update(val_cols)
        train_rows.extend(corpus_train)
        val_rows = corpus_val
        corpus_train_count = len(corpus_train)

    if augment and source in ("corpus", "both"):
        train_rows = augment_rows(
            [(disease, pipeline_symptom_vector(symptoms)) for disease, symptoms in train_rows],
            dropout=dropout,
        )

    if augment and source in ("corpus", "both") and val_rows:
        val_rows = [(disease, pipeline_symptom_vector(symptoms)) for disease, symptoms in val_rows]

    if source != "archive" and not train_rows:
        raise SystemExit("No training rows.")

    columns = sorted(all_columns)
    label_encoder = LabelEncoder()

    if source == "archive":
        # Split base vectors first so augmented variants never leak into the hold-out.
        base_labels = [disease for disease, _ in train_rows]
        label_encoder.fit(base_labels)
        train_base, test_base = train_test_split(
            train_rows,
            test_size=0.2,
            random_state=42,
            stratify=base_labels,
        )
        if nlp_corpus_max > 0:
            nlp_train, nlp_cols = load_nlp_extracted_rows({"train"}, max_rows=nlp_corpus_max)
            all_columns.update(nlp_cols)
            train_base.extend(nlp_train)
            columns = sorted(all_columns)
        eval_name = "archive_holdout_20pct"
        rendered_test = (
            [(d, pipeline_symptom_vector(s)) for d, s in test_base] if augment else []
        )
        if augment:
            train_base = augment_rows(train_base, dropout=dropout)

        train_features, train_labels = rows_to_features(train_base, columns)
        test_features, test_labels = rows_to_features(test_base, columns)
        x_train = pd.DataFrame(train_features, columns=columns)
        x_test = pd.DataFrame(test_features, columns=columns)
        y_train = label_encoder.transform(train_labels)
        y_test = label_encoder.transform(test_labels)
        use_early_stop = False
        train_row_count = len(y_train)
    else:
        x_train_list, y_train_list = rows_to_features(train_rows, columns)
        frame_train = pd.DataFrame(x_train_list, columns=columns)
        y_train = label_encoder.fit_transform(y_train_list)
        train_row_count = len(y_train)

        if val_rows:
            x_val_list, y_val_list = rows_to_features(val_rows, columns)
            frame_val = pd.DataFrame(x_val_list, columns=columns)
            y_val = label_encoder.transform(y_val_list)
            x_train, x_test, y_train_fit, y_test = frame_train, frame_val, y_train, y_val
            eval_name = "patient_cases_val"
            use_early_stop = True
        else:
            x_train, x_test, y_train_fit, y_test = train_test_split(
                frame_train,
                y_train,
                test_size=0.2,
                random_state=42,
                stratify=y_train,
            )
            eval_name = "holdout_20pct"
            use_early_stop = False

    model_kwargs = {
        "n_estimators": n_estimators,
        "max_depth": max_depth,
        "learning_rate": learning_rate,
        "subsample": 0.9,
        "colsample_bytree": 0.9,
        "objective": "multi:softprob",
        "eval_metric": "mlogloss",
        "random_state": 42,
        "n_jobs": -1,
    }
    if use_early_stop:
        model_kwargs["early_stopping_rounds"] = 40
    model = XGBClassifier(**model_kwargs)

    fit_labels = y_train if source == "archive" else y_train_fit
    sample_weight = None
    if balance:
        from collections import Counter

        counts = Counter(int(label) for label in fit_labels)
        mean_count = sum(counts.values()) / len(counts)
        sample_weight = [mean_count / counts[int(label)] for label in fit_labels]

    if use_early_stop:
        model.fit(
            x_train,
            fit_labels,
            sample_weight=sample_weight,
            eval_set=[(x_test, y_test)],
            verbose=False,
        )
    else:
        model.fit(x_train, fit_labels, sample_weight=sample_weight)

    predictions = model.predict(x_test)
    accuracy = float(accuracy_score(y_test, predictions))

    # Second view: same hold-out cases as the live NLP layer would report them.
    rendered_accuracy = None
    if source == "archive" and rendered_test:
        rendered_features, rendered_labels = rows_to_features(rendered_test, columns)
        rendered_predictions = model.predict(pd.DataFrame(rendered_features, columns=columns))
        rendered_accuracy = round(
            100 * float(accuracy_score(label_encoder.transform(rendered_labels), rendered_predictions)),
            2,
        )

    report = classification_report(
        label_encoder.inverse_transform(y_test),
        label_encoder.inverse_transform(predictions),
        zero_division=0,
    )

    # The scores above come from data the model never saw. Shipping that same model
    # would permanently discard 20% of only ~300 labelled vectors, and every disease
    # has just a handful — the held-out Psoriasis and UTI vectors were being
    # misclassified in production for exactly that reason. Refit on everything.
    shipped_model = model
    final_fit = "holdout_only"
    if use_early_stop:
        best_trees = int(getattr(model, "best_iteration", n_estimators - 1)) + 1
        ship_kwargs = {k: v for k, v in model_kwargs.items() if k != "early_stopping_rounds"}
        ship_kwargs["n_estimators"] = max(1, best_trees)
        shipped_model = XGBClassifier(**ship_kwargs)
        shipped_model.fit(frame_train, y_train, sample_weight=sample_weight)
        final_fit = "all_corpus_train"
    elif not use_early_stop:
        full_features = pd.concat([x_train, x_test])
        full_labels = list(fit_labels) + list(y_test)
        full_weight = None
        if balance:
            from collections import Counter

            counts = Counter(int(label) for label in full_labels)
            mean_count = sum(counts.values()) / len(counts)
            full_weight = [mean_count / counts[int(label)] for label in full_labels]
        shipped_model = XGBClassifier(**model_kwargs)
        shipped_model.fit(full_features, full_labels, sample_weight=full_weight)
        final_fit = "all_labeled_data"

    _MODEL_DIR.mkdir(parents=True, exist_ok=True)
    joblib.dump({"model": shipped_model, "label_encoder": label_encoder}, _MODEL_FILE)

    meta = {
        "symptom_columns": columns,
        "disease_count": len(label_encoder.classes_),
        "disease_labels": list(label_encoder.classes_),
        "classes": list(label_encoder.classes_),
        "training_rows": train_row_count,
        "test_accuracy": round(accuracy * 100, 2),
        "eval_split": eval_name,
        "model": "xgboost",
        "training_source": source,
        "archive_repeat": archive_repeat if source == "both" else 1,
        "archive_deduped_rows": archive_train_count,
        "corpus_train_rows": corpus_train_count,
        "corpus_val_rows": len(val_rows),
        "n_estimators": n_estimators,
        "test_accuracy_pipeline_rendered": rendered_accuracy,
        "final_fit": final_fit,
        "pipeline_augmented": augment,
        "dropout_variants": dropout,
        "class_balanced": balance,
        "nlp_corpus_max": nlp_corpus_max,
        "dataset": str(_DATASET.relative_to(_ROOT)),
        "patient_cases": str(_PATIENT_CASES.relative_to(_ROOT)),
    }
    _META_FILE.write_text(json.dumps(meta, indent=2), encoding="utf-8")

    return {
        "model_path": str(_MODEL_FILE),
        "meta_path": str(_META_FILE),
        "accuracy_percent": meta["test_accuracy"],
        "rendered_accuracy_percent": rendered_accuracy,
        "training_rows": meta["training_rows"],
        "disease_count": meta["disease_count"],
        "symptom_count": len(columns),
        "eval_split": eval_name,
        "report": report,
    }


def main() -> None:
    parser = argparse.ArgumentParser(description="Train disease XGBoost classifier")
    parser.add_argument(
        "--source",
        choices=["archive", "corpus", "both"],
        default="archive",
        help="Training data: archive CSV (default), patient_cases train, or both.",
    )
    parser.add_argument("--n-estimators", type=int, default=500)
    parser.add_argument("--max-depth", type=int, default=8)
    parser.add_argument(
        "--archive-repeat",
        type=int,
        default=25,
        help="When using corpus/both, repeat archive deduped rows N times (default 25).",
    )
    parser.add_argument("--learning-rate", type=float, default=0.08)
    parser.add_argument(
        "--augment",
        action="store_true",
        help="Train on pipeline-rendered vectors (matches what the live NLP layer emits).",
    )
    parser.add_argument(
        "--dropout",
        action="store_true",
        help="With --augment, also add variants where one symptom went unreported.",
    )
    parser.add_argument(
        "--balance",
        action="store_true",
        help="Weight samples inversely to class frequency.",
    )
    parser.add_argument(
        "--cv",
        type=int,
        default=0,
        help="Report stratified k-fold accuracy instead of training a saved model.",
    )
    parser.add_argument(
        "--nlp-corpus-max",
        type=int,
        default=0,
        help="Add up to N train-split cases with NLP-extracted symptom vectors (live pipeline).",
    )
    args = parser.parse_args()

    if args.cv:
        print(f"Cross-validating archive vectors (augment={args.augment})...")
        result = cross_validate_archive(
            folds=args.cv,
            n_estimators=args.n_estimators,
            max_depth=args.max_depth,
            learning_rate=args.learning_rate,
            augment=args.augment,
        )
        print(f"Folds: {result['folds']} | base vectors: {result['base_vectors']}")
        for view in ("raw_vectors", "pipeline_rendered"):
            stats = result[view]
            print(
                f"{view}: {stats['mean_percent']}% +/- {stats['std_percent']}% "
                f"(folds: {stats['fold_percents']})"
            )
        return

    print("Training medConnect disease classifier...")
    print(f"Source: {args.source}")
    print(f"Archive: {_DATASET}")
    if args.source != "archive":
        print(f"Corpus: {_PATIENT_CASES}")
    result = train_and_save(
        source=args.source,
        n_estimators=args.n_estimators,
        max_depth=args.max_depth,
        learning_rate=args.learning_rate,
        archive_repeat=args.archive_repeat,
        augment=args.augment,
        dropout=args.dropout,
        balance=args.balance,
        nlp_corpus_max=args.nlp_corpus_max,
    )
    print(f"Saved model: {result['model_path']}")
    print(f"Symptoms: {result['symptom_count']} | Diseases: {result['disease_count']}")
    print(f"Training rows: {result['training_rows']}")
    print(f"{result['eval_split']} accuracy: {result['accuracy_percent']}%")
    if result.get("rendered_accuracy_percent") is not None:
        print(f"same hold-out, pipeline-rendered: {result['rendered_accuracy_percent']}%")
    print()
    print(result["report"])


if __name__ == "__main__":
    main()
