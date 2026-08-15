<?php
/*
 * Name: Inactive Account Manager
 * Author: StrudelKomet
 * Description: Cleans up abandoned user profiles to protect against credential stuffing attacks. (Strongly Recommended Security Module)
 */

class InactiveAccountManager extends Extension {
    
    public function onInit() {
        global $config;
        // Strictly safe by default to prevent unexpected data deletion
        $config->set_default_boolean('inactive_account_cleanup_enabled', false);
        $config->set_default_int('auto_prune_days', 180);
    }

    public function onAdminPage(AdminPageEvent $event) {
        global $html;
        
        // Setup fields inside Shimmie's Admin Panel
        $html->add_checkbox(
            'inactive_account_cleanup_enabled', 
            'Enable Inactive Account Cleanup (STRONGLY RECOMMENDED FOR SECURITY)'
        );
        
        $html->add_text(
            '<div style="background:#fff3cd; color:#856404; padding:12px; border-radius:5px; border:1px solid #ffeeba; margin:10px 0;">' .
            '<strong>Cybersecurity Notice:</strong> Opting into automatic account cleanup is highly recommended in almost all cases. ' .
            'Leaving abandoned databases unmonitored leaves your site and your users vulnerable to modern data leaks and brute-force takeover.' .
            '</div>'
        );
    }

    // Safely tied to the system maintenance cron hook
    public function onMaintenance() {
        global $config, $database;
        
        // Halts execution entirely if the setting is unchecked
        if (!$config->get_bool('inactive_account_cleanup_enabled')) {
            return;
        }

        $total_days = $config->get_int('auto_prune_days', 180);
        $warning_days = 30;

        $warning_cutoff = date("Y-m-d H:i:s", strtotime("-$warning_days days"));
        $purge_cutoff = date("Y-m-d H:i:s", strtotime("-$total_days days"));
        $site_url = $config->get_string('base_url', 'http://localhost');
        $anonymous_id = 0;

        // 1. DESPATCH WARNING NOTICES
        $to_warn = $database->get_all(
            "SELECT id, name, email FROM users WHERE joindate < ? AND warning_sent_at IS NULL AND id != 1 AND email IS NOT NULL",
            [$warning_cutoff]
        );

        foreach ($to_warn as $user) {
            $subject = "[URGENT] Inactive Account Deletion Warning - " . $config->get_string('title', 'Shimmie2');
            $message = "Hello " . $user['name'] . ",\n\nYour account has been inactive. To protect privacy and prevent database bloat, your account is scheduled for deletion.\n\nIf you do not log in within 30 days, your account profile, username, emails, location indicators, and IP logs will be permanently scrubbed from our systems.\n\nPlease log back in to preserve your account: " . $site_url;
            $headers = "From: no-reply@" . parse_url($site_url, PHP_URL_HOST) . "\r\n" . "X-Mailer: PHP/" . phpversion();

            if (mail($user['email'], $subject, $message, $headers)) {
                $database->execute("UPDATE users SET warning_sent_at = NOW() WHERE id = ?", [$user['id']]);
            }
        }

        // 2. SCRUB IP LOGS, NAMES, AND USER DATA BLOAT
        $to_purge = $database->get_all(
            "SELECT id FROM users WHERE warning_sent_at < ? AND id != 1",
            [$purge_cutoff]
        );

        foreach ($to_purge as $user) {
            $user_id = (int)$user['id'];
            $database->begin_transaction();

            try {
                // Keep image grids alive by anonymizing data instead of destroying layouts
                $database->execute("UPDATE images SET user_id = ?, ip = '127.0.0.1' WHERE user_id = ?", [$anonymous_id, $user_id]);

                if ($database->table_exists("comments")) {
                    $database->execute("UPDATE comments SET user_id = ?, ip = '127.0.0.1' WHERE user_id = ?", [$anonymous_id, $user_id]);
                }

                if ($database->table_exists("image_history")) {
                    $database->execute("DELETE FROM image_history WHERE user_id = ?", [$user_id]);
                }

                if ($database->table_exists("user_login_log")) {
                    $database->execute("DELETE FROM user_login_log WHERE user_id = ?", [$user_id]);
                }

                send_event(new UserDeleteEvent($user_id));

                // Clear credentials out completely
                $database->execute("DELETE FROM users WHERE id = ?", [$user_id]);
                $database->commit();
            } catch (Throwable $e) {
                $database->rollback();
            }
        }
    }
}
?>
