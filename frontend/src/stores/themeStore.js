import { create } from 'zustand';

function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('theme', theme);
}

const useThemeStore = create((set, get) => ({
    theme: 'dark',

    initTheme: () => {
        const stored = localStorage.getItem('theme');
        const theme = stored === 'light' ? 'light' : 'dark';
        applyTheme(theme);
        set({ theme });
    },

    toggleTheme: () => {
        const next = get().theme === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        set({ theme: next });
    },
}));

export default useThemeStore;