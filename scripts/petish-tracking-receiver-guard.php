<?php
/**
 * Request-local protection for the synthetic AST receiver rehearsal only.
 *
 * This file registers nothing when included. It is deliberately not a plugin,
 * supplier adapter, persistent shipment ledger, or production tracking receiver.
 */
final class Petish_Tracking_Receiver_Rehearsal_Guard {
    private array $context;
    private int $order_id;
    private string $lock_name;
    private bool $locked = false;
    private bool $installed = false;
    private bool $last_release_ok = true;
    private array $allocations = [];

    private function __construct(array $context, int $order_id) {
        $this->context = $context;
        $this->order_id = $order_id;
        $this->lock_name = 'petish-track-' . substr(hash('sha256', DB_NAME . '|' . $order_id), 0, 48);
    }

    public static function install(array $context, int $order_id): self {
        // Existing rehearsal checks prove the CLI, staging host/database,
        // synthetic customer, mail capture, and unpaid productless order.
        petish_tracking_context($context);
        petish_tracking_scoped_order($context, $order_id);
        if (!defined('DB_NAME') || !class_exists('WC_Advanced_Shipment_Tracking_REST_API_Controller')
            || !class_exists('WC_Advanced_Shipment_Tracking_Actions')
            || !class_exists('WC_Advanced_Shipment_Tracking_Admin')) petish_tracking_fail();
        $guard = new self($context, $order_id);
        // Unlike rest_request_before_callbacks, this runs AFTER native
        // permission_callback evaluation. It does not register a new route.
        add_filter('rest_dispatch_request', [$guard, 'dispatch'], PHP_INT_MAX, 4);
        $guard->installed = true;
        register_shutdown_function(static function() use ($guard) {
            try { $guard->uninstall(); } catch (Throwable $ignored) {}
        });
        return $guard;
    }

    public function uninstall(): void {
        if ($this->installed) {
            remove_filter('rest_dispatch_request', [$this, 'dispatch'], PHP_INT_MAX);
            $this->installed = false;
        }
        if ($this->locked) $this->release();
    }

    /** Trusted CLI fixture allocations, never populated from REST parameters. */
    public function set_confirmed_fixture_allocations(array $allocations): void {
        $order = petish_tracking_scoped_order($this->context, $this->order_id);
        $required = $this->required_quantities($order);
        if (!$required || !$allocations || count($allocations) > 50) petish_tracking_fail();
        $total = array_fill_keys(array_keys($required), 0);
        foreach ($allocations as $number => $lines) {
            if (!self::valid_number($number) || !is_array($lines) || !$lines) petish_tracking_fail();
            foreach ($lines as $line_id => $quantity) {
                if (!is_int($line_id) || !isset($required[$line_id]) || !is_int($quantity)
                    || $quantity < 1 || $quantity > $required[$line_id]) petish_tracking_fail();
                $total[$line_id] += $quantity;
            }
        }
        if ($total !== $required) petish_tracking_fail();
        $this->allocations = $allocations;
    }

    public function locks_released(): bool {
        global $wpdb;
        return !$this->locked && $this->last_release_ok
            && (string)$wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)', $this->lock_name)) === '1';
    }

    private static function error(string $code, int $status = 400): WP_Error {
        return new WP_Error('petish_tracking_' . $code, 'The synthetic tracking update was not accepted.',
            ['status' => $status]);
    }

    private static function valid_number($number): bool {
        return is_string($number) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $number) === 1;
    }

    private function required_quantities($order): array {
        $required = [];
        foreach ($order->get_items() as $item) {
            $id = (int)$item->get_id();
            $quantity = $item->get_quantity();
            if ($id < 1 || isset($required[$id]) || !is_numeric($quantity)
                || (float)$quantity !== (float)(int)$quantity || $quantity < 1 || $quantity > 10000) return [];
            $required[$id] = (int)$quantity;
        }
        ksort($required);
        return $required;
    }

    private function completion_covered($order, array $items, string $incoming): bool {
        if (!$this->allocations) return false;
        $required = $this->required_quantities($order);
        if (!$required) return false;
        $numbers = [];
        foreach ($items as $item) {
            if (!is_array($item) || ($item['tracking_provider'] ?? null) !== 'petish-rehearsal'
                || !self::valid_number($item['tracking_number'] ?? null)
                || isset($numbers[$item['tracking_number']])) return false;
            $numbers[$item['tracking_number']] = true;
        }
        $numbers[$incoming] = true;
        $covered = array_fill_keys(array_keys($required), 0);
        foreach ($numbers as $number => $ignored) {
            if (!isset($this->allocations[$number])) return false;
            foreach ($this->allocations[$number] as $line_id => $quantity) {
                if (!isset($required[$line_id])) return false;
                $covered[$line_id] += $quantity;
            }
        }
        return $covered === $required;
    }

    private function release(): void {
        global $wpdb;
        if (!$this->locked) return;
        $this->last_release_ok = (string)$wpdb->get_var(
            $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->lock_name)
        ) === '1';
        $this->locked = false;
        if (!$this->last_release_ok) petish_tracking_fail();
    }

    public function dispatch($result, $request, $route, $handler) {
        if ($result !== null || !$this->installed || !$request instanceof WP_REST_Request
            || $request->get_method() !== 'POST') return $result;
        $callback = $handler['callback'] ?? null;
        $permission = $handler['permission_callback'] ?? null;
        if (!is_array($callback) || count($callback) !== 2 || !is_object($callback[0])
            || get_class($callback[0]) !== 'WC_Advanced_Shipment_Tracking_REST_API_Controller'
            || $callback[1] !== 'create_item') return $result;
        if (!is_callable($permission)) return self::error('controller', 409);
        // Native dispatch has checked this already; retaining it here also
        // prevents a direct invocation from bypassing the reviewed controller.
        $allowed = call_user_func($permission, $request);
        if (is_wp_error($allowed)) return $allowed;
        if ($allowed !== true || !current_user_can('manage_woocommerce')) return self::error('permission', 403);
        $request_id = $request['order_id'];
        if (!preg_match('/\A\/wc-ast\/v3\/orders\/([1-9][0-9]*)\/shipment-trackings\z/D',
                $request->get_route(), $matched)
            || (!is_string($request_id) && !is_int($request_id))
            || $matched[1] !== (string)$this->order_id
            || (string)$request_id !== $matched[1]) return self::error('scope', 403);
        $actions = WC_Advanced_Shipment_Tracking_Actions::get_instance();
        if ((string)$actions->get_formated_order_id($this->order_id) !== (string)$this->order_id) {
            return self::error('scope', 403);
        }
        if ($this->locked) return self::error('busy', 409);
        global $wpdb;
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $this->lock_name)) !== '1') {
            return self::error('busy', 409);
        }
        $this->locked = true;
        $this->last_release_ok = false;
        try {
            // Fresh order/parcel reads occur only after obtaining the lock.
            $order = petish_tracking_scoped_order($this->context, $this->order_id);
            $number = $request['tracking_number'];
            if (!self::valid_number($number)) return self::error('number');
            if (!in_array($request['replace_tracking'], [null, 0, '0'], true)) {
                return self::error('replacement', 409);
            }
            if (!in_array($request['tracking_id'], [null, ''], true)) return self::error('existing_id');
            if (!in_array($request['status_shipped'], [0, 1, 2, '0', '1', '2'], true)) return self::error('status');
            $status = (int)$request['status_shipped'];
            $date_text = $request['date_shipped'];
            if (!is_string($date_text) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $date_text)) {
                return self::error('date');
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $date_text, new DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d') !== $date_text) return self::error('date');
            // Only the owned, non-routable fixture carrier is admitted. This
            // rehearsal is not a generic carrier URL or DSers payload parser.
            $link = 'https://tracking.petish.invalid/parcel/' . $number;
            if ($request['tracking_provider'] !== 'Petish staging carrier'
                || $request['custom_tracking_provider'] !== 'Petish staging carrier'
                || $request['custom_tracking_link'] !== $link
                || WC_Advanced_Shipment_Tracking_Admin::get_instance()
                    ->get_provider_slug_from_name('Petish staging carrier') !== 'petish-rehearsal') {
                return self::error('carrier');
            }
            $items = $actions->get_tracking_items($this->order_id);
            if (!is_array($items) || count($items) > 50) return self::error('records', 409);
            $matches = [];
            foreach ($items as $item) {
                if (!is_array($item)) return self::error('records', 409);
                if (($item['tracking_provider'] ?? null) === 'petish-rehearsal'
                    && ($item['tracking_number'] ?? null) === $number) $matches[] = $item;
            }
            if (count($matches) > 1) return self::error('records', 409);
            if ($matches) {
                $item = $matches[0];
                if (!is_string($item['tracking_id'] ?? null)
                    || !preg_match('/\A[a-f0-9]{32}\z/D', $item['tracking_id'])
                    || ($item['custom_tracking_link'] ?? null) !== $link
                    || !is_numeric($item['date_shipped'] ?? null)
                    || gmdate('Y-m-d', (int)$item['date_shipped']) !== $date_text
                    || (string)($item['status_shipped'] ?? '') !== (string)$status) {
                    return self::error('conflicting_replay', 409);
                }
                // Build a new native read request. Client body/query fields
                // cannot redirect the replay response to a different parcel.
                $read = new WP_REST_Request('GET',
                    '/wc-ast/v3/orders/' . $this->order_id . '/shipment-trackings/' . $item['tracking_id']);
                $read->set_url_params(['order_id'=>$this->order_id, 'id'=>$item['tracking_id']]);
                $read->set_param('context', 'edit');
                return $callback[0]->get_item($read);
            }
            if (!in_array($order->get_status(), ['processing', 'partial-shipped'], true)) {
                return self::error('order_status', 409);
            }
            if ($status === 1 && !$this->completion_covered($order, $items, $number)) {
                return self::error('coverage', 409);
            }
            // Invoke precisely the reviewed native callback under the lock.
            // Native REST response/status/hooks continue normally afterwards.
            return call_user_func($callback, $request);
        } finally {
            $this->release();
        }
    }
}
