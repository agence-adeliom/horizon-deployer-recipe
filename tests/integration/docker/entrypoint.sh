#!/bin/bash
# Rôle du conteneur : « local » (machine qui lance dep) ou « server » (hôte SSH).
# Les clés SSH sont générées ici, au démarrage, dans un volume Docker éphémère : aucune clé
# n'est jamais écrite dans le dépôt.
set -euo pipefail

role=${1:-server}

mkdir -p /srv/git
chown deployer:deployer /srv /srv/git

case "$role" in
    local)
        if [ ! -f /keys/id_ed25519 ]; then
            ssh-keygen -q -t ed25519 -N '' -C integration -f /keys/id_ed25519.tmp
            mv /keys/id_ed25519.tmp.pub /keys/id_ed25519.pub
            mv /keys/id_ed25519.tmp /keys/id_ed25519
        fi
        install -d -o deployer -g deployer -m 700 /home/deployer/.ssh
        install -o deployer -g deployer -m 600 /keys/id_ed25519 /home/deployer/.ssh/id_ed25519
        cat > /home/deployer/.ssh/config <<'EOF'
Host *
    StrictHostKeyChecking no
    UserKnownHostsFile /dev/null
    LogLevel ERROR
EOF
        chown deployer:deployer /home/deployer/.ssh/config
        chown deployer:deployer /home/deployer/.cache 2>/dev/null || true
        install -d -o deployer -g deployer /project /home/deployer/.cache/composer
        exec sleep infinity
        ;;
    server)
        ssh-keygen -A >/dev/null
        for _ in $(seq 1 120); do
            [ -f /keys/id_ed25519.pub ] && break
            sleep 0.5
        done
        install -d -o deployer -g deployer -m 700 /home/deployer/.ssh
        install -o deployer -g deployer -m 600 /keys/id_ed25519.pub /home/deployer/.ssh/authorized_keys
        exec /usr/sbin/sshd -D -e
        ;;
    *)
        exec "$@"
        ;;
esac
