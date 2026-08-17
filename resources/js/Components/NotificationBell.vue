<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';

const page = usePage();

// Seeded from HandleInertiaRequests' `unreadNotificationCount` share
// so the badge is correct on first paint, before the first poll
// response comes back (spec Section 11).
const unreadCount = ref(page.props.unreadNotificationCount ?? 0);
const notifications = ref([]);
const isOpen = ref(false);
const isLoading = ref(false);
let pollTimer = null;

const hasUnread = computed(() => unreadCount.value > 0);

/**
 * Lightweight poll — unread-count only, every 20s (spec Section 11:
 * "every 10-30 seconds", "must be lightweight"). The full list is
 * only fetched when the dropdown is actually opened, not on every
 * poll tick.
 */
async function pollUnreadCount() {
    try {
        const { data } = await axios.get(route('notifications.unread-count'));
        unreadCount.value = data.unread_count;
    } catch (e) {
        // A failed poll tick is not worth surfacing to the user —
        // it'll just retry in another 20s.
    }
}

async function loadRecent() {
    isLoading.value = true;
    try {
        const { data } = await axios.get(route('notifications.recent'));
        notifications.value = data.notifications;
        unreadCount.value = data.unread_count;
    } finally {
        isLoading.value = false;
    }
}

function toggleOpen() {
    isOpen.value = !isOpen.value;
    if (isOpen.value) {
        loadRecent();
    }
}

function closeDropdown() {
    isOpen.value = false;
}

async function markAllRead() {
    await axios.patch(route('notifications.mark-all-read'));
    unreadCount.value = 0;
    notifications.value = notifications.value.map((n) => ({ ...n, is_read: true }));
}

/**
 * Marks read then navigates via the route the backend resolved from
 * the notification's Section id (spec Section 14) — never a stored
 * URL, and never done unless the mark-read call itself succeeds.
 */
async function openNotification(notification) {
    closeDropdown();
    const { data } = await axios.patch(route('notifications.read', notification.id));
    unreadCount.value = Math.max(0, unreadCount.value - (notification.is_read ? 0 : 1));
    if (data.redirect) {
        router.visit(data.redirect);
    }
}

const iconFor = (type) => ({
    SCHEDULE_FINALIZED: '🔒',
    SCHEDULE_UNLOCKED: '🔓',
    SCHEDULE_UPDATED: '✏️',
    SCHEDULE_CONFLICT: '⚠️',
    CONCURRENCY_CONFLICT: '⚡',
    SECTION_CREATED: '➕',
    SUBJECT_ADDED: '📘',
    SUBJECT_REMOVED: '📕',
    AUTO_SCHEDULE_COMPLETED: '✅',
    AUTO_SCHEDULE_NEEDS_ATTENTION: '🛠️',
}[type] ?? '🔔');

const PRIORITY_COLORS = {
    INFO: 'bg-slate-400',
    IMPORTANT: 'bg-blue-500',
    WARNING: 'bg-amber-500',
    CRITICAL: 'bg-red-600',
};
const priorityColor = (priority) => PRIORITY_COLORS[priority] ?? PRIORITY_COLORS.IMPORTANT;

function timeAgo(dateString) {
    const seconds = Math.floor((Date.now() - new Date(dateString).getTime()) / 1000);
    if (seconds < 60) return 'just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`;
    const days = Math.floor(hours / 24);
    return `${days} day${days === 1 ? '' : 's'} ago`;
}

function onClickOutside(event) {
    if (!event.target.closest('[data-notification-bell]')) {
        closeDropdown();
    }
}

onMounted(() => {
    pollTimer = setInterval(pollUnreadCount, 20000);
    document.addEventListener('click', onClickOutside);
});

onUnmounted(() => {
    if (pollTimer) clearInterval(pollTimer);
    document.removeEventListener('click', onClickOutside);
});
</script>

<template>
    <div class="relative" data-notification-bell>
        <button
            type="button"
            class="relative flex h-10 items-center justify-center gap-1 rounded-full border border-white/20 bg-white/10 px-3 text-white transition-colors hover:bg-white/20"
            :aria-label="hasUnread ? `${unreadCount} unread notifications` : 'Notifications'"
            @click="toggleOpen"
        >
            <i class="pi pi-bell text-base"></i>
            <span v-if="hasUnread" class="text-xs font-semibold">{{ unreadCount > 99 ? '99+' : unreadCount }}</span>
        </button>

        <div
            v-if="isOpen"
            class="absolute right-0 z-50 mt-2 w-96 max-w-[90vw] overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-[#0F1B4C]"
        >
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-700">
                <span class="text-sm font-semibold text-slate-800 dark:text-white">Notifications</span>
                <button
                    v-if="hasUnread"
                    type="button"
                    class="text-xs font-medium text-blue-600 hover:underline dark:text-blue-300"
                    @click="markAllRead"
                >
                    Mark all read
                </button>
            </div>

            <div class="max-h-96 overflow-y-auto">
                <div v-if="isLoading" class="px-4 py-6 text-center text-sm text-slate-400">
                    Loading…
                </div>
                <div v-else-if="notifications.length === 0" class="px-4 py-6 text-center text-sm text-slate-400">
                    No notifications yet.
                </div>
                <button
                    v-for="notification in notifications"
                    :key="notification.id"
                    type="button"
                    class="flex w-full flex-col gap-0.5 border-b border-slate-100 px-4 py-3 text-left transition-colors last:border-b-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-white/5"
                    :class="!notification.is_read ? 'bg-blue-50/60 dark:bg-blue-500/10' : ''"
                    @click="openNotification(notification)"
                >
                    <span class="flex items-center gap-1.5 text-sm font-semibold text-slate-800 dark:text-white">
                        <span>{{ iconFor(notification.type) }}</span>
                        <span>{{ notification.title }}</span>
                        <span
                            v-if="notification.priority && notification.priority !== 'IMPORTANT'"
                            class="h-1.5 w-1.5 shrink-0 rounded-full"
                            :class="priorityColor(notification.priority)"
                            :title="notification.priority"
                        ></span>
                        <span v-if="!notification.is_read" class="ml-auto h-2 w-2 shrink-0 rounded-full bg-blue-500"></span>
                    </span>
                    <span class="whitespace-pre-line text-xs text-slate-600 dark:text-slate-300">{{ notification.message }}</span>
                    <span class="text-[11px] text-slate-400">{{ timeAgo(notification.created_at) }}</span>
                </button>
            </div>

            <div class="border-t border-slate-200 px-4 py-2 text-center dark:border-slate-700">
                <a :href="route('notifications')" class="text-xs font-medium text-blue-600 hover:underline dark:text-blue-300">
                    View all notifications
                </a>
            </div>
        </div>
    </div>
</template>