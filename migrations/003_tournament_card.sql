-- Draft tournament card ("Публикуем или что-то поправить?") last shown to the organizer.
-- NULL = the current version of the draft has not been shown yet, so it cannot be published.
ALTER TABLE tournaments ADD COLUMN card_msg_id BIGINT NULL AFTER announce_msg_id;
