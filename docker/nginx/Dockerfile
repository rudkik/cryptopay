# ---- build Vue frontend ----
FROM node:22-alpine AS frontend
WORKDIR /app
COPY frontend/package*.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

# ---- nginx ----
FROM nginx:1.27-alpine
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/snippets/ /etc/nginx/snippets/
COPY --from=frontend /app/dist /usr/share/nginx/html
# Laravel public dir (index.php) для fastcgi SCRIPT_FILENAME
COPY backend/public /var/www/html/public

# 80 — наружу (публикуется в compose), 8081 — только внутренняя сеть compose
# (/api/internal для watcher'а), НЕ публиковать.
EXPOSE 80 8081

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD wget -qO- http://127.0.0.1/ >/dev/null 2>&1 || exit 1

# Штатный docker-entrypoint правит /etc/nginx/conf.d на месте (ipv6/templates),
# что несовместимо с read_only rootfs. Конфиг здесь полностью статичный,
# поэтому запускаем nginx напрямую.
ENTRYPOINT ["nginx", "-g", "daemon off;"]
