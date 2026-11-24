CREATE TABLE `users`(
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `active` BOOLEAN NOT NULL DEFAULT 1,
    `admin` BOOLEAN NOT NULL DEFAULT 0
);
ALTER TABLE
    `users` ADD UNIQUE `users_username_unique`(`username`);
ALTER TABLE
    `users` ADD UNIQUE `users_email_unique`(`email`);
CREATE TABLE `publication`(
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `image` VARCHAR(255) NULL,
    `title` VARCHAR(255) NOT NULL,
    `text` TEXT NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `like`(
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `publication_id` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `comment`(
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `publication_id` BIGINT UNSIGNED NOT NULL,
    `deleted` BOOLEAN NOT NULL DEFAULT 0,
    `text` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE
    `publication` ADD CONSTRAINT `publication_user_id_foreign` FOREIGN KEY(`user_id`) REFERENCES `users`(`id`);
ALTER TABLE
    `comment` ADD CONSTRAINT `comment_publication_id_foreign` FOREIGN KEY(`publication_id`) REFERENCES `publication`(`id`) ON DELETE CASCADE;
ALTER TABLE
    `like` ADD CONSTRAINT `like_user_id_foreign` FOREIGN KEY(`user_id`) REFERENCES `users`(`id`);
ALTER TABLE
    `comment` ADD CONSTRAINT `comment_user_id_foreign` FOREIGN KEY(`user_id`) REFERENCES `users`(`id`);
ALTER TABLE
    `like` ADD CONSTRAINT `like_publication_id_foreign` FOREIGN KEY(`publication_id`) REFERENCES `publication`(`id`) ON DELETE CASCADE;ALTER TABLE
    `like` ADD UNIQUE `like_user_publication_unique`(`user_id`, `publication_id`);
ALTER TABLE
    `publication` ADD FULLTEXT `publication_search`(`title`, `text`);
CREATE TABLE `category`(
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL
);
ALTER TABLE
    `publication` ADD `category_id` BIGINT UNSIGNED NOT NULL,
    ADD `type` ENUM('question', 'discussion', 'announcement') NOT NULL DEFAULT 'discussion',
    ADD `allow_comments` BOOLEAN NOT NULL DEFAULT 1;
ALTER TABLE
    `publication` ADD CONSTRAINT `publication_category_id_foreign` FOREIGN KEY(`category_id`) REFERENCES `category`(`id`);
CREATE TABLE `password_reset`(
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL
);
ALTER TABLE
    `password_reset` ADD UNIQUE `password_reset_token_hash_unique`(`token_hash`);
ALTER TABLE
    `password_reset` ADD CONSTRAINT `password_reset_user_id_foreign` FOREIGN KEY(`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;
