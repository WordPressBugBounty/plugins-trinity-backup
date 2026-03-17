<?php
/**
 * Plugin main class.
 *
 * @package TrinityBackup
 */

declare( strict_types=1 );

namespace TrinityBackup\Core;

defined( 'ABSPATH' ) || exit;

use TrinityBackup\Database\Drivers\MysqliDriver;
use TrinityBackup\Engine\Pipeline;
use TrinityBackup\Engine\ImportPipeline;
use TrinityBackup\Engine\Steps\ExportDatabase;
use TrinityBackup\Engine\Steps\ExportFiles;
use TrinityBackup\Engine\Steps\ImportDatabase;
use TrinityBackup\Engine\Steps\ImportFiles;
use TrinityBackup\Filesystem\Drivers\LocalDriver;

final class Plugin
{
    public const VERSION = '2.0.9';

    public static function init(): void
    {
        $plugin = new self();
        $plugin->boot();
    }

    private function boot(): void
    {
        $container = new Container();

        $container->set('filesystem', static fn (): LocalDriver => new LocalDriver());
        $container->set('database', static fn (): MysqliDriver => new MysqliDriver());
        $container->set('state', static fn (): StateManager => new StateManager());
        $container->set('db_step', static fn (Container $c): ExportDatabase => new ExportDatabase(
            $c->get('database'),
            $c->get('filesystem')
        ));
        $container->set('files_step', static fn (Container $c): ExportFiles => new ExportFiles(
            $c->get('filesystem')
        ));
        $container->set('pipeline', static fn (Container $c): Pipeline => new Pipeline(
            $c->get('state'),
            $c->get('filesystem'),
            $c->get('db_step'),
            $c->get('files_step')
        ));
        $container->set('import_db_step', static fn (Container $c): ImportDatabase => new ImportDatabase(
            $c->get('database')
        ));
        $container->set('import_files_step', static fn (Container $c): ImportFiles => new ImportFiles(
            $c->get('filesystem')
        ));
        $container->set('import_pipeline', static fn (Container $c): ImportPipeline => new ImportPipeline(
            $c->get('state'),
            $c->get('filesystem'),
            $c->get('import_db_step'),
            $c->get('import_files_step')
        ));

        $router = new Router($container->get('pipeline'), $container->get('import_pipeline'));
        $router->register();

        // Pro features — only register if licensed
        $canUsePro = function_exists('trinity_backup_can_use_pro') && trinity_backup_can_use_pro();
        $hasFeature = static fn(string $f): bool => function_exists('trinity_backup_has_feature') && trinity_backup_has_feature($f);

        // Register scheduled backup hooks (Pro)
        if ($canUsePro && $hasFeature('scheduled') && class_exists(Scheduler::class)) {
            Scheduler::init();
        }

        // Register pre-update backups (Pro)
        if ($canUsePro && $hasFeature('pre_update') && class_exists(PreUpdateBackups::class)) {
            (new PreUpdateBackups($container->get('pipeline')))->register();
        }

        // White-label hooks (Pro — business plan)
        if ($canUsePro && $hasFeature('white_label') && class_exists(WhiteLabel::class)) {
            WhiteLabel::register();
        }

        // WP-CLI commands (Pro — business plan)
        if (defined('WP_CLI') && WP_CLI && $canUsePro && $hasFeature('wp_cli') && class_exists(\TrinityBackup\CLI\Command::class)) {
            \WP_CLI::add_command('trinity', \TrinityBackup\CLI\Command::class);
        }

        add_action('admin_menu', [$this, 'registerAdminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function registerAdminMenu(): void
    {
        $menuTitle = class_exists(WhiteLabel::class) ? WhiteLabel::getPluginName() : 'Trinity Backup';
        $menuIcon  = class_exists(WhiteLabel::class) ? WhiteLabel::getMenuIcon() : 'dashicons-database';

        add_menu_page(
            $menuTitle,
            $menuTitle,
            'manage_options',
            'trinity-backup',
            [$this, 'renderAdminPage'],
            $menuIcon,
            70
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_trinity-backup') {
            return;
        }

        $pluginFile = dirname(__DIR__, 2) . '/trinity-backup.php';
        $assetUrl = rtrim(plugin_dir_url($pluginFile), '/') . '/assets/';

        wp_enqueue_style(
            'trinity-backup',
            $assetUrl . 'trinity-backup.css',
            [],
            self::VERSION
        );

        wp_enqueue_style(
            'trinity-backup-modern',
            $assetUrl . 'css/modern.css',
            ['trinity-backup'],
            self::VERSION
        );

        wp_enqueue_script(
            'trinity-backup',
            $assetUrl . 'trinity-backup.js',
            [],
            self::VERSION,
            true
        );

        wp_localize_script('trinity-backup', 'TrinityBackup', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('trinity_backup'),
            'assetUrl' => $assetUrl,
            'imgUrl' => $assetUrl . 'img/',
            'loginUrl' => wp_login_url(),
            'homeUrl' => home_url('/'),
            'isPro' => function_exists('trinity_backup_can_use_pro') && trinity_backup_can_use_pro(),
            'pricingUrl' => 'https://trinity.kingaddons.com/',
        ]);
    }

    private function icon(string $name): string
    {
        $pluginFile = dirname(__DIR__, 2) . '/trinity-backup.php';
        $assetUrl = plugin_dir_url($pluginFile) . 'assets/img/';
        return '<img src="' . esc_url($assetUrl . 'icon-' . $name . '.svg') . '" class="trinity-icon" alt="" />';
    }

    /**
     * Render a Pro lock overlay for a gated panel.
     */
    private function renderProOverlay(string $title, string $description, string $plan = 'starter'): void
    {
        $pricingUrl = 'https://trinity.kingaddons.com/';
        $buttonLabel = $plan === 'business' ? 'Go to Unlimited &rarr;' : 'Upgrade to Pro &rarr;';

        echo '<div class="trinity-pro-overlay">';
        echo '<span class="trinity-pro-overlay__badge">';
        echo '<svg viewBox="0 0 24 24"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/></svg>';
        echo 'PRO</span>';
        echo '<p class="trinity-pro-overlay__title">' . esc_html($title) . '</p>';
        echo '<p class="trinity-pro-overlay__desc">' . esc_html($description) . '</p>';
        echo '<a href="' . esc_url($pricingUrl) . '" target="_blank" class="trinity-pro-overlay__btn">' . $buttonLabel . '</a>';
        echo '</div>';
    }

    public function renderAdminPage(): void
    {
        $currentUrl = home_url('/');
        $pluginFile = dirname(__DIR__, 2) . '/trinity-backup.php';
        $imgUrl = plugin_dir_url($pluginFile) . 'assets/img/';
        
        // Read theme from user meta
        $userId = get_current_user_id();
        $savedTheme = get_user_meta($userId, 'trinity_backup_theme', true);
        if (!in_array($savedTheme, ['light', 'dark', 'auto'], true)) {
            $savedTheme = 'auto';
        }
        
        echo '<div class="wrap">';
        echo '<h1 style="display:none;"></h1>'; // Catches all WP admin notices above our UI
        echo '</div>';

        echo '<div class="trinity-backup trinity-modern" data-theme="' . esc_attr($savedTheme) . '">';
        
        // Header with theme switcher
        $brandName = class_exists(WhiteLabel::class) ? WhiteLabel::getPluginName() : 'Trinity Backup';
        $brandDesc = class_exists(WhiteLabel::class) ? WhiteLabel::getPluginDescription() : 'Create backups of any size — fast, safe, and resumable.';
        $hideBranding = class_exists(WhiteLabel::class) ? WhiteLabel::shouldHideBranding() : false;

        echo '<div class="trinity-backup__header">';
        echo '<div class="trinity-backup__title-group">';
        if (!$hideBranding) {
            echo '<h1 class="trinity-backup__title"><img src="' . esc_url($imgUrl . 'icon-512x512.png') . '" class="trinity-icon trinity-app-icon" alt="" /> ' . esc_html($brandName) . '</h1>';
        } else {
            echo '<h1 class="trinity-backup__title">' . esc_html($brandName) . '</h1>';
        }
        echo '<p class="trinity-backup__subtitle">' . esc_html($brandDesc) . '</p>';
        echo '</div>';
        
        // Theme switcher - set active class based on saved theme
        echo '<div class="trinity-theme-switcher">';
        echo '<button type="button" class="trinity-theme-btn' . ($savedTheme === 'light' ? ' active' : '') . '" data-theme="light" title="Light theme">';
        echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>';
        echo '</button>';
        echo '<button type="button" class="trinity-theme-btn' . ($savedTheme === 'dark' ? ' active' : '') . '" data-theme="dark" title="Dark theme">';
        echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
        echo '</button>';
        echo '<button type="button" class="trinity-theme-btn' . ($savedTheme === 'auto' ? ' active' : '') . '" data-theme="auto" title="Auto (system)">';
        echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 2a10 10 0 0 1 0 20V2z" fill="currentColor"/></svg>';
        echo '</button>';
        echo '</div>';
        echo '</div>';
        
        // === EXPORT PANEL ===
        echo '<div class="trinity-backup__panel">';
        echo '<h3>' . $this->icon('export') . ' Export Site</h3>';
        
        // Export Options
        echo '<div class="trinity-backup__options">';
        echo '<span class="trinity-backup__options-title">Export Options</span>';
        echo '<div class="trinity-backup__options-row">';
        
        // Column 1
        echo '<div class="trinity-backup__options-col">';
        echo '<div class="trinity-backup__option">';
        echo '<input type="checkbox" id="trinity-opt-no-media" />';
        echo '<label for="trinity-opt-no-media">Exclude media uploads</label>';
        echo '</div>';
        echo '<div class="trinity-backup__option">';
        echo '<input type="checkbox" id="trinity-opt-no-plugins" />';
        echo '<label for="trinity-opt-no-plugins">Exclude plugins</label>';
        echo '</div>';
        echo '</div>';
        
        // Column 2
        echo '<div class="trinity-backup__options-col">';
        echo '<div class="trinity-backup__option">';
        echo '<input type="checkbox" id="trinity-opt-no-themes" />';
        echo '<label for="trinity-opt-no-themes">Exclude themes</label>';
        echo '</div>';
        echo '<div class="trinity-backup__option">';
        echo '<input type="checkbox" id="trinity-opt-no-database" />';
        echo '<label for="trinity-opt-no-database">Exclude database</label>';
        echo '</div>';
        echo '</div>';
        
        // Column 3
        echo '<div class="trinity-backup__options-col">';
        echo '<div class="trinity-backup__option">';
        echo '<input type="checkbox" id="trinity-opt-no-spam" />';
        echo '<label for="trinity-opt-no-spam">Exclude spam comments</label>';
        echo '</div>';
        echo '<div class="trinity-backup__option">';
        echo '<input type="checkbox" id="trinity-opt-no-email-replace" />';
        echo '<label for="trinity-opt-no-email-replace">Do not replace email domain</label>';
        echo '</div>';
        echo '</div>';
        
        // Column 4: Security (Pro feature: encryption)
        echo '<div class="trinity-backup__options-col">';
        $canEncrypt = function_exists('trinity_backup_has_feature') && trinity_backup_has_feature('encryption');
        if ($canEncrypt) {
            echo '<div class="trinity-backup__option">';
            echo '<input type="checkbox" id="trinity-opt-encrypt" />';
            echo '<label for="trinity-opt-encrypt">' . $this->icon('encrypt') . ' Encrypt backup</label>';
            echo '</div>';
            echo '<div class="trinity-backup__password-fields" id="trinity-password-fields" style="display: none;">';
            echo '<div class="trinity-backup__option trinity-backup__option--stacked" style="margin-bottom: 8px;">';
            echo '<label for="trinity-opt-password" style="display: block; margin-bottom: 4px;">Password:</label>';
            echo '<div class="trinity-password-wrapper">';
            echo '<input type="text" id="trinity-opt-password" placeholder="Enter password" />';
            echo '<span class="trinity-toggle-password" data-target="trinity-opt-password" title="Hide password">' . $this->icon('visibility-off') . '</span>';
            echo '</div>';
            echo '</div>';
            echo '<div class="trinity-backup__option trinity-backup__option--stacked">';
            echo '<label for="trinity-opt-password-confirm" style="display: block; margin-bottom: 4px;">Confirm:</label>';
            echo '<div class="trinity-password-wrapper">';
            echo '<input type="text" id="trinity-opt-password-confirm" placeholder="Repeat password" />';
            echo '<span class="trinity-toggle-password" data-target="trinity-opt-password-confirm" title="Hide password">' . $this->icon('visibility-off') . '</span>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        } else {
            echo '<div class="trinity-backup__option" style="pointer-events:none;">';
            echo '<input type="checkbox" disabled />';
            echo '<label>' . $this->icon('encrypt') . ' Encrypt backup <a href="https://trinity.kingaddons.com/" target="_blank" style="pointer-events:auto;color:#f59e0b;font-weight:600;font-size:11px;text-decoration:none;margin-left:4px;">PRO</a></label>';
            echo '</div>';
        }
        echo '</div>';
        
        echo '</div>'; // options-row
        
        // Auto-download option (outside columns, full width)
        echo '<div class="trinity-backup__option" style="margin-top: 10px; padding-top: 10px; border-top: 1px dashed var(--trinity-border, #dcdcde);">';
        echo '<input type="checkbox" id="trinity-opt-auto-download" checked />';
        echo '<label for="trinity-opt-auto-download">Automatically download backup after creation</label>';
        echo '</div>';
        
        echo '</div>'; // options
        
        echo '<button class="trinity-btn trinity-btn--primary trinity-btn--hero" id="trinity-backup-start">' . $this->icon('export') . ' Start Export</button>';
        echo '<div class="trinity-backup__feedback" id="trinity-export-feedback" style="display: none;">';
        echo '<div class="trinity-backup__status-row"><span class="trinity-backup__status" id="trinity-export-status"></span><span class="trinity-spinner" id="trinity-export-spinner" style="display: none;"></span></div>';
        echo '<div class="trinity-backup__progress"><div class="trinity-backup__progress-bar" id="trinity-export-progress"></div></div>';
        echo '<div class="trinity-backup__log" id="trinity-export-log"></div>';
        echo '</div>';
        echo '<a class="trinity-btn trinity-btn--secondary" id="trinity-backup-download" href="#" style="display:none;">' . $this->icon('import') . ' Download Backup</a>';
        echo '</div>';
        
        // === IMPORT PANEL ===
        echo '<div class="trinity-backup__panel trinity-backup__panel--import">';
        echo '<h3>' . $this->icon('import') . ' Import Site</h3>';
        echo '<div class="trinity-backup__dropzone" id="trinity-dropzone">';
        echo '<input type="file" id="trinity-backup-import-file" accept=".trinity" />';
        echo '<div class="trinity-backup__dropzone-content">';
        echo '<span class="trinity-backup__dropzone-icon">' . $this->icon('folder') . '</span>';
        echo '<span class="trinity-backup__dropzone-text">Drag & drop .trinity file here or click to browse</span>';
        echo '</div>';
        echo '</div>';
        echo '<button class="trinity-btn trinity-btn--primary trinity-btn--hero" id="trinity-backup-import-start" style="display: none;">' . $this->icon('import') . ' Start Import</button>';
        echo '<div class="trinity-backup__feedback" id="trinity-import-feedback" style="display: none;">';
        echo '<div class="trinity-backup__status-row"><span class="trinity-backup__status" id="trinity-import-status"></span><span class="trinity-spinner" id="trinity-import-spinner" style="display: none;"></span></div>';
        echo '<div class="trinity-backup__progress"><div class="trinity-backup__progress-bar" id="trinity-import-progress"></div></div>';
        echo '<div class="trinity-backup__log" id="trinity-import-log"></div>';
        echo '</div>';
        echo '</div>';
        
        // === BACKUPS LIST PANEL ===
        echo '<div class="trinity-backup__panel">';
        echo '<h3>' . $this->icon('folder') . ' Existing Backups</h3>';
        echo '<div class="trinity-backup__list-actions">';
        echo '<button class="trinity-btn trinity-btn--secondary" id="trinity-refresh-backups">' . $this->icon('refresh') . ' Refresh List</button>';
        echo '<button class="trinity-btn trinity-btn--secondary" id="trinity-cleanup-jobs">' . $this->icon('cleanup') . ' Cleanup Temp Files</button>';
        echo '<button class="trinity-btn trinity-btn--secondary" id="trinity-delete-all-backups">' . $this->icon('delete-all') . ' Delete All Backups</button>';
        echo '</div>';
        echo '<table class="trinity-backup__list">';
        echo '<thead><tr><th>Filename</th><th>Size</th><th>Created</th><th>Created By</th><th>Actions</th></tr></thead>';
        echo '<tbody id="trinity-backups-list"><tr><td colspan="5">Loading...</td></tr></tbody>';
        echo '</table>';
        echo '</div>';
        
        // === SCHEDULED BACKUPS PANEL (Pro) ===
        $hasScheduled = function_exists('trinity_backup_has_feature') && trinity_backup_has_feature('scheduled');
        echo '<div class="trinity-backup__panel trinity-pro-panel' . ($hasScheduled ? '' : ' trinity-pro-panel--locked') . '" style="display:block;">';
        if (!$hasScheduled) {
            $this->renderProOverlay('Scheduled Backups', 'Automate your backups on a daily, weekly, or custom schedule with WordPress Cron.');
        }
        echo '<div class="trinity-pro-panel__content">';
        echo '<h3>' . $this->icon('schedule') . ' Scheduled Backups</h3>';
        echo '<p class="description">Configure automatic backups to run on a regular schedule using WordPress Cron.</p>';
        
        echo '<div class="trinity-backup__schedule">';
        
        // Frequency
        echo '<div class="trinity-backup__schedule-row">';
        echo '<label for="trinity-schedule-frequency">Frequency:</label>';
        echo '<select id="trinity-schedule-frequency">';
        echo '<option value="disabled">Disabled</option>';
        echo '<optgroup label="Standard">';
        echo '<option value="weekly">Weekly</option>';
        echo '<option value="daily">Daily</option>';
        echo '<option value="every_12hours">Every 12 Hours</option>';
        echo '<option value="every_6hours">Every 6 Hours</option>';
        echo '<option value="every_4hours">Every 4 Hours</option>';
        echo '<option value="every_2hours">Every 2 Hours</option>';
        echo '<option value="hourly">Hourly</option>';
        echo '</optgroup>';
        echo '<optgroup label="Short Periods For Testing">';
        echo '<option value="every_30min">Every 30 Minutes</option>';
        echo '<option value="every_15min">Every 15 Minutes</option>';
        echo '<option value="every_5min">Every 5 Minutes</option>';
        echo '<option value="every_3min">Every 3 Minutes</option>';
        echo '<option value="every_1min">Every 1 Minute</option>';
        echo '</optgroup>';
        echo '</select>';
        echo '</div>';
        
        // Time (only for daily/weekly)
        echo '<div class="trinity-backup__schedule-row" id="trinity-schedule-time-row">';
        echo '<label for="trinity-schedule-time">Time:</label>';
        echo '<select id="trinity-schedule-time">';
        for ($h = 0; $h < 24; $h++) {
            $timeVal = sprintf('%02d:00', $h);
            $timeDisplay = date('g:i A', strtotime($timeVal));
            echo '<option value="' . esc_attr($timeVal) . '">' . esc_html($timeDisplay) . '</option>';
        }
        echo '</select>';
        echo '<span class="description" style="margin-left: 8px;">(Server time: ' . esc_html(date_i18n('g:i A T')) . ')</span>';
        echo '</div>';
        
        // Retention
        echo '<div class="trinity-backup__schedule-row">';
        echo '<label for="trinity-schedule-retention">Keep last:</label>';
        echo '<select id="trinity-schedule-retention">';
        for ($r = 1; $r <= 10; $r++) {
            echo '<option value="' . $r . '">' . $r . ' backup' . ($r > 1 ? 's' : '') . '</option>';
        }
        echo '</select>';
        echo '<span class="description" style="margin-left: 8px;">Older scheduled backups will be automatically deleted</span>';
        echo '</div>';
        
        // Save button and status
        echo '<div class="trinity-backup__schedule-row" style="margin-top: 15px;">';
        echo '<button class="trinity-btn trinity-btn--primary" id="trinity-schedule-save">' . $this->icon('save') . ' Save Schedule</button>';
        echo '<span id="trinity-schedule-status" style="margin-left: 10px;"></span>';
        echo '</div>';
        
        // Next run info
        echo '<div class="trinity-backup__schedule-info" id="trinity-schedule-info" style="display: none;">';
        echo $this->icon('calendar') . ' <strong>Next scheduled backup:</strong> <span id="trinity-schedule-next">-</span>';
        echo '</div>';
        
        echo '</div>'; // schedule
        echo '</div>'; // trinity-pro-panel__content
        echo '</div>'; // panel

        // === PRE-UPDATE BACKUPS PANEL (Pro) ===
        $hasPreUpdate = function_exists('trinity_backup_has_feature') && trinity_backup_has_feature('pre_update');
        echo '<div class="trinity-backup__panel trinity-pro-panel' . ($hasPreUpdate ? '' : ' trinity-pro-panel--locked') . '" style="display:block;">';
        if (!$hasPreUpdate) {
            $this->renderProOverlay('Pre-Update Backups', 'Automatically back up your site before any plugin, theme, or core update.');
        }
        echo '<div class="trinity-pro-panel__content">';
        echo '<h3>' . $this->icon('update') . ' Pre-Update Backups</h3>';
        echo '<p class="description">Automatically create a full backup before updating plugins, themes, or WordPress core. Bulk updates create a single backup per update run.</p>';

        // Master toggle row
        echo '<div class="trinity-preupdate">';
        echo '<div class="trinity-preupdate__master">';
        echo '<label class="trinity-toggle trinity-toggle--large">';
        echo '<input type="checkbox" id="trinity-preupdate-enabled" />';
        echo '<span class="trinity-toggle__track"><span class="trinity-toggle__thumb"></span></span>';
        echo '<span class="trinity-toggle__text"><strong>Enable Pre-Update Backups</strong><span>Create automatic backups before any update</span></span>';
        echo '</label>';
        echo '</div>';

        // Dependent options container (visually connected)
        echo '<div class="trinity-preupdate__options" id="trinity-preupdate-options">';

        // Safety block
        echo '<div class="trinity-preupdate__section">';
        echo '<div class="trinity-preupdate__section-header">' . $this->icon('shield') . ' Safety</div>';
        echo '<div class="trinity-preupdate__card">';
        echo '<label class="trinity-preupdate__checkbox">';
        echo '<input type="checkbox" id="trinity-preupdate-block-updates" checked />';
        echo '<span><strong>Block updates if backup fails</strong></span>';
        echo '</label>';
        echo '<div class="trinity-preupdate__hint" style="margin-left: 28px; margin-top: 6px;">Recommended. WordPress will stop the update if pre-update backup cannot complete.</div>';
        echo '</div>';
        echo '</div>';

        // Exclusions block
        echo '<div class="trinity-preupdate__section">';
        echo '<div class="trinity-preupdate__section-header">' . $this->icon('folder') . ' Backup Content</div>';
        echo '<div class="trinity-preupdate__card">';
        echo '<p class="trinity-preupdate__card-desc">By default, a full backup is created. Optionally exclude specific content:</p>';
        echo '<div class="trinity-preupdate__grid">';

        echo '<label class="trinity-preupdate__checkbox"><input type="checkbox" id="trinity-preupdate-no-media" /><span>Exclude media uploads</span></label>';
        echo '<label class="trinity-preupdate__checkbox"><input type="checkbox" id="trinity-preupdate-no-themes" /><span>Exclude themes</span></label>';
        echo '<label class="trinity-preupdate__checkbox"><input type="checkbox" id="trinity-preupdate-no-spam" /><span>Exclude spam comments</span></label>';
        echo '<label class="trinity-preupdate__checkbox"><input type="checkbox" id="trinity-preupdate-no-plugins" /><span>Exclude plugins</span></label>';
        echo '<label class="trinity-preupdate__checkbox"><input type="checkbox" id="trinity-preupdate-no-database" /><span>Exclude database</span></label>';
        echo '<label class="trinity-preupdate__checkbox"><input type="checkbox" id="trinity-preupdate-no-email-replace" /><span>Keep email domain</span></label>';

        echo '</div>'; // grid
        echo '</div>'; // card
        echo '</div>'; // section
        echo '</div>'; // options

        // Save row
        echo '<div class="trinity-preupdate__footer">';
        echo '<button class="trinity-btn trinity-btn--primary" id="trinity-preupdate-save">' . $this->icon('save') . ' Save Settings</button>';
        echo '<span id="trinity-preupdate-status" class="trinity-preupdate__status"></span>';
        echo '</div>';

        echo '</div>'; // trinity-preupdate
        echo '</div>'; // trinity-pro-panel__content
        echo '</div>'; // panel

        // === EMAIL NOTIFICATIONS PANEL (Pro) ===
        $hasEmail = function_exists('trinity_backup_has_feature') && trinity_backup_has_feature('email');
        echo '<div class="trinity-backup__panel trinity-pro-panel' . ($hasEmail ? '' : ' trinity-pro-panel--locked') . '" style="display:block;">';
        if (!$hasEmail) {
            $this->renderProOverlay('Email Notifications', 'Get notified by email when backups succeed or fail.');
        }
        echo '<div class="trinity-pro-panel__content">';
        echo '<h3>' . $this->icon('export') . ' Email Notifications</h3>';
        echo '<p class="description">Receive email notifications when backups or imports complete or fail.</p>';

        echo '<div class="trinity-preupdate">';

        // Master toggle
        echo '<div class="trinity-preupdate__master">';
        echo '<label class="trinity-toggle trinity-toggle--large">';
        echo '<input type="checkbox" id="trinity-email-enabled" />';
        echo '<span class="trinity-toggle__track"><span class="trinity-toggle__thumb"></span></span>';
        echo '<span class="trinity-toggle__text"><strong>Enable Email Notifications</strong><span>Send email when operations finish</span></span>';
        echo '</label>';
        echo '</div>';

        echo '<div class="trinity-preupdate__options" id="trinity-email-options">';

        // Recipients
        echo '<div class="trinity-preupdate__section">';
        echo '<div class="trinity-preupdate__section-header">' . $this->icon('export') . ' Recipients</div>';
        echo '<div class="trinity-preupdate__card">';
        echo '<label for="trinity-email-recipients" style="display:block;margin-bottom:6px;"><strong>Email addresses</strong> (comma-separated)</label>';
        echo '<input type="text" id="trinity-email-recipients" class="regular-text" style="width:100%;max-width:500px;" placeholder="' . esc_attr((string) get_option('admin_email')) . '" />';
        echo '<div class="trinity-preupdate__hint" style="margin-left:0;">Leave blank to use the site admin email.</div>';
        echo '</div>';
        echo '</div>';

        // Events
        echo '<div class="trinity-preupdate__section">';
        echo '<div class="trinity-preupdate__section-header">' . $this->icon('schedule') . ' Notify On</div>';
        echo '<div class="trinity-preupdate__card">';
        echo '<div class="trinity-preupdate__grid">';
        echo '<label class="trinity-preupdate__checkbox"><input type="checkbox" id="trinity-email-on-manual" /><span>Manual backups</span></label>';
        echo '<label class="trinity-preupdate__checkbox"><input type="checkbox" id="trinity-email-on-scheduled" checked /><span>Scheduled backups</span></label>';
        echo '<label class="trinity-preupdate__checkbox"><input type="checkbox" id="trinity-email-on-preupdate" checked /><span>Pre-update backups</span></label>';
        echo '<label class="trinity-preupdate__checkbox"><input type="checkbox" id="trinity-email-on-import" checked /><span>Import / restore</span></label>';
        echo '<label class="trinity-preupdate__checkbox"><input type="checkbox" id="trinity-email-failure-only" /><span>Only on failure</span></label>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '</div>'; // options

        // Save
        echo '<div class="trinity-preupdate__footer">';
        echo '<button class="trinity-btn trinity-btn--primary" id="trinity-email-save">' . $this->icon('save') . ' Save Settings</button>';
        echo '<button class="trinity-btn" id="trinity-email-test" style="margin-left:8px;">' . $this->icon('export') . ' Send test email</button>';
        echo '<span id="trinity-email-status" class="trinity-preupdate__status"></span>';
        echo '<span id="trinity-email-test-status" class="trinity-preupdate__status" style="margin-left:8px;"></span>';
        echo '</div>';

        echo '</div>'; // trinity-preupdate
        echo '</div>'; // trinity-pro-panel__content
        echo '</div>'; // panel

        // === WHITE LABEL PANEL (Pro — business plan) ===
        $hasWhiteLabel = function_exists('trinity_backup_has_feature') && trinity_backup_has_feature('white_label');
        $canAccessWhiteLabelSettings = class_exists(WhiteLabel::class) ? WhiteLabel::canCurrentUserAccessSettings() : false;

        if ($hasWhiteLabel && !$canAccessWhiteLabelSettings) {
            echo '</div>'; // trinity-backup trinity-modern
            return;
        }

        echo '<div class="trinity-backup__panel trinity-pro-panel' . ($hasWhiteLabel ? '' : ' trinity-pro-panel--locked') . '" style="display:block;">';
        if (!$hasWhiteLabel) {
            $this->renderProOverlay('White Label', 'Rebrand the plugin with your own name, icon, and author details.', 'business');
        }
        echo '<div class="trinity-pro-panel__content">';
        echo '<h3>' . $this->icon('shield') . ' White Label</h3>';
        echo '<p class="description">Customise the plugin branding. Changes apply to the admin menu, page header, and plugins list.</p>';

        echo '<div class="trinity-preupdate">';

        // Master toggle
        echo '<div class="trinity-preupdate__master">';
        echo '<label class="trinity-toggle trinity-toggle--large">';
        echo '<input type="checkbox" id="trinity-wl-enabled" />';
        echo '<span class="trinity-toggle__track"><span class="trinity-toggle__thumb"></span></span>';
        echo '<span class="trinity-toggle__text"><strong>Enable White Label</strong><span>Replace plugin branding with your own</span></span>';
        echo '</label>';
        echo '</div>';

        echo '<div class="trinity-preupdate__options" id="trinity-wl-options">';

        echo '<div class="trinity-preupdate__section">';
        echo '<div class="trinity-preupdate__section-header">' . $this->icon('export') . ' Branding</div>';
        echo '<div class="trinity-preupdate__card">';

        echo '<div style="margin-bottom:12px;">';
        echo '<label for="trinity-wl-visible-user" style="display:block;margin-bottom:4px;"><strong>Who can access White Label settings</strong></label>';
        echo '<select id="trinity-wl-visible-user" class="regular-text" style="width:100%;max-width:400px;">';
        echo '<option value="0">All administrators</option>';
        foreach (get_users(['role' => 'administrator', 'orderby' => 'display_name', 'order' => 'ASC']) as $adminUser) {
            if (!is_object($adminUser) || !isset($adminUser->ID)) {
                continue;
            }
            $uid = (int) $adminUser->ID;
            $name = trim((string) ($adminUser->display_name ?? ''));
            $login = (string) ($adminUser->user_login ?? '');
            $label = $name !== '' ? ($name . ' (' . $login . ')') : $login;
            echo '<option value="' . esc_attr((string) $uid) . '">' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<div class="trinity-preupdate__hint" style="margin-left:0;">Choose one admin to be the only user who can view and edit this White Label block.</div>';
        echo '<button type="button" class="trinity-btn" id="trinity-wl-use-current-user" data-current-user-id="' . esc_attr((string) get_current_user_id()) . '" style="margin-top:8px;">Use current user (me)</button>';
        echo '</div>';

        echo '<label style="margin-bottom:12px;" class="trinity-preupdate__checkbox"><input type="checkbox" id="trinity-wl-hide-branding" /><span><strong>Hide logo in page header</strong></span></label>';
        echo '<label style="margin-bottom:12px;" class="trinity-preupdate__checkbox"><input type="checkbox" id="trinity-wl-hide-account-menu" /><span><strong>Hide "Account" submenu item</strong></span></label>';
        echo '<label style="margin-bottom:12px;" class="trinity-preupdate__checkbox"><input type="checkbox" id="trinity-wl-hide-contact-menu" /><span><strong>Hide "Contact Us" submenu item</strong></span></label>';
        echo '<label style="margin-bottom:12px;" class="trinity-preupdate__checkbox"><input type="checkbox" id="trinity-wl-hide-view-details" /><span><strong>Hide "View details" link on Plugins page</strong></span></label>';
        echo '<label style="margin-bottom:12px;" class="trinity-preupdate__checkbox"><input type="checkbox" id="trinity-wl-only-deactivate-action" /><span><strong>Keep only "Deactivate" action on Plugins page</strong></span></label>';

        echo '<div style="margin-bottom:12px;">';
        echo '<label for="trinity-wl-name" style="display:block;margin-bottom:4px;"><strong>Plugin Name</strong></label>';
        echo '<input type="text" id="trinity-wl-name" class="regular-text" style="width:100%;max-width:400px;" placeholder="Trinity Backup" />';
        echo '</div>';

        echo '<div style="margin-bottom:12px;">';
        echo '<label for="trinity-wl-description" style="display:block;margin-bottom:4px;"><strong>Description / Subtitle</strong></label>';
        echo '<input type="text" id="trinity-wl-description" class="regular-text" style="width:100%;max-width:400px;" placeholder="Create backups of any size — fast, safe, and resumable." />';
        echo '</div>';

        echo '<div style="margin-bottom:12px;">';
        echo '<label for="trinity-wl-author" style="display:block;margin-bottom:4px;"><strong>Author Name</strong></label>';
        echo '<input type="text" id="trinity-wl-author" class="regular-text" style="width:100%;max-width:400px;" placeholder="" />';
        echo '</div>';

        echo '<div style="margin-bottom:12px;">';
        echo '<label for="trinity-wl-author-url" style="display:block;margin-bottom:4px;"><strong>Author URL</strong></label>';
        echo '<input type="url" id="trinity-wl-author-url" class="regular-text" style="width:100%;max-width:400px;" placeholder="https://" />';
        echo '</div>';

        echo '<div style="margin-bottom:12px;">';
        echo '<label for="trinity-wl-icon" style="display:block;margin-bottom:4px;"><strong>Menu Icon</strong></label>';
        echo '<input type="text" id="trinity-wl-icon" class="regular-text" style="width:100%;max-width:400px;" placeholder="dashicons-database" />';
        echo '<div class="trinity-preupdate__hint" style="margin-left:0;">Dashicon class (e.g. dashicons-shield) or a full URL to an icon image.</div>';
        echo '</div>';

        echo '</div>'; // card
        echo '</div>'; // section
        echo '</div>'; // options

        // Save
        echo '<div class="trinity-preupdate__footer">';
        echo '<button class="trinity-btn trinity-btn--primary" id="trinity-wl-save">' . $this->icon('save') . ' Save Settings</button>';
        echo '<span id="trinity-wl-status" class="trinity-preupdate__status"></span>';
        echo '</div>';

        echo '</div>'; // trinity-preupdate (white label)
        echo '</div>'; // trinity-pro-panel__content
        echo '</div>'; // panel
        
        echo '</div>'; // trinity-backup trinity-modern
    }
}
