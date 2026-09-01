# syntax=docker/dockerfile:1

# ---------- build stage ----------
FROM node:22-alpine AS build
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY tsconfig.json ./
COPY src ./src
RUN npm run build

# ---------- runtime stage ----------
FROM node:22-alpine AS runtime
WORKDIR /app

ENV NODE_ENV=production \
    PORT=3100 \
    DATA_DIR=/app/data

# package.json остаётся в рантайме: compose запускает `npm run keygen`
COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force

COPY --from=build /app/dist ./dist

# Каталог состояния создаём и отдаём пользователю node ДО объявления VOLUME,
# иначе docker создаст анонимный/именованный том, принадлежащий root.
RUN mkdir -p /app/data && chown -R node:node /app/data

VOLUME ["/app/data"]
EXPOSE 3100
USER node

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD wget -qO- http://127.0.0.1:3100/health >/dev/null 2>&1 || exit 1

CMD ["node", "dist/index.js"]
