<?php

use App\Models\Order;
use Extensions\Servers\Wisp\Server;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    #[Locked]
    public int $order_id;

    #[Computed]
    public function order(): ?Order
    {
        return Order::query()->with('package.serverConnection')->find($this->order_id);
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function resources(): ?array
    {
        $order = $this->order;

        if (! $order || ! Server::usesWisp($order) || $order->status !== 'active' || empty($order->data['identifier'])) {
            return null;
        }

        try {
            return Server::actions()->resources($order);
        } catch (Throwable) {
            return ['error' => true];
        }
    }

    public function power(string $action): void
    {
        Server::actions()->powerAsClient([
            'order_id' => $this->order_id,
            'user_id' => auth()->id(),
            'action' => $action,
        ]);

        unset($this->resources);
        $this->dispatch('toast', type: 'success', message: 'Power action has been sent.', title: 'Success');
    }

    public function refreshResources(): void
    {
        unset($this->order, $this->resources);
    }

    public function megabytes(int|float $bytes): string
    {
        return number_format($bytes / 1024 / 1024, 0);
    }

    public function percent(int|float $value, int|float $limit): float
    {
        return $limit > 0 ? min(100, round($value / $limit * 100, 2)) : 0;
    }
}

?>

<div wire:poll.30s="refreshResources">
    @php
        $order = $this->order;
        $resources = $this->resources;
        $status = $resources['status'] ?? null;
        $online = in_array($status, [1, 2], true);
        $hostname = $order?->package?->serverConnection?->config['hostname'] ?? null;
        $account = $order?->getExternalUser();
    @endphp

    @if($order)
        <x-theme::card class="mb-4">
            <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white">Game Server</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        @if(isset($order->data['primary_ip']))
                            {{ $order->data['primary_ip'] }}:{{ $order->data['primary_port'] }}
                        @else
                            {{ $order->package->name }}
                        @endif
                        @if($online && !empty($order->data['identifier']))
                            · {{ $order->data['identifier'] }}
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if($order->status === 'suspended')
                        <x-theme::badge.warning text="Suspended" />
                    @elseif($online)
                        <x-theme::badge.success :text="$resources['state']" />
                    @elseif(in_array($status, [0, 3], true))
                        <x-theme::badge.danger :text="$resources['state']" />
                    @elseif($status !== null)
                        <x-theme::badge.warning :text="$resources['state']" />
                    @else
                        <x-theme::badge.primary text="Unknown" />
                    @endif

                    @if($hostname)
                        <x-theme::button.primary :href="$hostname" target="_blank" text="Login to Game Panel" />
                    @endif
                </div>
            </div>

            @if($order->status === 'suspended')
                <x-theme::alert.warning class="mb-4" text="This server is suspended. Renew the order to reactivate it." />
            @elseif(empty($order->data['identifier']))
                <x-theme::alert.primary text="Your server is still being created. Details will appear here once it is ready." />
            @elseif(($resources['error'] ?? false) === true)
                <x-theme::alert.warning text="Live usage could not be loaded from WISP. Refresh the page or contact support if this continues." />
            @elseif($online)
                <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">CPU</p>
                        <p class="mt-2 text-sm text-gray-900 dark:text-white">
                            {{ number_format($resources['cpu'], 2) }}% / {{ $resources['cpu_limit'] > 0 ? number_format($resources['cpu_limit'], 2).'%' : 'Unlimited' }}
                        </p>
                        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                            <div class="h-full rounded-full bg-primary-600" style="width: {{ $resources['cpu_limit'] > 0 ? $this->percent($resources['cpu'], $resources['cpu_limit']) : min(100, $resources['cpu']) }}%"></div>
                        </div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Memory</p>
                        <p class="mt-2 text-sm text-gray-900 dark:text-white">
                            {{ $this->megabytes($resources['memory']) }} MB / {{ $resources['memory_limit'] > 0 ? $this->megabytes($resources['memory_limit']).' MB' : 'Unlimited' }}
                        </p>
                        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                            <div class="h-full rounded-full bg-primary-600" style="width: {{ $this->percent($resources['memory'], $resources['memory_limit']) }}%"></div>
                        </div>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Disk</p>
                        <p class="mt-2 text-sm text-gray-900 dark:text-white">
                            {{ $this->megabytes($resources['disk']) }} MB / {{ $resources['disk_limit_mb'] > 0 ? $resources['disk_limit_mb'].' MB' : 'Unlimited' }}
                        </p>
                        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                            <div class="h-full rounded-full bg-primary-600" style="width: {{ $this->percent($resources['disk'], $resources['disk_limit_mb'] * 1024 * 1024) }}%"></div>
                        </div>
                    </div>
                </div>
            @endif

            @if($account)
                <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">Panel login: <span class="font-medium text-gray-900 dark:text-white">{{ $account->username }}</span></p>
            @endif

            @if($order->status === 'active' && !empty($order->data['identifier']))
                <div class="flex flex-wrap gap-2">
                    <x-theme::button.success type="button" wire:click="power('start')" wire:loading.attr="disabled" text="Start" />
                    <x-theme::button.primary type="button" wire:click="power('stop')" wire:loading.attr="disabled" wire:confirm="Stop this server?" text="Stop" />
                    <x-theme::button.warning type="button" wire:click="power('restart')" wire:loading.attr="disabled" wire:confirm="Restart this server?" text="Restart" />
                    <x-theme::button.danger type="button" wire:click="power('kill')" wire:loading.attr="disabled" wire:confirm="Kill this server? Unsaved data may be lost." text="Kill" />
                </div>
                @error('action') <x-theme::form.error :text="$message" /> @enderror
            @endif
        </x-theme::card>
    @endif
</div>
