# Emotion Intent Dataset — medConnect FAQ Chatbot

**Source CSV:** `emotion_intent_phrases.csv`  
**Generated JS:** `public/assets/js/faq-chatbot/emotion_intent_dataset.js` (10,000 phrases)  
**Full CSV export:** `data/nlp/emotion_intent_phrases_full.csv`  
**Build script:** `scripts/data/build_emotion_intent_dataset.py`

## Purpose

Recognize patient emotional intent from messages in **English**, **Filipino (Tagalog)**, **Hiligaynon (Ilonggo)**, and mixed-language text for the City Health Office – Bago City medConnect FAQ chatbot.

The chatbot acknowledges emotion with empathy, then redirects to the appropriate medConnect service. It does **not** diagnose, prescribe, or provide therapy.

## Supported emotions (20)

`happy` · `thankful` · `relieved` · `excited` · `curious` · `confused` · `frustrated` · `worried` · `anxious` · `nervous` · `sad` · `lonely` · `afraid` · `angry` · `disappointed` · `stressed` · `tired` · `hopeless` · `panic` · `emergency`

## CSV format

```csv
emotion,phrase,language
frustrated,Kapoy na ko sini,hil
worried,Nabalaka ko,hil
thankful,Salamat guid,hil
```

## Rebuild dataset after editing CSV

```bash
python scripts/data/build_emotion_intent_dataset.py
```

This regenerates `emotion_intent_dataset.js` with **10,000 unique phrases** and exports `emotion_intent_phrases_full.csv`.

## Performance

The dataset is large (~1 MB). `emotions.js` builds an inverted word index at first use so matching stays fast in the browser.

## Architecture

```
User message
  → McFaqLanguage (detect EN / FIL / HIL)
  → McFaqEmotionDataset (phrase matching)
  → McFaqEmotions (scoring + priority)
  → McFaqI18n.getEmpathyPrefix() (localized empathy)
  → McFaqEngine (FAQ intent + service redirect)
```

## Expanding to 300–500 phrases

Add rows to `emotion_intent_phrases.csv` with:

- Local Hiligaynon slang and spelling variants (`kaakig`, `kulba`, `kapoy gid`)
- Filipino expressions (`nag-aalala`, `hindi ko gets`)
- Mixed-language inputs (`Need ko mag-reset sang password`)
- Common misspellings

Then run the build script.

## Safety

- `emergency` and self-harm patterns bypass normal FAQ flows
- Medical advice requests are redirected to policy messaging
- Empathy responses never claim human feelings or provide counseling
