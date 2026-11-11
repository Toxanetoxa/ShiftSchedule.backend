#!/bin/bash
set -euo pipefail
umask 077

[[ $EUID -eq 0 && $# -eq 1 && $1 =~ ^[0-9a-f]{40}$ ]] || exit 64
revision=$1
image="shiftschedule-api:${revision}"
state=/var/lib/shiftschedule
backup_dir=/var/backups/shiftschedule
compose_file=/opt/shiftschedule/compose.production.yaml
environment_file=/etc/shiftschedule/app.env

install -d -m 700 "$state" "$backup_dir"
exec 9>"$state/deploy.lock"
flock -w 600 9

export APP_IMAGE="$image"
compose() {
    docker compose --env-file "$environment_file" -f "$compose_file" "$@"
}

# The workflow sends an immutable image, never shell scripts or server secrets.
gzip -dc | docker image load
actual_revision=$(docker image inspect "$image" --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}')
[[ $actual_revision == "$revision" ]] || { echo 'Image revision mismatch.' >&2; exit 65; }

previous=''
if [[ -f "$state/current-image" ]]; then
    previous=$(cat "$state/current-image")
    [[ $previous =~ ^shiftschedule-api:[0-9a-f]{40}$ ]] || exit 65
fi

compose up -d --wait --wait-timeout 180 db
backup="$backup_dir/$(date -u +%Y%m%dT%H%M%SZ)-${revision}.dump"
compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom' > "$backup"
[[ -s $backup ]]
echo "Database backup: $backup"

if ! compose up -d --no-deps --wait --wait-timeout 240 app; then
    echo 'Application healthcheck failed; database migrations are not automatically reversed.' >&2
    if [[ -n $previous && $previous != "$image" ]]; then
        export APP_IMAGE="$previous"
        compose up -d --no-deps --wait --wait-timeout 240 app || true
        echo "Attempted rollback to $previous. Database backup: $backup" >&2
    fi
    exit 1
fi

if [[ -n $previous && $previous != "$image" ]]; then
    printf '%s\n' "$previous" > "$state/previous-image"
fi
printf '%s\n' "$image" > "$state/current-image.next"
mv "$state/current-image.next" "$state/current-image"
echo "Deployed $image"
