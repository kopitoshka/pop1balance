-- Who the current draft card was shown to (the admin who ran /new, /edit or /open).
-- Besides the organizer and the bot owner, this person may confirm publishing.
ALTER TABLE tournaments ADD COLUMN card_shown_to BIGINT NULL AFTER card_msg_id;
