#!/bin/sh
set -e

# Railway-da bəzən mpm_event/mpm_worker simlinkləri tikinti mərhələsindən sonra
# konteyner başlayanda yenidən peyda olur — ona görə bunu burda, HƏR DƏFƏ
# konteyner işə düşəndə bir daha təmizləyirik (tikinti zamanı silmək kifayət etmir).
rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf \
      /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf
a2enmod mpm_prefork >/dev/null 2>&1 || true

PORT="${PORT:-8080}"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-enabled/000-default.conf

exec apache2-foreground
