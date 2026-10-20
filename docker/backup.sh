#!/bin/bash
# Daily database backup — the "backup" service in compose.production.yaml.
#
#   bash /backup.sh        keep running: one backup every day at BACKUP_AT
#   bash /backup.sh now    one backup right away, then exit
#
# Files: /backups/isp-YYYY-MM-DD_HHMM.sql.gz (on the PC: the folder set by
# BACKUP_DIR, ./backups by default). Older than BACKUP_KEEP_DAYS are deleted.
# Copy that folder somewhere else too (USB drive, Google Drive, OneDrive):
# a backup on the same disk doesn't survive that disk failing.
set -euo pipefail

DIR=/backups
AT="${BACKUP_AT:-02:00}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"

log() { echo "$(date '+%F %T') $*"; }

backup() {
    local stamp file part
    stamp="$(date +%Y-%m-%d_%H%M)"
    file="$DIR/isp-$stamp.sql.gz"
    part="$DIR/.isp-$stamp.sql.gz.part"

    # --single-transaction: a consistent copy without locking the app out.
    if mysqldump -h db -u root --single-transaction --quick --routines --triggers --no-tablespaces \
        --databases "$MYSQL_DATABASE" | gzip > "$part"; then
        mv "$part" "$file"
        log "backup OK: $(basename "$file") ($(du -h "$file" | cut -f1))"
    else
        rm -f "$part"
        log "backup FAILED" >&2
        return 1
    fi

    find "$DIR" -maxdepth 1 -name 'isp-*.sql.gz' -mtime +"$KEEP_DAYS" -print -delete | sed 's/^/removed old backup: /'
}

mkdir -p "$DIR"

if [ "${1:-}" = "now" ]; then
    backup
    exit
fi

# First start, or the PC was off at backup time: don't wait a whole day.
if [ -z "$(find "$DIR" -maxdepth 1 -name 'isp-*.sql.gz' -mmin -1440 -print -quit)" ]; then
    backup || true
fi

while true; do
    now="$(date +%s)"
    next="$(date -d "today $AT" +%s)"
    [ "$next" -le "$now" ] && next="$(date -d "tomorrow $AT" +%s)"
    log "next backup at $(date -d "@$next" '+%F %H:%M')"
    sleep $((next - now))
    backup || true
done
