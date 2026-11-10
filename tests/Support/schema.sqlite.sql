-- SQLite mirror of migrations/*.sql (all migrations applied) for the test suite.
-- The MySQL file is the reference; keep both in sync.

CREATE TABLE players (
	id            INTEGER PRIMARY KEY AUTOINCREMENT,
	tg_user_id    INTEGER NULL UNIQUE,
	username      TEXT NULL UNIQUE,
	display_name  TEXT NOT NULL DEFAULT '',
	ign           TEXT NULL,
	ign_norm      TEXT NULL UNIQUE,
	created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
	dm_ok         INTEGER NOT NULL DEFAULT 0,
	dm_started_at TEXT NULL,
	dm_group_id   INTEGER NULL
);

CREATE TABLE player_ign_history (
	id           INTEGER PRIMARY KEY AUTOINCREMENT,
	player_id    INTEGER NOT NULL REFERENCES players(id) ON DELETE CASCADE,
	ign          TEXT NOT NULL,
	ign_norm     TEXT NOT NULL,
	changed_at   TEXT NOT NULL,
	changed_by   INTEGER NULL REFERENCES players(id) ON DELETE SET NULL
);

CREATE TABLE chats (
	id            INTEGER PRIMARY KEY,
	title         TEXT NOT NULL DEFAULT '',
	is_active     INTEGER NOT NULL DEFAULT 1,
	admins_synced_at TEXT NULL
);

CREATE TABLE admins (
	player_id     INTEGER NOT NULL REFERENCES players(id) ON DELETE CASCADE,
	chat_id       INTEGER NOT NULL REFERENCES chats(id) ON DELETE CASCADE,
	is_owner      INTEGER NOT NULL DEFAULT 0,
	PRIMARY KEY (player_id, chat_id)
);

CREATE TABLE balances (
	id            INTEGER PRIMARY KEY AUTOINCREMENT,
	owner_id      INTEGER NOT NULL REFERENCES players(id),
	parent_id     INTEGER NULL REFERENCES balances(id) ON DELETE SET NULL,
	name          TEXT NOT NULL,
	is_default    INTEGER NOT NULL DEFAULT 0,
	created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE balance_ratings (
	balance_id    INTEGER NOT NULL REFERENCES balances(id) ON DELETE CASCADE,
	player_id     INTEGER NOT NULL REFERENCES players(id) ON DELETE CASCADE,
	level         INTEGER NOT NULL CHECK (level BETWEEN 1 AND 6),
	updated_by    INTEGER NOT NULL REFERENCES players(id),
	updated_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (balance_id, player_id)
);

CREATE TABLE tournaments (
	id              INTEGER PRIMARY KEY AUTOINCREMENT,
	chat_id         INTEGER NULL REFERENCES chats(id),
	organizer_id    INTEGER NOT NULL REFERENCES players(id),
	title           TEXT NOT NULL,
	starts_at       TEXT NOT NULL,
	team_size       INTEGER NOT NULL CHECK (team_size BETWEEN 2 AND 4),
	balance_id      INTEGER NULL REFERENCES balances(id) ON DELETE SET NULL,
	reference_team  TEXT NULL,
	max_points      INTEGER NULL,
	max_players     INTEGER NOT NULL DEFAULT 24 CHECK (max_players > 0),
	status          TEXT NOT NULL DEFAULT 'draft'
									CHECK (status IN ('draft','registration','closed','running','finished','cancelled')),
	announce_msg_id INTEGER NULL,
	card_msg_id     INTEGER NULL,
	card_shown_to   INTEGER NULL,
	reminded_at     TEXT NULL,
	created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_tournaments_chat_status ON tournaments (chat_id, status, starts_at);

CREATE TABLE teams (
	id              INTEGER PRIMARY KEY AUTOINCREMENT,
	tournament_id   INTEGER NOT NULL REFERENCES tournaments(id) ON DELETE CASCADE,
	captain_id      INTEGER NOT NULL REFERENCES players(id),
	name            TEXT NOT NULL,
	status          TEXT NOT NULL DEFAULT 'forming' CHECK (status IN ('forming','registered')),
	registered_at   TEXT NULL,
	created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
	UNIQUE (tournament_id, name),
	UNIQUE (id, tournament_id)
);

CREATE TABLE team_members (
	team_id         INTEGER NOT NULL,
	tournament_id   INTEGER NOT NULL,
	player_id       INTEGER NOT NULL REFERENCES players(id),
	joined_at       TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (team_id, player_id),
	UNIQUE (tournament_id, player_id),
	FOREIGN KEY (team_id, tournament_id) REFERENCES teams(id, tournament_id) ON DELETE CASCADE
);

CREATE TABLE solo_players (
	tournament_id   INTEGER NOT NULL REFERENCES tournaments(id) ON DELETE CASCADE,
	player_id       INTEGER NOT NULL REFERENCES players(id),
	created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (tournament_id, player_id)
);

CREATE TABLE conversations (
	id              INTEGER PRIMARY KEY AUTOINCREMENT,
	chat_id         INTEGER NOT NULL REFERENCES chats(id) ON DELETE CASCADE,
	player_id       INTEGER NOT NULL REFERENCES players(id) ON DELETE CASCADE,
	last_bot_msg_id INTEGER NULL,
	history_json    TEXT NOT NULL,
	expires_at      TEXT NOT NULL
);
CREATE INDEX idx_conversations_lookup ON conversations (chat_id, last_bot_msg_id);

CREATE TABLE edited_replies (
	chat_id     INTEGER NOT NULL,
	message_id  INTEGER NOT NULL,
	created_at  TEXT NOT NULL,
	PRIMARY KEY (chat_id, message_id)
);

CREATE TABLE group_notices (
	tournament_id  INTEGER NOT NULL REFERENCES tournaments (id) ON DELETE CASCADE,
	chat_id        INTEGER NOT NULL,
	minutes        INTEGER NOT NULL DEFAULT 0,
	created_at     TEXT NOT NULL,
	result         TEXT NULL,
	PRIMARY KEY (tournament_id, chat_id, minutes)
);

CREATE TABLE tournament_announcements (
	tournament_id  INTEGER NOT NULL REFERENCES tournaments (id) ON DELETE CASCADE,
	chat_id        INTEGER NOT NULL,
	message_id     INTEGER NOT NULL,
	PRIMARY KEY (tournament_id, chat_id)
);

CREATE TABLE pending_player_actions (
	id              INTEGER PRIMARY KEY AUTOINCREMENT,
	actor_player_id INTEGER NOT NULL REFERENCES players(id) ON DELETE CASCADE,
	chat_id         INTEGER NOT NULL,
	action_json     TEXT NOT NULL,
	expires_at      TEXT NOT NULL,
	created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_pending_expires ON pending_player_actions(expires_at);

CREATE TABLE webapp_contexts (
	token           TEXT NOT NULL PRIMARY KEY,
	actor_player_id INTEGER NOT NULL REFERENCES players(id) ON DELETE CASCADE,
	chat_id         INTEGER NOT NULL,
	action          TEXT NOT NULL,
	payload_json    TEXT NOT NULL,
	expires_at      TEXT NOT NULL,
	created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_webapp_ctx_expires ON webapp_contexts(expires_at);
