/**
 * Co-Curricular Module — Global Push Notification & Persistent Toast Manager
 * Supports:
 * - Silent global auto-initialization for authenticated student users
 * - Short-lived (~2s) unobtrusive bottom-right toast notifications
 * - Strict separation of Toast Visibility vs. Database Read State
 * - Theme-aware styling (Light / Dark mode adaptation)
 * - Multi-tab deduplication via BroadcastChannel & localStorage
 * - Multi-notification queue (max 3 visible simultaneously)
 * - Foreground FCM message reception without modal forcing on unrelated pages
 * - Background FCM Service Worker click routing
 */
(function () {
    'use strict';

    if (typeof window === 'undefined') return;
    if (window.__SMS_COCURRICULAR_FCM_INITIALIZED__) return;
    window.__SMS_COCURRICULAR_FCM_INITIALIZED__ = true;

    function startFcmManager() {
        const config = window.COCURRICULAR_FCM_CONFIG || {};
        const endpoint = config.endpoint || '/sms2-capstone/modules/cocurricular/endpoints/fcm-token.php';
        const swPath = config.swPath || '/sms2-capstone/firebase-messaging-sw.js';
        const swScope = config.swScope || '/sms2-capstone/';
        const notificationApiEndpoint = config.notificationEndpoint || '/sms2-capstone/api/notifications.php';

        // Helper: HTML escaping for safe string rendering
        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // =========================================================================
        // Cross-Tab Synchronization & Deduplication
        // =========================================================================
        const STORAGE_KEY = 'sms_cocurricular_toasts_seen';
        const TOKEN_STORAGE_KEY = 'sms_fcm_registered_token';
        const TOKEN_TIME_KEY = 'sms_fcm_registered_time';
        const CLAIM_KEY = 'sms_cocurricular_toast_claim';

        let broadcastChannel = null;
        if ('BroadcastChannel' in window) {
            try {
                broadcastChannel = new BroadcastChannel('sms_cocurricular_channel');
                broadcastChannel.addEventListener('message', function (evt) {
                    if (evt.data && evt.data.type === 'TOAST_SHOWN') {
                        const id = evt.data.id;
                        if (id) {
                            markToastSeenInMemory(String(id));
                        }
                    }
                });
            } catch (e) {
                broadcastChannel = null;
            }
        }

        const inMemorySeenIds = new Set();

        function getSeenToastIds() {
            try {
                const raw = localStorage.getItem(STORAGE_KEY);
                return raw ? JSON.parse(raw) : [];
            } catch (e) {
                return [];
            }
        }

        function markToastSeenInMemory(strId) {
            inMemorySeenIds.add(strId);
        }

        function markToastSeen(id) {
            if (!id) return;
            const strId = String(id);
            inMemorySeenIds.add(strId);
            try {
                const seen = getSeenToastIds();
                if (!seen.includes(strId)) {
                    seen.push(strId);
                    // Retain up to 100 recent entries to avoid storage bloat
                    if (seen.length > 100) {
                        seen.splice(0, seen.length - 100);
                    }
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(seen));
                }
            } catch (e) {}

            if (broadcastChannel) {
                try {
                    broadcastChannel.postMessage({ type: 'TOAST_SHOWN', id: strId });
                } catch (e) {}
            }
        }

        function isToastSeen(id) {
            if (!id) return false;
            const strId = String(id);
            if (inMemorySeenIds.has(strId)) return true;
            const seen = getSeenToastIds();
            const found = seen.includes(strId);
            if (found) inMemorySeenIds.add(strId);
            return found;
        }

        window.addEventListener('storage', function (e) {
            if (e.key === STORAGE_KEY && e.newValue) {
                try {
                    const ids = JSON.parse(e.newValue);
                    if (Array.isArray(ids)) {
                        ids.forEach(function (id) { inMemorySeenIds.add(String(id)); });
                    }
                } catch (err) {}
            }
        });

        // =========================================================================
        // Toast UI & Queue System (5s duration with smooth slide/fade animation)
        // =========================================================================
        const MAX_VISIBLE_TOASTS = 3;
        const TOAST_DURATION_MS = 5000; // 5 seconds visible experience
        const activeToasts = [];
        const toastQueue = [];

        function getToastContainer() {
            let container = document.getElementById('sms-toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'sms-toast-container';
                container.setAttribute('aria-live', 'polite');
                container.setAttribute('aria-atomic', 'false');
                document.body.appendChild(container);
            }
            return container;
        }

        function displayAnnouncementToast(notifData) {
            const notifId = notifData.notification_id || notifData.id || '';
            if (notifId && isToastSeen(notifId)) {
                return;
            }

            // Multi-tab arbitration: if another tab is currently focused/visible,
            // suppress redundant toast in background tabs
            if (document.visibilityState === 'hidden') {
                try {
                    const claim = JSON.parse(localStorage.getItem(CLAIM_KEY) || '{}');
                    if (claim.id === String(notifId) && (Date.now() - claim.time < 2500)) {
                        markToastSeen(notifId);
                        return;
                    }
                } catch (e) {}
            }

            // Register claim for this tab
            if (notifId) {
                try {
                    localStorage.setItem(CLAIM_KEY, JSON.stringify({ id: String(notifId), time: Date.now() }));
                } catch (e) {}
                markToastSeen(notifId);
            }

            if (activeToasts.length >= MAX_VISIBLE_TOASTS) {
                toastQueue.push(notifData);
                return;
            }

            renderToastElement(notifData);
        }

        function renderToastElement(notifData) {
            const container = getToastContainer();
            const toastEl = document.createElement('div');
            toastEl.className = 'sms-notification-toast';
            toastEl.setAttribute('role', 'alert');
            const notifId = notifData.notification_id || notifData.id || '';
            if (notifId) {
                toastEl.setAttribute('data-notification-id', String(notifId));
            }

            const announcementId = notifData.announcement_id || notifData.related_id || '';
            const clubId = notifData.club_id || '';
            let rawUrl = notifData.destination_url || notifData.url || '';
            if (!rawUrl && announcementId) {
                rawUrl = '/sms2-capstone/modules/cocurricular/pages/my-club.php?club_id=' + encodeURIComponent(clubId) + '&announcement_id=' + encodeURIComponent(announcementId) + '#announcements';
            } else if (!rawUrl) {
                rawUrl = '/sms2-capstone/modules/cocurricular/pages/student-club-membership.php';
            }

            const title = notifData.title || 'New Club Announcement';
            const message = notifData.body || notifData.message || 'A new announcement has been posted.';
            let clubName = notifData.club_name || '';
            let displayMessage = message;

            if (!clubName && message.includes(' posted a new announcement: ')) {
                const parts = message.split(' posted a new announcement: ');
                clubName = parts[0].trim();
                displayMessage = parts[1].trim();
            } else if (!clubName && message.includes(' posted a new announcement')) {
                const parts = message.split(' posted a new announcement');
                clubName = parts[0].trim();
                displayMessage = 'Posted a new announcement.';
            }

            const safeTitle = escapeHtml(title);
            const safeClub = clubName ? escapeHtml(clubName) : '';
            const safeMessage = escapeHtml(displayMessage);

            toastEl.innerHTML =
                '<div class="sms-toast-header">' +
                    '<div class="sms-toast-header-left">' +
                        '<span class="sms-toast-icon-badge" aria-hidden="true">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                                '<path d="M10 5a2 2 0 0 1 4 0a7 7 0 0 1 4 6v3a4 4 0 0 0 2 3h-16a4 4 0 0 0 2 -3v-3a7 7 0 0 1 4 -6" />' +
                                '<path d="M9 17v1a3 3 0 0 0 6 0v-1" />' +
                            '</svg>' +
                        '</span>' +
                        '<h6 class="sms-toast-title">' + safeTitle + '</h6>' +
                    '</div>' +
                    '<button type="button" class="sms-toast-close" data-action="dismiss" aria-label="Dismiss notification">&times;</button>' +
                '</div>' +
                '<div class="sms-toast-body">' +
                    (safeClub ? '<div class="sms-toast-club">' + safeClub + '</div>' : '') +
                    '<div class="sms-toast-message">' + safeMessage + '</div>' +
                '</div>' +
                '<div class="sms-toast-actions">' +
                    '<button type="button" class="sms-toast-btn-secondary" data-action="dismiss">Dismiss</button>' +
                    '<button type="button" class="sms-toast-btn-primary" data-action="view">' +
                        '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                            '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />' +
                            '<circle cx="12" cy="12" r="3" />' +
                        '</svg>' +
                        '<span>View Announcement</span>' +
                    '</button>' +
                '</div>';

            container.appendChild(toastEl);
            activeToasts.push(toastEl);

            // Auto-dismiss after ~2s with pause on hover/focus
            let dismissTimer = null;
            let remainingMs = TOAST_DURATION_MS;
            let timerStart = Date.now();

            function startTimer() {
                timerStart = Date.now();
                dismissTimer = setTimeout(function () {
                    removeToast(toastEl);
                }, remainingMs);
            }

            function pauseTimer() {
                if (dismissTimer) {
                    clearTimeout(dismissTimer);
                    dismissTimer = null;
                    remainingMs -= (Date.now() - timerStart);
                    if (remainingMs < 500) remainingMs = 500;
                }
            }

            toastEl.addEventListener('mouseenter', pauseTimer);
            toastEl.addEventListener('mouseleave', startTimer);
            toastEl.addEventListener('focusin', pauseTimer);
            toastEl.addEventListener('focusout', startTimer);
            startTimer();

            function removeToast(el) {
                if (!el || el.__isRemoving) return;
                el.__isRemoving = true;
                if (dismissTimer) clearTimeout(dismissTimer);

                // Add smooth slide & fade-out class
                el.classList.add('sms-toast-hiding');
                setTimeout(function () {
                    if (el.parentNode) {
                        el.parentNode.removeChild(el);
                    }
                    const idx = activeToasts.indexOf(el);
                    if (idx !== -1) {
                        activeToasts.splice(idx, 1);
                    }
                    // Process next queued toast
                    if (toastQueue.length > 0) {
                        const next = toastQueue.shift();
                        renderToastElement(next);
                    }
                }, 300);
            }

            // Click Handlers
            toastEl.addEventListener('click', async function (e) {
                const dismissBtn = e.target.closest('[data-action="dismiss"]');
                const viewBtn = e.target.closest('[data-action="view"]');

                if (dismissBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    // Toast dismissal (X, Dismiss, or timeout) ONLY closes the UI.
                    // It does NOT mark the database notification as read.
                    removeToast(toastEl);
                    return;
                }

                if (viewBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    removeToast(toastEl);

                    // Explicit 'View Announcement' marks DB notification as read
                    if (notifId) {
                        try {
                            const form = new FormData();
                            form.append('notification_id', String(notifId));
                            if (typeof navigator.sendBeacon === 'function') {
                                navigator.sendBeacon(notificationApiEndpoint, form);
                            }
                            fetch(notificationApiEndpoint, {
                                method: 'POST',
                                body: form,
                                headers: { 'Accept': 'application/json' },
                                credentials: 'same-origin',
                                keepalive: true
                            }).catch(function () {});
                        } catch (err) {}
                    }

                    if (typeof window.SMSRefreshNotifications === 'function') {
                        try {
                            window.SMSRefreshNotifications();
                        } catch (err) {}
                    }

                    // Check if already on the club workspace page
                    const isMyClubPage = window.location.pathname.includes('/modules/cocurricular/pages/my-club.php');
                    if (isMyClubPage && typeof window.cocurricularOpenAnnouncementModal === 'function' && announcementId) {
                        window.cocurricularOpenAnnouncementModal(announcementId, clubId);
                    } else {
                        setTimeout(function () {
                            window.location.href = rawUrl;
                        }, 120);
                    }
                }
            });
        }

        // Browser capability check
        if (!('Notification' in window) || !('serviceWorker' in navigator)) {
            return;
        }

        // =========================================================================
        // Foreground FCM & Service Worker Message Integration
        // =========================================================================
        let foregroundHandlerAttached = false;

        function setupForegroundHandler(messagingInstance) {
            if (!messagingInstance || foregroundHandlerAttached) return;
            foregroundHandlerAttached = true;

            messagingInstance.onMessage(async function (payload) {
                const notifData = payload.data || {};
                const announcementId = notifData.announcement_id || notifData.related_id || '';

                // Synchronize navbar notification badge
                if (typeof window.SMSRefreshNotifications === 'function') {
                    try {
                        await window.SMSRefreshNotifications();
                    } catch (eRefresh) {}
                }

                const title = (payload.notification && payload.notification.title) || notifData.title || 'New Club Announcement';
                const body = (payload.notification && payload.notification.body) || notifData.message || '';

                // Display short-lived bottom-right toast without forcing modal
                displayAnnouncementToast({
                    notification_id: notifData.notification_id || notifData.id || '',
                    announcement_id: announcementId,
                    club_id: notifData.club_id || '',
                    club_name: notifData.club_name || '',
                    title: title,
                    body: body,
                    destination_url: notifData.destination_url || ''
                });
            });
        }

        // Window message listener for direct in-page toast invocation
        window.addEventListener('message', function (event) {
            if (event.data && event.data.type === 'COCURRICULAR_FCM_MESSAGE') {
                displayAnnouncementToast(event.data.data || {});
            }
        });

        // Service Worker message listener (handles native notification click routing)
        let swMessageListenerAttached = false;
        if ('serviceWorker' in navigator && !swMessageListenerAttached) {
            swMessageListenerAttached = true;
            navigator.serviceWorker.addEventListener('message', function (event) {
                if (event.data && event.data.type === 'COCURRICULAR_FCM_CLICK') {
                    const notifData = event.data.data || {};
                    const announcementId = notifData.announcement_id || notifData.related_id;
                    const notifId = notifData.notification_id || notifData.id;
                    const clubId = notifData.club_id;
                    const destinationUrl = notifData.destination_url || notifData.url || '/sms2-capstone/modules/cocurricular/pages/my-club.php';

                    if (notifId) {
                        try {
                            const form = new FormData();
                            form.append('notification_id', String(notifId));
                            fetch(notificationApiEndpoint, {
                                method: 'POST',
                                body: form,
                                headers: { 'Accept': 'application/json' },
                                credentials: 'same-origin',
                                keepalive: true
                            }).catch(function () {});
                        } catch (e) {}
                    }

                    if (typeof window.SMSRefreshNotifications === 'function') {
                        try { window.SMSRefreshNotifications(); } catch (e) {}
                    }

                    const isMyClubPage = window.location.pathname.includes('/modules/cocurricular/pages/my-club.php');
                    if (isMyClubPage && typeof window.cocurricularOpenAnnouncementModal === 'function' && announcementId) {
                        window.cocurricularOpenAnnouncementModal(announcementId, clubId);
                    } else {
                        window.location.href = destinationUrl;
                    }
                }
            });
        }

        // Polling fallback listener
        window.addEventListener('sms:notifications-updated', function (event) {
            const items = (event.detail && Array.isArray(event.detail.items)) ? event.detail.items : [];
            for (let i = 0; i < items.length; i++) {
                const item = items[i];
                if (item.type === 'announcement_new' && item.is_unread) {
                    displayAnnouncementToast({
                        notification_id: item.id,
                        related_id: item.related_id,
                        title: item.label || 'New Club Announcement',
                        body: item.body || item.message || '',
                        url: item.url || ''
                    });
                }
            }
        });

        // =========================================================================
        // Global Idempotent Auto-Initialization
        // =========================================================================
        let isInitializing = false;

        async function initGlobalFCM() {
            if (isInitializing) return;
            isInitializing = true;

            try {
                // If permission is default, prompt permission automatically
                if (Notification.permission === 'default') {
                    try {
                        const permission = await Notification.requestPermission();
                        if (permission !== 'granted') {
                            return;
                        }
                    } catch (ePerm) {
                        return;
                    }
                }

                if (Notification.permission !== 'granted') return;

                // 1. Service Worker registration at /sms2-capstone/ scope
                let registration = null;
                if ('serviceWorker' in navigator) {
                    try {
                        await navigator.serviceWorker.register(swPath, { scope: swScope }).catch(function () {
                            return navigator.serviceWorker.register(swPath).catch(function () { return null; });
                        });
                        registration = await navigator.serviceWorker.ready;
                    } catch (swErr) {
                        // Registration failed
                    }
                } else {
                    return;
                }

                if (!registration) {
                    return;
                }

                // 2. Initialize Firebase Web SDK
                const fb = window.firebase;
                if (!fb || typeof fb.initializeApp !== 'function') return;

                if (!fb.apps || !fb.apps.length) {
                    fb.initializeApp({
                        apiKey: config.apiKey,
                        authDomain: config.authDomain,
                        projectId: config.projectId,
                        storageBucket: config.storageBucket,
                        messagingSenderId: config.messagingSenderId,
                        appId: config.appId
                    });
                }

                const messaging = fb.messaging();

                // Explicitly connect messaging instance to active Service Worker registration
                if (registration && typeof messaging.useServiceWorker === 'function') {
                    try {
                        messaging.useServiceWorker(registration);
                    } catch (eUseSw) {}
                }

                // 3. Attach foreground handler
                setupForegroundHandler(messaging);

                // 4. Token Check & Verification with PushManager
                const currentUserId = config.userId ? String(config.userId) : '';
                const storedToken = localStorage.getItem(TOKEN_STORAGE_KEY);
                const storedTime = Number(localStorage.getItem(TOKEN_TIME_KEY) || 0);
                const storedUserId = localStorage.getItem('sms_fcm_registered_user_id') || '';
                const now = Date.now();

                // Verify that active Service Worker push subscription is actually present
                const activeSubscription = (registration.pushManager && typeof registration.pushManager.getSubscription === 'function')
                    ? await registration.pushManager.getSubscription().catch(() => null)
                    : null;

                // If token is cached, push subscription is active on SW, and matches current user (< 7 days), restore safely
                if (storedToken && activeSubscription && (!currentUserId || storedUserId === currentUserId) && (now - storedTime < 7 * 86400 * 1000)) {
                    return;
                }

                // Retrieve token from FCM using the verified active Service Worker registration
                const cleanVapidKey = config.vapidKey ? config.vapidKey.trim() : null;
                const tokenOpts = {
                    serviceWorkerRegistration: registration
                };
                if (cleanVapidKey && cleanVapidKey.indexOf('REPLACE_WITH_REAL') === -1) {
                    tokenOpts.vapidKey = cleanVapidKey;
                }

                let token = null;
                try {
                    token = await messaging.getToken(tokenOpts);
                } catch (firstErr) {
                    if (storedToken && activeSubscription) {
                        token = storedToken;
                    } else {
                        throw firstErr;
                    }
                }

                if (!token || typeof token !== 'string' || token.trim() === '') return;

                // Send to backend if token changed, user changed, or expired
                if (token !== storedToken || (storedUserId !== currentUserId) || (now - storedTime > 24 * 3600 * 1000)) {
                    const res = await fetch(endpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            fcm_token: token,
                            device_type: 'web'
                        })
                    });

                    const data = await res.json();
                    if (data && data.success) {
                        localStorage.setItem(TOKEN_STORAGE_KEY, token);
                        localStorage.setItem(TOKEN_TIME_KEY, String(now));
                        if (currentUserId) {
                            localStorage.setItem('sms_fcm_registered_user_id', currentUserId);
                        }
                    }
                }

            } catch (err) {
                // Silent catch in production
            } finally {
                isInitializing = false;
            }
        }

        // Expose safe programmatic API for global notifications
        window.COCURRICULAR_FCM = {
            displayAnnouncementToast: displayAnnouncementToast
        };

        // Trigger global auto-initialization immediately for student users
        initGlobalFCM();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startFcmManager);
    } else {
        startFcmManager();
    }
})();
