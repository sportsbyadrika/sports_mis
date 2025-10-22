ALTER TABLE events
    ADD COLUMN receipt_signature_path VARCHAR(255) NULL AFTER payment_qr_path;
