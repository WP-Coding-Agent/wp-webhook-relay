<?php
declare(strict_types=1);

namespace WebhookRelay\REST;

use WebhookRelay\Signer;
use WebhookRelay\Subscriber;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class SubscriptionController extends WP_REST_Controller
{
    protected $namespace = 'webhook-relay/v1';
    protected $rest_base = 'subscriptions';

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'list_subscriptions'],
                'permission_callback' => [$this, 'admin_check'],
                'args'                => [
                    'page'     => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                    'per_page' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
                ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create_subscription'],
                'permission_callback' => [$this, 'admin_check'],
                'args'                => [
                    'url'    => ['type' => 'string', 'format' => 'uri', 'required' => true],
                    'events' => ['type' => 'array', 'items' => ['type' => 'string'], 'required' => true],
                    'secret' => ['type' => 'string', 'default' => ''],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', [
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete_subscription'],
                'permission_callback' => [$this, 'admin_check'],
            ],
        ]);
    }

    public function list_subscriptions(WP_REST_Request $request): WP_REST_Response
    {
        $result = Subscriber::list(
            $request->get_param('page'),
            $request->get_param('per_page')
        );

        $response = new WP_REST_Response($result['items']);
        $response->header('X-WP-Total', (string) $result['total']);
        return $response;
    }

    public function create_subscription(WP_REST_Request $request): WP_REST_Response
    {
        $secret = $request->get_param('secret');
        if (empty($secret)) {
            $secret = Signer::generateSecret();
        }

        $id = Subscriber::create(
            $request->get_param('url'),
            $request->get_param('events'),
            $secret
        );

        return new WP_REST_Response([
            'id'     => $id,
            'secret' => $secret,
        ], 201);
    }

    public function delete_subscription(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $deleted = Subscriber::delete((int) $request['id']);

        if (!$deleted) {
            return new WP_Error('not_found', 'Subscription not found.', ['status' => 404]);
        }

        return new WP_REST_Response(null, 204);
    }

    public function admin_check(): bool
    {
        return current_user_can('manage_options');
    }
}
