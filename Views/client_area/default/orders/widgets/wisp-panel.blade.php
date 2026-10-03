@if(isset($order) && \Extensions\Servers\Wisp\Server::usesWisp($order))
    @livewire('client_area.default.orders.livewire.wisp-panel', ['order_id' => $order->id])
@endif
