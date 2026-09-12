-- Wochenplaner MySQL schema
-- Import with: mysql -u <user> -p <database> < schema.sql

CREATE TABLE IF NOT EXISTS weeks (
  week_start DATE NOT NULL PRIMARY KEY,
  closed TINYINT(1) NOT NULL DEFAULT 0,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per day slot (7 days x 8 fixed slots per week).
CREATE TABLE IF NOT EXISTS day_slots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  week_start DATE NOT NULL,
  day_name VARCHAR(20) NOT NULL,
  slot_index TINYINT UNSIGNED NOT NULL,
  text TEXT NOT NULL,
  item_date DATE NULL,
  item_time TIME NULL,
  done TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_slot (week_start, day_name, slot_index),
  CONSTRAINT fk_day_slots_week FOREIGN KEY (week_start)
    REFERENCES weeks(week_start) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Variable-length note items (Diverses / Kaufen / Ausblick), ordered by `position`.
CREATE TABLE IF NOT EXISTS note_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  week_start DATE NOT NULL,
  section VARCHAR(20) NOT NULL,
  position INT UNSIGNED NOT NULL,
  text TEXT NOT NULL,
  item_date DATE NULL,
  item_time TIME NULL,
  done TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_week_section (week_start, section, position),
  CONSTRAINT fk_note_items_week FOREIGN KEY (week_start)
    REFERENCES weeks(week_start) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit trail for rejected optimistic-locking conflicts.
CREATE TABLE IF NOT EXISTS week_conflicts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  week_start DATE NOT NULL,
  base_revision INT UNSIGNED NOT NULL,
  server_revision INT UNSIGNED NOT NULL,
  payload LONGTEXT NOT NULL,
  detected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_week (week_start, detected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
