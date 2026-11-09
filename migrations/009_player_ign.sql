-- In-game nicknames (Population: One IGN) linked to Telegram players.
-- ign = display form; ign_norm = lower-case unique key. History keeps previous nicks.

ALTER TABLE players
	ADD COLUMN ign VARCHAR(64) NULL AFTER display_name,
	ADD COLUMN ign_norm VARCHAR(64) NULL AFTER ign,
	ADD UNIQUE KEY uq_players_ign_norm (ign_norm);

CREATE TABLE player_ign_history (
	id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	player_id    INT UNSIGNED NOT NULL,
	ign          VARCHAR(64) NOT NULL,
	ign_norm     VARCHAR(64) NOT NULL,
	changed_at   DATETIME NOT NULL,
	changed_by   INT UNSIGNED NULL,
	CONSTRAINT fk_ign_hist_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
	CONSTRAINT fk_ign_hist_author FOREIGN KEY (changed_by) REFERENCES players(id) ON DELETE SET NULL,
	KEY idx_ign_hist_player (player_id, changed_at),
	KEY idx_ign_hist_norm (ign_norm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
