import { useState, useEffect } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { getCategories } from "../services/categoryService";
import { getSession, updateSession } from "../services/sessionService";
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

function toDatetimeLocal(value) {
  if (!value) return "";
  const d = new Date(value.replace(" ", "T"));
  const pad = (n) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function SessionEdit() {
  const { id } = useParams();
  const navigate = useNavigate();
  const addToast = useToastStore((state) => state.addToast);

  const [categories, setCategories] = useState([]);
  const [categoryId, setCategoryId] = useState("");
  const [subcategory, setSubcategory] = useState("");
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [dateStart, setDateStart] = useState("");
  const [dateEnd, setDateEnd] = useState("");
  const [durationOverride, setDurationOverride] = useState(null);
  const [notes, setNotes] = useState("");
  const [extraData, setExtraData] = useState({});
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    async function load() {
      try {
        const [categoriesData, sessionData] = await Promise.all([
          getCategories(),
          getSession(id),
        ]);
        setCategories(Array.isArray(categoriesData) ? categoriesData : []);

        setCategoryId(String(sessionData.category_id));
        setSubcategory(sessionData.subcategory || "");
        setTitle(sessionData.title || "");
        setDescription(sessionData.description || "");
        setDateStart(toDatetimeLocal(sessionData.date_start));
        setDateEnd(toDatetimeLocal(sessionData.date_end));
        setDurationOverride(
          sessionData.duration ? String(sessionData.duration) : "",
        );
        setNotes(sessionData.notes || "");
        setExtraData(sessionData.data || {});
      } catch (err) {
        console.error(err);
        addToast("Impossible de charger cette session.", "error");
        navigate("/sessions");
      } finally {
        setIsLoading(false);
      }
    }
    load();
  }, [id]);

  const computedDuration = (() => {
    if (!dateStart || !dateEnd) return "";
    const start = new Date(dateStart);
    const end = new Date(dateEnd);
    const diffSeconds = Math.round((end - start) / 1000);
    return diffSeconds > 0 ? diffSeconds : "";
  })();

  const duration =
    durationOverride !== null && durationOverride !== ""
      ? durationOverride
      : computedDuration;

  const selectedCategory = categories.find(
    (c) => String(c.id) === String(categoryId),
  );
  const dynamicFields = Array.isArray(selectedCategory?.metadata?.fields)
    ? selectedCategory.metadata.fields
    : [];
  const dynamicUnits = Array.isArray(selectedCategory?.metadata?.units)
    ? selectedCategory.metadata.units
    : [];
  const dynamicTypes = Array.isArray(selectedCategory?.metadata?.types)
    ? selectedCategory.metadata.types
    : [];
  const dynamicOptions = Array.isArray(selectedCategory?.metadata?.options)
    ? selectedCategory.metadata.options
    : [];
  const subcategoryOptions = Array.isArray(
    selectedCategory?.metadata?.subcategories,
  )
    ? selectedCategory.metadata.subcategories
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
        subcategory: subcategory || null,
        title,
        description: description || null,
        date_start: dateStart.replace("T", " ") + ":00",
        date_end: dateEnd.replace("T", " ") + ":00",
        duration: duration ? Number(duration) : null,
        notes: notes || null,
        data: extraData,
      };

      await updateSession(id, payload);
      addToast("Session mise à jour avec succès !", "success");
      navigate(`/sessions/${id}`);
    } catch (err) {
      addToast(
        err.response?.data?.error ||
          err.response?.data?.errors?.join(", ") ||
          "Erreur lors de la mise à jour de la session.",
        "error",
      );
    } finally {
      setIsSubmitting(false);
    }
  };

  const renderDynamicField = (field, index) => {
    const key = slugifyKey(field);
    const type = dynamicTypes[index] || "text";
    const unit = dynamicUnits[index];
    const options = Array.isArray(dynamicOptions[index])
      ? dynamicOptions[index]
      : [];

    if (type === "boolean") {
      return (
        <div
          className="session-form-field session-form-field--checkbox"
          key={field}
        >
          <label
            htmlFor={`extra-${field}`}
            className="session-form-checkbox-label"
          >
            <input
              id={`extra-${field}`}
              type="checkbox"
              checked={extraData[key] === true || extraData[key] === "true"}
              onChange={(e) => handleExtraChange(field, e.target.checked)}
            />
            {field}
          </label>
        </div>
      );
    }

    if (type === "select") {
      return (
        <div className="session-form-field" key={field}>
          <label htmlFor={`extra-${field}`}>{field}</label>
          <select
            id={`extra-${field}`}
            value={extraData[key] || ""}
            onChange={(e) => handleExtraChange(field, e.target.value)}
          >
            <option value="">Sélectionner...</option>
            {options.map((opt) => (
              <option key={opt} value={opt}>
                {opt}
              </option>
            ))}
          </select>
        </div>
      );
    }

    return (
      <div className="session-form-field" key={field}>
        <label htmlFor={`extra-${field}`}>
          {field}
          {unit ? ` (${unit})` : ""}
        </label>
        <input
          id={`extra-${field}`}
          type={type === "number" ? "number" : "text"}
          value={extraData[key] || ""}
          onChange={(e) => handleExtraChange(field, e.target.value)}
        />
      </div>
    );
  };

  if (isLoading) {
    return <div className="session-form-loading">Chargement...</div>;
  }

  return (
    <div className="session-form-page">
      <h1 className="session-form-title">Modifier la session</h1>

      <form onSubmit={handleSubmit} className="session-form-card">
        <div className="session-form-field">
          <label htmlFor="category">Catégorie *</label>
          <select
            id="category"
            value={categoryId}
            onChange={(e) => {
              setCategoryId(e.target.value);
              setSubcategory("");
            }}
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

        {subcategoryOptions.length > 0 && (
          <div className="session-form-field">
            <label htmlFor="subcategory">Sous-catégorie</label>
            <select
              id="subcategory"
              value={subcategory}
              onChange={(e) => setSubcategory(e.target.value)}
            >
              <option value="">Aucune / Autre</option>
              {subcategoryOptions.map((sub) => (
                <option key={sub} value={sub}>
                  {sub}
                </option>
              ))}
            </select>
          </div>
        )}

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
          />
        </div>

        {dynamicFields.length > 0 && (
          <div className="session-form-dynamic">
            <span className="session-form-dynamic-title">
              Données spécifiques — {selectedCategory.name}
            </span>
            {dynamicFields.map((field, index) =>
              renderDynamicField(field, index),
            )}
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
          {isSubmitting ? "Enregistrement..." : "Enregistrer les modifications"}
        </button>
      </form>
    </div>
  );
}

export default SessionEdit;
