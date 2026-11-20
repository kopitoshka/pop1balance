-- Preferred team name of a player: the name of the last team they captained.
-- Set right away on team creation, rename and captain transfer; the new-team form prefills it.
-- Backfill: the most recently created team (created_at, then id) where the player is captain.
ALTER TABLE players ADD COLUMN preferred_team_name VARCHAR(64) NULL;

UPDATE players SET preferred_team_name = (
	SELECT t.name FROM teams t
	WHERE t.captain_id = players.id
	ORDER BY t.created_at DESC, t.id DESC
	LIMIT 1
) WHERE preferred_team_name IS NULL;
