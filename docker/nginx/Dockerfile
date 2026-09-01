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
COPY --from=frontend /app/dist /usr/share/nginx/html
# Laravel public dir (index.php) для fastcgi SCRIPT_FILENAME
COPY backend/public /var/www/html/public
EXPOSE 80
