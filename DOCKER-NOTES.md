# Docker: notes for the LLM session integrating these files

Copy these into the repo root, keeping their paths:

- `Dockerfile`
- `compose.yaml`
- `.dockerignore`
- `.env.docker.example`
- `docker/entrypoint.sh`
- `.github/workflows/docker.yml`

Then check each item below and fix what's needed. Don't restructure the setup.

1. **Vite build.** Does `vite.config.ts` use the Wayfinder plugin (it runs `php artisan wayfinder:generate`)? The build stage has PHP and dev dependencies, so it should work. Confirm `docker build .` passes.
2. **`/up` health route.** Confirm `bootstrap/app.php` still has `health: '/up'` (the Laravel 11+ default). The container healthcheck uses it.
3. **Route caching.** `route:cache` fails on closure routes. Make sure `routes/web.php` has no closures (convert them to controllers or `Route::inertia`).
4. **Reverse proxy.** In `bootstrap/app.php`, add `$middleware->trustProxies(at: '*');` so generated URLs and redirects are correct behind the NAS's nginx or other reverse proxy.
5. **Logging.** Logging goes to stderr (`LOG_CHANNEL=stderr`). Confirm `config/logging.php` has the `stderr` channel (it's there by default).
6. **Sessions, cache and queue** use the database. Confirm the `sessions`, `cache` and `jobs` migrations exist (Laravel 12 ships them).
7. **Images** are stored in the DB (base64), so no `storage:link` and no storage volume are needed. The containers are stateless; only `pgdata` holds data.
8. **README.** Add a "Docker" section, following the steps in the chat reply.
