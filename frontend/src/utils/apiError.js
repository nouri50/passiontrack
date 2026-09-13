/**
 * Traduit une erreur axios en message affichable à l'utilisateur.
 *
 * Priorité :
 *  1. Pas de réponse du tout (backend injoignable) -> message réseau
 *  2. Un code d'erreur connu renvoyé par le backend (ex: "EMAIL_ALREADY_IN_USE")
 *     -> traduit via la clé i18n `errors.<CODE>`
 *  3. Un tableau de messages de validation (Symfony validator) -> concaténés tels quels
 *  4. Status >= 500 sans code reconnu -> message serveur générique
 *  5. Sinon -> message de repli fourni par l'appelant
 *
 * @param {*} err - l'erreur capturée dans un bloc catch (typiquement une erreur axios)
 * @param {Function} t - la fonction de traduction de useTranslation()
 * @param {string} fallbackKey - clé i18n à utiliser si aucun cas ci-dessus ne s'applique
 * @returns {string} le message à afficher
 */
export function getApiErrorMessage(err, t, fallbackKey = "errors.generic") {
    if (!err?.response) {
        return t("auth.networkError");
    }

    const { status, data } = err.response;

    if (data?.error) {
        const translated = t(`errors.${data.error}`, { defaultValue: "" });
        if (translated) {
            return translated;
        }
    }

    if (Array.isArray(data?.errors) && data.errors.length > 0) {
        return data.errors.join(" ");
    }

    if (status >= 500) {
        return t("auth.serverError");
    }

    return t(fallbackKey);
}