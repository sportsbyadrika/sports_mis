CREATE TABLE individual_event_result_statuses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_master_id INT NOT NULL UNIQUE,
    status ENUM('pending', 'entry', 'published') NOT NULL DEFAULT 'pending',
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_individual_result_status_event_master FOREIGN KEY (event_master_id) REFERENCES event_master(id) ON DELETE CASCADE,
    CONSTRAINT fk_individual_result_status_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE individual_event_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_master_id INT NOT NULL,
    participant_id INT NOT NULL,
    result ENUM('participant', 'first_place', 'second_place', 'third_place', 'fourth_place', 'fifth_place', 'sixth_place', 'seventh_place', 'eighth_place', 'absent', 'withheld') NOT NULL DEFAULT 'participant',
    score VARCHAR(100) DEFAULT NULL,
    individual_points DECIMAL(10,2) NOT NULL DEFAULT 0,
    team_points DECIMAL(10,2) NOT NULL DEFAULT 0,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_individual_event_result UNIQUE (event_master_id, participant_id),
    CONSTRAINT fk_individual_result_event_master FOREIGN KEY (event_master_id) REFERENCES event_master(id) ON DELETE CASCADE,
    CONSTRAINT fk_individual_result_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
    CONSTRAINT fk_individual_result_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
