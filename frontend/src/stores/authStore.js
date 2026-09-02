import { create } from 'zustand';
import api from '../services/api';

const useAuthStore = create((set) => ({
    user: null,
    isAuthenticated: false,
    isLoading: true,

    login: async (email, password) => {
        const { data } = await api.post('/login', { email, password });
        localStorage.setItem('token', data.token);
        localStorage.setItem('refresh_token', data.refresh_token);
        set({ isAuthenticated: true });
        await useAuthStore.getState().fetchUser();
    },

    register: async (email, username, password) => {
        await api.post('/register', { email, username, password });
        await useAuthStore.getState().login(email, password);
    },

    logout: () => {
        localStorage.removeItem('token');
        localStorage.removeItem('refresh_token');
        set({ user: null, isAuthenticated: false });
    },

    fetchUser: async () => {
        try {
            const { data } = await api.get('/user');
            set({ user: data, isAuthenticated: true, isLoading: false });
        } catch (error) {
            set({ user: null, isAuthenticated: false, isLoading: false });
        }
    },

    checkAuth: async () => {
        const token = localStorage.getItem('token');
        if (!token) {
            set({ isLoading: false });
            return;
        }
        await useAuthStore.getState().fetchUser();
    },
}));

export default useAuthStore;