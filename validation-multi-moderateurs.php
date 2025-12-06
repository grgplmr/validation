<?php
/*
Plugin Name: Validation multi-modérateurs pour articles
Description: Ajoute un système de validation d’articles par les administrateurs et éditeurs, avec indicateurs visuels.
Version: 1.0.0
Author: Christian Auzolat
*/

if (!defined('ABSPATH')) {
    exit;
}

class SPA_Post_Validation {
    const META_KEY = '_spa_approvals';
    const META_KEY_CHANGES = '_spa_change_requests';
    const META_KEY_CHANGES_DONE = '_spa_changes_done_last';

    public function __construct() {
        add_action('add_meta_boxes', [$this, 'register_metabox']);
        add_action('admin_post_spa_toggle_approval', [$this, 'handle_toggle_approval']);
        add_action('admin_post_spa_toggle_change_request', [$this, 'handle_toggle_change_request']);
        add_action('admin_post_spa_notify_changes_done', [$this, 'handle_notify_changes_done']);
        add_filter('manage_post_posts_columns', [$this, 'add_validation_column']);
        add_action('manage_post_posts_custom_column', [$this, 'render_validation_column'], 10, 2);
        add_action('admin_head-edit.php', [$this, 'enqueue_admin_styles']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
        add_action('admin_menu', [$this, 'register_settings_page']);
    }

    public function register_settings_page() {
        add_options_page(
            __('Validation articles', 'spa'),
            __('Validation articles', 'spa'),
            'manage_options',
            'spa-validation-settings',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('spa_validation_save_moderators_action', 'spa_validation_nonce');

            $ids = isset($_POST['spa_validation_allowed_moderators'])
                ? (array) $_POST['spa_validation_allowed_moderators']
                : [];
            $ids = array_map('intval', $ids);
            $ids = array_values(array_unique($ids));

            update_option('spa_validation_allowed_moderators', $ids);

            echo '<div class="updated notice"><p>' . esc_html__('Paramètres mis à jour.', 'spa') . '</p></div>';
        }

        $allowed = get_option('spa_validation_allowed_moderators', []);
        if (!is_array($allowed)) {
            $allowed = [];
        }
        $allowed = array_map('intval', $allowed);

        $user_query = new WP_User_Query([
            'role__in' => ['administrator', 'editor'],
            'orderby'  => 'display_name',
            'order'    => 'ASC',
        ]);

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Validation articles', 'spa'); ?></h1>
            <form method="post" action="">
                <?php wp_nonce_field('spa_validation_save_moderators_action', 'spa_validation_nonce'); ?>
                <h2><?php echo esc_html__('Modérateurs autorisés', 'spa'); ?></h2>
                <p><?php echo esc_html__('Sélectionnez les administrateurs ou éditeurs autorisés à valider les articles.', 'spa'); ?></p>
                <?php
                if (!empty($user_query->get_results())) {
                    foreach ($user_query->get_results() as $user) {
                        $user_id = (int) $user->ID;
                        $checked = in_array($user_id, $allowed, true) ? 'checked="checked"' : '';
                        echo '<label style="display:block;margin-bottom:4px;">';
                        echo '<input type="checkbox" name="spa_validation_allowed_moderators[]" value="' . esc_attr($user_id) . '" ' . $checked . ' />';
                        echo ' ' . esc_html($user->display_name) . ' (' . esc_html($user->user_email) . ')';
                        echo '</label>';
                    }
                } else {
                    echo '<p>' . esc_html__('Aucun administrateur ou éditeur disponible.', 'spa') . '</p>';
                }
                submit_button(__('Enregistrer les modifications', 'spa'));
                ?>
            </form>
        </div>
        <?php
    }

    public function register_metabox() {
        if (function_exists('use_block_editor_for_post_type') && use_block_editor_for_post_type('post')) {
            return;
        }

        add_meta_box(
            'spa_post_validation',
            __('Validations des modérateurs', 'spa'),
            [$this, 'render_metabox'],
            'post',
            'side',
            'high'
        );
    }

    private function get_potential_moderators() {
        $query = new WP_User_Query([
            'role__in' => ['administrator', 'editor'],
            'fields' => ['ID', 'display_name', 'user_email'],
            'orderby' => 'display_name',
            'order' => 'ASC',
        ]);

        $results = $query->get_results();
        return is_array($results) ? $results : [];
    }

    public static function get_moderator_ids(): array {
        $user_query = new WP_User_Query([
            'role__in' => ['administrator', 'editor'],
            'fields'   => 'ID',
        ]);
        $base_ids = $user_query->get_results();
        if (!is_array($base_ids)) {
            $base_ids = [];
        }
        $base_ids = array_map('intval', $base_ids);
        $base_ids = array_values(array_unique($base_ids));

        $allowed = get_option('spa_validation_allowed_moderators', []);
        if (!is_array($allowed)) {
            $allowed = [];
        }
        $allowed = array_map('intval', $allowed);

        if (empty($allowed)) {
            $ids = $base_ids;
        } else {
            $ids = array_values(array_intersect($base_ids, $allowed));
        }

        return array_values(array_unique($ids));
    }

    private function get_moderators() {
        $moderator_ids = self::get_moderator_ids();

        if (empty($moderator_ids)) {
            return [];
        }

        $users = get_users([
            'include' => $moderator_ids,
            'orderby' => 'include',
            'fields' => ['ID', 'display_name'],
        ]);

        return is_array($users) ? $users : [];
    }

    private static function normalize_approvals($approvals) {
        if (!is_array($approvals)) {
            $approvals = [];
        }

        $approvals = array_map('absint', $approvals);
        $approvals = array_filter($approvals, function ($id) {
            return $id > 0;
        });

        return array_values(array_unique($approvals));
    }

    public static function get_post_approvals($post_id) {
        $approvals = get_post_meta($post_id, self::META_KEY, true);
        return self::normalize_approvals($approvals);
    }

    public static function get_post_change_requests($post_id) {
        $change_requests = get_post_meta($post_id, self::META_KEY_CHANGES, true);
        return self::normalize_approvals($change_requests);
    }

    public function render_metabox($post) {
        $moderators = $this->get_moderators();
        $approvals = self::get_post_approvals($post->ID);
        $change_requests = self::get_post_change_requests($post->ID);
        $total_moderators = count($moderators);
        $approvals_count = count($approvals);
        $change_requests_count = count($change_requests);
        $required = (int) ceil($total_moderators / 2);
        $has_threshold = $approvals_count >= $required && $total_moderators > 0;
        $button_base_style = 'display:block;width:100%;max-width:260px;margin-bottom:2px;color:#ffffff;';
        ?>
        <p><strong><?php echo esc_html__('Validations', 'spa'); ?>:</strong> <?php echo esc_html($approvals_count . ' / ' . $total_moderators); ?></p>
        <p><?php echo esc_html__('Seuil requis', 'spa'); ?>: <?php echo esc_html($required); ?> <?php echo esc_html__('validation(s) minimum.', 'spa'); ?></p>
        <?php if ($total_moderators === 0) : ?>
            <p style="color: #b32d2e; font-weight: 600;">
                <?php echo esc_html__('Aucun modérateur (administrateur ou éditeur) trouvé.', 'spa'); ?>
            </p>
        <?php elseif ($change_requests_count > 0) : ?>
            <p style="color: #ff4e00; font-weight: 600;">
                <?php echo esc_html__('Statut : modifications demandées par', 'spa'); ?> <?php echo esc_html($change_requests_count); ?> <?php echo esc_html__('modérateur(s).', 'spa'); ?>
            </p>
        <?php elseif ($has_threshold) : ?>
            <p style="color: green; font-weight: 600;">
                <?php echo esc_html__('Statut : seuil atteint, article prêt à être publié.', 'spa'); ?>
            </p>
        <?php else : ?>
            <p style="color: #b32d2e; font-weight: 600;">
                <?php echo esc_html__('Statut : validations insuffisantes.', 'spa'); ?>
            </p>
        <?php endif; ?>
        <hr />
        <p><strong><?php echo esc_html__('Détail par modérateur', 'spa'); ?></strong></p>
        <ul style="margin: 0; padding-left: 18px;">
            <?php foreach ($moderators as $user) :
                $has_approved = in_array($user->ID, $approvals, true);
                $has_requested_change = in_array($user->ID, $change_requests, true);
                ?>
                <li>
                    <?php echo esc_html($user->display_name); ?> —
                    <?php if ($has_approved) : ?>
                        <span style="color: green; font-weight: 600;"><?php echo esc_html__('Approuvé', 'spa'); ?></span>
                    <?php elseif ($has_requested_change) : ?>
                        <span style="color: #ff4e00; font-weight: 600;"><?php echo esc_html__('Modification demandée', 'spa'); ?></span>
                    <?php else : ?>
                        <span style="color: #6c757d;"><?php echo esc_html__('En attente', 'spa'); ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if (current_user_can('edit_post', $post->ID) && $this->current_user_is_moderator()) :
            $current_user = wp_get_current_user();
            $user_has_approved = in_array($current_user->ID, $approvals, true);
            $user_requested_change = in_array($current_user->ID, $change_requests, true);
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 12px;">
                <?php wp_nonce_field('spa_toggle_approval_' . $post->ID, 'spa_nonce'); ?>
                <input type="hidden" name="action" value="spa_toggle_approval" />
                <input type="hidden" name="post_id" value="<?php echo esc_attr($post->ID); ?>" />
                <button type="submit" class="button" style="<?php echo esc_attr($button_base_style . 'background-color:#2cd81f;border-color:#2cd81f;'); ?>">
                    <?php echo esc_html($user_has_approved ? __('Retirer mon approbation', 'spa') : __('Approuver cet article', 'spa')); ?>
                </button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('spa_toggle_change_request_' . $post->ID, 'spa_nonce'); ?>
                <input type="hidden" name="action" value="spa_toggle_change_request" />
                <input type="hidden" name="post_id" value="<?php echo esc_attr($post->ID); ?>" />
                <button type="submit" class="button" style="<?php echo esc_attr($button_base_style . 'background-color:#ff4e00;border-color:#ff4e00;'); ?>">
                    <?php echo esc_html($user_requested_change ? __('Retirer la demande de modification', 'spa') : __('Modifier cet article', 'spa')); ?>
                </button>
            </form>
            <?php if ($change_requests_count > 0) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('spa_notify_changes_done_' . $post->ID, 'spa_nonce'); ?>
                    <input type="hidden" name="action" value="spa_notify_changes_done" />
                    <input type="hidden" name="post_id" value="<?php echo esc_attr($post->ID); ?>" />
                    <button type="submit" class="button" style="<?php echo esc_attr($button_base_style . 'background-color:#0073aa;border-color:#0073aa;'); ?>">
                        <?php echo esc_html__('Modifications effectuées', 'spa'); ?>
                    </button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    public static function user_is_moderator(int $user_id): bool {
        if ($user_id <= 0) {
            return false;
        }

        $allowed = get_option('spa_validation_allowed_moderators', []);
        if (!is_array($allowed)) {
            $allowed = [];
        }
        $allowed = array_map('intval', $allowed);

        if (!empty($allowed)) {
            return in_array($user_id, $allowed, true);
        }

        $user = get_userdata($user_id);
        if (!$user || empty($user->roles) || !is_array($user->roles)) {
            return false;
        }

        return in_array('administrator', $user->roles, true)
            || in_array('editor', $user->roles, true);
    }

    private function current_user_is_moderator() {
        return self::user_is_moderator(get_current_user_id());
    }

    public function enqueue_editor_assets() {
        $screen = get_current_screen();

        if (!$screen || 'post' !== $screen->base || 'post' !== $screen->post_type) {
            return;
        }

        if (function_exists('use_block_editor_for_post_type') && !use_block_editor_for_post_type('post')) {
            return;
        }

        $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;

        if (!$post_id && isset($GLOBALS['post']) && $GLOBALS['post'] instanceof WP_Post) {
            $post_id = (int) $GLOBALS['post']->ID;
        }

        if (!$post_id) {
            return;
        }

        $moderator_ids = self::get_moderator_ids();
        $approvals = self::get_post_approvals($post_id);
        $change_requests = self::get_post_change_requests($post_id);
        $changes_done_last = get_post_meta($post_id, self::META_KEY_CHANGES_DONE, true);

        $total_mods = count($moderator_ids);
        $total_approved = count($approvals);
        $total_change_requests = count($change_requests);
        $required = $total_mods > 0 ? (int) ceil($total_mods / 2) : 0;

        $current_user_id = get_current_user_id();
        $current_user_can_toggle = $current_user_id
            && self::user_is_moderator($current_user_id)
            && current_user_can('edit_post', $post_id);
        $current_user_has_approved = in_array($current_user_id, $approvals, true);
        $current_user_requested_changes = in_array($current_user_id, $change_requests, true);

        $moderators_array = [];

        foreach ($moderator_ids as $uid) {
            $user = get_userdata($uid);
            if (!$user) {
                continue;
            }

            $status = 'pending';

            if (in_array($uid, $approvals, true)) {
                $status = 'approved';
            } elseif (in_array($uid, $change_requests, true)) {
                $status = 'change';
            }

            $moderators_array[] = [
                'id'     => (int) $uid,
                'name'   => $user->display_name,
                'status' => $status,
            ];
        }

        wp_enqueue_script(
            'spa-validation-sidebar',
            plugins_url('js/spa-validation-sidebar.js', __FILE__),
            ['wp-plugins', 'wp-edit-post', 'wp-components', 'wp-element'],
            '1.0.0',
            true
        );

        wp_localize_script('spa-validation-sidebar', 'SPAValidationSidebarData', [
            'postId'                      => $post_id,
            'totalModerators'             => $total_mods,
            'totalApproved'               => $total_approved,
            'totalChangeRequests'         => $total_change_requests,
            'required'                    => $required,
            'currentUserCanToggle'        => $current_user_can_toggle,
            'currentUserHasApproved'      => $current_user_has_approved,
            'currentUserRequestedChanges' => $current_user_requested_changes,
            'moderators'                  => $moderators_array,
            'toggleUrl'                   => admin_url('admin-post.php'),
            'approvalNonce'               => wp_create_nonce('spa_toggle_approval_' . $post_id),
            'changeNonce'                 => wp_create_nonce('spa_toggle_change_request_' . $post_id),
            'changesDoneNonce'            => wp_create_nonce('spa_notify_changes_done_' . $post_id),
            'changesDoneLast'             => $changes_done_last,
        ]);
    }

    public function handle_toggle_approval() {
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('Accès refusé.', 'spa'));
        }

        $nonce = isset($_POST['_wpnonce']) ? $_POST['_wpnonce'] : (isset($_POST['spa_nonce']) ? $_POST['spa_nonce'] : '');

        if (!$nonce || !wp_verify_nonce($nonce, 'spa_toggle_approval_' . $post_id)) {
            wp_die(esc_html__('Nonce invalide.', 'spa'));
        }

        if (!$this->current_user_is_moderator()) {
            wp_die(esc_html__('Vous devez être modérateur pour effectuer cette action.', 'spa'));
        }

        $approvals = self::get_post_approvals($post_id);
        $change_requests = self::get_post_change_requests($post_id);
        $user_id = get_current_user_id();

        if (in_array($user_id, $approvals, true)) {
            $approvals = array_values(array_filter($approvals, function ($id) use ($user_id) {
                return (int) $id !== (int) $user_id;
            }));
        } else {
            $approvals[] = (int) $user_id;
            $approvals = self::normalize_approvals($approvals);
        }

        if (in_array($user_id, $change_requests, true)) {
            $change_requests = array_values(array_filter($change_requests, function ($id) use ($user_id) {
                return (int) $id !== (int) $user_id;
            }));
        }

        update_post_meta($post_id, self::META_KEY, $approvals);
        update_post_meta($post_id, self::META_KEY_CHANGES, $change_requests);

        $redirect = get_edit_post_link($post_id, '');
        if ($redirect) {
            wp_safe_redirect($redirect);
            exit;
        }

        wp_safe_redirect(admin_url('edit.php'));
        exit;
    }

    public function handle_toggle_change_request() {
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('Accès refusé.', 'spa'));
        }

        $nonce = isset($_POST['spa_nonce']) ? $_POST['spa_nonce'] : '';

        if (!$nonce || !wp_verify_nonce($nonce, 'spa_toggle_change_request_' . $post_id)) {
            wp_die(esc_html__('Nonce invalide.', 'spa'));
        }

        $user_id = get_current_user_id();

        if (!self::user_is_moderator($user_id)) {
            wp_die(esc_html__('Vous devez être modérateur pour effectuer cette action.', 'spa'));
        }

        $change_requests = self::get_post_change_requests($post_id);
        $approvals = self::get_post_approvals($post_id);

        if (in_array($user_id, $change_requests, true)) {
            $change_requests = array_values(array_filter($change_requests, function ($id) use ($user_id) {
                return (int) $id !== (int) $user_id;
            }));
        } else {
            $change_requests[] = (int) $user_id;
            $change_requests = self::normalize_approvals($change_requests);
            if (in_array($user_id, $approvals, true)) {
                $approvals = array_values(array_filter($approvals, function ($id) use ($user_id) {
                    return (int) $id !== (int) $user_id;
                }));
            }
        }

        update_post_meta($post_id, self::META_KEY_CHANGES, $change_requests);
        update_post_meta($post_id, self::META_KEY, $approvals);

        $redirect = get_edit_post_link($post_id, '');
        if ($redirect) {
            wp_safe_redirect($redirect);
            exit;
        }

        wp_safe_redirect(admin_url('edit.php'));
        exit;
    }

    public function handle_notify_changes_done() {
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('Accès refusé.', 'spa'));
        }

        $nonce = isset($_POST['spa_nonce']) ? $_POST['spa_nonce'] : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'spa_notify_changes_done_' . $post_id)) {
            wp_die(esc_html__('Nonce invalide.', 'spa'));
        }

        if (!$this->current_user_is_moderator()) {
            wp_die(esc_html__('Vous devez être modérateur pour effectuer cette action.', 'spa'));
        }

        $change_request_ids = self::get_post_change_requests($post_id);
        $post = get_post($post_id);
        $current_user = wp_get_current_user();
        $edit_link = get_edit_post_link($post_id, '');

        if ($post && !empty($change_request_ids)) {
            foreach ($change_request_ids as $uid) {
                $user = get_userdata($uid);
                if (!$user || empty($user->user_email)) {
                    continue;
                }

                $subject = sprintf('[Validation article] Modifications effectuées sur « %s »', $post->post_title);
                $message_lines = [
                    sprintf(__('Bonjour %s,', 'spa'), $user->display_name),
                    __('L’auteur a indiqué avoir terminé les modifications demandées.', 'spa'),
                    sprintf(__('Article : %s', 'spa'), $post->post_title),
                ];

                if ($edit_link) {
                    $message_lines[] = sprintf(__('Lien de modification : %s', 'spa'), $edit_link);
                }

                if ($current_user && $current_user->exists()) {
                    $message_lines[] = sprintf(__('Signalé par : %s', 'spa'), $current_user->display_name);
                }

                $message = implode("\n\n", $message_lines);
                wp_mail($user->user_email, $subject, $message);
            }
        }

        update_post_meta($post_id, self::META_KEY_CHANGES_DONE, current_time('timestamp'));

        $redirect = get_edit_post_link($post_id, '');
        if ($redirect) {
            wp_safe_redirect($redirect);
            exit;
        }

        wp_safe_redirect(admin_url('edit.php'));
        exit;
    }

    public function add_validation_column($columns) {
        $new_columns = [];
        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;
            if ('title' === $key) {
                $new_columns['spa_approvals'] = __('Validations', 'spa');
            }
        }

        if (!isset($new_columns['spa_approvals'])) {
            $new_columns['spa_approvals'] = __('Validations', 'spa');
        }

        return $new_columns;
    }

    public function render_validation_column($column, $post_id) {
        if ('spa_approvals' !== $column) {
            return;
        }

        $moderators = $this->get_moderators();
        $approvals = self::get_post_approvals($post_id);
        $total_moderators = count($moderators);
        $approvals_count = count($approvals);
        $required = (int) ceil($total_moderators / 2);

        if ($total_moderators > 0 && $approvals_count >= $required) {
            $class = 'spa-ok';
            $title = __('Seuil atteint, article prêt à être publié.', 'spa');
        } elseif ($approvals_count > 0) {
            $class = 'spa-partial';
            $title = __('Validations partielles, seuil non atteint.', 'spa');
        } else {
            $class = 'spa-none';
            $title = __('Aucune validation.', 'spa');
        }

        printf(
            '<span class="%1$s" title="%2$s">%3$s</span>',
            esc_attr($class),
            esc_attr($title),
            esc_html($approvals_count . ' / ' . $total_moderators)
        );
    }

    public function enqueue_admin_styles() {
        $screen = get_current_screen();
        if (!$screen || 'edit-post' !== $screen->id) {
            return;
        }
        ?>
        <style>
            .column-spa_approvals { width: 120px; }
            .spa-ok { color: #007c35; font-weight: 600; }
            .spa-partial { color: #d59611; font-weight: 600; }
            .spa-none { color: #b32d2e; font-weight: 600; }
        </style>
        <?php
    }
}

new SPA_Post_Validation();
