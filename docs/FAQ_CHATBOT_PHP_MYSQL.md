# medConnect FAQ Chatbot — PHP + MySQL (no external AI)

This guide describes the **PHP-only** emotionally intelligent FAQ chatbot: rule-based emotion and intent detection, MySQL FAQs, conversation logging, emergencies, and feedback. It extends the existing landing-page widget (`McFaq*` JS + PHP APIs).

## Folder structure

```
database/migrations/2026_07_29_faq_chatbot_mysql.sql   # Schema + seed FAQs
app/includes/faq_chatbot_schema.php                   # Auto-create tables (dev)
app/core/FaqChatbotStandardEmotion.php                 # 8 canonical emotions
app/core/FaqChatbotEmergencyDetector.php
app/core/FaqChatbotIntentRecognizer.php
app/core/FaqChatbotFaqRepository.php                  # FULLTEXT / LIKE / similarity
app/core/FaqChatbotConversationRepository.php
app/core/FaqChatbotResponseGenerator.php
app/core/FaqChatbotOrchestrator.php                   # Main pipeline
app/core/FaqEmotionEngine.php                         # Existing detailed emotion rules
app/api/faq_chatbot_session.php
app/api/faq_chatbot_chat.php
app/api/faq_chatbot_feedback.php
app/api/faq_chatbot_emotion.php                       # Existing emotion API
public/assets/js/faq-chatbot/chat-api.js              # AJAX client
public/assets/js/faq-chatbot/app.js                   # UI orchestration (assist mode)
resources/views/landing/partials/faq_chatbot.php      # data-php-chat="assist"
docs/FAQ_CHATBOT_PHP_MYSQL.md                         # This file
```

## Step 1 — Database

1. Open phpMyAdmin or MySQL CLI.
2. Run:

```bash
mysql -u root -p medconnect < database/migrations/2026_07_29_faq_chatbot_mysql.sql
```

Tables:

| Table | Purpose |
|--------|---------|
| `faq` | category, question, answer, keywords |
| `chatbot_conversations` | session_id, user_id, lang |
| `chatbot_messages` | user/bot text, intent, flow_key, confidence, faq_id |
| `chatbot_emotions` | per user message: emotion + canonical (Happy/Sad/…) |
| `chatbot_feedback` | helpful / not_helpful per bot message |

On first API call, `faq_chatbot_ensure_schema()` also creates tables if missing (local dev).

## Step 2 — Configure landing chatbot

In `faq_chatbot.php`:

- `data-php-chat="assist"` — PHP logs + FAQ/emergency override; rich JS flows remain.
- `data-php-chat="full"` — always use PHP-generated HTML (FAQ + conversational fallback).
- `data-php-chat="off"` — disable MySQL pipeline (client-only).

## Step 3 — API usage (AJAX)

### Session

`GET /app/api/faq_chatbot_session.php` → `{ "success": true, "data": { "session_id": "..." } }`

### Chat

`POST /app/api/faq_chatbot_chat.php`

```json
{
  "text": "How do I register?",
  "lang": "en",
  "mode": "assist"
}
```

Response fields (in `data`):

- `emotion` — canonical: happy, sad, worried, angry, frustrated, confused, fearful, neutral
- `intent` — greeting, appointment, emergency, …
- `confidence` — 0–1 (FAQ match + intent)
- `emergency` / `emergency_flow` — stop normal FAQ when true
- `response_html` — safe HTML (escaped content)
- `suggestions` — related FAQ questions
- `typing_ms` — suggested delay for typing indicator
- `use_server_response` — when true, frontend should render `response_html`
- `bot_message_id` — use for feedback

Modes:

- `assist` — log user + emotion; server reply only for emergency or strong FAQ match
- `full` — always return PHP reply and log bot message
- `log_bot` — log client-rendered bot HTML after JS flow (`client_html`, `flow_key`)

### Feedback

`POST /app/api/faq_chatbot_feedback.php`

```json
{ "message_id": 42, "rating": "helpful" }
```

## Step 4 — Emotion detection (PHP)

1. `FaqEmotionEngine::analyze()` — keyword/regex (EN, Filipino, Hiligaynon).
2. `FaqChatbotStandardEmotion::canonicalize()` — maps to the 8 labels required for reporting.

Examples:

- “I am scared.” → `afraid` → **Fearful**
- “I feel sad today.” → `sad` → **Sad**

Empathy lines: `FaqChatbotResponseGenerator::empathyLine()`.

## Step 5 — Emergency handling

`FaqChatbotEmergencyDetector` matches chest pain, stroke, breathing difficulty, severe bleeding, unconsciousness, suicide/self-harm language.

When triggered:

- `response_html` shows emergency card (911 / ER).
- Normal FAQ is not continued (`use_server_response` true in assist mode).

## Step 6 — FAQ search

`FaqChatbotFaqRepository::search()`:

1. MySQL `FULLTEXT` on question, answer, keywords (if index exists).
2. Fallback `LIKE` on tokens.
3. PHP `similar_text()` scoring.

Manage FAQs in the `faq` table; set `keywords` as comma-separated phrases.

## Step 7 — Security

- All DB access via **PDO prepared statements**.
- Output escaped with `htmlspecialchars` in PHP generators.
- JSON APIs use `Api::startJson()` + session cookies (`httponly`).
- Message length limits (2000 chars server-side, 500 in UI).
- No diagnosis or prescribing copy in generators; inline medical disclaimer on FAQ answers.

## Step 8 — Manual test checklist

1. Run migration; confirm `faq` has seed rows.
2. Hard-refresh landing page; open chat.
3. Ask “How do I register?” → FAQ answer from MySQL (assist may use server HTML).
4. Say “I'm so stressed” → client distress flow; row in `chatbot_emotions`.
5. Say “chest pain” → emergency card; no casual FAQ continuation.
6. Click 👍 on a reply → row in `chatbot_feedback`.

## Optional: pure PHP UI mode

Set `data-php-chat="full"` to drive all answers from `FaqChatbotOrchestrator` (still uses the same panel, typing indicator, and feedback UI).

---

## Hiligaynon NLP pipeline (PHP only)

Migration: `database/migrations/2026_07_30_faq_chatbot_nlp_full.sql`

| Table | Purpose |
|--------|---------|
| `translation_dictionary` | Hiligaynon → English phrases and tokens |
| `medical_terms` | Clinical glosses |
| `synonyms` | FAQ / intent expansion |
| `chatbot_intents` | Intent catalog |
| `response_templates` | Warm `not_understood` / `no_exact_faq` copy |
| `conversation_history` | user text, translation, lang, emotion, intent, bot reply |

Core classes:

- `FaqChatbotNlpPipeline` — detect language → normalize → typo fix → translate → expand synonyms
- `FaqChatbotTranslator` / `FaqChatbotTypoCorrector` / `FaqChatbotSynonymEngine`
- `FaqChatbotDictionaryRepository` + `FaqChatbotDictionarySeed`

**Build large dictionary (10k+ rows from existing corpora):**

```bash
php scripts/data/build_faq_chatbot_dictionary.php
php scripts/data/import_faq_chatbot_dictionary.php --force
```

**APIs:**

- `POST /app/api/faq_chatbot_translate.php` — run pipeline only (used by `McFaqChatApi.translate`)
- Chat/emotion responses include `english_gloss`, `detected_lang`, `nlp_pipeline`

**Admin:** `views/admin/faq_chatbot.php` — counts, re-import, translation test.

Orchestrator flow: user message → `FaqChatbotNlpPipeline::process()` → emotion/intent/emergency on English gloss → FAQ search (synonyms + Levenshtein) → `conversation_history` row.

---

**Note:** Voice (`faq_chatbot_voice.php`) may use Whisper when configured; the **emotion/FAQ/intent pipeline described here is 100% PHP + MySQL**. Disable voice or use browser Web Speech only if you require zero Python services.
