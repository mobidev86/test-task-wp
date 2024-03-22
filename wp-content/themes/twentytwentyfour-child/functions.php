<?php

/**
 * Register all hooks
 */
add_action('admin_enqueue_scripts', 'custom_admin_scripts_styles');
add_action('woocommerce_thankyou', 'store_tag_ids_place_order');
add_action('admin_menu', 'woocommerce_order_graph_page');
add_action('wp', 'custom_custom_cron_job');
add_action('custom_woocommerce_send_email_digest', 'custom_generate_email_digest');
add_filter('cron_schedules', 'custom_check_every_24_hours');


/**
 * Enqueue custom style and scripts
 */
function custom_admin_scripts_styles()
{
    wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js');
    wp_enqueue_style('custom', get_stylesheet_directory_uri() . '/assets/css/admin/custom.css');
}

/**
 * Added custom pages for reports
 */
function woocommerce_order_graph_page()
{
    add_submenu_page(
        'woocommerce',
        'Order Tags Report',
        'Order Tags Report',
        'manage_options',
        'order-tags-report',
        'render_order_graph'
    );

    add_submenu_page(
        'woocommerce',
        'Sales Products Tracking System',
        'Sales Products Tracking System',
        'manage_options',
        'sales-products-tracking-system',
        'render_sales_products_tracking_system'
    );

    add_submenu_page(
        'woocommerce',
        'Sales Tags Tracking System',
        'Sales Tags Tracking System',
        'manage_options',
        'sales-tags-tracking-system',
        'render_sales_tags_tracking_system'
    );
}

/**
 *  Store tag ids on place order
 * */
function store_tag_ids_place_order($order_id)
{
    $order = wc_get_order($order_id);
    $alltagsarr = [];
    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();
        $tags = $product->get_tag_ids();
        $alltagsarr = array_merge($alltagsarr, $tags);
    }
    if (!empty ($alltagsarr)) {
        $alltagsarr = array_unique($alltagsarr);
        $order->update_meta_data('_tag_ids', implode(',', $alltagsarr));
        $order->save();
    }
}

/**
 *  Get order data for tags
 * */
function get_order_data_for_graph()
{
    $orders_for_listing = [];
    if (isset ($_POST['tag_type']) && $_POST['tag_type'] == 'current') {
        $product_tag_args = array(
            'taxonomy' => 'product_tag',
            'orderby' => 'name',
            'order' => 'ASC',
            'hide_empty' => false,
        );
        $product_tags = new WP_Term_Query($product_tag_args);
        foreach ($product_tags->get_terms() as $product_tag) {
            $all_product_ids = get_posts(
                array(
                    'post_type' => 'product',
                    'numberposts' => -1,
                    'post_status' => 'publish',
                    'fields' => 'ids',
                    'tax_query' => array(
                        array(
                            'taxonomy' => 'product_tag',
                            'field' => 'slug',
                            'terms' => $product_tag->slug,
                            'operator' => 'IN',
                        )
                    ),
                )
            );
            foreach ($all_product_ids as $id) {
                $order_ids = get_orders_id_from_product_id($id);
                $total_orders = count($order_ids);
                if (!isset ($order_data[$product_tag->name])) {
                    $order_data[$product_tag->name] = 0;
                }
                $order_data[$product_tag->name] += $total_orders;
                $orders_for_listing = array_merge($orders_for_listing, $order_ids);
            }
        }
    } else {

        $args = array('limit' => -1, 'type' => 'shop_order');
        $orders = wc_get_orders($args);

        $order_data = array();
        $time_of_sale_tags = array();
        foreach ($orders as $order) {
            $tag_ids = $order->get_meta('_tag_ids');
            if (!empty ($tag_ids)) {
                $tag_ids_arr = explode(',', $tag_ids);
                foreach ($tag_ids_arr as $tag_id) {
                    $order_data[get_term($tag_id)->name]++;
                    $orders_for_listing[] = $order->get_id();
                }
            }
        }
    }

    $order_data_arr = [
        'orders_for_listing' => array_unique($orders_for_listing),
        'order_data' => $order_data
    ];
    return $order_data_arr;
}

/**
 * Render Order Tags Report
 */
function render_order_graph()
{
    echo ('
    <div class="wrap">
        <h2>Order Tags Report</h2>

        <div class="wrap-filters">
        <form action="" method="post">
            <label for="tags" class="ltags">Tags:</label>
            <input type="radio" id="at_the_time_of_sale" name="tag_type" value="old" ' . ((isset ($_POST['tag_type']) && $_POST['tag_type'] == 'current') ? '' : 'checked') . '>
            <label for="at_the_time_of_sale">At the time of sale</label>
            <input type="radio" id="the_current_tags" name="tag_type" value="current" ' . ((isset ($_POST['tag_type']) && $_POST['tag_type'] == 'current') ? 'checked' : '') . '>
            <label for="the_current_tags">The current tags</label>
            <button type="submit" class="button-primary">Get Report</button>
        </form>
        </div>
    </div>
    ');
    $order_data_arr = get_order_data_for_graph();
    echo '<div class="order-data-wrap">';
        echo render_sales_order_graph($order_data_arr['order_data']);
        ?>
        <div class="table">
            <h2 class="orders">Orders</h2>
            <table class='wp-list-table widefat fixed striped table-view-list tags ui-sortable'>
                <thead class="thead">
                    <tr>
                        <td>Order</td>
                        <td>Date</td>
                        <td>Status</td>
                        <td>Total</td>
                    </tr>
                </thead>
                <?php
                if (!empty ($order_data_arr['orders_for_listing'])) {
                    foreach ($order_data_arr['orders_for_listing'] as $order_id) {
                        $order = wc_get_order($order_id);
                        $billing_first_name = $order->get_billing_first_name();
                        $billing_last_name = $order->get_billing_last_name();
                        $edit_order_url = admin_url('post.php?post=' . $order_id . '&action=edit');
                        $order_date = $order->get_date_created();
                        $formatted_order_date = $order_date ? wc_format_datetime($order_date) : 'N/A';
                        $order_status = $order->get_status();
                        $order_total = $order->get_total();
                        echo "<tr>
                            <td>
                                <a href='" . $edit_order_url . "'>#" . $order_id . " " . $billing_first_name . " " . $billing_last_name . "</a>
                            </td>
                            <td>" . $formatted_order_date . "</td>
                            <td>" . $order_status . "</td>
                            <td>" . wc_price($order_total) . "</td>
                        </tr>";
                    }
                } else {
                    echo "<tr>
                        <td colspan='4'>No records found</td>
                    </tr>";
                }
                ?>
            </table>
        </div>
        <?php
    echo '</div>';
}

/**
 * Render Sales Products Tracking System
 */
function render_sales_products_tracking_system()
{
    echo ('
    <div class="wrap">
        <h2>Sales Products Tracking System</h2>
    </div>
    ');
    $args = array('limit' => -1, 'type' => 'shop_order');
    $orders = wc_get_orders($args);

    $common_products = array();
    foreach ($orders as $order) {
        $items = $order->get_items();
        $product_ids = array();
        foreach ($items as $item) {
            $product_id = $item->get_product_id();
            $product_ids[] = $product_id;
        }

        $product_combinations = array();
        $num_items = count($product_ids);
        for ($i = 0; $i < $num_items; $i++) {
            for ($j = $i + 1; $j < $num_items; $j++) {
                $product_combinations[] = array($product_ids[$i], $product_ids[$j]);
            }
        }

        foreach ($product_combinations as $combination) {
            sort($combination);
            $key = implode('-', $combination);
            if (!isset ($common_products[$key])) {
                $common_products[$key] = 0;
            }
            $common_products[$key]++;
        }
    }

    $order_data = [];
    foreach ($common_products as $key => $common_product) {
        $product_ids = explode('-', $key);
        $order_data[get_the_title($product_ids[0]) . ' - ' . get_the_title($product_ids[1])] = $common_product;
    }

    echo '<div class="order-data-wrap">';
        echo render_sales_order_graph($order_data);
    echo '</div>';
}

/**
 * Render Sales Tags Tracking System
 */
function render_sales_tags_tracking_system()
{
    echo ('
    <div class="wrap">
        <h2>Sales Tags Tracking System</h2>
    </div>
    ');
    $args = array('limit' => -1, 'type' => 'shop_order', 'meta_key' => '_tag_ids');
    $orders = wc_get_orders($args);

    $common_tags = array();
    foreach ($orders as $order) {
        $tag_ids = explode(',', $order->get_meta('_tag_ids'));
        $tag_combinations = array();
        $num_items = count($tag_ids);
        for ($i = 0; $i < $num_items; $i++) {
            for ($j = $i + 1; $j < $num_items; $j++) {
                $tag_combinations[] = array($tag_ids[$i], $tag_ids[$j]);
            }
        }

        foreach ($tag_combinations as $combination) {
            sort($combination);
            $key = implode('-', $combination);
            if (!isset ($common_tags[$key])) {
                $common_tags[$key] = 0;
            }
            $common_tags[$key]++;
        }
    }

    $order_data = [];
    foreach ($common_tags as $key => $common_tag) {
        $tag_ids = explode('-', $key);
        $order_data[get_term($tag_ids[0])->name . ' - ' . get_term($tag_ids[1])->name] = $common_tag;
    }

    echo '<div class="order-data-wrap">';
        echo render_sales_order_graph($order_data);
    echo '</div>';
}

/**
 * Render Sales Order Graph
 */
function render_sales_order_graph($order_data){
    $labels = json_encode(array_keys($order_data));
    $values = json_encode(array_values($order_data));
    ?>
    <canvas id="orderGraph" width="400" height="200" class="canvas"></canvas>
    <script>
        var ctx = document.getElementById('orderGraph').getContext('2d');
        var myChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo $labels; ?>,
                datasets: [{
                    label: 'Orders',
                    data: <?php echo $values; ?>,
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                    y: {
                        ticks: {
                            callback: function (value, index, values) {
                                if (value % 1 === 0) {
                                    return value;
                                } else {
                                    return '';
                                }
                            }
                        }
                    }
                }
            }
        });
    </script>
    <?php
}

/**
 * Get Orders ID from product ID
 */
function get_orders_id_from_product_id($product_id, $args = array())
{
    global $wpdb;

    $table_posts = $wpdb->prefix . "posts";
    $table_items = $wpdb->prefix . "woocommerce_order_items";
    $table_itemmeta = $wpdb->prefix . "woocommerce_order_itemmeta";

    $orders_ids = $wpdb->get_col(
        "
        SELECT $table_items.order_id
        FROM $table_itemmeta, $table_items, $table_posts
        WHERE  $table_items.order_item_id = $table_itemmeta.order_item_id
        AND $table_items.order_id = $table_posts.ID
        AND $table_itemmeta.meta_key LIKE '_product_id'
        AND $table_itemmeta.meta_value LIKE '$product_id'
        ORDER BY $table_items.order_item_id DESC"
    );
    
    $orders_ids = array_unique($orders_ids);
    return $orders_ids;
}

/**
 * Set the email html content type
 */
function email_status_set_html_content_type()
{
    return 'text/html';
}

/**
 * Schedule cron for send daily emails
 */
function custom_check_every_24_hours($schedules)
{
    $schedules['every_twentyfour_hours'] = array(
        'interval' => 86400,
        'display' => __('Every 24 hours'),
    );
    return $schedules;
}

/**
 * Schedule cron for send daily emails
 */
function custom_custom_cron_job()
{
    if (!wp_next_scheduled('custom_woocommerce_send_email_digest')) {
        wp_schedule_event(time(), 'every_twentyfour_hours', 'custom_woocommerce_send_email_digest');
    }
}

/**
 * Generate the email template
 */
function custom_generate_email_digest()
{
    $yesterday = date('Y-m-d', strtotime('-1 days'));
    $args = array(
        'date_created' => $yesterday,
        'limit' => -1,
    );
    $orders = wc_get_orders($args);

    if (count($orders)) {
        $orders_by_status = array();
        foreach ($orders as $order) {
            if ('shop_order_refund' != $order->get_type()) {
                $orders_by_status[$order->get_status()][] = sprintf(
                    '<li style="margin-bottom: 5px;"><a style="text-decoration: none;color: #000;" href="%s">%s</a> %s %s for <span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">%s -  %s</span></bdi></span></li>%s',
                    get_edit_post_link($order->get_id()),
                    $order->get_id(),
                    $order->get_billing_first_name(),
                    $order->get_billing_last_name(),
                    $order->get_formatted_order_total(),
                    wc_get_order_status_name($order->get_status()),
                    "\n"
                );
            }
        }

        $subject = 'Orders received ' . date('l j F Y', strtotime('-1 days'));
        $mail_body = '<table border="0" cellpadding="0" cellspacing="0" height="100%" width="100%" style="background: #fafafa; padding: 100px 0; font-family: arial">
            <tr>
                <td align="center" valign="top">
                    <table border="0" cellpadding="0" cellspacing="0" width="600" style="background: #fff;">
                        <tr>
                            <td style="color: #000; padding: 20px;">
                            <h1 style="margin: 10px 0 0; font-size: 25px;">' . $subject . '</h1>
                            </td>
                        </tr>';

                        $status = 'cancelled';
                        if (array_key_exists($status, $orders_by_status)) {
                            $mail_body .= '<tr>
                                <td style="color: #000; padding: 20px;">
                                    <h2 style="margin: 0;font-size: 20px;">' . wc_get_order_status_name($status) . ' orders</h2>
                                </td>
                            </tr>
                            <tr>
                                <td style="color: #000;">
                                    <ul style="margin-bottom: 20px;">' . implode('', $orders_by_status[$status]) . '</ul>
                                </td>
                            </tr>';
                        }
                        $status = 'pending';
                        if (array_key_exists($status, $orders_by_status)) {
                            $mail_body .= '<tr>
                                <td style="color: #000; padding: 20px;">
                                    <h2 style="margin: 0;font-size: 20px;">' . wc_get_order_status_name($status) . ' orders</h2>
                                </td>
                            </tr>
                            <tr>
                                <td style="color: #000;">
                                    <ul style="margin-bottom: 20px;">' . implode('', $orders_by_status[$status]) . '</ul>
                                </td>
                            </tr>';
                        }

                        $important_statuses = array('cancelled', 'pending');
                        foreach (array_keys($orders_by_status) as $status) {
                            if (!in_array($status, $important_statuses)) {
                                $mail_body .= '<tr>
                                    <td style="color: #000; padding: 20px;">
                                        <h2 style="margin: 0;font-size: 20px;">' . wc_get_order_status_name($status) . ' orders</h2>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="color: #000;">
                                        <ul style="margin-bottom: 20px;">' . implode('', $orders_by_status[$status]) . '</ul>
                                    </td>
                                </tr>';
                            }
                        }

                        $args = array(
                            'post_type' => 'product',
                            'meta_key' => 'total_sales',
                            'orderby' => 'meta_value_num',
                            'posts_per_page' => 3,
                        );
                        $mail_body .= '<tr>
                            <td style="color: #000; padding: 20px;">
                                <h1 style="margin: 0; font-size: 25px;">Best Selling Products</h1>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 0 10px 20px;">
                                <table style="table-layout: fixed;">
                                    <tr>';
                                    $loop = new WP_Query($args);
                                    while ($loop->have_posts()) {
                                        $loop->the_post();
                                        global $product;
                                        $mail_body .= '<td style="padding: 0 10px; width: 33.33%; vertical-align: top;">
                                            <a style="display: block; text-decoration: none; color: #000" href="' . get_the_permalink() . '" id="id-' . get_the_id() . '" title="' . get_the_title() . '">';
                                                if (has_post_thumbnail($loop->post->ID)) {
                                                    $mail_body .= get_the_post_thumbnail($loop->post->ID, 'thumbnail', array('width' => 128, 'height' => 128));
                                                } else {
                                                    $mail_body .= '<img src="' . woocommerce_placeholder_img_src() . '" style="max-width: 100%;height: 128px;object-fit: cover;" alt="product placeholder Image" width="65" height="115" />';
                                                }
                                                $mail_body .= '<h3 style="margin: 10px 0; font-size: 18px;">' . get_the_title() . '</h3>
                                            </a>
                                        </td>';
                                    }
                                    wp_reset_query();
                                    $mail_body .= '</tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>';

        add_filter('wp_mail_content_type', 'email_status_set_html_content_type');
        $headers[] = 'From: ' . get_bloginfo('name') . ' <' . get_bloginfo('admin_email') . '>';
        wp_mail(get_bloginfo('admin_email'), $subject, $mail_body, $headers);
    }

}
?>