# Teleconsultation & Session Features

This document describes the teleconsultation, scheduling, queue, and video-session features implemented for MedConnect. Use it as a reference for how each feature works, which files are involved, and how to test them locally.

---

## Table of Contents

1. [Schedule-Based Appointment Booking](#1-schedule-based-appointment-booking)
2. [Provider Schedule Management](#2-provider-schedule-management)
3. [Fixed Portal Headers](#3-fixed-portal-headers)
4. [Provider Queue & Session Access Rules](#4-provider-queue--session-access-rules)
5. [Provider Messages — Call & Video Guards](#5-provider-messages--call--video-guards)
6. [Provider Triage History UI](#6-provider-triage-history-ui)
7. [Patient Triage & Slot Booking](#7-patient-triage--slot-booking)
8. [Video Consultation Room](#8-video-consultation-room)
9. [Session Extension (Doctor)](#9-session-extension-doctor)
10. [Key Files Reference](#10-key-files-reference)
11. [Test Plan](#11-test-plan)

---

## 1. Schedule-Based Appointment Booking

Patients book consultations from **real provider schedule slots** instead of free-form times.

### How it works

1. Provider sets weekly availability on **Schedule**.
2. The system generates `appointment_slots` for upcoming dates.
3. Patient triage flow loads available dates, then available times for the selected provider.
4. Booking links the slot to the patient and consultation.

### Database

Run once if not already applied:

```text
database/schema_schedule_updates.sql
```

Adds:

- Unique provider schedule per weekday
- `patient_id` and `consultation_id` on `appointment_slots`
- Unique slot per provider/date/start time

### APIs

| Endpoint | Purpose |
| --- | --- |
| `app/api/appointments/get_available_dates.php` | Dates with open slots for a provider |
| `app/api/appointments/get_available_slots.php` | Time slots for a provider + date |
| `app/api/patient/submit_triage.php` | Books slot and creates/reschedules consultation |

---

## 2. Provider Schedule Management

Providers manage availability and generated slots from:

```text
views/provider/schedule.php
```

### Fixes included

- Schedule save works reliably via `app/api/provider/save_schedule.php`
- Saved weekly hours display correctly after reload
- Slots regenerate for the next 4 weeks without duplicating existing rows

---

## 3. Fixed Portal Headers

All role portals use a **fixed top header** that stays visible while scrolling.

| Role | Shell CSS | Layout |
| --- | --- | --- |
| Provider | `assets/css/provider_shell.css` | `views/provider/partials/layout_open.php` |
| Admin / BHW | `assets/css/portal_shell.css` | respective layout partials |
| Patient | `assets/css/portal_shell.css` | `views/patient/layout_shell_open.php` |

The header is rendered outside the scrollable `main` area so navigation remains accessible on long pages.

---

## 4. Provider Queue & Session Access Rules

Location:

```text
views/provider/queue.php
```

### Open Session rule

A provider can open a consultation session only when:

- The consultation is scheduled for **today**, or
- The consultation is already **`in_consultation`**

Otherwise, **Open Session** is blocked and a custom in-app modal explains why (not a browser `alert`).

### Shared helpers & UI

| File | Role |
| --- | --- |
| `views/provider/partials/queue_helpers.php` | `queue_session_access()` date/session logic |
| `views/provider/partials/session_schedule_modal.php` | Reusable schedule warning modal |
| `assets/js/provider-session-alert.js` | Modal display logic |
| `assets/css/provider_session_alert.css` | Modal styling |

`views/provider/consultation_session.php` also enforces the same rule on direct URL access.

---

## 5. Provider Messages — Call & Video Guards

Location:

```text
views/provider/messages.php
```

**Call** and **Video** actions use the same schedule rule as the queue:

- Allowed today or when already in consultation
- Blocked with the shared custom modal when outside the allowed window

Server-side guard:

```text
app/api/consultations/start_video.php
```

Prevents starting a video session when the consultation date rule fails.

---

## 6. Provider Triage History UI

Location:

```text
views/provider/triage_history.php
```

Redesigned page includes:

- Summary stats
- Search and filters
- Symptom chips
- Detail modal for each triage record
- Styling via `assets/css/provider_triage.css`

---

## 7. Patient Triage & Slot Booking

Location:

```text
views/patient/dashboard.php#view-triage
assets/js/patient-portal.js
```

### Fixes included

- Hash routing to triage view works correctly
- Available dates and slots load from schedule APIs
- Slot must be selected before submit
- Clearer validation and error messages
- Handles `in_consultation` state without confusing duplicate-booking errors
- `submit_triage.php` can reschedule open consultations when appropriate

---

## 8. Video Consultation Room

Location:

```text
views/consultation/video_room.php
```

Entry points:

- Provider consultation session iframe
- Provider queue direct room link
- Patient dashboard **Join Call**

### Timer

- Countdown is based on the booked slot duration (default **30 minutes**)
- Timer uses actual slot end time when available
- At **5 minutes remaining**, the provider sees an extension prompt

### End call behavior

| Role | Button | Behavior |
| --- | --- | --- |
| **Patient** | Leave Call | Disconnects from video only; session stays `active`; patient can rejoin from dashboard |
| **Provider** | End Consultation | Ends session via `end_video.php`; room closes for both sides |

Server enforcement:

```text
app/api/consultations/end_video.php
```

Patients cannot end the consultation session server-side.

### Custom end-call modal

Browser `alert()` was replaced with an in-room modal for both leave and end actions.

---

## 9. Session Extension (Doctor)

Doctors can extend an active consultation by **15 minutes** when the next slot is not booked by another patient.

### Where to extend

1. **Consultation session sidebar** — `Extend Session (+15 min)`
2. **Video room status bar** — `+15 min` button (provider only)
3. **5-minute warning banner** in the video room — `Extend 15m`

### How it works

1. Provider requests extension with `consultation_id`.
2. API checks for conflicting booked slots after the current end time.
3. If clear, `appointment_slots.end_time` is updated in the database.
4. Provider video timer updates immediately.
5. Patient video timer syncs via polling (every 20 seconds).

### APIs

| Endpoint | Method | Purpose |
| --- | --- | --- |
| `app/api/provider/check_extension.php` | POST | Validate and apply 15-minute extension |
| `app/api/consultations/session_timer.php` | GET | Return remaining seconds for active video session |

### POST parameters (`check_extension.php`)

| Field | Required | Description |
| --- | --- | --- |
| `consultation_id` | Yes | Active consultation ID |
| `extension_mins` | No | Minutes to add (default `15`, clamped 5–60) |

### Success response example

```json
{
  "success": true,
  "message": "Session extended by 15 minutes.",
  "extension_mins": 15,
  "new_end_time": "11:15:00",
  "new_end_label": "11:15 AM",
  "seconds_remaining": 842
}
```

### Blocked response example

```json
{
  "success": false,
  "message": "Extension blocked: another patient is booked in the next slot."
}
```

### Parent ↔ iframe sync

When extending from the consultation session page, the parent posts a message to the video iframe:

```text
medconnect:extend-session
```

When extending from inside the video room, the iframe notifies the parent:

```text
medconnect:session-extended
```

### Patient experience after slot time ends

- Patient timer shows `00:00` but the call stays open
- Status message explains they can wait for a doctor extension
- When the doctor extends, the patient timer updates automatically and a toast confirms the new end time

---

## 10. Key Files Reference

```text
# Scheduling & booking
database/schema_schedule_updates.sql
app/api/provider/save_schedule.php
app/api/appointments/get_available_dates.php
app/api/appointments/get_available_slots.php
app/api/patient/submit_triage.php
views/provider/schedule.php
assets/js/patient-portal.js

# Queue & session access
views/provider/queue.php
views/provider/consultation_session.php
views/provider/partials/queue_helpers.php
views/provider/partials/session_schedule_modal.php
assets/js/provider-session-alert.js
assets/css/provider_session_alert.css

# Messages & video
views/provider/messages.php
app/api/consultations/start_video.php
app/api/consultations/end_video.php
views/consultation/video_room.php

# Session extension
app/api/provider/check_extension.php
app/api/consultations/session_timer.php

# Provider UI
views/provider/triage_history.php
assets/css/provider_triage.css
assets/css/provider_shell.css
assets/css/portal_shell.css
```

---

## 11. Test Plan

### Schedule & booking

- [ ] Provider saves schedule and sees generated slots on `schedule.php`
- [ ] Patient triage shows only dates/times with available slots
- [ ] Submitting triage books the slot and creates a consultation

### Queue & session access

- [ ] **Open Session** works for today's consultation
- [ ] **Open Session** is blocked for future/past dates with modal message
- [ ] Direct visit to `consultation_session.php?id=...` respects the same rule

### Messages & video start

- [ ] Call/Video blocked outside allowed date with custom modal
- [ ] Video starts successfully for today's consultation

### Video room

- [ ] Timer reflects slot duration (e.g. 30:00)
- [ ] Patient **Leave Call** allows rejoin from patient dashboard
- [ ] Provider **End Consultation** closes session for both sides

### Session extension

- [ ] Provider extends from consultation session sidebar; timer updates in iframe
- [ ] Provider extends from video `+15 min` button
- [ ] Extension blocked when next slot is booked by another patient
- [ ] Patient timer recovers after doctor extends (within ~20 seconds)

### UI

- [ ] Fixed headers remain visible while scrolling on provider, admin, BHW, and patient portals
- [ ] Triage history search, filters, and detail modal work

---

## Related Documentation

- AI teleconsultation setup and Python service: `docs/AI_TELECONSULTATION_SETUP.md`
- Database migration history: `docs/MIGRATION_LOG.md`
