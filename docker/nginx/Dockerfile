# Greywarden web server for production (docker-compose.prod.yml). Build context:
# the repository root. Serves the public files from the image; published asset
# bundles come from the web-assets volume the php container writes.
FROM nginx:1.27-alpine
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY src/web /var/www/html/web
# Mounted from the web-assets volume; left out so nginx never seeds it (php owns it)
RUN rm -rf /var/www/html/web/assets
