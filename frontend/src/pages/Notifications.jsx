import { useEffect, useState } from "react";
import useNotificationStore from "../stores/notificationStore";
import "../styles/Notifications.css";

const NOTIF_ICONS = {
  progress_alert: "✅",
  ai_insight: "🤖",
  system_alert: "⚠️",
  achievement: "🏆",
};

function Notifications() {
  const { notifications, isLoaded, fetchNotifications, markAsRead, remove } =
    useNotificationStore();
  const [filter, setFilter] = useState("all");

  useEffect(() => {
    fetchNotifications();
  }, [fetchNotifications]);

  const displayed =
    filter === "unread"
      ? notifications.filter((n) => !n.is_read)
      : notifications;

  const unreadCount = notifications.filter((n) => !n.is_read).length;

  if (!isLoaded) {
    return <div className="notifications-loading">Chargement...</div>;
  }

  return (
    <div className="notifications-page">
      <h1 className="notifications-title">Notifications</h1>

      <div className="notifications-filters">
        <button
          className={`notif-filter-btn ${filter === "all" ? "notif-filter-btn--active" : ""}`}
          onClick={() => setFilter("all")}
        >
          Toutes ({notifications.length})
        </button>
        <button
          className={`notif-filter-btn ${filter === "unread" ? "notif-filter-btn--active" : ""}`}
          onClick={() => setFilter("unread")}
        >
          Non lues ({unreadCount})
        </button>
      </div>

      {displayed.length === 0 ? (
        <div className="notifications-empty">
          <p>
            {filter === "unread"
              ? "Aucune notification non lue."
              : "Aucune notification pour le moment."}
          </p>
        </div>
      ) : (
        <ul className="notifications-list">
          {displayed.map((n) => (
            <li
              key={n.id}
              className={`notification-item ${!n.is_read ? "notification-item--unread" : ""}`}
            >
              <span className="notification-icon">
                {NOTIF_ICONS[n.type] || "🔔"}
              </span>

              <div className="notification-content">
                <div className="notification-header">
                  <strong>{n.title}</strong>
                  <span className="notification-time">
                    {new Date(n.sent_at).toLocaleString("fr-FR")}
                  </span>
                </div>
                <p className="notification-message">{n.message}</p>
              </div>

              <div className="notification-actions">
                {!n.is_read && (
                  <button
                    className="notif-action-btn"
                    onClick={() => markAsRead(n.id)}
                    title="Marquer comme lu"
                  >
                    ✓
                  </button>
                )}
                <button
                  className="notif-action-btn notif-action-btn--delete"
                  onClick={() => remove(n.id)}
                  title="Supprimer"
                >
                  ×
                </button>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export default Notifications;
