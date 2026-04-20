CREATE TABLE IF NOT EXISTS division_tracking_slip_user_order (
  division_id INT NOT NULL,
  user_id INT NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 999,
  updated_by_user_id INT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (division_id, user_id),
  KEY idx_division_tracking_slip_order_sort (division_id, sort_order),
  CONSTRAINT fk_division_tracking_slip_order_division
    FOREIGN KEY (division_id) REFERENCES divisions(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_division_tracking_slip_order_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_division_tracking_slip_order_updated_by
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
