ALTER TABLE events
    ADD COLUMN bank_account_number VARCHAR(60) NULL AFTER end_date,
    ADD COLUMN bank_ifsc VARCHAR(20) NULL AFTER bank_account_number,
    ADD COLUMN bank_name VARCHAR(150) NULL AFTER bank_ifsc,
    ADD COLUMN payment_qr_path VARCHAR(255) NULL AFTER bank_name;
