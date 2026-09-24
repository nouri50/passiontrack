import api from './api';

export const sendChatMessage = async (message, history = []) => {
    const { data } = await api.post('/chat', { message, history });
    return data;
};