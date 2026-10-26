FROM nginx:alpine

WORKDIR /var/www/html

# Nginx 只需要公开入口文件；PHP 应用代码由 app 镜像提供。
COPY public ./public
COPY docker/nginx/default.conf /etc/nginx/http.conf
COPY docker/nginx/https.conf.template /etc/nginx/https.conf.template
COPY docker/nginx/entrypoint.sh /usr/local/bin/pc-nginx-entrypoint
RUN chmod +x /usr/local/bin/pc-nginx-entrypoint

ENTRYPOINT ["/usr/local/bin/pc-nginx-entrypoint"]
CMD ["nginx", "-g", "daemon off;"]
