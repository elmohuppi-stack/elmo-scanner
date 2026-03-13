# Elmo Scanner

RSS Reader MVP mit Laravel API, Vue Frontend und PostgreSQL in Docker.

## Projektstruktur

- backend: Laravel API, Scheduler, Feed-Ingestion
- frontend: Vue 3 + Vite UI
- docker-compose.yml: Lokale PostgreSQL-Instanz
- Makefile: Vereinfachte Befehle fuer lokales Testen

## Voraussetzungen

- Docker + Docker Compose
- PHP 8.2+
- Composer
- Node.js 20+ und npm

## Schnellstart (lokal)

1. Projekt einrichten:

```bash
make setup
```

2. Backend starten (Terminal 1):

```bash
make backend-dev
```

3. Frontend starten (Terminal 2):

```bash
make frontend-dev
```

4. App oeffnen:

- Frontend: http://127.0.0.1:5173
- Backend API: http://127.0.0.1:8000

## Wichtige Make Targets

```bash
make help
make db-up
make db-down
make migrate
make seed
make fetch
make test
```

## API Endpunkte (MVP)

- GET /api/feeds
- POST /api/feeds
- POST /api/admin/feeds/{feed}/fetch
- GET /api/articles

## Lokaler Testablauf

1. Datenbank starten:

```bash
make db-up
```

2. Migrationen und Seeds:

```bash
make migrate
make seed
```

3. Einmaliger Feed-Import:

```bash
make fetch
```

4. API schnell pruefen:

```bash
curl http://127.0.0.1:8000/api/feeds
curl http://127.0.0.1:8000/api/articles
curl -X POST http://127.0.0.1:8000/api/admin/feeds/1/fetch
```

## Hinweise

- Die PostgreSQL-Zugangsdaten sind in docker-compose.yml definiert und in backend/.env bereits passend konfiguriert.
- Der Scheduler fuer periodisches Abrufen ist in backend/routes/console.php hinterlegt.
- Falls du die Datenbank komplett zuruecksetzen willst: make db-reset
