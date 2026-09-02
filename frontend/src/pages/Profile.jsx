import { useState } from "react";
import useAuthStore from "../stores/authStore";
import useToastStore from "../stores/toastStore";
import { updateProfile, updatePassword } from "../services/userService";
import "../styles/Profile.css";

function initials(user) {
  const source = user?.first_name || user?.username || "?";
  return source.charAt(0).toUpperCase();
}

function Profile() {
  const { user, fetchUser } = useAuthStore();
  const addToast = useToastStore((state) => state.addToast);

  const [firstName, setFirstName] = useState(user?.first_name || "");
  const [lastName, setLastName] = useState(user?.last_name || "");
  const [email, setEmail] = useState(user?.email || "");
  const [profileSubmitting, setProfileSubmitting] = useState(false);

  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [passwordSubmitting, setPasswordSubmitting] = useState(false);

  const handleProfileSubmit = async (e) => {
    e.preventDefault();
    setProfileSubmitting(true);

    try {
      await updateProfile({
        first_name: firstName,
        last_name: lastName,
        email,
      });
      await fetchUser();
      addToast("Profil mis à jour avec succès.", "success");
    } catch (err) {
      addToast(
        err.response?.data?.error || "Erreur lors de la mise à jour du profil.",
        "error",
      );
    } finally {
      setProfileSubmitting(false);
    }
  };

  const handlePasswordSubmit = async (e) => {
    e.preventDefault();

    if (newPassword.length < 8) {
      addToast(
        "Le nouveau mot de passe doit faire au moins 8 caractères.",
        "error",
      );
      return;
    }
    if (newPassword !== confirmPassword) {
      addToast("Les mots de passe ne correspondent pas.", "error");
      return;
    }

    setPasswordSubmitting(true);
    try {
      await updatePassword({
        current_password: currentPassword,
        new_password: newPassword,
      });
      addToast("Mot de passe mis à jour avec succès.", "success");
      setCurrentPassword("");
      setNewPassword("");
      setConfirmPassword("");
    } catch (err) {
      addToast(
        err.response?.data?.error ||
          "Erreur lors du changement de mot de passe.",
        "error",
      );
    } finally {
      setPasswordSubmitting(false);
    }
  };

  return (
    <div className="profile-page">
      <h1 className="profile-title">Mon profil</h1>

      <div className="profile-header">
        <div className="profile-avatar">{initials(user)}</div>
        <div>
          <p className="profile-username">{user?.username}</p>
          <p className="profile-email">{user?.email}</p>
          <p className="profile-avatar-note">
            La personnalisation de l'avatar arrive bientôt.
          </p>
        </div>
      </div>

      <div className="profile-card">
        <h2>Informations personnelles</h2>
        <form onSubmit={handleProfileSubmit} className="profile-form">
          <div className="profile-row">
            <div className="profile-field">
              <label htmlFor="first_name">Prénom</label>
              <input
                id="first_name"
                type="text"
                value={firstName}
                onChange={(e) => setFirstName(e.target.value)}
              />
            </div>
            <div className="profile-field">
              <label htmlFor="last_name">Nom</label>
              <input
                id="last_name"
                type="text"
                value={lastName}
                onChange={(e) => setLastName(e.target.value)}
              />
            </div>
          </div>

          <div className="profile-field">
            <label htmlFor="email">Email</label>
            <input
              id="email"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
          </div>

          <button
            type="submit"
            className="profile-submit"
            disabled={profileSubmitting}
          >
            {profileSubmitting ? "Enregistrement..." : "Enregistrer"}
          </button>
        </form>
      </div>

      <div className="profile-card">
        <h2>Changer le mot de passe</h2>
        <form onSubmit={handlePasswordSubmit} className="profile-form">
          <div className="profile-field">
            <label htmlFor="current_password">Mot de passe actuel</label>
            <input
              id="current_password"
              type="password"
              value={currentPassword}
              onChange={(e) => setCurrentPassword(e.target.value)}
              required
            />
          </div>

          <div className="profile-row">
            <div className="profile-field">
              <label htmlFor="new_password">Nouveau mot de passe</label>
              <input
                id="new_password"
                type="password"
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
                required
              />
            </div>
            <div className="profile-field">
              <label htmlFor="confirm_password">Confirmation</label>
              <input
                id="confirm_password"
                type="password"
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
                required
              />
            </div>
          </div>

          <button
            type="submit"
            className="profile-submit"
            disabled={passwordSubmitting}
          >
            {passwordSubmitting ? "Modification..." : "Changer le mot de passe"}
          </button>
        </form>
      </div>
    </div>
  );
}

export default Profile;
