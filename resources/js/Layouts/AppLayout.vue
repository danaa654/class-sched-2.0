<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import AmbientBackground from '@/Components/AmbientBackground.vue';
import TermSwitcher from '@/Components/TermSwitcher.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const page = usePage();
const user = computed(() => page.props.auth?.user);

// User Management (create/edit accounts) is Administrator only —
// Registrar, Dean, OIC and Assistant Dean never see this section, since
// the backend also blocks them from those routes directly.
const authRoles = computed(() => page.props.auth?.roles ?? []);
const isAdministrator = computed(() => authRoles.value.includes('Administrator'));

// Coarse module abilities shared by HandleInertiaRequests — UI
// convenience only. The backend independently re-checks every one of
// these on every request (Policies + Gate::define), so this list is
// never the actual security boundary, only what decides whether a
// link is worth showing.
const can = computed(() => page.props.auth?.can ?? {});

const sidebarOpen = ref(true);

const menuItems = [
    { label: 'Dashboard', route: 'dashboard', icon: 'pi pi-home' },
];

const userManagementItems = [
    { label: 'Users', route: 'users', icon: 'pi pi-users' },
];

// Academic Setup — everything that defines the academic environment
// (school year/semester/rules, colleges/departments/programs, the
// curriculum map, and subject records) before scheduling can begin.
// Academic Calendar/Structure/Curriculum are Admin/Registrar-only
// (spec Section 21); Subjects stays visible to every scheduling role
// since Assistant Dean/Dean/OIC all need to browse it (write access
// is enforced per-row by the backend regardless).
const academicSetupItems = computed(() => [
    ...(can.value.manageAcademicCalendar ? [{ label: 'Academic Calendar', route: 'academic-calendar', icon: 'pi pi-calendar' }] : []),
    ...(can.value.manageAcademicStructure ? [{ label: 'Academic Structure', route: 'academic-structure', icon: 'pi pi-sitemap' }] : []),
    ...(can.value.manageCurriculum ? [{ label: 'Curriculum', route: 'curriculums', icon: 'pi pi-book' }] : []),
    { label: 'Subjects', route: 'subjects', icon: 'pi pi-bookmark' },
]);

// Resource Management — the people/rooms/sections the scheduling
// engine draws on. Visible to every Scheduling-side role; write
// access within each page is enforced per-record by the backend
// (Faculty/Room/Section Policies) and reflected via `can_manage`
// flags the controllers attach to each row.
const resourceManagementItems = [
    { label: 'Faculty', route: 'scheduling.faculty', icon: 'pi pi-user' },
    { label: 'Rooms', route: 'scheduling.rooms', icon: 'pi pi-building' },
    { label: 'Sections', route: 'scheduling.sections', icon: 'pi pi-th-large' },
];

// Scheduling — the control center for generating and monitoring
// schedules. (Faculty/Rooms/Sections moved to Resource Management
// above; actual schedule editing still lives under Sections >
// Section Subjects, reached from the Sections page.)
const schedulingItems = [
    { label: 'Scheduling Dashboard', route: 'scheduling', icon: 'pi pi-calendar-plus' },
];

const reportsItems = [
    { label: 'Reports', route: 'reports', icon: 'pi pi-chart-bar' },
];

const systemItems = [
    { label: 'Settings', route: 'settings', icon: 'pi pi-cog' },
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
    <div class="relative min-h-screen overflow-hidden transition-colors duration-300" :class="isDark ? 'bg-[#0B1120]' : 'bg-[#F8FAFC]'">
        <AmbientBackground :is-dark="isDark" />
        <!-- Top Navigation Bar -->
        <header class="neu-navy-surface h-16 w-full flex items-center justify-between px-6 fixed top-0 left-0 right-0 z-30 shadow-[0_4px_12px_rgba(0,0,0,0.35)]">
            <div class="flex items-center gap-4">
                <button
                    type="button"
                    class="neu-navy-raised flex h-9 w-9 items-center justify-center rounded-full text-slate-300 hover:text-white text-lg leading-none"
                    @click="sidebarOpen = !sidebarOpen"
                >
                    ☰
                </button>
                <img src="/logo.png" alt="" class="h-7 w-7" />
                <span class="text-xl font-bold tracking-tight text-white">CLASSLY</span>
            </div>

            <div class="flex items-center gap-5">
                <TermSwitcher />
                <span class="neu-navy-raised flex h-9 w-9 shrink-0 items-center justify-center rounded-full">
                    <NotificationBell v-if="user" />
                </span>
                <ThemeToggle />
            </div>
        </header>

        <!-- Left Sidebar -->
        <aside
            class="neu-navy-surface fixed top-16 left-0 bottom-0 flex flex-col text-slate-200 overflow-hidden transition-all duration-200 z-20 shadow-[4px_0_12px_rgba(0,0,0,0.25)]"
            :class="sidebarOpen ? 'w-[200px]' : 'w-0 overflow-hidden'"
        >
            <nav class="flex-1 overflow-y-auto py-3 px-2 space-y-0.5 w-[200px] text-[13px] sidebar-scroll">
                <Link
                    v-for="item in menuItems"
                    :key="item.label"
                    :href="route(item.route)"
                    class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition-all"
                    :class="isActive(item.route)
                        ? 'neu-navy-active text-white'
                        : 'text-slate-300 hover:neu-navy-raised hover:text-white'"
                >
                    <i :class="item.icon" class="text-[14px] w-4 text-center opacity-90"></i>
                    <span>{{ item.label }}</span>
                </Link>

                <!-- User Management (Administrator only) -->
                <div v-if="isAdministrator" class="pt-2">
                    <p class="px-3 pb-0.5 text-[11px] font-semibold tracking-wider text-slate-500 uppercase">
                        User Management
                    </p>
                    <Link
                        v-for="item in userManagementItems"
                        :key="item.label"
                        :href="route(item.route)"
                        class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition-all"
                        :class="isActive(item.route)
                            ? 'neu-navy-active text-white'
                            : 'text-slate-300 hover:neu-navy-raised hover:text-white'"
                    >
                        <i :class="item.icon" class="text-[14px] w-4 text-center opacity-90"></i>
                        <span>{{ item.label }}</span>
                    </Link>
                </div>

                <!-- Academic Setup -->
                <div v-if="academicSetupItems.length" class="pt-2">
                    <p class="px-3 pb-0.5 text-[11px] font-semibold tracking-wider text-slate-500 uppercase">
                        Academic Setup
                    </p>
                    <Link
                        v-for="item in academicSetupItems"
                        :key="item.label"
                        :href="route(item.route)"
                        class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition-all"
                        :class="isActive(item.route)
                            ? 'neu-navy-active text-white'
                            : 'text-slate-300 hover:neu-navy-raised hover:text-white'"
                    >
                        <i :class="item.icon" class="text-[14px] w-4 text-center opacity-90"></i>
                        <span>{{ item.label }}</span>
                    </Link>
                </div>

                <!-- Resource Management -->
                <div class="pt-2">
                    <p class="px-3 pb-0.5 text-[11px] font-semibold tracking-wider text-slate-500 uppercase">
                        Resource Management
                    </p>
                    <Link
                        v-for="item in resourceManagementItems"
                        :key="item.label"
                        :href="route(item.route)"
                        class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition-all"
                        :class="isActive(item.route)
                            ? 'neu-navy-active text-white'
                            : 'text-slate-300 hover:neu-navy-raised hover:text-white'"
                    >
                        <i :class="item.icon" class="text-[14px] w-4 text-center opacity-90"></i>
                        <span>{{ item.label }}</span>
                    </Link>
                </div>

                <!-- Scheduling -->
                <div class="pt-2">
                    <p class="px-3 pb-0.5 text-[11px] font-semibold tracking-wider text-slate-500 uppercase">
                        Scheduling
                    </p>
                    <Link
                        v-for="item in schedulingItems"
                        :key="item.label"
                        :href="route(item.route)"
                        class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition-all"
                        :class="isActive(item.route)
                            ? 'neu-navy-active text-white'
                            : 'text-slate-300 hover:neu-navy-raised hover:text-white'"
                    >
                        <i :class="item.icon" class="text-[14px] w-4 text-center opacity-90"></i>
                        <span>{{ item.label }}</span>
                    </Link>
                </div>

                <!-- Reports -->
                <div class="pt-2">
                    <p class="px-3 pb-0.5 text-[11px] font-semibold tracking-wider text-slate-500 uppercase">
                        Reports
                    </p>
                    <Link
                        v-for="item in reportsItems"
                        :key="item.label"
                        :href="route(item.route)"
                        class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition-all"
                        :class="isActive(item.route)
                            ? 'neu-navy-active text-white'
                            : 'text-slate-300 hover:neu-navy-raised hover:text-white'"
                    >
                        <i :class="item.icon" class="text-[14px] w-4 text-center opacity-90"></i>
                        <span>{{ item.label }}</span>
                    </Link>
                </div>

                <!-- System -->
                <div class="pt-2">
                    <p class="px-3 pb-0.5 text-[11px] font-semibold tracking-wider text-slate-500 uppercase">
                        System
                    </p>
                    <Link
                        v-for="item in systemItems"
                        :key="item.label"
                        :href="route(item.route)"
                        class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition-all"
                        :class="isActive(item.route)
                            ? 'neu-navy-active text-white'
                            : 'text-slate-300 hover:neu-navy-raised hover:text-white'"
                    >
                        <i :class="item.icon" class="text-[14px] w-4 text-center opacity-90"></i>
                        <span>{{ item.label }}</span>
                    </Link>
                </div>
            </nav>

            <!-- User / Logout footer -->
            <div v-if="user" class="w-[200px] shrink-0 px-2 pb-3 pt-2">
                <div class="neu-navy-inset neu-user-card flex items-center gap-2.5 rounded-xl px-2.5 py-2.5 transition-all duration-200">
                    <span class="neu-navy-raised flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white">
                        {{ user.name?.charAt(0)?.toUpperCase() }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[13px] font-semibold text-white">{{ user.name }}</p>
                        <p v-if="authRoles.length" class="truncate text-[11px] text-slate-400">{{ authRoles.join(', ') }}</p>
                    </div>
                </div>
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="neu-navy-raised neu-logout-btn mt-2 flex w-full items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-[13px] font-medium text-slate-300 transition-all duration-200 hover:text-white"
                >
                    <i class="pi pi-sign-out text-[12px]"></i>
                    <span>Logout</span>
                </Link>
            </div>
        </aside>

        <!-- Main Content -->
        <main
            class="relative z-10 pt-16 transition-all duration-200"
            :class="sidebarOpen ? 'pl-[200px]' : 'pl-0'"
        >
            <div class="p-8" :class="isDark ? 'text-slate-100' : ''">
                <slot :is-dark="isDark" />
            </div>
        </main>
    </div>
</template>

<style scoped>
/* Keep the sidebar scrollable (so nav items aren't cut off on shorter
   screens) but hide the visible scrollbar track/thumb for a cleaner look. */
.sidebar-scroll {
    scrollbar-width: none; /* Firefox */
    -ms-overflow-style: none; /* IE/Edge legacy */
}
.sidebar-scroll::-webkit-scrollbar {
    display: none; /* Chrome/Safari/Edge Chromium */
}

/* Sidebar footer hover glows */
.neu-user-card:hover {
    box-shadow:
        inset 3px 3px 7px var(--neu-navy-shadow-2),
        inset -2px -2px 6px var(--neu-navy-shadow-1),
        0 0 12px 1px rgba(16, 185, 129, 0.45);
}

.neu-logout-btn:hover {
    background: linear-gradient(145deg, #ef4444, #dc2626);
    box-shadow:
        4px 4px 10px rgba(0, 0, 0, 0.5),
        -3px -3px 8px rgba(255, 255, 255, 0.05),
        0 0 14px 1px rgba(239, 68, 68, 0.55);
    color: #ffffff;
}
</style>