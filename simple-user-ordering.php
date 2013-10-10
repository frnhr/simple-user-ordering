<?php
/**
Plugin Name: Simple User Ordering
Plugin URI: http://changeset.hr/
Description: Order your users using drag and drop on the built in page list.
Version: 0.2
Author: Fran Hrženjak
Author URI: http://changeset.hr
License: GPLv2 or later
 */


class SimpleUserOrdering_Plugin {

    static protected $instance = NULL;

    static public function load() {
        if ( empty( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    protected function should_kick_in() {
        $screen = get_current_screen();
        if ( ! is_admin() ) return FALSE;
        if ( $screen->id !== 'users' ) return FALSE;
        if ( isset( $_GET['orderby'] ) ) return FALSE;
        return TRUE;
    }

    protected function __construct() {
        add_action( 'pre_user_query',                           array( $this, 'alter_user_search' )                     );
        add_action( 'admin_head',                               array( $this, 'sort_users_js' )                         );
        add_action( 'wp_ajax_my_action',                        array( $this, 'ajax_update' )                           );
        add_action( 'user_register',                            array( $this, 'registration_save' )                     );
        add_filter( 'manage_users_columns',                     array( $this, 'add_user_id_column' )                    );
        add_action( 'manage_users_custom_column',               array( $this, 'show_menu_order_column_content' ), 10, 3 );
        add_filter( 'get_user_option_manageuserscolumnshidden', array( $this, 'hide_column' )                           );
        add_filter( 'views_users' ,                             array( $this, 'sort_by_order_link' )                    );

    }



    function alter_user_search($qry) {

        if ( ! $this->should_kick_in() ) return;

        // doing outer join to make sure to include any users that might be missing 'menu_order' value in wp_usermeta
        /** @var $qry WP_User_Query */
        $qry->query_where = " LEFT OUTER JOIN wp_usermeta AS wp_usermeta_order ON (wp_users.ID = wp_usermeta_order.user_id AND wp_usermeta_order.meta_key = 'menu_order') " . $qry->query_where;
        $qry->query_orderby = preg_replace('/ORDER BY (.*) (ASC|DESC)/',"ORDER BY CAST(wp_usermeta_order.meta_value AS UNSIGNED) ".$qry->get('order') ,$qry->query_orderby);

        wp_enqueue_script( 'jquery-ui', 'http://code.jquery.com/ui/1.10.3/jquery-ui.js', array('jquery'), FALSE, TRUE );
        wp_enqueue_style( 'jquery-ui-css', 'http://code.jquery.com/ui/1.10.3/themes/smoothness/jquery-ui.css' );
    }


    function sort_users_js() {

        if ( ! $this->should_kick_in() ) return;
        ?>
            <script type="text/javascript">
                jQuery(function($){

                    jQuery.fn.reverse = [].reverse;

                    var update_orders = function() {

                        var $the_list = $('#the-list');
                        $the_list.addClass('updating').sortable( "disable" ).css('cursor', 'no-drop');

                        // normalize values:
                        // force values to int (make "0" if missing)
                        $the_list.find('tr').each(function(i, item){
                            var $tr = $(item);
                            var $menu_order_td = $tr.find('.column-menu_order');

                            $menu_order_td.text( parseInt( '0'+$menu_order_td.text() ) );
                        });


                        // do a one-pass bubble sort and mark updated needed
                        var $last_tr = null;
                        $the_list.find('tr').each(function(i, item){
                            var $tr = $(item);
                            var $menu_order_td = $tr.find('.column-menu_order');
                            if ( $last_tr !== null ) {
                                var $last_menu_order_td = $last_tr.find('.column-menu_order');
                                if ( parseInt( $last_menu_order_td.text() ) >= parseInt( $menu_order_td.text() ) ) {
                                    var tmp = $menu_order_td.text();
                                    $menu_order_td.text( $last_menu_order_td.text() );
                                    $last_menu_order_td.text( tmp );
                                    $last_tr.addClass( 'update_menu_order_needed');
                                    $tr.addClass( 'update_menu_order_needed' );
                                }
                            }
                            $last_tr = $tr;
                        });


                        // do a one-pass bubble sort in the oposite direction and mark updated needed
                        $last_tr = null;
                        $the_list.find('tr').reverse().each(function(i, item){
                            var $tr = $(item);
                            var $menu_order_td = $tr.find('.column-menu_order');
                            if ( $last_tr !== null ) {
                                var $last_menu_order_td = $last_tr.find('.column-menu_order');
                                if ( parseInt( $last_menu_order_td.text() ) < parseInt( $menu_order_td.text() ) ) {
                                    var tmp = $menu_order_td.text();
                                    $menu_order_td.text( $last_menu_order_td.text() );
                                    $last_menu_order_td.text( tmp );
                                    $last_tr.addClass( 'update_menu_order_needed');
                                    $tr.addClass( 'update_menu_order_needed' );
                                }
                            }
                            $last_tr = $tr;
                        });

                        // make sure values are unique, increase where needed
                        $last_tr = null;
                        $the_list.find('tr').each(function(i, item){
                            var $tr = $(item);
                            var $menu_order_td = $tr.find('.column-menu_order');
                            if ( $last_tr !== null ) {
                                var $last_menu_order_td = $last_tr.find('.column-menu_order');
                                // do a one-pass bubble sort
                                if ( parseInt( $last_menu_order_td.text() ) === parseInt( $menu_order_td.text() ) ) {
                                    $menu_order_td.text( parseInt( $last_menu_order_td.text() ) + 1 );
                                    $tr.addClass( 'update_menu_order_needed' ).css('font-weight', 'bold');
                                }
                                $menu_order_td.text( parseInt( '0'+$menu_order_td.text() ) );
                            }
                            $last_tr = $tr;
                        });

                        // collect data for AJAX based on update_menu_order_needed class
                        // also add spinner elements
                        var update_data = {};
                        $the_list.find('tr.update_menu_order_needed').each(function(i, item){
                            var $tr = $(item);
                            var user_id = $tr.attr('id').replace('user-', '');
                            var $menu_order_td = $tr.find('.column-menu_order');
                            update_data[user_id] = $menu_order_td.text();
                            $tr.find('th:first-child')
                                .filter(':not(:has(.relative_wrap))')
                                .wrapInner('<div class="relative_wrap" style="position: relative;"></div>')
                                .end()
                                .find('.spinner')
                                .remove()
                                .end()
                                .find('.relative_wrap')
                                .append('<span class="spinner" style="display: block; position: absolute; top: -5px; left: 1px;"></span>')
                            ;
                        });

                        var data = {
                            action: 'my_action',
                            update_data: update_data
                        };

                        // since 2.8 ajaxurl is always defined in the admin header and points to admin-ajax.php
                        //noinspection JSUnresolvedVariable
                        $.post(ajaxurl, data, function() {
                            $the_list
                                .find('.spinner')
                                .remove()
                                .end()
                                .find('.update_menu_order_needed')
                                .removeClass('update_menu_order_needed')
                                .end()
                                .removeClass('updating')
                                .sortable( "enable" )
                                .css('cursor', 'move')
                            ;
                        });

                    };

                    $('#the-list:not(.ui-sortable)')
                        .css( 'cursor', 'move' )
                        .sortable({
                            start: function(event, ui) {
                                ui.item.addClass('alternate');
                                ui.item.width( ui.helper.width() );
                                ui.helper.find('th, td').each(function(i, item){
                                    var w = ui.item.parent().parent().find('thead').find('th').eq(i).width();
                                    $(item).width( w );
                                });
                                ui.placeholder.css('display', 'table-row');
                                ui.placeholder.height( ui.helper.height() );
                                ui.placeholder.width( ui.helper.width() );
                                ui.item.parent().parent().parent().find('tr:not(.ui-sortable-helper)').find('th:hidden, td:hidden')
                                    .css({
                                        'visibility' : 'visible',
                                        'display' : 'table-cell',
                                        'overflow': 'hidden',
                                        'width': 0,
                                        'max-width': 0,
                                        'padding' : 0,
                                        'white-space': 'nowrap'
                                    })
                                ;
                            },
                            update: function() {
                                $(this).find('tr')
                                    .removeClass('alternate')
                                    .filter(':even')
                                    .addClass('alternate')
                                ;
                                update_orders();
                            }
                        })
                    ;
                });
            </script>
        <?php
    }



    function ajax_update() {
        $update_data = (array) $_POST['update_data'];

        foreach ( $update_data as $user_id => $new_order ){
            update_user_meta( $user_id, 'menu_order', (int) $new_order );
        }

        die(); // this is required to return a proper result
    }



    function registration_save($user_id) {
        $counts= count_users();
        update_user_meta( $user_id, 'menu_order', $counts['total_users'] );
    }


    function add_user_id_column($columns) {
        $columns['menu_order'] = 'Order';
        return $columns;
    }
    function show_menu_order_column_content($value, $column_name, $user_id) {

        if ( 'menu_order' == $column_name )
            return get_the_author_meta( 'menu_order', $user_id );
        return $value;
    }


    function hide_column( $result ) {
        $result = (array) $result;
        $result[] = 'menu_order';
        return $result;
    }

    function sort_by_order_link( $views ) {
        $orderby = isset( $_GET['orderby'] ) ? $_GET['orderby'] : '';
        $class = empty( $orderby ) ? 'current' : '';
        $query_string = remove_query_arg( array( 'orderby', 'order' ) );
        $views['byorder'] = '<a href="'. $query_string . '" class="' . $class . '">Sort by Order</a>';
        return $views;
    }


}

global $simple_user_ordering_plugin;
$simple_user_ordering_plugin = SimpleUserOrdering_Plugin::load();





