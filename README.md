# Elmo Scanner

RSS Reader MVP mit Laravel API, Vue Frontend und SQLite.

## Was macht diese App?

Elmo Scanner sammelt Artikel aus RSS/Atom-Feeds und stellt sie als durchsuchbare Uebersicht bereit.

- Feeds verwalten: Hinzufuegen, Bearbeiten, Loeschen, Sortieren
- Artikel lesen: Liste pro Feed, Detailansicht, Reader-Ansicht mit Fallback auf Feed-Summary
- Filtern: Volltextsuche und Tag/Kategorie-Filter
- Aktualisieren: Einzel-Refresh pro Feed und Bulk-Refresh fuer faellige Feeds
- Automatisierung: Geplanter Abruf ueber Laravel Scheduler (alle 10 Minuten)

Technisch besteht das Projekt aus einem Laravel-Backend (API + Feed-Ingestion) und einem Vue-Frontend (UI).

## Neu (März 2026)

- Sidebar-Filter verbessert: aktiver Tag-Filter ist im Feeds-Tab direkt sichtbar.
- Im Feeds-Tab gibt es einen direkten "Filter zuruecksetzen"-Button neben der Feed-Suche.
- Feed-Zaehler zeigen bei aktivem Tag-Filter jetzt Treffer und Gesamtzahl pro Feed (`x von y Artikel`).
- Feed-Buttons ohne Treffer fuer den aktiven Tag-Filter werden deaktiviert, um unnoetige Klicks zu vermeiden.

## Projektstruktur

- backend: Laravel API, Scheduler, Feed-Ingestion
- frontend: Vue 3 + Vite UI
- docker-compose.yml: Hetzner-Deploy-Stack fuer Frontend + Backend
- Makefile: Vereinfachte Befehle fuer lokales Testen

## Projekt-Notizen fuer Copilot

Eine kompakte technische Notiz fuer die Arbeit im Projekt liegt in `COPILOT_CONTEXT.md`.

## Voraussetzungen

- PHP 8.2+
- Composer
- Node.js 20+ und npm
- optional: Docker + Docker Compose fuer den Hetzner-Deploy-Stack

## Schnellstart (lokal)

1. Projekt einrichten:

```bash
make setup
```

2. Entwicklung starten:

```bash
make dev
```

Alternativ getrennt in zwei Terminals:

```bash
make backend-dev
make frontend-dev
```

3. App oeffnen:

- Frontend: http://127.0.0.1:5173
- Backend API: http://127.0.0.1:8000

## Wichtige Make Targets

```bash
make help
make setup
make dev
make db-up
make db-reset
make migrate
make seed
make fetch
make health
make docker-up
make docker-down
make test
```

## API Endpunkte (MVP)

- GET /api/feeds
- POST /api/feeds
- POST /api/admin/feeds/{feed}/fetch
- GET /api/articles

## Lokaler Ablauf mit SQLite

1. SQLite-Datei anlegen oder zuruecksetzen:

```bash
make db-up
# oder komplett neu
make db-reset
```

2. Migrationen und Seeds ausfuehren:

```bash
make migrate
make seed
```

3. Entwicklung starten:

```bash
make dev
```

4. API schnell pruefen:

```bash
make health
curl http://127.0.0.1:8000/api/feeds
curl http://127.0.0.1:8000/api/articles
curl -X POST http://127.0.0.1:8000/api/admin/feeds/1/fetch
```

## Hinweise

- Das Backend nutzt jetzt standardmaessig SQLite ueber `backend/database/database.sqlite`.
- Der Scheduler fuer periodisches Abrufen ist in backend/routes/console.php hinterlegt.
- Falls du die lokale Datenbank komplett zuruecksetzen willst: `make db-reset`

## Hetzner Deployment

Dieses Projekt wird auf dem gemeinsamen Hetzner-Server per `docker compose`, Host-`nginx` und separaten Subdomains deployed.

### Ziel-Domains

- Frontend: `https://elmo-scanner.elmarhepp.de`
- API: `https://elmo-scanner-api.elmarhepp.de`

### Server-Pfad

```bash
/var/www/elmo-scanner
```

### Deploy-Ablauf

```bash
ssh elmarhepp
cd /var/www/elmo-scanner
cp .env.example .env
cp backend/.env.production.example backend/.env.production

make docker-up
```

Danach wird auf dem Host eine Nginx-Site angelegt, die auf diese lokalen Container-Ports zeigt:

- Frontend: `127.0.0.1:3011`
- API: `127.0.0.1:3012`

### Produktions-Check

```bash
curl -I https://elmo-scanner.elmarhepp.de/
curl -i https://elmo-scanner-api.elmarhepp.de/api/health
curl -H "Origin: https://elmo-scanner.elmarhepp.de" -i https://elmo-scanner-api.elmarhepp.de/api/feeds?per_page=1
```

### Kuenftige Updates auf Hetzner

Nach weiteren Aenderungen reicht in der Regel dieser Ablauf:

```bash
ssh elmarhepp
cd /var/www/elmo-scanner
git pull --ff-only
docker compose up -d --build
curl -I https://elmo-scanner.elmarhepp.de/
curl -i https://elmo-scanner-api.elmarhepp.de/api/health
```

### Zertifikat / Browser-Hinweis

- Das Let's-Encrypt-Zertifikat ist gueltig fuer `elmo-scanner.elmarhepp.de` und `elmo-scanner-api.elmarhepp.de`.
- Wenn der Browser direkt nach dem ersten Livegang noch einen Zertifikatsfehler zeigt, liegt das oft an gecachtem DNS oder einer alten TLS-Session.
- Dann bitte die Seite hart neu laden, ein privates Fenster testen oder den Browser einmal komplett neu starten.
- Wichtig: die App nur ueber die exakten HTTPS-Domains aufrufen, nicht ueber die Server-IP.

### Wichtige Hinweise

- Lokal ist **kein Postgres und kein Docker** mehr noetig; SQLite reicht aus.
- Das Frontend liest die API-URL ueber `frontend/.env.production` (`VITE_API_BASE_URL`).
- Das Backend stellt fuer Monitoring und Reverse-Proxy-Checks einen JSON-Health-Endpoint unter `/api/health` bereit.
- `docker-compose.yml` ist fuer die Hetzner-Multi-App-Struktur vorbereitet und bindet nur an `127.0.0.1`.
