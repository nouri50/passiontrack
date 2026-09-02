import { create } from 'zustand';

let idCounter = 0;

const useToastStore = create((set) => ({
    toasts: [],

    addToast: (message, type = 'info') => {
        const id = ++idCounter;
        set((state) => ({
            toasts: [...state.toasts, { id, message, type }],
        }));

        setTimeout(() => {
            set((state) => ({
                toasts: state.toasts.filter((t) => t.id !== id),
            }));
        }, 4000);
    },

    removeToast: (id) => {
        set((state) => ({
            toasts: state.toasts.filter((t) => t.id !== id),
        }));
    },
}));

export default useToastStore;