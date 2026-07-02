# medConnect Python API (FastAPI)

Unified local Python service for NLP validation, disease prediction, transcription, fuzzy matching, and National ID OCR.

## Quick start

```powershell
ai_service\install_ai_dependencies.bat
ai_service\start_ai_service.bat
```

- **Base URL:** `http://127.0.0.1:8765`
- **Swagger UI:** `http://127.0.0.1:8765/docs`
- **ReDoc:** `http://127.0.0.1:8765/redoc`

PHP communicates with this service exclusively via HTTP (`AiServiceClient`, `OcrFastApiClient`, `MedicalFuzzyMatcher`).

## Architecture

```text
ai_service/
├── app/
│   ├── main.py              # FastAPI application factory
│   ├── core/                # config, logging, exceptions, startup
│   ├── routers/             # REST endpoints
│   ├── schemas/             # Pydantic request/response models
│   └── middleware/          # security headers
├── ocr/                     # PhilSys OCR pipeline
├── server.py                # uvicorn entry point (backward compatible)
├── requirements.txt         # unified dependencies
└── Dockerfile
```

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/health`, `/api/health` | Service health |
| GET | `/groq_health`, `/api/groq_health` | Groq connectivity |
| POST | `/analyze` | Consultation transcript analysis |
| POST | `/transcribe` | Audio/video transcription |
| POST | `/analyze-medical-profile` | Registration allergies/medications |
| POST | `/analyze-medical-text` | Free-text medical NLP |
| POST | `/recognize-symptoms` | Symptom recognition |
| POST | `/predict-disease` | ML disease prediction |
| POST | `/fuzzy/match-profile` | Fuzzy profile validation |
| POST | `/fuzzy/match-text-queue` | Fuzzy text queue validation |
| GET | `/ocr/health` | OCR module health |
| POST | `/ocr/extract` | National ID OCR auto-fill |

## NLP modules

- `preprocess.py` — normalize Hiligaynon / Ilonggo text
- `medical_translator.py` — dictionary translation
- `medical_fuzzy_matcher.py` — RapidFuzz WRatio (85% threshold)
- `medical_dataset_validator.py` — final record confirmation
- `analyzer.py` — Hiligaynon translation and symptom extraction
- `disease_predictor.py` — XGBoost symptom→disease model
- `transcriber.py` — Faster-Whisper transcription

Train the disease classifier:

```powershell
ai_service\.venv\Scripts\python.exe ai_service\train_disease_classifier.py
```

## National ID OCR

OCR runs on the **same** FastAPI service (port 8765):

- `POST /ocr/extract` — multipart field `national_id_image`
- PHP tries FastAPI first (`OcrFastApiClient`), then falls back to inline PHP OCR

## Environment variables

See `.env.example` for `MEDCONNECT_AI_SERVICE_URL`, `OCR_SPACE_API_KEY`, `GROQ_API_KEY`, etc.

## Docker

```bash
docker build -f ai_service/Dockerfile -t medconnect-api .
docker run -p 8765:8765 --env-file .env medconnect-api
```
