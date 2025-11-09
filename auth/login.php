<?php
/**
 * Login Page
 * Handles user authentication for the Online Exam Monitoring System
 * 
 * This file:
 * - Validates user credentials securely
 * - Uses prepared statements for SQL queries
 * - Sets session variables on successful login
 * - Provides clear error messages
 * - Redirects to appropriate pages based on login status
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Initialize variables
$error = '';
$email = '';

// Redirect if already logged in -> send to home page
if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get and sanitize inputs
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'] ?? '';

        // Validate required fields
        if (empty($email) || empty($password)) {
            $error = 'Both email and password are required.';
        } 
        // Validate email format
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } 
        else {
            // Prepare the SQL statement
            // Note: users table uses `user_id` as primary key. Alias it to `id` so rest of code can remain unchanged.
            $stmt = $conn->prepare("SELECT user_id AS id, name, email, password FROM users WHERE email = ?");
            if (!$stmt) {
                throw new Exception("Database error: Failed to prepare statement");
            }

            // Bind parameters and execute
            $stmt->bind_param("s", $email);
            if (!$stmt->execute()) {
                throw new Exception("Database error: Failed to execute query");
            }

            // Get the result
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();

                // Verify account status only if that column exists
                if (isset($user['status']) && $user['status'] === 'inactive') {
                    $error = 'Your account is inactive. Please contact the administrator.';
                }
                // Verify password
                elseif (password_verify($password, $user['password'])) {
                    // Start session if not already started
                    if (session_status() === PHP_SESSION_NONE) {
                        session_start();
                    }

                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['last_activity'] = time();

                    // Log successful login (non-fatal). If table doesn't exist, don't break login.
                    try {
                        $logStmt = $conn->prepare("INSERT INTO login_logs (user_id, ip_address, status) VALUES (?, ?, 'success')");
                        if ($logStmt) {
                            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                            $logStmt->bind_param("is", $user['id'], $ip);
                            $logStmt->execute();
                            $logStmt->close();
                        }
                    } catch (Exception $e) {
                        error_log('Login log failed: ' . $e->getMessage());
                    }

                    // Clean up and redirect to home page
                    $stmt->close();
                    header('Location: ' . BASE_URL . '/index.php');
                    exit();
                } else {
                    // Invalid password
                    $error = 'Invalid email or password.';
                }
            } else {
                // Email not found
                $error = 'Invalid email or password.';
            }

            // Close the statement
            $stmt->close();
        }
    } catch (Exception $e) {
        // Log the actual error for administrators
        error_log("Login error: " . $e->getMessage());
        
        // Show a generic error to users
        $error = 'An unexpected error occurred. Please try again later.';
    }
}

// Set page title and include header
$page_title = 'Login - Online Exam System';
include __DIR__ . '/../templates/header.php';
?>

<div class="container">
    <div class="auth-container">
        <div class="auth-box">
            <h2>Login</h2>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" class="auth-form">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" 
                           id="email" 
                           name="email" 
                           value="<?php echo htmlspecialchars($email); ?>"
                           required 
                           autocomplete="email">
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" 
                           id="password" 
                           name="password" 
                           required 
                           autocomplete="current-password">
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">Login</button>
            </form>
            
            <p class="text-center mt-3">
                Don't have an account? 
                <a href="<?php echo BASE_URL; ?>/auth/register.php">Register here</a>
            </p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>

