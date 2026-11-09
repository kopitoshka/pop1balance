-- Pending «maybe you meant…» player picks (fuzzy @username suggestions).
-- Short TTL; callback data is sug:<id>:<player_id> (≤64 bytes).

CREATE TABLE pending_player_actions (
	id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	actor_player_id INT UNSIGNED NOT NULL,
	chat_id         BIGINT NOT NULL,
	action_json     TEXT NOT NULL,
	expires_at      DATETIME NOT NULL,
	created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	KEY idx_pending_expires (expires_at),
	KEY idx_pending_actor (actor_player_id),
	CONSTRAINT fk_pending_actor FOREIGN KEY (actor_player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
