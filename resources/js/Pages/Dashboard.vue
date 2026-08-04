<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';

defineProps({
    roles: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const user = page.props.auth.user;
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <Card class="!rounded-2xl border border-slate-100 shadow-sm">
            <template #title>
                <span class="text-xl font-bold text-[#1E293B]">
                    Welcome, {{ user.name }}
                </span>
            </template>
            <template #content>
                <p class="text-slate-600">
                    You are signed in as
                    <span class="font-semibold text-[#2563EB]">{{ roles.join(', ') || 'No role assigned' }}</span>.
                </p>
                <p class="mt-2 text-slate-500 text-sm">
                    Scheduling modules will appear here.
                </p>
            </template>
        </Card>
    </AppLayout>
</template>