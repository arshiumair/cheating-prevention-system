<?php
/**
 * Registration Page
 * Handles new user registration for the Online Exam Monitoring System
 * 
 * Features:
 * - Secure password hashing
 * - Email uniqueness validation
 * - Input sanitization
 * - Prepared statements for SQL injection prevention
 * - Automatic login after registration
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Initialize variables
$error = '';
$success = '';
$formData = [
    'name' => '',
    'email' => ''
];

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/exam.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get and sanitize form data
        $formData = [
            'name' => trim($_POST['name'] ?? ''),
            'email' => strtolower(trim($_POST['email'] ?? ''))
        ];
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Input validation
        if (empty($formData['name']) || empty($formData['email']) || empty($password)) {
            $error = 'All fields are required.';
        } 
        // Validate email format
        elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        }
        // Validate password
        elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters long.';
        }
        // Confirm passwords match
        elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match.';
        }
        else {
            // Check for existing email using prepared statement
            $checkStmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            if (!$checkStmt) {
                throw new Exception("Database error: " . $conn->error);
            }

            $checkStmt->bind_param("s", $formData['email']);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult->num_rows > 0) {
                $error = 'Email already registered. Please login instead.';
                $checkStmt->close();
            } else {
                $checkStmt->close();

                // Prepare insert statement
                $insertStmt = $conn->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
                if (!$insertStmt) {
                    throw new Exception("Database error: " . $conn->error);
                }

                // Hash password and insert user
                $hashedPassword = hashPassword($password);
                $insertStmt->bind_param("sss", $formData['name'], $formData['email'], $hashedPassword);

                if ($insertStmt->execute()) {
                    // Get the new user's ID
                    $userId = $conn->insert_id;

                    // Start session and log user in
                    if (session_status() === PHP_SESSION_NONE) {
                        session_start();
                    }

                    $_SESSION['user_id'] = $userId;
                    $_SESSION['user_name'] = $formData['name'];
                    $_SESSION['user_email'] = $formData['email'];
                    $_SESSION['last_activity'] = time();

                    // Attempt to log the registration (non-fatal)
                    try {
                        $logStmt = $conn->prepare("INSERT INTO registration_logs (user_id, ip_address) VALUES (?, ?)");
                        if ($logStmt) {
                            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                            $logStmt->bind_param("is", $userId, $ip);
                            $logStmt->execute();
                            $logStmt->close();
                        }
                    } catch (Exception $e) {
                        // Logging failure should not break registration flow
                        error_log("registration log failed: " . $e->getMessage());
                    }

                    $success = 'Registration successful! Redirecting to exam page...';
                    
                    // Clean up
                    $insertStmt->close();
                    
                    // Redirect after a brief delay to show success message
                    header("refresh:2;url=" . BASE_URL . "/exam.php");
                } else {
                    throw new Exception("Failed to create account.");
                }
            }
        }
    } catch (Exception $e) {
        // Log the error for administrators
        error_log("Registration error: " . $e->getMessage());
        $error = 'An unexpected error occurred. Please try again later.';
    }
}

$page_title = 'Register - Exam Monitor';
include __DIR__ . '/../templates/header.php';
?>

<div class="container">
    <div class="auth-container">
        <div class="auth-box">
            <h2>Create Account</h2>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <p class="text-center"><a href="<?php echo BASE_URL; ?>/auth/login.php" class="btn btn-primary">Go to Login</a></p>
            <?php else: ?>
                <form method="POST" action="" class="auth-form" autocomplete="off">
                    <div class="form-group">
                        <label for="name">Full Name</label>
                        <input type="text" 
                               id="name" 
                               name="name" 
                               required 
                               maxlength="100"
                               pattern="[A-Za-z .]+"
                               title="Please enter a valid name (letters, spaces, and dots only)"
                               value="<?php echo htmlspecialchars($formData['name']); ?>"
                               autocomplete="name">
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" 
                               id="email" 
                               name="email" 
                               required 
                               maxlength="100"
                               value="<?php echo htmlspecialchars($formData['email']); ?>"
                               autocomplete="email">
                        <small class="form-text text-muted">This will be your login username</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" 
                               id="password" 
                               name="password" 
                               required 
                               minlength="6"
                               pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{6,}"
                               title="Must be at least 6 characters long and include a number, an uppercase and a lowercase letter"
                               autocomplete="new-password">
                        <small class="form-text text-muted">Minimum 6 characters, include numbers and letters</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" 
                               id="confirm_password" 
                               name="confirm_password" 
                               required 
                               minlength="6"
                               pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{6,}"
                               autocomplete="new-password">
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block">Create Account</button>
                </form>
                
                <p class="text-center mt-3">
                    Already have an account? <a href="<?php echo BASE_URL; ?>/auth/login.php">Login here</a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>

