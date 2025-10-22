-- Schema for the Sports MIS application

CREATE TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    location VARCHAR(150),
    start_date DATE,
    end_date DATE,
    bank_account_number VARCHAR(60),
    bank_ifsc VARCHAR(20),
    bank_name VARCHAR(150),
    payment_qr_path VARCHAR(255),
    receipt_signature_path VARCHAR(255),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE institutions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    spoc_name VARCHAR(150) NOT NULL,
    designation VARCHAR(150),
    contact_number VARCHAR(50),

    affiliation_number VARCHAR(100),

    address TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_institution_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(180) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'event_admin', 'institution_admin', 'event_staff') NOT NULL,
    contact_number VARCHAR(50),
    event_id INT DEFAULT NULL,
    institution_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL,
    CONSTRAINT fk_user_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE age_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    min_age TINYINT UNSIGNED DEFAULT NULL,
    max_age TINYINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    age_category_id INT NOT NULL,
    code VARCHAR(60) NOT NULL,
    name VARCHAR(180) NOT NULL,
    gender ENUM('Male', 'Female', 'Open') NOT NULL DEFAULT 'Open',
    event_type ENUM('Individual', 'Team', 'Institution') NOT NULL,
    fees DECIMAL(10,2) NOT NULL DEFAULT 0,
    label VARCHAR(180),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_event_master_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_master_age FOREIGN KEY (age_category_id) REFERENCES age_categories(id) ON DELETE RESTRICT,
    CONSTRAINT uq_event_master_code UNIQUE (event_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    institution_id INT NOT NULL,
    event_id INT NOT NULL,
    name VARCHAR(180) NOT NULL,
    date_of_birth DATE NOT NULL,
    gender ENUM('Male', 'Female') NOT NULL,
    guardian_name VARCHAR(180) NOT NULL,
    contact_number VARCHAR(50) NOT NULL,
    address TEXT,
    email VARCHAR(180),
    aadhaar_number VARCHAR(20) NOT NULL,
    photo_path VARCHAR(255) NOT NULL,
    status ENUM('draft', 'submitted', 'approved', 'rejected') NOT NULL DEFAULT 'draft',
    chest_number INT UNIQUE,
    created_by INT,
    submitted_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    submitted_at TIMESTAMP NULL,
    CONSTRAINT fk_participant_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_participant_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE participant_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NOT NULL,
    event_master_id INT NOT NULL,
    institution_id INT NOT NULL,
    fees DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_participant_event UNIQUE (participant_id, event_master_id),
    CONSTRAINT fk_participant_events_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
    CONSTRAINT fk_participant_events_event FOREIGN KEY (event_master_id) REFERENCES event_master(id) ON DELETE CASCADE,
    CONSTRAINT fk_participant_events_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE team_entries (
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

CREATE TABLE team_entry_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_entry_id INT NOT NULL,
    participant_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_team_entry_member UNIQUE (team_entry_id, participant_id),
    CONSTRAINT fk_team_entry_members_entry FOREIGN KEY (team_entry_id) REFERENCES team_entries(id) ON DELETE CASCADE,
    CONSTRAINT fk_team_entry_members_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE institution_event_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    institution_id INT NOT NULL,
    event_master_id INT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    submitted_by INT DEFAULT NULL,
    reviewed_by INT DEFAULT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_institution_event_registration UNIQUE (institution_id, event_master_id),
    CONSTRAINT fk_institution_registrations_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_institution_registrations_event FOREIGN KEY (event_master_id) REFERENCES event_master(id) ON DELETE CASCADE,
    CONSTRAINT fk_institution_registrations_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_institution_registrations_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fund_transfers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    institution_id INT NOT NULL,
    submitted_by INT NOT NULL,
    transfer_date DATE NOT NULL,
    mode ENUM('NEFT', 'UPI', 'Other') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    transaction_number VARCHAR(120) NOT NULL,
    reference_document_path VARCHAR(255) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    remarks TEXT,
    reviewed_by INT DEFAULT NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_fund_transfers_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_fund_transfers_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_fund_transfers_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_fund_transfers_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_news (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    url VARCHAR(255) DEFAULT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'inactive',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_event_news_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_news_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_team_event_result UNIQUE (event_master_id, team_entry_id),
    CONSTRAINT fk_team_event_result_event_master FOREIGN KEY (event_master_id) REFERENCES event_master(id) ON DELETE CASCADE,
    CONSTRAINT fk_team_event_result_team_entry FOREIGN KEY (team_entry_id) REFERENCES team_entries(id) ON DELETE CASCADE,
    CONSTRAINT fk_team_event_result_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Default super administrator (password: admin123)
INSERT INTO users (name, email, password_hash, role)
VALUES ('Super Admin', 'admin@sportsmis.test', '$2y$12$trU5SxG4m7i1INbCwSkUrOeIjll/RGdg6o/P4qNJDiGqwy4D1ew8O', 'super_admin');
