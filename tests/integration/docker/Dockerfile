# Image unique de la suite d'intégration : le conteneur « local » (celui qui lance dep) et les
# serveurs SSH en dérivent, seul le rôle passé à l'entrypoint change. Même PHP, même WP-CLI et
# même cœur WordPress partout : les écarts observés viennent du recipe, pas de l'environnement.
ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        openssh-server openssh-client rsync git mariadb-client unzip less procps gzip ca-certificates curl \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install mysqli pdo_mysql \
    && echo 'memory_limit=512M' > /usr/local/etc/php/conf.d/memory.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# WP-CLI complet (phar avec ses paquets de commandes) dans le PATH, local comme serveurs.
ARG WP_CLI_VERSION=2.12.0
RUN curl -fsSL -o /usr/local/bin/wp \
        "https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar" \
    && chmod +x /usr/local/bin/wp

# Cœur WordPress téléchargé une fois, copié ensuite dans web/wp (local) ou shared/web/wp (serveurs).
ARG WP_VERSION=6.8.2
RUN mkdir -p /opt/wp-core \
    && curl -fsSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" | tar -xz -C /opt/wp-core --strip-components=1 \
    && rm -rf /opt/wp-core/wp-content/plugins/* /opt/wp-core/wp-content/themes/* \
    && chmod -R a+rX /opt/wp-core

RUN useradd -m -u 1000 -s /bin/bash -p '*' deployer \
    && mkdir -p /run/sshd /srv \
    && printf 'PasswordAuthentication no\nKbdInteractiveAuthentication no\nPermitRootLogin no\nUsePAM no\nMaxStartups 100\nMaxSessions 100\n' \
        > /etc/ssh/sshd_config.d/integration.conf

COPY entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
