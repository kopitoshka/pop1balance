-- Remove legacy placeholder players (no Telegram id) that are not used in any registration.
-- Referenced placeholders are kept; diagnose with: php bin/diag.php --placeholders

DELETE FROM players
WHERE tg_user_id IS NULL
	AND id NOT IN (SELECT player_id FROM team_members)
	AND id NOT IN (SELECT player_id FROM solo_players)
	AND id NOT IN (SELECT captain_id FROM teams)
	AND id NOT IN (SELECT organizer_id FROM tournaments)
	AND id NOT IN (SELECT owner_id FROM balances)
	AND id NOT IN (SELECT player_id FROM admins);
