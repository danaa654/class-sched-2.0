<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
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

// Shared on every page by HandleInertiaRequests — the Academic Term
// currently marked Active, if any. Scheduling can't run without one,
// so the Dashboard nudges Admins/Registrars to set it up.
const activeAcademicTerm = computed(() => page.props.activeAcademicTerm);
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <!-- No Active Academic Term — scheduling can't run yet -->
        <Link
            v-if="!activeAcademicTerm"
            :href="route('academic-calendar')"
            class="mb-6 flex items-start gap-4 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 transition-colors hover:bg-amber-100 group"
        >
            <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-amber-100 text-amber-600 group-hover:bg-amber-200">
                <i class="pi pi-exclamation-triangle text-lg"></i>
            </span>
            <div class="flex-1">
                <p class="font-semibold text-amber-900">No Active Academic Term</p>
                <p class="mt-0.5 text-sm text-amber-800">
                    Admin and Registrar need to set the Active Academic Term (School Year, Semester, and Scheduling Preferences)
                    before scheduling can start.
                </p>
            </div>
            <span class="flex-none self-center text-sm font-semibold text-amber-700 group-hover:text-amber-900 whitespace-nowrap">
                Set Academic Term
                <i class="pi pi-arrow-right ml-1 text-xs"></i>
            </span>
        </Link>

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