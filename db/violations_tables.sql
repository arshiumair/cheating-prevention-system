-- Add exam_sessions and exam_violations tables
ALTER DATABASE exam_monitoring_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Exam Sessions table
CREATE TABLE IF NOT EXISTS exam_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL DEFAULT NULL,
    ended_reason ENUM('completed', 'terminated', 'timeout') NULL,
    score INT NULL DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Exam Violations table
CREATE TABLE IF NOT EXISTS exam_violations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_session_id INT NOT NULL,
    user_id INT NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    details TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_session_id) REFERENCES exam_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_session_user (exam_session_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;