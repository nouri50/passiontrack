import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { useTranslation } from "react-i18next";
import { getSessions } from "../services/sessionService";
import { getCategories } from "../services/categoryService";
import "../styles/Sessions.css";

const PAGE_SIZE = 20;
const SEARCH_DEBOUNCE_MS = 400;

function Sessions() {
  const { t, i18n } = useTranslation();
  const dateLocale = i18n.language === "en" ? "en-US" : "fr-FR";

  const [sessions, setSessions] = useState([]);
  const [categories, setCategories] = useState([]);
  const [availableSubcategories, setAvailableSubcategories] = useState([]);
  const [selectedCategory, setSelectedCategory] = useState("all");
  const [selectedSubcategory, setSelectedSubcategory] = useState("all");
  const [searchInput, setSearchInput] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [sortOrder, setSortOrder] = useState("date_desc");
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState({
    page: 1,
    limit: PAGE_SIZE,
    total: 0,
    total_pages: 1,
  });
  const [isLoading, setIsLoading] = useState(true);
  const [isFetching, setIsFetching] = useState(false);

  // Charge la liste des catégories une seule fois (indépendant de la pagination).
  useEffect(() => {
    getCategories()
      .then((data) => setCategories(Array.isArray(data) ? data : []))
      .catch((error) =>
        console.error("Erreur de chargement des catégories :", error),
      );
  }, []);

  // Debounce de la recherche pour éviter une requête serveur à chaque frappe.
  useEffect(() => {
    const timeoutId = setTimeout(() => {
      setDebouncedSearch(searchInput.trim());
    }, SEARCH_DEBOUNCE_MS);

    return () => clearTimeout(timeoutId);
  }, [searchInput]);

  // Tout changement de filtre/recherche/tri doit repartir de la page 1.
  useEffect(() => {
    setPage(1);
  }, [selectedCategory, selectedSubcategory, debouncedSearch, sortOrder]);

  // Charge la page courante depuis le serveur avec les filtres actifs.
  useEffect(() => {
    let isCancelled = false;

    async function loadSessions() {
      setIsFetching(true);
      try {
        const params = {
          page,
          limit: PAGE_SIZE,
          sort: sortOrder,
        };
        if (selectedCategory !== "all") {
          params.category_id = selectedCategory;
        }
        if (selectedSubcategory !== "all") {
          params.subcategory = selectedSubcategory;
        }
        if (debouncedSearch !== "") {
          params.search = debouncedSearch;
        }

        const data = await getSessions(params);
        if (isCancelled) return;

        setSessions(Array.isArray(data.sessions) ? data.sessions : []);
        setAvailableSubcategories(
          Array.isArray(data.available_subcategories)
            ? data.available_subcategories
            : [],
        );
        setPagination(
          data.pagination || { page: 1, limit: PAGE_SIZE, total: 0, total_pages: 1 },
        );
      } catch (error) {
        if (!isCancelled) {
          console.error("Erreur de chargement des sessions :", error);
        }
      } finally {
        if (!isCancelled) {
          setIsLoading(false);
          setIsFetching(false);
        }
      }
    }

    loadSessions();

    return () => {
      isCancelled = true;
    };
  }, [selectedCategory, selectedSubcategory, debouncedSearch, sortOrder, page]);

  // Filet de sécurité : si la page courante n'existe plus (ex. suppression de
  // la dernière session d'une dernière page), on revient à la dernière page valide.
  useEffect(() => {
    if (!isLoading && pagination.total_pages > 0 && page > pagination.total_pages) {
      setPage(pagination.total_pages);
    }
  }, [pagination, page, isLoading]);

  const handleCategoryClick = (value) => {
    setSelectedCategory(value);
    setSelectedSubcategory("all");
  };

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
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
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

      {sessions.length === 0 ? (
        <section className="sessions-empty">
          <span className="sessions-empty-icon">🗂️</span>
          <h2>{t("sessions.noSessionsFound")}</h2>
          <p>
            {debouncedSearch !== ""
              ? t("sessions.noSearchResults")
              : selectedCategory === "all"
                ? t("sessions.createFirst")
                : t("sessions.noneInCategory")}
          </p>

          {debouncedSearch === "" && selectedCategory === "all" && (
            <Link to="/sessions/new" className="sessions-create-button">
              {t("sessions.createFirstSession")}
            </Link>
          )}
        </section>
      ) : (
        <>
          <p className="sessions-total-count">
            {t("sessions.paginationTotalSessions", { count: pagination.total })}
          </p>

          <section className="sessions-list">
            {sessions.map((session) => (
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

          {pagination.total_pages > 1 && (
            <nav className="sessions-pagination" aria-label={t("sessions.paginationPageOf", {
              page: pagination.page,
              totalPages: pagination.total_pages,
            })}>
              <button
                type="button"
                className="pagination-button"
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={pagination.page <= 1 || isFetching}
              >
                ← {t("sessions.paginationPrevious")}
              </button>

              <span className="pagination-info">
                {t("sessions.paginationPageOf", {
                  page: pagination.page,
                  totalPages: pagination.total_pages,
                })}
              </span>

              <button
                type="button"
                className="pagination-button"
                onClick={() =>
                  setPage((p) => Math.min(pagination.total_pages, p + 1))
                }
                disabled={pagination.page >= pagination.total_pages || isFetching}
              >
                {t("sessions.paginationNext")} →
              </button>
            </nav>
          )}
        </>
      )}
    </div>
  );
}

export default Sessions;
