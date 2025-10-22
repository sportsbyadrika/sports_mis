CREATE TABLE team_event_result_statuses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_master_id INT NOT NULL UNIQUE,
    status ENUM('pending', 'entry', 'published') NOT NULL DEFAULT 'pending',
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_team_result_status_event_master FOREIGN KEY (event_master_id) REFERENCES event_master(id) ON DELETE CASCADE,
    CONSTRAINT fk_team_result_status_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE team_event_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_master_id INT NOT NULL,
    team_entry_id INT NOT NULL,
    result ENUM('participant', 'first_place', 'second_place', 'third_place', 'fourth_place', 'fifth_place', 'sixth_place', 'seventh_place', 'eighth_place', 'absent', 'withheld') NOT NULL DEFAULT 'participant',
    team_points DECIMAL(10,2) NOT NULL DEFAULT 0,
    team_score VARCHAR(255) DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_team_event_result UNIQUE (event_master_id, team_entry_id),
    CONSTRAINT fk_team_event_result_event_master FOREIGN KEY (event_master_id) REFERENCES event_master(id) ON DELETE CASCADE,
    CONSTRAINT fk_team_event_result_team_entry FOREIGN KEY (team_entry_id) REFERENCES team_entries(id) ON DELETE CASCADE,
    CONSTRAINT fk_team_event_result_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
