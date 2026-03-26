<?php
/**
 * Plugin Name: SE Sitewide Block
 * Description: Custom post type for scheduled, location-aware sitewide block content.
 * Version:     1.0.0
 * Plugin URI:  https://github.com/humbabba/se-sitewide-blocks
 * Author:      Charles Gray
 * Author URI:  https://humbabba.com/portfolio
 * Text Domain: se-sitewide-block
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SE_BLOCK_PATH', plugin_dir_path( __FILE__ ) );

add_filter( 'plugin_row_meta', function ( $links, $file ) {
    if ( plugin_basename( __FILE__ ) === $file ) {
        $links[] = '<a href="https://github.com/humbabba/se-sitewide-blocks#readme">' . __( 'Docs', 'se-sitewide-block' ) . '</a>';
    }
    return $links;
}, 10, 2 );

/**
 * Available insertion locations.
 */
function se_block_locations(): array {
    return [
        'site_top'       => __( 'Site top', 'se-sitewide-block' ),
        'before_content' => __( 'Before content', 'se-sitewide-block' ),
        'after_content'  => __( 'After content', 'se-sitewide-block' ),
        'before_footer'  => __( 'Before footer', 'se-sitewide-block' ),
    ];
}

/* ------------------------------------------------------------------ */
/*  CPT Registration                                                  */
/* ------------------------------------------------------------------ */

add_action( 'init', function () {
    register_post_type( 'sitewide_block', [
        'labels' => [
            'name'               => __( 'Sitewide Blocks', 'se-sitewide-block' ),
            'singular_name'      => __( 'Sitewide Block', 'se-sitewide-block' ),
            'add_new'            => __( 'Add New Block', 'se-sitewide-block' ),
            'add_new_item'       => __( 'Add New Block', 'se-sitewide-block' ),
            'edit_item'          => __( 'Edit Block', 'se-sitewide-block' ),
            'new_item'           => __( 'New Block', 'se-sitewide-block' ),
            'view_item'          => __( 'View Block', 'se-sitewide-block' ),
            'search_items'       => __( 'Search Blocks', 'se-sitewide-block' ),
            'not_found'          => __( 'No blocks found', 'se-sitewide-block' ),
            'not_found_in_trash' => __( 'No blocks found in trash', 'se-sitewide-block' ),
        ],
        'public'              => true,
        'publicly_queryable'  => true,   // needed for preview / View links
        'exclude_from_search' => true,
        'has_archive'         => false,
        'show_in_rest'        => true,
        'menu_icon'           => 'dashicons-megaphone',
        'supports'            => [ 'title', 'editor', 'revisions' ],
        'rewrite'             => [ 'slug' => 'block', 'with_front' => false ],
    ] );
} );

/* Flush rewrite rules on activation / deactivation */
register_activation_hook( __FILE__, function () {
    // fire init so CPT is registered before flush
    do_action( 'init' );
    flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

/* ------------------------------------------------------------------ */
/*  Bare-bones single template (no header / footer / theme chrome)    */
/* ------------------------------------------------------------------ */

add_filter( 'single_template', function ( string $template ): string {
    if ( get_post_type() === 'sitewide_block' ) {
        $custom = SE_BLOCK_PATH . 'templates/single-sitewide_block.php';
        if ( file_exists( $custom ) ) {
            return $custom;
        }
    }
    return $template;
} );

/* ------------------------------------------------------------------ */
/*  Meta Boxes                                                        */
/* ------------------------------------------------------------------ */

// Warn when a published block has no location (works in both classic and block editor)
add_action( 'admin_notices', function () {
    global $pagenow, $post;
    if ( $pagenow !== 'post.php' || ! $post || $post->post_type !== 'sitewide_block' ) return;
    if ( $post->post_status !== 'publish' ) return;

    $locations    = array_filter( (array) get_post_meta( $post->ID, '_block_location', true ) );
    $custom_hooks = array_filter( (array) get_post_meta( $post->ID, '_block_custom_hooks', true ) );

    if ( empty( $locations ) && empty( $custom_hooks ) ) {
        // Classic editor notice
        printf(
            '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
            esc_html__( 'This block is published but has no location or custom hook assigned — it will not appear anywhere.', 'se-sitewide-block' )
        );
    }
} );

add_action( 'enqueue_block_editor_assets', function () {
    global $post;
    if ( ! $post || $post->post_type !== 'sitewide_block' ) return;
    if ( $post->post_status !== 'publish' ) return;

    $locations    = array_filter( (array) get_post_meta( $post->ID, '_block_location', true ) );
    $custom_hooks = array_filter( (array) get_post_meta( $post->ID, '_block_custom_hooks', true ) );

    if ( empty( $locations ) && empty( $custom_hooks ) ) {
        $msg = esc_js( __( 'This block is published but has no location or custom hook assigned — it will not appear anywhere.', 'se-sitewide-block' ) );
        wp_add_inline_script( 'wp-notices', "wp.data.dispatch('core/notices').createWarningNotice('{$msg}',{id:'se-block-no-location',isDismissible:true});" );
    }
} );

add_action( 'add_meta_boxes', function () {
    add_meta_box(
        'se_block_location',
        __( 'Location', 'se-sitewide-block' ),
        'se_block_location_cb',
        'sitewide_block',
        'normal'
    );
    add_meta_box(
        'se_block_schedule',
        __( 'Schedule', 'se-sitewide-block' ),
        'se_block_schedule_cb',
        'sitewide_block',
        'normal'
    );
    add_meta_box(
        'se_block_visibility',
        __( 'Visibility', 'se-sitewide-block' ),
        'se_block_visibility_cb',
        'sitewide_block',
        'normal'
    );
} );

/* --- Location meta box -------------------------------------------- */

function se_block_location_cb( WP_Post $post ): void {
    wp_nonce_field( 'se_block_meta', 'se_block_nonce' );
    $current = (array) get_post_meta( $post->ID, '_block_location', true );
    $locations = se_block_locations();
    foreach ( $locations as $value => $label ) {
        printf(
            '<label style="display:inline-block;margin:4px 10px 4px 0"><input type="checkbox" name="_block_location[]" value="%s" %s> %s</label>',
            esc_attr( $value ),
            checked( in_array( $value, $current, true ), true, false ),
            esc_html( $label )
        );
    }

    // Custom hooks
    $custom_hooks = (array) get_post_meta( $post->ID, '_block_custom_hooks', true );
    $custom_hooks = array_filter( $custom_hooks ); // remove empties
    if ( empty( $custom_hooks ) ) {
        $custom_hooks = [ [ 'hook' => '', 'priority' => 10 ] ];
    }
    ?>
    <div id="se-block-custom-hooks" style="margin-top:12px;border-top:1px solid #ddd;padding-top:8px">
        <strong><?php esc_html_e( 'Custom hooks', 'se-sitewide-block' ); ?></strong>
        <input type="hidden" name="_block_custom_hooks_present" value="1">
        <div id="se-block-hooks-list">
            <?php foreach ( $custom_hooks as $i => $entry ) :
                $hook     = is_array( $entry ) ? ( $entry['hook'] ?? '' ) : '';
                $priority = is_array( $entry ) ? ( $entry['priority'] ?? 10 ) : 10;
            ?>
            <div class="se-block-hook-row" style="margin:6px 0;display:flex;gap:4px;align-items:center">
                <input type="text" name="_block_custom_hooks[<?php echo $i; ?>][hook]"
                    value="<?php echo esc_attr( $hook ); ?>"
                    placeholder="<?php esc_attr_e( 'action name', 'se-sitewide-block' ); ?>"
                    style="flex:1;min-width:0">
                <input type="number" name="_block_custom_hooks[<?php echo $i; ?>][priority]"
                    value="<?php echo esc_attr( $priority ); ?>"
                    title="<?php esc_attr_e( 'Priority', 'se-sitewide-block' ); ?>"
                    style="width:50px" min="0" step="1">
                <button type="button" class="se-block-remove-hook button-link" title="<?php esc_attr_e( 'Remove', 'se-sitewide-block' ); ?>" style="color:#b32d2e;text-decoration:none">&times;</button>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" id="se-block-add-hook" class="button button-small" style="margin-top:4px">
            <?php esc_html_e( '+ Add hook', 'se-sitewide-block' ); ?>
        </button>
    </div>
    <script>
    (function(){
        var list = document.getElementById('se-block-hooks-list');
        var add  = document.getElementById('se-block-add-hook');
        var idx  = list.querySelectorAll('.se-block-hook-row').length;

        add.addEventListener('click', function(){
            var row = document.createElement('div');
            row.className = 'se-block-hook-row';
            row.style.cssText = 'margin:6px 0;display:flex;gap:4px;align-items:center';
            row.innerHTML =
                '<input type="text" name="_block_custom_hooks[' + idx + '][hook]" placeholder="action name" style="flex:1;min-width:0">' +
                '<input type="number" name="_block_custom_hooks[' + idx + '][priority]" value="10" title="Priority" style="width:50px" min="0" step="1">' +
                '<button type="button" class="se-block-remove-hook button-link" title="Remove" style="color:#b32d2e;text-decoration:none">&times;</button>';
            list.appendChild(row);
            idx++;
        });

        list.addEventListener('click', function(e){
            if (e.target.classList.contains('se-block-remove-hook')) {
                e.target.closest('.se-block-hook-row').remove();
            }
        });
    })();
    </script>
    <?php
}

/* --- Schedule meta box -------------------------------------------- */

function se_block_schedule_cb( WP_Post $post ): void {
    $type       = get_post_meta( $post->ID, '_block_schedule_type', true ) ?: 'always';
    $start      = get_post_meta( $post->ID, '_block_start', true );
    $end        = get_post_meta( $post->ID, '_block_end', true );
    $rec_days   = (array) get_post_meta( $post->ID, '_block_recurring_days', true );
    $rec_start  = get_post_meta( $post->ID, '_block_recurring_time_start', true );
    $rec_end    = get_post_meta( $post->ID, '_block_recurring_time_end', true );

    $days_of_week = [
        'mon' => __( 'Mon', 'se-sitewide-block' ),
        'tue' => __( 'Tue', 'se-sitewide-block' ),
        'wed' => __( 'Wed', 'se-sitewide-block' ),
        'thu' => __( 'Thu', 'se-sitewide-block' ),
        'fri' => __( 'Fri', 'se-sitewide-block' ),
        'sat' => __( 'Sat', 'se-sitewide-block' ),
        'sun' => __( 'Sun', 'se-sitewide-block' ),
    ];
    ?>
    <style>
        .se-block-schedule-row { margin: 8px 0; }
        .se-block-schedule-row label { font-weight: 600; display: inline-block; min-width: 90px; }
        .se-block-panel { display: none; margin-top: 12px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; }
        .se-block-panel.active { display: block; }
        .se-block-days label { margin-right: 8px; font-weight: normal; }
    </style>

    <div class="se-block-schedule-row">
        <label for="_block_schedule_type"><?php esc_html_e( 'Type', 'se-sitewide-block' ); ?></label>
        <select id="_block_schedule_type" name="_block_schedule_type">
            <option value="always"    <?php selected( $type, 'always' ); ?>><?php esc_html_e( 'Always On', 'se-sitewide-block' ); ?></option>
            <option value="scheduled" <?php selected( $type, 'scheduled' ); ?>><?php esc_html_e( 'Date Range', 'se-sitewide-block' ); ?></option>
            <option value="recurring" <?php selected( $type, 'recurring' ); ?>><?php esc_html_e( 'Recurring', 'se-sitewide-block' ); ?></option>
        </select>
    </div>

    <!-- Date Range panel -->
    <div id="se-block-panel-scheduled" class="se-block-panel <?php echo $type === 'scheduled' ? 'active' : ''; ?>">
        <div class="se-block-schedule-row">
            <label for="_block_start"><?php esc_html_e( 'Start', 'se-sitewide-block' ); ?></label>
            <input type="datetime-local" id="_block_start" name="_block_start" value="<?php echo esc_attr( $start ); ?>">
        </div>
        <div class="se-block-schedule-row">
            <label for="_block_end"><?php esc_html_e( 'End', 'se-sitewide-block' ); ?></label>
            <input type="datetime-local" id="_block_end" name="_block_end" value="<?php echo esc_attr( $end ); ?>">
        </div>
    </div>

    <!-- Recurring panel -->
    <div id="se-block-panel-recurring" class="se-block-panel <?php echo $type === 'recurring' ? 'active' : ''; ?>">
        <div class="se-block-schedule-row se-block-days">
            <label style="display:block;margin-bottom:4px"><?php esc_html_e( 'Days', 'se-sitewide-block' ); ?></label>
            <?php foreach ( $days_of_week as $val => $lbl ) : ?>
                <label>
                    <input type="checkbox" name="_block_recurring_days[]" value="<?php echo esc_attr( $val ); ?>"
                        <?php checked( in_array( $val, $rec_days, true ) ); ?>>
                    <?php echo esc_html( $lbl ); ?>
                </label>
            <?php endforeach; ?>
        </div>
        <div class="se-block-schedule-row">
            <label for="_block_recurring_time_start"><?php esc_html_e( 'From', 'se-sitewide-block' ); ?></label>
            <input type="time" id="_block_recurring_time_start" name="_block_recurring_time_start" value="<?php echo esc_attr( $rec_start ); ?>">
        </div>
        <div class="se-block-schedule-row">
            <label for="_block_recurring_time_end"><?php esc_html_e( 'To', 'se-sitewide-block' ); ?></label>
            <input type="time" id="_block_recurring_time_end" name="_block_recurring_time_end" value="<?php echo esc_attr( $rec_end ); ?>">
        </div>
        <div class="se-block-schedule-row">
            <label for="_block_start_recurring"><?php esc_html_e( 'Active from', 'se-sitewide-block' ); ?></label>
            <input type="date" id="_block_start_recurring" name="_block_start" value="<?php echo esc_attr( $start ? substr( $start, 0, 10 ) : '' ); ?>">
        </div>
        <div class="se-block-schedule-row">
            <label for="_block_end_recurring"><?php esc_html_e( 'Active until', 'se-sitewide-block' ); ?></label>
            <input type="date" id="_block_end_recurring" name="_block_end" value="<?php echo esc_attr( $end ? substr( $end, 0, 10 ) : '' ); ?>">
        </div>
    </div>

    <script>
    (function(){
        var sel = document.getElementById('_block_schedule_type');
        sel.addEventListener('change', function(){
            document.querySelectorAll('.se-block-panel').forEach(function(p){ p.classList.remove('active'); });
            var target = document.getElementById('se-block-panel-' + sel.value);
            if (target) target.classList.add('active');
        });
    })();
    </script>
    <?php
}

/* --- Visibility meta box ------------------------------------------ */

/**
 * Return available page-type choices grouped for the visibility UI.
 */
function se_block_page_types(): array {
    $singles = [
        'single_post' => __( 'Posts', 'se-sitewide-block' ),
        'single_page' => __( 'Pages', 'se-sitewide-block' ),
    ];
    foreach ( get_post_types( [ 'public' => true, '_builtin' => false ], 'objects' ) as $pt ) {
        if ( $pt->name === 'sitewide_block' ) continue;
        $singles[ 'single_' . $pt->name ] = $pt->labels->singular_name;
    }

    $archives = [
        'archive_category' => __( 'Category archives', 'se-sitewide-block' ),
        'archive_post_tag' => __( 'Tag archives', 'se-sitewide-block' ),
        'archive_date'     => __( 'Date archives', 'se-sitewide-block' ),
        'archive_author'   => __( 'Author archives', 'se-sitewide-block' ),
    ];
    foreach ( get_post_types( [ 'public' => true, '_builtin' => false, 'has_archive' => true ], 'objects' ) as $pt ) {
        if ( $pt->name === 'sitewide_block' ) continue;
        $archives[ 'archive_' . $pt->name ] = sprintf( __( '%s archive', 'se-sitewide-block' ), $pt->labels->singular_name );
    }
    foreach ( get_taxonomies( [ 'public' => true, '_builtin' => false ], 'objects' ) as $tax ) {
        $archives[ 'archive_tax_' . $tax->name ] = sprintf( __( '%s archives', 'se-sitewide-block' ), $tax->labels->singular_name );
    }

    $special = [
        'front_page'  => __( 'Home page (front page)', 'se-sitewide-block' ),
        'posts_page'  => __( 'Posts page (blog index)', 'se-sitewide-block' ),
        'search'      => __( 'Search results', 'se-sitewide-block' ),
        '404'         => __( '404', 'se-sitewide-block' ),
    ];

    return [
        __( 'Singles', 'se-sitewide-block' )  => $singles,
        __( 'Archives', 'se-sitewide-block' ) => $archives,
        __( 'Special', 'se-sitewide-block' )  => $special,
    ];
}

/**
 * Walker that outputs checkboxes with custom name attributes and slug values.
 */
class SE_Block_Term_Walker extends Walker {
    public $tree_type = 'category';
    public $db_fields = [ 'parent' => 'parent', 'id' => 'term_id' ];

    private string $input_name;
    private array  $selected_slugs;

    public function __construct( string $input_name, array $selected_slugs ) {
        $this->input_name     = $input_name;
        $this->selected_slugs = $selected_slugs;
    }

    public function start_lvl( &$output, $depth = 0, $args = [] ) {
        $output .= '<ul class="children" style="margin-left:18px">';
    }

    public function end_lvl( &$output, $depth = 0, $args = [] ) {
        $output .= '</ul>';
    }

    public function start_el( &$output, $term, $depth = 0, $args = [], $current_object_id = 0 ) {
        $checked = in_array( $term->slug, $this->selected_slugs, true ) ? ' checked' : '';
        $output .= '<li><label><input type="checkbox" name="' . esc_attr( $this->input_name ) . '[]" value="' . esc_attr( $term->slug ) . '"' . $checked . '> ' . esc_html( $term->name ) . '</label>';
    }

    public function end_el( &$output, $term, $depth = 0, $args = [] ) {
        $output .= '</li>';
    }
}

/**
 * Resolve saved term data (any format) into a [ taxonomy => [ slugs ] ] map.
 */
function se_block_terms_by_tax( array $rows ): array {
    $by_tax = [];
    foreach ( array_filter( $rows ) as $row ) {
        if ( ! is_array( $row ) || empty( $row['taxonomy'] ) ) continue;
        $t     = $row['taxonomy'];
        $terms = $row['terms'] ?? [];
        if ( is_string( $terms ) ) {
            $terms = array_filter( array_map( 'trim', explode( ',', $terms ) ) );
        }
        $by_tax[ $t ] = array_merge( $by_tax[ $t ] ?? [], (array) $terms );
    }
    return $by_tax;
}

function se_block_visibility_cb( WP_Post $post ): void {
    $page_types = (array) get_post_meta( $post->ID, '_block_page_types', true );
    $show_terms = (array) get_post_meta( $post->ID, '_block_show_terms', true );
    $hide_terms = (array) get_post_meta( $post->ID, '_block_hide_terms', true );
    $show_ids   = get_post_meta( $post->ID, '_block_show_ids', true ) ?: '';
    $hide_ids   = get_post_meta( $post->ID, '_block_hide_ids', true ) ?: '';

    $show_by_tax = se_block_terms_by_tax( $show_terms );
    $hide_by_tax = se_block_terms_by_tax( $hide_terms );

    $taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
    ?>
    <style>
        .se-block-vis-group { margin: 10px 0; }
        .se-block-vis-group h4 { margin: 8px 0 4px; }
        .se-block-vis-group label { display: inline-block; margin: 2px 10px 2px 0; font-weight: normal; }
        .se-block-vis-section { margin: 14px 0; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; }
        .se-block-vis-section > strong { display: block; margin-bottom: 6px; }
        .se-block-tabs { display: flex; flex-wrap: wrap; gap: 0; border-bottom: 1px solid #ddd; margin-bottom: 8px; }
        .se-block-tabs button { padding: 6px 12px; border: 1px solid transparent; border-bottom: none; background: none; cursor: pointer; margin-bottom: -1px; font-size: 13px; }
        .se-block-tabs button.active { border-color: #ddd; background: #f9f9f9; border-bottom-color: #f9f9f9; font-weight: 600; }
        .se-block-tab-panel { display: none; max-height: 200px; overflow-y: auto; padding: 4px 0; }
        .se-block-tab-panel.active { display: block; }
        .se-block-tab-panel ul { margin: 0; padding: 0; list-style: none; }
        .se-block-tab-panel label { font-weight: normal; }
        .se-block-tab-panel .se-block-term-search { width: 100%; margin-bottom: 6px; padding: 4px 6px; box-sizing: border-box; }
    </style>

    <!-- Page types -->
    <div class="se-block-vis-group">
        <p class="description"><?php esc_html_e( 'Leave all unchecked to show everywhere.', 'se-sitewide-block' ); ?></p>
        <?php foreach ( se_block_page_types() as $group_label => $types ) : ?>
            <h4><?php echo esc_html( $group_label ); ?></h4>
            <?php foreach ( $types as $value => $label ) : ?>
                <label>
                    <input type="checkbox" name="_block_page_types[]" value="<?php echo esc_attr( $value ); ?>"
                        <?php checked( in_array( $value, $page_types, true ) ); ?>>
                    <?php echo esc_html( $label ); ?>
                </label>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>

    <?php
    foreach ( [
        '_block_show_terms' => [ __( 'Show on these terms', 'se-sitewide-block' ), $show_by_tax ],
        '_block_hide_terms' => [ __( 'Hide on these terms', 'se-sitewide-block' ), $hide_by_tax ],
    ] as $field_name => [ $heading, $saved_by_tax ] ) :
        $uid = str_replace( [ '[', ']', '_' ], '-', $field_name );
    ?>
    <div class="se-block-vis-section">
        <strong><?php echo esc_html( $heading ); ?></strong>
        <div class="se-block-tabs" data-group="<?php echo esc_attr( $uid ); ?>">
            <?php $first = true; foreach ( $taxonomies as $tax ) : ?>
                <button type="button" class="<?php echo $first ? 'active' : ''; ?>" data-tab="<?php echo esc_attr( $uid . '-' . $tax->name ); ?>">
                    <?php echo esc_html( $tax->labels->name ); ?>
                </button>
            <?php $first = false; endforeach; ?>
        </div>
        <?php $first = true; foreach ( $taxonomies as $tax ) :
            $selected_slugs = $saved_by_tax[ $tax->name ] ?? [];
            $term_count = wp_count_terms( [ 'taxonomy' => $tax->name, 'hide_empty' => false ] );
        ?>
        <div class="se-block-tab-panel <?php echo $first ? 'active' : ''; ?>" id="<?php echo esc_attr( $uid . '-' . $tax->name ); ?>">
            <?php if ( $term_count > 10 ) : ?>
                <input type="text" class="se-block-term-search" placeholder="<?php esc_attr_e( 'Filter…', 'se-sitewide-block' ); ?>">
            <?php endif; ?>
            <?php
            if ( $term_count > 0 ) {
                $walker = new SE_Block_Term_Walker(
                    $field_name . '[' . $tax->name . ']',
                    $selected_slugs
                );
                wp_terms_checklist( 0, [
                    'taxonomy'      => $tax->name,
                    'walker'        => $walker,
                    'checked_ontop' => false,
                ] );
            } else {
                echo '<p class="description">' . esc_html__( 'No terms.', 'se-sitewide-block' ) . '</p>';
            }
            ?>
        </div>
        <?php $first = false; endforeach; ?>
    </div>
    <?php endforeach; ?>

    <!-- Show / Hide on post IDs -->
    <div class="se-block-vis-section">
        <strong><?php esc_html_e( 'Show on post IDs', 'se-sitewide-block' ); ?></strong>
        <input type="text" name="_block_show_ids" value="<?php echo esc_attr( $show_ids ); ?>"
            placeholder="<?php esc_attr_e( 'e.g. 12, 345, 678', 'se-sitewide-block' ); ?>" class="widefat">
    </div>
    <div class="se-block-vis-section">
        <strong><?php esc_html_e( 'Hide on post IDs', 'se-sitewide-block' ); ?></strong>
        <input type="text" name="_block_hide_ids" value="<?php echo esc_attr( $hide_ids ); ?>"
            placeholder="<?php esc_attr_e( 'e.g. 12, 345, 678', 'se-sitewide-block' ); ?>" class="widefat">
    </div>

    <script>
    (function(){
        // Tabs
        document.querySelectorAll('.se-block-tabs').forEach(function(tabs){
            tabs.addEventListener('click', function(e){
                var btn = e.target.closest('button[data-tab]');
                if (!btn) return;
                tabs.querySelectorAll('button').forEach(function(b){ b.classList.remove('active'); });
                btn.classList.add('active');
                var section = tabs.closest('.se-block-vis-section');
                section.querySelectorAll('.se-block-tab-panel').forEach(function(p){ p.classList.remove('active'); });
                document.getElementById(btn.dataset.tab).classList.add('active');
            });
        });

        // Search filter
        document.querySelectorAll('.se-block-term-search').forEach(function(input){
            input.addEventListener('input', function(){
                var q = input.value.toLowerCase();
                var panel = input.closest('.se-block-tab-panel');
                panel.querySelectorAll('li').forEach(function(li){
                    li.style.display = li.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
                });
            });
        });
    })();
    </script>
    <?php
}

/* --- Save meta ---------------------------------------------------- */

add_action( 'save_post_sitewide_block', function ( int $post_id ): void {
    if ( ! isset( $_POST['se_block_nonce'] ) || ! wp_verify_nonce( $_POST['se_block_nonce'], 'se_block_meta' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    // Location
    $locations = array_map( 'sanitize_text_field', (array) ( $_POST['_block_location'] ?? [] ) );
    update_post_meta( $post_id, '_block_location', $locations );

    // Schedule type
    $type = sanitize_text_field( $_POST['_block_schedule_type'] ?? 'always' );
    if ( ! in_array( $type, [ 'always', 'scheduled', 'recurring' ], true ) ) {
        $type = 'always';
    }
    update_post_meta( $post_id, '_block_schedule_type', $type );

    // Start / End
    update_post_meta( $post_id, '_block_start', sanitize_text_field( $_POST['_block_start'] ?? '' ) );
    update_post_meta( $post_id, '_block_end', sanitize_text_field( $_POST['_block_end'] ?? '' ) );

    // Recurring fields
    $days = array_map( 'sanitize_text_field', (array) ( $_POST['_block_recurring_days'] ?? [] ) );
    update_post_meta( $post_id, '_block_recurring_days', $days );
    update_post_meta( $post_id, '_block_recurring_time_start', sanitize_text_field( $_POST['_block_recurring_time_start'] ?? '' ) );
    update_post_meta( $post_id, '_block_recurring_time_end', sanitize_text_field( $_POST['_block_recurring_time_end'] ?? '' ) );

    // Custom hooks (only update if the meta box was rendered — sentinel field present)
    if ( ! empty( $_POST['_block_custom_hooks_present'] ) ) {
        $raw_hooks = (array) ( $_POST['_block_custom_hooks'] ?? [] );
        $clean_hooks = [];
        foreach ( $raw_hooks as $entry ) {
            $hook = preg_replace( '/[^a-zA-Z0-9_]/', '', $entry['hook'] ?? '' );
            if ( $hook === '' ) continue;
            $clean_hooks[] = [
                'hook'     => $hook,
                'priority' => (int) ( $entry['priority'] ?? 10 ),
            ];
        }
        update_post_meta( $post_id, '_block_custom_hooks', $clean_hooks );
    }

    // Visibility — page types
    $page_types = array_map( 'sanitize_text_field', (array) ( $_POST['_block_page_types'] ?? [] ) );
    update_post_meta( $post_id, '_block_page_types', $page_types );

    // Visibility — show/hide terms (format: _block_show_terms[taxonomy_name][] = slug)
    foreach ( [ '_block_show_terms', '_block_hide_terms' ] as $key ) {
        $raw   = (array) ( $_POST[ $key ] ?? [] );
        $clean = [];
        foreach ( $raw as $tax => $slugs ) {
            $tax   = sanitize_text_field( $tax );
            $slugs = array_map( 'sanitize_text_field', (array) $slugs );
            $slugs = array_filter( $slugs );
            if ( $tax && ! empty( $slugs ) ) {
                $clean[] = [ 'taxonomy' => $tax, 'terms' => $slugs ];
            }
        }
        update_post_meta( $post_id, $key, $clean );
    }

    // Visibility — show/hide post IDs
    update_post_meta( $post_id, '_block_show_ids', sanitize_text_field( $_POST['_block_show_ids'] ?? '' ) );
    update_post_meta( $post_id, '_block_hide_ids', sanitize_text_field( $_POST['_block_hide_ids'] ?? '' ) );
} );

/* ------------------------------------------------------------------ */
/*  Admin columns                                                     */
/* ------------------------------------------------------------------ */

add_filter( 'manage_sitewide_block_posts_columns', function ( array $columns ): array {
    $new = [];
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'title' ) {
            $new['placement'] = __( 'Placement', 'se-sitewide-block' );
            $new['schedule']  = __( 'Schedule', 'se-sitewide-block' );
        }
    }
    return $new;
} );

add_action( 'manage_sitewide_block_posts_custom_column', function ( string $column, int $post_id ): void {
    if ( $column === 'placement' ) {
        $locs      = (array) get_post_meta( $post_id, '_block_location', true );
        $all_locs  = se_block_locations();
        $labels    = array_filter( array_map( fn( $l ) => $all_locs[ $l ] ?? null, $locs ) );
        $hooks     = (array) get_post_meta( $post_id, '_block_custom_hooks', true );
        foreach ( $hooks as $entry ) {
            if ( is_array( $entry ) && ! empty( $entry['hook'] ) ) {
                $labels[] = $entry['hook'] . ' (' . ( $entry['priority'] ?? 10 ) . ')';
            }
        }
        echo $labels ? esc_html( implode( ', ', $labels ) ) : '<span style="color:#999">—</span>';
    }

    if ( $column === 'schedule' ) {
        $type  = get_post_meta( $post_id, '_block_schedule_type', true ) ?: 'always';
        $start = get_post_meta( $post_id, '_block_start', true );
        $end   = get_post_meta( $post_id, '_block_end', true );

        if ( $type === 'always' ) {
            echo esc_html__( 'Always on', 'se-sitewide-block' );
            return;
        }

        if ( $type === 'scheduled' ) {
            $parts = [];
            if ( $start ) $parts[] = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $start ) );
            if ( $end )   $parts[] = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $end ) );
            echo esc_html( implode( ' — ', $parts ) );
            return;
        }

        if ( $type === 'recurring' ) {
            $days      = (array) get_post_meta( $post_id, '_block_recurring_days', true );
            $rec_start = get_post_meta( $post_id, '_block_recurring_time_start', true );
            $rec_end   = get_post_meta( $post_id, '_block_recurring_time_end', true );
            $parts     = [];
            if ( $days )      $parts[] = implode( ', ', array_map( 'ucfirst', $days ) );
            if ( $rec_start ) $parts[] = $rec_start;
            if ( $rec_end )   $parts[] = '– ' . $rec_end;
            echo esc_html( implode( ' ', $parts ) ?: __( 'Recurring', 'se-sitewide-block' ) );
        }
    }
}, 10, 2 );

/* ------------------------------------------------------------------ */
/*  Admin drag-and-drop sorting                                       */
/* ------------------------------------------------------------------ */

// AJAX handler — save order
add_action( 'wp_ajax_se_block_save_order', function () {
    check_ajax_referer( 'se_block_sort' );
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error();
    }
    $order = array_map( 'intval', $_POST['order'] ?? [] );
    foreach ( $order as $pos => $post_id ) {
        update_post_meta( $post_id, '_se_block_order', $pos );
    }
    wp_send_json_success();
} );

// Unsort handler
add_action( 'admin_init', function () {
    if ( ! isset( $_GET['se_block_unsort'] ) || ! current_user_can( 'edit_posts' ) ) return;
    check_admin_referer( 'se_block_unsort' );
    $blocks = get_posts( [
        'post_type'      => 'sitewide_block',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );
    foreach ( $blocks as $id ) {
        delete_post_meta( $id, '_se_block_order' );
    }
    wp_safe_redirect( admin_url( 'edit.php?post_type=sitewide_block' ) );
    exit;
} );

// Apply custom order on admin list
add_action( 'pre_get_posts', function ( WP_Query $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) return;
    if ( ( $query->get( 'post_type' ) ?? '' ) !== 'sitewide_block' ) return;

    // Only apply if any block has a saved order
    global $wpdb;
    $has_order = $wpdb->get_var(
        "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_se_block_order' LIMIT 1"
    );
    if ( ! $has_order ) return;

    $query->set( 'meta_key', '_se_block_order' );
    $query->set( 'orderby', 'meta_value_num' );
    $query->set( 'order', 'ASC' );
} );

// Admin notice when sorted
add_action( 'admin_notices', function () {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'edit-sitewide_block' ) return;

    global $wpdb;
    $has_order = $wpdb->get_var(
        "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_se_block_order' LIMIT 1"
    );
    if ( ! $has_order ) return;

    $unsort_url = wp_nonce_url(
        admin_url( 'edit.php?post_type=sitewide_block&se_block_unsort=1' ),
        'se_block_unsort'
    );
    printf(
        '<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
        esc_html__( 'Blocks are displayed in custom order.', 'se-sitewide-block' ),
        esc_url( $unsort_url ),
        esc_html__( 'Unsort', 'se-sitewide-block' )
    );
} );

// Enqueue SortableJS and inline script on the sitewide_block list screen
add_action( 'admin_enqueue_scripts', function ( string $hook ) {
    if ( $hook !== 'edit.php' ) return;
    if ( ( $_GET['post_type'] ?? '' ) !== 'sitewide_block' ) return;

    wp_enqueue_script(
        'sortablejs',
        plugins_url( 'assets/vendor/sortable.min.js', __FILE__ ),
        [],
        '1.15.6',
        true
    );

    $inline = sprintf(
        'window.seBlockSort = %s;',
        wp_json_encode( [
            'action' => 'se_block_save_order',
            'nonce'  => wp_create_nonce( 'se_block_sort' ),
        ] )
    );
    wp_add_inline_script( 'sortablejs', $inline, 'before' );

    wp_add_inline_script( 'sortablejs', <<<'JS'
(function(){
    var list = document.getElementById('the-list');
    if (!list) return;

    // Inject drag handles
    list.querySelectorAll('tr').forEach(function(tr){
        var td = document.createElement('td');
        td.className = 'se-block-drag-handle';
        td.innerHTML = '<span class="dashicons dashicons-menu"></span>';
        tr.prepend(td);
    });

    // Add blank header/footer cells to keep columns aligned
    ['thead','tfoot'].forEach(function(tag){
        var row = document.querySelector('.wp-list-table ' + tag + ' tr');
        if (row) {
            var th = document.createElement('th');
            th.style.width = '32px';
            row.prepend(th);
        }
    });

    Sortable.create(list, {
        animation: 150,
        handle: '.se-block-drag-handle',
        ghostClass: 'se-block-sort-ghost',
        onEnd: function(){
            var order = Array.from(list.querySelectorAll('tr[id^="post-"]'))
                .map(function(tr){ return tr.id.replace('post-',''); });
            var body = new FormData();
            body.append('action', seBlockSort.action);
            body.append('_ajax_nonce', seBlockSort.nonce);
            order.forEach(function(id){ body.append('order[]', id); });
            fetch(ajaxurl, { method:'POST', body: body });
        }
    });
})();
JS
    );

    // Inline styles matching theme pattern
    wp_add_inline_style( 'dashicons', <<<'CSS'
.se-block-drag-handle { width: 32px; padding: 8px 4px !important; cursor: grab; color: #999; text-align: center; }
.se-block-drag-handle:hover { color: #444; }
.se-block-sort-ghost { opacity: 0.4; background: #f0f6fc; }
CSS
    );
} );

/* ------------------------------------------------------------------ */
/*  Visibility check                                                  */
/* ------------------------------------------------------------------ */

/**
 * Determine the current page type key(s) for visibility matching.
 */
function se_block_current_page_types(): array {
    $types = [];

    if ( is_front_page() ) $types[] = 'front_page';
    if ( is_home() )       $types[] = 'posts_page';
    if ( is_search() )     $types[] = 'search';
    if ( is_404() )        $types[] = '404';

    if ( is_singular() ) {
        $pt = get_post_type();
        if ( $pt === 'post' )     $types[] = 'single_post';
        elseif ( $pt === 'page' ) $types[] = 'single_page';
        else                      $types[] = 'single_' . $pt;
    }

    if ( is_category() )  $types[] = 'archive_category';
    if ( is_tag() )       $types[] = 'archive_post_tag';
    if ( is_date() )      $types[] = 'archive_date';
    if ( is_author() )    $types[] = 'archive_author';

    if ( is_post_type_archive() ) {
        $types[] = 'archive_' . get_query_var( 'post_type' );
    }

    if ( is_tax() ) {
        $tax = get_queried_object()->taxonomy ?? '';
        if ( $tax ) $types[] = 'archive_tax_' . $tax;
    }

    return $types;
}

/**
 * Check whether a block passes visibility rules for the current page.
 */
function se_block_is_visible( int $block_id ): bool {
    // Page types
    $page_types = array_filter( (array) get_post_meta( $block_id, '_block_page_types', true ) );
    if ( ! empty( $page_types ) ) {
        $current = se_block_current_page_types();
        if ( ! array_intersect( $page_types, $current ) ) {
            return false;
        }
    }

    // Hide on post IDs (checked first — hide overrides show)
    $hide_ids = array_filter( array_map( 'intval', explode( ',', get_post_meta( $block_id, '_block_hide_ids', true ) ?: '' ) ) );
    if ( $hide_ids ) {
        $current_id = get_queried_object_id();
        if ( $current_id && in_array( $current_id, $hide_ids, true ) ) {
            return false;
        }
    }

    // Show on post IDs
    $show_ids = array_filter( array_map( 'intval', explode( ',', get_post_meta( $block_id, '_block_show_ids', true ) ?: '' ) ) );
    if ( $show_ids ) {
        $current_id = get_queried_object_id();
        if ( ! $current_id || ! in_array( $current_id, $show_ids, true ) ) {
            return false;
        }
    }

    // Hide on terms (overrides show)
    $hide_terms = array_filter( (array) get_post_meta( $block_id, '_block_hide_terms', true ) );
    if ( $hide_terms && se_block_matches_terms( $hide_terms ) ) {
        return false;
    }

    // Show on terms
    $show_terms = array_filter( (array) get_post_meta( $block_id, '_block_show_terms', true ) );
    if ( $show_terms && ! se_block_matches_terms( $show_terms ) ) {
        return false;
    }

    return true;
}

/**
 * Check if the current page matches any of the given taxonomy/term rules.
 */
function se_block_matches_terms( array $rules ): bool {
    foreach ( $rules as $rule ) {
        if ( ! is_array( $rule ) ) continue;
        $tax   = $rule['taxonomy'] ?? '';
        $raw_terms = $rule['terms'] ?? [];
        if ( is_string( $raw_terms ) ) {
            $slugs = array_filter( array_map( 'trim', explode( ',', $raw_terms ) ) );
        } else {
            $slugs = array_filter( (array) $raw_terms );
        }
        if ( ! $tax || empty( $slugs ) ) continue;

        // On a taxonomy archive, check the queried term
        if ( is_tax( $tax, $slugs ) || is_category( $slugs ) || is_tag( $slugs ) ) {
            return true;
        }

        // On a singular page, check if the post has any of these terms
        if ( is_singular() ) {
            $post_id = get_queried_object_id();
            if ( $post_id && has_term( $slugs, $tax, $post_id ) ) {
                return true;
            }
        }
    }
    return false;
}

/* ------------------------------------------------------------------ */
/*  Active-block query helper                                         */
/* ------------------------------------------------------------------ */

function se_block_get_active( string $location ): array {
    $blocks = get_posts( [
        'post_type'      => 'sitewide_block',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => [
            [
                'key'     => '_block_location',
                'value'   => $location,
                'compare' => 'LIKE',
            ],
        ],
    ] );

    $now     = current_time( 'timestamp' );
    $active  = [];

    foreach ( $blocks as $block ) {
        $type = get_post_meta( $block->ID, '_block_schedule_type', true ) ?: 'always';

        if ( $type === 'always' ) {
            $active[] = $block;
            continue;
        }

        $start = get_post_meta( $block->ID, '_block_start', true );
        $end   = get_post_meta( $block->ID, '_block_end', true );

        if ( $type === 'scheduled' ) {
            $in_range = true;
            if ( $start && strtotime( $start ) > $now ) $in_range = false;
            if ( $end   && strtotime( $end )   < $now ) $in_range = false;
            if ( $in_range ) $active[] = $block;
            continue;
        }

        if ( $type === 'recurring' ) {
            // Check optional bounding dates
            if ( $start && strtotime( $start ) > $now ) continue;
            if ( $end   && strtotime( $end . ' 23:59:59' ) < $now ) continue;

            // Check day of week
            $rec_days = (array) get_post_meta( $block->ID, '_block_recurring_days', true );
            $today    = strtolower( current_time( 'D' ) ); // Mon → mon
            if ( ! empty( $rec_days ) && ! in_array( $today, $rec_days, true ) ) continue;

            // Check time window
            $rec_start = get_post_meta( $block->ID, '_block_recurring_time_start', true );
            $rec_end   = get_post_meta( $block->ID, '_block_recurring_time_end', true );
            $current_time = current_time( 'H:i' );
            if ( $rec_start && $current_time < $rec_start ) continue;
            if ( $rec_end   && $current_time > $rec_end )   continue;

            $active[] = $block;
        }
    }

    // Visibility filter
    $active = array_filter( $active, fn( $b ) => se_block_is_visible( $b->ID ) );

    // Sort by saved order
    usort( $active, function ( $a, $b ) {
        $oa = (int) get_post_meta( $a->ID, '_se_block_order', true );
        $ob = (int) get_post_meta( $b->ID, '_se_block_order', true );
        return $oa - $ob;
    } );

    return $active;
}

/* ------------------------------------------------------------------ */
/*  Render helper                                                     */
/* ------------------------------------------------------------------ */

function se_block_render( string $location ): void {
    $blocks = se_block_get_active( $location );
    if ( empty( $blocks ) ) return;

    foreach ( $blocks as $block ) {
        printf(
            '<div class="se-sitewide-block se-sitewide-block--%s" data-block-id="%d">%s</div>',
            esc_attr( $location ),
            $block->ID,
            apply_filters( 'the_content', $block->post_content )
        );
    }
}

/* ------------------------------------------------------------------ */
/*  Frontend hooks — insert active blocks at each location            */
/* ------------------------------------------------------------------ */

// site_top — earliest opportunity after <body>
add_action( 'wp_body_open', function () {
    se_block_render( 'site_top' );
} );

add_action( 'loop_start', function ( WP_Query $query ) {
    if ( ! $query->is_main_query() || is_admin() ) return;
    static $fired = false;
    if ( $fired ) return;
    $fired = true;
    se_block_render( 'before_content' );
}, 1 );

add_action( 'loop_end', function ( WP_Query $query ) {
    if ( ! $query->is_main_query() || is_admin() ) return;
    static $fired = false;
    if ( $fired ) return;
    $fired = true;
    se_block_render( 'after_content' );
}, 999 );

add_action( 'get_footer', function () {
    se_block_render( 'before_footer' );
} );

// Custom hooks — register actions for each published block's custom hooks
add_action( 'wp', function () {
    if ( is_admin() ) return;

    $blocks = get_posts( [
        'post_type'      => 'sitewide_block',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_key'       => '_block_custom_hooks',
    ] );

    foreach ( $blocks as $block ) {
        $hooks = (array) get_post_meta( $block->ID, '_block_custom_hooks', true );
        foreach ( $hooks as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['hook'] ) ) continue;
            $hook     = $entry['hook'];
            $priority = (int) ( $entry['priority'] ?? 10 );
            $block_id = $block->ID;
            add_action( $hook, function () use ( $block_id ) {
                $block = get_post( $block_id );
                if ( ! $block || $block->post_status !== 'publish' ) return;
                if ( ! se_block_is_visible( $block_id ) ) return;

                // Check schedule
                $type = get_post_meta( $block_id, '_block_schedule_type', true ) ?: 'always';
                $now  = current_time( 'timestamp' );

                if ( $type === 'scheduled' ) {
                    $start = get_post_meta( $block_id, '_block_start', true );
                    $end   = get_post_meta( $block_id, '_block_end', true );
                    if ( $start && strtotime( $start ) > $now ) return;
                    if ( $end   && strtotime( $end )   < $now ) return;
                }

                if ( $type === 'recurring' ) {
                    $start = get_post_meta( $block_id, '_block_start', true );
                    $end   = get_post_meta( $block_id, '_block_end', true );
                    if ( $start && strtotime( $start ) > $now ) return;
                    if ( $end   && strtotime( $end . ' 23:59:59' ) < $now ) return;
                    $rec_days = (array) get_post_meta( $block_id, '_block_recurring_days', true );
                    $today    = strtolower( current_time( 'D' ) );
                    if ( ! empty( $rec_days ) && ! in_array( $today, $rec_days, true ) ) return;
                    $rec_start    = get_post_meta( $block_id, '_block_recurring_time_start', true );
                    $rec_end      = get_post_meta( $block_id, '_block_recurring_time_end', true );
                    $current_time = current_time( 'H:i' );
                    if ( $rec_start && $current_time < $rec_start ) return;
                    if ( $rec_end   && $current_time > $rec_end )   return;
                }

                printf(
                    '<div class="se-sitewide-block se-sitewide-block--custom-hook" data-block-id="%d">%s</div>',
                    $block_id,
                    apply_filters( 'the_content', $block->post_content )
                );
            }, $priority );
        }
    }
} );
