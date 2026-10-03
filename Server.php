<?php

namespace Extensions\Servers\Wisp;

use App\Extensions\Foundation\ServerExtension;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackagePrice;
use App\Models\ServerConnection;
use Exception;
use Extensions\Servers\Wisp\Actions\WispServerActions;
use Extensions\Servers\Wisp\Providers\EmailServiceProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Server extends ServerExtension
{
    protected string $id = 'server-wisp';

    protected string $name = 'Wisp';

    protected string $description = 'Sell game servers on a WISP panel.';

    protected string $type = 'Server';

    protected string $version = '1.0.0';

    protected array $wemxVersions = ['*'];

    protected array $authors = [
        [
            'name' => 'WemX',
            'email' => 'mubeen@wemx.net',
        ],
    ];

    public function providers(): array
    {
        return [
            EmailServiceProvider::class,
        ];
    }

    public function elements(): array
    {
        return [
            [
                'element' => 'client-order-top-view',
                'view' => 'server-wisp::client_area.default.orders.widgets.wisp-panel',
            ],
        ];
    }

    public function setConfig(): array
    {
        $doesNotEndWithSlash = function ($attribute, $value, $fail) {
            if (is_string($value) && preg_match('/\/$/', $value)) {
                $fail('Hostname URL must not end with a slash "/". It should be like https://panel.example.com');
            }
        };

        return [
            [
                'key' => 'hostname',
                'name' => 'Hostname',
                'description' => 'Hostname of your WISP panel i.e https://panel.example.com',
                'type' => 'url',
                'default_value' => 'https://panel.example.com',
                'rules' => ['required', 'active_url', $doesNotEndWithSlash],
            ],
            [
                'key' => 'api_key',
                'name' => 'API Key',
                'description' => 'Application API Key of your WISP panel',
                'type' => 'password',
                'rules' => ['required'],
            ],
            [
                'key' => 'client_api_key',
                'name' => 'Client API Key',
                'description' => 'Client API Key of an administrator on your WISP panel. Used for power actions and live resource usage.',
                'type' => 'password',
                'rules' => ['required'],
            ],
        ];
    }

    public function setPackageConfig(Package $package, ServerConnection $connection): array
    {
        $locations = $this->cachedLocations($connection);
        $locationList = collect($locations)->map(fn ($name, $id) => "{$id} = {$name}")->implode(', ');

        $config = [
            [
                'col' => 'col-4',
                'key' => 'database_limit',
                'name' => 'Database Limit',
                'description' => 'The total number of databases a user is allowed to create for this server on WISP.',
                'type' => 'number',
                'min' => 0,
                'default_value' => 0,
                'rules' => ['required', 'numeric', 'min:0'],
                'is_configurable' => true,
            ],
            [
                'col' => 'col-4',
                'key' => 'backup_limit_size',
                'name' => 'Backup Size Limit in MB',
                'description' => 'The total size of backups that can be created for this server on WISP.',
                'type' => 'number',
                'min' => 0,
                'default_value' => 0,
                'rules' => ['required', 'numeric', 'min:0'],
                'is_configurable' => true,
            ],
            [
                'col' => 'col-4',
                'key' => 'cpu_limit',
                'name' => 'CPU Limit in %',
                'description' => 'If you do not want to limit CPU usage, set the value to 0. To use a single thread set it to 100%, for 4 threads set to 400% etc',
                'type' => 'number',
                'min' => 0,
                'default_value' => 100,
                'rules' => ['required', 'numeric', 'min:0'],
                'is_configurable' => true,
            ],
            [
                'col' => 'col-4',
                'key' => 'memory_limit',
                'name' => 'Memory Limit in MB',
                'description' => 'The maximum amount of memory allowed for this container. Setting this to 0 will allow unlimited memory in a container.',
                'type' => 'number',
                'min' => 0,
                'default_value' => 1024,
                'rules' => ['required', 'numeric', 'min:0'],
                'is_configurable' => true,
            ],
            [
                'col' => 'col-4',
                'key' => 'disk_limit',
                'name' => 'Disk Limit in MB',
                'description' => 'The maximum amount of disk space allowed for this container. Setting this to 0 will allow unlimited disk space.',
                'type' => 'number',
                'min' => 0,
                'default_value' => 5120,
                'rules' => ['required', 'numeric', 'min:0'],
                'is_configurable' => true,
            ],
            [
                'col' => 'col-4',
                'key' => 'cpu_pinning',
                'name' => 'CPU Pinning (optional)',
                'description' => 'Advanced: Enter the specific CPU threads that this process can run on, or leave blank to allow all threads. Example: 0, 0-1,3, or 0,1,3,4.',
                'type' => 'text',
                'rules' => ['nullable'],
                'is_configurable' => false,
            ],
            [
                'col' => 'col-4',
                'key' => 'swap_limit',
                'name' => 'Swap Limit in MB',
                'description' => 'The maximum amount of swap allowed for this container. Setting this to 0 will disable swap. Setting this to -1 will allow unlimited swap.',
                'type' => 'number',
                'default_value' => 0,
                'rules' => ['required', 'numeric', 'min:-1'],
                'is_configurable' => false,
            ],
            [
                'col' => 'col-4',
                'key' => 'block_io_weight',
                'name' => 'Block IO Weight',
                'description' => 'The relative weight of IO for this container. This accepts a value between 10 and 1000. The default value is 500.',
                'type' => 'number',
                'default_value' => 500,
                'rules' => ['required', 'numeric', 'min:10', 'max:1000'],
                'is_configurable' => false,
            ],
            [
                'col' => 'col-4',
                'key' => 'dedicated_ip',
                'name' => 'Dedicated IP',
                'description' => 'Assign a dedicated IP to this server.',
                'type' => 'select',
                'options' => [
                    '0' => 'No',
                    '1' => 'Yes',
                ],
                'default_value' => '0',
                'rules' => ['required', 'in:0,1'],
                'is_configurable' => true,
            ],
            [
                'col' => 'col-12',
                'key' => 'locations',
                'name' => 'Selectable Locations',
                'description' => 'Comma separated location IDs the server can be deployed to when the customer does not select a location. Available: '.($locationList ?: 'could not load locations'),
                'type' => 'text',
                'default_value' => implode(',', array_keys($locations)),
                'rules' => ['required', 'string', 'regex:/^\s*\d+(\s*,\s*\d+)*\s*$/'],
                'is_configurable' => false,
            ],
            [
                'col' => 'col-12',
                'key' => 'location_id',
                'name' => 'Location',
                'description' => 'Make this a configurable option to let customers pick the location at checkout. "Any" deploys to one of the selectable locations above.',
                'type' => 'select',
                'options' => ['0' => 'Any selectable location'] + $locations,
                'default_value' => '0',
                'rules' => ['required', 'numeric'],
                'is_configurable' => true,
            ],
            [
                'col' => 'col-6',
                'key' => 'nest_id',
                'name' => 'Nest ID',
                'description' => 'Nest ID of the server you want to use for this package. You can find the nest ID by going to the nest page and looking at the URL.',
                'type' => 'text',
                'default_value' => 2,
                'rules' => ['required', 'numeric'],
                'is_configurable' => false,
            ],
            [
                'col' => 'col-6',
                'key' => 'egg_id',
                'name' => 'Egg ID',
                'description' => 'Egg ID of the server you want to use for this package. You can find the egg ID by going to the egg page and looking at the URL. Save the package once to load the egg variables.',
                'type' => 'text',
                'default_value' => 2,
                'rules' => ['required', 'numeric'],
                'is_configurable' => false,
            ],
        ];

        try {
            $nestId = (int) $package->data('nest_id', 2);
            $eggId = (int) $package->data('egg_id', 2);
            $connectionId = $connection->id ?? 'default';

            $egg = Cache::remember("wisp:egg:{$connectionId}:{$nestId}:{$eggId}", now()->addHour(), function () use ($connection, $nestId, $eggId) {
                $response = self::api($connection->config, 'get', "/nests/{$nestId}/eggs/{$eggId}", ['include' => 'variables']);

                if (! isset($response['attributes'])) {
                    throw new \RuntimeException('Invalid egg response from WISP.');
                }

                return $response['attributes'];
            });
        } catch (\Throwable) {
            return $config;
        }

        $config[] = [
            'col' => 'col-12',
            'key' => 'docker_image',
            'name' => 'Docker Image',
            'description' => 'Docker image to use for this server',
            'type' => 'text',
            'default_value' => $egg['docker_image'] ?? null,
            'rules' => ['required'],
            'is_configurable' => false,
        ];

        $config[] = [
            'col' => 'col-12',
            'key' => 'startup',
            'name' => 'Start Up Command',
            'description' => 'The command that will be executed when the server is started. i.e java -Xms128M -Xmx128M -jar server.jar',
            'type' => 'textarea',
            'default_value' => $egg['startup'] ?? null,
            'rules' => ['required'],
            'is_configurable' => false,
        ];

        foreach (data_get($egg, 'relationships.variables.data', []) as $variable) {
            $variable = $variable['attributes'] ?? [];

            if (! isset($variable['env_variable'])) {
                continue;
            }

            $rules = $variable['rules'] ?? 'nullable';

            $config[] = [
                'col' => 'col-4',
                'key' => "environment.{$variable['env_variable']}",
                'name' => $variable['name'] ?? $variable['env_variable'],
                'description' => $variable['description'] ?? '',
                'type' => 'text',
                'default_value' => $variable['default_value'] ?? '',
                'rules' => is_string($rules) ? explode('|', $rules) : $rules,
                'is_configurable' => false,
            ];
        }

        return $config;
    }

    public static function testConnection(array $credentials)
    {
        self::api($credentials, 'get', '/nodes');

        return 'Successfully connected to Wisp API';
    }

    /**
     * Make a request to the WISP API.
     *
     * @param  string  $type  "application" for the admin API, "client" for the client API
     */
    public static function api(array $credentials, string $method, string $endpoint, array $data = [], string $type = 'application'): Response
    {
        $method = strtolower($method);

        if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'])) {
            throw new Exception('[WISP] Invalid method');
        }

        $url = rtrim($credentials['hostname'] ?? '', '/')."/api/{$type}{$endpoint}";
        $apiKey = $type === 'application' ? ($credentials['api_key'] ?? '') : ($credentials['client_api_key'] ?? '');

        $response = Http::withToken($apiKey)
            ->accept($type === 'application' ? 'application/json' : 'application/vnd.wisp.v1+json')
            ->$method($url, $data);

        if ($response->failed()) {
            if ($response->unauthorized() || $response->forbidden()) {
                throw new Exception('[WISP] This action is unauthorized! Confirm that API token has the right permissions');
            }

            $errorCode = $response->json('errors.0.code');

            if ($errorCode === 'admin.servers.deployment.no_viable_nodes') {
                throw new Exception('[WISP] No viable nodes found for deployment within the allowed locations. Please ensure the nodes arent full.');
            }

            if ($errorCode === 'admin.servers.deployment.no_viable_allocations') {
                throw new Exception('[WISP] No viable allocations found for deployment within the allowed locations. Please ensure the nodes have available allocations');
            }

            if ($response->serverError()) {
                throw new Exception("[WISP] Internal Server Error: {$response->status()}");
            }

            throw new Exception("[WISP] Request to {$endpoint} failed with status {$response->status()}: {$response->body()}");
        }

        return $response;
    }

    public function create(Order $order, ServerConnection $connection)
    {
        $package = $order->package;
        $config = $connection->config;

        if (! $order->hasExternalUser()) {
            self::createWispUser($order, $connection);
        }

        $locationId = (int) $order->option('location_id', 0);
        $locations = $locationId > 0 ? [$locationId] : self::packageLocations($package);

        $limits = [
            'memory' => (int) $order->option('memory_limit', 0),
            'swap' => (int) $order->option('swap_limit', 0),
            'disk' => (int) $order->option('disk_limit', 0),
            'io' => (int) $order->option('block_io_weight', 500),
            'cpu' => (int) $order->option('cpu_limit', 0),
        ];

        if ($package->data('cpu_pinning')) {
            $limits['threads'] = $package->data('cpu_pinning');
        }

        $response = self::api($config, 'post', '/servers', [
            'name' => "{$order->user->username} {$package->name} Server",
            'external_id' => "wemx-{$order->id}",
            'description' => settings('app_name', 'WemX')." || {$order->user->username}",
            'user' => $order->getExternalUser()->external_id,
            'nest' => (int) $package->data('nest_id', 2),
            'egg' => (int) $package->data('egg_id', 2),
            'docker_image' => $package->data('docker_image'),
            'startup' => $package->data('startup'),
            'environment' => $package->data('environment', []),
            'limits' => $limits,
            'feature_limits' => [
                'databases' => (int) $order->option('database_limit', 0),
                'backup_megabytes_limit' => (int) $order->option('backup_limit_size', 0),
            ],
            'deploy' => [
                'locations' => $locations,
                'dedicated_ip' => (string) $order->option('dedicated_ip', '0') === '1',
                'port_range' => [],
            ],
            'start_on_completion' => true,
            'skip_scripts' => false,
            'oom_disabled' => false,
            'swap_disabled' => false,
        ]);

        if (! isset($response['attributes'])) {
            throw new Exception('[WISP] Failed to create server on Wisp.');
        }

        $server = $response['attributes'];

        $order->update([
            'external_id' => $server['id'],
            'data' => $server,
        ]);
    }

    public function upgradeOrDowngrade(Order $order, PackagePrice $oldPackagePrice, PackagePrice $newPackagePrice, ServerConnection $connection)
    {
        $server = $order->data ?? [];
        $serverId = $order->external_id ?: ($server['id'] ?? null);

        self::api($connection->config, 'put', "/servers/{$serverId}/build", [
            'allocation_id' => $server['allocation'] ?? null,
            'memory' => (int) $order->option('memory_limit', 0),
            'swap' => (int) $order->option('swap_limit', 0),
            'disk' => (int) $order->option('disk_limit', 0),
            'io' => (int) $order->option('block_io_weight', 500),
            'cpu' => (int) $order->option('cpu_limit', 0),
            'database_limit' => (int) $order->option('database_limit', 0),
            'backup_megabytes_limit' => (int) $order->option('backup_limit_size', 0),
        ]);
    }

    public function changePassword(Order $order, string $newPassword)
    {
        $wispUser = $order->getExternalUser();

        self::api($order->package->serverConnection->config, 'patch', "/users/{$wispUser->external_id}", [
            'first_name' => $order->user->first_name,
            'last_name' => $order->user->last_name,
            'email' => $order->user->email,
            'password' => $newPassword,
        ]);

        $order->updateExternalPassword($newPassword);
    }

    public function suspend(Order $order, ServerConnection $connection)
    {
        self::api($connection->config, 'post', '/servers/'.self::serverId($order).'/suspend');
    }

    public function unsuspend(Order $order, ServerConnection $connection)
    {
        self::api($connection->config, 'post', '/servers/'.self::serverId($order).'/unsuspend');
    }

    public function terminate(Order $order, ServerConnection $connection)
    {
        self::api($connection->config, 'delete', '/servers/'.self::serverId($order));
    }

    public static function actions(): WispServerActions
    {
        return new WispServerActions;
    }

    public static function usesWisp(?Order $order): bool
    {
        return $order?->package?->serverConnection?->extension_identifier === 'server-wisp';
    }

    public static function serverId(Order $order): string
    {
        $id = $order->external_id ?: ($order->data['id'] ?? null);

        if (! $id) {
            throw new Exception('[WISP] This order does not have a server yet.');
        }

        return (string) $id;
    }

    /**
     * @return array<int, int>
     */
    public static function packageLocations(Package $package): array
    {
        $locations = $package->data('locations', []);

        if (is_string($locations)) {
            $locations = explode(',', $locations);
        }

        return collect($locations)
            ->map(fn ($id) => (int) trim((string) $id))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Find an existing WISP user with the same email, or create one and email the login.
     */
    public static function createWispUser(Order $order, ServerConnection $connection): void
    {
        $user = $order->user;

        $existing = self::api($connection->config, 'get', '/users', ['search' => $user->email]);

        foreach ($existing['data'] ?? [] as $wispUser) {
            if (strcasecmp($wispUser['attributes']['email'] ?? '', $user->email) === 0) {
                $order->createExternalUser([
                    'external_id' => $wispUser['attributes']['id'],
                    'username' => $wispUser['attributes']['email'],
                    'password' => '',
                    'data' => $wispUser['attributes'],
                ]);

                return;
            }
        }

        $password = Str::random(12);

        $created = self::api($connection->config, 'post', '/users', [
            'external_id' => "wemx-{$user->id}",
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'password' => $password,
            'root_admin' => false,
        ]);

        $order->createExternalUser([
            'external_id' => $created['attributes']['id'],
            'username' => $created['attributes']['email'],
            'password' => $password,
            'data' => $created['attributes'],
        ]);

        $order->user->email([
            'identifier' => 'server.wisp.account_created',
            'mailable_type' => Order::class,
            'mailable_id' => $order->id,
            'subject' => 'Game Panel Account Created',
            'lines' => [
                'Your account has been created on the game panel.',
                'You can login using the following details:',
                "Email: {$created['attributes']['email']}",
                "Password: {$password}",
            ],
            'variables' => [
                'panel_email' => $created['attributes']['email'],
                'panel_password' => $password,
            ],
            'button' => [
                'text' => 'Login to Game Panel',
                'url' => $connection->config['hostname'] ?? route('orders.view', $order->id),
            ],
        ]);
    }

    /**
     * @return array<int|string, string>
     */
    protected function cachedLocations(ServerConnection $connection): array
    {
        $connectionId = $connection->id ?? 'new';

        try {
            return Cache::remember("wisp:locations:{$connectionId}", now()->addHour(), function () use ($connection) {
                return collect(self::api($connection->config, 'get', '/locations')['data'] ?? [])
                    ->mapWithKeys(fn ($item) => [
                        (string) $item['attributes']['id'] => $item['attributes']['long'] ?? $item['attributes']['short'] ?? (string) $item['attributes']['id'],
                    ])
                    ->all();
            });
        } catch (\Throwable) {
            return [];
        }
    }
}
