<?php

declare(strict_types=1);

namespace Shimmie2;

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *\
* Make sure that shimmie is correctly installed                             *
\* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

if (!file_exists("vendor/")) {
    die("
        <p>Shimmie is unable to find the composer <code>vendor</code> directory.</p>
		<p>To finish installing, you need to run <code>composer install</code>
		in the shimmie directory (<code>".getcwd()."</code>).</p>
		<p>(If you don't have composer, <a href='https://getcomposer.org/'>get it here</a>)</p>
	");
}
require_once "vendor/autoload.php";

sanitize_php();
version_check("8.4");

if (!file_exists("data/config/shimmie.conf.php")) {
    Installer::install();
    exit(0);
}


/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *\
* Load files                                                                *
\* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

@include_once "data/config/shimmie.conf.php";
@include_once "data/config/extensions.conf.php";

_set_up_shimmie_environment();
Ctx::$tracer = new \MicroOTLP\Client(
    resourceAttributes: [
        'service.name' => 'shimmie2',
        'service.instance.id' => gethostname() ?: 'unknown',
        'service.version' => SysConfig::getVersion(),
    ],
    scopeAttributes: [],
);
// Override TS to show that bootstrapping started in the past
Ctx::$root_span = Ctx::$tracer->startSpan("Root", startTime: (int)($_SERVER["REQUEST_TIME_FLOAT"] * 1e9));
$sBoot = Ctx::$tracer->startSpan("Bootstrap", startTime: (int)($_SERVER["REQUEST_TIME_FLOAT"] * 1e9));
_load_ext_files();
// Depends on core files
Ctx::$cache = load_cache(SysConfig::getCacheDsn());
Ctx::$database = new Database(SysConfig::getDatabaseDsn());
// $config depends on _load_ext_files (to load config.php files and
// calculate defaults) and $cache (to cache config values)
Ctx::$config = new DatabaseConfig(Ctx::$database);
// theme files depend on $config (theme name is a config value)
_load_theme_files();
// $page depends on theme files (to load theme-specific Page class)
Ctx::$page = Themelet::get_theme_class(Page::class) ?? new Page();
// $event_bus depends on ext/*/main.php being loaded
Ctx::$event_bus = new EventBus();
$sBoot->end();

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *\
* Send events, display output                                               *
\* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

function main(): int
{
    // Ctx::$tracer->mark($_SERVER["REQUEST_URI"] ?? "No Request");
    $sMain = Ctx::$tracer->startSpan(
        "Main",
        [
            "enduser.id" => $_COOKIE["shm_user"] ?? "No User",
            "net.peer.ip" => Network::get_real_ip(),
            "http.uri" => $_SERVER["REQUEST_URI"] ?? "No URI",
            "http.user_agent" => $_SERVER['HTTP_USER_AGENT'] ?? "No UA",
        ]
    );

    $iee = null;
    // nested try-catch blocks so that we can try to handle user-errors
    // in a pretty and theme-customisable way, but if that breaks, the
    // breakage will be handled by the server-error handler
    try {
        try {
            if (!Ctx::$config->get(SetupConfig::NO_AUTO_DB_UPGRADE)) {
                send_event(new DatabaseUpgradeEvent());
            }
            $iee = send_event(new InitExtEvent());

            // start the page generation waterfall
                        // =========================================================================
            // MANDATORY BOT: Automatically alerts & deletes inactive user data/bloat
            // =========================================================================
            if ((PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') && Ctx::$config->get('auto_prune_inactive', true)) {
                $total_days = (int)Ctx::$config->get('auto_prune_days', 180);
                $warning_days = max(1, $total_days - 30); // Message user 30 days before deletion

                $warning_cutoff = date('Y-m-d H:i:s', strtotime("-$warning_days days"));
                $purge_cutoff = date('Y-m-d H:i:s', strtotime("-$total_days days"));
                $site_url = Ctx::$config->get('base_url', 'http://localhost/');
                $anonymous_id = 0; 

                // Dynamic table support check for the tracking metrics
                Ctx::$database->execute("ALTER TABLE users ADD COLUMN IF NOT EXISTS warning_sent_at DATETIME DEFAULT NULL");

                // 1. DISPATCH WARNING NOTICES
                $to_warn = Ctx::$database->get_all(
                    "SELECT id, name, email FROM users WHERE joindate < ? AND warning_sent_at IS NULL AND id != 1 AND email IS NOT NULL",
                    [$warning_cutoff]
                );

                foreach ($to_warn as $user) {
                    $subject = "URGENT: Inactive Account Deletion Warning - " . Ctx::$config->get('title', 'Shimmie2');
                    $message = "Hello {$user['name']},\n\nYour account has been inactive. To protect privacy and prevent database bloat, your account is scheduled for deletion.\n\nIf you do not log in within 30 days, your account profile, username, emails, location indicators, and IP logs will be permanently scrubbed from our systems.\n\nPlease log back in to preserve your account: $site_url";
                    $headers = "From: no-reply@" . parse_url($site_url, PHP_URL_HOST) . "\r\n" . "X-Mailer: PHP/" . phpversion();

                    if (mail($user['email'], $subject, $message, $headers)) {
                        Ctx::$database->execute("UPDATE users SET warning_sent_at = NOW() WHERE id = ?", [(int)$user['id']]);
                    }
                }

                // 2. SCRUB IP LOGS, NAMES, AND USER DATA BLOAT
                $to_purge = Ctx::$database->get_all(
                    "SELECT id FROM users WHERE warning_sent_at < ? AND id != 1",
                    [$purge_cutoff]
                );

                foreach ($to_purge as $user) {
                    $user_id = (int)$user['id'];
                    Ctx::$database->begin_transaction();
                    try {
                        // Anonymize uploads to preserve imageboard grids while deleting network traces
                        Ctx::$database->execute("UPDATE images SET user_id = ?, ip = '127.0.0.1' WHERE user_id = ?", [$anonymous_id, $user_id]);
                        
                        if (Ctx::$database->table_exists("comments")) {
                            Ctx::$database->execute("UPDATE comments SET user_id = ?, ip = '127.0.0.1' WHERE user_id = ?", [$anonymous_id, $user_id]);
                        }
                        if (Ctx::$database->table_exists("image_history")) {
                            Ctx::$database->execute("DELETE FROM image_history WHERE user_id = ?", [$user_id]);
                        }
                        if (Ctx::$database->table_exists("user_login_log")) {
                            Ctx::$database->execute("DELETE FROM user_login_log WHERE user_id = ?", [$user_id]);
                        }

                        // Fire standard extension hooks to clean unmanaged caches
                        send_event(new UserDeleteEvent($user_id));

                        // Permanently remove the main profile record row (wiping name, hash, and email)
                        Ctx::$database->execute("DELETE FROM users WHERE id = ?", [$user_id]);
                        Ctx::$database->commit();
                    } catch (\Throwable $e) {
                        Ctx::$database->rollback();
                    }
                }
            }
            if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
                ob_end_flush();
                ob_implicit_flush(true);
                $app = new CliApp();
                send_event(new CliGenEvent($app));
                if ($app->run() !== 0) {
                    throw new \Exception("CLI command failed");
                }
            } else {
                send_event(new UserLoginEvent(_get_user()));
                send_event(new PageRequestEvent(
                    $_SERVER['REQUEST_METHOD'],
                    _get_query(),
                    new QueryArray($_GET),
                    new QueryArray($_POST)
                ));
                Ctx::$page->display();
            }

            if (Ctx::$database->is_transaction_open()) {
                Ctx::$database->commit();
            }

            // saving cache data and profiling data to disk can happen later
            if (function_exists("fastcgi_finish_request")) {
                fastcgi_finish_request();
            }
            $sMain->end(success: true, attributes: ["http.status_code" => Ctx::$page->code]);
            $exit_code = 0;
        } catch (UserError $e) {
            if (Ctx::$database->is_transaction_open()) {
                Ctx::$database->rollback();
            }
            Ctx::$page->set_error($e);
            Ctx::$page->display();
            // "User Error" is considered success from a system perspective
            $sMain->end(success: true, message: (string)$e, attributes: ["http.status_code" => Ctx::$page->code]);
            $exit_code = 2;
        }
    } catch (\Throwable $e) {
        _fatal_error($e);
        $code = is_a($e, SCoreException::class) ? $e->http_code : 500;
        $sMain->end(success: false, message: (string)$e, attributes: ["http.status_code" => $code]);
        $exit_code = 1;
    } finally {
        Ctx::$root_span->end();
        $iee?->run_shutdown_handlers();
    }
    return $exit_code;
}

if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
    exit(main());
} else {
    main();
}
