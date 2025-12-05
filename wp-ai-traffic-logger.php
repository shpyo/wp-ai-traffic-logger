<?php

/**
 * Plugin Name: WP AI Traffic Logger
 * Plugin URI: https://github.com/shpyo/wp-ai-traffic-logger
 * Description: Log all traffic from AI bots (ChatGPT, Claude, Gemini, Perplexity, etc.) and AI referrers with minimal performance impact using batch processing.
 * Version: 1.0.0
 * Author: Piotr Cichosz
 * Author URI: https://github.com/shpyo
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: wp-ai-traffic-logger
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 8.1
 */

namespace WPAITrafficLogger;

if (!defined('ABSPATH')) {
    exit;
}

const TEXT_DOMAIN = 'wp-ai-traffic-logger';

const SCHEDULED_HOOKS = [
    'wpai_process_log_queue' => 'wpai_five_minutes',
    'wpai_cleanup_old_logs' => 'daily',
];

/**
 * Queue Repository - handles queue table operations
 */
final class QueueRepository
{
    private static function getTableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ai_traffic_log_queue';
    }

    public static function enqueue(array $entry): void
    {
        global $wpdb;
        $table_name = self::getTableName();

        $wpdb->insert(
            $table_name,
            [
                'logged_at' => $entry['timestamp'],
                'user_agent' => $entry['user_agent'],
                'referrer' => $entry['referrer'],
                'ip_hash' => $entry['ip_hash'],
                'url' => $entry['url'],
                'request_method' => $entry['request_method'],
                'bot_type' => $entry['bot_type'],
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    public static function fetchBatch(int $limit = 500): array
    {
        global $wpdb;
        $table_name = self::getTableName();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} ORDER BY id ASC LIMIT %d",
            $limit
        ));
    }

    public static function deleteByIds(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        global $wpdb;
        $table_name = self::getTableName();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE id IN ({$placeholders})",
            ...$ids
        ));
    }

    public static function count(): int
    {
        global $wpdb;
        $table_name = self::getTableName();

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    }
}

final class AdminView
{
        public static function notice(string $type, string $message, ?string $label = null): void
        {
            echo '<div class="notice notice-' . esc_attr($type) . '"><p>';
            if ($label !== null) {
                echo '<strong>' . esc_html($label) . '</strong> ';
            }
            echo esc_html($message) . '</p></div>';
        }

        public static function section(string $title, callable $callback, array $options = []): void
        {
            $style = $options['style'] ?? 'background: #fff; padding: 15px; border: 1px solid #ccd0d4; margin: 20px 0;';
            $tag = $options['heading_tag'] ?? 'h3';

            echo '<div class="wpai-card" style="' . esc_attr($style) . '">';
            if ($title !== '') {
                echo '<' . esc_attr($tag) . ' style="margin-top: 0;">' . esc_html($title) . '</' . esc_attr($tag) . '>';
            }
            call_user_func($callback);
            echo '</div>';
        }

        public static function statCard(string $title, string $value, string $accentColor): void
        {
            echo '<div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-left: 4px solid ' . esc_attr($accentColor) . ';">';
            echo '<h3 style="margin: 0 0 10px 0; color: #666;">' . esc_html($title) . '</h3>';
            echo '<p style="font-size: 32px; font-weight: bold; margin: 0; color: ' . esc_attr($accentColor) . ';">' . esc_html($value) . '</p>';
            echo '</div>';
        }

        public static function emptyState(string $message, string $type = 'warning'): void
        {
            self::notice($type, $message);
        }
    }

    final class LogsPage
    {
        public static function render(): void
        {
            global $wpdb;

            if (!current_user_can('manage_options')) {
                return;
            }

            $table_name = $wpdb->prefix . 'ai_traffic_logs';

            if (isset($_POST['wpai_clear_logs']) && check_admin_referer('wpai_clear_logs_action', 'wpai_clear_logs_nonce')) {
                $wpdb->query("TRUNCATE TABLE {$table_name}");
                AdminView::notice('success', __('All logs have been cleared.', TEXT_DOMAIN));
            }

            $filter_bot = isset($_GET['filter_bot']) ? sanitize_text_field($_GET['filter_bot']) : '';
            $filter_date_from = isset($_GET['filter_date_from']) ? sanitize_text_field($_GET['filter_date_from']) : '';
            $filter_date_to = isset($_GET['filter_date_to']) ? sanitize_text_field($_GET['filter_date_to']) : '';

            $per_page = 50;
            $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
            $offset = ($current_page - 1) * $per_page;

            $where = [];
            $values = [];

            if ($filter_bot !== '') {
                $where[] = 'bot_type = %s';
                $values[] = $filter_bot;
            }

            if ($filter_date_from !== '') {
                $where[] = 'logged_at >= %s';
                $values[] = $filter_date_from . ' 00:00:00';
            }

            if ($filter_date_to !== '') {
                $where[] = 'logged_at <= %s';
                $values[] = $filter_date_to . ' 23:59:59';
            }

            $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            if ($values) {
                $total_items = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} {$where_sql}", ...$values));
            } else {
                $total_items = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            }

            $query_values = array_merge($values, [$per_page, $offset]);
            if ($values) {
                $logs = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$table_name} {$where_sql} ORDER BY logged_at DESC LIMIT %d OFFSET %d",
                    ...$query_values
                ));
            } else {
                $logs = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$table_name} ORDER BY logged_at DESC LIMIT %d OFFSET %d",
                    $per_page,
                    $offset
                ));
            }

            $total_pages = (int) ceil(max(1, $total_items) / $per_page);
            $bot_types = $wpdb->get_col("SELECT DISTINCT bot_type FROM {$table_name} ORDER BY bot_type");

            ?>
            <div class="wrap">
                <h1><?php esc_html_e('AI Traffic Logs', TEXT_DOMAIN); ?></h1>
                <?php AdminView::notice('info', __('This page shows all detected traffic from AI bots and AI referrers. Logs are processed in batches every 5 minutes for optimal performance.', TEXT_DOMAIN), __('About:', TEXT_DOMAIN)); ?>

                <form method="get">
                    <input type="hidden" name="page" value="wp-ai-traffic-logger" />
                    <?php
                    AdminView::section(
                        __('Filter Logs', TEXT_DOMAIN),
                        function () use ($bot_types, $filter_bot, $filter_date_from, $filter_date_to) {
                            ?>
                            <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
                                <div>
                                    <label for="filter_bot"><strong><?php esc_html_e('Bot Type:', TEXT_DOMAIN); ?></strong></label>
                                    <select name="filter_bot" id="filter_bot">
                                        <option value=""><?php esc_html_e('All Bots', TEXT_DOMAIN); ?></option>
                                        <?php foreach ($bot_types as $bot): ?>
                                            <option value="<?php echo esc_attr($bot); ?>" <?php selected($filter_bot, $bot); ?>><?php echo esc_html($bot); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label for="filter_date_from"><strong><?php esc_html_e('From:', TEXT_DOMAIN); ?></strong></label>
                                    <input type="date" name="filter_date_from" id="filter_date_from" value="<?php echo esc_attr($filter_date_from); ?>" />
                                </div>
                                <div>
                                    <label for="filter_date_to"><strong><?php esc_html_e('To:', TEXT_DOMAIN); ?></strong></label>
                                    <input type="date" name="filter_date_to" id="filter_date_to" value="<?php echo esc_attr($filter_date_to); ?>" />
                                </div>
                                <div>
                                    <button type="submit" class="button button-primary"><?php esc_html_e('Apply Filters', TEXT_DOMAIN); ?></button>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-ai-traffic-logger')); ?>" class="button"><?php esc_html_e('Reset', TEXT_DOMAIN); ?></a>
                                </div>
                            </div>
                            <?php
                        }
                    );
                    ?>
                </form>

                <?php
                AdminView::section(
                    __('Quick Stats', TEXT_DOMAIN),
                    function () use ($total_items) {
                        ?>
                        <p><strong><?php esc_html_e('Total Logs:', TEXT_DOMAIN); ?></strong> <?php echo esc_html(number_format($total_items)); ?></p>
                        <?php
                    }
                );
                ?>

                <?php if (empty($logs)): ?>
                    <?php AdminView::emptyState(__('No logs found. AI traffic will appear here when detected.', TEXT_DOMAIN)); ?>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 140px;"><?php esc_html_e('Date & Time', TEXT_DOMAIN); ?></th>
                                <th style="width: 130px;"><?php esc_html_e('Bot Type', TEXT_DOMAIN); ?></th>
                                <th style="width: 200px;"><?php esc_html_e('User Agent', TEXT_DOMAIN); ?></th>
                                <th style="width: 150px;"><?php esc_html_e('Referrer', TEXT_DOMAIN); ?></th>
                                <th><?php esc_html_e('URL', TEXT_DOMAIN); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($log->logged_at))); ?></td>
                                    <td>
                                        <span style="display: inline-block; padding: 3px 8px; background: #3f83f8; color: #fff; border-radius: 3px; font-size: 11px; font-weight: 600;">
                                            <?php echo esc_html($log->bot_type); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span title="<?php echo esc_attr($log->user_agent); ?>">
                                            <?php
                                            $ua = esc_html($log->user_agent);
                                            echo strlen($ua) > 35 ? esc_html(mb_substr($ua, 0, 35)) . '&hellip;' : $ua;
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($log->referrer)): ?>
                                            <span title="<?php echo esc_attr($log->referrer); ?>">
                                                <?php
                                                $ref = esc_html($log->referrer);
                                                echo strlen($ref) > 25 ? esc_html(mb_substr($ref, 0, 25)) . '&hellip;' : $ref;
                                                ?>
                                            </span>
                                        <?php else: ?>
                                            <span>&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url(home_url($log->url)); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr($log->url); ?>">
                                            <?php
                                            $url = esc_html($log->url);
                                            echo strlen($url) > 50 ? esc_html(mb_substr($url, 0, 50)) . '&hellip;' : $url;
                                            ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($total_pages > 1): ?>
                        <div class="tablenav">
                            <div class="tablenav-pages">
                                <span class="displaying-num"><?php echo esc_html(number_format($total_items)); ?> <?php esc_html_e('items', TEXT_DOMAIN); ?></span>
                                <?php
                                $base_url = admin_url('admin.php?page=wp-ai-traffic-logger');
                                if ($filter_bot) {
                                    $base_url = add_query_arg('filter_bot', $filter_bot, $base_url);
                                }
                                if ($filter_date_from) {
                                    $base_url = add_query_arg('filter_date_from', $filter_date_from, $base_url);
                                }
                                if ($filter_date_to) {
                                    $base_url = add_query_arg('filter_date_to', $filter_date_to, $base_url);
                                }

                                echo paginate_links([
                                    'base' => add_query_arg('paged', '%#%', $base_url),
                                    'format' => '',
                                    'current' => $current_page,
                                    'total' => max(1, $total_pages),
                                ]);
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php
        }
    }

    final class StatsPage
    {
        public static function render(): void
        {
            global $wpdb;

            if (!current_user_can('manage_options')) {
                return;
            }

            $table_name = $wpdb->prefix . 'ai_traffic_logs';
            $days = isset($_GET['days']) ? max(1, (int) $_GET['days']) : 30;

            $total_visits = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ));

            $top_bots = $wpdb->get_results($wpdb->prepare(
                "SELECT bot_type, COUNT(*) AS count
                 FROM {$table_name}
                 WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                 GROUP BY bot_type
                 ORDER BY count DESC
                 LIMIT 10",
                $days
            ));

            $daily_trend = $wpdb->get_results($wpdb->prepare(
                "SELECT DATE(logged_at) AS date, COUNT(*) AS count
                 FROM {$table_name}
                 WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                 GROUP BY DATE(logged_at)
                 ORDER BY date DESC",
                $days
            ));

            $referrer_stats = $wpdb->get_results($wpdb->prepare(
                "SELECT
                    CASE WHEN referrer IS NULL OR referrer = '' THEN 'Direct' ELSE 'AI Referral' END AS referrer_type,
                    COUNT(*) AS count
                 FROM {$table_name}
                 WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                 GROUP BY referrer_type",
                $days
            ));

            ?>
            <div class="wrap">
                <h1><?php esc_html_e('AI Traffic Statistics', TEXT_DOMAIN); ?></h1>

                <form method="get" style="margin: 20px 0;">
                    <input type="hidden" name="page" value="wp-ai-traffic-logger-stats" />
                    <label for="days"><strong><?php esc_html_e('Time Period:', TEXT_DOMAIN); ?></strong></label>
                    <select name="days" id="days" onchange="this.form.submit()">
                        <option value="7" <?php selected($days, 7); ?>><?php esc_html_e('Last 7 days', TEXT_DOMAIN); ?></option>
                        <option value="30" <?php selected($days, 30); ?>><?php esc_html_e('Last 30 days', TEXT_DOMAIN); ?></option>
                        <option value="90" <?php selected($days, 90); ?>><?php esc_html_e('Last 90 days', TEXT_DOMAIN); ?></option>
                        <option value="365" <?php selected($days, 365); ?>><?php esc_html_e('Last year', TEXT_DOMAIN); ?></option>
                    </select>
                </form>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin: 20px 0;">
                    <?php
                    AdminView::statCard(__('Total AI Visits', TEXT_DOMAIN), number_format($total_visits), '#3f83f8');
                    AdminView::statCard(__('Unique Bots', TEXT_DOMAIN), number_format(count($top_bots)), '#0e9f6e');
                    $average = $days > 0 ? $total_visits / $days : 0;
                    AdminView::statCard(__('Avg Daily Visits', TEXT_DOMAIN), number_format($average, 1), '#f59e0b');
                    ?>
                </div>

                <?php
                $section_style = 'background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin: 20px 0;';

                AdminView::section(
                    __('Top AI Bots', TEXT_DOMAIN),
                    function () use ($top_bots, $total_visits) {
                        if (!$top_bots) {
                            echo '<p>' . esc_html__('No data available.', TEXT_DOMAIN) . '</p>';
                            return;
                        }
                        ?>
                        <table class="wp-list-table widefat">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Bot Type', TEXT_DOMAIN); ?></th>
                                    <th style="text-align: right;">&nbsp;</th>
                                    <th style="text-align: right;">%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top_bots as $bot): ?>
                                    <tr>
                                        <td><?php echo esc_html($bot->bot_type); ?></td>
                                        <td style="text-align: right;"><?php echo esc_html(number_format($bot->count)); ?></td>
                                        <td style="text-align: right;">
                                            <?php
                                            $percentage = $total_visits > 0 ? ($bot->count / $total_visits) * 100 : 0;
                                            echo esc_html(number_format($percentage, 1));
                                            ?>%
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php
                    },
                    ['heading_tag' => 'h2', 'style' => $section_style]
                );

                AdminView::section(
                    __('Daily Traffic Trend', TEXT_DOMAIN),
                    function () use ($daily_trend) {
                        if (!$daily_trend) {
                            echo '<p>' . esc_html__('No data available.', TEXT_DOMAIN) . '</p>';
                            return;
                        }
                        $max_count = max(array_column($daily_trend, 'count')) ?: 1;
                        ?>
                        <table class="wp-list-table widefat striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Date', TEXT_DOMAIN); ?></th>
                                    <th style="text-align: right;"><?php esc_html_e('Visits', TEXT_DOMAIN); ?></th>
                                    <th style="width: 60%;"><?php esc_html_e('Visualization', TEXT_DOMAIN); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($daily_trend as $day):
                                    $percentage = ($day->count / $max_count) * 100;
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html($day->date); ?></td>
                                        <td style="text-align: right;"><?php echo esc_html(number_format($day->count)); ?></td>
                                        <td>
                                            <div style="background: #e5e7eb; height: 20px; border-radius: 3px; overflow: hidden;">
                                                <div style="background: #3f83f8; height: 100%; width: <?php echo esc_attr($percentage); ?>%;"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php
                    },
                    ['heading_tag' => 'h2', 'style' => $section_style]
                );

                AdminView::section(
                    __('Traffic Source', TEXT_DOMAIN),
                    function () use ($referrer_stats, $total_visits) {
                        if (!$referrer_stats) {
                            echo '<p>' . esc_html__('No data available.', TEXT_DOMAIN) . '</p>';
                            return;
                        }
                        ?>
                        <table class="wp-list-table widefat">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Source Type', TEXT_DOMAIN); ?></th>
                                    <th style="text-align: right;"><?php esc_html_e('Visits', TEXT_DOMAIN); ?></th>
                                    <th style="text-align: right;">%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($referrer_stats as $stat): ?>
                                    <tr>
                                        <td><?php echo esc_html($stat->referrer_type); ?></td>
                                        <td style="text-align: right;"><?php echo esc_html(number_format($stat->count)); ?></td>
                                        <td style="text-align: right;">
                                            <?php
                                            $percentage = $total_visits > 0 ? ($stat->count / $total_visits) * 100 : 0;
                                            echo esc_html(number_format($percentage, 1));
                                            ?>%
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php
                    },
                    ['heading_tag' => 'h2', 'style' => $section_style]
                );
                ?>
            </div>
            <?php
        }
    }

    final class SettingsPage
    {
        public static function render(): void
        {
            if (!current_user_can('manage_options')) {
                return;
            }

            if (isset($_GET['settings-updated'])) {
                add_settings_error('wpai_messages', 'wpai_message', esc_html__('Settings saved.', TEXT_DOMAIN), 'success');
            }

            settings_errors('wpai_messages');

            ?>
            <div class="wrap">
                <h1><?php esc_html_e('AI Traffic Logger Settings', TEXT_DOMAIN); ?></h1>

                <form action="options.php" method="post">
                    <?php settings_fields('wpai_settings'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Enable Logging', TEXT_DOMAIN); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wpai_enabled" value="1" <?php checked(get_option('wpai_enabled', true), true); ?> />
                                    <?php esc_html_e('Enable AI traffic logging', TEXT_DOMAIN); ?>
                                </label>
                                <p class="description"><?php esc_html_e('Master switch to enable or disable the plugin.', TEXT_DOMAIN); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e('Log IP Hash', TEXT_DOMAIN); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wpai_log_ip_hash" value="1" <?php checked(get_option('wpai_log_ip_hash', true), true); ?> />
                                    <?php esc_html_e('Store hashed IP addresses', TEXT_DOMAIN); ?>
                                </label>
                                <p class="description"><?php esc_html_e('IPs are hashed with SHA256 for privacy compliance (GDPR-friendly). Disable to avoid storing IPs.', TEXT_DOMAIN); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e('Sampling Rate', TEXT_DOMAIN); ?></th>
                            <td>
                                <input type="number" name="wpai_sampling_rate" value="<?php echo esc_attr(get_option('wpai_sampling_rate', 100)); ?>" min="1" max="100" class="small-text" /> %
                                <p class="description"><?php esc_html_e('Log only a percentage of AI visits (1-100%). Use lower values for very high traffic sites.', TEXT_DOMAIN); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e('Log Retention', TEXT_DOMAIN); ?></th>
                            <td>
                                <input type="number" name="wpai_retention_days" value="<?php echo esc_attr(get_option('wpai_retention_days', 90)); ?>" min="0" class="small-text" /> <?php esc_html_e('days', TEXT_DOMAIN); ?>
                                <p class="description"><?php esc_html_e('Automatically delete logs older than this many days. Set to 0 to keep logs forever.', TEXT_DOMAIN); ?></p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('Save Settings', TEXT_DOMAIN)); ?>
                </form>

                <hr>

                <h2><?php esc_html_e('System Information', TEXT_DOMAIN); ?></h2>
                <table class="widefat">
                    <tr>
                        <td style="width: 200px;"><strong><?php esc_html_e('Database Table:', TEXT_DOMAIN); ?></strong></td>
                        <td><?php global $wpdb; echo esc_html($wpdb->prefix . 'ai_traffic_logs'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Batch Processing:', TEXT_DOMAIN); ?></strong></td>
                        <td><?php esc_html_e('Every 5 minutes via WP-Cron', TEXT_DOMAIN); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Queue Status:', TEXT_DOMAIN); ?></strong></td>
                        <td>
                            <?php
                            $count = QueueRepository::count();
                            echo $count > 0
                                ? sprintf(esc_html__('%d entries pending', TEXT_DOMAIN), (int) $count)
                                : esc_html__('Empty', TEXT_DOMAIN);
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Next Cleanup:', TEXT_DOMAIN); ?></strong></td>
                        <td>
                            <?php
                            $next_cleanup = wp_next_scheduled('wpai_cleanup_old_logs');
                            echo $next_cleanup ? esc_html(date('Y-m-d H:i:s', $next_cleanup)) : esc_html__('Not scheduled', TEXT_DOMAIN);
                            ?>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Detected AI Bots', TEXT_DOMAIN); ?></h2>
                <p><?php esc_html_e('The plugin currently detects the following AI bots and referrers:', TEXT_DOMAIN); ?></p>
                <ul style="columns: 3; list-style: disc; padding-left: 20px;">
                    <?php
                    $detected_bots = [
                        __('OpenAI / ChatGPT', TEXT_DOMAIN),
                        __('Claude / Anthropic', TEXT_DOMAIN),
                        __('Google Gemini', TEXT_DOMAIN),
                        __('Perplexity', TEXT_DOMAIN),
                        __('Grok (xAI)', TEXT_DOMAIN),
                        __('Meta AI', TEXT_DOMAIN),
                        __('You.com', TEXT_DOMAIN),
                        __('DeepSeek', TEXT_DOMAIN),
                        __('Cohere', TEXT_DOMAIN),
                        __('Apple Intelligence', TEXT_DOMAIN),
                        __('ByteDance / TikTok', TEXT_DOMAIN),
                        __('Amazon Alexa', TEXT_DOMAIN),
                        __('Bing Chat / Copilot', TEXT_DOMAIN),
                        __('Poe', TEXT_DOMAIN),
                        __('Phind', TEXT_DOMAIN),
                    ];

                    foreach ($detected_bots as $bot_label) {
                        echo '<li>' . esc_html($bot_label) . '</li>';
                    }
                    ?>
                </ul>
            </div>
            <?php
        }
    }

    /**
     * Detect known AI user agents
     */
    function isAIUserAgent(string $user_agent): array
    {
        if ($user_agent === '') {
            return ['detected' => false, 'bot_type' => null];
        }

        $ai_agents = [
            // OpenAI bots - order matters, check specific patterns first
            'chatgpt-user' => 'ChatGPT-User',
            'oai-searchbot' => 'OAI-SearchBot',
            'gptbot' => 'GPTBot',
            // Generic OpenAI patterns
            'chatgpt' => 'ChatGPT',
            'openai' => 'OpenAI',
            // Anthropic/Claude
            'claudebot' => 'ClaudeBot',
            'claude-web' => 'Claude Web',
            'anthropic-ai' => 'Anthropic',
            'google-extended' => 'Google Gemini',
            'google-other' => 'Google Other',
            'gemini' => 'Google Gemini',
            'perplexitybot' => 'Perplexity',
            'perplexity' => 'Perplexity',
            'magpie-crawler' => 'Perplexity',
            'YouBot' => 'You.com',
            'youchat' => 'You.com',
            'Bytespider' => 'ByteDance (TikTok)',
            'Applebot-Extended' => 'Apple Intelligence',
            'Applebot' => 'Apple Intelligence',
            'cohere-ai' => 'Cohere',
            'AI2Bot' => 'AI2 (Semantic Scholar)',
            'DuckAssistBot' => 'DuckAssist',
            'grok' => 'Grok (xAI)',
            'xai' => 'Grok (xAI)',
            'phindbot' => 'Phind',
            'MetaAI' => 'Meta AI',
            'facebookexternalhit' => 'Meta AI',
            'Amazonbot' => 'Amazon Alexa',
            'bingbot' => 'Bing Bot',
            'bingpreview' => 'Bing Preview',
            'msnbot' => 'Bing Bot',
        ];

        $user_agent_lower = strtolower($user_agent);
        foreach ($ai_agents as $agent_pattern => $bot_name) {
            if (str_contains($user_agent_lower, strtolower($agent_pattern))) {
                return ['detected' => true, 'bot_type' => $bot_name];
            }
        }

        return ['detected' => false, 'bot_type' => null];
    }

/**
 * Check if request is from an AI referrer
 */
function isAIReferrer(string $referrer): array
{
    if (empty($referrer)) {
        return ['detected' => false, 'bot_type' => null];
    }

    $ai_referrers = [
        'chatgpt.com' => 'ChatGPT Referral',
        'chat.openai.com' => 'ChatGPT Referral',
        'claude.ai' => 'Claude Referral',
        'gemini.google.com' => 'Gemini Referral',
        'bard.google.com' => 'Gemini Referral',
        'perplexity.ai' => 'Perplexity Referral',
        'you.com' => 'You.com Referral',
        'poe.com' => 'Poe Referral',
        'phind.com' => 'Phind Referral',
        'bing.com/chat' => 'Bing Chat Referral',
        'copilot.microsoft.com' => 'Copilot Referral',
    ];

    $referrer_lower = strtolower($referrer);
    foreach ($ai_referrers as $domain => $bot_name) {
        if (str_contains($referrer_lower, $domain)) {
            return ['detected' => true, 'bot_type' => $bot_name];
        }
    }

    return ['detected' => false, 'bot_type' => null];
}

/**
 * Hash IP address for privacy compliance (GDPR)
 */
function hashIP(string $ip): string
{
    // Use SHA256 with site-specific salt for consistent hashing
    $salt = wp_salt('auth');
    return hash('sha256', $ip . $salt);
}

/**
 * Get client IP address
 */
function getClientIP(): string
{
    $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    
    foreach ($ip_keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = $_SERVER[$key];
            // Handle multiple IPs in X-Forwarded-For
            if (str_contains($ip, ',')) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    
    return '';
}

final class TrafficLogger
{
    public static function handleRequest(): void
    {
        if (!get_option('wpai_enabled', true)) {
            return;
        }

        if (is_admin() || wp_doing_cron() || wp_doing_ajax()) {
            return;
        }

        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $referrer = $_SERVER['HTTP_REFERER'] ?? '';

        $bot_check = isAIUserAgent($user_agent);

        if (!$bot_check['detected']) {
            $bot_check = isAIReferrer($referrer);
        }

        if (!$bot_check['detected']) {
            return;
        }

        $sampling_rate = (int) get_option('wpai_sampling_rate', 100);
        if ($sampling_rate < 100 && rand(1, 100) > $sampling_rate) {
            return;
        }

        $ip_hash = null;
        if (get_option('wpai_log_ip_hash', true)) {
            $client_ip = getClientIP();
            if (!empty($client_ip)) {
                $ip_hash = hashIP($client_ip);
            }
        }

        $log_entry = [
            'timestamp' => current_time('mysql'),
            'user_agent' => substr($user_agent, 0, 1000),
            'referrer' => substr($referrer, 0, 500),
            'ip_hash' => $ip_hash,
            'url' => substr($_SERVER['REQUEST_URI'] ?? '', 0, 500),
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'bot_type' => $bot_check['bot_type'],
        ];

        QueueRepository::enqueue($log_entry);
    }
}

add_action('init', [TrafficLogger::class, 'handleRequest'], 1);

/**
 * Process log queue and batch insert to database
 */
add_action('wpai_process_log_queue', function() {
    global $wpdb;
    
    $queue = QueueRepository::fetchBatch();
    
    if (empty($queue)) {
        return;
    }
    
    $table_name = $wpdb->prefix . 'ai_traffic_logs';
    
    // Prepare multi-row insert
    $values = [];
    $placeholders = [];
    
    foreach ($queue as $entry) {
        $entry_placeholders = [];
        
        $fields = [
            'logged_at' => '%s',
            'user_agent' => '%s',
            'referrer' => '%s',
            'ip_hash' => '%s',
            'url' => '%s',
            'request_method' => '%s',
            'bot_type' => '%s',
        ];
        
        foreach ($fields as $field => $format) {
            $value = $entry->{$field};
            if ($value === null) {
                $entry_placeholders[] = 'NULL';
                continue;
            }

            $entry_placeholders[] = $format;
            $values[] = ($format === '%d') ? (int) $value : $value;
        }
        
        $placeholders[] = '(' . implode(', ', $entry_placeholders) . ')';
    }
    
        $sql = "INSERT INTO {$table_name} 
            (logged_at, user_agent, referrer, ip_hash, url, request_method, bot_type) 
            VALUES " . implode(', ', $placeholders);
    
    $prepared = $wpdb->prepare($sql, $values);
    $result = $wpdb->query($prepared);
    
    // Clear queue after successful insert
    if ($result !== false) {
        QueueRepository::deleteByIds(array_map(static function($entry) {
            return (int) $entry->id;
        }, $queue));
    }
});

/**
 * Auto-cleanup old logs based on retention setting
 */
add_action('wpai_cleanup_old_logs', function() {
    $retention_days = (int) get_option('wpai_retention_days', 90);
    
    if ($retention_days <= 0) {
        return; // Retention disabled
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'ai_traffic_logs';
    
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$table_name} WHERE logged_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
        $retention_days
    ));
});

// Schedule daily cleanup
schedulePluginHook('wpai_cleanup_old_logs');

/**
 * ========================================
 * ADMIN INTERFACE
 * ========================================
 */

/**
 * Register admin menu
 */
add_action('admin_menu', function() {
    add_menu_page(
        __('AI Traffic Logger', TEXT_DOMAIN),
        __('AI Traffic', TEXT_DOMAIN),
        'manage_options',
        'wp-ai-traffic-logger',
        [AdminPages::class, 'renderLogsPage'],
        'dashicons-analytics',
        80
    );
    
    add_submenu_page(
        'wp-ai-traffic-logger',
        __('AI Traffic Logs', TEXT_DOMAIN),
        __('Logs', TEXT_DOMAIN),
        'manage_options',
        'wp-ai-traffic-logger',
        [AdminPages::class, 'renderLogsPage']
    );
    
    add_submenu_page(
        'wp-ai-traffic-logger',
        __('AI Traffic Statistics', TEXT_DOMAIN),
        __('Statistics', TEXT_DOMAIN),
        'manage_options',
        'wp-ai-traffic-logger-stats',
        [AdminPages::class, 'renderStatsPage']
    );
    
    add_submenu_page(
        'wp-ai-traffic-logger',
        __('AI Traffic Settings', TEXT_DOMAIN),
        __('Settings', TEXT_DOMAIN),
        'manage_options',
        'wp-ai-traffic-logger-settings',
        [AdminPages::class, 'renderSettingsPage']
    );
});

/**
 * Register settings
 */
add_action('admin_init', function() {
    register_setting('wpai_settings', 'wpai_enabled', [
        'type' => 'boolean',
        'default' => true,
    ]);
    
    register_setting('wpai_settings', 'wpai_log_ip_hash', [
        'type' => 'boolean',
        'default' => true,
    ]);
    
    register_setting('wpai_settings', 'wpai_sampling_rate', [
        'type' => 'integer',
        'default' => 100,
        'sanitize_callback' => function($value) {
            $value = (int) $value;
            return max(1, min(100, $value));
        },
    ]);
    
    register_setting('wpai_settings', 'wpai_retention_days', [
        'type' => 'integer',
        'default' => 90,
        'sanitize_callback' => function($value) {
            return max(0, (int) $value);
        },
    ]);
});

final class AdminPages
{
    /**
     * Render logs page
     */
    public static function renderLogsPage(): void
    {
    global $wpdb;
    
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $table_name = $wpdb->prefix . 'ai_traffic_logs';
    
    // Handle bulk delete
    if (isset($_POST['wpai_clear_logs']) && check_admin_referer('wpai_clear_logs_action', 'wpai_clear_logs_nonce')) {
        $wpdb->query("TRUNCATE TABLE {$table_name}");
        echo '<div class="notice notice-success"><p>' . esc_html__('All logs have been cleared.', TEXT_DOMAIN) . '</p></div>';
    }
    
    // Get filters
    $filter_bot = isset($_GET['filter_bot']) ? sanitize_text_field($_GET['filter_bot']) : '';
    $filter_date_from = isset($_GET['filter_date_from']) ? sanitize_text_field($_GET['filter_date_from']) : '';
    $filter_date_to = isset($_GET['filter_date_to']) ? sanitize_text_field($_GET['filter_date_to']) : '';
    
    // Pagination
    $per_page = 50;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;
    
    // Build WHERE clause
    $where_clauses = [];
    $where_values = [];
    
    if (!empty($filter_bot)) {
        $where_clauses[] = 'bot_type = %s';
        $where_values[] = $filter_bot;
    }
    
    if (!empty($filter_date_from)) {
        $where_clauses[] = 'logged_at >= %s';
        $where_values[] = $filter_date_from . ' 00:00:00';
    }
    
    if (!empty($filter_date_to)) {
        $where_clauses[] = 'logged_at <= %s';
        $where_values[] = $filter_date_to . ' 23:59:59';
    }
    
    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    
    // Get total count
    if (!empty($where_values)) {
        $total_items = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} {$where_sql}",
            ...$where_values
        ));
    } else {
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    }
    
    // Get logs
    $query_values = array_merge($where_values, [$per_page, $offset]);
    if (!empty($where_values)) {
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} {$where_sql} ORDER BY logged_at DESC LIMIT %d OFFSET %d",
            ...$query_values
        ));
    } else {
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} ORDER BY logged_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ));
    }
    
    $total_pages = ceil($total_items / $per_page);
    
    // Get unique bot types for filter
    $bot_types = $wpdb->get_col("SELECT DISTINCT bot_type FROM {$table_name} ORDER BY bot_type");
    
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('AI Traffic Logs', TEXT_DOMAIN); ?></h1>
        
        <div class="notice notice-info">
            <p><strong><?php esc_html_e('About:', TEXT_DOMAIN); ?></strong> <?php esc_html_e('This page shows all detected traffic from AI bots and AI referrers. Logs are processed in batches every 5 minutes for optimal performance.', TEXT_DOMAIN); ?></p>
        </div>
        
        <!-- Filters -->
        <form method="get" action="">
            <input type="hidden" name="page" value="wp-ai-traffic-logger" />
            
            <div style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; margin: 20px 0;">
                <h3 style="margin-top: 0;"><?php esc_html_e('Filter Logs', TEXT_DOMAIN); ?></h3>
                
                <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: end;">
                    <div>
                        <label for="filter_bot"><strong><?php esc_html_e('Bot Type:', TEXT_DOMAIN); ?></strong></label>
                        <select name="filter_bot" id="filter_bot">
                            <option value=""><?php esc_html_e('All Bots', TEXT_DOMAIN); ?></option>
                            <?php foreach ($bot_types as $bot): ?>
                                <option value="<?php echo esc_attr($bot); ?>" <?php selected($filter_bot, $bot); ?>>
                                    <?php echo esc_html($bot); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="filter_date_from"><strong><?php esc_html_e('From:', TEXT_DOMAIN); ?></strong></label>
                        <input type="date" name="filter_date_from" id="filter_date_from" value="<?php echo esc_attr($filter_date_from); ?>" />
                    </div>
                    
                    <div>
                        <label for="filter_date_to"><strong><?php esc_html_e('To:', TEXT_DOMAIN); ?></strong></label>
                        <input type="date" name="filter_date_to" id="filter_date_to" value="<?php echo esc_attr($filter_date_to); ?>" />
                    </div>
                    
                    <div>
                        <button type="submit" class="button"><?php esc_html_e('Apply Filters', TEXT_DOMAIN); ?></button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wp-ai-traffic-logger')); ?>" class="button"><?php esc_html_e('Reset', TEXT_DOMAIN); ?></a>
                    </div>
                </div>
            </div>
        </form>
        
        <!-- Quick Stats -->
        <div style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; margin: 20px 0;">
            <h3 style="margin-top: 0;"><?php esc_html_e('Quick Stats', TEXT_DOMAIN); ?></h3>
            <p><strong><?php esc_html_e('Total Logs:', TEXT_DOMAIN); ?></strong> <?php echo number_format($total_items); ?></p>
        </div>
        
        <!-- Logs Table -->
        <?php if (empty($logs)): ?>
            <div class="notice notice-warning">
                <p><?php esc_html_e('No logs found. AI traffic will appear here when detected.', TEXT_DOMAIN); ?></p>
            </div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 140px;"><?php esc_html_e('Date & Time', TEXT_DOMAIN); ?></th>
                        <th style="width: 130px;"><?php esc_html_e('Bot Type', TEXT_DOMAIN); ?></th>
                        <th><?php esc_html_e('User Agent', TEXT_DOMAIN); ?></th>
                        <th style="width: 150px;"><?php esc_html_e('Referrer', TEXT_DOMAIN); ?></th>
                        <th style="width: 250px;"><?php esc_html_e('URL', TEXT_DOMAIN); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($log->logged_at))); ?></td>
                            <td>
                                <span style="display: inline-block; padding: 3px 8px; background: #3f83f8; color: #fff; border-radius: 3px; font-size: 11px; font-weight: 600;">
                                    <?php echo esc_html($log->bot_type); ?>
                                </span>
                            </td>
                            <td style="word-wrap: break-word; word-break: break-all; max-width: 400px;">
                                <?php echo esc_html($log->user_agent); ?>
                            </td>
                            <td>
                                <?php if (!empty($log->referrer)): ?>
                                    <span title="<?php echo esc_attr($log->referrer); ?>">
                                        <?php 
                                        $ref = esc_html($log->referrer);
                                        echo strlen($ref) > 25 ? substr($ref, 0, 25) . '...' : $ref;
                                        ?>
                                    </span>
                                <?php else: ?>
                                    <em>—</em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(home_url($log->url)); ?>" target="_blank" title="<?php echo esc_attr($log->url); ?>">
                                    <?php 
                                    $url = esc_html($log->url);
                                    echo strlen($url) > 50 ? substr($url, 0, 50) . '...' : $url;
                                    ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo number_format($total_items); ?> <?php esc_html_e('items', TEXT_DOMAIN); ?></span>
                        <?php
                        $base_url = admin_url('admin.php?page=wp-ai-traffic-logger');
                        if ($filter_bot) $base_url = add_query_arg('filter_bot', $filter_bot, $base_url);
                        if ($filter_date_from) $base_url = add_query_arg('filter_date_from', $filter_date_from, $base_url);
                        if ($filter_date_to) $base_url = add_query_arg('filter_date_to', $filter_date_to, $base_url);
                        
                        echo paginate_links([
                            'base' => add_query_arg('paged', '%#%', $base_url),
                            'format' => '',
                            'current' => $current_page,
                            'total' => $total_pages,
                        ]);
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
    }

    /**
     * Render statistics page
     */
    public static function renderStatsPage(): void
    {
    global $wpdb;
    
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $table_name = $wpdb->prefix . 'ai_traffic_logs';
    
    // Get date range filter
    $days = isset($_GET['days']) ? max(1, (int) $_GET['days']) : 30;
    
    // Total visits
    $total_visits = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_name} WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
        $days
    ));
    
    // Top bots
    $top_bots = $wpdb->get_results($wpdb->prepare(
        "SELECT bot_type, COUNT(*) as count 
         FROM {$table_name} 
         WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
         GROUP BY bot_type 
         ORDER BY count DESC 
         LIMIT 10",
        $days
    ));
    
    // Daily trend
    $daily_trend = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(logged_at) as date, COUNT(*) as count 
         FROM {$table_name} 
         WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
         GROUP BY DATE(logged_at) 
         ORDER BY date DESC",
        $days
    ));
    
    // Referrer breakdown
    $referrer_stats = $wpdb->get_results($wpdb->prepare(
        "SELECT 
            CASE 
                WHEN referrer IS NULL OR referrer = '' THEN 'Direct'
                ELSE 'AI Referral'
            END as referrer_type,
            COUNT(*) as count
         FROM {$table_name}
         WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
         GROUP BY referrer_type",
        $days
    ));
    
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('AI Traffic Statistics', TEXT_DOMAIN); ?></h1>
        
        <!-- Period Filter -->
        <form method="get" style="margin: 20px 0;">
            <input type="hidden" name="page" value="wp-ai-traffic-logger-stats" />
            <label for="days"><strong><?php esc_html_e('Time Period:', TEXT_DOMAIN); ?></strong></label>
            <select name="days" id="days" onchange="this.form.submit()">
                <option value="7" <?php selected($days, 7); ?>><?php esc_html_e('Last 7 days', TEXT_DOMAIN); ?></option>
                <option value="30" <?php selected($days, 30); ?>><?php esc_html_e('Last 30 days', TEXT_DOMAIN); ?></option>
                <option value="90" <?php selected($days, 90); ?>><?php esc_html_e('Last 90 days', TEXT_DOMAIN); ?></option>
                <option value="365" <?php selected($days, 365); ?>><?php esc_html_e('Last year', TEXT_DOMAIN); ?></option>
            </select>
        </form>
        
        <!-- Overview Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin: 20px 0;">
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-left: 4px solid #3f83f8;">
                <h3 style="margin: 0 0 10px 0; color: #666;"><?php esc_html_e('Total AI Visits', TEXT_DOMAIN); ?></h3>
                <p style="font-size: 32px; font-weight: bold; margin: 0; color: #3f83f8;"><?php echo number_format($total_visits); ?></p>
            </div>
            
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-left: 4px solid #0e9f6e;">
                <h3 style="margin: 0 0 10px 0; color: #666;"><?php esc_html_e('Unique Bots', TEXT_DOMAIN); ?></h3>
                    <p style="font-size: 32px; font-weight: bold; margin: 0; color: #0e9f6e;"><?php echo count($top_bots); ?></p>
            </div>
            
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-left: 4px solid #f59e0b;">
                <h3 style="margin: 0 0 10px 0; color: #666;"><?php esc_html_e('Avg Daily Visits', TEXT_DOMAIN); ?></h3>
                <p style="font-size: 32px; font-weight: bold; margin: 0; color: #f59e0b;">
                    <?php echo number_format($days > 0 ? $total_visits / $days : 0, 1); ?>
                </p>
            </div>
        </div>
        
        <!-- Charts -->
        <div style="display: grid; grid-template-columns: minmax(0, 1fr); gap: 20px; margin: 20px 0;">
            <!-- Top Bots -->
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4;">
                <h2 style="margin-top: 0;"><?php esc_html_e('Top AI Bots', TEXT_DOMAIN); ?></h2>
                <?php if (!empty($top_bots)): ?>
                    <table class="wp-list-table widefat">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Bot Type', TEXT_DOMAIN); ?></th>
                                <th style="text-align: right;"><?php esc_html_e('Visits', TEXT_DOMAIN); ?></th>
                                <th style="text-align: right;">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_bots as $bot): ?>
                                <tr>
                                    <td><?php echo esc_html($bot->bot_type); ?></td>
                                    <td style="text-align: right;"><?php echo number_format($bot->count); ?></td>
                                    <td style="text-align: right;">
                                        <?php
                                        $percentage = $total_visits > 0 ? ($bot->count / $total_visits) * 100 : 0;
                                        echo number_format($percentage, 1);
                                        ?>%
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><?php esc_html_e('No data available.', TEXT_DOMAIN); ?></p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Daily Trend -->
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin: 20px 0;">
            <h2 style="margin-top: 0;"><?php esc_html_e('Daily Traffic Trend', TEXT_DOMAIN); ?></h2>
            <?php if (!empty($daily_trend)): ?>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date', TEXT_DOMAIN); ?></th>
                            <th style="text-align: right;"><?php esc_html_e('Visits', TEXT_DOMAIN); ?></th>
                            <th style="width: 60%;"><?php esc_html_e('Visualization', TEXT_DOMAIN); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $max_count = max(array_column($daily_trend, 'count'));
                        foreach ($daily_trend as $day): 
                            $percentage = ($day->count / $max_count) * 100;
                        ?>
                            <tr>
                                <td><?php echo esc_html($day->date); ?></td>
                                <td style="text-align: right;"><?php echo number_format($day->count); ?></td>
                                <td>
                                    <div style="background: #e5e7eb; height: 20px; border-radius: 3px; overflow: hidden;">
                                        <div style="background: #3f83f8; height: 100%; width: <?php echo $percentage; ?>%;"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p><?php esc_html_e('No data available.', TEXT_DOMAIN); ?></p>
            <?php endif; ?>
        </div>
        
        <!-- Referrer Stats -->
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin: 20px 0;">
            <h2 style="margin-top: 0;"><?php esc_html_e('Traffic Source', TEXT_DOMAIN); ?></h2>
            <?php if (!empty($referrer_stats)): ?>
                <table class="wp-list-table widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Source Type', TEXT_DOMAIN); ?></th>
                            <th style="text-align: right;"><?php esc_html_e('Visits', TEXT_DOMAIN); ?></th>
                            <th style="text-align: right;">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($referrer_stats as $stat): ?>
                            <tr>
                                <td><?php echo esc_html($stat->referrer_type); ?></td>
                                <td style="text-align: right;"><?php echo number_format($stat->count); ?></td>
                                <td style="text-align: right;">
                                    <?php
                                    $refPercent = $total_visits > 0 ? ($stat->count / $total_visits) * 100 : 0;
                                    echo number_format($refPercent, 1);
                                    ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p><?php esc_html_e('No data available.', TEXT_DOMAIN); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
    }

    /**
     * Render settings page
     */
    public static function renderSettingsPage(): void
    {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    if (isset($_GET['settings-updated'])) {
        add_settings_error('wpai_messages', 'wpai_message', esc_html__('Settings saved.', TEXT_DOMAIN), 'success');
    }
    
    settings_errors('wpai_messages');
    
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('AI Traffic Logger Settings', TEXT_DOMAIN); ?></h1>
        
        <form action="options.php" method="post">
            <?php settings_fields('wpai_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Logging', TEXT_DOMAIN); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wpai_enabled" value="1" <?php checked(get_option('wpai_enabled', true), true); ?> />
                            <?php esc_html_e('Enable AI traffic logging', TEXT_DOMAIN); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Master switch to enable or disable the plugin.', TEXT_DOMAIN); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php esc_html_e('Log IP Hash', TEXT_DOMAIN); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wpai_log_ip_hash" value="1" <?php checked(get_option('wpai_log_ip_hash', true), true); ?> />
                            <?php esc_html_e('Store hashed IP addresses', TEXT_DOMAIN); ?>
                        </label>
                        <p class="description"><?php esc_html_e('IPs are hashed with SHA256 for privacy compliance (GDPR-friendly). Disable to avoid storing IPs.', TEXT_DOMAIN); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php esc_html_e('Sampling Rate', TEXT_DOMAIN); ?></th>
                    <td>
                        <input type="number" name="wpai_sampling_rate" value="<?php echo esc_attr(get_option('wpai_sampling_rate', 100)); ?>" 
                               min="1" max="100" class="small-text" /> %
                        <p class="description"><?php esc_html_e('Log only a percentage of AI visits (1-100%). Use lower values for very high traffic sites.', TEXT_DOMAIN); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php esc_html_e('Log Retention', TEXT_DOMAIN); ?></th>
                    <td>
                        <input type="number" name="wpai_retention_days" value="<?php echo esc_attr(get_option('wpai_retention_days', 90)); ?>" 
                               min="0" class="small-text" /> <?php esc_html_e('days', TEXT_DOMAIN); ?>
                        <p class="description"><?php esc_html_e('Automatically delete logs older than this many days. Set to 0 to keep logs forever.', TEXT_DOMAIN); ?></p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(__('Save Settings', TEXT_DOMAIN)); ?>
        </form>
        
        <hr>
        
        <h2><?php esc_html_e('System Information', TEXT_DOMAIN); ?></h2>
        <table class="widefat">
            <tr>
                <td style="width: 200px;"><strong><?php esc_html_e('Database Table:', TEXT_DOMAIN); ?></strong></td>
                <td><?php global $wpdb; echo $wpdb->prefix . 'ai_traffic_logs'; ?></td>
            </tr>
            <tr>
                <td><strong><?php esc_html_e('Batch Processing:', TEXT_DOMAIN); ?></strong></td>
                <td><?php esc_html_e('Every 5 minutes via WP-Cron', TEXT_DOMAIN); ?></td>
            </tr>
            <tr>
                <td><strong><?php esc_html_e('Queue Status:', TEXT_DOMAIN); ?></strong></td>
                <td>
                    <?php 
                    $count = QueueRepository::count();
                    echo $count > 0
                        ? sprintf(esc_html__('%d entries pending', TEXT_DOMAIN), intval($count))
                        : esc_html__('Empty', TEXT_DOMAIN);
                    ?>
                </td>
            </tr>
            <tr>
                <td><strong><?php esc_html_e('Next Cleanup:', TEXT_DOMAIN); ?></strong></td>
                <td>
                    <?php
                    $next_cleanup = wp_next_scheduled('wpai_cleanup_old_logs');
                    echo $next_cleanup ? esc_html(date('Y-m-d H:i:s', $next_cleanup)) : esc_html__('Not scheduled', TEXT_DOMAIN);
                    ?>
                </td>
            </tr>
        </table>
        
        <h2><?php esc_html_e('Detected AI Bots', TEXT_DOMAIN); ?></h2>
        <p><?php esc_html_e('The plugin currently detects the following AI bots and referrers:', TEXT_DOMAIN); ?></p>
        <ul style="columns: 3; list-style: disc; padding-left: 20px;">
            <li>GPTBot (OpenAI)</li>
            <li>ChatGPT-User</li>
            <li>OAI-SearchBot</li>
            <li>Claude / Anthropic</li>
            <li>Google Gemini</li>
            <li>Perplexity</li>
            <li>Grok (xAI)</li>
            <li>Meta AI</li>
            <li>You.com</li>
            <li>DeepSeek</li>
            <li>Cohere</li>
            <li>Apple Intelligence</li>
            <li>ByteDance / TikTok</li>
            <li>Amazon Alexa</li>
            <li>Bing Chat / Copilot</li>
            <li>Poe</li>
            <li>Phind</li>
        </ul>
    </div>
    <?php
    }
}

/**
 * ========================================
 * PLUGIN ACTIVATION / DEACTIVATION
 * ========================================
 */

/**
 * Plugin activation
 */
function activatePlugin(): void
{
    global $wpdb;

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $charset_collate = $wpdb->get_charset_collate();

    // Create main logs table
    $logs_table = $wpdb->prefix . 'ai_traffic_logs';
    $logs_sql = "CREATE TABLE {$logs_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        logged_at datetime NOT NULL,
        user_agent text NOT NULL,
        referrer varchar(500) DEFAULT NULL,
        ip_hash varchar(64) DEFAULT NULL,
        url varchar(500) NOT NULL,
        request_method varchar(10) DEFAULT 'GET',
        bot_type varchar(100) NOT NULL,
        PRIMARY KEY  (id),
        KEY logged_at (logged_at),
        KEY bot_type (bot_type),
        KEY ip_hash (ip_hash)
    ) {$charset_collate};";

    dbDelta($logs_sql);

    // Create queue table
    $queue_table = $wpdb->prefix . 'ai_traffic_log_queue';
    $queue_sql = "CREATE TABLE {$queue_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        logged_at datetime NOT NULL,
        user_agent text NOT NULL,
        referrer varchar(500) DEFAULT NULL,
        ip_hash varchar(64) DEFAULT NULL,
        url varchar(500) NOT NULL,
        request_method varchar(10) DEFAULT 'GET',
        bot_type varchar(100) NOT NULL,
        PRIMARY KEY  (id),
        KEY logged_at (logged_at)
    ) {$charset_collate};";

    dbDelta($queue_sql);

    // Set default options
    add_option('wpai_enabled', true);
    add_option('wpai_log_ip_hash', true);
    add_option('wpai_sampling_rate', 100);
    add_option('wpai_retention_days', 90);

    // Register custom cron schedule
    add_filter('cron_schedules', __NAMESPACE__ . '\\addCustomCronSchedule');

    // Schedule cron events
    foreach (SCHEDULED_HOOKS as $hook => $schedule) {
        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(time(), $schedule, $hook);
        }
    }
}

register_activation_hook(__FILE__, __NAMESPACE__ . '\\activatePlugin');

/**
 * Plugin deactivation
 */
function deactivatePlugin(): void
{
    // Clear scheduled hooks
    foreach (SCHEDULED_HOOKS as $hook => $schedule) {
        $timestamp = wp_next_scheduled($hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
        }
    }
}

register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\deactivatePlugin');

/**
 * Add custom cron schedule (5 minutes)
 */
function addCustomCronSchedule(array $schedules): array
{
    $schedules['wpai_five_minutes'] = [
        'interval' => 300,
        'display' => __('Every 5 Minutes', TEXT_DOMAIN)
    ];
    return $schedules;
}

add_filter('cron_schedules', __NAMESPACE__ . '\\addCustomCronSchedule');

/**
 * Helper function to schedule plugin hooks
 */
function schedulePluginHook(string $hook): void
{
    if (!wp_next_scheduled($hook) && isset(SCHEDULED_HOOKS[$hook])) {
        wp_schedule_event(time(), SCHEDULED_HOOKS[$hook], $hook);
    }
}
