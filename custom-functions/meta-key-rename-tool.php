<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generic admin utility to rename meta keys in the database and update theme files.
 *
 * Live mode always requires:
 * - administrator capability
 * - valid nonce
 * - explicit confirmation phrase
 * - explicit opt-in for each destructive area
 */
final class Meta_Key_Rename_Tool
{
    private const PAGE_SLUG = 'meta-key-rename-tool';
    private const NONCE_ACTION = 'meta_key_rename_tool';
    private const CONFIRM_PHRASE = 'RENAME META KEY';
    private const DEFAULT_BATCH_SIZE = 100;
    private const MAX_BATCH_SIZE = 500;
    private const DEFAULT_FILE_LIMIT = 200;

    public static function init()
    {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [__CLASS__, 'register_page']);
    }

    public static function register_page()
    {
        add_management_page(
            __('Rename Meta Key', 'default'),
            __('Rename Meta Key', 'default'),
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this tool.', 'default'));
        }

        $form = self::default_form();
        $result = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer(self::NONCE_ACTION);
            $form = self::sanitize_request($_POST);
            $result = self::process_request($form);
        }

        self::render_template($form, $result);
    }

    private static function default_form()
    {
        return [
            'old_key'                     => '',
            'new_key'                     => '',
            'post_type'                   => '',
            'batch_size'                  => self::DEFAULT_BATCH_SIZE,
            'file_limit'                  => self::DEFAULT_FILE_LIMIT,
            'dry_run'                     => 1,
            'rename_database_meta'        => 1,
            'rename_theme_files'          => 1,
            'delete_old_key'              => 0,
            'skip_if_target_has_value'    => 1,
            'create_file_backups'         => 1,
            'confirmation'                => '',
        ];
    }

    private static function sanitize_request($input)
    {
        $batch_size = isset($input['batch_size']) ? absint($input['batch_size']) : self::DEFAULT_BATCH_SIZE;
        if ($batch_size < 1) {
            $batch_size = self::DEFAULT_BATCH_SIZE;
        }
        if ($batch_size > self::MAX_BATCH_SIZE) {
            $batch_size = self::MAX_BATCH_SIZE;
        }

        $file_limit = isset($input['file_limit']) ? absint($input['file_limit']) : self::DEFAULT_FILE_LIMIT;
        if ($file_limit < 1) {
            $file_limit = self::DEFAULT_FILE_LIMIT;
        }

        return [
            'old_key'                  => isset($input['old_key']) ? sanitize_key(wp_unslash($input['old_key'])) : '',
            'new_key'                  => isset($input['new_key']) ? sanitize_key(wp_unslash($input['new_key'])) : '',
            'post_type'                => isset($input['post_type']) ? sanitize_key(wp_unslash($input['post_type'])) : '',
            'batch_size'               => $batch_size,
            'file_limit'               => $file_limit,
            'dry_run'                  => !empty($input['dry_run']) ? 1 : 0,
            'rename_database_meta'     => !empty($input['rename_database_meta']) ? 1 : 0,
            'rename_theme_files'       => !empty($input['rename_theme_files']) ? 1 : 0,
            'delete_old_key'           => !empty($input['delete_old_key']) ? 1 : 0,
            'skip_if_target_has_value' => !empty($input['skip_if_target_has_value']) ? 1 : 0,
            'create_file_backups'      => !empty($input['create_file_backups']) ? 1 : 0,
            'confirmation'             => isset($input['confirmation']) ? sanitize_text_field(wp_unslash($input['confirmation'])) : '',
        ];
    }

    private static function process_request(array $form)
    {
        $errors = self::validate_request($form);
        if ($errors) {
            return [
                'ok'     => false,
                'errors' => $errors,
            ];
        }

        $result = [
            'ok'     => true,
            'db'     => null,
            'files'  => null,
        ];

        if ($form['rename_database_meta']) {
            $result['db'] = self::rename_database_meta($form);
        }

        if ($form['rename_theme_files']) {
            $result['files'] = self::rename_theme_file_references($form);
        }

        return $result;
    }

    private static function validate_request(array $form)
    {
        $errors = [];

        if ($form['old_key'] === '' || $form['new_key'] === '') {
            $errors[] = __('Old key and new key are required.', 'default');
        }

        if ($form['old_key'] === $form['new_key']) {
            $errors[] = __('Old key and new key must be different.', 'default');
        }

        if (!$form['rename_database_meta'] && !$form['rename_theme_files']) {
            $errors[] = __('Select at least one target: database meta or theme files.', 'default');
        }

        if ($form['post_type'] !== '' && !post_type_exists($form['post_type'])) {
            $errors[] = __('Post type does not exist.', 'default');
        }

        if (!$form['dry_run'] && $form['confirmation'] !== self::CONFIRM_PHRASE) {
            $errors[] = __('Confirmation phrase is incorrect.', 'default');
        }

        if (!$form['dry_run'] && $form['rename_database_meta'] && !$form['delete_old_key']) {
            $errors[] = __('Live database rename requires "Delete old key after copy" to avoid split data.', 'default');
        }

        return $errors;
    }

    private static function rename_database_meta(array $form)
    {
        global $wpdb;

        $params = [$form['old_key']];
        $join_post_type = '';

        if ($form['post_type'] !== '') {
            $join_post_type = " INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = %s ";
            $params[] = $form['post_type'];
        }

        $sql = "
            SELECT pm.meta_id, pm.post_id, pm.meta_value
            FROM {$wpdb->postmeta} pm
            {$join_post_type}
            WHERE pm.meta_key = %s
            ORDER BY pm.meta_id ASC
            LIMIT %d
        ";
        $params[] = $form['batch_size'];
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        $stats = [
            'scanned'  => 0,
            'eligible' => 0,
            'copied'   => 0,
            'deleted'  => 0,
            'skipped'  => 0,
            'errors'   => [],
            'samples'  => [],
            'dry_run'  => (bool) $form['dry_run'],
        ];

        foreach ($rows as $row) {
            $stats['scanned']++;
            $post_id = (int) $row['post_id'];

            $target_exists = metadata_exists('post', $post_id, $form['new_key']);
            if ($target_exists && $form['skip_if_target_has_value']) {
                $stats['skipped']++;
                $stats['samples'][] = sprintf('#%d: skipped because target key already exists', $post_id);
                continue;
            }

            $stats['eligible']++;
            $stats['samples'][] = sprintf('#%d: %s -> %s', $post_id, $form['old_key'], $form['new_key']);

            if ($form['dry_run']) {
                continue;
            }

            $copied = update_post_meta($post_id, $form['new_key'], maybe_unserialize($row['meta_value']));
            if ($copied === false && !metadata_exists('post', $post_id, $form['new_key'])) {
                $stats['errors'][] = sprintf(__('Failed to write new meta key for post #%d.', 'default'), $post_id);
                continue;
            }

            $stats['copied']++;

            if ($form['delete_old_key']) {
                $deleted = delete_post_meta($post_id, $form['old_key']);
                if ($deleted) {
                    $stats['deleted']++;
                } else {
                    $stats['errors'][] = sprintf(__('Failed to delete old meta key for post #%d.', 'default'), $post_id);
                }
            }
        }

        $remaining_params = [$form['old_key']];
        $remaining_join = '';

        if ($form['post_type'] !== '') {
            $remaining_join = " INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = %s ";
            $remaining_params[] = $form['post_type'];
        }

        $remaining_sql = "
            SELECT COUNT(*)
            FROM {$wpdb->postmeta} pm
            {$remaining_join}
            WHERE pm.meta_key = %s
        ";

        return [
            'stats'     => $stats,
            'remaining' => (int) $wpdb->get_var($wpdb->prepare($remaining_sql, $remaining_params)),
        ];
    }

    private static function rename_theme_file_references(array $form)
    {
        $stats = [
            'scanned'         => 0,
            'matched_files'   => 0,
            'updated_files'   => 0,
            'backups_created' => 0,
            'errors'          => [],
            'samples'         => [],
            'sample_groups'   => [],
            'dry_run'         => (bool) $form['dry_run'],
        ];

        $files = self::get_theme_files($form['file_limit']);

        foreach ($files as $file_path) {
            $stats['scanned']++;
            $contents = file_get_contents($file_path);

            if ($contents === false || strpos($contents, $form['old_key']) === false) {
                continue;
            }

            $relative_path = ltrim(str_replace(get_stylesheet_directory(), '', $file_path), '/');
            $replacement_count = 0;
            $group_counts = [];
            $updated_contents = self::replace_meta_key_references_in_code($contents, $form['old_key'], $form['new_key'], $replacement_count, $group_counts);

            if ($replacement_count < 1) {
                continue;
            }

            $stats['matched_files']++;
            $stats['samples'][] = sprintf('%s (%d matches)', $relative_path, $replacement_count);
            foreach ($group_counts as $group_name => $count) {
                if (!isset($stats['sample_groups'][$group_name])) {
                    $stats['sample_groups'][$group_name] = [];
                }
                $stats['sample_groups'][$group_name][] = sprintf('%s (%d matches)', $relative_path, $count);
            }

            if ($form['dry_run']) {
                continue;
            }

            if ($form['create_file_backups']) {
                $backup_path = $file_path . '.bak-' . gmdate('Ymd-His');
                if (@copy($file_path, $backup_path)) {
                    $stats['backups_created']++;
                } else {
                    $stats['errors'][] = sprintf(__('Failed to create backup for %s.', 'default'), $relative_path);
                    continue;
                }
            }

            if (@file_put_contents($file_path, $updated_contents) === false) {
                $stats['errors'][] = sprintf(__('Failed to update file %s.', 'default'), $relative_path);
                continue;
            }

            $stats['updated_files']++;
        }

        return [
            'stats' => $stats,
        ];
    }

    private static function get_theme_files($limit)
    {
        $directory = new RecursiveDirectoryIterator(get_stylesheet_directory(), FilesystemIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directory);
        $allowed_extensions = ['php'];
        $files = [];

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, $allowed_extensions, true)) {
                continue;
            }

            $filename = $file->getFilename();
            if (strpos($filename, '.bak-') !== false) {
                continue;
            }

            $files[] = $file->getPathname();
            if (count($files) >= $limit) {
                break;
            }
        }

        return $files;
    }

    private static function replace_meta_key_references_in_code($contents, $old_key, $new_key, &$replacement_count, array &$group_counts)
    {
        $replacement_count = 0;
        $group_counts = [];

        $quoted_old = preg_quote($old_key, '/');
        $patterns = [
            'db_meta_function' => [
                "/\\b(get_post_meta|update_post_meta|add_post_meta|delete_post_meta)\\s*\\(\\s*([^,]+,\\s*)['\"]{$quoted_old}(['\"])/",
                "/\\bmetadata_exists\\s*\\(\\s*['\"]post['\"]\\s*,\\s*[^,]+,\\s*['\"]{$quoted_old}(['\"])/",
            ],
            'rwmb_helper' => [
                "/\\b(rwmb_meta|rwmb_get_field_settings)\\s*\\(\\s*['\"]{$quoted_old}(['\"])/",
            ],
            'meta_query' => [
                "/(['\"](?:key|meta_key)['\"]\\s*=>\\s*['\"]){$quoted_old}(['\"])/",
            ],
            'metabox_field_id' => [
                "/(['\"]id['\"]\\s*=>\\s*['\"]){$quoted_old}(['\"])/",
                "/(['\"]id['\"]\\s*=>\\s*\\$[A-Za-z_][A-Za-z0-9_]*\\s*\\.\\s*['\"]){$quoted_old}(['\"])/",
            ],
            'admin_columns' => [
                "/(['\"])column_meta_{$quoted_old}(['\"])/",
            ],
        ];

        foreach ($patterns as $group_name => $group_patterns) {
            foreach ($group_patterns as $pattern) {
                $contents = preg_replace_callback(
                    $pattern,
                    static function ($matches) use ($old_key, $new_key, &$replacement_count, &$group_counts, $group_name) {
                        $replacement_count++;
                        if (!isset($group_counts[$group_name])) {
                            $group_counts[$group_name] = 0;
                        }
                        $group_counts[$group_name]++;
                        return str_replace($old_key, $new_key, $matches[0]);
                    },
                    $contents
                );
            }
        }

        $contents = self::replace_safe_array_literals($contents, $old_key, $new_key, $replacement_count, $group_counts);

        return $contents;
    }

    private static function replace_safe_array_literals($contents, $old_key, $new_key, &$replacement_count, array &$group_counts)
    {
        return preg_replace_callback(
            '/(\\$[A-Za-z_][A-Za-z0-9_]*\\s*=\\s*\\[)(.*?)(\\];)/s',
            static function ($matches) use ($old_key, $new_key, &$replacement_count, &$group_counts) {
                $assignment = $matches[1];
                $body = $matches[2];
                $closing = $matches[3];

                if (!preg_match('/\\$(?:[A-Za-z0-9_]*(?:key|keys|field|fields|meta|map|maps|filter|filters|column|columns)[A-Za-z0-9_]*)\\s*=\\s*\\[$/i', $assignment)) {
                    return $matches[0];
                }

                $body_replacements = 0;
                $updated_body = str_replace(
                    ["'{$old_key}'", "\"{$old_key}\"", "'column_meta_{$old_key}'", "\"column_meta_{$old_key}\""],
                    ["'{$new_key}'", "\"{$new_key}\"", "'column_meta_{$new_key}'", "\"column_meta_{$new_key}\""],
                    $body,
                    $body_replacements
                );

                if ($body_replacements > 0) {
                    $replacement_count += $body_replacements;
                    if (!isset($group_counts['array_literal'])) {
                        $group_counts['array_literal'] = 0;
                    }
                    $group_counts['array_literal'] += $body_replacements;
                    return $assignment . $updated_body . $closing;
                }

                return $matches[0];
            },
            $contents
        );
    }

    private static function render_preview_group($title, array $items)
    {
        if (empty($items)) {
            return;
        }
        ?>
        <h3><?php echo esc_html($title); ?></h3>
        <pre style="max-height:220px; overflow:auto; background:#fff; padding:12px; border:1px solid #ccd0d4;"><?php echo esc_html(implode("\n", array_slice($items, 0, 50))); ?></pre>
        <?php
    }

    private static function render_template(array $form, $result)
    {
        $post_types = get_post_types(['show_ui' => true], 'objects');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Rename Existing Meta Key', 'default'); ?></h1>
            <p><?php echo esc_html__('This tool can rename a stored post meta key and update theme code references in the current child theme. Theme replacement is pattern-based and limited to common WordPress and Meta Box usages.', 'default'); ?></p>

            <?php if (is_array($result) && !$result['ok']) : ?>
                <div class="notice notice-error"><p><?php echo esc_html(implode(' ', $result['errors'])); ?></p></div>
            <?php endif; ?>

            <?php if (is_array($result) && $result['ok']) : ?>
                <?php if (!empty($result['db'])) : ?>
                    <div class="notice notice-success">
                        <p><?php echo esc_html(sprintf('Database batch. Scanned: %d. Eligible: %d. Copied: %d. Deleted: %d. Skipped: %d. Remaining old-key rows: %d.', $result['db']['stats']['scanned'], $result['db']['stats']['eligible'], $result['db']['stats']['copied'], $result['db']['stats']['deleted'], $result['db']['stats']['skipped'], $result['db']['remaining'])); ?></p>
                        <p><?php echo esc_html($result['db']['stats']['dry_run'] ? 'Database dry-run only. No database rows were changed.' : 'Database live run completed for this batch.'); ?></p>
                        <?php if (!empty($result['db']['stats']['errors'])) : ?>
                            <p><?php echo esc_html(implode(' ', $result['db']['stats']['errors'])); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($result['files'])) : ?>
                    <div class="notice notice-success">
                        <p><?php echo esc_html(sprintf('Theme files. Scanned: %d. Matched files: %d. Updated files: %d. Backups created: %d.', $result['files']['stats']['scanned'], $result['files']['stats']['matched_files'], $result['files']['stats']['updated_files'], $result['files']['stats']['backups_created'])); ?></p>
                        <p><?php echo esc_html($result['files']['stats']['dry_run'] ? 'Theme file dry-run only. No files were changed.' : 'Theme file replacements completed.'); ?></p>
                        <?php if (!empty($result['files']['stats']['errors'])) : ?>
                            <p><?php echo esc_html(implode(' ', $result['files']['stats']['errors'])); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="old_key"><?php echo esc_html__('Old meta key', 'default'); ?></label></th>
                            <td><input name="old_key" id="old_key" type="text" class="regular-text" value="<?php echo esc_attr($form['old_key']); ?>" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="new_key"><?php echo esc_html__('New meta key', 'default'); ?></label></th>
                            <td><input name="new_key" id="new_key" type="text" class="regular-text" value="<?php echo esc_attr($form['new_key']); ?>" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="post_type"><?php echo esc_html__('Limit database rename to post type', 'default'); ?></label></th>
                            <td>
                                <select name="post_type" id="post_type">
                                    <option value=""><?php echo esc_html__('All post types', 'default'); ?></option>
                                    <?php foreach ($post_types as $post_type) : ?>
                                        <option value="<?php echo esc_attr($post_type->name); ?>" <?php selected($form['post_type'], $post_type->name); ?>>
                                            <?php echo esc_html(sprintf('%s (%s)', $post_type->labels->singular_name, $post_type->name)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="batch_size"><?php echo esc_html__('Database batch size', 'default'); ?></label></th>
                            <td><input name="batch_size" id="batch_size" type="number" min="1" max="<?php echo esc_attr(self::MAX_BATCH_SIZE); ?>" value="<?php echo esc_attr($form['batch_size']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="file_limit"><?php echo esc_html__('Theme file scan limit', 'default'); ?></label></th>
                            <td>
                                <input name="file_limit" id="file_limit" type="number" min="1" value="<?php echo esc_attr($form['file_limit']); ?>">
                                <p class="description"><?php echo esc_html__('Maximum number of theme files to scan in one run.', 'default'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Targets', 'default'); ?></th>
                            <td>
                                <label><input name="rename_database_meta" type="checkbox" value="1" <?php checked($form['rename_database_meta'], 1); ?>> <?php echo esc_html__('Rename post meta rows in database', 'default'); ?></label><br>
                                <label><input name="rename_theme_files" type="checkbox" value="1" <?php checked($form['rename_theme_files'], 1); ?>> <?php echo esc_html__('Update references inside current child theme files', 'default'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Safety options', 'default'); ?></th>
                            <td>
                                <label><input name="dry_run" type="checkbox" value="1" <?php checked($form['dry_run'], 1); ?>> <?php echo esc_html__('Dry-run only', 'default'); ?></label><br>
                                <label><input name="skip_if_target_has_value" type="checkbox" value="1" <?php checked($form['skip_if_target_has_value'], 1); ?>> <?php echo esc_html__('Skip database rows where target key already exists', 'default'); ?></label><br>
                                <label><input name="delete_old_key" type="checkbox" value="1" <?php checked($form['delete_old_key'], 1); ?>> <?php echo esc_html__('Delete old database key after copy', 'default'); ?></label><br>
                                <label><input name="create_file_backups" type="checkbox" value="1" <?php checked($form['create_file_backups'], 1); ?>> <?php echo esc_html__('Create timestamped .bak copy before editing each file', 'default'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="confirmation"><?php echo esc_html__('Confirmation phrase', 'default'); ?></label></th>
                            <td>
                                <input name="confirmation" id="confirmation" type="text" class="regular-text" value="<?php echo esc_attr($form['confirmation']); ?>">
                                <p class="description"><?php echo esc_html(sprintf('Required for live runs: type "%s".', self::CONFIRM_PHRASE)); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(__('Run rename batch', 'default')); ?>
            </form>

            <?php if (is_array($result) && $result['ok']) : ?>
                <?php if (!empty($result['db']['stats']['samples'])) : ?>
                    <h2><?php echo esc_html__('Database preview', 'default'); ?></h2>
                    <pre style="max-height:280px; overflow:auto; background:#fff; padding:12px; border:1px solid #ccd0d4;"><?php echo esc_html(implode("\n", array_slice($result['db']['stats']['samples'], 0, 50))); ?></pre>
                <?php endif; ?>
                <?php if (!empty($result['files']['stats']['samples'])) : ?>
                    <h2><?php echo esc_html__('Theme file preview', 'default'); ?></h2>
                    <pre style="max-height:280px; overflow:auto; background:#fff; padding:12px; border:1px solid #ccd0d4;"><?php echo esc_html(implode("\n", array_slice($result['files']['stats']['samples'], 0, 50))); ?></pre>
                    <?php
                    $groups = isset($result['files']['stats']['sample_groups']) ? $result['files']['stats']['sample_groups'] : [];
                    self::render_preview_group('DB Meta Functions', isset($groups['db_meta_function']) ? $groups['db_meta_function'] : []);
                    self::render_preview_group('Meta Box Helpers', isset($groups['rwmb_helper']) ? $groups['rwmb_helper'] : []);
                    self::render_preview_group('Meta Query / Meta Key Args', isset($groups['meta_query']) ? $groups['meta_query'] : []);
                    self::render_preview_group('Meta Box Field ID', isset($groups['metabox_field_id']) ? $groups['metabox_field_id'] : []);
                    self::render_preview_group('Admin Columns', isset($groups['admin_columns']) ? $groups['admin_columns'] : []);
                    self::render_preview_group('Array Literal', isset($groups['array_literal']) ? $groups['array_literal'] : []);
                    ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}

Meta_Key_Rename_Tool::init();
