-- Online Exam Monitoring System Database Schema
-- Drop database if exists to ensure clean state
DROP DATABASE IF EXISTS exam_monitoring_system;
CREATE DATABASE exam_monitoring_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE exam_monitoring_system;

-- Users table with consistent ID naming
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Questions table (unchanged structure)
CREATE TABLE questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_text TEXT NOT NULL,
    option_a VARCHAR(255) NOT NULL,
    option_b VARCHAR(255) NOT NULL,
    option_c VARCHAR(255) NOT NULL,
    option_d VARCHAR(255) NOT NULL,
    correct_option ENUM('a', 'b', 'c', 'd') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Exam Sessions table
CREATE TABLE exam_sessions (
    session_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL DEFAULT NULL,
    ended_reason ENUM('completed', 'terminated', 'timeout', 'cheated') NULL,
    score INT NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_user_session (user_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Exam Violations table
CREATE TABLE exam_violations (
    violation_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    user_id INT NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    event_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    details TEXT NULL,
    FOREIGN KEY (session_id) REFERENCES exam_sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_session_user (session_id, user_id),
    INDEX idx_event_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Results table (updated foreign key reference)
CREATE TABLE results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    score INT NOT NULL DEFAULT 0,
    total_questions INT NOT NULL DEFAULT 0,
    time_taken INT NOT NULL DEFAULT 0,
    status ENUM('completed', 'cheated') NOT NULL DEFAULT 'completed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_user_results (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert sample questions
INSERT INTO questions (question_text, option_a, option_b, option_c, option_d, correct_option) VALUES
('What does HTML stand for?', 'HyperText Markup Language', 'High Tech Modern Language', 'Home Tool Markup Language', 'Hyperlink and Text Markup Language', 'a'),
('Which CSS property is used to change the text color?', 'font-color', 'text-color', 'color', 'text-style', 'c'),
('What is the correct way to declare a JavaScript variable?', 'var name = "John";', 'variable name = "John";', 'v name = "John";', 'var = "John";', 'a'),
('Which method is used to add an element to the end of an array in JavaScript?', 'push()', 'append()', 'add()', 'insert()', 'a'),
('What does PHP stand for?', 'Personal Home Page', 'PHP: Hypertext Preprocessor', 'Private Home Page', 'Programmed Hypertext Processor', 'b'),
('Which SQL statement is used to extract data from a database?', 'EXTRACT', 'SELECT', 'GET', 'OPEN', 'b'),
('What is the default method for HTML forms?', 'GET', 'POST', 'PUT', 'DELETE', 'a'),
('Which HTML tag is used to define an internal stylesheet?', '<style>', '<css>', '<script>', '<link>', 'a'),
('What is the correct syntax for referring to an external script called "script.js"?', '<script href="script.js">', '<script name="script.js">', '<script src="script.js">', '<script file="script.js">', 'c'),
('Which property is used to change the background color?', 'bgcolor', 'background-color', 'color-background', 'background', 'b');

