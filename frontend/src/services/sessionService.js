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

export const updateSession = async (id, payload) => {
    const { data } = await api.put(`/sessions/${id}`, payload);
    return data;
};

export const deleteSession = async (id) => {
    const { data } = await api.delete(`/sessions/${id}`);
    return data;
};

export const uploadSessionAttachment = async (id, file) => {
    const formData = new FormData();
    formData.append('file', file);
    const { data } = await api.post(`/sessions/${id}/attachment`, formData);
    return data;
};

export const downloadSessionAttachment = async (id) => {
    const response = await api.get(`/sessions/${id}/attachment`, {
        responseType: 'blob',
    });
    return response.data;
};

export const deleteSessionAttachment = async (id) => {
    const { data } = await api.delete(`/sessions/${id}/attachment`);
    return data;
};

export const getSessionTrace = async (id) => {
    const { data } = await api.get(`/sessions/${id}/trace`);
    return data.points;
};

export const getFshubFlights = async () => {
    const { data } = await api.get('/fshub/flights');
    return data.flights;
};

export const importFshubFlight = async (sessionId, flightId = null) => {
    const payload = flightId !== null ? { flight_id: flightId } : {};
    const { data } = await api.post(`/sessions/${sessionId}/fshub-import`, payload);
    return data;
};