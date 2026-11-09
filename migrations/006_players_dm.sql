-- Private chat with the bot: who has started it (for personal reminders later) and which
-- group the player is currently working with in private (tournament numbers are per group).
ALTER TABLE players
	ADD COLUMN dm_ok TINYINT(1) NOT NULL DEFAULT 0,
	ADD COLUMN dm_started_at DATETIME NULL,
	ADD COLUMN dm_group_id BIGINT NULL;
