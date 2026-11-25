<?php
namespace ContentMcpBridge\Abilities;

use ContentMcpBridge\AuditLog;
use WP_Error;

/**
 * Read-only access to WooCommerce Subscriptions: list and inspect
 * subscriptions, and find paid orders for a subscription product that never
 * got a subscription.
 *
 * Deliberately has no write abilities. Changing a subscription's status,
 * dates or payment method moves money, so that stays in wp-admin.
 *
 * Everything goes through wc_get_orders() and the WC_Subscription getters,
 * so it works the same whether the store keeps orders in posts or in the
 * HPOS tables.
 */
class Subscriptions implements AbilityGroup {
    private const READ_SUBSCRIPTIONS = 'read_private_shop_subscriptions';
    private const READ_ORDERS = 'read_private_shop_orders';

    private const MAX_PER_PAGE = 100;
    private const MAX_META_VALUE_LENGTH = 500;

    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * Dates WooCommerce Subscriptions tracks per subscription, as its own
     * date keys => the field name returned to the client.
     */
    private const DATES = [
        'start'                   => 'start_date',
        'trial_end'               => 'trial_end_date',
        'next_payment'            => 'next_payment_date',
        'last_order_date_created' => 'last_order_date',
        'cancelled'               => 'cancelled_date',
        'end'                     => 'end_date',
    ];

    private const RELATED_ORDER_TYPES = ['parent', 'renewal', 'switch', 'resubscribe'];

    public function registerReadOnly(): void {
        $readOnlyMeta = [
            'annotations' => [
                'readonly'    => true,
                'destructive' => false,
                'idempotent'  => true,
            ],
            'mcp'         => [
                'public' => true,
                'type'   => 'tool',
            ],
        ];

        wp_register_ability('content-mcp-bridge/list-subscriptions', [
            'label'               => 'List subscriptions',
            'description'         => 'Lists WooCommerce subscriptions, newest first, with status, customer, recurring total, billing schedule, next payment date, parent order and payment method. Filter by status, customer, billing email, creation date or manual renewal. Pass order_id instead to get the subscriptions an order belongs to (as parent, renewal, switch or resubscribe order); the other filters are then ignored. A subscription whose card is detached shows manual_renewal true or an empty payment reference in get-subscription.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'status'         => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'Statuses to include, e.g. ["active", "on-hold"]. One of pending, active, on-hold, cancelled, switched, expired, pending-cancel. Defaults to all of them.',
                    ],
                    'customer_id'    => [
                        'type'        => 'integer',
                        'description' => 'WordPress user ID of the customer.',
                    ],
                    'billing_email'  => [
                        'type'        => 'string',
                        'description' => 'Exact billing email address.',
                    ],
                    'created_after'  => [
                        'type'        => 'string',
                        'description' => 'Only subscriptions created on or after this date (YYYY-MM-DD).',
                    ],
                    'created_before' => [
                        'type'        => 'string',
                        'description' => 'Only subscriptions created on or before this date (YYYY-MM-DD).',
                    ],
                    'manual_renewal' => [
                        'type'        => 'boolean',
                        'description' => 'true for subscriptions that renew manually (no automatic card payment), false for automatic ones.',
                    ],
                    'order_id'       => [
                        'type'        => 'integer',
                        'description' => 'Return the subscriptions related to this order instead of searching.',
                    ],
                    'page'           => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    'per_page'       => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE, 'default' => 20],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'subscriptions' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'total'         => ['type' => 'integer'],
                    'total_pages'   => ['type' => 'integer'],
                    'page'          => ['type' => 'integer'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/list-subscriptions', [$this, 'listSubscriptions']),
            'permission_callback' => fn (): bool => current_user_can(self::READ_SUBSCRIPTIONS),
            'meta'                => $readOnlyMeta,
        ]);

        wp_register_ability('content-mcp-bridge/get-subscription', [
            'label'               => 'Get subscription',
            'description'         => 'Returns one WooCommerce subscription in full: status, customer, billing schedule and dates, line items, payment method with its stored payment references (Stripe customer and source IDs, saved payment tokens), and every related order (parent, renewal, switch, resubscribe) with its status, total and date. Set include_meta to also get the subscription\'s custom fields.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'subscription_id' => ['type' => 'integer'],
                    'include_meta'    => [
                        'type'        => 'boolean',
                        'default'     => false,
                        'description' => 'Also return the subscription\'s custom fields (text and number values only, long values truncated).',
                    ],
                ],
                'required'             => ['subscription_id'],
                'additionalProperties' => false,
            ],
            'output_schema'       => ['type' => 'object'],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/get-subscription', [$this, 'getSubscription']),
            'permission_callback' => fn (): bool => current_user_can(self::READ_SUBSCRIPTIONS),
            'meta'                => $readOnlyMeta,
        ]);

        wp_register_ability('content-mcp-bridge/find-orders-without-subscription', [
            'label'               => 'Find orders without a subscription',
            'description'         => 'Scans orders created in a date range and returns the ones that contain a subscription product but are not linked to any subscription — a paid signup where the subscription was never created. Orders are scanned a page at a time, oldest first; keep requesting the next page until page reaches total_pages. An order whose product has since been deleted can\'t be checked and is skipped.',
            'category'            => 'content-mcp-bridge',
            'input_schema'        => [
                'type'                 => 'object',
                'properties'           => [
                    'created_after'  => [
                        'type'        => 'string',
                        'description' => 'Start of the range, inclusive (YYYY-MM-DD).',
                    ],
                    'created_before' => [
                        'type'        => 'string',
                        'description' => 'End of the range, inclusive (YYYY-MM-DD). Defaults to today.',
                    ],
                    'status'         => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'Order statuses to scan, e.g. ["processing", "completed"]. Defaults to processing and completed (paid orders).',
                    ],
                    'page'           => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    'per_page'       => [
                        'type'        => 'integer',
                        'minimum'     => 1,
                        'maximum'     => self::MAX_PER_PAGE,
                        'default'     => 50,
                        'description' => 'Orders scanned per call (not orders returned).',
                    ],
                ],
                'required'             => ['created_after'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'orders'      => ['type' => 'array', 'items' => ['type' => 'object']],
                    'scanned'     => ['type' => 'integer'],
                    'total'       => ['type' => 'integer'],
                    'total_pages' => ['type' => 'integer'],
                    'page'        => ['type' => 'integer'],
                ],
            ],
            'execute_callback'    => AuditLog::wrap('content-mcp-bridge/find-orders-without-subscription', [$this, 'findOrdersWithoutSubscription']),
            'permission_callback' => fn (): bool => current_user_can(self::READ_SUBSCRIPTIONS) && current_user_can(self::READ_ORDERS),
            'meta'                => $readOnlyMeta,
        ]);
    }

    public function registerWrite(): void {
        // No write ability in this group.
    }

    public function listSubscriptions(array $input) {
        $orderId = (int)(    $input['order_id'] ?? 0    );

        if ($orderId) {
            return $this->subscriptionsForOrder($orderId);
        }

        $statuses = $this->subscriptionStatuses((array)(    $input['status'] ?? []    ));

        if (is_wp_error($statuses)) {
            return $statuses;
        }

        $created = $this->dateRange($input);

        if (is_wp_error($created)) {
            return $created;
        }

        $page = max(1, (int)(    $input['page'] ?? 1    ));

        $args = [
            'type'     => 'shop_subscription',
            'status'   => $statuses,
            'limit'    => $this->perPage($input, 20),
            'paged'    => $page,
            'paginate' => true,
            'orderby'  => 'date',
            'order'    => 'DESC',
        ];

        if ($created) {
            $args['date_created'] = $created;
        }

        if (!empty($input['customer_id'])) {
            $args['customer_id'] = (int)$input['customer_id'];
        }

        if (!empty($input['billing_email'])) {
            $args['billing_email'] = sanitize_email((string)$input['billing_email']);
        }

        if (isset($input['manual_renewal'])) {
            $args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                $input['manual_renewal']
                    ? ['key' => '_requires_manual_renewal', 'value' => 'true']
                    : ['key' => '_requires_manual_renewal', 'value' => 'true', 'compare' => '!='],
            ];
        }

        $result = wc_get_orders($args);

        return [
            'subscriptions' => array_values(array_map([$this, 'summary'], array_filter($result->orders, [$this, 'isSubscription']))),
            'total'         => (int)$result->total,
            'total_pages'   => (int)$result->max_num_pages, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'page'          => $page,
        ];
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function subscriptionsForOrder(int $orderId) {
        if (!wc_get_order($orderId)) {
            return new WP_Error('order_not_found', "Order {$orderId} was not found.");
        }

        $subscriptions = array_values(wcs_get_subscriptions_for_order($orderId, ['order_type' => 'any']));

        return [
            'subscriptions' => array_map([$this, 'summary'], $subscriptions),
            'total'         => count($subscriptions),
            'total_pages'   => 1,
            'page'          => 1,
        ];
    }

    public function getSubscription(array $input) {
        $id           = (int)(    $input['subscription_id'] ?? 0    );
        $subscription = $id ? wcs_get_subscription($id) : false;

        if (!$subscription) {
            return new WP_Error('subscription_not_found', "Subscription {$id} was not found.");
        }

        $items = [];

        foreach ($subscription->get_items() as $item) {
            $items[] = [
                'name'         => $item->get_name(),
                'product_id'   => (int)$item->get_product_id(),
                'variation_id' => (int)$item->get_variation_id(),
                'quantity'     => (int)$item->get_quantity(),
                'total'        => (string)$item->get_total(),
            ];
        }

        $data = array_merge($this->summary($subscription), [
            'created_via'    => (string)$subscription->get_created_via(),
            'customer_note'  => (string)$subscription->get_customer_note(),
            'items'          => $items,
            'payment'        => $this->payment($subscription),
            'related_orders' => $this->relatedOrders($subscription),
        ]);

        if (!empty($input['include_meta'])) {
            $data['meta'] = (object)$this->meta($subscription);
        }

        return $data;
    }

    public function findOrdersWithoutSubscription(array $input) {
        $created = $this->dateRange($input);

        if (is_wp_error($created)) {
            return $created;
        }

        $statuses = $this->orderStatuses((array)(    $input['status'] ?? []    ));

        if (is_wp_error($statuses)) {
            return $statuses;
        }

        $page = max(1, (int)(    $input['page'] ?? 1    ));

        $result = wc_get_orders([
            'type'         => 'shop_order',
            'status'       => $statuses,
            'date_created' => $created,
            'limit'        => $this->perPage($input, 50),
            'paged'        => $page,
            'paginate'     => true,
            'orderby'      => 'date',
            'order'        => 'ASC',
        ]);

        $orphans = [];

        foreach ($result->orders as $order) {
            $products = $this->subscriptionProductNames($order);

            if (!$products || wcs_get_subscriptions_for_order($order, ['order_type' => 'any'])) {
                continue;
            }

            $orphans[] = [
                'id'                    => $order->get_id(),
                'status'                => $order->get_status(),
                'date_created'          => $this->formatDate($order->get_date_created()),
                'customer_id'           => (int)$order->get_customer_id(),
                'billing_email'         => (string)$order->get_billing_email(),
                'total'                 => (string)$order->get_total(),
                'currency'              => (string)$order->get_currency(),
                'payment_method'        => (string)$order->get_payment_method(),
                'created_via'           => (string)$order->get_created_via(),
                'subscription_products' => $products,
            ];
        }

        return [
            'orders'      => $orphans,
            'scanned'     => count($result->orders),
            'total'       => (int)$result->total,
            'total_pages' => (int)$result->max_num_pages, // phpcs:ignore Zend.NamingConventions.ValidVariableName
            'page'        => $page,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(\WC_Subscription $subscription): array {
        $data = [
            'id'                   => $subscription->get_id(),
            'status'               => $subscription->get_status(),
            'date_created'         => $this->formatDate($subscription->get_date_created()),
            'customer_id'          => (int)$subscription->get_customer_id(),
            'billing_email'        => (string)$subscription->get_billing_email(),
            'billing_name'         => trim($subscription->get_billing_first_name().' '.$subscription->get_billing_last_name()),
            'billing_company'      => (string)$subscription->get_billing_company(),
            'total'                => (string)$subscription->get_total(),
            'currency'             => (string)$subscription->get_currency(),
            'billing_period'       => (string)$subscription->get_billing_period(),
            'billing_interval'     => (int)$subscription->get_billing_interval(),
            'parent_order_id'      => (int)$subscription->get_parent_id(),
            'payment_method'       => (string)$subscription->get_payment_method(),
            'payment_method_title' => (string)$subscription->get_payment_method_title(),
            'manual_renewal'       => (bool)$subscription->is_manual(),
            'products'             => array_values(array_map(
                static fn ($item): string => (string)$item->get_name(),
                $subscription->get_items()
            )),
        ];

        foreach (self::DATES as $key => $field) {
            // Stored in GMT as "Y-m-d H:i:s", or 0 when the date isn't set.
            $data[$field] = $subscription->get_date($key) ?: null;
        }

        return $data;
    }

    /**
     * What the gateway needs to charge a renewal. A subscription set to
     * automatic renewal with none of these references has lost its card.
     *
     * @return array<string, mixed>
     */
    private function payment(\WC_Subscription $subscription): array {
        return [
            'method'             => (string)$subscription->get_payment_method(),
            'method_title'       => (string)$subscription->get_payment_method_title(),
            'manual_renewal'     => (bool)$subscription->is_manual(),
            'payment_token_ids'  => array_values(array_map('intval', (array)$subscription->get_payment_tokens())),
            'stripe_customer_id' => (string)$subscription->get_meta('_stripe_customer_id'),
            'stripe_source_id'   => (string)$subscription->get_meta('_stripe_source_id'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function relatedOrders(\WC_Subscription $subscription): array {
        $related = [];

        foreach (self::RELATED_ORDER_TYPES as $type) {
            foreach ($subscription->get_related_orders('ids', $type) as $orderId) {
                $order = wc_get_order($orderId);

                if (!$order) {
                    continue;
                }

                $related[] = [
                    'id'           => (int)$orderId,
                    'relation'     => $type,
                    'status'       => $order->get_status(),
                    'date_created' => $this->formatDate($order->get_date_created()),
                    'total'        => (string)$order->get_total(),
                ];
            }
        }

        usort($related, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);

        return $related;
    }

    /**
     * @return array<string, string>
     */
    private function meta(\WC_Subscription $subscription): array {
        $meta = [];

        foreach ($subscription->get_meta_data() as $entry) {
            $value = $entry->value;

            if (!is_scalar($value)) {
                continue;
            }

            $value = (string)$value;

            if (mb_strlen($value) > self::MAX_META_VALUE_LENGTH) {
                $value = mb_substr($value, 0, self::MAX_META_VALUE_LENGTH).'…';
            }

            $meta[(string)$entry->key] = $value;
        }

        return $meta;
    }

    /**
     * @return string[] Names of the order's line items that are subscription products.
     */
    private function subscriptionProductNames(\WC_Order $order): array {
        $names = [];

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();

            if ($product && \WC_Subscriptions_Product::is_subscription($product)) {
                $names[] = (string)$item->get_name();
            }
        }

        return $names;
    }

    private function isSubscription($order): bool {
        return $order instanceof \WC_Subscription;
    }

    /**
     * @param string[] $requested Statuses without the "wc-" prefix; empty for all.
     * @return string[]|WP_Error Prefixed statuses, as both order stores expect them.
     */
    private function subscriptionStatuses(array $requested) {
        return $this->statuses($requested, array_keys(wcs_get_subscription_statuses()), []);
    }

    /**
     * @param string[] $requested
     * @return string[]|WP_Error
     */
    private function orderStatuses(array $requested) {
        return $this->statuses($requested, array_keys(wc_get_order_statuses()), ['wc-processing', 'wc-completed']);
    }

    /**
     * @param string[] $requested
     * @param string[] $valid Prefixed statuses.
     * @param string[] $default Used when nothing is requested; empty means all valid ones.
     * @return string[]|WP_Error
     */
    private function statuses(array $requested, array $valid, array $default) {
        if (!$requested) {
            return $default ?: $valid;
        }

        $statuses = [];

        foreach ($requested as $status) {
            $status = (string)$status;
            $status = str_starts_with($status, 'wc-') ? $status : 'wc-'.$status;

            if (!in_array($status, $valid, true)) {
                return new WP_Error(
                    'invalid_parameter',
                    "Unknown status '{$status}'. Valid statuses: ".implode(', ', array_map(
                        static fn (string $s): string => substr($s, 3),
                        $valid
                    )).'.'
                );
            }

            $statuses[] = $status;
        }

        return array_values(array_unique($statuses));
    }

    /**
     * Builds wc_get_orders()'s date_created value from created_after /
     * created_before.
     *
     * @return string|WP_Error Empty when neither bound is given.
     */
    private function dateRange(array $input) {
        $after  = (string)(    $input['created_after'] ?? ''    );
        $before = (string)(    $input['created_before'] ?? ''    );

        foreach (['created_after' => $after, 'created_before' => $before] as $name => $date) {
            if ($date !== '' && !preg_match(self::DATE_PATTERN, $date)) {
                return new WP_Error('invalid_parameter', "{$name} must be a date in YYYY-MM-DD format.");
            }
        }

        if ($after !== '' && $before !== '') {
            return $after.'...'.$before;
        }

        if ($after !== '') {
            return '>='.$after;
        }

        return $before !== '' ? '<='.$before : '';
    }

    private function perPage(array $input, int $default): int {
        return min(self::MAX_PER_PAGE, max(1, (int)(    $input['per_page'] ?? $default    )));
    }

    private function formatDate($date): ?string {
        return $date instanceof \DateTimeInterface ? $date->format('c') : null;
    }
}
