import api from './api';

export const getSessions = async () => {
    const { data } = await api.get('/sessions');
    return data;
};

export const getSession = async (id) => {
    const { data } = await api.get(`/sessions/${id}`);
    return data;
};

export const createSession = async (payload) => {
    const { data } = await api.post('/sessions', payload);
    return data;
};

export const deleteSession = async (id) => {
    const { data } = await api.delete(`/sessions/${id}`);
    return data;
};