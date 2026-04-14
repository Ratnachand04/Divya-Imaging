-- Idempotent migration for role architecture: developer -> platform_admin
-- Runs during first-time database initialization in Docker.

ALTER TABLE `users`
  MODIFY `role` enum('manager','receptionist','accountant','writer','superadmin','platform_admin') NOT NULL;

UPDATE `users`
SET `role` = 'platform_admin'
WHERE `role` = 'developer';
