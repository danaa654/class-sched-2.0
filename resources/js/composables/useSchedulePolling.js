import { ref, watch, onBeforeUnmount } from 'vue';

/**
 * REAL-TIME SCHEDULE CHANGE DETECTION — lightweight polling composable.
 *
 * Frontend/UX layer ONLY. Periodically hits the lightweight
 * `scheduling.section-subjects.version` endpoint (returns just
 * { schedule_version, updated_at }, never the schedule itself) and
 * compares it against the version the current page/session last
 * knew about. When they diverge, marks the view `isStale` so the
 * caller can show a non-blocking "Schedule updated by another user"
 * notice and disable/warn on Save — without ever auto-reloading the
 * page or discarding in-progress edits.
 *
 * The backend transaction + expected_schedule_version check
 * (ScheduleConflictService::checkSectionVersion(), HTTP 409
 * SCHEDULE_VERSION_CONFLICT) remains the ONLY authoritative
 * concurrency guard. This composable never blocks or approves a
 * save by itself — it only informs the UI early.
 *
 * Usage:
 *   const polling = useSchedulePolling({
 *       sectionId: () => props.section.id,
 *       initialVersion: () => props.scheduleVersion,
 *       fetchVersion: (sectionId) => fetch(route('scheduling.section-subjects.version', sectionId), { headers: { Accept: 'application/json' } }).then(r => r.json()),
 *   });
 *
 *   polling.currentVersion   // ref<number> — what the UI is currently showing
 *   polling.isStale          // ref<boolean> — backend has moved ahead
 *   polling.checkError       // ref<boolean> — last poll failed (soft, non-blocking)
 *   polling.refresh(applyFn) // discard-and-resync helper
 *   polling.acceptVersion(n) // call after a successful save with the server's new version
 *   polling.pause() / resume()
 */
export function useSchedulePolling({
    sectionId,
    initialVersion,
    fetchVersion,
    intervalMs = 15000,
    hiddenIntervalMs = 60000, // reduced-frequency background check while tab is hidden; still far cheaper than foreground
}) {
    const currentVersion = ref(typeof initialVersion === 'function' ? initialVersion() : initialVersion ?? 1);
    const backendVersion = ref(currentVersion.value);
    const isStale = ref(false);
    const checking = ref(false);
    const checkError = ref(false);
    const updatedAt = ref(null);

    let timerId = null;
    let destroyed = false;
    let activeSectionId = typeof sectionId === 'function' ? sectionId() : sectionId;

    const resolveSectionId = () => (typeof sectionId === 'function' ? sectionId() : sectionId);

    const clearTimer = () => {
        // AVOID MULTIPLE POLLING TIMERS — only one setTimeout chain is
        // ever "live" at once; clearing before every re-schedule
        // guarantees that, even across rapid section switches.
        if (timerId) {
            clearTimeout(timerId);
            timerId = null;
        }
    };

    const scheduleNext = () => {
        clearTimer();
        if (destroyed) return;
        const delay = document.visibilityState === 'hidden' ? hiddenIntervalMs : intervalMs;
        timerId = setTimeout(tick, delay);
    };

    const tick = async () => {
        // Don't poll a tab that's fully hidden as often — 15s active,
        // much slower in the background — but still keep a slow
        // heartbeat so a long-hidden tab isn't wildly out of date the
        // moment it's revisited.
        const id = resolveSectionId();
        if (!id) {
            scheduleNext();
            return;
        }

        checking.value = true;
        try {
            const data = await fetchVersion(id);
            checkError.value = false;
            if (typeof data?.schedule_version === 'number') {
                backendVersion.value = data.schedule_version;
                updatedAt.value = data.updated_at ?? null;
                if (backendVersion.value !== currentVersion.value) {
                    isStale.value = true;
                }
            }
        } catch (e) {
            // ERROR HANDLING — a failed poll must never fabricate a
            // false "schedule changed" notice. Log-and-retry only;
            // the next successful save is still protected server-side
            // regardless of polling health.
            checkError.value = true;
        } finally {
            checking.value = false;
            scheduleNext();
        }
    };

    /** Run an out-of-cycle version check immediately (e.g. on tab focus). */
    const checkNow = () => {
        clearTimer();
        tick();
    };

    const onVisibilityChange = () => {
        if (document.visibilityState === 'visible') {
            // Returning to the tab: check immediately, then resume the
            // normal cadence.
            checkNow();
        } else {
            // Going hidden: drop the in-flight fast timer and switch to
            // the slow background cadence.
            scheduleNext();
        }
    };

    const start = () => {
        if (destroyed) return;
        document.addEventListener('visibilitychange', onVisibilityChange);
        scheduleNext();
    };

    const stop = () => {
        clearTimer();
        document.removeEventListener('visibilitychange', onVisibilityChange);
    };

    /**
     * Call this after the caller has actually refreshed the on-screen
     * schedule with fresh data (or right after a successful save,
     * which already returns the new version). Clears the stale flag
     * and re-syncs currentVersion so the next poll compares against
     * the right baseline.
     */
    const acceptVersion = (version) => {
        if (typeof version === 'number') {
            currentVersion.value = version;
            backendVersion.value = version;
        } else {
            currentVersion.value = backendVersion.value;
        }
        isStale.value = false;
    };

    /**
     * Re-point polling at a different Section (the header's Section
     * switcher, or any other in-place navigation) without leaking a
     * second timer. Safe to call even if the id hasn't changed.
     */
    const resetForSection = (newVersion) => {
        clearTimer();
        isStale.value = false;
        checkError.value = false;
        acceptVersion(newVersion);
        start();
    };

    watch(
        resolveSectionId,
        (newId, oldId) => {
            if (newId === oldId) return;
            activeSectionId = newId;
            // STOP the previous section's timer before anything else so
            // a fast section switch never leaves two timers running or
            // compares one section's version against another's.
            clearTimer();
        },
    );

    onBeforeUnmount(() => {
        destroyed = true;
        stop();
    });

    return {
        currentVersion,
        backendVersion,
        isStale,
        checking,
        checkError,
        updatedAt,
        start,
        stop,
        checkNow,
        acceptVersion,
        resetForSection,
    };
}