-- Team strength can exceed 255 once pair bonuses are included
-- (a team of 12 at level 6 is 270). Widen max_points from TINYINT to SMALLINT.

ALTER TABLE tournaments MODIFY max_points SMALLINT UNSIGNED NULL;
