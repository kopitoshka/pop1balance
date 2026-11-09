-- «Tournament in an hour» notices sent to groups (REMIND_ALL_GROUPS): at most one per
-- tournament and group, even when cron runs overlap. The row is claimed before sending.
CREATE TABLE group_notices (
	tournament_id  INT UNSIGNED NOT NULL,
	chat_id        BIGINT NOT NULL,
	created_at     DATETIME NOT NULL,
	result         VARCHAR(255) NULL,           -- sent | error text
	PRIMARY KEY (tournament_id, chat_id),
	CONSTRAINT fk_group_notices_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
