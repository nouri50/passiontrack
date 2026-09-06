import { useEffect, useState } from "react";
import { useParams, useNavigate, Link } from "react-router-dom";
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
        setError("Session introuvable.");
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
    if (!confirm("Supprimer cette session définitivement ?")) return;
    try {
      await deleteSession(id);
      navigate("/sessions");
    } catch (err) {
      console.error(err);
    }
  };

  if (isLoading) {
    return <div className="session-detail-loading">Chargement...</div>;
  }

  if (error || !session) {
    return (
      <div className="session-detail-error">
        <p>{error || "Session introuvable."}</p>
        <Link to="/sessions" className="session-detail-back">
          Retour aux sessions
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
        ← Retour aux sessions
      </Link>

      <div className="session-detail-header">
        <div>
          <h1 className="session-detail-title">{session.title}</h1>
          <span className="session-detail-meta">
            {session.category_name} ·{" "}
            {new Date(session.date_start).toLocaleDateString("fr-FR")}
          </span>
        </div>
        <div className="session-detail-header-actions">
          <Link to={`/sessions/${id}/edit`} className="session-detail-edit">
            Modifier
          </Link>
          <button className="session-detail-delete" onClick={handleDelete}>
            Supprimer
          </button>
        </div>
      </div>

      {session.description && (
        <p className="session-detail-description">{session.description}</p>
      )}

      <div className="session-detail-grid">
        <div className="session-detail-card">
          <span className="session-detail-label">Début</span>
          <span className="session-detail-value">
            {new Date(session.date_start).toLocaleString("fr-FR")}
          </span>
        </div>
        <div className="session-detail-card">
          <span className="session-detail-label">Fin</span>
          <span className="session-detail-value">
            {new Date(session.date_end).toLocaleString("fr-FR")}
          </span>
        </div>
        <div className="session-detail-card">
          <span className="session-detail-label">Durée</span>
          <span className="session-detail-value">
            {formatDuration(session.duration)}
          </span>
        </div>
      </div>

      {dataEntries.length > 0 && (
        <div className="session-detail-section">
          <h2>Données spécifiques</h2>
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
          <h2>Notes</h2>
          <p className="session-detail-notes">{session.notes}</p>
        </div>
      )}

      <div className="session-detail-section">
        <h2>🤖 Analyse IA</h2>

        {analysis && (
          <div className="session-detail-analysis">
            {analysis.summary && (
              <p className="analysis-summary">{analysis.summary}</p>
            )}

            {analysis.content?.strengths?.length > 0 && (
              <div className="analysis-block">
                <span className="analysis-block-title analysis-block-title--good">
                  Points forts
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
                  Points faibles
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
                  Conseils
                </span>
                <ul>
                  {analysis.content.tips.map((t, i) => (
                    <li key={i}>{t}</li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        )}

        {analysisMissing && (
          <div className="session-detail-analysis-empty">
            <p>Aucune analyse pour cette session.</p>
            <button
              className="ai-analyze-btn"
              onClick={handleAnalyze}
              disabled={analysisLoading}
            >
              {analysisLoading
                ? "Analyse en cours..."
                : "Analyser cette session"}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

export default SessionDetail;
