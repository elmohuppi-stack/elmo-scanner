# Elmo Scanner

RSS Reader MVP mit Laravel API, Vue Frontend und PostgreSQL in Docker.

## Neu (März 2026)

- Sidebar-Filter verbessert: aktiver Tag-Filter ist im Feeds-Tab direkt sichtbar.
- Im Feeds-Tab gibt es einen direkten "Filter zuruecksetzen"-Button neben der Feed-Suche.
- Feed-Zaehler zeigen bei aktivem Tag-Filter jetzt Treffer und Gesamtzahl pro Feed (`x von y Artikel`).
- Feed-Buttons ohne Treffer fuer den aktiven Tag-Filter werden deaktiviert, um unnoetige Klicks zu vermeiden.

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

## Railway Deployment

Dieses Projekt wird auf Railway nicht ueber GitHub-Autodeploy aus dem Repo-Root deployed, sondern gezielt pro Service aus den jeweiligen Unterordnern.

### Wichtig

- Das Repo-Root kann in Railway auf den Service `Postgres` gelinkt sein.
- Ein `railway up` aus dem Projekt-Root kann dann gegen den DB-Service laufen und Fehler wie `Script start.sh not found` oder `Railpack could not determine how to build the app.` ausloesen.
- Deshalb immer aus dem passenden Service-Verzeichnis deployen.

### Backend deployen

```bash
cd backend
railway up . --service backend --path-as-root --detach
```

Hinweise:

- Das Backend wird ueber `backend/Dockerfile` gebaut.
- Beim Container-Start laufen die Migrationen automatisch ueber `php artisan migrate --force`.
- Die produktive Backend-URL ist:

```text
https://backend-production-9a1c.up.railway.app
```

### Frontend deployen

```bash
cd frontend
railway up . --service frontend --path-as-root --detach
```

Hinweise:

- Das Frontend wird ueber `frontend/Dockerfile` gebaut.
- In Produktion darf das Frontend nicht nur relative `/api/...` Requests verwenden, wenn es auf einer eigenen Domain laeuft.
- Die App muss auf die Railway-Backend-URL zeigen, sonst liefert der Frontend-Host HTML statt JSON und der Browser meldet `Unexpected token '<'`.

### Produktions-Check

Backend direkt pruefen:

```bash
curl -i https://backend-production-9a1c.up.railway.app/api/feeds?per_page=1
```

Frontend direkt pruefen:

```bash
curl -I https://frontend-production-e7bf.up.railway.app/
```

Logs ansehen:

```bash
cd backend
railway logs --service backend --build -n 100
railway logs --service backend --deployment -n 100

cd ../frontend
railway logs --service frontend --build -n 100
railway logs --service frontend --deployment -n 100
```

### Bekannte Stolpersteine

- `start.sh not found`:
  meist wurde gegen den falschen Railway-Service deployt.
- `Unexpected token '<'` im Frontend:
  das Frontend spricht die falsche URL an und bekommt HTML statt JSON.
- `Deploy failed` in der CLI trotz erfolgreichem Build:
  immer die Build- und Deployment-Logs des richtigen Service pruefen, nicht nur die CLI-Zeile.
