/**
 * Owner Auth Module - JavaScript
 * Password strength, toggle, form validation, AJAX feedback
 */

'use strict';

// ─── Password Toggle ───────────────────────────────────────
document.querySelectorAll('.toggle-password').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var targetId = this.dataset.target;
        var input    = document.getElementById(targetId);
        if (!input) return;
        var isText = input.type === 'text';
        input.type = isText ? 'password' : 'text';
        var icon = this.querySelector('i');
        if (icon) {
            icon.classList.toggle('fa-eye',       isText);
            icon.classList.toggle('fa-eye-slash', !isText);
        }
    });
});

// ─── Password Strength Meter ───────────────────────────────
var strengthInput = document.getElementById('password-strength-input');
if (strengthInput) {
    strengthInput.addEventListener('input', function() {
        updateStrength(this.value);
    });
}

function updateStrength(val) {
    var fill  = document.getElementById('strength-fill');
    var label = document.getElementById('strength-label');
    if (!fill || !label) return;

    var score = 0;
    if (val.length >= 8)                    score++;
    if (/[A-Z]/.test(val))                  score++;
    if (/[a-z]/.test(val))                  score++;
    if (/[0-9]/.test(val))                  score++;
    if (/[^A-Za-z0-9]/.test(val))           score++;

    var levels = [
        { pct: '0%',   color: 'transparent',   text: '' },
        { pct: '20%',  color: '#ef4444',        text: 'Very Weak' },
        { pct: '40%',  color: '#f59e0b',        text: 'Weak' },
        { pct: '60%',  color: '#eab308',        text: 'Fair' },
        { pct: '80%',  color: '#22c55e',        text: 'Strong' },
        { pct: '100%', color: '#10b981',        text: 'Very Strong' }
    ];

    var level = levels[Math.min(score, 5)];
    fill.style.width      = level.pct;
    fill.style.background = level.color;
    label.textContent     = level.text;
    label.style.color     = level.color;
}

// ─── Confirm Password Validation ──────────────────────────
var confirmInput = document.getElementById('confirm_password');
var mainPwd      = document.getElementById('password-strength-input') || document.getElementById('new_password');
if (confirmInput && mainPwd) {
    confirmInput.addEventListener('input', function() {
        var matchMsg = document.getElementById('match-msg');
        if (!matchMsg) return;
        if (this.value === '') {
            matchMsg.textContent = '';
        } else if (this.value === mainPwd.value) {
            matchMsg.textContent = '✓ Passwords match';
            matchMsg.style.color = '#10b981';
        } else {
            matchMsg.textContent = '✗ Passwords do not match';
            matchMsg.style.color = '#ef4444';
        }
    });
}

// ─── Auto-dismiss Alerts ──────────────────────────────────
var alerts = document.querySelectorAll('.alert');
alerts.forEach(function(alert) {
    setTimeout(function() {
        alert.style.transition = 'opacity 0.5s, transform 0.5s';
        alert.style.opacity    = '0';
        alert.style.transform  = 'translateY(-10px)';
        setTimeout(function() { alert.remove(); }, 500);
    }, 5000);
});

// ─── Button Loading State ─────────────────────────────────
var forms = document.querySelectorAll('.auth-form');
forms.forEach(function(form) {
    form.addEventListener('submit', function() {
        var btn = this.querySelector('button[type="submit"]');
        if (btn && !btn.dataset.noLoading) {
            var original = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Processing...';
            btn.disabled  = true;
            // Re-enable after 8s as fallback
            setTimeout(function() {
                btn.innerHTML = original;
                btn.disabled  = false;
            }, 8000);
        }
    });
});

// ─── Copy Reset Link ──────────────────────────────────────
var copyBtn = document.getElementById('copy-reset-link');
if (copyBtn) {
    copyBtn.addEventListener('click', function() {
        var link = document.getElementById('reset-link-input');
        if (!link) return;
        navigator.clipboard.writeText(link.value).then(function() {
            copyBtn.innerHTML = '<i class="fas fa-check"></i> Copied!';
            copyBtn.style.background = 'rgba(16,185,129,0.15)';
            copyBtn.style.borderColor = 'rgba(16,185,129,0.35)';
            copyBtn.style.color = '#6ee7b7';
            setTimeout(function() {
                copyBtn.innerHTML = '<i class="fas fa-copy"></i> Copy Link';
                copyBtn.style.background = '';
                copyBtn.style.borderColor = '';
                copyBtn.style.color = '';
            }, 2000);
        }).catch(function() {
            link.select();
            document.execCommand('copy');
        });
    });
}

// ─── Floating label focus effects ─────────────────────────
document.querySelectorAll('.form-control').forEach(function(input) {
    var wrapper = input.closest('.input-wrapper');
    if (!wrapper) return;
    var icon = wrapper.querySelector('.input-icon');
    input.addEventListener('focus', function() {
        if (icon) icon.style.color = '#8b84ff';
    });
    input.addEventListener('blur', function() {
        if (icon) icon.style.color = '';
    });
});
