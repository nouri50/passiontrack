import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { useTranslation } from "react-i18next";
import { getSessions } from "../services/sessionService";
import { getCategories } from "../services/categoryService";
import "../styles/Analytics.css";

function formatDuration(seconds) {
  if (!seconds) return "0h";
  const hours = seconds / 3600;
  return hours >= 1 ? `${hours.toFixed(1)}h` : `${Math.round(seconds / 60)}min`;
}

function Analytics() {
  const { t } = useTranslation();
  const [sessions, setSessions] = useState([]);
  const [categories, setCategories] = useState([]);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    async function load() {
      try {
        const [sessionsData, categoriesData] = await Promise.all([
          getSessions(),
          getCategories(),
        ]);
        setSessions(Array.isArray(sessionsData) ? sessionsData : []);
        setCategories(Array.isArray(categoriesData) ? categoriesData : []);
      } catch (err) {
        console.error(err);
      } finally {
        setIsLoading(false);
      }
    }
    load();
  }, []);

  if (isLoading) {
    return <div className="analytics-loading">{t("common.loading")}</div>;
  }

  if (sessions.length === 0) {
    return (
      <div className="analytics-page">
        <h1 className="analytics-title">{t("header.analytics")}</h1>
        <div className="analytics-empty">
          <p>{t("analytics.noData")}</p>
          <Link to="/sessions/new" className="analytics-cta">
            {t("dashboard.createFirstSession")}
          </Link>
        </div>
      </div>
    );
  }

  const totalDuration = sessions.reduce((sum, s) => sum + (s.duration || 0), 0);
  const totalSessions = sessions.length;

  const byCategory = {};
  sessions.forEach((s) => {
    if (!byCategory[s.category_id]) {
      byCategory[s.category_id] = {
        count: 0,
        duration: 0,
        name: s.category_name,
      };
    }
    byCategory[s.category_id].count += 1;
    byCategory[s.category_id].duration += s.duration || 0;
  });

  const categoryBreakdown = Object.entries(byCategory)
    .map(([id, stats]) => {
      const cat = categories.find((c) => String(c.id) === String(id));
      return {
        id,
        name: stats.name,
        color: cat?.color_hex || "var(--accent-secondary)",
        count: stats.count,
        duration: stats.duration,
        percent: (stats.count / totalSessions) * 100,
      };
    })
    .sort((a, b) => b.count - a.count);

  const chartSessions = [...sessions]
    .sort((a, b) => new Date(a.date_start) - new Date(b.date_start))
    .slice(-12);
  const maxDuration = Math.max(...chartSessions.map((s) => s.duration || 0), 1);

  const avgDuration = totalDuration / totalSessions;

  return (
    <div className="analytics-page">
      <h1 className="analytics-title">{t("header.analytics")}</h1>
      <p className="analytics-subtitle">{t("analytics.subtitle")}</p>

      <div className="analytics-stats">
        <div className="analytics-stat">
          <span className="analytics-stat-label">
            {t("analytics.totalTime")}
          </span>
          <span className="analytics-stat-value">
            {formatDuration(totalDuration)}
          </span>
        </div>
        <div className="analytics-stat">
          <span className="analytics-stat-label">
            {t("dashboard.sessions")}
          </span>
          <span className="analytics-stat-value">{totalSessions}</span>
        </div>
        <div className="analytics-stat">
          <span className="analytics-stat-label">
            {t("analytics.avgDuration")}
          </span>
          <span className="analytics-stat-value">
            {formatDuration(avgDuration)}
          </span>
        </div>
        <div className="analytics-stat">
          <span className="analytics-stat-label">{t("header.categories")}</span>
          <span className="analytics-stat-value">
            {categoryBreakdown.length}
          </span>
        </div>
      </div>

      <div className="analytics-section">
        <h2>{t("dashboard.progression")}</h2>
        <div className="analytics-chart">
          {chartSessions.map((s) => (
            <div
              key={s.id}
              className="analytics-bar"
              style={{ height: `${((s.duration || 0) / maxDuration) * 100}%` }}
              title={`${s.title} · ${formatDuration(s.duration)}`}
            />
          ))}
        </div>
      </div>

      <div className="analytics-section">
        <h2>{t("analytics.breakdown")}</h2>
        <div className="analytics-breakdown">
          {categoryBreakdown.map((cat) => (
            <div className="analytics-breakdown-row" key={cat.id}>
              <div className="analytics-breakdown-header">
                <span className="analytics-breakdown-name">
                  <span
                    className="analytics-breakdown-dot"
                    style={{ background: cat.color }}
                  />
                  {cat.name}
                </span>
                <span className="analytics-breakdown-meta">
                  {t("analytics.sessionsCount", { count: cat.count })} ·{" "}
                  {formatDuration(cat.duration)}
                </span>
              </div>
              <div className="analytics-breakdown-bar">
                <div
                  className="analytics-breakdown-fill"
                  style={{ width: `${cat.percent}%`, background: cat.color }}
                />
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

export default Analytics;
