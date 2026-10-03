<?php

namespace Extensions\Servers\Wisp\Actions;

use App\Actions\Action;
use App\Models\Order;
use App\Models\User;
use Extensions\Servers\Wisp\Server;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WispServerActions extends Action
{
    /**
     * WISP server status codes.
     *
     * @var array<int, string>
     */
    public const STATES = [
        0 => 'Stopped',
        1 => 'Running',
        2 => 'Starting',
        3 => 'Stopping',
        10 => 'Migrating',
        20 => 'Installing',
        21 => 'Failed Install',
        30 => 'Suspended',
        31 => 'Updating',
        32 => 'Moving',
        40 => 'Creating Backup',
        41 => 'Deploying Backup',
    ];

    /**
     * Live status, resource usage and primary allocation of the server.
     *
     * @return array<string, mixed>
     */
    public function resources(Order $order): array
    {
        $config = $order->package->serverConnection->config;
        $identifier = $order->data['identifier'] ?? null;

        if (! $identifier) {
            return ['status' => null];
        }

        if (! isset($order->data['primary_ip'])) {
            $server = Server::api($config, 'get', "/servers/{$identifier}", ['include' => 'allocations'], 'client');
            $allocation = $server->json('attributes.relationships.allocations.data.0.attributes');

            if ($allocation) {
                $order->update([
                    'data' => array_merge($order->data ?? [], [
                        'primary_ip' => $allocation['ip_alias'] ?? $allocation['ip'] ?? null,
                        'primary_port' => $allocation['port'] ?? null,
                    ]),
                ]);
            }
        }

        $response = Server::api($config, 'get', "/servers/{$identifier}/resources", [], 'client');
        $status = $response->json('status', $response->json('attributes.status'));
        $proc = $response->json('proc', $response->json('attributes.proc', []));

        return [
            'status' => is_numeric($status) ? (int) $status : null,
            'state' => self::STATES[(int) $status] ?? 'Unknown Status',
            'cpu' => (float) data_get($proc, 'cpu.total', 0),
            'cpu_limit' => (float) data_get($proc, 'cpu.limit', 0),
            'memory' => (int) data_get($proc, 'memory.total', 0),
            'memory_limit' => (int) data_get($proc, 'memory.limit', 0),
            'disk' => (int) data_get($proc, 'disk.used', 0),
            'disk_limit_mb' => (int) data_get($proc, 'disk.limit', 0),
        ];
    }

    /**
     * Send a power signal (start, stop, restart, kill) to the server.
     *
     * @param  array<string, mixed>  $input
     */
    public function powerAsClient(array $input): Order
    {
        $validated = Validator::make($input, [
            'order_id' => ['required', 'exists:orders,id'],
            'user_id' => ['required', 'exists:users,id'],
            'action' => ['required', 'in:start,stop,restart,kill'],
        ])->validate();

        $order = $this->authorizedOrder($validated['order_id'], $validated['user_id']);

        $key = "wisp-power:{$order->id}:{$validated['user_id']}";

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'action' => 'Too many power actions. Please wait '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        RateLimiter::hit($key, 60);

        Server::api($order->package->serverConnection->config, 'post', "/servers/{$order->data['identifier']}/power", [
            'signal' => $validated['action'],
        ], 'client');

        return $order;
    }

    protected function authorizedOrder(int|string $orderId, int|string $userId): Order
    {
        $order = Order::query()->with(['package.serverConnection', 'members'])->find($orderId);
        $user = User::query()->find($userId);

        if (! $order || ! Server::usesWisp($order)) {
            throw ValidationException::withMessages(['order_id' => 'This order is not provisioned on WISP.']);
        }

        $isOwner = $user && (int) $order->user_id === (int) $user->id;
        $isMember = $user && $order->members()->where('status', 'active')->where('user_id', $user->id)->exists();

        if (! $isOwner && ! $isMember) {
            throw ValidationException::withMessages(['order_id' => 'You do not have access to this order.']);
        }

        if ($order->status !== 'active') {
            throw ValidationException::withMessages(['order_id' => 'Power actions are only available while the server is active.']);
        }

        if (empty($order->data['identifier'])) {
            throw ValidationException::withMessages(['order_id' => 'This server has not finished provisioning yet.']);
        }

        return $order;
    }
}
