<?php
declare(strict_types=1);

namespace WebhookRelay\REST;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class DeliveryController extends WP_REST_Controller
{
    protected $namespace = 'webhook-relay/v1';
    protected $rest_base = 'deliveries';

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'list_deliveries'],
            'permission_callback' => [$this, 'admin_check'],
            'args'                => [
                'subscription_id' => ['type' => 'integer'],
                'event'           => ['type' => 'string'],
                'status'          => ['type' => 'string', 'enum' => ['pending', 'processing', 'delivered', 'failed']],
                'page'            => ['type' => 'integer', 'default' => 1],
                'per_page'        => ['type' => 'integer', 'default' => 50, 'maximum' => 200],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)/retry', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'retry_delivery'],
            'permission_callback' => [$this, 'admin_check'],
        ]);

        register_rest_route($this->namespace, '/replay', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'replay_event'],
            'permission_callback' => [$this, 'admin_check'],
            'args'                => [
                'event'   => ['type' => 'string', 'required' => true],
                'payload' => ['type' => 'object', 'default' => []],
            ],
        ]);

        register_rest_route($this->namespace, '/log', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_log'],
            'permission_callback' => [$this, 'admin_check'],
            'args'                => [
                'queue_id'        => ['type' => 'integer'],
                'subscription_id' => ['type' => 'integer'],
                'page'            => ['type' => 'integer', 'default' => 1],
                'per_page'        => ['type' => 'integer', 'default' => 50],
            ],
        ]);
    }

    public function list_deliveries(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $table = $wpdb->prefix . WEBHOOK_RELAY_TABLE_QUEUE;

        $where = ['1=1'];
        $params = [];

        if ($request->get_param('subscription_id')) {
            $where[] = 'subscription_id = %d';
            $params[] = $request->get_param('subscription_id');
        }
        if ($request->get_param('event')) {
            $where[] = 'event = %s';
            $params[] = $request->get_param('event');
        }
        if ($request->get_param('status')) {
            $where[] = 'status = %s';
            $params[] = $request->get_param('status');
        }

        $where_sql = implode(' AND ', $where);
        $offset = ($request->get_param('page') - 1) * $request->get_param('per_page');

        $params[] = $request->get_param('per_page');
        $params[] = $offset;

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, subscription_id, event, attempts, max_attempts, status, next_attempt_at, created_at
                 FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                ...$params
            ),
            ARRAY_A
        );

        return new WP_REST_Response($items);
    }

    public function retry_delivery(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;
        $table = $wpdb->prefix . WEBHOOK_RELAY_TABLE_QUEUE;
        $id = (int) $request['id'];

        $item = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id),
            ARRAY_A
        );

        if (!$item) {
            return new WP_Error('not_found', 'Queue entry not found.', ['status' => 404]);
        }

        $wpdb->update(
            $table,
            [
                'status'          => 'pending',
                'attempts'        => 0,
                'next_attempt_at' => current_time('mysql', true),
            ],
            ['id' => $id],
            ['%s', '%d', '%s'],
            ['%d']
        );

        return new WP_REST_Response(['retrying' => $id]);
    }

    public function replay_event(WP_REST_Request $request): WP_REST_Response
    {
        $count = \WebhookRelay\WebhookRelay::dispatch(
            $request->get_param('event'),
            $request->get_param('payload')
        );

        return new WP_REST_Response(['queued' => $count]);
    }

    public function get_log(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $table = $wpdb->prefix . WEBHOOK_RELAY_TABLE_LOG;

        $where = ['1=1'];
        $params = [];

        if ($request->get_param('queue_id')) {
            $where[] = 'queue_id = %d';
            $params[] = $request->get_param('queue_id');
        }
        if ($request->get_param('subscription_id')) {
            $where[] = 'subscription_id = %d';
            $params[] = $request->get_param('subscription_id');
        }

        $where_sql = implode(' AND ', $where);
        $offset = ($request->get_param('page') - 1) * $request->get_param('per_page');

        $params[] = $request->get_param('per_page');
        $params[] = $offset;

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY attempted_at DESC LIMIT %d OFFSET %d",
                ...$params
            ),
            ARRAY_A
        );

        return new WP_REST_Response($items);
    }

    public function admin_check(): bool
    {
        return current_user_can('manage_options');
    }
}
