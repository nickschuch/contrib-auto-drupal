Test repository for Drupal and OTEL integration

## Spinning up environment

### Docker Compose

* `docker compose up`
* `docker compose exec php-cli -- composer install --prefer-dist`
* `docker compose exec php-cli -- ./vendor/drush/drush/drush si demo_umami -y`

## Viewing Traces

* Browse to: http://127.0.0.1:16686
* Select service: drupal
* Click search
