-- Team size may now be 1..12 (was 2..4). Replaces chk_tournaments_team_size.
-- MariaDB 10.2+ and MySQL 8.0.16+ enforce CHECK and list it in
-- information_schema.TABLE_CONSTRAINTS; older MySQL ignores CHECK, so the
-- old constraint is dropped only when it exists (idempotent, like 010).
-- MariaDB drops it with DROP CONSTRAINT, MySQL 8.0.16+ with DROP CHECK.

SET @db := DATABASE();

SET @exists := (
	SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
	WHERE CONSTRAINT_SCHEMA = @db
		AND TABLE_NAME = 'tournaments'
		AND CONSTRAINT_NAME = 'chk_tournaments_team_size'
);
SET @drop := IF(VERSION() LIKE '%MariaDB%',
	'ALTER TABLE tournaments DROP CONSTRAINT chk_tournaments_team_size',
	'ALTER TABLE tournaments DROP CHECK chk_tournaments_team_size');
SET @sql := IF(@exists > 0,
	@drop,
	'SELECT ''chk_tournaments_team_size not found'' AS info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE tournaments
	ADD CONSTRAINT chk_tournaments_team_size CHECK (team_size BETWEEN 1 AND 12);
