import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { getCategories } from "../services/categoryService";
import { createSession } from "../services/sessionService";
import useToastStore from "../stores/toastStore";
import "../styles/SessionForm.css";

function slugifyKey(label) {
  return label
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_|_$/g, "");
}

function SessionForm() {
  const navigate = useNavigate();
  const addToast = useToastStore((state) => state.addToast);
  const [categories, setCategories] = useState([]);
  const [categoryId, setCategoryId] = useState("");
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [dateStart, setDateStart] = useState("");
  const [dateEnd, setDateEnd] = useState("");
  const [durationOverride, setDurationOverride] = useState(null);
  const [notes, setNotes] = useState("");
  const [extraData, setExtraData] = useState({});
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isLoading, setIsLoading] = useState(true);

  useState(() => {
    async function load() {
      try {
        const data = await getCategories();
        setCategories(Array.isArray(data) ? data : []);
      } catch (err) {
        console.error(err);
      } finally {
        setIsLoading(false);
      }
    }
    load();
  }, []);

  const computedDuration = (() => {
    if (!dateStart || !dateEnd) return "";
    const start = new Date(dateStart);
    const end = new Date(dateEnd);
    const diffSeconds = Math.round((end - start) / 1000);
    return diffSeconds > 0 ? diffSeconds : "";
  })();

  const duration =
    durationOverride !== null ? durationOverride : computedDuration;

  const selectedCategory = categories.find(
    (c) => String(c.id) === String(categoryId),
  );
  const dynamicFields = Array.isArray(selectedCategory?.metadata?.fields)
    ? selectedCategory.metadata.fields
    : [];
  const dynamicUnits = Array.isArray(selectedCategory?.metadata?.units)
    ? selectedCategory.metadata.units
    : [];

  const handleExtraChange = (fieldLabel, value) => {
    const key = slugifyKey(fieldLabel);
    setExtraData((prev) => ({ ...prev, [key]: value }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();

    if (!categoryId || !title || !dateStart || !dateEnd) {
      addToast(
        "Catégorie, titre, date de début et date de fin sont obligatoires.",
        "error",
      );
      return;
    }

    setIsSubmitting(true);
    try {
      const payload = {
        category_id: Number(categoryId),
        title,
        description: description || null,
        date_start: dateStart.replace("T", " ") + ":00",
        date_end: dateEnd.replace("T", " ") + ":00",
        duration: duration ? Number(duration) : null,
        notes: notes || null,
        data: extraData,
      };

      const session = await createSession(payload);
      addToast("Session créée avec succès !", "success");
      navigate(`/sessions/${session.id}`);
    } catch (err) {
      addToast(
        err.response?.data?.error ||
          err.response?.data?.errors?.join(", ") ||
          "Erreur lors de la création de la session.",
        "error",
      );
    } finally {
      setIsSubmitting(false);
    }
  };

  if (isLoading) {
    return <div className="session-form-loading">Chargement...</div>;
  }

  return (
    <div className="session-form-page">
      <h1 className="session-form-title">Nouvelle session</h1>

      <form onSubmit={handleSubmit} className="session-form-card">
        <div className="session-form-field">
          <label htmlFor="category">Catégorie *</label>
          <select
            id="category"
            value={categoryId}
            onChange={(e) => setCategoryId(e.target.value)}
            required
          >
            <option value="">Sélectionne une catégorie</option>
            {categories.map((cat) => (
              <option key={cat.id} value={cat.id}>
                {cat.name}
              </option>
            ))}
          </select>
        </div>

        <div className="session-form-field">
          <label htmlFor="title">Titre *</label>
          <input
            id="title"
            type="text"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            required
          />
        </div>

        <div className="session-form-field">
          <label htmlFor="description">Description</label>
          <textarea
            id="description"
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            rows={3}
          />
        </div>

        <div className="session-form-row">
          <div className="session-form-field">
            <label htmlFor="date_start">Date de début *</label>
            <input
              id="date_start"
              type="datetime-local"
              value={dateStart}
              onChange={(e) => setDateStart(e.target.value)}
              required
            />
          </div>

          <div className="session-form-field">
            <label htmlFor="date_end">Date de fin *</label>
            <input
              id="date_end"
              type="datetime-local"
              value={dateEnd}
              onChange={(e) => setDateEnd(e.target.value)}
              required
            />
          </div>
        </div>

        <div className="session-form-field">
          <label htmlFor="duration">Durée (secondes)</label>
          <input
            id="duration"
            type="number"
            value={duration}
            onChange={(e) => setDurationOverride(e.target.value)}
            placeholder="Calculée automatiquement si les deux dates sont remplies"
          />
        </div>

        {dynamicFields.length > 0 && (
          <div className="session-form-dynamic">
            <span className="session-form-dynamic-title">
              Données spécifiques — {selectedCategory.name}
            </span>
            {dynamicFields.map((field, index) => (
              <div className="session-form-field" key={field}>
                <label htmlFor={`extra-${field}`}>
                  {field}
                  {dynamicUnits[index] ? ` (${dynamicUnits[index]})` : ""}
                </label>
                <input
                  id={`extra-${field}`}
                  type="text"
                  onChange={(e) => handleExtraChange(field, e.target.value)}
                />
              </div>
            ))}
          </div>
        )}

        <div className="session-form-field">
          <label htmlFor="notes">Notes</label>
          <textarea
            id="notes"
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            rows={3}
          />
        </div>

        <button
          type="submit"
          className="session-form-submit"
          disabled={isSubmitting}
        >
          {isSubmitting ? "Création..." : "Créer la session"}
        </button>
      </form>
    </div>
  );
}

export default SessionForm;
