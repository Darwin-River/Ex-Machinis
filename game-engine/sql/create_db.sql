-- MySQL Script generated by MySQL Workbench
-- Mon Apr 15 07:01:20 2019
-- Model: New Model    Version: 1.0
-- MySQL Workbench Forward Engineering

SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='TRADITIONAL,ALLOW_INVALID_DATES';

-- -----------------------------------------------------
-- Schema exmachinis
-- -----------------------------------------------------

-- -----------------------------------------------------
-- Schema exmachinis
-- -----------------------------------------------------
CREATE SCHEMA IF NOT EXISTS `exmachinis` DEFAULT CHARACTER SET utf8 ;
USE `exmachinis` ;

-- -----------------------------------------------------
-- Table `exmachinis`.`users`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `exmachinis`.`users` ;

CREATE TABLE IF NOT EXISTS `exmachinis`.`users` (
  `user_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password` VARCHAR(255) NULL DEFAULT NULL,
  `remember_token` VARCHAR(100) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  `credits` INT(10) UNSIGNED NULL COMMENT 'This is the amount of credits the player owns.',
  PRIMARY KEY (`user_id`),
  UNIQUE INDEX `users_email_unique` (`email` ASC))
ENGINE = InnoDB
AUTO_INCREMENT = 1
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `exmachinis`.`agents`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `exmachinis`.`agents` ;

CREATE TABLE IF NOT EXISTS `exmachinis`.`agents` (
  `agent_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `vm` MEDIUMBLOB NULL DEFAULT NULL,
  `input` LONGTEXT NULL DEFAULT NULL,
  `output` LONGTEXT NULL DEFAULT NULL,
  `subject` VARCHAR(2048) NULL DEFAULT NULL,
  `object_id` INT(11) NOT NULL DEFAULT '187' COMMENT 'This is the ID of the static obect where the drone is located.',
  `hull_type` INT(2) UNSIGNED NULL COMMENT 'This is the ID of the drone\'s hull type.',
  PRIMARY KEY (`agent_id`),
  INDEX `fk_user_id_idx` (`user_id` ASC),
  KEY `agent_name_INDEX` (`name`),
  KEY `fk_object_id_idx` (`object_id`),
  KEY `fk_hull_type_idx` (`hull_type`),
  CONSTRAINT `fk_user_id`
    FOREIGN KEY (`user_id`)
    REFERENCES `exmachinis`.`users` (`user_id`)
    ON DELETE CASCADE
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_hull_type` FOREIGN KEY (`hull_type`) REFERENCES `hulls` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `fk_object_id` FOREIGN KEY (`object_id`) REFERENCES `objects` (`object_id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE = InnoDB
AUTO_INCREMENT = 1
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `exmachinis`.`commands`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `exmachinis`.`commands` ;

CREATE TABLE IF NOT EXISTS `exmachinis`.`commands` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(2048) NOT NULL,
  `agent_id` INT(10) UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  `subject` VARCHAR(2048) NULL DEFAULT NULL,
  `email_content` LONGTEXT NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `commands_agent_id_foreign` (`agent_id` ASC),
  CONSTRAINT `commands_agent_id_foreign`
    FOREIGN KEY (`agent_id`)
    REFERENCES `exmachinis`.`agents` (`agent_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE)
ENGINE = InnoDB
AUTO_INCREMENT = 1
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `exmachinis`.`migrations`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `exmachinis`.`migrations` ;

CREATE TABLE IF NOT EXISTS `exmachinis`.`migrations` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` VARCHAR(255) NOT NULL,
  `batch` INT(11) NOT NULL,
  PRIMARY KEY (`id`))
ENGINE = InnoDB
AUTO_INCREMENT = 1
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `exmachinis`.`objects`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `exmachinis`.`objects` ;

CREATE TABLE IF NOT EXISTS `exmachinis`.`objects` (
  `object_id` INT(11) NOT NULL,
  `object_name` VARCHAR(45) NOT NULL,
  `object_type` VARCHAR(16) NOT NULL,
  `gravitational_parameter` DOUBLE NULL DEFAULT NULL,
  `central_body_object_id` INT(11) NULL DEFAULT NULL,
  `epoch` DATETIME NULL DEFAULT NULL,
  `semimajor_axis` DOUBLE NULL DEFAULT NULL,
  `eccentricity` DECIMAL(10,9) NULL DEFAULT NULL,
  `periapsis_argument` DECIMAL(12,9) NULL DEFAULT NULL,
  `mean_anomaly` DECIMAL(12,9) NULL DEFAULT NULL,
  `inclination` DECIMAL(12,9) NULL DEFAULT NULL,
  `ascending_node_longitude` DECIMAL(12,9) NULL DEFAULT NULL,
  `mean_angular_motion` DECIMAL(13,9) NULL DEFAULT NULL,
  `computed_on` DATETIME NULL,
  `x_coord` DOUBLE NULL,
  `y_coord` DOUBLE NULL,
  `z_coord` DOUBLE NULL,
  PRIMARY KEY (`object_id`),
  UNIQUE INDEX `object_id_UNIQUE` (`object_id` ASC),
  UNIQUE INDEX `object_name_UNIQUE` (`object_name` ASC),
  KEY `object_name_INDEX` (`object_name`),
  KEY `object_type_INDEX` (`object_type`),
  KEY `object_central_body_object_id_INDEX` (`central_body_object_id`),
  CONSTRAINT `central_body_object_id_objects_id_FOREIGN_KEY` FOREIGN KEY (`central_body_object_id`) REFERENCES `objects` (`object_id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `exmachinis`.`password_resets`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `exmachinis`.`password_resets` ;

CREATE TABLE IF NOT EXISTS `exmachinis`.`password_resets` (
  `email` VARCHAR(255) NOT NULL,
  `token` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  INDEX `password_resets_email_index` (`email` ASC))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `exmachinis`.`hulls`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `exmachinis`.`hulls` ;

CREATE TABLE IF NOT EXISTS `exmachinis`.`hulls` (
  `id` INT(2) UNSIGNED NOT NULL COMMENT 'Table index',
  `name` VARCHAR(45) NULL COMMENT 'This is the name of the hull',
  `description` VARCHAR(255) NULL COMMENT 'This is a brief description of the hull design.',
  `cargo_capacity` INT(10) UNSIGNED NULL COMMENT 'This the amount of materials that can be held in the ship\'s hold.',
  `a_slots` INT(2) UNSIGNED NULL,
  `b_slots` INT(2) UNSIGNED NULL,
  `c_slots` INT(2) UNSIGNED NULL,
  `d_slots` INT(2) UNSIGNED NULL,
  `e_slots` INT(2) UNSIGNED NULL,
  PRIMARY KEY (`id`))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `exmachinis`.`hull_bonuses`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `exmachinis`.`hull_bonuses` ;

CREATE TABLE IF NOT EXISTS `exmachinis`.`hull_bonuses` (
  `id` INT(5) UNSIGNED NOT NULL,
  `hull_type` INT(2) UNSIGNED NULL,
  `affected_resource` INT(5) UNSIGNED NULL,
  `multiplier` INT(2) UNSIGNED NULL,
  PRIMARY KEY (`id`))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `exmachinis`.`resources`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `exmachinis`.`resources` ;

CREATE TABLE IF NOT EXISTS `exmachinis`.`resources` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(45) NULL,
  `description` LONGTEXT NULL,
  `mass` INT(10) UNSIGNED NULL,
  `capacity` INT(2) UNSIGNED NULL,
  `slot_size` INT(1) UNSIGNED NULL,
  PRIMARY KEY (`id`))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `exmachinis`.`actions`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `exmachinis`.`actions` ;

CREATE TABLE IF NOT EXISTS `exmachinis`.`actions` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `drone` INT(10) UNSIGNED NULL,
  `protocol` INT(10) UNSIGNED NULL,
  `multiplier` INT(10) UNSIGNED NULL,
  `aborted` TINYINT UNSIGNED NULL DEFAULT 0,
  `actionscol` VARCHAR(45) NULL,
  `timestamp` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `id_UNIQUE` (`id` ASC),
  INDEX `timestamp_idx` (`timestamp` ASC))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `exmachinis`.`events`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `exmachinis`.`events` ;

CREATE TABLE IF NOT EXISTS `exmachinis`.`events` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type` INT(2) UNSIGNED NULL,
  `action` INT(10) UNSIGNED NULL,
  `logged` TINYINT NULL,
  `outcome` INT(2) NULL,
  `drone` INT(10) UNSIGNED NULL,
  `resource` INT(10) UNSIGNED NULL,
  `installed` TINYINT NULL,
  `locked` TINYINT NULL,
  `new_quantity` INT(10) NULL,
  `new_credits` INT(10) NULL,
  `new_location` INT(10) NULL,
  `new_transmission` INT(10) NULL,
  `new_cargo` INT(10) NULL,
  `timestamp` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `id_UNIQUE` (`id` ASC),
  INDEX `timestamp_idx` (`timestamp` ASC),
  INDEX `action_idx` (`action` ASC),
  INDEX `drone_resource_idx` (`drone` ASC, `resource` ASC),
    CONSTRAINT `fk_agent_id` FOREIGN KEY (`drone`) REFERENCES `agents` (`agent_id`) ON DELETE CASCADE ON UPDATE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `exmachinis`.`transmissions`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `exmachinis`.`transmissions` ;

CREATE TABLE IF NOT EXISTS `exmachinis`.`transmissions` (
  `id` INT(10) UNSIGNED NOT NULL,
  `content` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `id_UNIQUE` (`id` ASC))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `exmachinis`.`observations`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `exmachinis`.`observations` ;

CREATE TABLE IF NOT EXISTS `exmachinis`.`observations` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `drone` INT(10) UNSIGNED NULL,
  `event` INT(10) UNSIGNED NULL,
  `time` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `id_UNIQUE` (`id` ASC),
  KEY `event` (`event`),
  CONSTRAINT `event`
    FOREIGN KEY (`event`)
    REFERENCES `exmachinis`.`events` (`id`)
    ON DELETE CASCADE
    ON UPDATE NO ACTION)
ENGINE = InnoDB
AUTO_INCREMENT = 1
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Table `exmachinis`.`queries`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `exmachinis`.`queries` ;

CREATE TABLE IF NOT EXISTS `exmachinis`.`queries` (
  `id` INT(5) UNSIGNED NOT NULL,
  `name` VARCHAR(45) NOT NULL,
  `description` VARCHAR(255) NULL,
  `parameters` INT(1) NOT NULL,
  `script` VARCHAR(512) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `id_UNIQUE` (`id` ASC))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `exmachinis`.`protocols`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `exmachinis`.`protocols` ;

CREATE TABLE IF NOT EXISTS `exmachinis`.`protocols` (
  `id` INT(5) UNSIGNED NOT NULL,
  `name` VARCHAR(45) NULL,
  `parameters` SMALLINT(2) NOT NULL DEFAULT 0,
  `bulk_modifier` INT(5) UNSIGNED NULL,
  `description` VARCHAR(255) NULL,
  `observable` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `reportable` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `multiplier` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `id_UNIQUE` (`id` ASC))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `exmachinis`.`event_types`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `exmachinis`.`event_types` ;

CREATE TABLE IF NOT EXISTS `exmachinis`.`event_types` (
  `id` INT(2) UNSIGNED NOT NULL,
  `name` VARCHAR(45) NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `id_UNIQUE` (`id` ASC))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `exmachinis`.`resource_effects`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `exmachinis`.`resource_effects` ;

CREATE TABLE IF NOT EXISTS `exmachinis`.`resource_effects` (
  `id` INT(5) UNSIGNED NOT NULL,
  `drone` INT(5) SIGNED NULL,
  `resource` INT(5) SIGNED NULL,
  `protocol` INT(5) UNSIGNED NULL,
  `event_type` INT(2) UNSIGNED NULL,
  `local` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `installed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `locked` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `deplete` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `abundancies` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `quantity` INT(5) SIGNED NULL,
  `time` INT(5) UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `id_UNIQUE` (`id` ASC),
  INDEX `protocol_idx` (`protocol` ASC))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `exmachinis`.`market_effects`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `exmachinis`.`market_effects` ;

CREATE TABLE IF NOT EXISTS `exmachinis`.`market_effects` (
  `id` INT(5) UNSIGNED NOT NULL,
  `protocol` INT(5) UNSIGNED NOT NULL,
  `event_type` INT(2) UNSIGNED NOT NULL,
  `resource` INT(5) NOT NULL DEFAULT -1,
  `upper_limit` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `quantity` INT(5) NOT NULL DEFAULT -1,
  `price` INT(5) NOT NULL DEFAULT -1,
  `time` INT(5) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `id_UNIQUE` (`id` ASC),
  INDEX `protocol_idx` (`protocol` ASC))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `exmachinis`.`location_effects`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `exmachinis`.`location_effects` ;

CREATE TABLE IF NOT EXISTS `exmachinis`.`location_effects` (
  `id` INT(5) UNSIGNED NOT NULL,
  `protocol` INT(5) UNSIGNED NULL,
  `event_type` INT(2) UNSIGNED NULL,
  `location` INT(5) NULL,
  `time` INT(5) UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `id_UNIQUE` (`id` ASC)
  INDEX `protocol_idx` (`protocol` ASC))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- Table `exmachinis`.`abundancies`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `exmachinis`.`abundancies` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `location` INT(5) NOT NULL,
  `resource` INT(10) UNSIGNED NULL,
  `multiplier` INT(2) NULL,
  PRIMARY KEY (`id`),
  INDEX `location_id_foreign_idx` (`location` ASC),
  INDEX `resource_id_foreign_idx` (`resource` ASC),
  INDEX `location_resource_idx` (`location` ASC, `resource` ASC),
  CONSTRAINT `location_id_foreign`
    FOREIGN KEY (`location`)
    REFERENCES `exmachinis`.`objects` (`object_id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `resource_id_foreign`
    FOREIGN KEY (`resource`)
    REFERENCES `exmachinis`.`resources` (`id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4
COLLATE = utf8mb4_unicode_ci;

SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
