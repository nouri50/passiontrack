import api from './api';

export const getNotifications = async () => {
    const { data } = await api.get('/notifications');
    return data;
};

export const markNotificationAsRead = async (id) => {
    const { data } = await api.put(`/notifications/${id}/read`);
    return data;
};

export const deleteNotification = async (id) => {
    const { data } = await api.delete(`/notifications/${id}`);
    return data;
};