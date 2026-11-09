-- Global tournaments: announcements in every active group; chat_id kept as optional origin.
-- group_notices gains minutes so 60- and 15-minute broadcasts can both be claimed.

CREATE TABLE tournament_announcements (
	tournament_id  INT UNSIGNED NOT NULL,
	chat_id        BIGINT NOT NULL,
	message_id     BIGINT NOT NULL,
	PRIMARY KEY (tournament_id, chat_id),
	CONSTRAINT fk_tann_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tournament_announcements (tournament_id, chat_id, message_id)
SELECT id, chat_id, announce_msg_id FROM tournaments
WHERE announce_msg_id IS NOT NULL AND chat_id IS NOT NULL;

ALTER TABLE tournaments MODIFY chat_id BIGINT NULL;

CREATE TABLE group_notices_new (
	tournament_id  INT UNSIGNED NOT NULL,
	chat_id        BIGINT NOT NULL,
	minutes        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
	created_at     DATETIME NOT NULL,
	result         VARCHAR(255) NULL,
	PRIMARY KEY (tournament_id, chat_id, minutes),
	CONSTRAINT fk_group_notices_new_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO group_notices_new (tournament_id, chat_id, minutes, created_at, result)
SELECT tournament_id, chat_id, 60, created_at, result FROM group_notices;

DROP TABLE group_notices;
RENAME TABLE group_notices_new TO group_notices;
