/*
 * Co-Curricular Module - Firebase Cloud Messaging Service Worker
 * Handles background push notifications for Co-Curricular student events, membership updates, and announcements.
 * Scope: /sms2-capstone/
 */

// Service Worker lifecycle management: take control immediately
self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

// Import Firebase App and Messaging SDKs (Compat version for Service Workers)
importScripts('https://www.gstatic.com/firebasejs/9.23.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/9.23.0/firebase-messaging-compat.js');

// Initialize Firebase inside the Service Worker
// Public client credentials used for message routing
firebase.initializeApp({
    apiKey: "AIzaSyBBxVoH7mLn_u1xZwTywNczCdkMLfpXQqc",
    authDomain: "co-curricular-management-ed6aa.firebaseapp.com",
    projectId: "co-curricular-management-ed6aa",
    storageBucket: "co-curricular-management-ed6aa.firebasestorage.app",
    messagingSenderId: "982710359986",
    appId: "1:982710359986:web:bad1f738d2a5577bd0f6ab"
});

const messaging = firebase.messaging();

// Handle background messages via Firebase Compat SDK
messaging.onBackgroundMessage(() => {
    // Handled by custom push listener
});

// Suppress duplicate, untagged showNotification calls triggered by Firebase Compat SDK internally
// so that ONLY the custom Service Worker push handler renders the native notification.
const _originalShowNotification = self.registration.showNotification.bind(self.registration);
self.registration.showNotification = function (title, options) {
    if (options && options.data && !options.data.announcement_id && !options.data.notification_id && !(options.tag && options.tag.startsWith('cocurricular-'))) {
        return Promise.resolve();
    }
    return _originalShowNotification(title, options);
};

// Primary native Push Event Listener for Background Delivery
// Ensures native OS/browser notification triggers when student is on Google, YouTube, Facebook, another site, or closed
self.addEventListener('push', (event) => {
    let rawData = {};
    try {
        rawData = event.data ? event.data.json() : {};
    } catch (e) {
        try { rawData = { text: event.data.text() }; } catch (e2) {}
    }

    const notif = rawData.notification || {};
    const data = rawData.data || {};
    const announcementId = data.announcement_id || data.related_id || '';
    const clubId = data.club_id || '';
    const notifId = data.notification_id || data.id || '';
    const clubName = data.club_name || '';
    const notificationTitle = notif.title || data.title || 'New Club Announcement';
    const rawBody = notif.body || data.message || '';
    let destinationUrl = data.destination_url || data.url || '';

    event.waitUntil((async () => {
        // Inspect if any portal client is currently visible/focused
        const windowClients = await clients.matchAll({ type: 'window', includeUncontrolled: true });

        // Determine whether an ACTUAL SMS2 student portal client is currently visible/focused.
        // External URLs (e.g. Google, Facebook, YouTube) or uncontrolled/admin publisher windows
        // MUST be treated as NO ACTIVE SMS2 PORTAL CLIENT.
        const portalClients = windowClients.filter(c => {
            try {
                if (!c.url) return false;
                const urlObj = new URL(c.url, self.location.href);
                const path = urlObj.pathname.toLowerCase();
                if (!path.includes('/sms2-capstone/')) {
                    return false;
                }
                if (path.includes('/api/') || path.includes('/endpoints/') || path.includes('/images/') || path.includes('/assets/')) {
                    return false;
                }
                if (path.includes('student-affairs-announcements.php') || path.includes('/admin/')) {
                    return false;
                }
                return true;
            } catch (e) {
                return false;
            }
        });

        const hasVisiblePortalTab = portalClients.some(c => c.visibilityState === 'visible');

        // If the student is outside the portal (on Google, YouTube, Facebook, minimized, or no tab), show native notification
        if (!hasVisiblePortalTab) {
            if (!destinationUrl && announcementId && clubId) {
                destinationUrl = '/sms2-capstone/modules/cocurricular/pages/my-club.php?club_id=' + encodeURIComponent(clubId) + '&announcement_id=' + encodeURIComponent(announcementId) + '#announcements';
            } else if (!destinationUrl && announcementId) {
                destinationUrl = '/sms2-capstone/modules/cocurricular/pages/my-club.php?announcement_id=' + encodeURIComponent(announcementId) + '#announcements';
            } else if (!destinationUrl) {
                destinationUrl = '/sms2-capstone/modules/cocurricular/pages/my-club.php';
            }

            if (!destinationUrl.startsWith('http') && !destinationUrl.startsWith('/sms2-capstone')) {
                destinationUrl = '/sms2-capstone' + (destinationUrl.startsWith('/') ? destinationUrl : '/' + destinationUrl);
            }

            let notificationBody = rawBody;

            // Ensure format: "[Club Name] posted a new announcement: [Announcement Title]"
            if (clubName && !notificationBody.includes(clubName)) {
                notificationBody = `${clubName} posted a new announcement: ${rawBody || notificationTitle}`;
            } else if (!notificationBody) {
                notificationBody = clubName ? `${clubName} posted a new announcement.` : 'A new club announcement has been posted.';
            }

            const tag = notifId ? ('cocurricular-' + notifId) : ('cocurricular-announcement-' + (announcementId || Date.now()));

            const notificationOptions = {
                body: notificationBody,
                icon: notif.icon || data.icon || '/sms2-capstone/images/bcp-logo-source.png',
                badge: '/sms2-capstone/images/bcp-logo-source.png',
                data: {
                    announcement_id: announcementId,
                    club_id: clubId,
                    notification_id: notifId,
                    destination_url: destinationUrl,
                    club_name: clubName,
                    title: notificationTitle,
                    message: notificationBody
                },
                tag: tag,
                renotify: true,
                requireInteraction: true
            };

            try {
                await self.registration.showNotification(notificationTitle, notificationOptions);
            } catch (showErr) {
                // Silently handle or log genuine production failure
            }
        }
    })());
});

// Handle notification click routing to Co-Curricular destination URL
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    let rawData = event.notification.data || {};
    let notifData = Object.assign({}, rawData);
    for (const key of Object.keys(rawData)) {
        if (rawData[key] && typeof rawData[key] === 'object' && rawData[key].data) {
            notifData = Object.assign({}, rawData[key].data, notifData);
        }
    }

    const announcementId = notifData.announcement_id || notifData.related_id || '';
    const clubId = notifData.club_id || '';
    const notifId = notifData.notification_id || notifData.id || '';

    let destinationUrl = notifData.destination_url || notifData.url || '';
    if (!destinationUrl && announcementId && clubId) {
        destinationUrl = '/sms2-capstone/modules/cocurricular/pages/my-club.php?club_id=' + encodeURIComponent(clubId) + '&announcement_id=' + encodeURIComponent(announcementId) + '#announcements';
    } else if (!destinationUrl && announcementId) {
        destinationUrl = '/sms2-capstone/modules/cocurricular/pages/my-club.php?announcement_id=' + encodeURIComponent(announcementId) + '#announcements';
    } else if (!destinationUrl) {
        destinationUrl = '/sms2-capstone/modules/cocurricular/pages/my-club.php';
    }

    if (!destinationUrl.startsWith('http') && !destinationUrl.startsWith('/sms2-capstone')) {
        destinationUrl = '/sms2-capstone' + (destinationUrl.startsWith('/') ? destinationUrl : '/' + destinationUrl);
    }

    notifData.destination_url = destinationUrl;

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async (windowClients) => {
            for (let i = 0; i < windowClients.length; i++) {
                const client = windowClients[i];
                if ((client.url.includes('/sms2-capstone/') || client.url.includes('/modules/')) && 'focus' in client) {
                    client.postMessage({
                        type: 'COCURRICULAR_FCM_CLICK',
                        data: notifData
                    });
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(destinationUrl);
            }
        })
    );
});
