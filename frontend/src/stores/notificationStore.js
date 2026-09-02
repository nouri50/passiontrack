import { create } from 'zustand';
import {
    getNotifications,
    markNotificationAsRead,
    deleteNotification,
} from '../services/notificationService';

const useNotificationStore = create((set, get) => ({
    notifications: [],
    isLoaded: false,

    fetchNotifications: async () => {
        try {
            const data = await getNotifications();
            set({ notifications: Array.isArray(data) ? data : [], isLoaded: true });
        } catch (err) {
            console.error(err);
        }
    },

    markAsRead: async (id) => {
        try {
            await markNotificationAsRead(id);
            set({
                notifications: get().notifications.map((n) =>
                    n.id === id ? { ...n, is_read: true } : n
                ),
            });
        } catch (err) {
            console.error(err);
        }
    },

    remove: async (id) => {
        try {
            await deleteNotification(id);
            set({
                notifications: get().notifications.filter((n) => n.id !== id),
            });
        } catch (err) {
            console.error(err);
        }
    },

    unreadCount: () => get().notifications.filter((n) => !n.is_read).length,
}));

export default useNotificationStore;