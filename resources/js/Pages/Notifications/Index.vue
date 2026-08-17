<script setup>
import { Head, router, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import Button from 'primevue/button';

const props = defineProps({
    notifications: { type: Object, required: true },
    filter: { type: String, default: 'all' },
});

const items = computed(() => props.notifications.data ?? []);

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

function setFilter(value) {
    router.get(route('notifications'), { filter: value }, { preserveState: true, preserveScroll: true });
}

async function openNotification(notification) {
    const { data } = await axios.patch(route('notifications.read', notification.id));
    if (data.redirect) {
        router.visit(data.redirect);
    } else {
        router.reload({ only: ['notifications'] });
    }
}

async function markAllRead() {
    await axios.patch(route('notifications.mark-all-read'));
    router.reload({ only: ['notifications'] });
}
</script>

<template>
    <Head title="Notifications" />

    <AppLayout>
        <div class="mx-auto max-w-3xl px-4 py-6">
            <div class="mb-4 flex items-center justify-between">
                <h1 class="text-xl font-bold text-slate-800 dark:text-white">Notifications</h1>
                <Button label="Mark all read" size="small" severity="secondary" outlined @click="markAllRead" />
            </div>

            <div class="mb-4 flex gap-2">
                <Button
                    label="All"
                    size="small"
                    :severity="filter === 'all' ? 'primary' : 'secondary'"
                    :outlined="filter !== 'all'"
                    @click="setFilter('all')"
                />
                <Button
                    label="Unread"
                    size="small"
                    :severity="filter === 'unread' ? 'primary' : 'secondary'"
                    :outlined="filter !== 'unread'"
                    @click="setFilter('unread')"
                />
            </div>

            <Card>
                <template #content>
                    <div v-if="items.length === 0" class="py-10 text-center text-sm text-slate-400">
                        No notifications to show.
                    </div>
                    <button
                        v-for="notification in items"
                        :key="notification.id"
                        type="button"
                        class="flex w-full flex-col gap-1 border-b border-slate-100 py-3 text-left last:border-b-0 dark:border-slate-800"
                        :class="!notification.is_read ? 'bg-blue-50/60 dark:bg-blue-500/10 -mx-4 px-4' : ''"
                        @click="openNotification(notification)"
                    >
                        <span class="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-white">
                            <span>{{ iconFor(notification.type) }}</span>
                            <span>{{ notification.title }}</span>
                            <span v-if="!notification.is_read" class="ml-auto h-2 w-2 shrink-0 rounded-full bg-blue-500"></span>
                        </span>
                        <span class="whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ notification.message }}</span>
                        <span class="text-xs text-slate-400">{{ new Date(notification.created_at).toLocaleString() }}</span>
                    </button>
                </template>
            </Card>

            <div v-if="notifications.links?.length > 3" class="mt-4 flex flex-wrap justify-center gap-1">
                <Link
                    v-for="link in notifications.links"
                    :key="link.label"
                    :href="link.url || '#'"
                    v-html="link.label"
                    class="rounded px-3 py-1 text-xs"
                    :class="[
                        link.active ? 'bg-blue-600 text-white' : 'text-slate-500 hover:bg-slate-100 dark:hover:bg-white/10',
                        !link.url ? 'pointer-events-none opacity-40' : '',
                    ]"
                    preserve-scroll
                />
            </div>
        </div>
    </AppLayout>
</template>