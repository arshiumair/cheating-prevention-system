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
    this.warningLevel = 0;         // 0 = no warnings shown, increments with each warning (max 2 before termination)
        this.terminated = false;       // whether the exam has been terminated
        this.fullscreenInitialized = false; // track if fullscreen has been initialized
        this.fullscreenCountdown = null; // tracks the 30-second countdown timer
        this.countdownModal = null; // reference to the countdown modal
    this.lastIsFullscreen = null; // track previous fullscreen state to detect transitions
    // Throttle duplicate/rapid-fire events to avoid double-counting
    this.lastViolationTime = 0; // ms timestamp of last handled violation
    this.violationDebounceMs = 2000; // ignore additional events within 2s

        // Simple in-memory log storage for debugging
        // Each entry: { time, event, description }
        this.logs = [];

        this.init();
    }

    // Helper: whether we should start detecting violations yet
    readyToDetect() {
        return this.fullscreenInitialized && !this.terminated;
    }
    
    init() {
        // Check if we're on exam page (works with any path structure)
        const pathname = window.location.pathname;
        if (!pathname.includes('exam.php')) {
            return;
        }
        
        // Event listeners for cheating detection
        this.setupEventListeners();
        
        // Initialize fullscreen with a grace period
        this.initializeFullscreen();
    }

    initializeFullscreen() {
        // Show fullscreen instruction modal
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.id = 'fullscreenModal';
        modal.style.display = 'flex';
        modal.innerHTML = `
            <div class="modal-content">
                <h2>Exam Instructions</h2>
                <p>This exam must be taken in fullscreen mode.</p>
                <p>Click the button below to start the exam in fullscreen mode.</p>
                <button id="startFullscreenBtn" class="btn btn-primary">Start Exam in Fullscreen</button>
            </div>
        `;
        document.body.appendChild(modal);

        // Handle fullscreen initialization
        document.getElementById('startFullscreenBtn').addEventListener('click', async () => {
            try {
                await this.requestFullscreen(document.documentElement);
                this.fullscreenInitialized = true;
                modal.style.display = 'none';

                // Notify server to start a session (creates session row without logging a violation)
                try {
                    const res = await this.reportViolation('start_session', 'Session started by client');
                    if (res && res.session_id) {
                        // store for debugging and future use
                        window.exam_session_id = res.session_id;
                        console.info('Exam session started (id):', res.session_id);
                    }
                } catch (err) {
                    console.warn('Failed to explicitly start session on server:', err);
                }

                // Start monitoring fullscreen after a 2-second grace period
                setTimeout(() => {
                    this.startFullscreenMonitoring();
                }, 2000);
            } catch (err) {
                console.error('Failed to enter fullscreen:', err);
                alert('Error entering fullscreen mode. Please ensure your browser allows fullscreen and try again.');
            }
        });
    }

    async requestFullscreen(element) {
        if (element.requestFullscreen) {
            await element.requestFullscreen();
        } else if (element.webkitRequestFullscreen) {
            await element.webkitRequestFullscreen();
        } else if (element.mozRequestFullScreen) {
            await element.mozRequestFullScreen();
        } else if (element.msRequestFullscreen) {
            await element.msRequestFullscreen();
        }
    }

    startFullscreenMonitoring() {
        // Initial check
        this.checkFullscreen();

        // Set last known state so we only treat transitions as violations
        const isFS = !!(
            document.fullscreenElement ||
            document.webkitFullscreenElement ||
            document.mozFullScreenElement ||
            document.msFullscreenElement
        );
        this.lastIsFullscreen = isFS;

        // Periodic check every 2 seconds (less aggressive than before)
        setInterval(() => {
            if (!this.terminated && this.fullscreenInitialized) {
                this.checkFullscreen();
            }
        }, 2000);
    }
    
    setupEventListeners() {
        // Tab switch / Window blur detection
        // When the document becomes hidden (user switched tab)
        document.addEventListener('visibilitychange', () => {
            if (!this.readyToDetect()) return; // only detect after fullscreen initialized
            if (document.hidden) {
                this.handleViolation('visibilitychange', 'Tab switch or window blur detected');
            }
        });
        
        // Window blur detection (additional check) - detects when window loses focus
        // Window losing focus (additional blur detection)
        window.addEventListener('blur', () => {
            if (!this.readyToDetect()) return;
            this.handleViolation('blur', 'Window blur detected');
        });
        
        // Window focus detection - also check when window regains focus (might have switched)
        // When window regains focus, check if visibility indicates a switch
        window.addEventListener('focus', () => {
            if (!this.readyToDetect()) return;
            setTimeout(() => {
                if (document.hidden) {
                    this.handleViolation('focus', 'Tab switch detected on focus');
                }
            }, 100);
        });
        
        // Additional polling check for visibility changes (defensive)
        let lastVisibilityState = document.visibilityState;
        setInterval(() => {
            if (!this.readyToDetect()) return;
            const currentVisibility = document.visibilityState;
            if (currentVisibility === 'hidden' && lastVisibilityState === 'visible') {
                this.handleViolation('visibility_poll', 'Tab visibility changed - possible tab switch');
            }
            lastVisibilityState = currentVisibility;
        }, 200); // Check every 200ms
        
        // Fullscreen exit detection
        document.addEventListener('fullscreenchange', () => {
            if (this.fullscreenInitialized) {
                this.checkFullscreen();
            }
        });
        
        document.addEventListener('webkitfullscreenchange', () => {
            if (this.fullscreenInitialized) {
                this.checkFullscreen();
            }
        });
        
        document.addEventListener('mozfullscreenchange', () => {
            if (this.fullscreenInitialized) {
                this.checkFullscreen();
            }
        });
        
        document.addEventListener('MSFullscreenChange', () => {
            if (this.fullscreenInitialized) {
                this.checkFullscreen();
            }
        });
        
        // Cursor tracking: detect when mouse leaves the browser window
        // Use mouseout on window and check relatedTarget/toElement to detect leaving viewport
        window.addEventListener('mouseout', (e) => {
            if (!this.readyToDetect()) return;
            e = e || window.event;
            const from = e.relatedTarget || e.toElement;
            if (!from) {
                // No related target means the mouse left the browser window
                this.handleViolation('cursor_leave', 'Mouse left the browser window');
            }
        });

        // Prevent context menu (right-click)
        document.addEventListener('contextmenu', (e) => {
            // Always prevent context menu during exam UI, but only treat as violation after fullscreen started
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
                if (!this.readyToDetect()) return;
                this.handleViolation('devtools_shortcut', 'Developer tools access attempted');
            }
        });
    }
    
    checkFullscreen() {
        if (!this.fullscreenInitialized) return;

        const isFullscreen = !!(
            document.fullscreenElement ||
            document.webkitFullscreenElement ||
            document.mozFullScreenElement ||
            document.msFullscreenElement
        );
        
        // If we don't yet have a previous state recorded, record and return (no transition)
        if (this.lastIsFullscreen === null) {
            this.lastIsFullscreen = isFullscreen;
            return;
        }

        // If we've transitioned from fullscreen -> not-fullscreen, count a violation
        if (this.lastIsFullscreen && !isFullscreen) {
            // Always report the violation on transition
            this.handleViolation('fullscreen_exit', 'Fullscreen exit detected');

            // If there's no countdown running, start one
            if (this.fullscreenCountdown === null) {
                this.startFullscreenCountdown();
            }
        }

        // If we've transitioned back into fullscreen, clear any countdown
        if (!this.lastIsFullscreen && isFullscreen) {
            this.clearFullscreenCountdown();
        }

        // Update last known state
        this.lastIsFullscreen = isFullscreen;
    }

    startFullscreenCountdown() {
        let secondsLeft = 30;
        
        // Create countdown modal if it doesn't exist
        if (!this.countdownModal) {
            this.countdownModal = document.createElement('div');
            this.countdownModal.style.position = 'fixed';
            this.countdownModal.style.top = '50%';
            this.countdownModal.style.left = '50%';
            this.countdownModal.style.transform = 'translate(-50%, -50%)';
            this.countdownModal.style.backgroundColor = '#fff';
            this.countdownModal.style.padding = '20px';
            this.countdownModal.style.borderRadius = '8px';
            this.countdownModal.style.boxShadow = '0 2px 10px rgba(0,0,0,0.2)';
            this.countdownModal.style.zIndex = '9999';
            document.body.appendChild(this.countdownModal);
        }

        const updateCountdown = () => {
            this.countdownModal.innerHTML = `
                <h3 style="margin-top: 0; color: #dc3545;">⚠️ Warning: Exit from Fullscreen</h3>
                <p>A violation has been recorded for exiting fullscreen mode.</p>
                <p>Please return to fullscreen mode within <strong>${secondsLeft}</strong> seconds.</p>
                <p>Additional attempts to exit fullscreen will be counted as violations.</p>
                <button onclick="document.documentElement.requestFullscreen().catch(console.error)" 
                        style="background: #007bff; color: white; border: none; padding: 8px 16px; 
                        border-radius: 4px; cursor: pointer;">
                    Return to Fullscreen
                </button>
            `;
        };

        updateCountdown();

        // Start the countdown
        this.fullscreenCountdown = setInterval(() => {
            secondsLeft--;
            
            if (secondsLeft <= 0) {
                this.clearFullscreenCountdown();
                this.handleViolation('fullscreen_exit', 'Failed to return to fullscreen within 30 seconds');
            } else {
                updateCountdown();
            }
        }, 1000);
    }

    clearFullscreenCountdown() {
        if (this.fullscreenCountdown !== null) {
            clearInterval(this.fullscreenCountdown);
            this.fullscreenCountdown = null;
        }
        
        if (this.countdownModal) {
            this.countdownModal.remove();
            this.countdownModal = null;
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
                // Fallback escalation: warn on 1st and 2nd, end on 3rd or more
                action: this.violationCount >= 3 ? 'end' : (this.violationCount >= 1 ? 'warn' : 'ok'),
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

        // Debounce rapid duplicate events (many browsers fire multiple related events)
        const now = Date.now();
        if (now - this.lastViolationTime < this.violationDebounceMs) {
            // Log suppressed event for debugging but do not increment
            this.logs.push({ time: new Date().toISOString(), event: event, description: description, suppressed: true });
            console.warn('Suppressed duplicate violation event:', event, description);
            return;
        }

        // Mark this as the latest handled violation
        this.lastViolationTime = now;

        // Increment violation count now that this is a real event
        this.violationCount++;

        // Record log entry locally
        const entry = {
            time: new Date().toISOString(),
            event: event,
            description: description,
            violationCount: this.violationCount
        };
        this.logs.push(entry);
        console.table(this.logs); // Debug output

        try {
            // Report to server and get response
            const result = await this.reportViolation(event, description);

            // If server explicitly requests termination, obey it immediately
            if (result && result.action === 'end') {
                console.error('Server requested termination:', result.message);
                this.terminateExam();
                return;
            }

            // Escalation logic based on local violationCount:
            // 1st violation: show first warning
            // 2nd violation: show final warning
            // 3rd (or more): terminate the exam
            if (this.violationCount === 1) {
                this.showWarning('Warning: This is your first violation. Two warnings will be shown before termination.');
                console.warn('Violation handled - first warning shown:', description);
            } else if (this.violationCount === 2) {
                this.showWarning('Warning: This is your second violation. One more violation will terminate the exam.');
                console.warn('Violation handled - second warning shown:', description);
            } else if (this.violationCount >= 3) {
                console.error('Third violation - terminating exam:', description);
                this.terminateExam();
            }

        } catch (error) {
            // Server communication failed, but we already incremented the counter
            // and will use the same logic as success case
            console.warn('Server communication failed:', error.message);
            
            // If the server failed to respond, fall back to client-side escalation
            if (this.violationCount === 1) {
                this.showWarning('Warning: This is your first violation. Two warnings will be shown before termination.');
                console.warn('Violation handled (fallback) - first warning shown');
            } else if (this.violationCount === 2) {
                this.showWarning('Warning: This is your second violation. One more violation will terminate the exam.');
                console.warn('Violation handled (fallback) - second warning shown');
            } else if (this.violationCount >= 3) {
                console.error('Third violation (fallback) - terminating exam:', description);
                this.terminateExam();
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
        if (this.terminated) return;

        // Increment warning level (we allow up to 2 warnings before termination)
        this.warningLevel = (this.warningLevel || 0) + 1;

        // Reuse existing banner if present
        let warning = document.getElementById('violationWarning');
        if (!warning) {
            warning = document.createElement('div');
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
            document.body.appendChild(warning);
        }

        // Update message; include a small prefix indicating warning number
        const prefix = this.warningLevel === 1 ? 'Warning (1/2): ' : this.warningLevel === 2 ? 'Final Warning (2/2): ' : '';
        warning.textContent = prefix + message;
    }

    /**
     * Terminate the exam: disable inputs, stop timer, show terminated message.
     */
    terminateExam() {
        if (this.terminated) return;
        this.terminated = true;

        // Clear fullscreen countdown if it's running
        this.clearFullscreenCountdown();

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

