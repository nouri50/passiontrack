#!/bin/sh
set -e

echo "→ Installation des dépendances Composer..."
composer install --no-interaction

echo "→ Clés JWT (générées seulement si elles n'existent pas)..."
php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction

echo "→ Exécution des migrations Doctrine..."
php bin/console doctrine:migrations:migrate --no-interaction

echo "→ Démarrage du serveur PHP sur 0.0.0.0:8000..."
exec php -S 0.0.0.0:8000 -t public
