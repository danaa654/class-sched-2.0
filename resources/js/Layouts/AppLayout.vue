<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import Button from 'primevue/button';

const page = usePage();
const user = computed(() => page.props.auth?.user);

const sidebarOpen = ref(true);

const menuItems = [
    { label: 'Dashboard', route: 'dashboard' },
];

const userManagementItems = [
    { label: 'Users', route: 'users' },
];

const academicManagementItems = [
    { label: 'Academic Calendar', route: 'academic-calendar' },
    { label: 'Academic Structure', route: 'academic-structure' },
    { label: 'Subjects', route: 'subjects' },
    { label: 'Curriculum', route: 'curriculums' },
];

const schedulingItems = [
    { label: 'Scheduling', route: 'scheduling' },
    { label: 'Faculty', route: 'scheduling.faculty' },
    { label: 'Teaching Qualifications', route: 'scheduling.teaching-qualifications' },
    { label: 'Rooms', route: 'scheduling.rooms' },
    { label: 'Sections', route: 'scheduling.sections' },
];

const restMenuItems = [
    { label: 'Reports', route: 'reports' },
    { label: 'Settings', route: 'settings' },
];

const isActive = (routeName) => {
    try {
        return route().current(routeName);
    } catch (e) {
        return false;
    }
};
</script>

<template>
    <div class="min-h-screen bg-[#F8FAFC]">
        <!-- Top Navigation Bar -->
        <header class="h-16 w-full bg-white shadow flex items-center justify-between px-6 fixed top-0 left-0 right-0 z-30">
            <div class="flex items-center gap-4">
                <button
                    type="button"
                    class="text-slate-500 hover:text-slate-800 text-xl leading-none"
                    @click="sidebarOpen = !sidebarOpen"
                >
                    ☰
                </button>
                <span class="text-xl font-bold tracking-tight text-[#1E293B]">CLASSLY</span>
            </div>

            <div class="flex items-center gap-4">
                <span v-if="user" class="text-sm text-slate-500">
                    {{ user.name }}
                </span>
                <Link :href="route('logout')" method="post" as="button">
                    <Button label="Logout" severity="secondary" outlined size="small" />
                </Link>
            </div>
        </header>

        <!-- Left Sidebar -->
        <aside
            class="fixed top-16 left-0 bottom-0 bg-[#0F172A] text-slate-200 overflow-y-auto transition-all duration-200 z-20"
            :class="sidebarOpen ? 'w-[260px]' : 'w-0 overflow-hidden'"
        >
            <nav class="py-4 px-2 space-y-1 w-[260px]">
                <Link
                    v-for="item in menuItems"
                    :key="item.label"
                    :href="route(item.route)"
                    class="block px-4 py-2.5 rounded-lg text-sm font-medium transition-colors"
                    :class="isActive(item.route)
                        ? 'bg-[#2563EB] text-white'
                        : 'text-slate-300 hover:bg-white/5 hover:text-white'"
                >
                    {{ item.label }}
                </Link>

                <!-- User Management (Administrator only) -->
                <div class="pt-3">
                    <p class="px-4 pb-1 text-xs font-semibold tracking-wider text-slate-500 uppercase">
                        User Management
                    </p>
                    <Link
                        v-for="item in userManagementItems"
                        :key="item.label"
                        :href="route(item.route)"
                        class="block px-4 py-2.5 rounded-lg text-sm font-medium transition-colors"
                        :class="isActive(item.route)
                            ? 'bg-[#2563EB] text-white'
                            : 'text-slate-300 hover:bg-white/5 hover:text-white'"
                    >
                        {{ item.label }}
                    </Link>
                </div>

                <!-- Academic Management -->
                <div class="pt-3">
                    <p class="px-4 pb-1 text-xs font-semibold tracking-wider text-slate-500 uppercase">
                        Academic Management
                    </p>
                    <Link
                        v-for="item in academicManagementItems"
                        :key="item.label"
                        :href="route(item.route)"
                        class="block px-4 py-2.5 rounded-lg text-sm font-medium transition-colors"
                        :class="isActive(item.route)
                            ? 'bg-[#2563EB] text-white'
                            : 'text-slate-300 hover:bg-white/5 hover:text-white'"
                    >
                        {{ item.label }}
                    </Link>
                </div>

                <!-- Scheduling -->
                <div class="pt-3">
                    <p class="px-4 pb-1 text-xs font-semibold tracking-wider text-slate-500 uppercase">
                        Scheduling
                    </p>
                    <Link
                        v-for="item in schedulingItems"
                        :key="item.label"
                        :href="route(item.route)"
                        class="block px-4 py-2.5 rounded-lg text-sm font-medium transition-colors"
                        :class="isActive(item.route)
                            ? 'bg-[#2563EB] text-white'
                            : 'text-slate-300 hover:bg-white/5 hover:text-white'"
                    >
                        {{ item.label }}
                    </Link>
                </div>

                <Link
                    v-for="item in restMenuItems"
                    :key="item.label"
                    :href="route(item.route)"
                    class="block px-4 py-2.5 rounded-lg text-sm font-medium transition-colors"
                    :class="isActive(item.route)
                        ? 'bg-[#2563EB] text-white'
                        : 'text-slate-300 hover:bg-white/5 hover:text-white'"
                >
                    {{ item.label }}
                </Link>
            </nav>
        </aside>

        <!-- Main Content -->
        <main
            class="pt-16 transition-all duration-200"
            :class="sidebarOpen ? 'pl-[260px]' : 'pl-0'"
        >
            <div class="p-8">
                <slot />
            </div>
        </main>
    </div>
</template>