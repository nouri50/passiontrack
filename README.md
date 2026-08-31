# PassionTrack

Application web de suivi de hobbies et passions (aviation, racing, gaming, fitness, apprentissage...) avec analyse IA personnalisée de la progression.

## Stack technique

**Frontend**
- React + Vite (JavaScript)
- Zustand (state management)
- react-router-dom

**Backend**
- Symfony 8.0 (PHP 8.4)
- Doctrine ORM
- JWT Auth (LexikJWTAuthenticationBundle + refresh token)
- Nelmio CORS Bundle

**Base de données**
- MySQL / PostgreSQL

**IA**
- Ollama (Mistral 7B) en développement local
- API Claude (Anthropic) prévue en production
- Architecture switchable via `AIProviderInterface`


## Fonctionnalités

- Authentification JWT (login, register, refresh token)
- Gestion de profil utilisateur (avatar, préférences)
- Catégories de hobbies extensibles (aviation, racing, gaming, fitness...)
- Tracking de sessions avec données flexibles selon la catégorie
- Analyse IA personnalisée par session
- Notifications (progression, conseils IA)
- Intégrations externes (MSFS, iRacing, Discord, Slack) — prévu
- Mode dark/light
- Multi-langue FR/EN

## Installation

### Backend

```bash
cd backend
composer install
```

Configure `.env.local` :
```env
DATABASE_URL="mysql://user:password@127.0.0.1:3306/passion_track"
JWT_PASSPHRASE=your_passphrase
```

Génère les clés JWT :
```bash
mkdir -p config/jwt
openssl genrsa -out config/jwt/private.pem 4096
openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout
```

Crée la base et applique les migrations :
```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
```

Lance le serveur :
```bash
php -S 127.0.0.1:8000 -t public
```

### Frontend

```bash
cd frontend
npm install
npm run dev
```

## Entités principales

`User` → `Avatar` → `Category` → `Session` → `Analysis` → `Notification` → `Integration`

