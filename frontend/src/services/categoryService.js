import api from './api';

export const getCategories = async () => {
    const { data } = await api.get('/categories');
    return data;
};

export const getCategory = async (id) => {
    const { data } = await api.get(`/categories/${id}`);
    return data;
};

export const createCategory = async (payload) => {
    const { data } = await api.post('/categories', payload);
    return data;
};

export const updateCategory = async (id, payload) => {
    const { data } = await api.put(`/categories/${id}`, payload);
    return data;
};

export const deleteCategory = async (id) => {
    const { data } = await api.delete(`/categories/${id}`);
    return data;
};