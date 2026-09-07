import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import {
  getCategories,
  createCategory,
  updateCategory,
  deleteCategory,
} from "../services/categoryService";
import useToastStore from "../stores/toastStore";
import "../styles/Categories.css";

function slugify(name) {
  return name
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "");
}

const emptyForm = {
  name: "",
  color_hex: "#667eea",
  description: "",
  fields: [], // [{ label: '', unit: '', type: 'text', options: [] }]
  subcategories: [],
};

const emptyNewField = {
  label: "",
  unit: "",
  type: "text",
  optionsInput: "",
};

function Categories() {
  const { t } = useTranslation();
  const addToast = useToastStore((state) => state.addToast);
  const [categories, setCategories] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [editingId, setEditingId] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [newField, setNewField] = useState(emptyNewField);
  const [newSubcategory, setNewSubcategory] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);

  const loadCategories = async () => {
    try {
      const data = await getCategories();
      setCategories(Array.isArray(data) ? data : []);
    } catch (err) {
      console.error(err);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    loadCategories();
  }, []);

  const resetForm = () => {
    setForm(emptyForm);
    setEditingId(null);
    setNewField(emptyNewField);
    setNewSubcategory("");
  };

  const startEdit = (cat) => {
    const rawFields = Array.isArray(cat.metadata?.fields)
      ? cat.metadata.fields
      : [];
    const units = Array.isArray(cat.metadata?.units) ? cat.metadata.units : [];
    const types = Array.isArray(cat.metadata?.types) ? cat.metadata.types : [];
    const optionsList = Array.isArray(cat.metadata?.options)
      ? cat.metadata.options
      : [];

    const fields = rawFields.map((label, i) => ({
      label,
      unit: units[i] || "",
      type: types[i] || "text",
      options: Array.isArray(optionsList[i]) ? optionsList[i] : [],
    }));

    setForm({
      name: cat.name,
      color_hex: cat.color_hex || "#667eea",
      description: cat.description || "",
      fields,
      subcategories: Array.isArray(cat.metadata?.subcategories)
        ? cat.metadata.subcategories
        : [],
    });
    setEditingId(cat.id);
  };

  const addField = () => {
    if (!newField.label.trim()) return;

    const options =
      newField.type === "select"
        ? newField.optionsInput
            .split(",")
            .map((o) => o.trim())
            .filter(Boolean)
        : [];

    setForm((prev) => ({
      ...prev,
      fields: [
        ...prev.fields,
        {
          label: newField.label.trim(),
          unit: newField.unit.trim(),
          type: newField.type,
          options,
        },
      ],
    }));
    setNewField(emptyNewField);
  };

  const removeField = (index) => {
    setForm((prev) => ({
      ...prev,
      fields: prev.fields.filter((_, i) => i !== index),
    }));
  };

  const addSubcategory = () => {
    if (!newSubcategory.trim()) return;
    setForm((prev) => ({
      ...prev,
      subcategories: [...prev.subcategories, newSubcategory.trim()],
    }));
    setNewSubcategory("");
  };

  const removeSubcategory = (index) => {
    setForm((prev) => ({
      ...prev,
      subcategories: prev.subcategories.filter((_, i) => i !== index),
    }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!form.name.trim()) {
      addToast(t("categories.errorNameRequired"), "error");
      return;
    }

    setIsSubmitting(true);
    try {
      const payload = {
        name: form.name,
        slug: slugify(form.name),
        color_hex: form.color_hex,
        description: form.description || null,
        metadata: {
          fields: form.fields.map((f) => f.label),
          units: form.fields.map((f) => f.unit),
          types: form.fields.map((f) => f.type),
          options: form.fields.map((f) => f.options || []),
          subcategories: form.subcategories,
        },
      };

      if (editingId) {
        await updateCategory(editingId, payload);
        addToast(t("categories.successUpdated"), "success");
      } else {
        await createCategory(payload);
        addToast(t("categories.successCreated"), "success");
      }

      resetForm();
      await loadCategories();
    } catch (err) {
      addToast(
        err.response?.data?.error || t("categories.errorSaving"),
        "error",
      );
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleDeactivate = async (id) => {
    if (!confirm(t("categories.deactivateConfirm"))) return;
    try {
      await deleteCategory(id);
      addToast(t("categories.successDeactivated"), "success");
      await loadCategories();
    } catch (err) {
      addToast(t("categories.errorDeactivating"), "error");
    }
  };

  if (isLoading) {
    return <div className="categories-loading">{t("common.loading")}</div>;
  }

  return (
    <div className="categories-page">
      <h1 className="categories-title">{t("categories.pageTitle")}</h1>

      <div className="categories-layout">
        <form onSubmit={handleSubmit} className="category-form">
          <h2>
            {editingId
              ? t("categories.editCategoryTitle")
              : t("categories.newCategoryTitle")}
          </h2>

          <div className="category-form-field">
            <label htmlFor="cat-name">{t("categories.name")}</label>
            <input
              id="cat-name"
              type="text"
              value={form.name}
              onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))}
              required
            />
          </div>

          <div className="category-form-field">
            <label htmlFor="cat-color">{t("categories.color")}</label>
            <div className="category-color-row">
              <input
                id="cat-color"
                type="color"
                value={form.color_hex}
                onChange={(e) =>
                  setForm((p) => ({ ...p, color_hex: e.target.value }))
                }
              />
              <span className="category-color-value">{form.color_hex}</span>
            </div>
          </div>

          <div className="category-form-field">
            <label htmlFor="cat-description">
              {t("sessionForm.description")}
            </label>
            <textarea
              id="cat-description"
              value={form.description}
              onChange={(e) =>
                setForm((p) => ({ ...p, description: e.target.value }))
              }
              rows={2}
            />
          </div>

          <div className="category-form-section">
            <span className="category-form-section-title">
              {t("categories.dynamicFields")}
            </span>

            {form.fields.length > 0 && (
              <ul className="category-tag-list">
                {form.fields.map((f, i) => (
                  <li key={i} className="category-tag">
                    {f.label}
                    {f.unit ? ` (${f.unit})` : ""}
                    <span className="category-tag-type">{f.type}</span>
                    <button type="button" onClick={() => removeField(i)}>
                      ×
                    </button>
                  </li>
                ))}
              </ul>
            )}

            <div className="category-new-field">
              <div className="category-add-row">
                <input
                  type="text"
                  placeholder={t("categories.fieldNamePlaceholder")}
                  value={newField.label}
                  onChange={(e) =>
                    setNewField((p) => ({ ...p, label: e.target.value }))
                  }
                />
                <select
                  value={newField.type}
                  onChange={(e) =>
                    setNewField((p) => ({ ...p, type: e.target.value }))
                  }
                >
                  <option value="text">{t("categories.fieldTypeText")}</option>
                  <option value="number">
                    {t("categories.fieldTypeNumber")}
                  </option>
                  <option value="boolean">
                    {t("categories.fieldTypeBoolean")}
                  </option>
                  <option value="select">
                    {t("categories.fieldTypeSelect")}
                  </option>
                </select>
              </div>

              {newField.type !== "boolean" && (
                <div className="category-add-row">
                  {newField.type === "select" ? (
                    <input
                      type="text"
                      placeholder={t("categories.optionsPlaceholder")}
                      value={newField.optionsInput}
                      onChange={(e) =>
                        setNewField((p) => ({
                          ...p,
                          optionsInput: e.target.value,
                        }))
                      }
                    />
                  ) : (
                    <input
                      type="text"
                      placeholder={t("categories.unitPlaceholder")}
                      value={newField.unit}
                      onChange={(e) =>
                        setNewField((p) => ({ ...p, unit: e.target.value }))
                      }
                    />
                  )}
                  <button
                    type="button"
                    onClick={addField}
                    className="category-add-btn"
                  >
                    +
                  </button>
                </div>
              )}

              {newField.type === "boolean" && (
                <div className="category-add-row">
                  <button
                    type="button"
                    onClick={addField}
                    className="category-add-btn category-add-btn--wide"
                  >
                    {t("categories.addThisField")}
                  </button>
                </div>
              )}
            </div>
          </div>

          <div className="category-form-section">
            <span className="category-form-section-title">
              {t("categories.subcategories")}
            </span>

            {form.subcategories.length > 0 && (
              <ul className="category-tag-list">
                {form.subcategories.map((s, i) => (
                  <li key={i} className="category-tag">
                    {s}
                    <button type="button" onClick={() => removeSubcategory(i)}>
                      ×
                    </button>
                  </li>
                ))}
              </ul>
            )}

            <div className="category-add-row">
              <input
                type="text"
                placeholder={t("categories.subcategoryPlaceholder")}
                value={newSubcategory}
                onChange={(e) => setNewSubcategory(e.target.value)}
              />
              <button
                type="button"
                onClick={addSubcategory}
                className="category-add-btn"
              >
                +
              </button>
            </div>
          </div>

          <div className="category-form-actions">
            <button
              type="submit"
              className="category-submit"
              disabled={isSubmitting}
            >
              {isSubmitting
                ? t("sessionEdit.submitting")
                : editingId
                  ? t("categories.update")
                  : t("categories.createCategory")}
            </button>
            {editingId && (
              <button
                type="button"
                className="category-cancel"
                onClick={resetForm}
              >
                {t("common.cancel")}
              </button>
            )}
          </div>
        </form>

        <div className="categories-list">
          <h2>{t("categories.existingCategories")}</h2>
          {categories.map((cat) => (
            <div
              key={cat.id}
              className="category-item"
              style={{ borderLeftColor: cat.color_hex }}
            >
              <div className="category-item-header">
                <span className="category-item-name">{cat.name}</span>
                <div className="category-item-actions">
                  <button
                    onClick={() => startEdit(cat)}
                    className="category-item-btn"
                  >
                    {t("categories.edit")}
                  </button>
                  <button
                    onClick={() => handleDeactivate(cat.id)}
                    className="category-item-btn category-item-btn--danger"
                  >
                    {t("categories.deactivate")}
                  </button>
                </div>
              </div>
              {cat.description && (
                <p className="category-item-desc">{cat.description}</p>
              )}
              <div className="category-item-meta">
                {t("categories.fieldsCount", {
                  count: (cat.metadata?.fields || []).length,
                })}{" "}
                ·{" "}
                {t("categories.subcategoriesCount", {
                  count: (cat.metadata?.subcategories || []).length,
                })}
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

export default Categories;
