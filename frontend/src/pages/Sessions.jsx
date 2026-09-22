import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { useTranslation } from "react-i18next";
import { getSessions } from "../services/sessionService";
import { getCategories } from "../services/categoryService";
import "../styles/Sessions.css";

function Sessions() {
  const { t, i18n } = useTranslation();
  const dateLocale = i18n.language === "en" ? "en-US" : "fr-FR";

  const [sessions, setSessions] = useState([]);
  const [categories, setCategories] = useState([]);
  const [selectedCategory, setSelectedCategory] = useState("all");
  const [selectedSubcategory, setSelectedSubcategory] = useState("all");
  const [searchQuery, setSearchQuery] = useState("");
  const [sortOrder, setSortOrder] = useState("date_desc");
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

  const handleCategoryClick = (value) => {
    setSelectedCategory(value);
    setSelectedSubcategory("all");
  };

  const byCategory =
    selectedCategory === "all"
      ? sessions
      : sessions.filter(
          (session) => String(session.category_id) === selectedCategory,
        );

  const availableSubcategories = [
    ...new Set(
      byCategory.map((s) => s.subcategory).filter((s) => s && s.trim() !== ""),
    ),
  ];

  const bySubcategory =
    selectedSubcategory === "all"
      ? byCategory
      : byCategory.filter((s) => s.subcategory === selectedSubcategory);

  const bySearch =
    searchQuery.trim() === ""
      ? bySubcategory
      : bySubcategory.filter((s) =>
          s.title.toLowerCase().includes(searchQuery.trim().toLowerCase()),
        );

  const sortComparators = {
    date_desc: (a, b) => new Date(b.date_start) - new Date(a.date_start),
    date_asc: (a, b) => new Date(a.date_start) - new Date(b.date_start),
    duration_desc: (a, b) => (b.duration || 0) - (a.duration || 0),
    duration_asc: (a, b) => (a.duration || 0) - (b.duration || 0),
    title_asc: (a, b) => a.title.localeCompare(b.title),
  };

  const displayedSessions = [...bySearch].sort(sortComparators[sortOrder]);

  const formatDuration = (duration) => {
    if (!duration) return t("sessions.durationNotSet");

    const hours = Math.floor(duration / 3600);
    const minutes = Math.floor((duration % 3600) / 60);

    if (hours === 0) return `${minutes} min`;
    if (minutes === 0) return `${hours} h`;

    return `${hours} h ${minutes} min`;
  };

  if (isLoading) {
    return (
      <div className="sessions-loading">{t("sessions.loadingSessions")}</div>
    );
  }

  return (
    <div className="sessions-page">
      <section className="sessions-hero">
        <div>
          <p className="page-kicker">{t("sessions.kicker")}</p>
          <h1>{t("sessions.title")}</h1>
          <p>{t("sessions.subtitle")}</p>
        </div>

        <Link to="/sessions/new" className="sessions-create-button">
          + {t("sessions.newSession")}
        </Link>
      </section>

      <section
        className="sessions-filters"
        aria-label={t("sessions.filterByCategory")}
      >
        <button
          type="button"
          className={`filter-button ${selectedCategory === "all" ? "filter-button--active" : ""}`}
          onClick={() => handleCategoryClick("all")}
        >
          {t("sessions.all")}
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
            onClick={() => handleCategoryClick(String(category.id))}
          >
            {category.name}
          </button>
        ))}
      </section>

      {availableSubcategories.length > 0 && (
        <section
          className="sessions-filters sessions-filters--sub"
          aria-label={t("sessions.filterBySubcategory")}
        >
          <button
            type="button"
            className={`filter-button ${selectedSubcategory === "all" ? "filter-button--active" : ""}`}
            onClick={() => setSelectedSubcategory("all")}
          >
            {t("sessions.allSubcategories")}
          </button>

          {availableSubcategories.map((sub) => (
            <button
              type="button"
              key={sub}
              className={`filter-button ${
                selectedSubcategory === sub ? "filter-button--active" : ""
              }`}
              onClick={() => setSelectedSubcategory(sub)}
            >
              {sub}
            </button>
          ))}
        </section>
      )}

      <div className="sessions-toolbar">
        <div className="sessions-search">
          <span className="sessions-search-icon">🔍</span>
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder={t("sessions.searchPlaceholder")}
            aria-label={t("sessions.searchPlaceholder")}
          />
        </div>

        <select
          className="sessions-sort"
          value={sortOrder}
          onChange={(e) => setSortOrder(e.target.value)}
          aria-label={t("sessions.sortLabel")}
        >
          <option value="date_desc">{t("sessions.sortDateDesc")}</option>
          <option value="date_asc">{t("sessions.sortDateAsc")}</option>
          <option value="duration_desc">
            {t("sessions.sortDurationDesc")}
          </option>
          <option value="duration_asc">{t("sessions.sortDurationAsc")}</option>
          <option value="title_asc">{t("sessions.sortTitleAsc")}</option>
        </select>
      </div>

      {displayedSessions.length === 0 ? (
        <section className="sessions-empty">
          <span className="sessions-empty-icon">🗂️</span>
          <h2>{t("sessions.noSessionsFound")}</h2>
          <p>
            {searchQuery.trim() !== ""
              ? t("sessions.noSearchResults")
              : selectedCategory === "all"
                ? t("sessions.createFirst")
                : t("sessions.noneInCategory")}
          </p>

          {searchQuery.trim() === "" && selectedCategory === "all" && (
            <Link to="/sessions/new" className="sessions-create-button">
              {t("sessions.createFirstSession")}
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
                  <span>
                    {session.category_name}
                    {session.subcategory ? ` · ${session.subcategory}` : ""}
                  </span>
                </div>

                <div className="session-card-meta">
                  <span>
                    📅 {new Date(session.date_start).toLocaleString(dateLocale)}
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
