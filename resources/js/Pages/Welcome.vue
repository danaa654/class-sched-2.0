<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Card from 'primevue/card';
import Button from 'primevue/button';

const features = [
    {
        icon: 'pi pi-calendar',
        title: 'Scheduling',
        description: 'Manage class schedules efficiently.',
    },
    {
        icon: 'pi pi-users',
        title: 'Teachers',
        description: 'Assign instructors with conflict checking.',
    },
    {
        icon: 'pi pi-chart-bar',
        title: 'Reports',
        description: 'Generate printable scheduling reports.',
    },
];
</script>

<template>
    <Head title="Welcome" />

    <div class="min-h-screen flex flex-col bg-[#F8FAFC]">
        <!-- Top Navigation -->
        <header class="w-full border-b border-slate-200 bg-white/80 backdrop-blur sticky top-0 z-30">
            <nav class="max-w-7xl mx-auto px-6 lg:px-8 h-16 flex items-center justify-between">
                <span class="text-xl font-bold tracking-tight text-[#1E293B]">CLASSLY</span>

                <Link :href="route('login')">
                    <Button label="Login" class="!bg-[#2563EB] !border-[#2563EB]" />
                </Link>
            </nav>
        </header>

        <!-- Hero Section -->
        <section class="max-w-7xl mx-auto w-full px-6 lg:px-8 py-16 lg:py-24">
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <!-- Left: Heading -->
                <div>
                    <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-[#1E293B] leading-tight">
                        CLASSLY
                    </h1>
                    <p class="mt-2 text-xl sm:text-2xl font-semibold text-[#2563EB]">
                        Class Scheduling and Management System
                    </p>
                    <p class="mt-6 text-lg text-slate-600 max-w-xl">
                        A centralized platform for managing class schedules, teachers, rooms, and academic planning.
                    </p>

                    <div class="mt-8 flex flex-wrap gap-4">
                        <Link :href="route('login')">
                            <Button label="Login" size="large" class="!bg-[#2563EB] !border-[#2563EB] !px-6" />
                        </Link>
                        <Button
                            label="Learn More"
                            size="large"
                            severity="secondary"
                            outlined
                            class="!text-[#1E293B] !border-slate-300 !px-6"
                        />
                    </div>
                </div>

                <!-- Right: Illustration placeholder -->
                <div class="flex justify-center lg:justify-end">
                    <Card class="w-full max-w-md shadow-lg border border-slate-100 !rounded-2xl">
                        <template #content>
                            <div class="flex flex-col items-center justify-center py-12 px-6 text-center">
                                <div
                                    class="w-28 h-28 rounded-full flex items-center justify-center mb-6"
                                    style="background-color: rgba(37, 99, 235, 0.1);"
                                >
                                    <i class="pi pi-graduation-cap text-6xl" style="color: #2563EB;"></i>
                                </div>
                                <p class="text-slate-500 text-sm">
                                    Academic scheduling made simple, organized, and conflict-free.
                                </p>
                            </div>
                        </template>
                    </Card>
                </div>
            </div>
        </section>

        <!-- Feature Cards -->
        <section class="max-w-7xl mx-auto w-full px-6 lg:px-8 pb-16 lg:pb-24">
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <Card
                    v-for="feature in features"
                    :key="feature.title"
                    class="shadow-sm hover:shadow-md transition-shadow border border-slate-100 !rounded-xl"
                >
                    <template #content>
                        <div class="p-2">
                            <div
                                class="w-12 h-12 rounded-lg flex items-center justify-center mb-4"
                                style="background-color: rgba(37, 99, 235, 0.1);"
                            >
                                <i :class="feature.icon" class="text-2xl" style="color: #2563EB;"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-[#1E293B] mb-1">{{ feature.title }}</h3>
                            <p class="text-slate-500 text-sm">{{ feature.description }}</p>
                        </div>
                    </template>
                </Card>
            </div>
        </section>

        <!-- Footer -->
        <footer class="mt-auto border-t border-slate-200 bg-white">
            <div class="max-w-7xl mx-auto px-6 lg:px-8 py-6 text-center text-sm text-slate-500">
                CLASSLY &copy; 2026
            </div>
        </footer>
    </div>
</template>