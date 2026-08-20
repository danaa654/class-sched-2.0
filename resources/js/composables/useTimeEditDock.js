import { ref } from 'vue';

/**
 * Shared "which subject's Edit Day & Time panel is docked open right
 * now" state for the Auto Schedule Complete review modal.
 *
 * Every TimeRecommendationSelector instance rendered inside that
 * modal is given the same `dockTarget` selector and imports this same
 * module-level ref (ES modules are singletons, so every instance sees
 * the exact same ref object rather than its own private copy) — that
 * is what actually guarantees only one subject's panel is ever open
 * at a time: opening one subject's Time editor just reassigns this
 * one shared id, which automatically closes whichever other row had
 * it before.
 *
 * The ordinary (non-docked) Start/End Select fields in the main
 * scheduling table never touch this file at all — they keep using
 * PrimeVue's own Popover component exactly as before, unaffected by
 * anything here.
 */
export const dockedEditSectionSubjectId = ref(null);