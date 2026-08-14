import { ref, watch } from 'vue';

function getInitialTheme() {
    if (typeof window === 'undefined') {
        return 'dark';
    }

    const stored = localStorage.getItem('classly-theme');
    if (stored === 'light' || stored === 'dark') {
        return stored;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

// Module-level singleton so every page/component sharing this composable
// reads and writes the same theme state.
const theme = ref(getInitialTheme());

watch(
    theme,
    (value) => {
        if (typeof window !== 'undefined') {
            localStorage.setItem('classly-theme', value);
            document.documentElement.classList.toggle('dark', value === 'dark');
        }
    },
    { immediate: true }
);

export function useTheme() {
    function toggleTheme() {
        theme.value = theme.value === 'dark' ? 'light' : 'dark';
    }

    function setTheme(value) {
        theme.value = value;
    }

    return { theme, toggleTheme, setTheme };
}