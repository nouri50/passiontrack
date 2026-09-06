import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { useTranslation } from "react-i18next";
import { getSessions } from "../services/sessionService";
import { getCategories } from "../services/categoryService";
import {
  getSessionAnalysis,
  triggerAnalysis,
} from "../services/analysisService";
import useAuthStore from "../stores/authStore";
import useNotificationStore from "../stores/notificationStore";
import "../styles/Dashboard.css";

const NOTIF_ICONS = {
  progress_alert: "✅",
  ai_insight: "🤖",
  system_alert: "⚠️",
  achievement: "🏆",
};

function Dashboard() {
  const { t, i18n } = useTranslation();
  const dateLocale = i18n.language === "en" ? "en-US" : "fr-FR";
  const user = useAuthStore((state) => state.user);
  const {
    notifications,
    isLoaded: notificationsLoaded,
    fetchNotifications,
  } = useNotificationStore();

  const [sessions, setSessions] = useState([]);
  // eslint-disable-next-line no-unused-vars
  const [categories, setCategories] = useState([]);
  const [analysis, setAnalysis] = useState(null);
  const [analysisLoading, setAnalysisLoading] = useState(false);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    async function loadDashboard() {
      try {
        const [sessionsData, categoriesData] = await Promise.all([
          getSessions(),
          getCategories(),
        ]);

        if (!notificationsLoaded) {
          fetchNotifications();
        }

        const sessionsList = Array.isArray(sessionsData) ? sessionsData : [];

        setSessions(sessionsList);
        setCategories(Array.isArray(categoriesData) ? categoriesData : []);

        const latestSession = [...sessionsList].sort(
          (a, b) => new Date(b.date_start) - new Date(a.date_start),
        )[0];

        if (latestSession) {
          try {
            const analysisData = await getSessionAnalysis(latestSession.id);

            setAnalysis({
              ...analysisData,
              sessionId: latestSession.id,
            });
          } catch {
            setAnalysis({
              sessionId: latestSession.id,
              missing: true,
            });
          }
        }
      } catch (error) {
        console.error("Erreur de chargement du dashboard :", error);
      } finally {
        setIsLoading(false);
      }
    }

    loadDashboard();
  }, [notificationsLoaded, fetchNotifications]);

  const handleAnalyze = async () => {
    if (!analysis?.sessionId) return;

    setAnalysisLoading(true);

    try {
      const result = await triggerAnalysis(analysis.sessionId);

      setAnalysis({
        ...result,
        sessionId: analysis.sessionId,
      });
    } catch (error) {
      console.error("Erreur d'analyse IA :", error);
    } finally {
      setAnalysisLoading(false);
    }
  };

  const totalDurationHours = (
    sessions.reduce((total, session) => total + (session.duration || 0), 0) /
    3600
  ).toFixed(1);

  const activeCategoriesCount = new Set(
    sessions.map((session) => session.category_id),
  ).size;

  const recentSessions = [...sessions]
    .sort((a, b) => new Date(b.date_start) - new Date(a.date_start))
    .slice(0, 3);

  const chartSessions = [...sessions]
    .sort((a, b) => new Date(a.date_start) - new Date(b.date_start))
    .slice(-9);

  const maxDuration = Math.max(
    ...chartSessions.map((session) => session.duration || 0),
    1,
  );

  const unreadNotifications = notifications
    .filter((notification) => !notification.is_read)
    .slice(0, 3);

  if (isLoading) {
    return (
      <div className="dashboard-loading">{t("dashboard.loadingDashboard")}</div>
    );
  }

  return (
    <div className="dashboard">
      <section className="dashboard-hero">
        <h1 className="dashboard-welcome">
          {t("dashboard.welcome")},{" "}
          <span className="dashboard-username">
            {user?.first_name || user?.username || t("dashboard.defaultUser")}
          </span>{" "}
          <span aria-hidden="true">🚀</span>
        </h1>
      </section>

      {unreadNotifications.length > 0 && (
        <section
          className="notif-panel"
          aria-label={t("dashboard.recentNotifications")}
        >
          {unreadNotifications.map((notification) => (
            <article key={notification.id} className="notif-item">
              <strong>
                {NOTIF_ICONS[notification.type] || "🔔"} {notification.title}
              </strong>

              <span>{notification.message}</span>

              <small>
                {new Date(notification.sent_at).toLocaleString(dateLocale)}
              </small>
            </article>
          ))}
        </section>
      )}

      <section className="dashboard-overview">
        <article className="stat-card stat-card--hours">
          <span className="stat-label">⏱ {t("dashboard.totalHours")}</span>

          <strong className="stat-value">{totalDurationHours}h</strong>

          <span className="stat-description">{t("dashboard.sinceStart")}</span>

          <div className="stat-progress">
            <div
              className="stat-progress-fill"
              style={{
                width: `${Math.min(
                  100,
                  (Number(totalDurationHours) / 50) * 100,
                )}%`,
              }}
            />
          </div>
        </article>

        <article className="stat-card stat-card--sessions">
          <span className="stat-label">📊 {t("dashboard.sessions")}</span>

          <strong className="stat-value">{sessions.length}</strong>

          <span className="stat-description">
            {t("dashboard.sessionsRecorded")}
          </span>

          <div className="stat-progress">
            <div
              className="stat-progress-fill"
              style={{
                width: `${Math.min(100, (sessions.length / 30) * 100)}%`,
              }}
            />
          </div>
        </article>

        <article className="stat-card stat-card--categories">
          <span className="stat-label">
            🎯 {t("dashboard.activeCategories")}
          </span>

          <strong className="stat-value">{activeCategoriesCount}</strong>

          <span className="stat-description">
            {t("dashboard.passionsTracked")}
          </span>

          <div className="stat-progress">
            <div
              className="stat-progress-fill"
              style={{
                width: `${Math.min(100, (activeCategoriesCount / 10) * 100)}%`,
              }}
            />
          </div>
        </article>

        <article className="analysis-card">
          <span className="analysis-card-title">
            🤖 {t("dashboard.aiAnalysis")}
          </span>

          {analysis && !analysis.missing ? (
            <p className="analysis-card-content">{analysis.summary}</p>
          ) : (
            <>
              <p className="analysis-card-content">
                {sessions.length === 0
                  ? t("dashboard.noAnalysisFirstSession")
                  : t("dashboard.noAnalysisAvailable")}
              </p>

              {analysis?.sessionId && (
                <button
                  type="button"
                  className="ai-analyze-btn"
                  onClick={handleAnalyze}
                  disabled={analysisLoading}
                >
                  {analysisLoading
                    ? t("dashboard.analyzing")
                    : t("dashboard.launchAnalysis")}
                </button>
              )}
            </>
          )}
        </article>

        <article className="progress-card">
          <h2>📈 {t("dashboard.progression")}</h2>

          {chartSessions.length > 0 ? (
            <div
              className="progress-chart"
              aria-label="Durée des dernières sessions"
            >
              {chartSessions.map((session) => (
                <div className="chart-column" key={session.id}>
                  <div
                    className="chart-bar"
                    style={{
                      height: `${Math.max(
                        12,
                        ((session.duration || 0) / maxDuration) * 100,
                      )}%`,
                    }}
                    title={`${session.title} : ${(
                      (session.duration || 0) / 3600
                    ).toFixed(1)} h`}
                  />
                </div>
              ))}
            </div>
          ) : (
            <p className="progress-empty">{t("dashboard.noSessionsChart")}</p>
          )}
        </article>
      </section>

      <section className="dashboard-bottom-grid">
        <article className="dashboard-panel activity-panel">
          <div className="dashboard-panel-header">
            <h2>🔥 {t("dashboard.recentActivity")}</h2>

            <Link to="/sessions">{t("dashboard.viewAll")}</Link>
          </div>

          {recentSessions.length > 0 ? (
            <ul className="activity-list">
              {recentSessions.map((session) => (
                <li key={session.id} className="activity-item">
                  <span className="activity-icon">✦</span>

                  <div className="activity-content">
                    <strong>{session.title}</strong>

                    <span>
                      {session.category_name} ·{" "}
                      {new Date(session.date_start).toLocaleDateString(
                        dateLocale,
                      )}
                    </span>
                  </div>
                </li>
              ))}
            </ul>
          ) : (
            <div className="dashboard-empty">
              <p>{t("dashboard.noSessionsYet")}</p>

              <Link to="/sessions/new">
                {t("dashboard.createFirstSession")}
              </Link>
            </div>
          )}
        </article>

        <article className="dashboard-panel integrations-panel">
          <div className="dashboard-panel-header">
            <h2>🔗 {t("dashboard.integrations")}</h2>
          </div>

          <p className="integrations-text">{t("dashboard.integrationsText")}</p>

          <div className="integration-list">
            <span>MSFS</span>
            <span>iRacing</span>
            <span>Discord</span>
            <span>Slack</span>
          </div>

          <button type="button" className="integration-button" disabled>
            + {t("dashboard.addIntegration")}
          </button>
        </article>
      </section>
    </div>
  );
}

export default Dashboard;
