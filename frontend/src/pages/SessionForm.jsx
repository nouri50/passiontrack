import { useState, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import { useTranslation } from "react-i18next";
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
  const { t } = useTranslation();
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

  const handleCategoryChange = (value) => {
    setCategoryId(value);
    setSubcategory("");
    setExtraData({});
  };

  const handleExtraChange = (fieldLabel, value) => {
    const key = slugifyKey(fieldLabel);
    setExtraData((prev) => ({ ...prev, [key]: value }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();

    if (!categoryId || !title || !dateStart || !dateEnd) {
      addToast(t("sessionForm.errorRequired"), "error");
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

      const session = await createSession(payload);
      addToast(t("sessionForm.successCreated"), "success");
      navigate(`/sessions/${session.id}`);
    } catch (err) {
      addToast(
        err.response?.data?.error ||
          err.response?.data?.errors?.join(", ") ||
          t("sessionForm.errorGeneric"),
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
            <option value="">{t("sessionForm.selectPlaceholder")}</option>
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
    return <div className="session-form-loading">{t("common.loading")}</div>;
  }

  return (
    <div className="session-form-page">
      <h1 className="session-form-title">{t("sessionForm.newSessionTitle")}</h1>

      <form onSubmit={handleSubmit} className="session-form-card">
        <div className="session-form-field">
          <label htmlFor="category">{t("sessionForm.category")}</label>
          <select
            id="category"
            value={categoryId}
            onChange={(e) => handleCategoryChange(e.target.value)}
            required
          >
            <option value="">{t("sessionForm.selectCategory")}</option>
            {categories.map((cat) => (
              <option key={cat.id} value={cat.id}>
                {cat.name}
              </option>
            ))}
          </select>
        </div>

        {subcategoryOptions.length > 0 && (
          <div className="session-form-field">
            <label htmlFor="subcategory">{t("sessionForm.subcategory")}</label>
            <select
              id="subcategory"
              value={subcategory}
              onChange={(e) => setSubcategory(e.target.value)}
            >
              <option value="">{t("sessionForm.noneOrOther")}</option>
              {subcategoryOptions.map((sub) => (
                <option key={sub} value={sub}>
                  {sub}
                </option>
              ))}
            </select>
          </div>
        )}

        <div className="session-form-field">
          <label htmlFor="title">{t("sessionForm.titleLabel")}</label>
          <input
            id="title"
            type="text"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            required
          />
        </div>

        <div className="session-form-field">
          <label htmlFor="description">{t("sessionForm.description")}</label>
          <textarea
            id="description"
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            rows={3}
          />
        </div>

        <div className="session-form-row">
          <div className="session-form-field">
            <label htmlFor="date_start">{t("sessionForm.dateStart")}</label>
            <input
              id="date_start"
              type="datetime-local"
              value={dateStart}
              onChange={(e) => setDateStart(e.target.value)}
              required
            />
          </div>

          <div className="session-form-field">
            <label htmlFor="date_end">{t("sessionForm.dateEnd")}</label>
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
          <label htmlFor="duration">{t("sessionForm.duration")}</label>
          <input
            id="duration"
            type="number"
            value={duration}
            onChange={(e) => setDurationOverride(e.target.value)}
            placeholder={t("sessionForm.durationPlaceholder")}
          />
        </div>

        {dynamicFields.length > 0 && (
          <div className="session-form-dynamic">
            <span className="session-form-dynamic-title">
              {t("sessionForm.specificData")} {selectedCategory.name}
            </span>
            {dynamicFields.map((field, index) =>
              renderDynamicField(field, index),
            )}
          </div>
        )}

        <div className="session-form-field">
          <label htmlFor="notes">{t("sessionForm.notes")}</label>
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
          {isSubmitting ? t("sessionForm.submitting") : t("sessionForm.submit")}
        </button>
      </form>
    </div>
  );
}

export default SessionForm;
