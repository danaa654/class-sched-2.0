/**
 * v-uppercase
 *
 * Forces a text input to uppercase as the user types. Works on plain
 * <input> elements and on PrimeVue components whose root element is
 * (or wraps) an <input> — e.g. InputText — so it can be dropped
 * directly onto those components without extra markup.
 *
 * Applied per-field rather than globally: it's meant for name/code
 * fields (Employee ID, First Name, Last Name, Suffix, etc.), not for
 * fields where case matters — Email and Password are deliberately
 * left alone wherever this is used.
 *
 * Listens on the native 'input' event rather than patching v-model,
 * so it plays correctly with whatever v-model binding is already on
 * the element: the browser fires 'input' -> v-model's own listener
 * updates the bound value first -> this directive then upper-cases
 * the DOM value and re-dispatches 'input' so v-model picks up the
 * uppercased value too. The caret position is preserved so typing
 * mid-string doesn't jump the cursor to the end.
 */
const upperCaseInPlace = (input) => {
    const start = input.selectionStart;
    const end = input.selectionEnd;
    const upper = input.value.toUpperCase();

    if (input.value === upper) {
        return;
    }

    input.value = upper;
    input.dispatchEvent(new Event('input', { bubbles: true }));

    // Re-apply the caret position after the synthetic 'input' event's
    // listeners (including v-model's) have run.
    requestAnimationFrame(() => {
        if (document.activeElement === input) {
            input.setSelectionRange(start, end);
        }
    });
};

const resolveInputElement = (el) => (el.tagName === 'INPUT' ? el : el.querySelector('input'));

export default {
    mounted(el) {
        const input = resolveInputElement(el);
        if (!input) {
            return;
        }

        const handler = () => upperCaseInPlace(input);
        input.addEventListener('input', handler);
        el.__uppercaseHandler = handler;
        el.__uppercaseInput = input;

        // Cover values already present when the directive is applied
        // (e.g. editing an existing record whose stored value happens
        // to be mixed/lower case).
        if (input.value) {
            upperCaseInPlace(input);
        }
    },
    updated(el) {
        // The directive may be applied to a component whose internal
        // <input> is re-created (rare, but cheap to guard against).
        const input = resolveInputElement(el);
        if (input && input !== el.__uppercaseInput && el.__uppercaseHandler) {
            el.__uppercaseInput?.removeEventListener('input', el.__uppercaseHandler);
            input.addEventListener('input', el.__uppercaseHandler);
            el.__uppercaseInput = input;
        }
    },
    beforeUnmount(el) {
        if (el.__uppercaseInput && el.__uppercaseHandler) {
            el.__uppercaseInput.removeEventListener('input', el.__uppercaseHandler);
        }
        delete el.__uppercaseHandler;
        delete el.__uppercaseInput;
    },
};