import { useState } from "react";
import { Link } from "react-router-dom";
import { useTranslation } from "react-i18next";
import { requestPasswordReset } from "../services/passwordResetService";
import { getApiErrorMessage } from "../utils/apiError";
import "../styles/Auth.css";

function ForgotPassword() {
  const { t } = useTranslation();
  const [email, setEmail] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [errorMessage, setErrorMessage] = useState("");

  const handleSubmit = async (e) => {
    e.preventDefault();
    setErrorMessage("");
    setIsLoading(true);

    try {
      await requestPasswordReset(email.trim());
      // On affiche toujours le même message de succès, que l'email existe ou
      // non dans le système : c'est le backend qui garantit cette absence de
      // distinction (voir PasswordResetController), pas juste l'UI.
      setSubmitted(true);
    } catch (err) {
      setErrorMessage(getApiErrorMessage(err, t, "errors.generic"));
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="auth-container">
      <div className="auth-card">
        <h1 className="auth-title">{t("forgotPassword.title")}</h1>

        {submitted ? (
          <>
            <p className="auth-subtitle">
              {t("forgotPassword.successMessage")}
            </p>
            <p className="auth-switch">
              <Link to="/login">{t("forgotPassword.backToLogin")}</Link>
            </p>
          </>
        ) : (
          <>
            <p className="auth-subtitle">{t("forgotPassword.subtitle")}</p>

            <form onSubmit={handleSubmit} className="auth-form">
              <div className="auth-field">
                <label htmlFor="email">{t("forgotPassword.emailLabel")}</label>
                <input
                  id="email"
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  required
                  autoComplete="email"
                />
              </div>

              {errorMessage && (
                <p className="auth-error" role="alert">
                  {errorMessage}
                </p>
              )}

              <button
                type="submit"
                className="auth-submit"
                disabled={isLoading}
              >
                {isLoading
                  ? t("forgotPassword.sending")
                  : t("forgotPassword.submitButton")}
              </button>
            </form>

            <p className="auth-switch">
              <Link to="/login">{t("forgotPassword.backToLogin")}</Link>
            </p>
          </>
        )}
      </div>
    </div>
  );
}

export default ForgotPassword;
