-- Purge ALL placeholder players (tg_user_id IS NULL), including those still on
-- old team_members / solo_players / ratings. Empty teams left behind are deleted
-- (safe: no roster). Placeholders that own a balance or organize a tournament
-- are kept (should not happen in practice — check with diag --placeholders).
--
-- After: php bin/diag.php --placeholders  →  None.

-- 1) Drop roster links.
DELETE FROM team_members
WHERE player_id IN (SELECT id FROM (SELECT id FROM players WHERE tg_user_id IS NULL) AS ph);

DELETE FROM solo_players
WHERE player_id IN (SELECT id FROM (SELECT id FROM players WHERE tg_user_id IS NULL) AS ph);

-- 2) Teams whose captain was a placeholder but still have members → new captain.
UPDATE teams t
INNER JOIN (
	SELECT tm.team_id, tm.tournament_id, MIN(tm.player_id) AS new_cap
	FROM team_members tm
	GROUP BY tm.team_id, tm.tournament_id
) x ON x.team_id = t.id AND x.tournament_id = t.tournament_id
INNER JOIN players p ON p.id = t.captain_id AND p.tg_user_id IS NULL
SET t.captain_id = x.new_cap;

-- 3) Empty teams (only member was a placeholder, or already empty) → delete.
DELETE FROM teams
WHERE NOT EXISTS (
	SELECT 1 FROM team_members tm
	WHERE tm.team_id = teams.id AND tm.tournament_id = teams.tournament_id
);

-- 4) Ratings authored by a placeholder but belonging to a real player → re-attribute.
UPDATE balance_ratings
SET updated_by = player_id
WHERE updated_by IN (SELECT id FROM (SELECT id FROM players WHERE tg_user_id IS NULL) AS ph);

-- 5) IGN history authored by a placeholder.
UPDATE player_ign_history
SET changed_by = NULL
WHERE changed_by IN (SELECT id FROM (SELECT id FROM players WHERE tg_user_id IS NULL) AS ph);

-- 6) Remove placeholder rows that are safe to delete.
DELETE FROM players
WHERE tg_user_id IS NULL
	AND id NOT IN (SELECT organizer_id FROM tournaments)
	AND id NOT IN (SELECT owner_id FROM balances);
