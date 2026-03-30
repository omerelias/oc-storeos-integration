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

    /** @var string Uploads-relative directory for REST incoming log. */
    const REST_INCOMING_LOG_DIR = 'oc-storeos-integration';

    /** @var string Log file name (under REST_INCOMING_LOG_DIR or WP_CONTENT_DIR fallback). */
    const REST_INCOMING_LOG_FILE = 'incoming-rest-orders.log';

    /**
     * Cardcom internal deal number stored on the order by the gateway (preferred transaction id for OrderPayment).
     */
    const META_CARDCOM_PAYMENT_ID = 'Cardcom Payment ID';

    /**
     * Guard against nested / duplicate dispatch in the same request (e.g. payment_complete + status completed).
     *
     * @var array<int, bool>
     */
    protected static $payment_webhook_v2_dispatching = array();

    /**
     * Same PHP request: skip sending an identical successful payload twice (payment_complete + completed).
     *
     * @var array<int, string> order_id => md5( json payload )
     */
    protected static $payment_webhook_v2_ok_payload_hash = array();

    /**
     * One outgoing sync per order per request from creation/checkout hooks (avoid double POST).
     *
     * @var array<int, true>
     */
    protected static $outgoing_sync_after_creation_done = array();

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

        // Add optional percentage fee to the cart/checkout totals.
        add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_order_percentage_fee' ), 20, 1 );
        add_filter( 'woocommerce_cart_totals_fee_html', array( $this, 'filter_cart_fee_html' ), 10, 3 );
        add_action( 'wp_head', array( $this, 'render_fee_tooltip_styles' ) );

        // Temporary debug helper for order meta (order ID 1921).
//        add_action( 'init', array( $this, 'debug_order_meta_1921' ) );

        // REST API.
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_filter( 'rest_pre_dispatch', array( $this, 'log_rest_orders_pre_dispatch' ), 5, 3 );

        // Outgoing order: when enabled, sync once as the order is created (enters Woo) — not on payment / status change.
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'handle_checkout_order_processed' ), 10, 2 );
        add_action( 'woocommerce_new_order', array( $this, 'handle_new_order' ), 10, 2 );

        // Payment webhook (Woo → StoreOS OrderPayment), new format — late so Cardcom meta is saved first.
        add_action( 'woocommerce_payment_complete', array( $this, 'handle_payment_complete_webhook_v2' ), 99, 1 );
        add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_completed_payment_webhook_v2' ), 99, 4 );

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
     * Log that WordPress reached REST dispatch for POST /orders (before callback).
     * If Postman shows logs but server-side calls show only this line (or nothing), the problem is before REST or after dispatch — compare with callback logs.
     *
     * @param mixed             $result  Short-circuit response or null.
     * @param WP_REST_Server    $server  Server.
     * @param WP_REST_Request   $request Request.
     * @return mixed
     */
    public function log_rest_orders_pre_dispatch( $result, $server, $request ) {
        if ( ! $request instanceof WP_REST_Request ) {
            return $result;
        }
        $route = $request->get_route();
        if ( false === strpos( (string) $route, 'oc-storeos/v1/orders' ) ) {
            return $result;
        }
        if ( 'POST' !== strtoupper( $request->get_method() ) ) {
            return $result;
        }
        $remote_ip = function_exists( 'rest_get_ip_address' ) ? rest_get_ip_address() : ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
        $this->log_rest_incoming_order(
            array(
                'time_utc'    => gmdate( 'c' ),
                'result'      => 'rest_dispatch_reached',
                'route'       => $route,
                'remote_ip'   => $remote_ip,
                'user_agent'  => $request->get_header( 'user_agent' ),
                'auth_header' => $request->get_header( 'authorization' ) ? '(present)' : '(none)',
            )
        );
        return $result;
    }

    /**
     * REST callback to create or update a WooCommerce order from external system.
     *
     * Response: 201 when a new order is created, 200 when an existing order is updated.
     * `orderOperation` is `created` or `updated`. `storeosSync.status` is `ok`, `skipped`, or `error`.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function rest_create_order( $request ) {
        $remote_ip = function_exists( 'rest_get_ip_address' ) ? rest_get_ip_address() : ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );

        if ( ! class_exists( 'WC_Order' ) ) {
            $this->log_rest_incoming_order(
                array(
                    'time_utc'   => gmdate( 'c' ),
                    'remote_ip'  => $remote_ip,
                    'result'     => 'error',
                    'error_code' => 'no_woocommerce',
                )
            );
            return new WP_Error(
                'oc_storeos_no_woocommerce',
                __( 'WooCommerce is not available.', 'oc-storeos-integration' ),
                array( 'status' => 500 )
            );
        }

        $data = $request->get_json_params();
        if ( empty( $data ) || ! is_array( $data ) ) {
            $this->log_rest_incoming_order(
                array(
                    'time_utc'  => gmdate( 'c' ),
                    'remote_ip' => $remote_ip,
                    'result'    => 'error',
                    'error'     => 'invalid_json_body',
                )
            );
            return new WP_Error(
                'oc_storeos_invalid_body',
                __( 'Invalid JSON payload.', 'oc-storeos-integration' ),
                array( 'status' => 400 )
            );
        }

        try {
            $order_args             = array();
            $order                  = null;
            $updating_existing      = false;
            $items_eligible         = 0;
            $items_added            = 0;
            $items_unresolved_keys  = array();

            // אם נשלח order_id / orderId / orderNumber – ננסה לעדכן הזמנה קיימת במקום ליצור חדשה.
            // (orderNumber — מפתח כמו ב-payload מול StoreOS; בפלגין היוצא orderNumber הוא get_id().)
            $incoming_order_id = 0;
            if ( ! empty( $data['order_id'] ) && is_numeric( $data['order_id'] ) ) {
                $incoming_order_id = (int) $data['order_id'];
            } elseif ( ! empty( $data['orderId'] ) && is_numeric( $data['orderId'] ) ) {
                $incoming_order_id = (int) $data['orderId'];
            } elseif ( ! empty( $data['orderNumber'] ) && is_numeric( $data['orderNumber'] ) ) {
                $incoming_order_id = (int) $data['orderNumber'];
            }
 
            if ( $incoming_order_id > 0 ) {
                $existing_order = wc_get_order( $incoming_order_id );
                if ( $existing_order instanceof WC_Order ) {
                    $order             = $existing_order; 
                    $updating_existing = true;
                }
            }

            if ( ! $order instanceof WC_Order ) {
                if ( ! empty( $data['status'] ) && is_string( $data['status'] ) ) {
                    $order_args['status'] = sanitize_key( $data['status'] );
                }

                $order = wc_create_order( $order_args );
            } else {
                // הזמנה קיימת – אם נשלח סטטוס, נעדכן אותו.
                if ( ! empty( $data['status'] ) && is_string( $data['status'] ) ) {
                    $order->set_status( sanitize_key( $data['status'] ) );
                }

                // ננקה פריטים ומשלוחים קיימים לפני שנוסיף מה‑payload החדש.
                foreach ( $order->get_items() as $item_id => $item ) {
                    $order->remove_item( $item_id );
                }
                foreach ( $order->get_shipping_methods() as $item_id => $item ) {
                    $order->remove_item( $item_id );
                }
            }

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

                if ( ! empty( $shipping['city'] ) ) {
                    $city_value = sanitize_text_field( $shipping['city'] );
                    $order->set_shipping_city( $city_value );
                    if ( ! $order->get_billing_city() ) {
                        $order->set_billing_city( $city_value );
                    }
                }
                if ( ! empty( $shipping['zip'] ) ) {
                    $zip_value = sanitize_text_field( $shipping['zip'] );
                    $order->set_shipping_postcode( $zip_value );
                    if ( ! $order->get_billing_postcode() ) {
                        $order->set_billing_postcode( $zip_value );
                    }
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
                    $order->set_shipping_address_1( $street_name );
                    $order->update_meta_data( '_shipping_street', $street_name );
                    $order->update_meta_data( '_billing_street', $street_name );
                }

                if ( '' !== $house_num ) {
                    $order->set_billing_address_2( $house_num );
                    $order->set_shipping_address_2( $house_num );
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

            // 3. הוספת מוצרים (+ ספירה ללוג והערות)
            if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
                foreach ( $data['items'] as $item ) {
                    if ( ! is_array( $item ) ) {
                        continue;
                    }
                    $identifier = ! empty( $item['sku'] ) ? (string) $item['sku'] : (string) $item['productId'];
                    $quantity   = isset( $item['quantity'] ) ? (float) $item['quantity'] : 0;
                    if ( $quantity <= 0 ) {
                        continue;
                    }
                    ++$items_eligible;

                    $product = is_numeric( $identifier ) ? wc_get_product( (int) $identifier ) : null;
                    if ( ! $product && function_exists( 'wc_get_product_id_by_sku' ) ) {
                        $pid = wc_get_product_id_by_sku( $identifier );
                        if ( $pid ) {
                            $product = wc_get_product( $pid );
                        }
                    }

                    if ( $product ) {
                        $order->add_product( $product, $quantity );
                        ++$items_added;
                    } else {
                        $items_unresolved_keys[] = $identifier;
                    }
                }
            }

            // 4. דמי משלוח (אם נשלחו) ויצירת שורת משלוח
            $shipping_total = 0;
            if ( isset( $data['shippingTotal'] ) && is_numeric( $data['shippingTotal'] ) ) {
                $shipping_total = (float) $data['shippingTotal'];
            }

            if ( $shipping_total > 0 ) {
                // כותרת שורת המשלוח בהתאם לסוג המשלוח (משלוח / איסוף עצמי).
                $shipping_label    = __( 'משלוח עד הבית', 'oc-storeos-integration' );
                $shipping_method_id = 'storeos_shipping';

                if ( isset( $data['shippingInfo']['type'] ) && is_string( $data['shippingInfo']['type'] ) ) {
                    $shipping_type = sanitize_key( $data['shippingInfo']['type'] );

                    if ( 'pickup' === $shipping_type ) {
                        $shipping_label     = __( 'איסוף עצמי', 'oc-storeos-integration' );
                        $shipping_method_id = 'storeos_pickup';
                    }
                }

                $shipping_item = new WC_Order_Item_Shipping();
                $shipping_item->set_method_title( $shipping_label );
                $shipping_item->set_method_id( $shipping_method_id );
                $shipping_item->set_total( $shipping_total );
                $order->add_item( $shipping_item );
            }

            // 5. מידע משלוח (תאריך / שעה / איסוף מסניף) ששוגר מהמערכת החיצונית
            if ( isset( $data['shippingInfo'] ) && is_array( $data['shippingInfo'] ) ) {
                $this->apply_shipping_info_from_payload( $order, $data['shippingInfo'] );
            }

            // 5. סיום ועדכון Meta חיצוני 
            if ( ! empty( $data['customerNotes'] ) ) {
                $order->set_customer_note( wp_kses_post( $data['customerNotes'] ) );
            }

            if ( ! empty( $data['externalOrderId'] ) ) {
                $order->update_meta_data( '_oc_storeos_external_order_id', sanitize_text_field( (string) $data['externalOrderId'] ) );
            }

            $order->calculate_totals();
            $order->save(); // כאן הכל נשמר ב-Database בפעם אחת

            $outgoing_sync = $this->send_outgoing_when_order_enters( $order );

            // סיכום ללקוח API: נוצרה vs עודכנה, וסטטוס סנכרון ל-StoreOS (או דילוג/שגיאה).
            $storeos_sync_summary = array(
                'status' => 'skipped',
            );
            if ( is_array( $outgoing_sync ) ) {
                if ( ! empty( $outgoing_sync['skipped'] ) ) {
                    $storeos_sync_summary['status'] = 'skipped';
                    if ( ! empty( $outgoing_sync['reason'] ) ) {
                        $storeos_sync_summary['reason'] = $outgoing_sync['reason'];
                    }
                } elseif ( ! empty( $outgoing_sync['storeosHttpResponse'] ) && is_array( $outgoing_sync['storeosHttpResponse'] ) ) {
                    $r = $outgoing_sync['storeosHttpResponse'];
                    if ( ! empty( $r['success'] ) ) {
                        $storeos_sync_summary['status'] = 'ok';
                    } else {
                        $storeos_sync_summary['status'] = 'error';
                        if ( ! empty( $r['error'] ) ) {
                            $storeos_sync_summary['error'] = $r['error'];
                        } elseif ( is_array( $r['body'] ) && ! empty( $r['body']['errors'] ) && is_array( $r['body']['errors'] ) ) {
                            $storeos_sync_summary['error'] = $this->first_string_in_nested_lists( $r['body']['errors'] );
                        }
                        if ( empty( $storeos_sync_summary['error'] ) && isset( $r['http_status'] ) && $r['http_status'] > 0 ) {
                            /* translators: %d: HTTP status code. */
                            $storeos_sync_summary['error'] = sprintf( __( 'HTTP %d from StoreOS.', 'oc-storeos-integration' ), (int) $r['http_status'] );
                        }
                        if ( empty( $storeos_sync_summary['error'] ) ) {
                            $storeos_sync_summary['error'] = __( 'StoreOS request failed.', 'oc-storeos-integration' );
                        }
                    }
                }
            }

            $http_status = $updating_existing ? 200 : 201;

            $line_items_after = count( $order->get_items() );

            if ( $updating_existing ) {
                $sync_note = (string) $storeos_sync_summary['status'];
                if ( 'error' === $storeos_sync_summary['status'] && ! empty( $storeos_sync_summary['error'] ) ) {
                    $sync_note .= ' — ' . $storeos_sync_summary['error'];
                }
                $order_note_text = sprintf(
                    /* translators: 1: items with qty>0 in payload, 2: products added as line items, 3: line items on order after save, 4: StoreOS sync summary. */
                    __( 'עודכן דרך OC StoreOS REST API. פריטים בבקשה (כמות>0): %1$d, נוספו כמוצר מהקטלוג: %2$d, שורות מוצר בהזמנה אחרי שמירה: %3$d. סנכרון StoreOS: %4$s', 'oc-storeos-integration' ),
                    $items_eligible,
                    $items_added,
                    $line_items_after,
                    $sync_note
                );
                if ( $items_added < $items_eligible ) {
                    $order_note_text .= ' ' . sprintf(
                        /* translators: %s: comma-separated SKU/product ids not resolved. */
                        __( 'לא נמצא מוצר עבור: %s', 'oc-storeos-integration' ),
                        implode( ', ', array_map( 'strval', $items_unresolved_keys ) )
                    );
                }
                $order->add_order_note( $order_note_text, false, false );
            }

            $this->log_rest_incoming_order(
                array(
                    'time_utc'              => gmdate( 'c' ),
                    'remote_ip'             => $remote_ip,
                    'result'                => 'ok',
                    'operation'             => $updating_existing ? 'updated' : 'created',
                    'order_id'              => $order->get_id(),
                    'wc_status'             => $order->get_status(),
                    'items_eligible'        => $items_eligible,
                    'items_added'           => $items_added,
                    'line_items_after_save' => $line_items_after,
                    'items_all_saved'       => ( 0 === $items_eligible ) ? null : ( $items_added === $items_eligible ),
                    'items_unresolved'      => $items_unresolved_keys,
                    'storeos_sync'          => $storeos_sync_summary,
                    'http_response'         => $http_status,
                )
            );

            return new WP_REST_Response(
                array(
                    'success'         => true,
                    'orderOperation'  => $updating_existing ? 'updated' : 'created',
                    'storeosSync'     => $storeos_sync_summary,
                    'orderId'         => $order->get_id(),
                    'orderKey'        => $order->get_order_key(),
                    'status'          => $order->get_status(),
                    'orderDate'       => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
                    'outgoingSync'    => $outgoing_sync,
                ),
                $http_status
            );
        } catch ( Exception $e ) {
            $this->log_rest_incoming_order(
                array(
                    'time_utc'  => gmdate( 'c' ),
                    'remote_ip' => $remote_ip,
                    'result'    => 'exception',
                    'message'   => $e->getMessage(),
                )
            );
            return new WP_Error( 'oc_storeos_order_error', $e->getMessage(), array( 'status' => 500 ) );
        }
    }

    /**
     * Absolute path to the incoming REST log file (uploads/oc-storeos-integration/ or wp-content fallback).
     *
     * @return string
     */
    protected function get_rest_incoming_log_path() {
        $upload = wp_upload_dir();
        if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
            $dir = trailingslashit( $upload['basedir'] ) . self::REST_INCOMING_LOG_DIR;
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }
            return trailingslashit( $dir ) . self::REST_INCOMING_LOG_FILE;
        }

        return trailingslashit( WP_CONTENT_DIR ) . self::REST_INCOMING_LOG_FILE;
    }

    /**
     * Append one JSON line (or error text) to the incoming REST log.
     *
     * @param array $fields Key-value row to log.
     */
    protected function log_rest_incoming_order( $fields ) {
        $path = $this->get_rest_incoming_log_path();
        if ( ! $path ) {
            return;
        }
        $line = wp_json_encode( $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( false === $line ) {
            $line = '{"time_utc":"' . gmdate( 'c' ) . '","result":"log_encode_error"}';
        }
        @file_put_contents( $path, $line . "\n", FILE_APPEND | LOCK_EX );
    }

    /**
     * Apply incoming shipping info (delivery / pickup) onto the order as OC StoreOS meta.
     *
     * Expected payload structure:
     *  'shippingInfo' => [
     *      'type'                 => 'delivery' | 'pickup',
     *      'date'                 => 'YYYY-MM-DD',
     *      'slotStart'            => 'HH:MM' (optional),
     *      'slotEnd'              => 'HH:MM' (optional),
     *      'pickupAffiliateId'    => 123 (optional, for pickup),
     *      'pickupAffiliateName'  => 'Branch name' (optional, for pickup),
     *  ]
     *
     * @param WC_Order $order Order object.
     * @param array    $info  Shipping info payload.
     */
    protected function apply_shipping_info_from_payload( $order, $info ) {
        if ( ! $order instanceof WC_Order || ! is_array( $info ) ) {
            return;
        }
        $type       = '';
        $raw_date   = '';
        $slot_start = '';
        $slot_end   = '';
        $pickup_id  = '';
        $pickup_name= '';

        if ( ! empty( $info['type'] ) && is_string( $info['type'] ) ) {
            $type = sanitize_key( $info['type'] );
            $order->update_meta_data( '_oc_storeos_shipping_type', $type );
        }

        if ( ! empty( $info['date'] ) ) {
            $raw_date = sanitize_text_field( $info['date'] );
            $order->update_meta_data(
                '_oc_storeos_delivery_date',
                $raw_date
            );
        }

        if ( ! empty( $info['slotStart'] ) ) {
            $slot_start = sanitize_text_field( $info['slotStart'] );
            $order->update_meta_data(
                '_oc_storeos_delivery_slot_start',
                $slot_start
            );
        }

        if ( ! empty( $info['slotEnd'] ) ) {
            $slot_end = sanitize_text_field( $info['slotEnd'] );
            $order->update_meta_data(
                '_oc_storeos_delivery_slot_end',
                $slot_end
            );
        }

        // נתוני איסוף מסניף (רלוונטי כש-type הוא pickup, אבל לא נכריח).
        if ( ! empty( $info['pickupAffiliateId'] ) ) {
            $pickup_id = sanitize_text_field( (string) $info['pickupAffiliateId'] );
            $order->update_meta_data(
                '_oc_storeos_pickup_aff_id',
                $pickup_id
            );
        }

        if ( ! empty( $info['pickupAffiliateName'] ) ) {
            $pickup_name = sanitize_text_field( $info['pickupAffiliateName'] );
            $order->update_meta_data(
                '_oc_storeos_pickup_aff_name',
                $pickup_name
            );
        }

        /**
         * OC Woo Shipping compatibility:
         * ממלא גם את מטא המשלוחים שתוסף OCWS משתמש בהם להצגה ולטבלאות באדמין,
         * כדי שהזמנות שנוצרו דרך ה‑API יראו כמו הזמנות מה‑checkout.
         */
        if ( ! empty( $raw_date ) ) {
            $timestamp = strtotime( $raw_date );

            if ( $timestamp ) {
                $ocws_display_date  = date_i18n( 'd/m/Y', $timestamp );
                $ocws_sortable_date = date_i18n( 'Y/m/d', $timestamp );
            } else {
                // אם הפורמט לא צפוי, נשמור כמו שהוא.
                $ocws_display_date  = $raw_date;
                $ocws_sortable_date = $raw_date;
            }

            $order->update_meta_data( 'ocws_shipping_info_date', $ocws_display_date );
            $order->update_meta_data( 'ocws_shipping_info_date_sortable', $ocws_sortable_date );

            // Tag לפי סוג המשלוח כדי שעמודות OCWS יזהו את ההזמנה.
            if ( 'pickup' === $type ) {
                if ( class_exists( 'OCWS_LP_Local_Pickup' ) && defined( 'OCWS_LP_Local_Pickup::PICKUP_METHOD_TAG' ) ) {
                    $order->update_meta_data( 'ocws_shipping_tag', OCWS_LP_Local_Pickup::PICKUP_METHOD_TAG );
                } else {
                    $order->update_meta_data( 'ocws_shipping_tag', 'pickup' );
                }
            } else {
                if ( class_exists( 'OCWS_Advanced_Shipping' ) && defined( 'OCWS_Advanced_Shipping::SHIPPING_METHOD_TAG' ) ) {
                    $order->update_meta_data( 'ocws_shipping_tag', OCWS_Advanced_Shipping::SHIPPING_METHOD_TAG );
                } else {
                    $order->update_meta_data( 'ocws_shipping_tag', 'shipping' );
                }
            }
        }

        if ( '' !== $slot_start ) {
            $order->update_meta_data( 'ocws_shipping_info_slot_start', $slot_start );
        }

        if ( '' !== $slot_end ) {
            $order->update_meta_data( 'ocws_shipping_info_slot_end', $slot_end );
        }

        // תאימות ל-meta הישן של OCWS: ocws_shipping_info נשמר כמערך מסוּריאלז.
        if ( '' !== $raw_date || '' !== $slot_start || '' !== $slot_end ) {
            $legacy_shipping_info = array(
                'date'       => $raw_date,
                'slot_start' => $slot_start,
                'slot_end'   => $slot_end,
            );

            $serialized = serialize( $legacy_shipping_info );

            // נשמור גם על ההזמנה וגם על פריטי המשלוח עצמם (OCWS קורא מה-items).
            $order->update_meta_data( 'ocws_shipping_info', $serialized );

            foreach ( $order->get_items( 'shipping' ) as $item ) {
                if ( $item instanceof WC_Order_Item_Shipping ) {
                    $item->update_meta_data( 'ocws_shipping_info', $serialized );
                }
            }
        }

        // במצב איסוף, נמלא גם חלק מה‑META הייעודי של OCWS לפיקאפ (למי שמשתמש במסכים האלו).
        if ( 'pickup' === $type ) {
            if ( '' !== $pickup_id ) {
                $order->update_meta_data( 'ocws_lp_pickup_aff_id', $pickup_id );
            }
            if ( '' !== $pickup_name ) {
                $order->update_meta_data( 'ocws_lp_pickup_aff_name', $pickup_name );
            }
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
            'send_order_to_storeos',
            __( 'האם לשלוח הזמנה?', 'oc-storeos-integration' ),
            array( $this, 'render_field_send_order_to_storeos' ),
            'oc-storeos-integration',
            'oc_storeos_main_section'
        );

        add_settings_field(
            'send_order_payment_webhook_on_charge',
            __( 'עדכון תשלום בעת חיוב', 'oc-storeos-integration' ),
            array( $this, 'render_field_send_order_payment_webhook_on_charge' ),
            'oc-storeos-integration',
            'oc_storeos_main_section'
        );

        add_settings_field(
            'order_total_fee_percent',
            __( 'תוספת באחוזים לסכום הזמנה', 'oc-storeos-integration' ),
            array( $this, 'render_field_order_total_fee_percent' ),
            'oc-storeos-integration',
            'oc_storeos_main_section'
        );

        add_settings_field(
            'order_total_fee_tooltip',
            __( 'טקסט טולטיפ לתוספת', 'oc-storeos-integration' ),
            array( $this, 'render_field_order_total_fee_tooltip' ),
            'oc-storeos-integration',
            'oc_storeos_main_section'
        );

        add_settings_field(
            'shipping_method_label_map',
            __( 'מיפוי שיטות משלוח לשם חיצוני', 'oc-storeos-integration' ),
            array( $this, 'render_field_shipping_method_label_map' ),
            'oc-storeos-integration',
            'oc_storeos_main_section'
        );

        add_settings_field(
            'payment_method_label_map',
            __( 'מיפוי שיטות תשלום לשם חיצוני', 'oc-storeos-integration' ),
            array( $this, 'render_field_payment_method_label_map' ),
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

        // Checkbox + hidden field: 0 when unchecked, 1 when checked.
        $options['send_order_to_storeos'] = isset( $input['send_order_to_storeos'] )
            && ( '1' === (string) $input['send_order_to_storeos'] );

        unset( $options['order_status_trigger'] );

        $options['send_order_payment_webhook_on_charge'] = isset( $input['send_order_payment_webhook_on_charge'] )
            && ( '1' === (string) $input['send_order_payment_webhook_on_charge'] );

        if ( isset( $input['order_total_fee_percent'] ) ) {
            $raw = is_string( $input['order_total_fee_percent'] ) || is_numeric( $input['order_total_fee_percent'] )
                ? (string) $input['order_total_fee_percent']
                : '';
            $raw = str_replace( ',', '.', $raw );
            $val = (float) $raw;
            if ( $val < 0 ) {
                $val = 0;
            }
            if ( $val > 100 ) {
                $val = 100;
            }
            $options['order_total_fee_percent'] = $val;
        }

        if ( isset( $input['order_total_fee_tooltip'] ) ) {
            $options['order_total_fee_tooltip'] = sanitize_textarea_field( $input['order_total_fee_tooltip'] );
        }

        if ( isset( $input['shipping_method_label_map'] ) && is_array( $input['shipping_method_label_map'] ) ) {
            $raw = $input['shipping_method_label_map'];
            $map = array();

            foreach ( $raw as $method_id => $label ) {
                $method_id = sanitize_text_field( trim( (string) $method_id ) );
                $label     = sanitize_text_field( trim( (string) $label ) );

                if ( '' !== $method_id && '' !== $label ) {
                    $map[ $method_id ] = $label;
                }
            }

            $options['shipping_method_label_map'] = $map;
        } elseif ( isset( $input['shipping_method_label_map'] ) && is_string( $input['shipping_method_label_map'] ) ) {
            // Backward compatibility with old textarea format: method_id|label
            $raw_map = sanitize_textarea_field( $input['shipping_method_label_map'] );
            $lines   = preg_split( '/\r\n|\r|\n/', $raw_map );
            $map     = array();

            if ( is_array( $lines ) ) {
                foreach ( $lines as $line ) {
                    $line = trim( (string) $line );
                    if ( '' === $line ) {
                        continue;
                    }
                    $chunks = explode( '|', $line, 2 );
                    if ( count( $chunks ) < 2 ) {
                        continue;
                    }
                    $method_id = sanitize_text_field( trim( $chunks[0] ) );
                    $label     = sanitize_text_field( trim( $chunks[1] ) );
                    if ( '' !== $method_id && '' !== $label ) {
                        $map[ $method_id ] = $label;
                    }
                }
            }

            $options['shipping_method_label_map'] = $map;
        }

        if ( isset( $input['payment_method_label_map'] ) && is_array( $input['payment_method_label_map'] ) ) {
            $raw = $input['payment_method_label_map'];
            $map = array();

            foreach ( $raw as $method_id => $label ) {
                $method_id = sanitize_text_field( trim( (string) $method_id ) );
                $label     = sanitize_text_field( trim( (string) $label ) );

                if ( '' !== $method_id && '' !== $label ) {
                    $map[ $method_id ] = $label;
                }
            }

            $options['payment_method_label_map'] = $map;
        } elseif ( isset( $input['payment_method_label_map'] ) && is_string( $input['payment_method_label_map'] ) ) {
            // Backward compatibility with old textarea format: method_id|label
            $raw_map = sanitize_textarea_field( $input['payment_method_label_map'] );
            $lines   = preg_split( '/\r\n|\r|\n/', $raw_map );
            $map     = array();

            if ( is_array( $lines ) ) {
                foreach ( $lines as $line ) {
                    $line = trim( (string) $line );
                    if ( '' === $line ) {
                        continue;
                    }
                    $chunks = explode( '|', $line, 2 );
                    if ( count( $chunks ) < 2 ) {
                        continue;
                    }
                    $method_id = sanitize_text_field( trim( $chunks[0] ) );
                    $label     = sanitize_text_field( trim( $chunks[1] ) );
                    if ( '' !== $method_id && '' !== $label ) {
                        $map[ $method_id ] = $label;
                    }
                }
            }

            $options['payment_method_label_map'] = $map;
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
            'send_order_to_storeos' => true,
            'send_order_payment_webhook_on_charge' => true,
            'order_total_fee_percent' => 0,
            'order_total_fee_tooltip' => 'תוספת זו מוסיפה Fee באחוז מסכום ההזמנה (למשל שינויי משקל בפועל מול מה שהלקוח סימן).',
            'shipping_method_label_map' => array(),
            'payment_method_label_map' => array(),
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
                .oc-storeos-settings .oc-storeos-tooltip {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 18px;
                    height: 18px;
                    margin-right: 6px;
                    vertical-align: middle;
                    cursor: help;
                    color: #2271b1;
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
     * Render checkbox: outgoing order sync when the order is first created.
     */
    public function render_field_send_order_to_storeos() {
        $options = $this->get_options();
        $on      = ! empty( $options['send_order_to_storeos'] );
        $name    = self::OPTION_NAME . '[send_order_to_storeos]';
        ?>
        <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0" />
        <label>
            <input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $on ); ?> />
            <?php esc_html_e( 'כן — שלח את ההזמנה ל־StoreOS ברגע שהיא נכנסת למערכת (נוצרת ב־Woo).', 'oc-storeos-integration' ); ?>
        </label>
        <p class="description">
            <?php esc_html_e( 'לא בעת חיוב ולא לפי שינוי סטטוס — רק יצירת ההזמנה (כולל יבוא ב־REST/אדמין כשה־hook רלוונטי).', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Render checkbox: send OrderPayment webhook when WooCommerce marks payment complete.
     */
    public function render_field_send_order_payment_webhook_on_charge() {
        $options = $this->get_options();
        $on      = ! empty( $options['send_order_payment_webhook_on_charge'] );
        $name    = self::OPTION_NAME . '[send_order_payment_webhook_on_charge]';
        ?>
        <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0" />
        <label>
            <input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $on ); ?> />
            <?php esc_html_e( 'שלח ל־StoreOS עדכון תשלום (OrderPayment) מיד כשהחיוב ב־Woo עובר (Payment complete).', 'oc-storeos-integration' ); ?>
        </label>
        <p class="description">
            <?php esc_html_e( 'כבוי = לא יישלח בעת החיוב בלבד. שינוי סטטוס ההזמנה ל״הושלמה״ עדיין ישלח עדכון תשלום ל־StoreOS.', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Render "order total percentage fee" field (adds a WooCommerce Fee).
     */
    public function render_field_order_total_fee_percent() {
        $options = $this->get_options();
        $percent = isset( $options['order_total_fee_percent'] ) ? (float) $options['order_total_fee_percent'] : 0;
        $tooltip = isset( $options['order_total_fee_tooltip'] ) ? (string) $options['order_total_fee_tooltip'] : '';
        $tooltip = '' !== $tooltip ? $tooltip : __( 'תוספת זו מוסיפה Fee באחוז מסכום ההזמנה.', 'oc-storeos-integration' );
        ?>
        <span class="oc-storeos-tooltip dashicons dashicons-info-outline" title="<?php echo esc_attr( $tooltip ); ?>"></span>
        <input
                type="number"
                step="0.01"
                min="0"
                max="100"
                class="small-text"
                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[order_total_fee_percent]"
                value="<?php echo esc_attr( $percent ); ?>"
        />
        <span>%</span>
        <p class="description">
            <?php esc_html_e( 'האחוז יחושב מסכום ההזמנה ויוסף כ‑Fee בעגלה/צ׳קאאוט. 0 = כבוי.', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Render editable tooltip text for the percentage fee field.
     */
    public function render_field_order_total_fee_tooltip() {
        $options = $this->get_options();
        $tooltip = isset( $options['order_total_fee_tooltip'] ) ? (string) $options['order_total_fee_tooltip'] : '';
        ?>
        <textarea
                class="large-text"
                rows="3"
                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[order_total_fee_tooltip]"
        ><?php echo esc_textarea( $tooltip ); ?></textarea>
        <p class="description">
            <?php esc_html_e( 'הטקסט שיופיע בטולטיפ ליד שדה האחוזים (ניתן לעריכה).', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Render shipping method -> external shipping label map.
     * Left column: current label on site, right column: override label to send.
     */
    public function render_field_shipping_method_label_map() {
        $options = $this->get_options();
        $map     = isset( $options['shipping_method_label_map'] ) ? $options['shipping_method_label_map'] : array();
        if ( is_string( $map ) ) {
            // Backward compatibility with older saved format.
            $parsed = array();
            $lines  = preg_split( '/\r\n|\r|\n/', $map );
            if ( is_array( $lines ) ) {
                foreach ( $lines as $line ) {
                    $line = trim( (string) $line );
                    if ( '' === $line ) {
                        continue;
                    }
                    $chunks = explode( '|', $line, 2 );
                    if ( count( $chunks ) < 2 ) {
                        continue;
                    }
                    $method_id = trim( $chunks[0] );
                    $label     = trim( $chunks[1] );
                    if ( '' !== $method_id && '' !== $label ) {
                        $parsed[ $method_id ] = $label;
                    }
                }
            }
            $map = $parsed;
        }
        if ( ! is_array( $map ) ) {
            $map = array();
        }

        $available_methods = $this->get_available_shipping_methods_for_mapping();

        // Keep any manually saved mappings that are not currently detected on site.
        foreach ( $map as $saved_method_id => $saved_label ) {
            if ( ! isset( $available_methods[ $saved_method_id ] ) ) {
                $available_methods[ $saved_method_id ] = __( '(Method not currently detected on site)', 'oc-storeos-integration' );
            }
        }
        ?>
        <table class="widefat striped" style="max-width: 920px;">
            <thead>
            <tr>
                <th style="width:28%;"><?php esc_html_e( 'Method ID', 'oc-storeos-integration' ); ?></th>
                <th style="width:32%;"><?php esc_html_e( 'שם נוכחי באתר', 'oc-storeos-integration' ); ?></th>
                <th style="width:40%;"><?php esc_html_e( 'Label לשליחה למערכת (shipping_label)', 'oc-storeos-integration' ); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ( $available_methods as $method_id => $current_label ) : ?>
                <tr>
                    <td>
                        <code><?php echo esc_html( $method_id ); ?></code>
                    </td>
                    <td>
                        <?php echo esc_html( $current_label ); ?>
                    </td>
                    <td>
                        <input
                                type="text"
                                class="regular-text"
                                style="width:100%;"
                                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[shipping_method_label_map][<?php echo esc_attr( $method_id ); ?>]"
                                value="<?php echo esc_attr( isset( $map[ $method_id ] ) ? (string) $map[ $method_id ] : '' ); ?>"
                                placeholder="<?php esc_attr_e( 'אם ריק - יישלח השם הנוכחי', 'oc-storeos-integration' ); ?>"
                        />
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">
            <?php esc_html_e( 'המערכת מזהה אוטומטית את שיטות המשלוח מהאתר. בכל שורה אפשר להגדיר תווית חלופית שתישלח ל-API. לדוגמה: flat_rate:5 עם שם נוכחי "משלוח עד הבית" אפשר למפות לתווית אחרת לשליחה.', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Render payment method -> external payment label map.
     * Left column: current label on site, right column: override label to send.
     */
    public function render_field_payment_method_label_map() {
        $options = $this->get_options();
        $map     = isset( $options['payment_method_label_map'] ) ? $options['payment_method_label_map'] : array();

        if ( is_string( $map ) ) {
            $parsed = array();
            $lines  = preg_split( '/\r\n|\r|\n/', $map );
            if ( is_array( $lines ) ) {
                foreach ( $lines as $line ) {
                    $line = trim( (string) $line );
                    if ( '' === $line ) {
                        continue;
                    }
                    $chunks = explode( '|', $line, 2 );
                    if ( count( $chunks ) < 2 ) {
                        continue;
                    }
                    $method_id = trim( $chunks[0] );
                    $label     = trim( $chunks[1] );
                    if ( '' !== $method_id && '' !== $label ) {
                        $parsed[ $method_id ] = $label;
                    }
                }
            }
            $map = $parsed;
        }

        if ( ! is_array( $map ) ) {
            $map = array();
        }

        $available_methods = $this->get_available_payment_methods_for_mapping();

        foreach ( $map as $saved_method_id => $saved_label ) {
            if ( ! isset( $available_methods[ $saved_method_id ] ) ) {
                $available_methods[ $saved_method_id ] = __( '(Method not currently detected on site)', 'oc-storeos-integration' );
            }
        }
        ?>
        <table class="widefat striped" style="max-width: 920px;">
            <thead>
            <tr>
                <th style="width:28%;"><?php esc_html_e( 'Payment Method ID', 'oc-storeos-integration' ); ?></th>
                <th style="width:32%;"><?php esc_html_e( 'שם נוכחי באתר', 'oc-storeos-integration' ); ?></th>
                <th style="width:40%;"><?php esc_html_e( 'Label לשליחה למערכת (payment_label)', 'oc-storeos-integration' ); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ( $available_methods as $method_id => $current_label ) : ?>
                <tr>
                    <td><code><?php echo esc_html( $method_id ); ?></code></td>
                    <td><?php echo esc_html( $current_label ); ?></td>
                    <td>
                        <input
                                type="text"
                                class="regular-text"
                                style="width:100%;"
                                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[payment_method_label_map][<?php echo esc_attr( $method_id ); ?>]"
                                value="<?php echo esc_attr( isset( $map[ $method_id ] ) ? (string) $map[ $method_id ] : '' ); ?>"
                                placeholder="<?php esc_attr_e( 'אם ריק - יישלח השם הנוכחי', 'oc-storeos-integration' ); ?>"
                        />
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">
            <?php esc_html_e( 'המערכת מזהה אוטומטית את שיטות התשלום מהאתר. בכל שורה אפשר להגדיר תווית חלופית שתישלח ל-API בשדה payment_label.', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Collect available shipping methods (instance ids from zones + generic methods).
     *
     * @return array method_id => current_label
     */
    protected function get_available_shipping_methods_for_mapping() {
        $methods = array();

        if ( class_exists( 'WC_Shipping_Zones' ) ) {
            $zones = WC_Shipping_Zones::get_zones();
            if ( is_array( $zones ) ) {
                foreach ( $zones as $zone_data ) {
                    if ( empty( $zone_data['shipping_methods'] ) || ! is_array( $zone_data['shipping_methods'] ) ) {
                        continue;
                    }
                    foreach ( $zone_data['shipping_methods'] as $method ) {
                        if ( ! is_object( $method ) || ! isset( $method->id ) ) {
                            continue;
                        }
                        $method_id   = (string) $method->id;
                        $instance_id = isset( $method->instance_id ) ? (string) $method->instance_id : '';
                        $full_id     = $method_id . ( '' !== $instance_id ? ':' . $instance_id : '' );
                        $title       = method_exists( $method, 'get_title' ) ? (string) $method->get_title() : '';
                        if ( '' === $title && isset( $method->method_title ) ) {
                            $title = (string) $method->method_title;
                        }
                        if ( '' === $title ) {
                            $title = $full_id;
                        }
                        $methods[ $full_id ] = $title;
                    }
                }
            }
        }

        // Fallback generic methods list.
        if ( function_exists( 'WC' ) && isset( WC()->shipping ) && method_exists( WC()->shipping, 'get_shipping_methods' ) ) {
            $generic = WC()->shipping->get_shipping_methods();
            if ( is_array( $generic ) ) {
                foreach ( $generic as $id => $method ) {
                    $id = (string) $id;
                    if ( isset( $methods[ $id ] ) ) {
                        continue;
                    }
                    $title = is_object( $method ) && isset( $method->method_title ) ? (string) $method->method_title : $id;
                    $methods[ $id ] = $title;
                }
            }
        }

        // Extra fallback: collect real method ids/titles from recent orders.
        $recent_orders = wc_get_orders(
            array(
                'limit'   => 200,
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'objects',
            )
        );

        if ( is_array( $recent_orders ) ) {
            foreach ( $recent_orders as $order ) {
                if ( ! $order instanceof WC_Order ) {
                    continue;
                }

                foreach ( $order->get_shipping_methods() as $shipping_item ) {
                    if ( ! $shipping_item instanceof WC_Order_Item_Shipping ) {
                        continue;
                    }

                    $method_id   = (string) $shipping_item->get_method_id();
                    $instance_id = (string) $shipping_item->get_instance_id();
                    $full_id     = $method_id . ( '' !== $instance_id ? ':' . $instance_id : '' );

                    $title = trim( (string) $shipping_item->get_method_title() );
                    if ( '' === $title ) {
                        $title = trim( (string) $shipping_item->get_name() );
                    }
                    if ( '' === $title ) {
                        $title = $full_id;
                    }

                    if ( '' !== $full_id && ! isset( $methods[ $full_id ] ) ) {
                        $methods[ $full_id ] = $title;
                    }
                    if ( '' !== $method_id && ! isset( $methods[ $method_id ] ) ) {
                        $methods[ $method_id ] = $title;
                    }
                }
            }
        }

        ksort( $methods );

        return $methods;
    }

    /**
     * Collect available payment methods.
     *
     * @return array payment_method_id => current_label
     */
    protected function get_available_payment_methods_for_mapping() {
        $methods = array();

        if ( function_exists( 'WC' ) && isset( WC()->payment_gateways ) ) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            if ( is_array( $gateways ) ) {
                foreach ( $gateways as $gateway ) {
                    if ( ! is_object( $gateway ) || ! isset( $gateway->id ) ) {
                        continue;
                    }
                    $id    = (string) $gateway->id;
                    $title = isset( $gateway->title ) ? (string) $gateway->title : $id;
                    $methods[ $id ] = $title;
                }
            }
        }

        // Extra fallback: collect real payment methods from recent orders.
        $recent_orders = wc_get_orders(
            array(
                'limit'   => 200,
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'objects',
            )
        );

        if ( is_array( $recent_orders ) ) {
            foreach ( $recent_orders as $order ) {
                if ( ! $order instanceof WC_Order ) {
                    continue;
                }

                $method_id = trim( (string) $order->get_payment_method() );
                if ( '' === $method_id ) {
                    continue;
                }

                $title = trim( (string) $order->get_payment_method_title() );
                if ( '' === $title ) {
                    $title = $method_id;
                }

                if ( ! isset( $methods[ $method_id ] ) ) {
                    $methods[ $method_id ] = $title;
                }
            }
        }

        ksort( $methods );

        return $methods;
    }

    /**
     * Add a percentage Fee to cart/checkout totals (for weight adjustments).
     *
     * @param WC_Cart $cart WooCommerce cart.
     */
    public function add_order_percentage_fee( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        if ( ! $cart || ! method_exists( $cart, 'add_fee' ) ) {
            return;
        }

        $options = $this->get_options();
        $percent = isset( $options['order_total_fee_percent'] ) ? (float) $options['order_total_fee_percent'] : 0;
        if ( $percent <= 0 ) {
            return;
        }

        // Base: cart contents + shipping (if already calculated). Fees are calculated before final totals.
        $base = 0.0;
        if ( method_exists( $cart, 'get_cart_contents_total' ) ) {
            $base += (float) $cart->get_cart_contents_total();
        }
        if ( method_exists( $cart, 'get_shipping_total' ) ) {
            $base += (float) $cart->get_shipping_total();
        }

        if ( $base <= 0 ) {
            return;
        }

        $amount = ( $base * $percent ) / 100;
        if ( function_exists( 'wc_get_price_decimals' ) ) {
            $amount = round( $amount, wc_get_price_decimals() );
        }

        if ( $amount <= 0 ) {
            return;
        }

        $label = __( 'תוספת משקל', 'oc-storeos-integration' );
        $cart->add_fee( $label, $amount, false );
    }

    /**
     * Attach tooltip HTML directly to the fee total HTML (wc_cart_totals_fee_html).
     *
     * @param string      $fee_html Rendered fee HTML.
     * @param WC_Cart_Fee $fee      Fee object.
     * @param WC_Cart     $cart     Cart object.
     *
     * @return string
     */
    public function filter_cart_fee_html( $fee_html, $fee, $cart = null ) {
        if ( empty( $fee ) || ! is_object( $fee ) ) {
            return $fee_html;
        }

        // Only affect our specific fee.
        $our_label = __( 'תוספת משקל', 'oc-storeos-integration' );

        $name = '';
        if ( is_object( $fee ) ) {
            if ( method_exists( $fee, 'get_name' ) ) {
                $name = (string) $fee->get_name();
            } elseif ( isset( $fee->name ) ) {
                $name = (string) $fee->name;
            }
        }

        if ( $name !== $our_label ) {
            return $fee_html;
        }

        $options = $this->get_options();
        $tooltip = isset( $options['order_total_fee_tooltip'] ) ? (string) $options['order_total_fee_tooltip'] : '';
        $tooltip = '' !== $tooltip ? $tooltip : __( 'תוספת זו מוסיפה Fee באחוז מסכום ההזמנה (למשל שינויי משקל בפועל מול מה שהלקוח סימן).', 'oc-storeos-integration' );

        $icon = '<span class="oc-storeos-fee-tooltip" tabindex="0" role="img" aria-label="' . esc_attr( $tooltip ) . '" data-tooltip="' . esc_attr( $tooltip ) . '">i</span>';

        return $fee_html . '&nbsp;' . $icon;
    }

    /**
     * Render frontend styles for the custom fee tooltip (cart/checkout only).
     */
    public function render_fee_tooltip_styles() {
        if ( is_admin() ) {
            return;
        }
        if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
            return;
        }
        if ( ! is_cart() && ! is_checkout() ) {
            return;
        }
        ?>
        <style id="oc-storeos-fee-tooltip-style">
            .oc-storeos-fee-tooltip {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 18px;
                height: 18px;
                margin-inline-start: 6px;
                border-radius: 999px;
                border: 1px solid #c7ced4;
                background: #f8fafc;
                color: #2f3f4a;
                font-size: 12px;
                font-weight: 700;
                line-height: 1;
                cursor: help;
                position: relative;
                vertical-align: middle;
            }
            .oc-storeos-fee-tooltip::after {
                content: attr(data-tooltip);
                position: absolute;
                left: 50%;
                bottom: calc(100% + 10px);
                transform: translateX(-50%) translateY(4px);
                min-width: 220px;
                max-width: 320px;
                padding: 8px 10px;
                border-radius: 8px;
                background: #111827;
                color: #fff;
                font-size: 12px;
                font-weight: 400;
                line-height: 1.45;
                text-align: start;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
                transition: opacity .15s ease, transform .15s ease, visibility .15s ease;
                z-index: 9999;
                white-space: normal;
            }
            .oc-storeos-fee-tooltip::before {
                content: '';
                position: absolute;
                left: 50%;
                bottom: calc(100% + 4px);
                transform: translateX(-50%);
                border: 6px solid transparent;
                border-top-color: #111827;
                opacity: 0;
                visibility: hidden;
                transition: opacity .15s ease, visibility .15s ease;
                z-index: 10000;
            }
            .oc-storeos-fee-tooltip:hover::after,
            .oc-storeos-fee-tooltip:hover::before,
            .oc-storeos-fee-tooltip:focus::after,
            .oc-storeos-fee-tooltip:focus::before {
                opacity: 1;
                visibility: visible;
                transform: translateX(-50%) translateY(0);
            }
        </style>
        <?php
    }


    /**
     * Storefront checkout: order object is complete with line items.
     *
     * @param WC_Order $order Order.
     * @param array    $data  Posted data.
     */
    public function handle_checkout_order_processed( $order, $data ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $this->send_outgoing_when_order_enters( $order );
    }

    /**
     * `woocommerce_new_order` — creation from checkout (may run before line items), admin, gateways, etc.
     *
     * @param int           $order_id Order ID.
     * @param WC_Order|null $order    Order instance when passed.
     */
    public function handle_new_order( $order_id, $order = null ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        $this->send_outgoing_when_order_enters( $order );
    }

    /**
     * Single POST per order per request when the order is created and has at least one line item.
     *
     * @param WC_Order|null $order Order.
     *
     * @return array|null Skipped flags, or outgoingPayload + storeosHttpResponse from StoreOS.
     */
    protected function send_outgoing_when_order_enters( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return null;
        }

        $order_id = $order->get_id();
        if ( ! $order_id ) {
            return null;
        }

        if ( ! empty( self::$outgoing_sync_after_creation_done[ $order_id ] ) ) {
            return array(
                'skipped' => true,
                'reason'  => 'already_synced_this_request',
            );
        }

        if ( count( $order->get_items() ) < 1 ) {
            return array(
                'skipped' => true,
                'reason'  => 'no_line_items',
            );
        }

        self::$outgoing_sync_after_creation_done[ $order_id ] = true;
        return $this->send_order_to_storeos( $order );
    }

    /**
     * POST order JSON to StoreOS when the "send order" option is enabled.
     *
     * @param WC_Order $order Order object.
     *
     * @return array|null See send_outgoing_when_order_enters().
     */
    protected function send_order_to_storeos( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return null;
        }

        $options = $this->get_options();

        if ( empty( $options['send_order_to_storeos'] ) ) {
            return array(
                'skipped' => true,
                'reason'  => 'send_order_to_storeos_disabled',
            );
        }

        if ( empty( $options['api_base_url'] ) || empty( $options['api_token'] ) ) {
            return array(
                'skipped' => true,
                'reason'  => 'missing_api_credentials',
            );
        }

        $payload    = $this->build_order_payload( $order, $options );
        $api_result = $this->send_order_to_api( $order, $payload, $options );

        return array(
            'outgoingPayload'     => $payload,
            'storeosHttpResponse' => $api_result,
        );
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
        $order_number = (string) $order->get_id(); // Use internal ID as orderNumber for stable external key.
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

            $sku = '';
            $product = $item->get_product();
            if ( $product instanceof WC_Product ) {
                $sku = $product->get_sku();
            }

            // Variation attributes (selected properties) + extra item meta.
            // This is used for variable products (e.g. size/color).
            $variation_id = 0;
            if ( method_exists( $item, 'get_variation_id' ) ) {
                $variation_id = (int) $item->get_variation_id();
            }

            $variation_attributes = array();
            $item_meta_payload    = array();

            // Best source for variation attributes on WC_Order_Item_Product.
            if ( method_exists( $item, 'get_variation_attributes' ) ) {
                $raw_attrs = $item->get_variation_attributes();
                if ( is_array( $raw_attrs ) ) {
                    foreach ( $raw_attrs as $key => $value ) {
                        if ( ! is_string( $key ) ) {
                            continue;
                        }
                        if ( $value === '' || $value === null ) {
                            continue;
                        }

                        $attr_key = $key;
                        // Typically: attribute_pa_color => color
                        if ( 0 === strpos( $attr_key, 'attribute_' ) ) {
                            $attr_key = substr( $attr_key, strlen( 'attribute_' ) ); // pa_color
                        }
                        if ( 0 === strpos( $attr_key, 'pa_' ) ) {
                            $attr_key = substr( $attr_key, 3 ); // color
                        }

                        $variation_attributes[ $attr_key ] = is_scalar( $value ) ? (string) $value : $value;
                    }
                }
            }

            // Fallback + extra meta.
            // בתוך הלולאה של foreach ( $order->get_items() as $item )

            $variation_id = (int) $item->get_variation_id();
            $variation_attributes = array();

// 1. שליפת וריאציות בצורה נקייה (עברית וסלאגים)
            if ( $variation_id > 0 ) {
                $product_variation = $item->get_product();
                if ( $product_variation instanceof WC_Product_Variation ) {
                    $selection = $product_variation->get_attributes();
                    foreach ( $selection as $taxonomy => $slug ) {
                        $label = wc_attribute_label( $taxonomy, $product_variation );
                        $display_value = $slug;

                        if ( taxonomy_exists( $taxonomy ) ) {
                            $term = get_term_by( 'slug', $slug, $taxonomy );
                            if ( $term && ! is_wp_error( $term ) ) {
                                $display_value = $term->name;
                            }
                        } else {
                            $display_value = urldecode( $slug );
                        }
                        $variation_attributes[ $label ] = $display_value;
                    }
                }
            }

// 2. שליפת הערת המוצר הספציפית (מה ששמרת ב-cart_item_data)
            $product_note = $item->get_meta('product_note');

// 3. בניית ה-Payload
            // StoreOS מצפה ל-Dictionary (JSON object); מערך PHP ריק נהפך ל-[] וגורם ל-400 ב-.NET.
            $variation_attrs_for_json = empty( $variation_attributes )
                ? new \stdClass()
                : $variation_attributes;

            $items_payload[] = array(
                'productId'  => $item->get_product_id(),
                'name'       => $item->get_name(),
                'sku'        => $sku,
                'quantity'   => $quantity,
                'unitPrice'  => $unit_price,
                'lineTotal'  => $line_total,
                'productNote' => $product_note ? $product_note : '', // הוספת ההערה כאן
                'variation'  => array(
                    'variationId' => $variation_id ?: null,
                    'attributes'  => $variation_attrs_for_json,
                ),
            );
        }

        // Map WooCommerce status to external API expectations.
        $external_status = 'on-hold';
        switch ( $status ) {
            case 'completed':
                $external_status = 'completed';
                break;
            case 'cancelled':
            case 'canceled':
                $external_status = 'cancelled';
                break;
            default:
                $external_status = 'on-hold';
                break;
        }

        $payload = array(
            'externalOrderId' => (int) $order_id,
            'orderNumber'     => $order_number,
            'source'          => 'WooCommerce',
            'siteId'          => ! empty( $options['site_id'] ) ? (string) $options['site_id'] : null,
            'status'          => $external_status,
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

        $shipping_label = $this->resolve_shipping_label_for_payload( $order, $options );
        if ( '' !== $shipping_label ) {
            $payload['shipping_label'] = $shipping_label;
        }

        $payment_label = $this->resolve_payment_label_for_payload( $order, $options );
        if ( '' !== $payment_label ) {
            $payload['payment_label'] = $payment_label;
        }

        // הוספת מידע משלוח (אם קיים ב-Meta של ההזמנה).
        $shipping_info = $this->get_order_shipping_info_meta( $order );
        if ( ! empty( $shipping_info ) ) {
            $payload['shippingInfo'] = $shipping_info;
        }

        return $payload;
    }

    /**
     * Extract normalized shipping info from OC StoreOS meta on the order.
     *
     * @param WC_Order $order Order object.
     * @return array
     */
    protected function get_order_shipping_info_meta( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return array();
        }

        $type         = $order->get_meta( '_oc_storeos_shipping_type', true );
        $date         = $order->get_meta( '_oc_storeos_delivery_date', true );
        $slot_start   = $order->get_meta( '_oc_storeos_delivery_slot_start', true );
        $slot_end     = $order->get_meta( '_oc_storeos_delivery_slot_end', true );
        $pickup_id    = $order->get_meta( '_oc_storeos_pickup_aff_id', true );
        $pickup_name  = $order->get_meta( '_oc_storeos_pickup_aff_name', true );

        // Fallback for regular site orders: use OC Woo Shipping meta.
        if ( '' === (string) $date ) {
            $date = $order->get_meta( 'ocws_shipping_info_date', true );
        }
        if ( '' === (string) $slot_start ) {
            $slot_start = $order->get_meta( 'ocws_shipping_info_slot_start', true );
        }
        if ( '' === (string) $slot_end ) {
            $slot_end = $order->get_meta( 'ocws_shipping_info_slot_end', true );
        }

        if ( '' === (string) $type ) {
            $tag = $order->get_meta( 'ocws_shipping_tag', true );
            if ( 'pickup' === (string) $tag ) {
                $type = 'pickup';
            } elseif ( 'shipping' === (string) $tag ) {
                $type = 'delivery';
            }
        }

        if ( '' === (string) $pickup_id ) {
            $pickup_id = $order->get_meta( 'ocws_lp_pickup_aff_id', true );
        }
        if ( '' === (string) $pickup_name ) {
            $pickup_name = $order->get_meta( 'ocws_lp_pickup_aff_name', true );
        }

        if (
            '' === (string) $type &&
            '' === (string) $date &&
            '' === (string) $slot_start &&
            '' === (string) $slot_end &&
            '' === (string) $pickup_id &&
            '' === (string) $pickup_name
        ) {
            return array();
        }

        $info = array();

        if ( '' !== (string) $type ) {
            $info['type'] = $type;
        }
        if ( '' !== (string) $date ) {
            $info['date'] = $date;
        }
        if ( '' !== (string) $slot_start ) {
            $info['slotStart'] = $slot_start;
        }
        if ( '' !== (string) $slot_end ) {
            $info['slotEnd'] = $slot_end;
        }
        if ( '' !== (string) $pickup_id ) {
            $info['pickupAffiliateId'] = $pickup_id;
        }
        if ( '' !== (string) $pickup_name ) {
            $info['pickupAffiliateName'] = $pickup_name;
        }

        return $info;
    }

    /**
     * Resolve shipping label from admin mapping by shipping method ID.
     *
     * @param WC_Order $order   Order object.
     * @param array    $options Plugin options.
     *
     * @return string
     */
    protected function resolve_shipping_label_for_payload( $order, $options ) {
        if ( ! $order instanceof WC_Order ) {
            return '';
        }

        $raw_map = isset( $options['shipping_method_label_map'] ) ? $options['shipping_method_label_map'] : array();
        $map     = array();

        if ( is_array( $raw_map ) ) {
            foreach ( $raw_map as $method_id => $label ) {
                $method_id = trim( (string) $method_id );
                $label     = trim( (string) $label );
                if ( '' !== $method_id && '' !== $label ) {
                    $map[ $method_id ] = $label;
                }
            }
        } elseif ( is_string( $raw_map ) ) {
            // Backward compatibility for old text format.
            $lines = preg_split( '/\r\n|\r|\n/', $raw_map );
            if ( is_array( $lines ) ) {
                foreach ( $lines as $line ) {
                    $line = trim( (string) $line );
                    if ( '' === $line ) {
                        continue;
                    }
                    $chunks = explode( '|', $line, 2 );
                    if ( count( $chunks ) < 2 ) {
                        continue;
                    }
                    $method_id = trim( $chunks[0] );
                    $label     = trim( $chunks[1] );
                    if ( '' !== $method_id && '' !== $label ) {
                        $map[ $method_id ] = $label;
                    }
                }
            }
        }

        foreach ( $order->get_shipping_methods() as $shipping_item ) {
            if ( ! $shipping_item instanceof WC_Order_Item_Shipping ) {
                continue;
            }

            // Woo usually stores method_id + instance_id (e.g. flat_rate + 5).
            $method_id  = (string) $shipping_item->get_method_id();
            $instance_id = (string) $shipping_item->get_instance_id();
            $full_id    = $method_id . ( '' !== $instance_id ? ':' . $instance_id : '' );

            if ( isset( $map[ $full_id ] ) ) {
                return $map[ $full_id ];
            }
            if ( isset( $map[ $method_id ] ) ) {
                return $map[ $method_id ];
            }

            // Fallback: send the current shipping label from the order itself.
            $current_label = '';
            if ( method_exists( $shipping_item, 'get_method_title' ) ) {
                $current_label = trim( (string) $shipping_item->get_method_title() );
            }
            if ( '' === $current_label ) {
                $current_label = trim( (string) $shipping_item->get_name() );
            }
            if ( '' !== $current_label ) {
                return $current_label;
            }
        }

        return '';
    }

    /**
     * Resolve payment label from admin mapping by payment method ID.
     *
     * @param WC_Order $order   Order object.
     * @param array    $options Plugin options.
     *
     * @return string
     */
    protected function resolve_payment_label_for_payload( $order, $options ) {
        if ( ! $order instanceof WC_Order ) {
            return '';
        }

        $raw_map = isset( $options['payment_method_label_map'] ) ? $options['payment_method_label_map'] : array();
        $map     = array();

        if ( is_array( $raw_map ) ) {
            foreach ( $raw_map as $method_id => $label ) {
                $method_id = trim( (string) $method_id );
                $label     = trim( (string) $label );
                if ( '' !== $method_id && '' !== $label ) {
                    $map[ $method_id ] = $label;
                }
            }
        } elseif ( is_string( $raw_map ) ) {
            $lines = preg_split( '/\r\n|\r|\n/', $raw_map );
            if ( is_array( $lines ) ) {
                foreach ( $lines as $line ) {
                    $line = trim( (string) $line );
                    if ( '' === $line ) {
                        continue;
                    }
                    $chunks = explode( '|', $line, 2 );
                    if ( count( $chunks ) < 2 ) {
                        continue;
                    }
                    $method_id = trim( $chunks[0] );
                    $label     = trim( $chunks[1] );
                    if ( '' !== $method_id && '' !== $label ) {
                        $map[ $method_id ] = $label;
                    }
                }
            }
        }

        $method_id = trim( (string) $order->get_payment_method() );
        if ( '' !== $method_id && isset( $map[ $method_id ] ) ) {
            return $map[ $method_id ];
        }

        $current_label = trim( (string) $order->get_payment_method_title() );
        if ( '' !== $current_label ) {
            return $current_label;
        }

        return $method_id;
    }

    /**
     * Send order payload to external API (create/update order).
     *
     * @param WC_Order $order   Order object.
     * @param array    $payload Payload array.
     * @param array    $options Plugin options.
     *
     * @return array Keys: success, http_status, body, error.
     */
    protected function send_order_to_api( $order, $payload, $options ) {
        $endpoint = trailingslashit( $options['api_base_url'] ) . 'WooCommerce/Order';

        $args = array(
            'method'      => 'POST',
            'timeout'     => 20,
            'headers'     => array(
                // Either header is accepted by the external API. We prefer X-Api-Key as per docs.
                'X-Api-Key'     => $options['api_token'],
                'Authorization' => 'Bearer ' . $options['api_token'],
                'Content-Type'  => 'application/json',
            ),
            'body'        => wp_json_encode( $payload ),
            'data_format' => 'body',
        );

        $response = wp_remote_post( $endpoint, $args );
        if ( is_wp_error( $response ) ) {
            $this->log_order_error( $order->get_id(), $response->get_error_message() );
            return array(
                'success'     => false,
                'http_status' => null,
                'body'        => null,
                'error'       => $response->get_error_message(),
            );
        }

        $code     = (int) wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        $decoded  = json_decode( $body_raw, true );
        $body     = ( JSON_ERROR_NONE === json_last_error() && null !== $decoded ) ? $decoded : $body_raw;

        if ( $code >= 200 && $code < 300 ) {
            $this->mark_order_synced( $order->get_id() );
        } else {
            $this->log_order_error( $order->get_id(), 'HTTP ' . $code . ' - ' . $body_raw );
        }

        return array(
            'success'     => ( $code >= 200 && $code < 300 ),
            'http_status' => $code,
            'body'        => $body,
            'error'       => null,
        );
    }

    /**
     * First non-empty string from ASP.NET-style validation errors (arrays of messages per field).
     *
     * @param array $errors Associative array of string lists.
     * @return string
     */
    protected function first_string_in_nested_lists( $errors ) {
        if ( ! is_array( $errors ) ) {
            return '';
        }
        foreach ( $errors as $messages ) {
            if ( ! is_array( $messages ) ) {
                continue;
            }
            foreach ( $messages as $msg ) {
                if ( is_string( $msg ) && '' !== $msg ) {
                    return $msg;
                }
            }
        }
        return '';
    }

    /**
     * WooCommerce: after payment is completed — send OrderPayment webhook (v2) to StoreOS.
     *
     * @param int $order_id Order ID.
     */
    public function handle_payment_complete_webhook_v2( $order_id ) {
        $options = $this->get_options();
        if ( empty( $options['send_order_payment_webhook_on_charge'] ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        $this->maybe_send_order_payment_webhook_v2( $order );
    }

    /**
     * WooCommerce: when order becomes completed — send OrderPayment webhook (v2) to StoreOS.
     *
     * @param int        $order_id   Order ID.
     * @param string     $old_status Previous status.
     * @param string     $new_status New status.
     * @param WC_Order|mixed $order  Order instance when available.
     */
    public function handle_order_completed_payment_webhook_v2( $order_id, $old_status, $new_status, $order ) {
        if ( 'completed' !== $new_status ) {
            return;
        }

        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }

        $this->maybe_send_order_payment_webhook_v2( $order );
    }

    /**
     * Build payload and POST to WooCommerce/OrderPayment (new format).
     *
     * @param WC_Order|null $order Order.
     */
    protected function maybe_send_order_payment_webhook_v2( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $order_id = $order->get_id();

        if ( ! empty( self::$payment_webhook_v2_dispatching[ $order_id ] ) ) {
            return;
        }

        self::$payment_webhook_v2_dispatching[ $order_id ] = true;

        try {
            $order = wc_get_order( $order_id );
            if ( ! $order instanceof WC_Order ) {
                return;
            }

            $options = $this->get_options();
            if ( empty( $options['api_base_url'] ) || empty( $options['api_token'] ) ) {
                return;
            }

            $payload      = $this->build_order_payment_webhook_v2_payload( $order );
            $payload_hash = md5( wp_json_encode( $payload ) );

            if ( isset( self::$payment_webhook_v2_ok_payload_hash[ $order_id ] )
                && self::$payment_webhook_v2_ok_payload_hash[ $order_id ] === $payload_hash ) {
                return;
            }

            $this->send_order_payment_webhook_v2_request( $order, $payload, $options );
        } finally {
            unset( self::$payment_webhook_v2_dispatching[ $order_id ] );
        }
    }

    /**
     * New OrderPayment body: orderId, status (success if Cardcom Payment ID meta is set, else failed), optional cardcomPayment.
     *
     * @param WC_Order $order Order.
     *
     * @return array
     */
    protected function build_order_payment_webhook_v2_payload( WC_Order $order ) {
        $transaction_id = trim( (string) $order->get_meta( self::META_CARDCOM_PAYMENT_ID, true ) );
        $status         = ( '' !== $transaction_id ) ? 'success' : 'failed';

        $payload = array(
            'orderId' => (int) $order->get_id(),
            'status'  => $status,
        );

        if ( 'success' === $status ) {
            $payload['cardcomPayment'] = array(
                'transactionId' => $transaction_id,
            );
        }

        return $payload;
    }

    /**
     * POST payment webhook v2 to StoreOS (does not use order sync meta / notes).
     *
     * @param WC_Order $order   Order.
     * @param array    $payload JSON body.
     * @param array    $options Plugin options.
     */
    protected function send_order_payment_webhook_v2_request( WC_Order $order, array $payload, array $options ) {
        $endpoint = trailingslashit( $options['api_base_url'] ) . 'WooCommerce/OrderPayment';

        $args = array(
            'method'      => 'POST',
            'timeout'     => 20,
            'headers'     => array(
                'Content-Type' => 'application/json',
                'X-Api-Key'    => $options['api_token'],
            ),
            'body'        => wp_json_encode( $payload ),
            'data_format' => 'body',
        );

        $response = wp_remote_post( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            $this->log_payment_webhook_v2_error( $order->get_id(), $response->get_error_message() );
            return;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code >= 200 && $code < 300 ) {
            $this->mark_payment_webhook_v2_ok( $order->get_id() );
            self::$payment_webhook_v2_ok_payload_hash[ $order->get_id() ] = md5( wp_json_encode( $payload ) );
            return;
        }

        $body = wp_remote_retrieve_body( $response );
        $this->log_payment_webhook_v2_error( $order->get_id(), 'HTTP ' . $code . ' — ' . $body );
    }

    /**
     * @param int    $order_id Order ID.
     * @param string $message  Error message.
     */
    protected function log_payment_webhook_v2_error( $order_id, $message ) {
        update_post_meta( $order_id, '_oc_storeos_payment_webhook_v2_error', $message );
        update_post_meta( $order_id, '_oc_storeos_payment_webhook_v2_at', current_time( 'mysql' ) );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( 'OC StoreOS OrderPayment v2 webhook error (order %d): %s', $order_id, $message ) );
        }
    }

    /**
     * @param int $order_id Order ID.
     */
    protected function mark_payment_webhook_v2_ok( $order_id ) {
        update_post_meta( $order_id, '_oc_storeos_payment_webhook_v2_error', '' );
        update_post_meta( $order_id, '_oc_storeos_payment_webhook_v2_at', current_time( 'mysql' ) );
    }

    /**
     * Handle payment complete event (API 2: OrderPayment).
     *
     * @param int $order_id Order ID.
     */
    public function handle_payment_complete( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $options = $this->get_options();
        if ( empty( $options['api_base_url'] ) || empty( $options['api_token'] ) ) {
            return;
        }

        $this->send_order_payment_to_api( $order, $options );
    }

    /**
     * Build and send payment payload to external API (OrderPayment).
     *
     * @param WC_Order $order   Order object.
     * @param array    $options Plugin options.
     */
    protected function send_order_payment_to_api( $order, $options ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $order_number = (string) $order->get_id();

        // Try to infer amount and paidAt from order data.
        $amount  = (float) $order->get_total();
        $paid_at = $order->get_date_paid();

        $payload = array(
            'orderNumber'     => $order_number,
            'siteId'          => ! empty( $options['site_id'] ) ? (string) $options['site_id'] : null,
            'invoiceNumber'   => $order->get_meta( '_invoice_number', true ),
            'paymentReference'=> $order->get_transaction_id(),
            'clearanceNumber' => $order->get_meta( '_payment_clearance_number', true ),
            'amount'          => $amount,
            'paidAt'          => $paid_at ? $paid_at->date( 'c' ) : current_time( 'c' ),
        );

        $endpoint = trailingslashit( $options['api_base_url'] ) . 'WooCommerce/OrderPayment';

        $args = array(
            'method'      => 'POST',
            'timeout'     => 20,
            'headers'     => array(
                'X-Api-Key'     => $options['api_token'],
                'Authorization' => 'Bearer ' . $options['api_token'],
                'Content-Type'  => 'application/json',
            ),
            'body'        => wp_json_encode( $payload ),
            'data_format' => 'body',
        );

        $response = wp_remote_post( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            $this->log_order_error( $order->get_id(), 'Payment sync error: ' . $response->get_error_message() );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            $body = wp_remote_retrieve_body( $response );
            $this->log_order_error( $order->get_id(), 'Payment sync HTTP ' . $code . ' - ' . $body );
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
        $synced_at = current_time( 'mysql' );
        update_post_meta( $order_id, self::META_LAST_SYNC, $synced_at );

        // Add an internal order note as a visible indicator in admin.
        $order = wc_get_order( $order_id );
        if ( $order instanceof WC_Order ) {
            $order->add_order_note(
                sprintf(
                /* translators: %s is a datetime in mysql format */
                    __( 'ההזמנה סונכרנה ל‑StoreOS בהצלחה (%s).', 'oc-storeos-integration' ),
                    $synced_at
                ),
                false,
                false
            );
        }
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

    /**
     * Temporary debug: print shipping-related meta for order 1921.
     */
    public function debug_order_meta_1921() {
        if ( ! is_admin() ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if ( ! isset( $_GET['debug_ocws_1921'] ) ) {
            return;
        }

        $order = wc_get_order( 1921 );
        if ( ! $order ) {
            echo '<pre>Order 1921 not found.</pre>';
            exit;
        }

        $meta_keys = array(
            'ocws_shipping_info',
            'ocws_shipping_info_date',
            'ocws_shipping_info_date_sortable',
            'ocws_shipping_info_slot_start',
            'ocws_shipping_info_slot_end',
            '_oc_storeos_delivery_date',
            '_oc_storeos_delivery_slot_start',
            '_oc_storeos_delivery_slot_end',
        );

        $data = array();
        foreach ( $meta_keys as $key ) {
            $data[ $key ] = $order->get_meta( $key, true );
        }

        echo '<pre>' . esc_html( print_r( $data, true ) ) . '</pre>';
        exit;
    }
}

