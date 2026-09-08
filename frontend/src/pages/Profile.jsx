import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { useTranslation } from "react-i18next";
import useAuthStore from "../stores/authStore";
import useToastStore from "../stores/toastStore";
import {
  updateProfile,
  updatePassword,
  deleteAccount,
} from "../services/userService";
import ConfirmModal from "../components/ConfirmModal";
import "../styles/Profile.css";
import "../styles/ConfirmModal.css";

function initials(user) {
  const source = user?.first_name || user?.username || "?";
  return source.charAt(0).toUpperCase();
}

function Profile() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { user, fetchUser, logout } = useAuthStore();
  const addToast = useToastStore((state) => state.addToast);

  const [firstName, setFirstName] = useState(user?.first_name || "");
  const [lastName, setLastName] = useState(user?.last_name || "");
  const [email, setEmail] = useState(user?.email || "");
  const [profileSubmitting, setProfileSubmitting] = useState(false);

  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [passwordSubmitting, setPasswordSubmitting] = useState(false);

  const [showCurrentPassword, setShowCurrentPassword] = useState(false);
  const [showNewPassword, setShowNewPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);

  const [deletePassword, setDeletePassword] = useState("");
  const [showDeletePassword, setShowDeletePassword] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [showDeleteModal, setShowDeleteModal] = useState(false);

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
      addToast(t("profile.successProfileUpdated"), "success");
    } catch (err) {
      addToast(
        err.response?.data?.error || t("profile.errorProfileUpdate"),
        "error",
      );
    } finally {
      setProfileSubmitting(false);
    }
  };

  const handlePasswordSubmit = async (e) => {
    e.preventDefault();

    if (newPassword.length < 8) {
      addToast(t("profile.errorPasswordTooShort"), "error");
      return;
    }
    if (newPassword !== confirmPassword) {
      addToast(t("profile.errorPasswordMismatch"), "error");
      return;
    }

    setPasswordSubmitting(true);
    try {
      await updatePassword({
        current_password: currentPassword,
        new_password: newPassword,
      });
      addToast(t("profile.successPasswordUpdated"), "success");
      setCurrentPassword("");
      setNewPassword("");
      setConfirmPassword("");
    } catch (err) {
      addToast(
        err.response?.data?.error || t("profile.errorPasswordChange"),
        "error",
      );
    } finally {
      setPasswordSubmitting(false);
    }
  };

  const handleDeleteFormSubmit = (e) => {
    e.preventDefault();

    if (!deletePassword) {
      addToast(t("profile.errorPasswordRequired"), "error");
      return;
    }

    setShowDeleteModal(true);
  };

  const confirmDeleteAccount = async () => {
    setShowDeleteModal(false);
    setIsDeleting(true);

    try {
      await deleteAccount({ password: deletePassword });
      addToast(t("profile.successDeleted"), "success");
      logout();
      navigate("/login");
    } catch (err) {
      addToast(
        err.response?.data?.error || t("profile.errorDeleting"),
        "error",
      );
      setIsDeleting(false);
    }
  };

  return (
    <div className="profile-page">
      <h1 className="profile-title">{t("profile.title")}</h1>

      <div className="profile-header">
        <div className="profile-avatar">{initials(user)}</div>
        <div>
          <p className="profile-username">{user?.username}</p>
          <p className="profile-email">{user?.email}</p>
          <p className="profile-avatar-note">{t("profile.avatarComingSoon")}</p>
        </div>
      </div>

      <div className="profile-card">
        <h2>{t("profile.personalInfo")}</h2>
        <form onSubmit={handleProfileSubmit} className="profile-form">
          <div className="profile-row">
            <div className="profile-field">
              <label htmlFor="first_name">{t("profile.firstName")}</label>
              <input
                id="first_name"
                type="text"
                value={firstName}
                onChange={(e) => setFirstName(e.target.value)}
              />
            </div>
            <div className="profile-field">
              <label htmlFor="last_name">{t("profile.lastName")}</label>
              <input
                id="last_name"
                type="text"
                value={lastName}
                onChange={(e) => setLastName(e.target.value)}
              />
            </div>
          </div>

          <div className="profile-field">
            <label htmlFor="email">{t("auth.email")}</label>
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
            {profileSubmitting ? t("sessionEdit.submitting") : t("common.save")}
          </button>
        </form>
      </div>

      <div className="profile-card">
        <h2>{t("profile.changePassword")}</h2>
        <form onSubmit={handlePasswordSubmit} className="profile-form">
          <div className="profile-field">
            <label htmlFor="current_password">
              {t("profile.currentPassword")}
            </label>
            <div className="profile-password-wrapper">
              <input
                id="current_password"
                type={showCurrentPassword ? "text" : "password"}
                value={currentPassword}
                onChange={(e) => setCurrentPassword(e.target.value)}
                required
              />
              <button
                type="button"
                className="profile-password-toggle"
                onClick={() => setShowCurrentPassword((prev) => !prev)}
                aria-label={
                  showCurrentPassword
                    ? t("auth.hidePassword")
                    : t("auth.showPassword")
                }
              >
                {showCurrentPassword ? "🙈" : "👁️"}
              </button>
            </div>
          </div>

          <div className="profile-row">
            <div className="profile-field">
              <label htmlFor="new_password">{t("profile.newPassword")}</label>
              <div className="profile-password-wrapper">
                <input
                  id="new_password"
                  type={showNewPassword ? "text" : "password"}
                  value={newPassword}
                  onChange={(e) => setNewPassword(e.target.value)}
                  required
                />
                <button
                  type="button"
                  className="profile-password-toggle"
                  onClick={() => setShowNewPassword((prev) => !prev)}
                  aria-label={
                    showNewPassword
                      ? t("auth.hidePassword")
                      : t("auth.showPassword")
                  }
                >
                  {showNewPassword ? "🙈" : "👁️"}
                </button>
              </div>
            </div>
            <div className="profile-field">
              <label htmlFor="confirm_password">
                {t("auth.confirmPassword")}
              </label>
              <div className="profile-password-wrapper">
                <input
                  id="confirm_password"
                  type={showConfirmPassword ? "text" : "password"}
                  value={confirmPassword}
                  onChange={(e) => setConfirmPassword(e.target.value)}
                  required
                />
                <button
                  type="button"
                  className="profile-password-toggle"
                  onClick={() => setShowConfirmPassword((prev) => !prev)}
                  aria-label={
                    showConfirmPassword
                      ? t("auth.hidePassword")
                      : t("auth.showPassword")
                  }
                >
                  {showConfirmPassword ? "🙈" : "👁️"}
                </button>
              </div>
            </div>
          </div>

          <button
            type="submit"
            className="profile-submit"
            disabled={passwordSubmitting}
          >
            {passwordSubmitting
              ? t("profile.updating")
              : t("profile.changePassword")}
          </button>
        </form>
      </div>

      <div className="profile-card profile-danger-zone">
        <h2 className="profile-danger-title">{t("profile.dangerZone")}</h2>
        <p className="profile-danger-warning">
          {t("profile.deleteAccountWarning")}
        </p>

        <form onSubmit={handleDeleteFormSubmit} className="profile-form">
          <div className="profile-field">
            <label htmlFor="delete_password">
              {t("profile.deleteAccountPasswordLabel")}
            </label>
            <div className="profile-password-wrapper">
              <input
                id="delete_password"
                type={showDeletePassword ? "text" : "password"}
                value={deletePassword}
                onChange={(e) => setDeletePassword(e.target.value)}
              />
              <button
                type="button"
                className="profile-password-toggle"
                onClick={() => setShowDeletePassword((prev) => !prev)}
                aria-label={
                  showDeletePassword
                    ? t("auth.hidePassword")
                    : t("auth.showPassword")
                }
              >
                {showDeletePassword ? "🙈" : "👁️"}
              </button>
            </div>
          </div>

          <button
            type="submit"
            className="profile-delete-btn"
            disabled={isDeleting}
          >
            {isDeleting
              ? t("profile.deleting")
              : t("profile.deleteAccountButton")}
          </button>
        </form>
      </div>

      <ConfirmModal
        isOpen={showDeleteModal}
        title={t("profile.deleteAccountModalTitle")}
        message={t("profile.deleteAccountConfirm")}
        confirmLabel={t("profile.deleteAccountButton")}
        cancelLabel={t("common.cancel")}
        onConfirm={confirmDeleteAccount}
        onCancel={() => setShowDeleteModal(false)}
        danger
      />
    </div>
  );
}

export default Profile;
