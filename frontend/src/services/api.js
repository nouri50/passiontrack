import axios from 'axios';

const api = axios.create({
    baseURL: 'http://127.0.0.1:8000/api',
});

api.interceptors.request.use((config) => {
    const token = localStorage.getItem('token');
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

api.interceptors.response.use(
    (response) => response,
    async (error) => {
        const isAuthRequest =
            error.config?.url?.includes('/login') ||
            error.config?.url?.includes('/register');

        if (error.response?.status === 401 && !isAuthRequest) {
            const refreshToken = localStorage.getItem('refresh_token');
            if (refreshToken) {
                try {
                    const { data } = await axios.post('http://127.0.0.1:8000/api/token/refresh', {
                        refresh_token: refreshToken,
                    });
                    localStorage.setItem('token', data.token);
                    error.config.headers.Authorization = `Bearer ${data.token}`;
                    return axios(error.config);
                } catch (refreshError) {
                    console.error('Échec du rafraîchissement du token :', refreshError);
                    localStorage.removeItem('token');
                    localStorage.removeItem('refresh_token');
                    window.location.href = '/login';
                }
            } else {
                window.location.href = '/login';
            }
        }

        return Promise.reject(error);
    }
);

export default api;