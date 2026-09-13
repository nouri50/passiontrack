import { useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { useTranslation } from "react-i18next";
import { resetPassword } from "../services/passwordResetService";
import useToastStore from "../stores/toastStore";
import { getApiErrorMessage } from "../utils/apiError";
import "../styles/Auth.css";

function ResetPassword() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const addToast = useToastStore((state) => state.addToast);
  const [searchParams] = useSearchParams();
  const token = searchParams.get("token");

  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState("");

  if (!token) {
    return (
      <div className="auth-container">
        <div className="auth-card">
          <h1 className="auth-title">{t("resetPassword.missingTokenTitle")}</h1>
          <p className="auth-subtitle">
            {t("resetPassword.missingTokenMessage")}
          </p>
          <p className="auth-switch">
            <Link to="/forgot-password">
              {t("resetPassword.requestNewLink")}
            </Link>
          </p>
        </div>
      </div>
    );
  }

  const handleSubmit = async (e) => {
    e.preventDefault();
    setErrorMessage("");

    if (newPassword.length < 8) {
      setErrorMessage(t("resetPassword.errorPasswordTooShort"));
      return;
    }
    if (newPassword !== confirmPassword) {
      setErrorMessage(t("resetPassword.errorPasswordMismatch"));
      return;
    }

    setIsSubmitting(true);
    try {
      await resetPassword(token, newPassword);
      addToast(t("resetPassword.successMessage"), "success");
      navigate("/login");
    } catch (err) {
      setErrorMessage(getApiErrorMessage(err, t, "resetPassword.errorGeneric"));
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="auth-container">
      <div className="auth-card">
        <h1 className="auth-title">{t("resetPassword.title")}</h1>
        <p className="auth-subtitle">{t("resetPassword.subtitle")}</p>

        <form onSubmit={handleSubmit} className="auth-form">
          <div className="auth-field">
            <label htmlFor="new_password">
              {t("resetPassword.newPasswordLabel")}
            </label>
            <div className="auth-password-wrapper">
              <input
                id="new_password"
                type={showPassword ? "text" : "password"}
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
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
            <label htmlFor="confirm_password">
              {t("resetPassword.confirmPasswordLabel")}
            </label>
            <div className="auth-password-wrapper">
              <input
                id="confirm_password"
                type={showConfirmPassword ? "text" : "password"}
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
                required
                minLength="8"
                autoComplete="new-password"
              />
              <button
                type="button"
                className="auth-password-toggle"
                onClick={() => setShowConfirmPassword((prev) => !prev)}
                aria-label={
                  showConfirmPassword
                    ? t("auth.hidePassword")
                    : t("auth.showPassword")
                }
              >
                {showConfirmPassword ? "🙈" : "👁️"}
              </button>
            </div>
          </div>

          {errorMessage && (
            <p className="auth-error" role="alert">
              {errorMessage}
            </p>
          )}

          <button type="submit" className="auth-submit" disabled={isSubmitting}>
            {isSubmitting
              ? t("resetPassword.submitting")
              : t("resetPassword.submitButton")}
          </button>
        </form>
      </div>
    </div>
  );
}

export default ResetPassword;
