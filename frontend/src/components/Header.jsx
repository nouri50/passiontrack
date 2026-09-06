import { Link, useNavigate } from "react-router-dom";
import { useEffect } from "react";
import { useTranslation } from "react-i18next";
import useAuthStore from "../stores/authStore";
import useNotificationStore from "../stores/notificationStore";
import useLanguageStore from "../stores/languageStore";
import "../styles/Header.css";
import useThemeStore from "../stores/themeStore";

function Header() {
  const { t } = useTranslation();
  const { user, isAuthenticated, logout } = useAuthStore();
  const navigate = useNavigate();
  const { notifications, isLoaded, fetchNotifications } =
    useNotificationStore();
  const { language, setLanguage } = useLanguageStore();

  const displayName = user?.first_name || user?.username || "Utilisateur";
  const avatarInitial = displayName.charAt(0).toUpperCase();
  const unreadCount = notifications.filter((n) => !n.is_read).length;
  const { theme, toggleTheme } = useThemeStore();

  useEffect(() => {
    if (isAuthenticated && !isLoaded) {
      fetchNotifications();
    }
  }, [isAuthenticated, isLoaded, fetchNotifications]);

  const handleLogout = () => {
    logout();
    navigate("/login", { replace: true });
  };

  return (
    <header className="header">
      <Link to="/" className="header-logo">
        <svg viewBox="0 0 100 100" className="header-logo-icon">
          <circle
            cx="50"
            cy="50"
            r="42"
            fill="none"
            stroke="#ffffff"
            strokeWidth="6"
          />
          <path
            d="M 15 55 L 30 55 L 38 35 L 48 65 L 56 45 L 62 55 L 78 30"
            fill="none"
            stroke="#ffffff"
            strokeWidth="6"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        </svg>

        <span className="header-title">PassionTrack</span>
      </Link>

      {isAuthenticated && (
        <nav className="header-nav">
          <Link to="/dashboard">{t("header.dashboard")}</Link>
          <Link to="/sessions">{t("header.sessions")}</Link>
          <Link to="/analytics">{t("header.analytics")}</Link>
          <Link to="/notifications" className="header-nav-notif">
            {t("header.notifications")}
            {unreadCount > 0 && (
              <span className="header-notif-badge">{unreadCount}</span>
            )}
          </Link>
          <Link to="/categories">{t("header.categories")}</Link>
        </nav>
      )}

      <div className="header-actions">
        <div className="header-lang-switch">
          <button
            type="button"
            onClick={() => setLanguage("fr")}
            className={`header-lang-btn ${language === "fr" ? "active" : ""}`}
            aria-label="Français"
          >
            FR
          </button>
          <button
            type="button"
            onClick={() => setLanguage("en")}
            className={`header-lang-btn ${language === "en" ? "active" : ""}`}
            aria-label="English"
          >
            EN
          </button>
        </div>

        {isAuthenticated ? (
          <div className="header-user">
            <Link
              to="/profile"
              className="header-user-link"
              aria-label={`Profil de ${displayName}`}
            >
              <span className="header-avatar">{avatarInitial}</span>
              <span className="header-username">{displayName}</span>
            </Link>

            <button
              type="button"
              onClick={handleLogout}
              className="header-logout"
            >
              {t("header.logout")}
            </button>

            <button
              type="button"
              onClick={toggleTheme}
              className="header-theme-toggle"
              aria-label="Changer de thème"
            >
              {theme === "dark" ? "☀️" : "🌙"}
            </button>
          </div>
        ) : (
          <Link to="/login" className="header-login-link">
            {t("header.login")}
          </Link>
        )}
      </div>
    </header>
  );
}

export default Header;
