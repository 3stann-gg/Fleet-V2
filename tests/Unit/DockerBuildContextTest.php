<?php

/** Evaluate the literal, *, ** and negation subset used by this policy; no Docker daemon is involved. */
function fleetDockerContextIncludes(string $path): bool
{
    $included = true;
    foreach (file(__DIR__.'/../../.dockerignore', FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $exception = str_starts_with($line, '!');
        $pattern = trim($exception ? substr($line, 1) : $line, '/');
        $expression = str_replace(['\*\*/', '\*\*', '\*'], ['(?:.*/)?', '.*', '[^/]*'], preg_quote($pattern, '~'));
        if (preg_match('~\A'.$expression.'(?:/.*)?\z~', $path) === 1) {
            $included = $exception;
        }
    }

    return $included;
}

it('uses a default-deny context and no Dockerfile-specific override', function (): void {
    $rules = array_values(array_filter(file(__DIR__.'/../../.dockerignore', FILE_IGNORE_NEW_LINES),
        fn (string $line): bool => trim($line) !== '' && ! str_starts_with(trim($line), '#')));
    expect($rules[0])->toBe('**')
        ->and(is_file(__DIR__.'/../../Dockerfile.dockerignore'))->toBeFalse();
});

it('excludes sensitive files runtime state test evidence and unknown local artifacts', function (string $path): void {
    expect(fleetDockerContextIncludes($path))->toBeFalse();
})->with([
    '.env', '.env.production', '.env.mysql-concurrency', '.env.example', '.claude/settings.local.json',
    '.git/config', '.aws/credentials', 'auth.json', '.ssh/id_rsa', '.kilo/settings.json', '.npmrc.private',
    'storage/logs/laravel.log', 'storage/framework/sessions/private-session', 'storage/app/private/patient.pdf',
    'bootstrap/cache/config.php', 'bootstrap/cache/packages.php', 'bootstrap/cache/services.php',
    'database/database.sqlite', 'database/database.sqlite-wal', 'database/test.sqlite3-shm', 'database/fleet.db',
    'database/fleet.db-journal', 'database/backup.sql', 'database/backup.sql.gz', 'database/backup.sql.zip',
    'app/private.pem', 'config/server.key', 'public/private.pfx', 'resources/credentials.json',
    'app/nested/.env', 'app/nested/.envrc', 'app/nested/.npmrc', 'app/nested/.claude/settings.local.json',
    'config/secrets/token.txt', 'config/client-secret.json', 'public/service-account-private.json',
    'resources/evidence/screenshot.png', 'public/debug.log', 'app/source.php.bak', 'app/source.php.backup',
    'public/storage/private-upload.png', 'public/uploads/private-upload.png', 'public/exports/private.pdf', 'public/build/manifest.json', 'public/hot',
    'app/nested/.vscode/settings.json', 'resources/.kilo/settings.json',
    'tests/MySqlConcurrency/bin/run.php', 'tests/fixtures/private.json', 'evidence/network.json',
    'fleet-stage4-evidence/response.json', '.phpunit.cache/test-results', 'tmp/database.sqlite',
    'node_modules/package/index.js', 'vendor/autoload.php', 'public/vendor/private.php',
    'future-unknown-folder/sensitive.txt',
]);

it('preserves required Composer Vite Laravel and runtime source inputs', function (string $path): void {
    expect(is_file(__DIR__.'/../../'.$path))->toBeTrue()
        ->and(fleetDockerContextIncludes($path))->toBeTrue();
})->with([
    'Dockerfile', '.dockerignore', '.npmrc', 'artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
    'vite.config.js', 'tailwind.config.js', 'postcss.config.js', 'bootstrap/app.php', 'bootstrap/providers.php',
    'app/Http/Controllers/RoutePlanController.php', 'app/Services/RouteSaveDiagnosticService.php',
    'config/app.php', 'config/logging.php', 'routes/web.php', 'routes/console.php', 'public/index.php',
    'public/.htaccess', 'public/assets/js/route-planning/route-modal.js',
    'resources/css/app.css', 'resources/js/app.js', 'resources/views/route-planning/index.blade.php',
    'database/migrations/2026_11_12_180121_create_route_estimate_provenances_table.php',
]);

it('retains only reviewed non-secret npm build settings and creates fresh runtime directories', function (): void {
    $settings = array_values(array_filter(array_map('trim', file(__DIR__.'/../../.npmrc', FILE_IGNORE_NEW_LINES))));
    expect($settings)->toBe(['ignore-scripts=true', 'audit=true']);
    $dockerfile = file_get_contents(__DIR__.'/../../Dockerfile');
    foreach (['storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $directory) {
        expect($dockerfile)->toContain($directory);
    }
    expect($dockerfile)->toContain('RUN npm ci', 'RUN npm run build', '--no-dev', '--no-scripts');
});
