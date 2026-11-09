-- Profile rating is the only source of truth for player levels.
-- Drop frozen level_snapshot from team_members and solo_players (idempotent).

SET @db := DATABASE();

SET @exists := (
	SELECT COUNT(*) FROM information_schema.COLUMNS
	WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'team_members' AND COLUMN_NAME = 'level_snapshot'
);
SET @sql := IF(@exists > 0,
	'ALTER TABLE team_members DROP COLUMN level_snapshot',
	'SELECT ''team_members.level_snapshot already dropped'' AS info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
	SELECT COUNT(*) FROM information_schema.COLUMNS
	WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'solo_players' AND COLUMN_NAME = 'level_snapshot'
);
SET @sql := IF(@exists > 0,
	'ALTER TABLE solo_players DROP COLUMN level_snapshot',
	'SELECT ''solo_players.level_snapshot already dropped'' AS info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
