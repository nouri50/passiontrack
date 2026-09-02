import { useState } from "react";
import { Link, Navigate, useNavigate } from "react-router-dom";
import useAuthStore from "../stores/authStore";
import "../styles/Auth.css";

function Register() {
  const [username, setUsername] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [error, setError] = useState("");
  const [isLoading, setIsLoading] = useState(false);

  const register = useAuthStore((state) => state.register);
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated);
  const navigate = useNavigate();

  if (isAuthenticated) {
    return <Navigate to="/dashboard" replace />;
  }

  const handleSubmit = async (event) => {
    event.preventDefault();
    setError("");

    if (password.length < 8) {
      setError("Le mot de passe doit contenir au moins 8 caractères.");
      return;
    }

    if (password !== passwordConfirmation) {
      setError("Les mots de passe ne correspondent pas.");
      return;
    }

    setIsLoading(true);

    try {
      await register(email.trim(), username.trim(), password);
      navigate("/dashboard");
    } catch (requestError) {
      const apiData = requestError.response?.data;

      if (apiData?.error) {
        setError(apiData.error);
      } else if (Array.isArray(apiData?.errors)) {
        setError(apiData.errors.join(" "));
      } else {
        setError(
          "Impossible de créer votre compte. Vérifiez vos informations et réessayez.",
        );
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="auth-container">
      <div className="auth-card">
        <h1 className="auth-title">Créer un compte</h1>

        <p className="auth-subtitle">
          Commence à suivre tes passions avec PassionTrack.
        </p>

        <form onSubmit={handleSubmit} className="auth-form">
          <div className="auth-field">
            <label htmlFor="username">Nom d’utilisateur</label>
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
            <label htmlFor="email">E-mail</label>
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
            <label htmlFor="password">Mot de passe</label>
            <input
              id="password"
              type="password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              required
              minLength="8"
              autoComplete="new-password"
            />
          </div>

          <div className="auth-field">
            <label htmlFor="password-confirmation">
              Confirmer le mot de passe
            </label>
            <input
              id="password-confirmation"
              type="password"
              value={passwordConfirmation}
              onChange={(event) => setPasswordConfirmation(event.target.value)}
              required
              minLength="8"
              autoComplete="new-password"
            />
          </div>

          {error && <p className="auth-error">{error}</p>}

          <button type="submit" className="auth-submit" disabled={isLoading}>
            {isLoading ? "Création du compte…" : "Créer mon compte"}
          </button>
        </form>

        <p className="auth-switch">
          Déjà un compte ? <Link to="/login">Connecte-toi</Link>
        </p>
      </div>
    </div>
  );
}

export default Register;
