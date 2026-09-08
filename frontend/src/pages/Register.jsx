import { useState } from "react";
import { Link, Navigate, useNavigate } from "react-router-dom";
import { useTranslation } from "react-i18next";
import useAuthStore from "../stores/authStore";
import useToastStore from "../stores/toastStore";
import "../styles/Auth.css";

function Register() {
  const { t } = useTranslation();
  const [username, setUsername] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [showPasswordConfirmation, setShowPasswordConfirmation] =
    useState(false);
  const [isLoading, setIsLoading] = useState(false);

  const register = useAuthStore((state) => state.register);
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated);
  const addToast = useToastStore((state) => state.addToast);
  const navigate = useNavigate();

  if (isAuthenticated) {
    return <Navigate to="/dashboard" replace />;
  }

  const handleSubmit = async (event) => {
    event.preventDefault();

    if (password.length < 8) {
      addToast(t("register.errorPasswordTooShort"), "error");
      return;
    }

    if (password !== passwordConfirmation) {
      addToast(t("profile.errorPasswordMismatch"), "error");
      return;
    }

    setIsLoading(true);

    try {
      await register(email.trim(), username.trim(), password);
      addToast(t("register.successCreated"), "success");
      navigate("/dashboard");
    } catch (requestError) {
      const apiData = requestError.response?.data;

      if (apiData?.error) {
        addToast(apiData.error, "error");
      } else if (Array.isArray(apiData?.errors)) {
        addToast(apiData.errors.join(" "), "error");
      } else {
        addToast(t("register.errorGeneric"), "error");
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="auth-container">
      <div className="auth-card">
        <h1 className="auth-title">{t("auth.registerTitle")}</h1>

        <p className="auth-subtitle">{t("register.subtitle")}</p>

        <form onSubmit={handleSubmit} className="auth-form">
          <div className="auth-field">
            <label htmlFor="username">{t("auth.username")}</label>
            <input
              id="username"
              type="text"
              value={username}
              onChange={(event) => setUsername(event.target.value)}
              required
              minLength="3"
              maxLength="180"
              autoComplete="username"
            />
          </div>

          <div className="auth-field">
            <label htmlFor="email">{t("auth.email")}</label>
            <input
              id="email"
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              required
              autoComplete="email"
            />
          </div>

          <div className="auth-field">
            <label htmlFor="password">{t("auth.password")}</label>
            <div className="auth-password-wrapper">
              <input
                id="password"
                type={showPassword ? "text" : "password"}
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                required
                minLength="8"
                autoComplete="new-password"
              />
              <button
                type="button"
                className="auth-password-toggle"
                onClick={() => setShowPassword((prev) => !prev)}
                aria-label={
                  showPassword ? t("auth.hidePassword") : t("auth.showPassword")
                }
              >
                {showPassword ? "🙈" : "👁️"}
              </button>
            </div>
          </div>

          <div className="auth-field">
            <label htmlFor="password-confirmation">
              {t("register.confirmPasswordLabel")}
            </label>
            <div className="auth-password-wrapper">
              <input
                id="password-confirmation"
                type={showPasswordConfirmation ? "text" : "password"}
                value={passwordConfirmation}
                onChange={(event) =>
                  setPasswordConfirmation(event.target.value)
                }
                required
                minLength="8"
                autoComplete="new-password"
              />
              <button
                type="button"
                className="auth-password-toggle"
                onClick={() => setShowPasswordConfirmation((prev) => !prev)}
                aria-label={
                  showPasswordConfirmation
                    ? t("auth.hidePassword")
                    : t("auth.showPassword")
                }
              >
                {showPasswordConfirmation ? "🙈" : "👁️"}
              </button>
            </div>
          </div>

          <button type="submit" className="auth-submit" disabled={isLoading}>
            {isLoading
              ? t("register.creatingAccount")
              : t("auth.registerButton")}
          </button>
        </form>

        <p className="auth-switch">
          {t("auth.hasAccount")} <Link to="/login">{t("auth.signIn")}</Link>
        </p>
      </div>
    </div>
  );
}

export default Register;
