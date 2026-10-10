'use strict';

document.addEventListener('DOMContentLoaded', function () {
    var body = document.body;
    var nav = document.getElementById('siteNav');
    var navToggle = document.getElementById('navToggle');
    var navPanel = document.getElementById('navPanel');
    var toast = document.getElementById('uiToast');
    var newsletterForm = document.getElementById('newsletterForm');
    var newsletterNote = document.getElementById('newsletterNote');
    var toastTimer = null;

    function showToast(message) {
        if (!toast || !message) {
            return;
        }

        toast.textContent = message;
        toast.classList.add('is-visible');

        if (toastTimer) {
            window.clearTimeout(toastTimer);
        }

        toastTimer = window.setTimeout(function () {
            toast.classList.remove('is-visible');
        }, 2600);
    }

    function closeNavPanel() {
        if (!navPanel || !navToggle) {
            return;
        }

        navPanel.classList.remove('is-open');
        navToggle.setAttribute('aria-expanded', 'false');
    }

    function closeDropdowns() {
        document.querySelectorAll('.nav-menu__has-dropdown.is-open').forEach(function (item) {
            item.classList.remove('is-open');
            var trigger = item.querySelector('[data-dropdown-toggle]');
            if (trigger) {
                trigger.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function setScrolledState() {
        if (!nav) {
            return;
        }

        nav.classList.toggle('is-scrolled', window.scrollY > 18);
    }

    function finishBoot() {
        window.setTimeout(function () {
            body.classList.remove('page-loading');
            body.classList.add('page-ready');
        }, 720);
    }

    if (document.readyState === 'complete') {
        finishBoot();
    } else {
        window.addEventListener('load', finishBoot, { once: true });
    }

    setScrolledState();
    window.addEventListener('scroll', setScrolledState, { passive: true });

    if (navToggle && navPanel) {
        navToggle.addEventListener('click', function () {
            var isOpen = navPanel.classList.toggle('is-open');
            navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

            if (!isOpen) {
                closeDropdowns();
            }
        });
    }

    document.querySelectorAll('[data-dropdown-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (window.innerWidth >= 1024) {
                return;
            }

            var parent = this.closest('.nav-menu__has-dropdown');
            if (!parent) {
                return;
            }

            var willOpen = !parent.classList.contains('is-open');
            closeDropdowns();
            parent.classList.toggle('is-open', willOpen);
            this.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
    });

    document.querySelectorAll('a[href^="#"]').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth < 1024) {
                closeDropdowns();
                closeNavPanel();
            }
        });
    });

    document.addEventListener('click', function (event) {
        if (nav && !nav.contains(event.target)) {
            if (window.innerWidth < 1024) {
                closeDropdowns();
                closeNavPanel();
            } else {
                closeDropdowns();
            }
        }
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth >= 1024) {
            if (navPanel) {
                navPanel.classList.remove('is-open');
            }
            if (navToggle) {
                navToggle.setAttribute('aria-expanded', 'false');
            }
            closeDropdowns();
        }
    });

    document.querySelectorAll('.js-demo-trigger').forEach(function (button) {
        button.addEventListener('click', function () {
            showToast(this.getAttribute('data-demo-message') || 'This interaction is ready for your next backend step.');
        });
    });

    if (newsletterForm && newsletterNote) {
        newsletterForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var emailInput = newsletterForm.querySelector('input[type="email"]');
            if (!emailInput || !emailInput.value.trim()) {
                showToast('Please enter your email address first.');
                return;
            }

            newsletterForm.reset();
            newsletterNote.textContent = 'You are on the list. Launch notes and campaign-ready updates will land in your inbox soon.';
            newsletterNote.classList.add('is-success');
            showToast('Subscription captured successfully.');
        });
    }

    var revealTargets = document.querySelectorAll('[data-reveal]');
    if ('IntersectionObserver' in window) {
        var revealObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    revealObserver.unobserve(entry.target);
                }
            });
        }, {
            threshold: 0.16,
            rootMargin: '0px 0px -40px 0px'
        });

        revealTargets.forEach(function (target) {
            revealObserver.observe(target);
        });
    } else {
        revealTargets.forEach(function (target) {
            target.classList.add('is-visible');
        });
    }
});
