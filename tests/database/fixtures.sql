-- Test fixtures, loaded after game-engine/sql/create_db.sql and
-- game-engine/db-backup/exmachinis_tables.sql. Test data only.

USE `exmachinis`;

-- One company (user) with one drone parked at Earth (object 3).
INSERT INTO `users` (`user_id`, `name`, `email`, `credits`)
  VALUES (1, 'Test Company', 'test@example.com', 1000);

INSERT INTO `agents` (`agent_id`, `user_id`, `name`, `object_id`, `hull_type`)
  VALUES (1, 1, 'Test Drone', 3, 1);

-- One open buy order (event type 5, resource 1) and one open sell order
-- (event type 4, resource 2). See app/EventType.php.
INSERT INTO `events` (`id`, `event_type`, `outcome`, `drone`, `resource`, `new_quantity`, `new_credits`, `timestamp`)
  VALUES (1, 5, 1, 1, 1, 10, 100, '2022-11-01 12:00:00'),
         (2, 4, 1, 1, 2, 5, 200, '2022-11-01 12:05:00');

-- Inventory history for the drone (read through the sqInventory view): it now
-- holds 20 of resource 2. Only the latest successful increment/decrement
-- event counts; the failed one and the move event are ignored.
INSERT INTO `events` (`id`, `event_type`, `outcome`, `drone`, `resource`, `new_quantity`, `timestamp`)
  VALUES (3, 1, 1, 1, 2, 12, '2022-10-30 10:00:00'),
         (4, 1, 1, 1, 2, 20, '2022-10-31 10:00:00'),
         (5, 2, 0, 1, 2, 99, '2022-10-31 11:00:00'),
         (6, 3, 1, 1, 2, 77, '2022-10-31 12:00:00');
