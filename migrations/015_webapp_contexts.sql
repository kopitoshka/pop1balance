-- Short-lived Mini App (Web App) picker contexts: action + actor + team,
-- addressed by a random token in the web_app button URL. TTL is enforced
-- in PHP; this table is only the store.

CREATE TABLE webapp_contexts (
	token           CHAR(32) NOT NULL PRIMARY KEY,
	actor_player_id INT UNSIGNED NOT NULL,
	chat_id         BIGINT NOT NULL,
	action          VARCHAR(32) NOT NULL,
	payload_json    TEXT NOT NULL,
	expires_at      DATETIME NOT NULL,
	created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	KEY idx_webapp_ctx_expires (expires_at),
	KEY idx_webapp_ctx_actor (actor_player_id),
	CONSTRAINT fk_webapp_ctx_actor FOREIGN KEY (actor_player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
