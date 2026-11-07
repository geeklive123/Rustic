<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Reportes diarios · Rustik POS</title>
    <style>
        :root{color-scheme:light;--ink:#202a25;--muted:#6c7970;--green:#244c39;--line:#e6ebe6;--paper:#fff;--bg:#f5f7f3}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,-apple-system,sans-serif}main{max-width:1240px;margin:auto;padding:36px 24px 64px}.header{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:32px}.brand{font-size:12px;font-weight:800;letter-spacing:.16em;color:#547c63;text-transform:uppercase}.header h1{font-size:clamp(26px,4vw,38px);letter-spacing:-.04em;line-height:1.1;margin:8px 0}.sub,.muted{color:var(--muted)}.refresh{background:var(--green);color:white;text-decoration:none;padding:12px 19px;border-radius:10px;font-weight:700;white-space:nowrap}.refresh:hover{background:#163a29}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.card,.panel{background:var(--paper);border:1px solid var(--line);border-radius:16px;box-shadow:0 4px 20px #233a2910}.card{padding:21px;min-height:140px}.card.highlight{background:var(--green);color:white;border-color:var(--green)}.label{font-size:13px;font-weight:650;color:var(--muted)}.highlight .label,.highlight .detail{color:#c7d9cc}.value{font-size:clamp(23px,2.7vw,30px);font-weight:750;letter-spacing:-.04em;margin:12px 0 3px}.detail{font-size:12px;color:var(--muted)}.tag{display:inline-block;background:#fff3dc;color:#8c5b0d;font-size:11px;font-weight:700;padding:3px 8px;border-radius:20px}.section-title{font-size:18px;letter-spacing:-.02em;margin:32px 0 14px}.columns{display:grid;grid-template-columns:1fr 1.6fr;gap:16px}.panel{padding:22px;min-width:0}.panel h2{font-size:17px;margin:0 0 4px}.panel p{margin:0 0 16px;font-size:13px}.ranking{list-style:none;margin:0;padding:0}.ranking li{display:flex;align-items:center;gap:12px;padding:13px 0;border-bottom:1px solid var(--line)}.ranking li:last-child{border:0}.rank{display:grid;place-items:center;width:30px;height:30px;border-radius:9px;background:#edf3ed;color:var(--green);font-weight:700}.product{flex:1}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;white-space:nowrap;text-align:left}th{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}td,th{padding:12px 9px;border-bottom:1px solid var(--line)}td:last-child,th:last-child{text-align:right}tr:last-child td{border:0}.empty{padding:25px 0;color:var(--muted)}.foot{font-size:12px;color:var(--muted);margin-top:24px}@media(max-width:850px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.columns{grid-template-columns:1fr}}@media(max-width:480px){main{padding:24px 16px 48px}.header{align-items:flex-start}.refresh{padding:10px 13px}.card{padding:16px;min-height:125px}.grid{gap:10px}.value{font-size:22px}}
    </style>
</head>
<body>
@php
    $money = fn ($cents) => 'Bs '.number_format(((int) $cents) / 100, 2, ',', '.');
    $statuses = ['cash_collected' => 'Efectivo cobrado', 'simulated' => 'QR simulado', 'qr_verified' => 'QR verificado', 'qr_pending' => 'QR pendiente', 'unpaid' => 'Sin pagar'];
@endphp
<main>
    <header class="header"><div><div class="brand">Rustik POS / Reportes</div><h1>Resumen de hoy</h1><div class="sub">{{ $now->format('d/m/Y') }} · Hora de Bolivia</div></div><a class="refresh" href="{{ url('/reportes') }}">&#8635; Actualizar</a></header>
    <div class="grid">
        <section class="card highlight"><div class="label">Total vendido</div><div class="value">{{ $money($summary['total_cents']) }}</div><div class="detail">Valor de los pedidos de hoy</div></section>
        <section class="card"><div class="label">Pedidos</div><div class="value">{{ number_format($summary['order_count'], 0, ',', '.') }}</div><div class="detail">Registrados hoy</div></section>
        <section class="card"><div class="label">Efectivo cobrado</div><div class="value">{{ $money($summary['cash_cents']) }}</div><div class="detail">Pagos en efectivo cobrados</div></section>
        <section class="card"><div class="label">QR_STATIC verificado</div><div class="value">{{ $money($summary['verified_qr_cents']) }}</div><div class="detail">{{ $summary['verified_qr_count'] }} pagos verificados</div></section>
        <section class="card"><div class="label">QR_SIMULATED</div><div class="value">{{ $money($summary['simulated_qr_cents']) }}</div><span class="tag">Simulado · no es cobro real</span></section>
        <section class="card"><div class="label">Pedidos con pago pendiente</div><div class="value">{{ $summary['unpaid_count'] }}</div><div class="detail">Sin pagar o QR pendiente</div></section>
        <section class="card"><div class="label">Producto más vendido</div><div class="value" style="font-size:20px;overflow-wrap:anywhere">{{ $products->first()->product_name ?? 'Sin ventas' }}</div><div class="detail">{{ $products->isNotEmpty() ? $products->first()->quantity.' unidades' : 'Aún no hay productos' }}</div></section>
    </div>
    <h2 class="section-title">Actividad del día</h2>
    <div class="columns">
        <section class="panel"><h2>Top 5 productos</h2><p class="muted">Ordenados por unidades vendidas</p>
            @if($products->isEmpty()) <div class="empty">Todavía no hay productos vendidos hoy.</div> @else
            <ol class="ranking">@foreach($products as $product)<li><span class="rank">{{ $loop->iteration }}</span><span class="product">{{ $product->product_name }}</span><strong>{{ $product->quantity }} uds.</strong></li>@endforeach</ol>@endif
        </section>
        <section class="panel"><h2>Últimas 10 ventas</h2><p class="muted">Pedidos registrados hoy</p>
            @if($sales->isEmpty()) <div class="empty">Todavía no hay ventas registradas hoy.</div> @else
            <div class="table-wrap"><table><thead><tr><th>Pedido</th><th>Hora</th><th>Pago</th><th>Total</th></tr></thead><tbody>@foreach($sales as $sale)<tr><td>#{{ $sale->id }}</td><td>{{ \Carbon\CarbonImmutable::parse($sale->created_at, 'UTC')->setTimezone('America/La_Paz')->format('H:i') }}</td><td>{{ $statuses[$sale->payment_status] ?? $sale->payment_status }}</td><td>{{ $money($sale->total_cents) }}</td></tr>@endforeach</tbody></table></div>@endif
        </section>
    </div>
    <p class="foot">Actualizado a las {{ $now->format('H:i:s') }} · America/La_Paz. El total vendido incluye pedidos pendientes y pagos simulados; no representa únicamente dinero cobrado.</p>
</main>
</body>
</html>
