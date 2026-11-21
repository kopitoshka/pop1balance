-- Soft delete of a player (owner's player card in the Mini App). A deleted player keeps the row
-- (past teams, tournaments and ratings still point at it and show «удалённый игрок») but loses
-- tg id, @username and nick, so those are free again and the player is hidden from /players,
-- pickers and matching.
ALTER TABLE players ADD COLUMN deleted_at DATETIME NULL;
