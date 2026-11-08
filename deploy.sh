#!/bin/bash
set -euo pipefail

HOST="45.79.220.248"
USER="root"
DEST="/var/www/tasafy"
DOMAIN="tasafy.45.79.220.248.sslip.io"
SSH_OPTS="-o StrictHostKeyChecking=no -o PreferredAuthentications=password -o PubkeyAuthentication=no"

if [ -n "${SSHPASS_FILE:-}" ]; then
  PASS_OPTS=(-f "$SSHPASS_FILE")
elif [ -n "${SSHPASS:-}" ]; then
  PASS_OPTS=(-e)
else
  echo "Definí SSHPASS o SSHPASS_FILE con la contraseña SSH" >&2
  exit 1
fi

echo "→ rsync a $USER@$HOST:$DEST"
sshpass "${PASS_OPTS[@]}" rsync -az --delete \
  --exclude '.git' --exclude 'deploy' --exclude 'deploy.sh' \
  --exclude 'AGENTS.md' --exclude 'node_modules' \
  --exclude 'cache/*.json' --exclude 'cache/*.tmp' \
  -e "ssh $SSH_OPTS" ./ "$USER@$HOST:$DEST/"

echo "→ permisos + recarga Apache"
sshpass "${PASS_OPTS[@]}" ssh $SSH_OPTS "$USER@$HOST" "
  chown -R www-data:www-data $DEST
  find $DEST -type d -exec chmod 755 {} +
  find $DEST -type f -exec chmod 644 {} +
  mkdir -p $DEST/cache
  chown www-data:www-data $DEST/cache
  chmod 775 $DEST/cache
  systemctl reload apache2
"

echo "✔ Deploy completo → https://$DOMAIN/"
