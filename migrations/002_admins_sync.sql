-- When chat admins were last fetched with getChatAdministrators (cache in the admins table).
ALTER TABLE chats ADD COLUMN admins_synced_at DATETIME NULL AFTER is_active;
