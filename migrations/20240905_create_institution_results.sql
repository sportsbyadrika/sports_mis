CREATE TABLE institution_event_result_statuses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_master_id INT NOT NULL UNIQUE,
    status ENUM('pending', 'entry', 'published') NOT NULL DEFAULT 'pending',
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_institution_result_status_event_master FOREIGN KEY (event_master_id) REFERENCES event_master(id) ON DELETE CASCADE,
    CONSTRAINT fk_institution_result_status_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE institution_event_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_master_id INT NOT NULL,
    institution_id INT NOT NULL,
    result ENUM('participant', 'first_place', 'second_place', 'third_place') NOT NULL DEFAULT 'participant',
    score VARCHAR(100) DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_institution_event_result UNIQUE (event_master_id, institution_id),
    CONSTRAINT fk_institution_event_result_event_master FOREIGN KEY (event_master_id) REFERENCES event_master(id) ON DELETE CASCADE,
    CONSTRAINT fk_institution_event_result_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_institution_event_result_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
