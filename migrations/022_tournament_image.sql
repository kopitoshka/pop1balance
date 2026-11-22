-- Tournament picture (optional). image_path is only the file name of the processed picture in
-- public/media (a random name, never the tournament id), e.g. 0f3a...c9.jpg. The public URL is
-- built from it at send time (MEDIA_BASE_URL, or derived from WEBHOOK_URL).
ALTER TABLE tournaments ADD COLUMN image_path VARCHAR(64) NULL;
