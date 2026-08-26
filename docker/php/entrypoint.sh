#!/bin/sh
set -e

# Windows -> Docker Desktop (WSL2) bind mounts of ./backend do not preserve
# meaningful Unix ownership/permissions (everything lands root:root, and the
# php-fpm worker pool runs as www-data — neither the owner nor in the root
# group). Without this, Laravel can't write to storage/ or bootstrap/cache/
# and every request fails with a tempnam()/file-write error. This container
# starts as root, so it can fix this before dropping to www-data for the
# actual php-fpm/queue-worker process. Local-dev-only; irrelevant on a real
# (non-bind-mounted) deployment where the image's own file ownership holds.
chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

exec "$@"
