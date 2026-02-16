php artisan down
curl -L https://github.com/aport73/panel/releases/latest/download/panel.tar.gz | tar -xzv
chmod -R 755 storage/* bootstrap/cache
composer install --no-dev --optimize-autoloader
php artisan view:clear
php artisan config:clear
php artisan migrate --seed --force
chown -R www-data:www-data /var/www/pterodactyl/*
php artisan queue:restart
php artisan up
blueprint update
blueprint -i betterfilesmanager configeditor eggify environmentvariables larvellogs playerlisting playermanager pullfiles serverbackgrounds serverimporter sociallogin
