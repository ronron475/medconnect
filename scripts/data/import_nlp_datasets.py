#!/usr/bin/env python3
"""Import NLP CSV parts into MySQL using environment credentials from .env or defaults."""

from __future__ import annotations

import csv
import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def load_db_config() -> dict[str, str]:
    env_path = ROOT / ".env"
    config = {
        "host": os.environ.get("DB_HOST", "127.0.0.1"),
        "user": os.environ.get("DB_USER", "root"),
        "password": os.environ.get("DB_PASS", ""),
        "database": os.environ.get("DB_NAME", "medconnect"),
    }
    if env_path.is_file():
        for line in env_path.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, val = line.split("=", 1)
            key, val = key.strip(), val.strip().strip('"').strip("'")
            if key in ("DB_HOST", "DB_USER", "DB_PASS", "DB_NAME"):
                config[key.replace("DB_", "").lower() if key != "DB_NAME" else "database"] = val
    if "DB_HOST" in os.environ:
        config["host"] = os.environ["DB_HOST"]
    return {
        "host": config.get("host", "127.0.0.1"),
        "user": config.get("user", "root"),
        "password": config.get("password", config.get("pass", "")),
        "database": config.get("database", config.get("name", "medconnect")),
    }


def import_file(cursor, table: str, columns: list[str], path: Path) -> int:
    with path.open(encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        placeholders = ",".join(["%s"] * len(columns))
        sql = f"INSERT INTO `{table}` (`{'`,`'.join(columns)}`) VALUES ({placeholders})"
        count = 0
        batch: list[tuple] = []
        for row in reader:
            batch.append(tuple(row.get(c, "") for c in columns))
            if len(batch) >= 500:
                cursor.executemany(sql, batch)
                count += len(batch)
                batch = []
        if batch:
            cursor.executemany(sql, batch)
            count += len(batch)
    return count


def main() -> None:
    try:
        import mysql.connector  # type: ignore
    except ImportError:
        print("Install: pip install mysql-connector-python", file=sys.stderr)
        sys.exit(1)

    cfg = load_db_config()
    conn = mysql.connector.connect(
        host=cfg["host"],
        user=cfg["user"],
        password=cfg["password"],
        database=cfg["database"],
    )
    cursor = conn.cursor()

    cond_cols = [
        "icd10_code", "condition_name", "icd10_category", "chapter_code",
        "chapter_title", "long_description", "is_billable", "search_name", "source",
    ]
    allergy_cols = ["allergy_name", "category", "search_name", "source"]

    cursor.execute("TRUNCATE TABLE nlp_medical_conditions")
    cursor.execute("TRUNCATE TABLE nlp_allergies")

    icd_dir = ROOT / "data" / "nlp" / "icd10"
    for path in sorted(icd_dir.glob("medical_conditions_part_*.csv")):
        n = import_file(cursor, "nlp_medical_conditions", cond_cols, path)
        print(f"Conditions {path.name}: {n}")
    conn.commit()

    allergy_dir = ROOT / "data" / "nlp" / "allergies"
    for path in sorted(allergy_dir.glob("allergies_part_*.csv")):
        n = import_file(cursor, "nlp_allergies", allergy_cols, path)
        print(f"Allergies {path.name}: {n}")
    conn.commit()

    cursor.close()
    conn.close()
    print("Import complete.")


if __name__ == "__main__":
    main()
