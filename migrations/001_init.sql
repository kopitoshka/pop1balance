-- P1 tournament bot: initial schema (MySQL 8 / MariaDB 10.6+).
-- All DATETIME values are stored in UTC; conversion to Europe/Moscow happens in PHP.
-- Reserve lists are NOT stored: they are computed from registration order (see TournamentService::lineup()).

SET NAMES utf8mb4;

CREATE TABLE players (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tg_user_id    BIGINT NULL,                 -- NULL while the player is known only by @username
  username      VARCHAR(64) NULL,            -- without '@', stored lower-case
  display_name  VARCHAR(128) NOT NULL DEFAULT '',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tg_user (tg_user_id),
  UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE chats (
  id            BIGINT PRIMARY KEY,          -- Telegram chat_id of the group
  title         VARCHAR(255) NOT NULL DEFAULT '',
  is_active     TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admins (
  player_id     INT UNSIGNED NOT NULL,
  chat_id       BIGINT NOT NULL,
  is_owner      TINYINT(1) NOT NULL DEFAULT 0, -- bot author
  PRIMARY KEY (player_id, chat_id),
  CONSTRAINT fk_admins_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
  CONSTRAINT fk_admins_chat   FOREIGN KEY (chat_id)   REFERENCES chats(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A rating scale ("баланс") owned by one admin. The default scale belongs to the bot owner.
CREATE TABLE balances (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id      INT UNSIGNED NOT NULL,
  parent_id     INT UNSIGNED NULL,           -- inherited scale: missing ratings are looked up there
  name          VARCHAR(128) NOT NULL,
  is_default    TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_owner (owner_id),
  CONSTRAINT fk_balances_owner  FOREIGN KEY (owner_id)  REFERENCES players(id),
  CONSTRAINT fk_balances_parent FOREIGN KEY (parent_id) REFERENCES balances(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE balance_ratings (
  balance_id    INT UNSIGNED NOT NULL,
  player_id     INT UNSIGNED NOT NULL,
  level         TINYINT UNSIGNED NOT NULL,   -- 1..6, see Domain\Levels
  updated_by    INT UNSIGNED NOT NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (balance_id, player_id),
  CONSTRAINT fk_ratings_balance FOREIGN KEY (balance_id) REFERENCES balances(id) ON DELETE CASCADE,
  CONSTRAINT fk_ratings_player  FOREIGN KEY (player_id)  REFERENCES players(id)  ON DELETE CASCADE,
  CONSTRAINT fk_ratings_author  FOREIGN KEY (updated_by) REFERENCES players(id),
  CONSTRAINT chk_ratings_level  CHECK (level BETWEEN 1 AND 6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tournaments (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chat_id         BIGINT NOT NULL,
  organizer_id    INT UNSIGNED NOT NULL,      -- author of the tournament, set automatically
  title           VARCHAR(255) NOT NULL,
  starts_at       DATETIME NOT NULL,          -- UTC
  team_size       TINYINT UNSIGNED NOT NULL,  -- 2..4
  balance_id      INT UNSIGNED NULL,          -- NULL = no level limit
  reference_team  VARCHAR(32) NULL,           -- reference (strongest allowed) team, e.g. "3,3,4"
  max_points      TINYINT UNSIGNED NULL,      -- sum of reference_team, e.g. 10
  max_players     SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  status          ENUM('draft','registration','closed','running','finished','cancelled') NOT NULL DEFAULT 'draft',
  announce_msg_id BIGINT NULL,                -- announcement message in the group
  reminded_at     DATETIME NULL,              -- so cron does not remind twice
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_chat_status (chat_id, status, starts_at),
  CONSTRAINT fk_tournaments_chat      FOREIGN KEY (chat_id)      REFERENCES chats(id),
  CONSTRAINT fk_tournaments_organizer FOREIGN KEY (organizer_id) REFERENCES players(id),
  CONSTRAINT fk_tournaments_balance   FOREIGN KEY (balance_id)   REFERENCES balances(id) ON DELETE SET NULL,
  CONSTRAINT chk_tournaments_team_size   CHECK (team_size BETWEEN 2 AND 4),
  CONSTRAINT chk_tournaments_max_players CHECK (max_players > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Withdrawing a team deletes its row (members cascade). No 'withdrawn'/'reserve' statuses.
CREATE TABLE teams (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tournament_id   INT UNSIGNED NOT NULL,
  captain_id      INT UNSIGNED NOT NULL,
  name            VARCHAR(64) NOT NULL,
  status          ENUM('forming','registered') NOT NULL DEFAULT 'forming',
  registered_at   DATETIME NULL,              -- set when the roster is full; defines queue order
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_team_name (tournament_id, name),
  UNIQUE KEY uq_team_tournament (id, tournament_id), -- target for the composite FK below
  KEY idx_queue (tournament_id, status, registered_at),
  CONSTRAINT fk_teams_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
  CONSTRAINT fk_teams_captain    FOREIGN KEY (captain_id)    REFERENCES players(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE team_members (
  team_id         INT UNSIGNED NOT NULL,
  tournament_id   INT UNSIGNED NOT NULL,      -- duplicated to keep a player unique per tournament
  player_id       INT UNSIGNED NOT NULL,
  level_snapshot  TINYINT UNSIGNED NOT NULL,  -- level frozen when the player joined
  joined_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (team_id, player_id),
  UNIQUE KEY uq_player_in_tournament (tournament_id, player_id),
  CONSTRAINT fk_members_team   FOREIGN KEY (team_id, tournament_id) REFERENCES teams(id, tournament_id) ON DELETE CASCADE,
  CONSTRAINT fk_members_player FOREIGN KEY (player_id) REFERENCES players(id),
  CONSTRAINT chk_members_level CHECK (level_snapshot BETWEEN 1 AND 6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Want to play" without a team. Leaving deletes the row. A player cannot be both here
-- and in a team of the same tournament (enforced by TeamService).
CREATE TABLE solo_players (
  tournament_id   INT UNSIGNED NOT NULL,
  player_id       INT UNSIGNED NOT NULL,
  level_snapshot  TINYINT UNSIGNED NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- defines queue order
  PRIMARY KEY (tournament_id, player_id),
  KEY idx_queue (tournament_id, created_at),
  CONSTRAINT fk_solo_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
  CONSTRAINT fk_solo_player     FOREIGN KEY (player_id)     REFERENCES players(id),
  CONSTRAINT chk_solo_level     CHECK (level_snapshot BETWEEN 1 AND 6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Short dialog memory for the AI layer: a reply to last_bot_msg_id continues the dialog.
CREATE TABLE conversations (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chat_id         BIGINT NOT NULL,
  player_id       INT UNSIGNED NOT NULL,
  last_bot_msg_id BIGINT NULL,
  history_json    JSON NOT NULL,
  expires_at      DATETIME NOT NULL,
  KEY idx_lookup (chat_id, last_bot_msg_id),
  KEY idx_expires (expires_at),
  CONSTRAINT fk_conv_chat   FOREIGN KEY (chat_id)   REFERENCES chats(id)   ON DELETE CASCADE,
  CONSTRAINT fk_conv_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
