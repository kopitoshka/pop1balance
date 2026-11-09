-- Edited messages addressed to the bot that already got the "write a new message" reply
-- (Telegram may send several edits of the same message; we answer once).
CREATE TABLE edited_replies (
	chat_id     BIGINT NOT NULL,
	message_id  BIGINT NOT NULL,
	created_at  DATETIME NOT NULL,
	PRIMARY KEY (chat_id, message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
