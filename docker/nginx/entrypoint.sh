#!/bin/sh
set -eu

case "${NGINX_PROTOCOL:-https}" in
    http)
        if [ -z "${PC_MANAGEMENT_HTTP_PORT:-}" ]; then
            echo 'PC_MANAGEMENT_HTTP_PORT is required when NGINX_PROTOCOL=http' >&2
            exit 1
        fi

        cp /etc/nginx/http.conf /etc/nginx/conf.d/default.conf
        ;;
    https)
        if [ -z "${PC_MANAGEMENT_HTTPS_PORT:-}" ]; then
            echo 'PC_MANAGEMENT_HTTPS_PORT is required when NGINX_PROTOCOL=https' >&2
            exit 1
        fi

        if [ -z "${NGINX_SSL_CERTIFICATE:-}" ] || [ -z "${NGINX_SSL_CERTIFICATE_KEY:-}" ]; then
            echo 'NGINX_SSL_CERTIFICATE and NGINX_SSL_CERTIFICATE_KEY are required when NGINX_PROTOCOL=https' >&2
            exit 1
        fi

        envsubst "\${NGINX_SSL_CERTIFICATE} \${NGINX_SSL_CERTIFICATE_KEY}" \
            < /etc/nginx/https.conf.template \
            > /etc/nginx/conf.d/default.conf
        ;;
    *)
        echo 'NGINX_PROTOCOL must be http or https' >&2
        exit 1
        ;;
esac

exec /docker-entrypoint.sh "$@"
