import { create } from 'zustand';
import i18n from '../i18n';

const useLanguageStore = create((set) => ({
    language: localStorage.getItem('language') || 'fr',

    setLanguage: (lang) => {
        i18n.changeLanguage(lang);
        localStorage.setItem('language', lang);
        set({ language: lang });
    },
}));

export default useLanguageStore;