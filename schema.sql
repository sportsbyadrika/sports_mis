-- Schema for the Sports MIS application

CREATE TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    location VARCHAR(150),
    start_date DATE,
    end_date DATE,
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
    status ENUM('draft', 'submitted') NOT NULL DEFAULT 'draft',
    created_by INT,
    submitted_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    submitted_at TIMESTAMP NULL,
    CONSTRAINT fk_participant_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_participant_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default super administrator (password: admin123)
INSERT INTO users (name, email, password_hash, role)
VALUES ('Super Admin', 'admin@sportsmis.test', '$2y$12$trU5SxG4m7i1INbCwSkUrOeIjll/RGdg6o/P4qNJDiGqwy4D1ew8O', 'super_admin');
