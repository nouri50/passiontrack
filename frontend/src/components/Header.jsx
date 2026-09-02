import { Link, useNavigate } from "react-router-dom";
import { useEffect } from "react";
import useAuthStore from "../stores/authStore";
import useNotificationStore from "../stores/notificationStore";
import "../styles/Header.css";

function Header() {
  const { user, isAuthenticated, logout } = useAuthStore();
  const navigate = useNavigate();
  const { notifications, isLoaded, fetchNotifications } =
    useNotificationStore();

  const displayName = user?.first_name || user?.username || "Utilisateur";
  const avatarInitial = displayName.charAt(0).toUpperCase();
  const unreadCount = notifications.filter((n) => !n.is_read).length;

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
          <Link to="/dashboard">Tableau de bord</Link>
          <Link to="/sessions">Sessions</Link>
          <Link to="/analytics">Analyses</Link>
          <Link to="/notifications" className="header-nav-notif">
            Notifications
            {unreadCount > 0 && (
              <span className="header-notif-badge">{unreadCount}</span>
            )}
          </Link>
        </nav>
      )}

      <div className="header-actions">
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
              Déconnexion
            </button>
          </div>
        ) : (
          <Link to="/login" className="header-login-link">
            Connexion
          </Link>
        )}
      </div>
    </header>
  );
}

export default Header;
