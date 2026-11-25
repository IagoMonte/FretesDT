# FretesDT: BACK

API REST e painel administrativo do sistema de gestão de fretes. Laravel 13, PHP 8.3+, autenticação por JWT (RS256).

## Rodar em desenvolvimento

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan frete:jwt-keys
```

Com SQLite, defina no `.env` `DB_CONNECTION=sqlite` e `DB_DATABASE=database/dev.sqlite`, depois:

```bash
touch database/dev.sqlite
# defina FRETE_ADMIN_LOGIN e FRETE_ADMIN_SENHA no .env antes de semear
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=8000
```

Dados fixos do piso ANTT (opcional): `php artisan antt:importa-coeficientes` e `php artisan antt:baixa-distancias`.
Tarefas agendadas: `php artisan schedule:work`.

## Testes

```bash
php artisan test
```
