<?php

namespace BCC\Onchain\Services;

use BCC\Onchain\Repositories\CollectionRepository;
use BCC\Onchain\Repositories\ValidatorRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database migration logic that runs on admin_init when version changes.
 *
 * Handles ALTER TABLE statements that dbDelta cannot perform.
 */
final class MigrationService
{
    /**
     * Run if the stored DB version is behind the plugin version.
     *
     * Hooked to: admin_init
     */
    public static function maybeUpgrade(): void
    {
        $installed = get_option('bcc_onchain_db_version', '0');
        if (version_compare($installed, BCC_ONCHAIN_VERSION, '>=')) {
            return;
        }

        bcc_onchain_ensure_schema();

        global $wpdb;

        // dbDelta cannot ALTER existing columns — run explicit migrations.
        $wpdb->query("ALTER TABLE " . ValidatorRepository::table() . " MODIFY wallet_link_id BIGINT UNSIGNED DEFAULT NULL");
        $wpdb->query("ALTER TABLE " . CollectionRepository::table() . " MODIFY wallet_link_id BIGINT UNSIGNED DEFAULT NULL");

        // Add image_url column if missing (new in 1.6.0).
        $cols = $wpdb->get_col("SHOW COLUMNS FROM " . CollectionRepository::table());
        if (!in_array('image_url', $cols, true)) {
            $wpdb->query("ALTER TABLE " . CollectionRepository::table() . " ADD COLUMN image_url VARCHAR(500) DEFAULT NULL AFTER metadata_storage");
        }

        update_option('bcc_onchain_db_version', BCC_ONCHAIN_VERSION);
    }
}
