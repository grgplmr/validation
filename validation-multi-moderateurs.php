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

    public function __construct() {
        add_action('add_meta_boxes', [$this, 'register_metabox']);
        add_action('admin_post_spa_toggle_approval', [$this, 'handle_toggle_approval']);
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

    private function normalize_approvals($approvals) {
        if (!is_array($approvals)) {
            $approvals = [];
        }

        $approvals = array_map('absint', $approvals);
        $approvals = array_filter($approvals, function ($id) {
            return $id > 0;
        });

        return array_values(array_unique($approvals));
    }

    private function get_post_approvals($post_id) {
        $approvals = get_post_meta($post_id, self::META_KEY, true);
        return $this->normalize_approvals($approvals);
    }

    public function render_metabox($post) {
        $moderators = $this->get_moderators();
        $approvals = $this->get_post_approvals($post->ID);
        $total_moderators = count($moderators);
        $approvals_count = count($approvals);
        $required = (int) ceil($total_moderators / 2);
        $has_threshold = $approvals_count >= $required && $total_moderators > 0;
        ?>
        <p><strong><?php echo esc_html__('Validations', 'spa'); ?>:</strong> <?php echo esc_html($approvals_count . ' / ' . $total_moderators); ?></p>
        <p><?php echo esc_html__('Seuil requis', 'spa'); ?>: <?php echo esc_html($required); ?> <?php echo esc_html__('validation(s) minimum.', 'spa'); ?></p>
        <?php if ($has_threshold) : ?>
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
                ?>
                <li>
                    <?php echo esc_html($user->display_name); ?> —
                    <?php if ($has_approved) : ?>
                        <span style="color: green; font-weight: 600;"><?php echo esc_html__('Approuvé', 'spa'); ?></span>
                    <?php else : ?>
                        <span style="color: #6c757d;"><?php echo esc_html__('En attente', 'spa'); ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if (current_user_can('edit_post', $post->ID) && $this->current_user_is_moderator()) :
            $current_user = wp_get_current_user();
            $user_has_approved = in_array($current_user->ID, $approvals, true);
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 12px;">
                <?php wp_nonce_field('spa_toggle_approval_' . $post->ID); ?>
                <input type="hidden" name="action" value="spa_toggle_approval" />
                <input type="hidden" name="post_id" value="<?php echo esc_attr($post->ID); ?>" />
                <?php if ($user_has_approved) : ?>
                    <button type="submit" class="button button-secondary">
                        <?php echo esc_html__('Retirer mon approbation', 'spa'); ?>
                    </button>
                <?php else : ?>
                    <button type="submit" class="button button-primary">
                        <?php echo esc_html__('Approuver cet article', 'spa'); ?>
                    </button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
        <?php
    }

    private function current_user_is_moderator() {
        $user = wp_get_current_user();
        if (!$user || empty($user->roles)) {
            return false;
        }

        return !empty(array_intersect($user->roles, ['administrator', 'editor']));
    }

    public function handle_toggle_approval() {
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('Accès refusé.', 'spa'));
        }

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'spa_toggle_approval_' . $post_id)) {
            wp_die(esc_html__('Nonce invalide.', 'spa'));
        }

        if (!$this->current_user_is_moderator()) {
            wp_die(esc_html__('Vous devez être modérateur pour effectuer cette action.', 'spa'));
        }

        $approvals = $this->get_post_approvals($post_id);
        $user_id = get_current_user_id();

        if (in_array($user_id, $approvals, true)) {
            $approvals = array_values(array_filter($approvals, function ($id) use ($user_id) {
                return (int) $id !== (int) $user_id;
            }));
        } else {
            $approvals[] = (int) $user_id;
            $approvals = $this->normalize_approvals($approvals);
        }

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
        $approvals = $this->get_post_approvals($post_id);
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
