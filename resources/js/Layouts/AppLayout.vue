<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import Button from 'primevue/button';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import AmbientBackground from '@/Components/AmbientBackground.vue';
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

// Currently Active Academic Term (School Year + Semester), shared on
// every page by HandleInertiaRequests — shown in the top header so
// it's visible no matter where in the app the user is.
const activeAcademicTerm = computed(() => page.props.activeAcademicTerm);
const activeAcademicTermLabel = computed(() => {
    const term = activeAcademicTerm.value;
    if (!term) return null;

    const schoolYear = term.school_year?.name;
    const semester = term.semester?.name;

    return [schoolYear, semester].filter(Boolean).join(' • ');
});

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
const academicSetupItems = [
    { label: 'Academic Calendar', route: 'academic-calendar', icon: 'pi pi-calendar' },
    { label: 'Academic Structure', route: 'academic-structure', icon: 'pi pi-sitemap' },
    { label: 'Curriculum', route: 'curriculums', icon: 'pi pi-book' },
    { label: 'Subjects', route: 'subjects', icon: 'pi pi-bookmark' },
];

// Resource Management — the people/rooms/sections the scheduling
// engine draws on.
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
        <header class="h-16 w-full bg-[#0B1849] shadow flex items-center justify-between px-6 fixed top-0 left-0 right-0 z-30">
            <div class="flex items-center gap-4">
                <button
                    type="button"
                    class="text-slate-300 hover:text-white text-xl leading-none"
                    @click="sidebarOpen = !sidebarOpen"
                >
                    ☰
                </button>
                <img src="/logo.png" alt="" class="h-7 w-7" />
                <span class="text-xl font-bold tracking-tight text-white">CLASSLY</span>
            </div>

            <div class="flex items-center gap-4">
                <span
                    v-if="activeAcademicTermLabel"
                    class="hidden sm:inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-emerald-300 border border-white/10"
                    title="Currently Active Academic Term"
                >
                    <i class="pi pi-calendar text-[11px]"></i>
                    {{ activeAcademicTermLabel }}
                </span>
                <ThemeToggle />
                <span v-if="user" class="text-sm text-slate-300">
                    {{ user.name }}
                </span>
                <Link :href="route('logout')" method="post" as="button">
                    <Button label="Logout" severity="secondary" outlined size="small" />
                </Link>
            </div>
        </header>

        <!-- Left Sidebar -->
        <aside
            class="fixed top-16 left-0 bottom-0 bg-[#0B1849] text-slate-200 overflow-hidden transition-all duration-200 z-20"
            :class="sidebarOpen ? 'w-[200px]' : 'w-0 overflow-hidden'"
        >
            <nav class="h-full overflow-y-auto py-3 px-2 space-y-0.5 w-[200px] text-[13px] sidebar-scroll">
                <Link
                    v-for="item in menuItems"
                    :key="item.label"
                    :href="route(item.route)"
                    class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition-colors"
                    :class="isActive(item.route)
                        ? 'bg-[#2563EB] text-white'
                        : 'text-slate-300 hover:bg-white/5 hover:text-white'"
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
                        class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition-colors"
                        :class="isActive(item.route)
                            ? 'bg-[#2563EB] text-white'
                            : 'text-slate-300 hover:bg-white/5 hover:text-white'"
                    >
                        <i :class="item.icon" class="text-[14px] w-4 text-center opacity-90"></i>
                        <span>{{ item.label }}</span>
                    </Link>
                </div>

                <!-- Academic Setup -->
                <div class="pt-2">
                    <p class="px-3 pb-0.5 text-[11px] font-semibold tracking-wider text-slate-500 uppercase">
                        Academic Setup
                    </p>
                    <Link
                        v-for="item in academicSetupItems"
                        :key="item.label"
                        :href="route(item.route)"
                        class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition-colors"
                        :class="isActive(item.route)
                            ? 'bg-[#2563EB] text-white'
                            : 'text-slate-300 hover:bg-white/5 hover:text-white'"
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
                        class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition-colors"
                        :class="isActive(item.route)
                            ? 'bg-[#2563EB] text-white'
                            : 'text-slate-300 hover:bg-white/5 hover:text-white'"
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
                        class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition-colors"
                        :class="isActive(item.route)
                            ? 'bg-[#2563EB] text-white'
                            : 'text-slate-300 hover:bg-white/5 hover:text-white'"
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
                        class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition-colors"
                        :class="isActive(item.route)
                            ? 'bg-[#2563EB] text-white'
                            : 'text-slate-300 hover:bg-white/5 hover:text-white'"
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
                        class="flex items-center gap-2.5 px-3 py-2 rounded-lg font-medium transition-colors"
                        :class="isActive(item.route)
                            ? 'bg-[#2563EB] text-white'
                            : 'text-slate-300 hover:bg-white/5 hover:text-white'"
                    >
                        <i :class="item.icon" class="text-[14px] w-4 text-center opacity-90"></i>
                        <span>{{ item.label }}</span>
                    </Link>
                </div>
            </nav>
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
</style>