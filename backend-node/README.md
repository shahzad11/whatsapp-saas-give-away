# WhatsApp SaaS Backend (Node + Baileys)

Multi-tenant WhatsApp session manager. Normally run as a container by the root
`docker-compose.yml`; the steps below are for running it directly.

## Setup
1. `cp .env.example .env`
2. Set `BACKEND_API_KEY` (min 32 chars, e.g. `openssl rand -hex 32`).
   The process **refuses to start** without it.
3. `npm install`
4. `npm run dev`

## Authentication
Every `/api/v1/*` request requires two headers:

| Header | Purpose |
|--------|---------|
| `X-Api-Key` | Shared secret proving the caller is the PHP frontend |
| `X-Tenant-Id` | Which tenant the caller acts for — `t<userId>`, e.g. `t42` |

`/health` is the only unauthenticated route.

## Tenancy
Tenant isolation is structural. Both the on-disk path
(`$DATA_DIR/tenants/<tenantId>/sessions/<sessionId>/`) and the in-memory key
(`<tenantId>:<sessionId>`) are derived from the **authenticated** tenant id, so
a caller cannot name another tenant's session — the address does not exist for
them. There is no endpoint that lists sessions across tenants.

Sessions created before tenancy existed are moved to
`tenants/_unassigned/sessions/` on boot and never served: `_unassigned` fails
the tenant-id validation regex.

## Endpoints
| Method | Path |
|--------|------|
| GET | `/health` |
| GET | `/api/v1/wa/sessions` |
| POST | `/api/v1/wa/sessions` |
| GET | `/api/v1/wa/sessions/:sessionId/status` |
| GET | `/api/v1/wa/sessions/:sessionId/qr` |
| POST | `/api/v1/wa/sessions/:sessionId/logout` |
| GET | `/api/v1/wa/sessions/:sessionId/chats` |
| GET | `/api/v1/wa/sessions/:sessionId/chats/:chatId/messages` |
| POST | `/api/v1/wa/sessions/:sessionId/chats/:chatId/messages` |
| GET | `/api/v1/wa/sessions/:sessionId/messages/:messageId/media` |
