<?php
/*
 * Name: Inactive Account Manager
 * Author: Your GitHub Username
 * Description: Cleans up abandoned user profiles to protect against credential stuffing attacks. (Strongly Recommended Security Module)
 */

class InactiveAccountManager extends Extension {
    public function onInit() {
        // Safe baseline: Default configuration is turned OFF (false) to prevent data loss
        global $config;
        $config->set_default_boolean('inactive_account_cleanup_enabled', false);
        $config->set_default_int('inactive_account_days_threshold', 365);
    }

    public function onAdminPage(AdminPageEvent $event) {
        global $html;
        
        // Render the setup fields inside Shimmie's Admin Interface
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

    // Your main execution function (e.g., hooks into Shimmie's maintenance task)
    public function onMaintenance() {
        global $config, $db;
        
        // This stops the execution completely unless the site owner checked the box
        if (!$config->get_bool('inactive_account_cleanup_enabled')) {
            return;
        }

        $days = $config->get_int('inactive_account_days_threshold');
        
        // Your logic to delete inactive accounts goes below here
        // Example: $db->execute("DELETE FROM users WHERE last_login < ... ");
    }
}
?>
