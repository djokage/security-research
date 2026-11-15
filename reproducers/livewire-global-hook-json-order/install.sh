#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${1:-}"
if [[ -z "$APP_DIR" || ! -f "$APP_DIR/artisan" ]]; then
  echo "Usage: $0 /path/to/fresh/laravel-app" >&2
  exit 2
fi

APP_DIR="$(cd "$APP_DIR" && pwd)"
SOURCE_DIR="$(cd "$(dirname "$0")" && pwd)"
PROVIDER="$APP_DIR/app/Providers/AppServiceProvider.php"
BACKUP="$PROVIDER.before-livewire-global-hook-repro"

if [[ ! -d "$APP_DIR/vendor/livewire/livewire" ]]; then
  echo "Livewire is not installed. Run: composer require laravel/framework:13.42.0 livewire/livewire:4.4.8 --with-all-dependencies" >&2
  exit 3
fi

LIVEWIRE_VERSION="$(cd "$APP_DIR" && composer show livewire/livewire --format=json | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["versions"][0] ?? "unknown";')"
if [[ "$LIVEWIRE_VERSION" != "4.4.8" ]]; then
  echo "Expected livewire/livewire 4.4.8; found $LIVEWIRE_VERSION" >&2
  exit 4
fi

if [[ -e "$BACKUP" ]]; then
  echo "Backup already exists: $BACKUP" >&2
  echo "Refusing to overwrite it. Restore or move it before reinstalling." >&2
  exit 5
fi

cp "$PROVIDER" "$BACKUP"
cp "$SOURCE_DIR/app/Providers/AppServiceProvider.php" "$PROVIDER"
mkdir -p "$APP_DIR/app/Livewire/Hooks" "$APP_DIR/app/Livewire" \
  "$APP_DIR/database/migrations" "$APP_DIR/resources/views/livewire" \
  "$APP_DIR/tests/Feature"
cp "$SOURCE_DIR/app/Livewire/Hooks/RejectNonAdminCalls.php" "$APP_DIR/app/Livewire/Hooks/"
cp "$SOURCE_DIR/app/Livewire/GlobalHookProbe.php" "$APP_DIR/app/Livewire/"
cp "$SOURCE_DIR/database/migrations/"*.php "$APP_DIR/database/migrations/"
cp "$SOURCE_DIR/resources/views/livewire/global-hook-probe.blade.php" "$APP_DIR/resources/views/livewire/"
cp "$SOURCE_DIR/tests/Feature/GlobalHookJsonAuthorizationTest.php" "$APP_DIR/tests/Feature/"

echo "Installed into: $APP_DIR"
echo "Original provider backup: $BACKUP"
echo "Run: php artisan test tests/Feature/GlobalHookJsonAuthorizationTest.php"
