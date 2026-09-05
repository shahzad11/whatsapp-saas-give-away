# Debian slim rather than Alpine: Baileys pulls in sharp/protobuf, whose
# prebuilt binaries are far better tested against glibc than musl.
FROM node:22-bookworm-slim

# ffmpeg is a hard requirement, not a nicety: a browser-recorded voice note is
# webm/opus or mp4/aac, and WhatsApp only renders a voice message from ogg/opus.
# Baileys also uses it to extract video thumbnails.
RUN apt-get update \
 && apt-get install -y --no-install-recommends curl ca-certificates ffmpeg \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy manifests first so `npm ci` is cached independently of source changes.
COPY backend-node/package.json backend-node/package-lock.json* ./
RUN npm ci --omit=dev

COPY backend-node/src ./src

# Session credentials live here. Owned by the unprivileged user because the
# process must not run as root: a WhatsApp auth store is the crown jewels.
ENV DATA_DIR=/data \
    PORT=3001 \
    BIND_HOST=0.0.0.0 \
    NODE_ENV=production

RUN mkdir -p /data && chown -R node:node /data /app
USER node

VOLUME ["/data"]
EXPOSE 3001

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD curl -fsS http://127.0.0.1:3001/health || exit 1

CMD ["node", "src/index.js"]
