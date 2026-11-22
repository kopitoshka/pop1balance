-- Map (Classic, Evolving, Phoenix, custom) and an optional short description,
-- shown on the announcement and the card. NULL on tournaments created before this.
ALTER TABLE tournaments
	ADD COLUMN map VARCHAR(16) NULL,
	ADD COLUMN description VARCHAR(500) NULL;
