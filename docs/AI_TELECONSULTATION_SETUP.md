# MedConnect AI Teleconsultation Summary

This document summarizes the AI teleconsultation work implemented today and explains how to run, test, and troubleshoot it.

## Features Implemented

### 1. AI Transcript Assistant

The provider consultation page now includes an AI Transcript Assistant.

Location:

```text
http://localhost/medconnect/views/provider/consultation_session.php?id=2
```

It can:

- Accept Hiligaynon or mixed Hiligaynon-English transcript text.
- Translate known Hiligaynon medical words into English.
- Extract possible symptoms.
- Extract mentioned medicines.
- Detect urgent cues like chest pain or difficulty breathing.
- Copy the generated AI summary into the SOAP Assessment field.

### 2. Python AI Service

A local Python AI service was created.

Main files:

```text
ai_service/server.py
ai_service/start_ai_service.bat
ai_service/check_ai_service.bat
ai_service/install_ai_dependencies.bat
```

Default service URL:

```text
http://127.0.0.1:8765
```

Health check:

```text
http://127.0.0.1:8765/health
```

Available endpoints:

```text
GET  /health
POST /analyze
POST /transcribe
```

### 3. Hiligaynon Medical Keyword Translation

The AI currently understands common Hiligaynon medical terms using a dictionary-based prototype.

Examples:

```text
hilanat -> fever
ubo -> cough
sakit ulo -> headache
sakit dughan -> chest pain
budlay ginhawa -> difficulty breathing
sakit tiyan -> stomach pain
suka -> vomiting
kalibanga -> diarrhea
```

Example input:

```text
May hilanat kag ubo ang pasyente. Nag inom sang paracetamol.
```

Expected AI result:

```text
Symptoms: fever, cough
Medicine: paracetamol
```

### 4. Faster-Whisper and spaCy Support

The AI service now supports installed AI dependencies:

- Faster-Whisper for speech-to-text transcription.
- spaCy for NLP support.
- Whisper model: `small`.

When running correctly, `/health` should show:

```json
{
  "success": true,
  "status": "ok",
  "service": "medconnect-ai",
  "models": {
    "faster_whisper": "available",
    "spacy": "available",
    "whisper_model": "small"
  }
}
```

### 5. Live AI During WebRTC Call

The WebRTC video room now sends short audio chunks during the call.

Flow:

```text
Live call audio
↓
Short browser audio chunks
↓
PHP live transcription endpoint
↓
Python AI service /transcribe
↓
Faster-Whisper transcript
↓
Hiligaynon keyword translation
↓
Symptom / medicine extraction
↓
Doctor AI Transcript Assistant
```

The AI should update while the call is ongoing, not only after the call ends.

### 6. End-of-Call Recording Transcription

The system also keeps the backup behavior:

```text
End call
↓
Save WebM recording
↓
Send saved recording to AI service
↓
Transcribe and analyze
↓
Save AI note in database
```

This helps preserve an AI note even if live chunks miss some audio.

## Important Files Changed or Added

```text
ai_service/server.py
ai_service/start_ai_service.bat
ai_service/check_ai_service.bat
ai_service/install_ai_dependencies.bat
app/api/ai/analyze_transcript.php
app/api/ai/transcribe_chunk.php
app/api/consultations/upload_recording.php
views/consultation/video_room.php
views/provider/consultation_session.php
```

## How To Start The AI Service

Open PowerShell and run:

```powershell
cd C:\xampp\htdocs\medconnect
.\ai_service\start_ai_service.bat
```

Keep that window open while using the AI assistant.

Then open:

```text
http://127.0.0.1:8765/health
```

If it works, you should see `success: true`.

## How To Check If The AI Service Is Running

From PowerShell:

```powershell
cd C:\xampp\htdocs\medconnect\ai_service
.\check_ai_service.bat
```

Or open this in the browser:

```text
http://127.0.0.1:8765/health
```

## How To Install AI Dependencies

If Faster-Whisper or spaCy is not installed, run:

```powershell
cd C:\xampp\htdocs\medconnect
.\ai_service\install_ai_dependencies.bat
```

This installs dependencies into:

```text
C:\xampp\htdocs\medconnect\ai_service\.venv
```

After installation, restart the AI service:

```powershell
cd C:\xampp\htdocs\medconnect
.\ai_service\start_ai_service.bat
```

## If `python`, `py`, Or `pip` Does Not Work

That is okay. This machine may not have normal Python commands added to PATH.

Use the included installer instead:

```powershell
cd C:\xampp\htdocs\medconnect
.\ai_service\install_ai_dependencies.bat
```

The script uses the bundled Python available on this machine and creates its own virtual environment.

## If AI Dependencies Are Missing

If `/health` shows only this:

```json
{
  "success": true,
  "status": "ok",
  "service": "medconnect-ai"
}
```

or if it says dependencies are not available, run:

```powershell
cd C:\xampp\htdocs\medconnect
.\ai_service\install_ai_dependencies.bat
```

Then restart:

```powershell
.\ai_service\start_ai_service.bat
```

## How To Test Text AI

1. Start the AI service.
2. Open:

```text
http://localhost/medconnect/views/provider/consultation_session.php?id=2
```

3. In AI Transcript Assistant, paste:

```text
May hilanat kag ubo ang pasyente. Nag inom sang paracetamol.
```

4. Click `Analyze Transcript`.

Expected:

```text
Symptoms: fever, cough
Medicine: paracetamol
```

## How To Test Live Call AI

1. Start XAMPP Apache and MySQL.
2. Start the AI service:

```powershell
cd C:\xampp\htdocs\medconnect
.\ai_service\start_ai_service.bat
```

3. Open provider consultation session:

```text
http://localhost/medconnect/views/provider/consultation_session.php?id=2
```

4. Click `Start Video Consultation`.
5. Allow microphone and camera.
6. Speak for around 15-20 seconds.

Example:

```text
May hilanat kag ubo ang pasyente. Nag inom sang paracetamol.
```

7. Watch the AI Transcript Assistant panel.

Expected:

- Transcript appears while the call is still ongoing.
- Symptoms and medicines update after each processed audio chunk.

## Notes About Live AI Speed

The first live result can be slow because Faster-Whisper loads the model first.

After the model is loaded, later chunks should process faster.

Current chunk behavior:

```text
Records short audio chunks during the call
Sends each chunk to the AI service
Updates the dashboard when results return
```

## Current Limitations

This is still a prototype.

Current limitations:

- Hiligaynon translation is dictionary-based.
- It only understands known medical terms added to the dictionary.
- It is not yet a full Hiligaynon medical translation model.
- Live Whisper transcription may have delay depending on CPU speed.
- Browser microphone permission is required.
- The AI service window must stay open.

## Recommended Next Improvements

Future upgrades:

- Add a larger Hiligaynon-English medical dictionary.
- Add more medicine names and local brand names.
- Add a real translation model or translation API.
- Store combined live transcript per consultation.
- Add a provider button to review all AI notes from the session.
- Add confidence scores for extracted symptoms.
- Add Hiligaynon-specific Whisper language tuning if available.

