#!/bin/sh
set -e

echo "→ Installation des dépendances npm..."
npm install

echo "→ Démarrage de Vite sur 0.0.0.0:5173..."
exec npm run dev -- --host 0.0.0.0
