import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { getSessions } from "../services/sessionService";
import { getCategories } from "../services/categoryService";
import "../styles/Sessions.css";

function Sessions() {
  const [sessions, setSessions] = useState([]);
  const [categories, setCategories] = useState([]);
  const [selectedCategory, setSelectedCategory] = useState("all");
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    async function loadSessions() {
      try {
        const [sessionsData, categoriesData] = await Promise.all([
          getSessions(),
          getCategories(),
        ]);

        setSessions(Array.isArray(sessionsData) ? sessionsData : []);
        setCategories(Array.isArray(categoriesData) ? categoriesData : []);
      } catch (error) {
        console.error("Erreur de chargement des sessions :", error);
      } finally {
        setIsLoading(false);
      }
    }

    loadSessions();
  }, []);

  const displayedSessions =
    selectedCategory === "all"
      ? sessions
      : sessions.filter(
          (session) => String(session.category_id) === selectedCategory,
        );

  const formatDuration = (duration) => {
    if (!duration) return "Durée non renseignée";

    const hours = Math.floor(duration / 3600);
    const minutes = Math.floor((duration % 3600) / 60);

    if (hours === 0) return `${minutes} min`;
    if (minutes === 0) return `${hours} h`;

    return `${hours} h ${minutes} min`;
  };

  if (isLoading) {
    return <div className="sessions-loading">Chargement des sessions…</div>;
  }

  return (
    <div className="sessions-page">
      <section className="sessions-hero">
        <div>
          <p className="page-kicker">SUIVI DE PROGRESSION</p>
          <h1>Mes sessions</h1>
          <p>Retrouve et analyse toutes tes sessions d’entraînement.</p>
        </div>

        <Link to="/sessions/new" className="sessions-create-button">
          + Nouvelle session
        </Link>
      </section>

      <section className="sessions-filters" aria-label="Filtrer les sessions">
        <button
          type="button"
          className={`filter-button ${selectedCategory === "all" ? "filter-button--active" : ""}`}
          onClick={() => setSelectedCategory("all")}
        >
          Toutes
        </button>

        {categories.map((category) => (
          <button
            type="button"
            key={category.id}
            className={`filter-button ${
              selectedCategory === String(category.id)
                ? "filter-button--active"
                : ""
            }`}
            onClick={() => setSelectedCategory(String(category.id))}
          >
            {category.name}
          </button>
        ))}
      </section>

      {displayedSessions.length === 0 ? (
        <section className="sessions-empty">
          <span className="sessions-empty-icon">🗂️</span>
          <h2>Aucune session trouvée</h2>
          <p>
            {selectedCategory === "all"
              ? "Crée ta première session pour commencer à suivre ta progression."
              : "Aucune session n’existe dans cette catégorie."}
          </p>

          {selectedCategory === "all" && (
            <Link to="/sessions/new" className="sessions-create-button">
              Créer ma première session
            </Link>
          )}
        </section>
      ) : (
        <section className="sessions-list">
          {displayedSessions.map((session) => (
            <Link
              key={session.id}
              to={`/sessions/${session.id}`}
              className="session-card"
            >
              <span className="session-card-icon">✦</span>

              <div className="session-card-content">
                <div className="session-card-heading">
                  <h2>{session.title}</h2>
                  <span>{session.category_name}</span>
                </div>

                <div className="session-card-meta">
                  <span>
                    📅{" "}
                    {new Date(session.date_start).toLocaleDateString("fr-FR")}
                  </span>
                  <span>⏱ {formatDuration(session.duration)}</span>
                </div>
              </div>

              <span className="session-card-arrow">→</span>
            </Link>
          ))}
        </section>
      )}
    </div>
  );
}

export default Sessions;
