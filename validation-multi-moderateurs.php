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

    public function __construct() {
        add_action('add_meta_boxes', [$this, 'register_metabox']);
        add_action('admin_post_spa_toggle_approval', [$this, 'handle_toggle_approval']);
        add_action('admin_post_spa_toggle_change_request', [$this, 'handle_toggle_change_request']);
        add_filter('manage_post_posts_columns', [$this, 'add_validation_column']);
        add_action('manage_post_posts_custom_column', [$this, 'render_validation_column'], 10, 2);
        add_action('admin_head-edit.php', [$this, 'enqueue_admin_styles']);
    }

    public function register_metabox() {
        add_meta_box(
            'spa_post_validation',
            __('Validations des modérateurs', 'spa'),
            [$this, 'render_metabox'],
            'post',
            'side',
            'high'
        );
    }

    private function get_moderators() {
        $query = new WP_User_Query([
            'role__in' => ['administrator', 'editor'],
            'fields' => ['ID', 'display_name'],
        ]);

        $results = $query->get_results();
        return is_array($results) ? $results : [];
    }

    public static function get_moderator_ids() {
        $query = new WP_User_Query([
            'role__in' => ['administrator', 'editor'],
            'fields' => 'ID',
        ]);

        $results = $query->get_results();
        $results = is_array($results) ? $results : [];

        return array_values(array_filter(array_map('absint', $results)));
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
                <button type="submit" class="button" style="background-color:#2cd81f; border-color:#2cd81f; color:#ffffff; margin-right: 8px;">
                    <?php echo esc_html($user_has_approved ? __('Retirer mon approbation', 'spa') : __('Approuver cet article', 'spa')); ?>
                </button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 8px;">
                <?php wp_nonce_field('spa_toggle_change_request_' . $post->ID, 'spa_nonce'); ?>
                <input type="hidden" name="action" value="spa_toggle_change_request" />
                <input type="hidden" name="post_id" value="<?php echo esc_attr($post->ID); ?>" />
                <button type="submit" class="button" style="background-color:#ff4e00; border-color:#ff4e00; color:#ffffff;">
                    <?php echo esc_html($user_requested_change ? __('Retirer la demande de modification', 'spa') : __('Modifier cet article', 'spa')); ?>
                </button>
            </form>
        <?php endif; ?>
        <?php
    }

    public static function user_is_moderator($user_id) {
        $user = get_userdata($user_id);
        if (!$user || empty($user->roles)) {
            return false;
        }

        return !empty(array_intersect($user->roles, ['administrator', 'editor']));
    }

    private function current_user_is_moderator() {
        return self::user_is_moderator(get_current_user_id());
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
