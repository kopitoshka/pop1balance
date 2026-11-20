-- Team letters: every team of a tournament may carry one letter A–Z,
-- unique per tournament (NULL = none yet / more than 26 teams).
-- New teams get the first free letter in PHP; the organizer may change it.
-- Existing teams are backfilled in their display order: registered teams in
-- queue order (registered_at, id), then forming teams by id.
-- The UPDATE works on MariaDB 10.2+ / MySQL 8 and SQLite 3.25+ (window
-- functions); the derived table is materialized, so MySQL accepts it.

ALTER TABLE teams ADD COLUMN letter CHAR(1) NULL CHECK (letter IS NULL OR (LENGTH(letter) = 1 AND letter BETWEEN 'A' AND 'Z'));
CREATE UNIQUE INDEX uq_team_letter ON teams (tournament_id, letter);

UPDATE teams SET letter = (
	SELECT CASE WHEN r.rn <= 26 THEN SUBSTR('ABCDEFGHIJKLMNOPQRSTUVWXYZ', r.rn, 1) END
	FROM (
		SELECT id, ROW_NUMBER() OVER (
			PARTITION BY tournament_id
			ORDER BY CASE WHEN status = 'registered' THEN 0 ELSE 1 END, registered_at, id
		) AS rn
		FROM teams
	) r
	WHERE r.id = teams.id
) WHERE letter IS NULL;
