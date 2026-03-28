-- Migration 122: Add document_tokens table for secure file download links
-- Used to generate expiring, token-based download URLs for email attachments
-- (replacing direct file attachments in emails)

CREATE TABLE IF NOT EXISTS document_tokens (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    token         VARCHAR(64)  NOT NULL UNIQUE COMMENT 'Secure random hex token',
    file_path     VARCHAR(1000) NOT NULL COMMENT 'Absolute or relative path to the file',
    file_name     VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Suggested download filename',
    type          VARCHAR(50)  NOT NULL DEFAULT '' COMMENT 'Document type (dpe, quittance, etat_lieux, inventaire, bilan)',
    expires_at    DATETIME     NOT NULL COMMENT 'Token expiry (typically 30 days)',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_token (token),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
