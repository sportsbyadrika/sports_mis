CREATE TABLE result_master_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    result_key VARCHAR(50) NOT NULL,
    result_label VARCHAR(150) NOT NULL,
    individual_points DECIMAL(10,2) NOT NULL DEFAULT 0,
    team_points DECIMAL(10,2) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_result_master_event_key UNIQUE (event_id, result_key),
    CONSTRAINT fk_result_master_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
