<script setup>
/**
 * Reusable contextual-help icon + popover.
 *
 * Usage:
 *   <InfoPopover
 *       title="Academic Calendar"
 *       :paragraphs="['Academic Calendar manages the school\'s academic terms used by the scheduling system.']"
 *       :bullets="[
 *           'Only one academic term can be Active at a time.',
 *           'Active terms are used for day-to-day scheduling.',
 *       ]"
 *   />
 *
 * Keeps every info icon/popover in the system visually and behaviorally
 * consistent instead of hand-rolling tooltip markup on every page.
 */
import { ref } from 'vue';
import Button from 'primevue/button';
import Popover from 'primevue/popover';

const props = defineProps({
    // Bold heading shown at the top of the popover.
    title: {
        type: String,
        required: true,
    },
    // Plain-text paragraphs, rendered in order above any bullets.
    paragraphs: {
        type: Array,
        default: () => [],
    },
    // Short bullet points — used for rules, statuses, or step lists.
    bullets: {
        type: Array,
        default: () => [],
    },
    // Overrides the default aria-label / title tooltip on the icon button.
    ariaLabel: {
        type: String,
        default: null,
    },
    // Popover width class — 'w-80' (page-level) or 'w-64' (field/status-level, more compact).
    width: {
        type: String,
        default: 'w-80',
    },
});

const popover = ref();
const toggle = (event) => popover.value?.toggle(event);

// Hover-to-open/close: opening on mouseenter already worked via
// popover.show(e) below, but PrimeVue's Popover only auto-closes on
// an outside click — so without this it stays open once the cursor
// leaves both the icon and the card. A short grace delay avoids it
// flickering shut while the cursor is in the gap between icon and card.
let closeTimer = null;
const CLOSE_DELAY = 150;

function cancelClose() {
    if (closeTimer) {
        clearTimeout(closeTimer);
        closeTimer = null;
    }
}

function scheduleClose() {
    cancelClose();
    closeTimer = setTimeout(() => {
        popover.value?.hide();
    }, CLOSE_DELAY);
}
</script>

<template>
    <span class="inline-flex" @mouseenter="(e) => { cancelClose(); popover?.show(e); }" @mouseleave="scheduleClose">
        <Button
            icon="pi pi-info-circle"
            text
            rounded
            size="small"
            severity="secondary"
            class="!p-1.5 !text-slate-400 hover:!text-[#2563EB] hover:!bg-blue-50 shrink-0 transition-colors"
            :aria-label="ariaLabel || `About ${title}`"
            :title="ariaLabel || `About ${title}`"
            @click="toggle"
        />
        <Popover ref="popover" :pt="{ content: { class: '!p-0', onmouseenter: cancelClose, onmouseleave: scheduleClose } }">
            <div :class="[width, 'max-w-[85vw] overflow-hidden -m-[1px] rounded-2xl neu-info-panel']">
                <!-- Accent header strip -->
                <div class="flex items-center gap-2.5 bg-gradient-to-r from-[#2563EB] to-[#3B82F6] px-4 py-3">
                    <span class="flex items-center justify-center h-6 w-6 rounded-full bg-white/20 shrink-0">
                        <i class="pi pi-info-circle text-white text-xs"></i>
                    </span>
                    <p class="font-semibold text-white text-sm tracking-tight">{{ title }}</p>
                </div>

                <!-- Body -->
                <div class="px-4 py-3.5 space-y-2.5 text-sm text-slate-600 leading-relaxed bg-white">
                    <p v-for="(paragraph, i) in paragraphs" :key="`p-${i}`">{{ paragraph }}</p>
                    <ul v-if="bullets.length" class="space-y-1.5">
                        <li v-for="(bullet, i) in bullets" :key="`b-${i}`" class="flex items-start gap-2">
                            <span class="mt-1.5 h-1.5 w-1.5 rounded-full bg-[#2563EB]/60 shrink-0"></span>
                            <span>{{ bullet }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </Popover>
    </span>
</template>

<style scoped>
/* Popover panel itself: rounded corners + soft layered shadow so the
   accent header strip's top corners read cleanly against the card edge
   and the card lifts off the page behind it. */
:deep(.p-popover) {
    border-radius: 1rem;
    overflow: hidden;
    border: none;
    background: transparent;
    box-shadow: none;
}

.neu-info-panel {
    box-shadow:
        0 16px 40px -8px rgba(15, 23, 42, 0.35),
        0 6px 16px -4px rgba(15, 23, 42, 0.15),
        0 1px 0 rgba(255, 255, 255, 0.6) inset;
}
</style>