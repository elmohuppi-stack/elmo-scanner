# Copilot Context

Kurzkontext fuer schnelle Orientierung im Projekt.

## Zweck

Elmo Scanner ist ein RSS-Reader MVP.
Das System importiert RSS/Atom-Feeds, speichert Artikel in PostgreSQL und zeigt sie im Vue-Frontend.

## Architektur

- backend/: Laravel API, Feed-Import, Scheduler
- frontend/: Vue 3 + Vite UI
- docker-compose.yml: lokale PostgreSQL-Instanz
- Makefile: Standard-Workflows (Setup, Migration, Test, Fetch)

## Relevante API-Endpunkte

- GET /api/feeds
- POST /api/feeds
- PATCH /api/feeds/reorder
- PATCH /api/feeds/{feed}
- DELETE /api/feeds/{feed}
- POST /api/admin/feeds/{feed}/fetch
- POST /api/admin/feeds/fetch-all
- GET /api/articles
- GET /api/articles/{article}

## Import- und Scheduler-Logik

- Feed-Import ueber Artisan-Command: feeds:fetch
- Optional gezielter Import via --feed_id
- Faelligkeitslogik via --stale_for_minutes
- Scheduler ruft feeds:fetch alle 10 Minuten auf

## Lokale Befehle

- make setup
- make backend-dev
- make frontend-dev
- make db-up
- make migrate
- make seed
- make fetch
- make test

## Hinweis

Diese Datei ist absichtlich kurz gehalten und dient als schneller Einstieg fuer Assistenz-Tasks.
