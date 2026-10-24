#!/usr/bin/env bash
# Starts the API and the Reverb websocket server together. Ctrl+C stops both.
cd "$(dirname "$0")"
php artisan reverb:start --host=0.0.0.0 --port=8081 &
REVERB_PID=$!
trap 'kill $REVERB_PID 2>/dev/null' EXIT INT TERM
php artisan serve
