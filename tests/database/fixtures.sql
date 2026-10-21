-- Test fixtures, loaded after game-engine/sql/create_db.sql and
-- game-engine/db-backup/exmachinis_tables.sql. Test data only.

USE `exmachinis`;

-- Stand-in for `sqInventory`, used by the buy/sell order searches.
-- On the old live server this was a view whose definition is not in
-- this repo (it only survives inside ms-access/ms_access_client.accdb).
-- The columns below are the ones the website queries use.
CREATE TABLE IF NOT EXISTS `sqInventory` (
  `drone` INT UNSIGNED NOT NULL,
  `resource` INT UNSIGNED NOT NULL,
  `units` INT NULL,
  PRIMARY KEY (`drone`, `resource`)
) ENGINE = InnoDB;

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

INSERT INTO `sqInventory` (`drone`, `resource`, `units`)
  VALUES (1, 2, 20);
