#!/bin/sh
# Shared healthcheck script for the RME-License-Hub containers.
#
# All hub processes (app, reverb, queue, scheduler) share the SAME image
# (docker/Dockerfile) and entrypoint; the only thing that differs is the
# command they run. This single script branches on $HUB_HEALTHCHECK so each
# service can register the appropriate, ACTUAL liveness check.
#
#   app        -> php-fpm is serving the real Laravel /up route (FastCGI probe,
#                 no in-container HTTP server — nginx is a separate container)
#   reverb     -> the websocket server is accepting TCP connections on 8080
#   queue      -> a `php artisan queue:work` master process is alive
#   scheduler  -> a `php artisan schedule:work` process is alive
#
# No new application dependencies are introduced; only two tiny, standard
# system packages added in the Dockerfile: libfcgi-bin (cgi-fcgi) and procps
# (pgrep).

set -u

MODE="${HUB_HEALTHCHECK:-app}"

case "$MODE" in
  app)
    # Prove php-fpm actually serves requests by hitting the Laravel health
    # route registered in bootstrap/app.php via `health: '/up'` (returns 204).
    # Probing FastCGI directly on the loopback avoids needing an HTTP server
    # inside this container (nginx is the separate hub-nginx service).
    RESP=$(SCRIPT_FILENAME=/var/www/public/index.php \
           SCRIPT_NAME=/index.php \
           REQUEST_URI=/up \
           REQUEST_METHOD=GET \
           cgi-fcgi -bind -connect 127.0.0.1:9000 2>/dev/null)
    # Laravel's health route (`health: '/up'` in bootstrap/app.php) serves a
    # real response proving the app boots and answers HTTP. In this Laravel
    # version it returns a 200 "Application up" HTML page (older versions
    # returned 204 No Content). Accept either: a 2xx/204 status line, the
    # default health body text, or an explicit 204. Reject only if the probe
    # failed to connect (cgi-fcgi non-zero / empty) or the body shows an error.
    echo "$RESP" | grep -qiE 'Status: 2[0-9]{2}|Status: 204|204 No Content|Application up'
    ;;

  reverb)
    # Reverb listens on TCP 8080 (see docker-compose.yml REVERB_SERVER_PORT /
    # the reverb:start --port=8080 command). A successful TCP connect proves
    # the server socket is open and accepting connections. Use a dependency-free
    # PHP socket check (php is always present; nc/cgi-fcgi are not in the base
    # image), connecting to the server's bind address 0.0.0.0:8080.
    php -r '$ok=@fsockopen("0.0.0.0", 8080, $e, $s, 2); if($ok){fclose($ok);exit(0);} fwrite(STDERR, "reverb:8080 unreachable ($e/$s)\n"); exit(1);'
    ;;

  queue)
    # `php artisan queue:work` forks; the MASTER php process owns the command
    # line `artisan queue:work ...`. Bracket the pattern so pgrep never matches
    # its own shell (the classic "pgrep self-match" pitfall).
    pgrep -f '[a]rtisan queue:work' >/dev/null 2>&1
    ;;

  scheduler)
    # `php artisan schedule:work` runs a long-lived master loop that fires the
    # Laravel scheduler every minute. Same bracketing trick as the queue.
    pgrep -f '[a]rtisan schedule:work' >/dev/null 2>&1
    ;;

  *)
    echo "Unknown HUB_HEALTHCHECK mode: $MODE" >&2
    exit 2
    ;;
esac
