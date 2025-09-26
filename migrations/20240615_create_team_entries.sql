CREATE TABLE IF NOT EXISTS team_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    institution_id INT NOT NULL,
    event_master_id INT NOT NULL,
    team_name VARCHAR(180) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    submitted_by INT DEFAULT NULL,
    reviewed_by INT DEFAULT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_team_entries_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_team_entries_event_master FOREIGN KEY (event_master_id) REFERENCES event_master(id) ON DELETE CASCADE,
    CONSTRAINT fk_team_entries_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_team_entries_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS team_entry_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_entry_id INT NOT NULL,
    participant_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_team_entry_member UNIQUE (team_entry_id, participant_id),
    CONSTRAINT fk_team_entry_members_entry FOREIGN KEY (team_entry_id) REFERENCES team_entries(id) ON DELETE CASCADE,
    CONSTRAINT fk_team_entry_members_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
