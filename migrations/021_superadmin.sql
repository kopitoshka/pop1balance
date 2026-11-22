-- Superadmin mode (/su). can_su = the player may switch superadmin mode on (given by the bot owner
-- with /su grant or on the player card). su_mode = the stored mode: 1 on, 0 off, NULL = the default
-- (on for the bot owner, off for everyone else). The bot owner always has the right (BOT_OWNER_TG_ID),
-- no row value needed. With the mode on a player gets every bot-owner ability.
ALTER TABLE players ADD COLUMN can_su TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE players ADD COLUMN su_mode TINYINT(1) NULL;
