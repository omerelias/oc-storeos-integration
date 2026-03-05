<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OC_StoreOS_Integration {
    const OPTION_GROUP   = 'oc_storeos_integration_options_group'; 
    const OPTION_NAME    = 'oc_storeos_integration_options';
    const META_SYNCED    = '_oc_storeos_synced';
    const META_LAST_ERR  = '_oc_storeos_last_error';
    const META_LAST_SYNC = '_oc_storeos_last_sync';

    /**
     * Singleton instance.
     *
     * @var OC_StoreOS_Integration|null 
     */
    protected static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return OC_StoreOS_Integration
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        // Admin settings.
        add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // REST API.
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // Order hooks.
        add_action( 'woocommerce_new_order', array( $this, 'handle_new_order' ), 10, 1 );
        add_action( 'woocommerce_order_status_changed', array( $this, 'handle_status_changed' ), 10, 4 );

        // Make sure StoreOS-created orders have a nice, readable address
        // in the WooCommerce order screen preview, even when OC Woo Shipping
        // overrides the default formatting.
        add_filter( 'woocommerce_order_get_formatted_billing_address', array( $this, 'filter_formatted_billing_address' ), 20, 2 );
        add_filter( 'woocommerce_order_get_formatted_shipping_address', array( $this, 'filter_formatted_shipping_address' ), 20, 2 );
    }

    /**
     * Improve formatted billing address preview for StoreOS-created orders.
     *
     * @param string   $formatted The current formatted address string.
     * @param WC_Order $order     Order object.
     *
     * @return string
     */
    public function filter_formatted_billing_address( $formatted, $order ) {
        if ( ! $order instanceof WC_Order ) {
            return $formatted;
        }

        // Only touch orders that came from the external system.
        $external_id = $order->get_meta( '_oc_storeos_external_order_id', true );
        if ( '' === (string) $external_id ) {
            return $formatted;
        }

        $street  = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );
        $city    = $order->get_billing_city();
        $zip     = $order->get_billing_postcode();

        $parts = array();
        if ( '' !== $street ) {
            $parts[] = $street;
        }
        if ( '' !== $city ) {
            $parts[] = $city;
        }
        if ( '' !== $zip ) {
            $parts[] = $zip;
        }

        if ( empty( $parts ) ) {
            return $formatted;
        }

        return implode( ', ', $parts );
    }

    /**
     * Improve formatted shipping address preview for StoreOS-created orders.
     *
     * @param string   $formatted The current formatted address string.
     * @param WC_Order $order     Order object.
     *
     * @return string
     */
    public function filter_formatted_shipping_address( $formatted, $order ) {
        if ( ! $order instanceof WC_Order ) {
            return $formatted;
        }

        $external_id = $order->get_meta( '_oc_storeos_external_order_id', true );
        if ( '' === (string) $external_id ) {
            return $formatted;
        }

        $street  = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
        $city    = $order->get_shipping_city();
        $zip     = $order->get_shipping_postcode();

        // Fallback to billing if shipping fields are empty.
        if ( '' === $street && '' === $city && '' === $zip ) {
            $street = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );
            $city   = $order->get_billing_city();
            $zip    = $order->get_billing_postcode();
        }

        $parts = array();
        if ( '' !== $street ) {
            $parts[] = $street;
        }
        if ( '' !== $city ) {
            $parts[] = $city;
        }
        if ( '' !== $zip ) {
            $parts[] = $zip;
        }

        if ( empty( $parts ) ) {
            return $formatted;
        }

        return implode( ', ', $parts );
    }

    /**
     * Register REST API routes.
     */
    public function register_rest_routes() {
        register_rest_route(
            'oc-storeos/v1',
            '/orders',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'rest_create_order' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * REST callback to create a WooCommerce order from external system.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function rest_create_order( $request ) {
        if ( ! class_exists( 'WC_Order' ) ) {
            return new WP_Error(
                'oc_storeos_no_woocommerce',
                __( 'WooCommerce is not available.', 'oc-storeos-integration' ),
                array( 'status' => 500 )
            );
        }

        $data = $request->get_json_params();
        if ( empty( $data ) || ! is_array( $data ) ) {
            return new WP_Error(
                'oc_storeos_invalid_body',
                __( 'Invalid JSON payload.', 'oc-storeos-integration' ),
                array( 'status' => 400 )
            );
        }

        try {
            $order_args = array();
            if ( ! empty( $data['status'] ) && is_string( $data['status'] ) ) {
                $order_args['status'] = sanitize_key( $data['status'] );
            }

            $order = wc_create_order( $order_args );

            // 1. פרטי לקוח וחיוב
            if ( isset( $data['customer'] ) && is_array( $data['customer'] ) ) {
                $customer = $data['customer'];
                if ( ! empty( $customer['email'] ) ) $order->set_billing_email( sanitize_email( $customer['email'] ) );
                if ( ! empty( $customer['phone'] ) ) $order->set_billing_phone( sanitize_text_field( $customer['phone'] ) );
                if ( ! empty( $customer['name'] ) ) {
                    $name_parts = explode( ' ', $customer['name'], 2 );
                    $order->set_billing_first_name( sanitize_text_field( $name_parts[0] ) );
                    if ( isset( $name_parts[1] ) ) $order->set_billing_last_name( sanitize_text_field( $name_parts[1] ) );
                }
            }

            // 2. כתובת משלוח וטיפול ב-Meta נתונים
            if ( isset( $data['shippingAddress'] ) && is_array( $data['shippingAddress'] ) ) {
                $shipping = $data['shippingAddress'];

                if ( ! empty( $shipping['street'] ) ) {
                    $street_value = sanitize_text_field( $shipping['street'] );
                    $order->set_shipping_address_1( $street_value );
                }
                if ( ! empty( $shipping['city'] ) ) {
                    $city_value = sanitize_text_field( $shipping['city'] );
                    $order->set_shipping_city( $city_value );
                    if ( ! $order->get_billing_city() ) $order->set_billing_city( $city_value );
                }
                if ( ! empty( $shipping['zip'] ) ) {
                    $zip_value = sanitize_text_field( $shipping['zip'] );
                    $order->set_shipping_postcode( $zip_value );
                    if ( ! $order->get_billing_postcode() ) $order->set_billing_postcode( $zip_value );
                }

                // עיבוד רחוב ומספר בית
                $street_full = isset( $shipping['street'] ) ? sanitize_text_field( $shipping['street'] ) : '';
                $street_name = $street_full;
                $house_num   = '';

                if ( preg_match( '/^(.*)\s+(\d+[A-Za-z]?)$/u', $street_full, $matches ) ) {
                    $street_name = trim( $matches[1] );
                    $house_num   = $matches[2];
                }

                if ( '' !== $street_name ) {
                    $order->set_billing_address_1( $street_name );
                    $order->update_meta_data( '_shipping_street', $street_name );
                    $order->update_meta_data( '_billing_street', $street_name );
                }

                if ( '' !== $house_num ) {
                    $order->set_billing_address_2( $house_num );
                    $order->update_meta_data( '_shipping_house_num', $house_num );
                    $order->update_meta_data( '_billing_house_num', $house_num );
                }

                if ( ! empty( $shipping['city'] ) ) {
                    $city_name = sanitize_text_field( $shipping['city'] );
                    $order->update_meta_data( '_shipping_city_name', $city_name );
                    $order->update_meta_data( '_billing_city_name', $city_name );
                }

                // אינטגרציה עם OC Woo Shipping
                if ( function_exists( 'ocws_save_full_address_to_order' ) ) {
                    ocws_save_full_address_to_order( $order );
                    // במקרה ש-ocws משנה את ה-meta מאחורי הקלעים, נרענן את האובייקט במידת הצורך
                }
            }

            // 3. הוספת מוצרים
            if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
                foreach ( $data['items'] as $item ) {
                    $identifier = !empty( $item['sku'] ) ? (string) $item['sku'] : (string) $item['productId'];
                    $quantity   = (float) $item['quantity'];
                    if ( $quantity <= 0 ) continue;

                    $product = is_numeric( $identifier ) ? wc_get_product( (int) $identifier ) : null;
                    if ( ! $product && function_exists( 'wc_get_product_id_by_sku' ) ) {
                        $pid = wc_get_product_id_by_sku( $identifier );
                        if ( $pid ) $product = wc_get_product( $pid );
                    }

                    if ( $product ) {
                        $order->add_product( $product, $quantity );
                    }
                }
            }

            // 4. סיום ועדכון Meta חיצוני
            if ( ! empty( $data['customerNotes'] ) ) {
                $order->set_customer_note( wp_kses_post( $data['customerNotes'] ) );
            }

            if ( ! empty( $data['externalOrderId'] ) ) {
                $order->update_meta_data( '_oc_storeos_external_order_id', sanitize_text_field( (string) $data['externalOrderId'] ) );
            }

            $order->calculate_totals();
            $order->save(); // כאן הכל נשמר ב-Database בפעם אחת

            return new WP_REST_Response(
                array(
                    'success'   => true,
                    'orderId'   => $order->get_id(),
                    'orderKey'  => $order->get_order_key(),
                    'status'    => $order->get_status(),
                    'orderDate' => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
                ),
                201
            );
        } catch ( Exception $e ) {
            return new WP_Error( 'oc_storeos_order_error', $e->getMessage(), array( 'status' => 500 ) );
        }
    }
    /**
     * Register settings page under WooCommerce menu.
     */
    public function register_settings_page() {
        add_submenu_page(
            'woocommerce',
            __( 'OC StoreOS Integration', 'oc-storeos-integration' ),
            __( 'OC StoreOS Integration', 'oc-storeos-integration' ),
            'manage_woocommerce',
            'oc-storeos-integration',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register plugin settings.
     */
    public function register_settings() {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            array( $this, 'sanitize_options' )
        );

        add_settings_section(
            'oc_storeos_main_section',
            __( 'API Settings', 'oc-storeos-integration' ),
            '__return_false',
            'oc-storeos-integration'
        );

        add_settings_field(
            'api_base_url',
            __( 'API Base URL', 'oc-storeos-integration' ),
            array( $this, 'render_field_api_base_url' ),
            'oc-storeos-integration',
            'oc_storeos_main_section'
        );

        add_settings_field(
            'api_token',
            __( 'API Token / API Key', 'oc-storeos-integration' ),
            array( $this, 'render_field_api_token' ),
            'oc-storeos-integration',
            'oc_storeos_main_section'
        );

        add_settings_field(
            'site_id',
            __( 'Site ID (optional)', 'oc-storeos-integration' ),
            array( $this, 'render_field_site_id' ),
            'oc-storeos-integration',
            'oc_storeos_main_section'
        );

        add_settings_field(
            'order_status_trigger',
            __( 'Order Status Trigger', 'oc-storeos-integration' ),
            array( $this, 'render_field_order_status_trigger' ),
            'oc-storeos-integration',
            'oc_storeos_main_section'
        );
    }

    /**
     * Sanitize options.
     *
     * @param array $input Raw input.
     *
     * @return array
     */
    public function sanitize_options( $input ) {
        $options = $this->get_options();

        if ( isset( $input['api_base_url'] ) ) {
            $options['api_base_url'] = trim( esc_url_raw( $input['api_base_url'] ) );
            $options['api_base_url'] = rtrim( $options['api_base_url'], '/' );
        }

        if ( isset( $input['api_token'] ) ) {
            $options['api_token'] = sanitize_text_field( $input['api_token'] );
        }

        if ( isset( $input['site_id'] ) ) {
            $options['site_id'] = sanitize_text_field( $input['site_id'] );
        }

        if ( isset( $input['order_status_trigger'] ) && is_array( $input['order_status_trigger'] ) ) {
            $options['order_status_trigger'] = array_map( 'sanitize_text_field', $input['order_status_trigger'] );
        } else {
            $options['order_status_trigger'] = array( 'on-hold' );
        }

        return $options;
    }

    /**
     * Get plugin options with defaults.
     *
     * @return array
     */
    public function get_options() {
        $defaults = array(
            'api_base_url'        => '',
            'api_token'           => '', 
            'site_id'             => '',
            'order_status_trigger'=> array( 'on-hold' ), 
        );
 
        $options = get_option( self::OPTION_NAME, array() ); 
        if ( ! is_array( $options ) ) { 
            $options = array();
        }

        return wp_parse_args( $options, $defaults );
    }

    /**
     * Render settings page.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $options           = $this->get_options();
        $endpoint          = '';
        $incoming_endpoint = rest_url( 'oc-storeos/v1/orders' );
        $wc_keys_url       = admin_url( 'admin.php?page=wc-settings&tab=advanced&section=keys' );

        if ( ! empty( $options['api_base_url'] ) ) {
            $endpoint = trailingslashit( $options['api_base_url'] ) . 'api/orders';
        }

        ?>
        <div class="wrap oc-storeos-settings">
            <h1><?php esc_html_e( 'OC StoreOS Integration', 'oc-storeos-integration' ); ?></h1>
            <style> 
                .oc-storeos-settings .oc-storeos-grid { 
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                    gap: 16px;
                    margin-top: 16px;
                    margin-bottom: 24px;
                }
                .oc-storeos-settings .oc-storeos-card {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    border-radius: 4px;
                    padding: 16px;
                    box-shadow: 0 1px 1px rgba(0,0,0,0.04);
                }
                .oc-storeos-settings .oc-storeos-card h2 {
                    margin-top: 0;
                    font-size: 16px;
                }
                .oc-storeos-settings code {
                    font-family: Menlo, Monaco, Consolas, "Courier New", monospace;
                }
                .oc-storeos-settings pre {
                    background: #f6f7f7;
                    border: 1px solid #dcdcde;
                    padding: 8px;
                    max-height: 260px;
                    overflow: auto;
                    font-size: 12px;
                }
                .oc-storeos-settings .oc-storeos-form-card {
                    max-width: 900px;
                }
            </style>
            <div class="oc-storeos-grid">
                <div class="oc-storeos-card">
                    <h2><?php esc_html_e( 'Outgoing orders → StoreOS', 'oc-storeos-integration' ); ?></h2>
                    <?php if ( ! empty( $endpoint ) ) : ?>
                        <p>
                            <?php esc_html_e( 'Orders are currently sent to:', 'oc-storeos-integration' ); ?>
                            <br />
                            <code><?php echo esc_html( $endpoint ); ?></code>
                        </p>
                        <p>
                            <?php esc_html_e( 'Example JSON payload sent to the external system:', 'oc-storeos-integration' ); ?>
                        </p>
                        <pre><code><?php
                            echo esc_html(
                                wp_json_encode(
                                    array(
                                        'externalOrderId' => 12345,
                                        'orderNumber'     => '12345',
                                        'source'          => 'WooCommerce',
                                        'siteId'          => 'site_001',
                                        'status'          => 'on-hold',
                                        'orderDate'       => '2026-03-05T12:30:00',
                                        'customer'        => array(
                                            'name'  => 'John Doe',
                                            'phone' => '0501234567',
                                            'email' => 'john@example.com',
                                        ),
                                        'shippingAddress' => array(
                                            'street' => 'Herzl 10',
                                            'city'   => 'Tel Aviv',
                                            'zip'    => '61000',
                                        ),
                                        'items'           => array(
                                            array(
                                                'productId' => 123,
                                                'name'      => 'Product Name',
                                                'quantity'  => 2,
                                                'unitPrice' => 50,
                                                'lineTotal' => 100,
                                            ),
                                        ),
                                        'shippingTotal'   => 20,
                                        'orderTotal'      => 120,
                                        'customerNotes'   => 'Please call before delivery',
                                    ),
                                    JSON_PRETTY_PRINT
                                )
                            );
                            ?></code></pre>
                    <?php else : ?>
                        <p>
                            <?php esc_html_e( 'Set the API Base URL below to see the full outgoing orders endpoint URL and example payload.', 'oc-storeos-integration' ); ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="oc-storeos-card">
                    <h2><?php esc_html_e( 'Incoming orders ← StoreOS', 'oc-storeos-integration' ); ?></h2>
                    <p>
                        <?php esc_html_e( 'To create orders in WooCommerce, the external system should POST to:', 'oc-storeos-integration' ); ?>
                        <br />
                        <code><?php echo esc_html( $incoming_endpoint ); ?></code>
                    </p>
                    <p>
                        <?php esc_html_e( 'Example JSON payload:', 'oc-storeos-integration' ); ?>
                    </p>
                    <pre><code><?php echo esc_html( wp_json_encode( array(
                        'status'  => 'on-hold',
                        'externalOrderId' => 'EXT-12345',
                        'customer'=> array(
                            'name'  => 'John Doe',
                            'phone' => '0501234567',
                            'email' => 'john@example.com',
                        ),
                        'shippingAddress' => array(
                            'street' => 'Herzl 10',
                            'city'   => 'Tel Aviv',
                            'zip'    => '61000',
                        ),
                        'items' => array(
                            array(
                                'sku'      => 'ABC-123',
                                'quantity' => 1,
                            ),
                        ),
                        'customerNotes' => 'Please call before delivery',
                    ), JSON_PRETTY_PRINT ) ); ?></code></pre>
                </div>
            </div>
            <div class="oc-storeos-card oc-storeos-form-card">
                <h2><?php esc_html_e( 'Integration settings', 'oc-storeos-integration' ); ?></h2>
                <form method="post" action="options.php">
                    <?php
                    settings_fields( self::OPTION_GROUP );
                    do_settings_sections( 'oc-storeos-integration' );
                    submit_button();
                    ?>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render API Base URL field.
     */
    public function render_field_api_base_url() {
        $options = $this->get_options();
        ?>
        <input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[api_base_url]"
               value="<?php echo esc_attr( $options['api_base_url'] ); ?>"
               placeholder="https://example.com" />
        <p class="description">
            <?php esc_html_e( 'Base URL of the external API (without trailing slash).', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Render API Token field.
     */
    public function render_field_api_token() {
        $options = $this->get_options();
        ?>
        <input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[api_token]"
               value="<?php echo esc_attr( $options['api_token'] ); ?>" autocomplete="off" />
        <p class="description">
            <?php esc_html_e( 'API token or key provided by the external system.', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Render Site ID field.
     */
    public function render_field_site_id() {
        $options = $this->get_options();
        ?>
        <input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[site_id]"
               value="<?php echo esc_attr( $options['site_id'] ); ?>" />
        <p class="description">
            <?php esc_html_e( 'Optional site identifier in the external system.', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Render Order Status Trigger field.
     */
    public function render_field_order_status_trigger() {
        $options      = $this->get_options();
        $selected     = isset( $options['order_status_trigger'] ) && is_array( $options['order_status_trigger'] )
            ? $options['order_status_trigger']
            : array( 'on-hold' );
        $statuses     = wc_get_order_statuses();
        ?>
        <select multiple="multiple" style="min-width:300px;height: 120px;"
                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[order_status_trigger][]">
            <?php foreach ( $statuses as $status_key => $status_label ) : ?>
                <option value="<?php echo esc_attr( substr( $status_key, 3 ) ); ?>"
                    <?php selected( in_array( substr( $status_key, 3 ), $selected, true ), true ); ?>>
                    <?php echo esc_html( $status_label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e( 'Select the order statuses that will trigger sending the order to the external system. Default: On hold.', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Handle new order event.
     *
     * @param int $order_id Order ID.
     */
    public function handle_new_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $this->maybe_send_order_to_api( $order );
    }

    /**
     * Handle order status change.
     *
     * @param int        $order_id   Order ID.
     * @param string     $old_status Old status.
     * @param string     $new_status New status.
     * @param WC_Order   $order      Order object.
     */
    public function handle_status_changed( $order_id, $old_status, $new_status, $order ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }

        if ( ! $order ) {
            return;
        }

        $options = $this->get_options();
        $triggers = isset( $options['order_status_trigger'] ) && is_array( $options['order_status_trigger'] )
            ? $options['order_status_trigger']
            : array( 'on-hold' );

        if ( in_array( $new_status, $triggers, true ) ) {
            $this->maybe_send_order_to_api( $order );
        }
    }

    /**
     * Check if order should be sent and send it.
     *
     * @param WC_Order $order Order object.
     */
    protected function maybe_send_order_to_api( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $options = $this->get_options();

        if ( empty( $options['api_base_url'] ) || empty( $options['api_token'] ) ) {
            return;
        }

        $payload = $this->build_order_payload( $order, $options );

        $this->send_order_to_api( $order, $payload, $options );
    }

    /**
     * Build order payload as JSON-ready array.
     *
     * @param WC_Order $order   Order object.
     * @param array    $options Plugin options.
     *
     * @return array
     */
    protected function build_order_payload( $order, $options ) {
        $order_id     = $order->get_id();
        $order_number = $order->get_order_number();
        $status       = $order->get_status();
        $date_created = $order->get_date_created();

        $customer_name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $customer_phone = $order->get_billing_phone();
        $customer_email = $order->get_billing_email();

        // Prefer OC Woo Shipping's enriched address data when available.
        $shipping_city = $order->get_shipping_city();
        if ( function_exists( 'ocws_get_order_shipping_city_name' ) ) {
            $ocws_city = ocws_get_order_shipping_city_name( $order );
            if ( ! empty( $ocws_city ) ) {
                $shipping_city = $ocws_city;
            }
        }

        $shipping_street_meta  = $order->get_meta( '_shipping_street', true );
        $shipping_house_meta   = $order->get_meta( '_shipping_house_num', true );
        $billing_street_meta   = $order->get_meta( '_billing_street', true );
        $billing_house_meta    = $order->get_meta( '_billing_house_num', true );

        $street_parts = array();

        if ( ! empty( $shipping_street_meta ) || ! empty( $shipping_house_meta ) ) {
            if ( ! empty( $shipping_street_meta ) ) {
                $street_parts[] = $shipping_street_meta;
            }
            if ( ! empty( $shipping_house_meta ) ) {
                $street_parts[] = $shipping_house_meta;
            }
        } elseif ( ! empty( $billing_street_meta ) || ! empty( $billing_house_meta ) ) {
            if ( ! empty( $billing_street_meta ) ) {
                $street_parts[] = $billing_street_meta;
            }
            if ( ! empty( $billing_house_meta ) ) {
                $street_parts[] = $billing_house_meta;
            }
        }

        $shipping_street = trim( implode( ' ', $street_parts ) );

        // Fallback to standard WooCommerce fields if OC Woo Shipping meta is not present.
        if ( '' === $shipping_street ) {
            $shipping_street = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
        }

        $shipping_zip = $order->get_shipping_postcode();

        $items_payload = array();
        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $product_id = $item->get_product_id();
            $name       = $item->get_name();
            $quantity   = (float) $item->get_quantity();
            $line_total = (float) $item->get_total();

            $unit_price = $quantity > 0 ? $line_total / $quantity : 0;

            $items_payload[] = array(
                'productId'  => $product_id,
                'name'       => $name,
                'quantity'   => $quantity,
                'unitPrice'  => $unit_price,
                'lineTotal'  => $line_total,
            );
        }

        $payload = array(
            'externalOrderId' => (int) $order_id,
            'orderNumber'     => (string) $order_number,
            'source'          => 'WooCommerce',
            'siteId'          => ! empty( $options['site_id'] ) ? (string) $options['site_id'] : null,
            'status'          => (string) $status,
            'orderDate'       => $date_created ? $date_created->date( 'c' ) : current_time( 'c' ),
            'customer'        => array(
                'name'  => $customer_name,
                'phone' => $customer_phone,
                'email' => $customer_email,
            ),
            'shippingAddress' => array(
                'street' => $shipping_street,
                'city'   => $shipping_city,
                'zip'    => $shipping_zip,
            ),
            'items'           => $items_payload,
            'shippingTotal'   => (float) $order->get_shipping_total(),
            'orderTotal'      => (float) $order->get_total(),
            'customerNotes'   => $order->get_customer_note(),
        );

        return $payload;
    }

    /**
     * Send order payload to external API.
     *
     * @param WC_Order $order   Order object.
     * @param array    $payload Payload array.
     * @param array    $options Plugin options.
     */
    protected function send_order_to_api( $order, $payload, $options ) {
        $endpoint = trailingslashit( $options['api_base_url'] ) . 'api/orders';

        $args = array(
            'method'      => 'POST',
            'timeout'     => 20,
            'headers'     => array(
                'Authorization' => 'Bearer ' . $options['api_token'],
                'Content-Type'  => 'application/json',
            ),
            'body'        => wp_json_encode( $payload ),
            'data_format' => 'body',
        );

        $response = wp_remote_post( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            $this->log_order_error( $order->get_id(), $response->get_error_message() );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 200 && $code < 300 ) {
            $this->mark_order_synced( $order->get_id() );
        } else {
            $body = wp_remote_retrieve_body( $response );
            $this->log_order_error( $order->get_id(), 'HTTP ' . $code . ' - ' . $body );
        }
    }

    /**
     * Mark order as synced.
     *
     * @param int $order_id Order ID.
     */
    protected function mark_order_synced( $order_id ) {
        update_post_meta( $order_id, self::META_SYNCED, 1 );
        update_post_meta( $order_id, self::META_LAST_ERR, '' );
        update_post_meta( $order_id, self::META_LAST_SYNC, current_time( 'mysql' ) );
    }

    /**
     * Log order sync error.
     *
     * @param int    $order_id Order ID.
     * @param string $message  Error message.
     */
    protected function log_order_error( $order_id, $message ) {
        update_post_meta( $order_id, self::META_SYNCED, 0 );
        update_post_meta( $order_id, self::META_LAST_ERR, $message );
        update_post_meta( $order_id, self::META_LAST_SYNC, current_time( 'mysql' ) );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( 'OC StoreOS Integration error for order %d: %s', $order_id, $message ) );
        }
    }
}

