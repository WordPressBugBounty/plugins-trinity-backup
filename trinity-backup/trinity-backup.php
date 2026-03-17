<?php
/**
 * Plugin Name: Trinity Backup - Backup, Migrate, Restore, Clone & Schedule Backups
 * Description: Lightweight backup and migration plugin with chunked processing, streaming archives, and optional AES-256 encryption. Create full site backups, migrate between servers, and restore with ease.
 * Version: 2.0.9
 * Author: KingAddons.com
 * Author URI: https://trinity.kingaddons.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: trinity-backup
 * Requires at least: 6.2
 * Requires PHP: 8.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if ( ! function_exists( 'trinity_backup_fs' ) ) {
    // Create a helper function for easy SDK access.
    function trinity_backup_fs() {
        global $trinity_backup_fs;

        if ( ! isset( $trinity_backup_fs ) ) {
            // Include Freemius SDK.
            require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';

            $trinity_backup_fs = fs_dynamic_init( array(
                'id'                  => '23373',
                'slug'                => 'trinity-backup',
                'premium_slug'        => 'trinity-backup-pro',
                'type'                => 'plugin',
                'public_key'          => 'pk_19dba60089a3238fd47574daff4c6',
                'is_premium'          => false,
                'premium_suffix'      => 'pro',
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'has_affiliation'     => 'selected',
                'menu'                => array(
                    'slug'           => 'trinity-backup',
                    'contact'        => true,
                    'support'        => false,
                    'pricing'        => false,
                    'affiliation'    => false,
                ),
                'is_live'             => true,
            ) );
        }

        return $trinity_backup_fs;
    }

    // Init Freemius.
    trinity_backup_fs();
    // Signal that SDK was initiated.
    do_action( 'trinity_backup_fs_loaded' );
    trinity_backup_fs()->add_filter( 'show_deactivation_subscription_cancellation', '__return_false' );
    trinity_backup_fs()->add_filter( 'deactivate_on_activation', '__return_false' );
}

/**
 * Check if the user can use Pro features.
 *
 * @return bool
 */
if ( ! function_exists( 'trinity_backup_can_use_pro' ) ) {
    function trinity_backup_can_use_pro(): bool {
        if ( ! function_exists( 'trinity_backup_fs' ) ) {
            return false;
        }

        $fs = trinity_backup_fs();
        if ( ! is_object( $fs ) || ! method_exists( $fs, 'can_use_premium_code__premium_only' ) ) {
            return false;
        }

        return (bool) $fs->can_use_premium_code__premium_only();
    }
}

/**
 * Check if the current license corresponds to the "Unlimited websites" offering.
 *
 * Important: In Freemius, the plan slug/name and the pricing option are different concepts.
 * Your pricing option ID for Unlimited is 51880.
 *
 * @return bool
 */
if ( ! function_exists( 'trinity_backup_is_unlimited_pricing' ) ) {
    function trinity_backup_is_unlimited_pricing(): bool {
        if ( ! trinity_backup_can_use_pro() ) {
            return false;
        }

        $fs = trinity_backup_fs();
        if ( ! is_object( $fs ) ) {
            return false;
        }

        // 1) Prefer checking the active license pricing_id (most reliable for differentiating tiers).
        if ( method_exists( $fs, '_get_license' ) ) {
            $license = $fs->_get_license();
            if ( is_object( $license ) && isset( $license->pricing_id ) ) {
                $isUnlimited = ( (int) $license->pricing_id === 51880 );
                /**
                 * Allow overriding unlimited detection if needed.
                 *
                 * @param bool $isUnlimited
                 * @param object $license
                 */
                return (bool) apply_filters( 'trinity_backup_is_unlimited_pricing', $isUnlimited, $license );
            }
        }

        return false;
    }
}

/**
 * Check if a specific Pro feature is available by plan.
 *
 * Plan 1 ($4.99/yr 5 sites):  scheduled, pre_update, encryption, email
 * Plan 2 ($29/yr unlimited):  All Plan 1 + wp_cli, white_label
 * Plan 3 ($59 lifetime):      Same as Plan 2
 *
 * @param string $feature Feature slug.
 * @return bool
 */
if ( ! function_exists( 'trinity_backup_has_feature' ) ) {
    function trinity_backup_has_feature( string $feature ): bool {
        if ( ! trinity_backup_can_use_pro() ) {
            return false;
        }

        // Plan 1 features — available on any paid plan
        $plan1 = [ 'scheduled', 'pre_update', 'encryption', 'email' ];
        if ( in_array( $feature, $plan1, true ) ) {
            return true;
        }

        // Plan 2 / Plan 3 features — require Unlimited websites pricing (or equivalent plan)
        $plan2 = [ 'wp_cli', 'white_label' ];
        if ( in_array( $feature, $plan2, true ) ) {
            return trinity_backup_is_unlimited_pricing();
        }

        return false;
    }
}

require_once __DIR__ . '/src/Core/Autoloader.php';
\TrinityBackup\Core\Autoloader::register();

\TrinityBackup\Core\Plugin::init();
