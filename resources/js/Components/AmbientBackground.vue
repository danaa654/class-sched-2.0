<script setup>
// Shared decorative background: timetable grid + ambient glow blobs,
// plus dark-mode fireflies / light-mode grid-lights. Used behind the
// public Welcome page and behind the authenticated app shell so both
// modes feel like the same product.
defineProps({
    isDark: { type: Boolean, default: false },
});

// Fireflies: randomized so each page load feels alive rather than a
// fixed, repeating pattern. Position/size/timing vary per particle.
const fireflies = Array.from({ length: 22 }, (_, i) => ({
    id: i,
    left: Math.random() * 100,
    top: Math.random() * 100,
    size: 2 + Math.random() * 3,
    duration: 7 + Math.random() * 8,
    delay: -Math.random() * 12,
    drift: (Math.random() > 0.5 ? 1 : -1) * (20 + Math.random() * 40),
}));

// Grid lights: light-mode counterpart to the dark-mode fireflies.
// Small glowing streaks that travel along the timetable grid lines
// (horizontal or vertical) rather than floating freely, echoing the
// grid backdrop instead of ignoring it. Positions snap to the 56px
// grid so each streak rides an actual line. Colors cycle through the
// brand palette so the effect doesn't read as flat, single-tone blue.
const GRID_LIGHT_COLORS = ['#2563EB', '#38BDF8', '#7C3AED', '#E11D2E', '#F59E0B', '#10B981'];
const GRID_SIZE = 56;
const gridLights = Array.from({ length: 14 }, (_, i) => {
    const axis = Math.random() > 0.5 ? 'x' : 'y';
    return {
        id: i,
        axis,
        line: Math.round(Math.random() * 24) * GRID_SIZE + 'px',
        length: 60 + Math.random() * 60,
        duration: 5 + Math.random() * 6,
        delay: -Math.random() * 10,
        color: GRID_LIGHT_COLORS[i % GRID_LIGHT_COLORS.length],
    };
});
</script>

<template>
    <div class="pointer-events-none absolute inset-0 overflow-hidden">
        <!-- Timetable grid backdrop: faint ruled lines evoking a schedule grid -->
        <div
            class="pointer-events-none absolute inset-0"
            :class="isDark ? 'opacity-[0.35]' : 'opacity-100'"
            :style="{
                backgroundImage: isDark
                    ? 'linear-gradient(rgba(148,163,184,0.09) 1px, transparent 1px), linear-gradient(90deg, rgba(148,163,184,0.09) 1px, transparent 1px)'
                    : 'linear-gradient(rgba(30,41,59,0.12) 1px, transparent 1px), linear-gradient(90deg, rgba(30,41,59,0.12) 1px, transparent 1px)',
                backgroundSize: '56px 56px',
                maskImage: 'radial-gradient(ellipse 80% 60% at 50% 0%, black 40%, transparent 90%)',
                WebkitMaskImage: 'radial-gradient(ellipse 80% 60% at 50% 0%, black 40%, transparent 90%)',
            }"
        />

        <!-- Ambient glow field -->
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
            <div
                class="absolute -top-40 -left-32 h-[28rem] w-[28rem] rounded-full blur-[110px] motion-safe:animate-[pulse_9s_ease-in-out_infinite]"
                :class="isDark ? 'bg-[#2563EB]/30' : 'bg-[#2563EB]/20'"
            />
            <div
                class="absolute top-1/4 -right-40 h-[32rem] w-[32rem] rounded-full blur-[120px] motion-safe:animate-[pulse_11s_ease-in-out_infinite]"
                :class="isDark ? 'bg-[#E11D2E]/20' : 'bg-[#E11D2E]/10'"
            />
            <div
                class="absolute bottom-[-10rem] left-1/3 h-[26rem] w-[26rem] rounded-full blur-[110px]"
                :class="isDark ? 'bg-[#38BDF8]/20' : 'bg-[#38BDF8]/10'"
            />
        </div>

        <!-- Fireflies: dark mode only, drifting points of light -->
        <div v-if="isDark" class="pointer-events-none absolute inset-0 overflow-hidden">
            <span
                v-for="fly in fireflies"
                :key="fly.id"
                class="firefly"
                :style="{
                    left: fly.left + '%',
                    top: fly.top + '%',
                    width: fly.size + 'px',
                    height: fly.size + 'px',
                    animationDuration: fly.duration + 's',
                    animationDelay: fly.delay + 's',
                    '--drift': fly.drift + 'px',
                }"
            />
        </div>

        <!-- Grid lights: light mode only, streaks traveling along the grid lines -->
        <div v-else class="pointer-events-none absolute inset-0 overflow-hidden">
            <span
                v-for="light in gridLights"
                :key="light.id"
                class="grid-light"
                :class="light.axis === 'x' ? 'grid-light--x' : 'grid-light--y'"
                :style="light.axis === 'x'
                    ? { top: light.line, width: light.length + 'px', animationDuration: light.duration + 's', animationDelay: light.delay + 's', '--glow-color': light.color }
                    : { left: light.line, height: light.length + 'px', animationDuration: light.duration + 's', animationDelay: light.delay + 's', '--glow-color': light.color }"
            />
        </div>
    </div>
</template>