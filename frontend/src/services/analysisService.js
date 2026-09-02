import api from './api';

export const getSessionAnalysis = async (sessionId) => {
    const { data } = await api.get(`/sessions/${sessionId}/analysis`);
    return data;
};

export const triggerAnalysis = async (sessionId) => {
    const { data } = await api.post(`/sessions/${sessionId}/analyze`);
    return data;
};