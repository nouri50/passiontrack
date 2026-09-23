import api from './api';

export const updateProfile = async (payload) => {
    const { data } = await api.put('/user', payload);
    return data;
};

export const updatePassword = async (payload) => {
    const { data } = await api.put('/user/password', payload);
    return data;
};

export const deleteAccount = async (payload) => {
    const { data } = await api.delete('/user', { data: payload });
    return data;
};

export const uploadAvatar = async (file) => {
    const formData = new FormData();
    formData.append('file', file);
    const { data } = await api.post('/user/avatar', formData);
    return data;
};

export const deleteAvatar = async () => {
    const { data } = await api.delete('/user/avatar');
    return data;
};