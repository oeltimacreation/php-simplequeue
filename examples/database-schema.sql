-- Database Schema for PHP SimpleQueue
-- 
-- This file contains the SQL schema for the background_jobs table.
-- Supports MySQL 5.7+, MariaDB 10.2+, PostgreSQL 9.5+, and SQLite 3.

-- MySQL / MariaDB Schema
CREATE TABLE IF NOT EXISTS background_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue VARCHAR(255) NOT NULL DEFAULT 'default',
    type VARCHAR(255) NOT NULL,
    status ENUM('pending', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    payload JSON,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
    progress INT UNSIGNED DEFAULT NULL,
    progress_message VARCHAR(255) DEFAULT NULL,
    result JSON DEFAULT NULL,
    available_at DATETIME DEFAULT NULL,
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    locked_by VARCHAR(255) DEFAULT NULL,
    locked_at DATETIME DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    error_trace TEXT DEFAULT NULL,
    request_id VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    
    INDEX idx_queue_status (queue, status),
    INDEX idx_status_available (status, available_at),
    INDEX idx_locked_at (locked_at),
    INDEX idx_type (type),
    INDEX idx_request_id (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PostgreSQL Schema
-- CREATE TABLE IF NOT EXISTS background_jobs (
--     id BIGSERIAL PRIMARY KEY,
--     queue VARCHAR(255) NOT NULL DEFAULT 'default',
--     type VARCHAR(255) NOT NULL,
--     status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'completed', 'failed', 'cancelled')),
--     payload JSONB,
--     attempts INTEGER NOT NULL DEFAULT 0,
--     max_attempts INTEGER NOT NULL DEFAULT 3,
--     progress INTEGER DEFAULT NULL,
--     progress_message VARCHAR(255) DEFAULT NULL,
--     result JSONB DEFAULT NULL,
--     available_at TIMESTAMP DEFAULT NULL,
--     started_at TIMESTAMP DEFAULT NULL,
--     completed_at TIMESTAMP DEFAULT NULL,
--     locked_by VARCHAR(255) DEFAULT NULL,
--     locked_at TIMESTAMP DEFAULT NULL,
--     error_message TEXT DEFAULT NULL,
--     error_trace TEXT DEFAULT NULL,
--     request_id VARCHAR(255) DEFAULT NULL,
--     created_at TIMESTAMP NOT NULL,
--     updated_at TIMESTAMP NOT NULL
-- );
-- 
-- CREATE INDEX idx_queue_status ON background_jobs (queue, status);
-- CREATE INDEX idx_status_available ON background_jobs (status, available_at);
-- CREATE INDEX idx_locked_at ON background_jobs (locked_at);
-- CREATE INDEX idx_type ON background_jobs (type);
-- CREATE INDEX idx_request_id ON background_jobs (request_id);

-- SQLite Schema
-- CREATE TABLE IF NOT EXISTS background_jobs (
--     id INTEGER PRIMARY KEY AUTOINCREMENT,
--     queue TEXT NOT NULL DEFAULT 'default',
--     type TEXT NOT NULL,
--     status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'completed', 'failed', 'cancelled')),
--     payload TEXT,
--     attempts INTEGER NOT NULL DEFAULT 0,
--     max_attempts INTEGER NOT NULL DEFAULT 3,
--     progress INTEGER DEFAULT NULL,
--     progress_message TEXT DEFAULT NULL,
--     result TEXT DEFAULT NULL,
--     available_at TEXT DEFAULT NULL,
--     started_at TEXT DEFAULT NULL,
--     completed_at TEXT DEFAULT NULL,
--     locked_by TEXT DEFAULT NULL,
--     locked_at TEXT DEFAULT NULL,
--     error_message TEXT DEFAULT NULL,
--     error_trace TEXT DEFAULT NULL,
--     request_id TEXT DEFAULT NULL,
--     created_at TEXT NOT NULL,
--     updated_at TEXT NOT NULL
-- );
-- 
-- CREATE INDEX idx_queue_status ON background_jobs (queue, status);
-- CREATE INDEX idx_status_available ON background_jobs (status, available_at);
-- CREATE INDEX idx_locked_at ON background_jobs (locked_at);
