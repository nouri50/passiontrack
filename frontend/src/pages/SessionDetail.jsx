import { useEffect, useState } from "react";
import { useParams, useNavigate, Link } from "react-router-dom";
import { useTranslation } from "react-i18next";
import {
  getSession,
  deleteSession,
  uploadSessionAttachment,
  downloadSessionAttachment,
  deleteSessionAttachment,
  getSessionTrace,
  getFshubFlights,
  importFshubFlight,
} from "../services/sessionService";
import {
  getSessionAnalysis,
  triggerAnalysis,
} from "../services/analysisService";
import useToastStore from "../stores/toastStore";
import { getApiErrorMessage } from "../utils/apiError";
import FlightMap from "../components/FlightMap";
import ConfirmModal from "../components/ConfirmModal";
import "../styles/SessionDetail.css";
import "../styles/ConfirmModal.css";

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
  const addToast = useToastStore((state) => state.addToast);
  const [session, setSession] = useState(null);
  const [analysis, setAnalysis] = useState(null);
  const [analysisMissing, setAnalysisMissing] = useState(false);
  const [analysisLoading, setAnalysisLoading] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState("");
  const [attachmentFile, setAttachmentFile] = useState(null);
  const [attachmentUploading, setAttachmentUploading] = useState(false);
  const [attachmentDownloading, setAttachmentDownloading] = useState(false);
  const [trace, setTrace] = useState(null);
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
  const [showAttachmentDeleteConfirm, setShowAttachmentDeleteConfirm] =
    useState(false);
  const [fshubFlights, setFshubFlights] = useState(null);
  const [fshubListLoading, setFshubListLoading] = useState(false);
  const [fshubImportingId, setFshubImportingId] = useState(null);

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

  useEffect(() => {
    if (!session) {
      setTrace(null);
      return;
    }

    // Volontairement pas conditionné sur attachment_url : une session
    // FSHub n'a jamais ce champ rempli, mais peut quand même avoir une
    // trace stockée côté serveur (voir SessionAttachmentController::
    // tryFshubTrace). Le backend décide de la source, ici on tente juste.
    let cancelled = false;
    getSessionTrace(id)
      .then((points) => {
        if (!cancelled) setTrace(points);
      })
      .catch(() => {
        // Pas de toast ici : la carte est un bonus, pas une action demandée
        // explicitement — un échec silencieux (carte absente) suffit.
        if (!cancelled) setTrace(null);
      });

    return () => {
      cancelled = true;
    };
  }, [id, session?.attachment_url]);

  const handleAnalyze = async () => {
    setAnalysisLoading(true);
    try {
      const result = await triggerAnalysis(id);
      setAnalysis(result);
      setAnalysisMissing(false);
    } catch (err) {
      addToast(
        getApiErrorMessage(err, t, "sessionDetail.errorAnalyzing"),
        "error",
      );
    } finally {
      setAnalysisLoading(false);
    }
  };

  const handleDeleteClick = () => {
    setShowDeleteConfirm(true);
  };

  const handleDeleteConfirmed = async () => {
    setShowDeleteConfirm(false);
    try {
      await deleteSession(id);
      addToast(t("sessionDetail.successDeleted"), "success");
      navigate("/sessions");
    } catch (err) {
      addToast(
        getApiErrorMessage(err, t, "sessionDetail.errorDeleting"),
        "error",
      );
    }
  };

  const handleAttachmentUpload = async (e) => {
    e.preventDefault();
    if (!attachmentFile) return;

    setAttachmentUploading(true);
    try {
      const result = await uploadSessionAttachment(id, attachmentFile);

      setSession((prev) => ({
        ...prev,
        attachment_url: result.attachment_url,
        data: result.data,
      }));
      setAttachmentFile(null);

      if (result.filled_fields && result.filled_fields.length > 0) {
        addToast(
          t("sessionDetail.attachmentFilledFields", {
            fields: result.filled_fields.join(", "),
          }),
          "success",
        );
      } else {
        addToast(t("sessionDetail.attachmentUploadSuccess"), "success");
      }
    } catch (err) {
      addToast(
        getApiErrorMessage(err, t, "sessionDetail.attachmentErrorGeneric"),
        "error",
      );
    } finally {
      setAttachmentUploading(false);
    }
  };

  const handleAttachmentDownload = async () => {
    setAttachmentDownloading(true);
    try {
      const blob = await downloadSessionAttachment(id);
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = `${session.title || "flight-report"}.xlsx`;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      window.URL.revokeObjectURL(url);
    } catch (err) {
      addToast(
        getApiErrorMessage(err, t, "sessionDetail.attachmentErrorGeneric"),
        "error",
      );
    } finally {
      setAttachmentDownloading(false);
    }
  };

  const handleAttachmentDeleteClick = () => {
    setShowAttachmentDeleteConfirm(true);
  };

  const handleAttachmentDeleteConfirmed = async () => {
    setShowAttachmentDeleteConfirm(false);
    try {
      await deleteSessionAttachment(id);
      setSession((prev) => ({ ...prev, attachment_url: null }));
      addToast(t("sessionDetail.attachmentDeleteSuccess"), "success");
    } catch (err) {
      addToast(
        getApiErrorMessage(err, t, "sessionDetail.attachmentErrorGeneric"),
        "error",
      );
    }
  };

  const handleFshubListFlights = async () => {
    setFshubListLoading(true);
    try {
      const flights = await getFshubFlights();
      setFshubFlights(flights);
    } catch (err) {
      addToast(
        getApiErrorMessage(err, t, "sessionDetail.fshubErrorGeneric"),
        "error",
      );
    } finally {
      setFshubListLoading(false);
    }
  };

  const handleFshubImport = async (flightId) => {
    setFshubImportingId(flightId);
    try {
      const result = await importFshubFlight(id, flightId);

      setSession((prev) => ({ ...prev, data: result.data }));
      setFshubFlights(null);

      if (result.filled_fields && result.filled_fields.length > 0) {
        addToast(
          t("sessionDetail.fshubFilledFields", {
            fields: result.filled_fields.join(", "),
          }),
          "success",
        );
      } else {
        addToast(t("sessionDetail.fshubImportSuccess"), "success");
      }

      // La trace FSHub, si elle existe, ne déclenche pas le useEffect
      // habituel (celui-ci se redéclenche sur attachment_url, qui reste
      // toujours vide pour une session FSHub) — on la recharge donc
      // directement ici pour que la carte apparaisse sans recharger la page.
      try {
        const points = await getSessionTrace(id);
        setTrace(points);
      } catch {
        // Pas grave : la trace peut simplement ne pas exister pour ce vol.
      }
    } catch (err) {
      addToast(
        getApiErrorMessage(err, t, "sessionDetail.fshubErrorGeneric"),
        "error",
      );
    } finally {
      setFshubImportingId(null);
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
          <button className="session-detail-delete" onClick={handleDeleteClick}>
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
        <h2>{t("sessionDetail.attachmentTitle")}</h2>

        {session.attachment_url ? (
          <div className="session-detail-attachment">
            <button
              type="button"
              onClick={handleAttachmentDownload}
              disabled={attachmentDownloading}
              className="session-detail-attachment-btn"
            >
              {attachmentDownloading
                ? t("sessionDetail.attachmentDownloading")
                : t("sessionDetail.attachmentDownload")}
            </button>
            <button
              type="button"
              onClick={handleAttachmentDeleteClick}
              className="session-detail-attachment-btn session-detail-attachment-btn--danger"
            >
              {t("sessionDetail.attachmentDelete")}
            </button>
          </div>
        ) : (
          <form
            onSubmit={handleAttachmentUpload}
            className="session-detail-attachment"
          >
            <label className="session-detail-attachment-label">
              {t("sessionDetail.attachmentUploadLabel")}
              <input
                type="file"
                accept=".xlsx"
                onChange={(e) => setAttachmentFile(e.target.files[0] ?? null)}
              />
            </label>
            <button
              type="submit"
              disabled={!attachmentFile || attachmentUploading}
              className="session-detail-attachment-btn"
            >
              {attachmentUploading
                ? t("sessionDetail.attachmentUploading")
                : t("sessionDetail.attachmentUploadButton")}
            </button>
          </form>
        )}

        {/* Rendue dès qu'une trace existe, qu'elle vienne d'un upload
            SimBit (attachment_url renseigné) ou d'un import FSHub
            (attachment_url toujours vide — voir le useEffect de trace
            et handleFshubImport, qui recharge trace après import). */}
        {trace && <FlightMap points={trace} />}
      </div>

      <div className="session-detail-section">
        <h2>{t("sessionDetail.fshubTitle")}</h2>

        {!fshubFlights ? (
          <button
            type="button"
            onClick={handleFshubListFlights}
            disabled={fshubListLoading}
            className="session-detail-attachment-btn"
          >
            {fshubListLoading
              ? t("sessionDetail.fshubLoading")
              : t("sessionDetail.fshubShowFlights")}
          </button>
        ) : fshubFlights.length === 0 ? (
          <p className="session-detail-fshub-empty">
            {t("sessionDetail.fshubNoFlights")}
          </p>
        ) : (
          <ul className="session-detail-fshub-list">
            {fshubFlights.map((flight) => (
              <li key={flight.id} className="session-detail-fshub-item">
                <span className="session-detail-fshub-item-info">
                  {flight.departure} → {flight.arrival} · {flight.aircraft}
                  {flight.departure_time
                    ? ` · ${new Date(flight.departure_time).toLocaleString(dateLocale)}`
                    : ""}
                </span>
                <button
                  type="button"
                  onClick={() => handleFshubImport(flight.id)}
                  disabled={fshubImportingId !== null}
                  className="session-detail-attachment-btn"
                >
                  {fshubImportingId === flight.id
                    ? t("sessionDetail.fshubImporting")
                    : t("sessionDetail.fshubImportButton")}
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>

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

      <ConfirmModal
        isOpen={showDeleteConfirm}
        title={t("sessionDetail.deleteModalTitle")}
        message={t("sessionDetail.deleteConfirm")}
        confirmLabel={t("common.delete")}
        cancelLabel={t("common.cancel")}
        onConfirm={handleDeleteConfirmed}
        onCancel={() => setShowDeleteConfirm(false)}
        danger
      />

      <ConfirmModal
        isOpen={showAttachmentDeleteConfirm}
        title={t("sessionDetail.attachmentDeleteModalTitle")}
        message={t("sessionDetail.attachmentDeleteConfirm")}
        confirmLabel={t("sessionDetail.attachmentDelete")}
        cancelLabel={t("common.cancel")}
        onConfirm={handleAttachmentDeleteConfirmed}
        onCancel={() => setShowAttachmentDeleteConfirm(false)}
        danger
      />
    </div>
  );
}

export default SessionDetail;
