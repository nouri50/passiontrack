import { useEffect, useState } from "react";
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
      addToast("Le nom de la catégorie est obligatoire.", "error");
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
        addToast("Catégorie mise à jour.", "success");
      } else {
        await createCategory(payload);
        addToast("Catégorie créée avec succès.", "success");
      }

      resetForm();
      await loadCategories();
    } catch (err) {
      addToast(
        err.response?.data?.error || "Erreur lors de l'enregistrement.",
        "error",
      );
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleDeactivate = async (id) => {
    if (
      !confirm(
        "Désactiver cette catégorie ? Elle ne sera plus proposée dans les formulaires.",
      )
    )
      return;
    try {
      await deleteCategory(id);
      addToast("Catégorie désactivée.", "success");
      await loadCategories();
    } catch (err) {
      addToast("Erreur lors de la désactivation.", "error");
    }
  };

  if (isLoading) {
    return <div className="categories-loading">Chargement...</div>;
  }

  return (
    <div className="categories-page">
      <h1 className="categories-title">Gestion des catégories</h1>

      <div className="categories-layout">
        <form onSubmit={handleSubmit} className="category-form">
          <h2>{editingId ? "Modifier la catégorie" : "Nouvelle catégorie"}</h2>

          <div className="category-form-field">
            <label htmlFor="cat-name">Nom *</label>
            <input
              id="cat-name"
              type="text"
              value={form.name}
              onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))}
              required
            />
          </div>

          <div className="category-form-field">
            <label htmlFor="cat-color">Couleur</label>
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
            <label htmlFor="cat-description">Description</label>
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
              Champs dynamiques
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
                  placeholder="Nom du champ"
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
                  <option value="text">Texte</option>
                  <option value="number">Nombre</option>
                  <option value="boolean">Oui / Non</option>
                  <option value="select">Liste déroulante</option>
                </select>
              </div>

              {newField.type !== "boolean" && (
                <div className="category-add-row">
                  {newField.type === "select" ? (
                    <input
                      type="text"
                      placeholder="Options séparées par des virgules"
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
                      placeholder="Unité (optionnel)"
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
                    Ajouter ce champ
                  </button>
                </div>
              )}
            </div>
          </div>

          <div className="category-form-section">
            <span className="category-form-section-title">Sous-catégories</span>

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
                placeholder="Nom de la sous-catégorie"
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
                ? "Enregistrement..."
                : editingId
                  ? "Mettre à jour"
                  : "Créer la catégorie"}
            </button>
            {editingId && (
              <button
                type="button"
                className="category-cancel"
                onClick={resetForm}
              >
                Annuler
              </button>
            )}
          </div>
        </form>

        <div className="categories-list">
          <h2>Catégories existantes</h2>
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
                    Éditer
                  </button>
                  <button
                    onClick={() => handleDeactivate(cat.id)}
                    className="category-item-btn category-item-btn--danger"
                  >
                    Désactiver
                  </button>
                </div>
              </div>
              {cat.description && (
                <p className="category-item-desc">{cat.description}</p>
              )}
              <div className="category-item-meta">
                {(cat.metadata?.fields || []).length} champs ·{" "}
                {(cat.metadata?.subcategories || []).length} sous-catégories
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

export default Categories;
