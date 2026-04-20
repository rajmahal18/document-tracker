CREATE TABLE IF NOT EXISTS working_calendar_settings (
  id TINYINT NOT NULL PRIMARY KEY,
  timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Manila',
  default_start_time TIME NOT NULL DEFAULT '08:00:00',
  default_end_time TIME NOT NULL DEFAULT '17:00:00',
  workdays VARCHAR(32) NOT NULL DEFAULT '1,2,3,4,5',
  updated_by_user_id INT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_working_calendar_settings_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO working_calendar_settings
  (id, timezone, default_start_time, default_end_time, workdays)
VALUES
  (1, 'Asia/Manila', '08:00:00', '17:00:00', '1,2,3,4,5');

CREATE TABLE IF NOT EXISTS working_calendar_exceptions (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  exception_date DATE NOT NULL,
  exception_type ENUM('non_working', 'special_holiday', 'regular_holiday', 'other_non_working', 'custom_hours', 'special_working') NOT NULL DEFAULT 'non_working',
  title VARCHAR(160) NOT NULL DEFAULT '',
  start_time TIME NULL,
  end_time TIME NULL,
  notes TEXT NULL,
  created_by_user_id INT NULL,
  updated_by_user_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_working_calendar_exception_date (exception_date),
  KEY idx_working_calendar_exception_type (exception_type),
  CONSTRAINT fk_working_calendar_exceptions_created_by
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_working_calendar_exceptions_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE working_calendar_exceptions
  MODIFY exception_type ENUM('non_working', 'special_holiday', 'regular_holiday', 'other_non_working', 'custom_hours', 'special_working') NOT NULL DEFAULT 'non_working';
