import api from "./api";

export const requestPasswordReset = async (email) => {
    const { data } = await api.post("/forgot-password", { email });
    return data;
};

export const resetPassword = async (token, newPassword) => {
    const { data } = await api.post("/reset-password", {
        token,
        new_password: newPassword,
    });
    return data;
};