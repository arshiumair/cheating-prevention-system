/**
 * Cheating Detection Module
 * Monitors tab switches, window blur, and fullscreen exits
 */

class CheatingDetector {
    constructor() {
        // Flag that a cheating action has been detected (used for older immediate flow)
        this.cheatingDetected = false;

        // Violation counter and state flags
        this.violationCount = 0;        // counts detected violations
        this.warningShown = false;     // whether the 2nd-violation warning is visible
        this.terminated = false;       // whether the exam has been terminated

        // Simple in-memory log storage for debugging
        // Each entry: { time, event, description }
        this.logs = [];

        this.init();
    }
    
    init() {
        // Check if we're on exam page (works with any path structure)
        const pathname = window.location.pathname;
        if (!pathname.includes('exam.php')) {
            return;
        }
        
    // Event listeners for cheating detection
    this.setupEventListeners();
        
        // Check fullscreen status on load
        this.checkFullscreen();
        
        // Continuous monitoring for fullscreen (in case it's exited immediately)
        setInterval(() => {
            if (!this.cheatingDetected) {
                this.checkFullscreen();
            }
        }, 500); // Check every 500ms
    }
    
    setupEventListeners() {
        // Tab switch / Window blur detection
        // When the document becomes hidden (user switched tab)
        document.addEventListener('visibilitychange', () => {
            if (this.terminated) return; // ignore after termination
            if (document.hidden) {
                this.handleViolation('visibilitychange', 'Tab switch or window blur detected');
            }
        });
        
        // Window blur detection (additional check) - detects when window loses focus
        // Window losing focus (additional blur detection)
        window.addEventListener('blur', () => {
            if (this.terminated) return;
            this.handleViolation('blur', 'Window blur detected');
        });
        
        // Window focus detection - also check when window regains focus (might have switched)
        // When window regains focus, check if visibility indicates a switch
        window.addEventListener('focus', () => {
            if (this.terminated) return;
            setTimeout(() => {
                if (document.hidden) {
                    this.handleViolation('focus', 'Tab switch detected on focus');
                }
            }, 100);
        });
        
        // Additional polling check for visibility changes (defensive)
        let lastVisibilityState = document.visibilityState;
        setInterval(() => {
            if (this.terminated) return;
            const currentVisibility = document.visibilityState;
            if (currentVisibility === 'hidden' && lastVisibilityState === 'visible') {
                this.handleViolation('visibility_poll', 'Tab visibility changed - possible tab switch');
            }
            lastVisibilityState = currentVisibility;
        }, 200); // Check every 200ms
        
        // Fullscreen exit detection
        document.addEventListener('fullscreenchange', () => {
            this.checkFullscreen();
        });
        
        document.addEventListener('webkitfullscreenchange', () => {
            this.checkFullscreen();
        });
        
        document.addEventListener('mozfullscreenchange', () => {
            this.checkFullscreen();
        });
        
        document.addEventListener('MSFullscreenChange', () => {
            this.checkFullscreen();
        });
        
        // Cursor tracking: detect when mouse leaves the browser window
        // Use mouseout on window and check relatedTarget/toElement to detect leaving viewport
        window.addEventListener('mouseout', (e) => {
            if (this.terminated) return;
            e = e || window.event;
            const from = e.relatedTarget || e.toElement;
            if (!from) {
                // No related target means the mouse left the browser window
                this.handleViolation('cursor_leave', 'Mouse left the browser window');
            }
        });

        // Prevent context menu (right-click)
        document.addEventListener('contextmenu', (e) => {
            e.preventDefault();
        });
        
        // Prevent common keyboard shortcuts
        // Prevent common keyboard shortcuts used to open devtools or view source
        document.addEventListener('keydown', (e) => {
            // Prevent F12, Ctrl+Shift+I, Ctrl+Shift+J, Ctrl+U
            if (e.key === 'F12' || 
                (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'J')) ||
                (e.ctrlKey && e.key === 'u')) {
                e.preventDefault();
                this.handleViolation('devtools_shortcut', 'Developer tools access attempted');
            }
        });
    }
    
    checkFullscreen() {
        const isFullscreen = !!(
            document.fullscreenElement ||
            document.webkitFullscreenElement ||
            document.mozFullScreenElement ||
            document.msFullscreenElement
        );
        // If fullscreen is exited, treat it as a violation
        if (!isFullscreen) {
            this.handleViolation('fullscreen_exit', 'Fullscreen exit detected');
        }
    }
    
    /**
     * Centralized handler for violations.
     * - Logs the event
     * - Increments violation counter
     * - On 2nd violation: shows a visible warning
     * - On 3rd violation: terminates the exam (disable inputs + show terminated message)
     */
    /**
     * Sends violation event to server and handles response
     * @param {string} eventType - Type of violation
     * @param {string} details - Additional details about the violation
     * @returns {Promise<void>}
     */
    async reportViolation(eventType, details) {
        try {
            const response = await fetch('log_event.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ event_type: eventType, details }),
                credentials: 'same-origin' // Include session cookies
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.error || 'Unknown error occurred');
            }

            return data.data; // Return the data portion of the response
            
        } catch (error) {
            console.error('Failed to report violation:', error);
            // Fall back to client-side counting if server communication fails
            return {
                violations: this.violationCount + 1,
                action: this.violationCount >= 2 ? 'end' : this.violationCount === 1 ? 'warn' : 'ok',
                message: error.message
            };
        }
    }

    /**
     * Handles a violation event, reports to server, and takes appropriate action
     * @param {string} event - Event type identifier
     * @param {string} description - Description of the violation
     */
    async handleViolation(event, description) {
        // Do nothing if already terminated
        if (this.terminated) return;

        // Record log entry locally
        const entry = {
            time: new Date().toISOString(),
            event: event,
            description: description
        };
        this.logs.push(entry);
        console.table(this.logs); // Debug output

        try {
            // Report to server and get response
            const result = await this.reportViolation(event, description);
            
            // Update local counter to match server
            this.violationCount = result.violations;

            // Handle response based on action
            switch (result.action) {
                case 'warn':
                    if (!this.warningShown) {
                        this.showWarning(result.message || 'Warning: Continued violations will terminate the exam.');
                        console.warn(`Violation ${this.violationCount} - warning shown:`, result.message);
                    }
                    break;

                case 'end':
                    console.error(`Violation ${this.violationCount} - terminating exam:`, result.message);
                    this.terminateExam();
                    break;

                default: // 'ok'
                    console.warn(`Violation ${this.violationCount} detected:`, result.message);
                    break;
            }

        } catch (error) {
            // Fallback to client-side handling if server communication fails
            this.violationCount++;
            
            if (this.violationCount === 2) {
                this.showWarning('Warning: Continued violations will terminate the exam.');
                console.warn('Violation 2 - warning shown (fallback)');
            } else if (this.violationCount >= 3) {
                console.error('Violation 3 - terminating exam (fallback):', description);
                this.terminateExam();
            } else {
                console.warn('Violation 1 detected (fallback):', description);
            }
        }
    }
    
    submitExamAsCheated() {
        // Get current answers and time
        const answers = window.examAnswers || {};
        const timeTaken = window.examStartTime ? Math.floor((Date.now() - window.examStartTime) / 1000) : 0;
        
        // Calculate score (0 for cheated)
        const score = 0;
        const totalQuestions = window.examQuestions ? window.examQuestions.length : 0;
        
        // Submit via form
        const form = document.createElement('form');
        form.method = 'POST';
        // Get base URL from current location
        const basePath = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
        form.action = basePath + '/result.php';
        
        form.appendChild(this.createHiddenInput('submit_result', '1'));
        form.appendChild(this.createHiddenInput('score', score));
        form.appendChild(this.createHiddenInput('total_questions', totalQuestions));
        form.appendChild(this.createHiddenInput('time_taken', timeTaken));
        form.appendChild(this.createHiddenInput('status', 'cheated'));
        
        document.body.appendChild(form);
        form.submit();
    }

    /**
     * Show a visible on-screen warning (used on 2nd violation).
     * Creates a simple banner at the top of the page.
     */
    showWarning(message) {
        if (this.warningShown || this.terminated) return;
        this.warningShown = true;

        const warning = document.createElement('div');
        warning.id = 'violationWarning';
        // Minimal inline styles to ensure visibility without changing existing CSS
        warning.style.position = 'fixed';
        warning.style.top = '20px';
        warning.style.left = '50%';
        warning.style.transform = 'translateX(-50%)';
        warning.style.background = '#fff3cd';
        warning.style.color = '#856404';
        warning.style.border = '1px solid #ffeeba';
        warning.style.padding = '12px 18px';
        warning.style.borderRadius = '6px';
        warning.style.zIndex = '3000';
        warning.style.boxShadow = '0 2px 6px rgba(0,0,0,0.1)';
        warning.textContent = message;

        document.body.appendChild(warning);
    }

    /**
     * Terminate the exam: disable inputs, stop timer, show terminated message.
     */
    terminateExam() {
        if (this.terminated) return;
        this.terminated = true;

        // Stop exam timer if running
        if (window.examTimer) {
            clearInterval(window.examTimer);
        }

        // Disable all interactive elements to prevent further actions
        try {
            const selectors = 'input, button, textarea, select, [contenteditable]';
            document.querySelectorAll(selectors).forEach(el => {
                try { el.disabled = true; } catch (e) { /* ignore */ }
                // Also remove event listeners by cloning node (best-effort)
                if (el.cloneNode) {
                    const clone = el.cloneNode(true);
                    el.parentNode && el.parentNode.replaceChild(clone, el);
                }
            });
        } catch (e) {
            // ignore any DOM errors
        }

        // Update or show a modal/overlay with termination message
        const modal = document.getElementById('cheatingModal');
        if (modal) {
            // Try to update existing modal content to indicate termination
            const content = modal.querySelector('.modal-content');
            if (content) {
                content.innerHTML = `\n                    <h2>⚠️ Exam Terminated</h2>\n                    <p>Your exam session has been terminated due to repeated violations of the exam rules.</p>\n                    <p>Please contact the administrator for further details.</p>\n                    <a href="${window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'))}/result.php?status=cheated" class="btn btn-primary">View Results</a>\n                `;
            }
            modal.style.display = 'flex';
        } else {
            // Fallback: create a simple overlay
            const overlay = document.createElement('div');
            overlay.id = 'examTerminatedOverlay';
            overlay.style.position = 'fixed';
            overlay.style.top = '0';
            overlay.style.left = '0';
            overlay.style.width = '100%';
            overlay.style.height = '100%';
            overlay.style.background = 'rgba(0,0,0,0.7)';
            overlay.style.display = 'flex';
            overlay.style.alignItems = 'center';
            overlay.style.justifyContent = 'center';
            overlay.style.zIndex = '4000';

            const box = document.createElement('div');
            box.style.background = '#fff';
            box.style.padding = '24px';
            box.style.borderRadius = '8px';
            box.style.maxWidth = '560px';
            box.style.textAlign = 'center';
            box.innerHTML = `\n                <h2>⚠️ Exam Terminated</h2>\n                <p>Your exam session has been terminated due to repeated violations of the exam rules.</p>\n                <p>Please contact the administrator for further details.</p>\n            `;

            overlay.appendChild(box);
            document.body.appendChild(overlay);
        }

        // Log termination
        this.logs.push({ time: new Date().toISOString(), event: 'terminated', description: 'Exam terminated after repeated violations' });
        console.table(this.logs);
    }
    
    createHiddenInput(name, value) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        return input;
    }
    
    showCheatingModal() {
        const modal = document.getElementById('cheatingModal');
        if (modal) {
            modal.style.display = 'flex';
        }
    }
}

// Initialize cheating detector when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.cheatingDetector = new CheatingDetector();
    });
} else {
    window.cheatingDetector = new CheatingDetector();
}

