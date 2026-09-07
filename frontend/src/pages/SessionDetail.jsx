import { useEffect, useState } from "react";
import { useParams, useNavigate, Link } from "react-router-dom";
import { useTranslation } from "react-i18next";
import { getSession, deleteSession } from "../services/sessionService";
import {
  getSessionAnalysis,
  triggerAnalysis,
} from "../services/analysisService";
import "../styles/SessionDetail.css";

function formatKey(key) {
  return key
    .split("_")
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(" ");
}

function formatDuration(seconds) {
  if (!seconds) return "—";
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  return h > 0 ? `${h}h ${m}min` : `${m}min`;
}

function SessionDetail() {
  const { t, i18n } = useTranslation();
  const dateLocale = i18n.language === "en" ? "en-US" : "fr-FR";
  const { id } = useParams();
  const navigate = useNavigate();
  const [session, setSession] = useState(null);
  const [analysis, setAnalysis] = useState(null);
  const [analysisMissing, setAnalysisMissing] = useState(false);
  const [analysisLoading, setAnalysisLoading] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    async function load() {
      try {
        const sessionData = await getSession(id);
        setSession(sessionData);

        try {
          const analysisData = await getSessionAnalysis(id);
          setAnalysis(analysisData);
        } catch {
          setAnalysisMissing(true);
        }
      } catch {
        setError(t("sessionDetail.notFound"));
      } finally {
        setIsLoading(false);
      }
    }
    load();
  }, [id]);

  const handleAnalyze = async () => {
    setAnalysisLoading(true);
    try {
      const result = await triggerAnalysis(id);
      setAnalysis(result);
      setAnalysisMissing(false);
    } catch (err) {
      console.error(err);
    } finally {
      setAnalysisLoading(false);
    }
  };

  const handleDelete = async () => {
    if (!confirm(t("sessionDetail.deleteConfirm"))) return;
    try {
      await deleteSession(id);
      navigate("/sessions");
    } catch (err) {
      console.error(err);
    }
  };

  if (isLoading) {
    return <div className="session-detail-loading">{t("common.loading")}</div>;
  }

  if (error || !session) {
    return (
      <div className="session-detail-error">
        <p>{error || t("sessionDetail.notFound")}</p>
        <Link to="/sessions" className="session-detail-back">
          {t("sessionDetail.backToSessions")}
        </Link>
      </div>
    );
  }

  const dataEntries =
    session.data && typeof session.data === "object"
      ? Object.entries(session.data)
      : [];

  return (
    <div className="session-detail-page">
      <Link to="/sessions" className="session-detail-back">
        ← {t("sessionDetail.backToSessions")}
      </Link>

      <div className="session-detail-header">
        <div>
          <h1 className="session-detail-title">{session.title}</h1>
          <span className="session-detail-meta">
            {session.category_name} ·{" "}
            {new Date(session.date_start).toLocaleDateString(dateLocale)}
          </span>
        </div>
        <div className="session-detail-header-actions">
          <Link to={`/sessions/${id}/edit`} className="session-detail-edit">
            {t("sessionDetail.edit")}
          </Link>
          <button className="session-detail-delete" onClick={handleDelete}>
            {t("common.delete")}
          </button>
        </div>
      </div>

      {session.description && (
        <p className="session-detail-description">{session.description}</p>
      )}

      <div className="session-detail-grid">
        <div className="session-detail-card">
          <span className="session-detail-label">
            {t("sessionDetail.start")}
          </span>
          <span className="session-detail-value">
            {new Date(session.date_start).toLocaleString(dateLocale)}
          </span>
        </div>
        <div className="session-detail-card">
          <span className="session-detail-label">{t("sessionDetail.end")}</span>
          <span className="session-detail-value">
            {new Date(session.date_end).toLocaleString(dateLocale)}
          </span>
        </div>
        <div className="session-detail-card">
          <span className="session-detail-label">
            {t("sessionDetail.duration")}
          </span>
          <span className="session-detail-value">
            {formatDuration(session.duration)}
          </span>
        </div>
      </div>

      {dataEntries.length > 0 && (
        <div className="session-detail-section">
          <h2>{t("sessionDetail.specificData")}</h2>
          <div className="session-detail-data-grid">
            {dataEntries.map(([key, value]) => (
              <div className="session-detail-data-item" key={key}>
                <span className="session-detail-data-label">
                  {formatKey(key)}
                </span>
                <span className="session-detail-data-value">
                  {String(value)}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}

      {session.notes && (
        <div className="session-detail-section">
          <h2>{t("sessionForm.notes")}</h2>
          <p className="session-detail-notes">{session.notes}</p>
        </div>
      )}

      <div className="session-detail-section">
        <h2>🤖 {t("dashboard.aiAnalysis")}</h2>

        {analysis && (
          <div className="session-detail-analysis">
            {analysis.summary && (
              <p className="analysis-summary">{analysis.summary}</p>
            )}

            {analysis.content?.strengths?.length > 0 && (
              <div className="analysis-block">
                <span className="analysis-block-title analysis-block-title--good">
                  {t("sessionDetail.strengths")}
                </span>
                <ul>
                  {analysis.content.strengths.map((s, i) => (
                    <li key={i}>{s}</li>
                  ))}
                </ul>
              </div>
            )}

            {analysis.content?.weaknesses?.length > 0 && (
              <div className="analysis-block">
                <span className="analysis-block-title analysis-block-title--bad">
                  {t("sessionDetail.weaknesses")}
                </span>
                <ul>
                  {analysis.content.weaknesses.map((w, i) => (
                    <li key={i}>{w}</li>
                  ))}
                </ul>
              </div>
            )}

            {analysis.content?.tips?.length > 0 && (
              <div className="analysis-block">
                <span className="analysis-block-title analysis-block-title--tip">
                  {t("sessionDetail.tips")}
                </span>
                <ul>
                  {analysis.content.tips.map((tip, i) => (
                    <li key={i}>{tip}</li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        )}

        {analysisMissing && (
          <div className="session-detail-analysis-empty">
            <p>{t("sessionDetail.noAnalysis")}</p>
            <button
              className="ai-analyze-btn"
              onClick={handleAnalyze}
              disabled={analysisLoading}
            >
              {analysisLoading
                ? t("dashboard.analyzing")
                : t("sessionDetail.analyzeSession")}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

export default SessionDetail;
