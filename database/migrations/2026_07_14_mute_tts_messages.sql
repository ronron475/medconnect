-- Mute Typed Voice (TTS) messages during video consultations
-- Also auto-applied by consultation_messages_ensure_schema().

ALTER TABLE consultation_messages
  ADD COLUMN message_kind VARCHAR(32) NOT NULL DEFAULT 'chat'
  COMMENT 'chat|mute_tts'
  AFTER message;
