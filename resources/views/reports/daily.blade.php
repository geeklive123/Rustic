<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Reportes diarios · Rustik POS</title>
    <style>
        :root{color-scheme:light;--ink:#202a25;--muted:#6c7970;--green:#244c39;--line:#e6ebe6;--paper:#fff;--bg:#f5f7f3}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,-apple-system,sans-serif}main{max-width:1240px;margin:auto;padding:36px 24px 64px}.header{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:32px}.brand{font-size:12px;font-weight:800;letter-spacing:.16em;color:#547c63;text-transform:uppercase}.header h1{font-size:clamp(26px,4vw,38px);letter-spacing:-.04em;line-height:1.1;margin:8px 0}.sub,.muted{color:var(--muted)}.refresh{background:var(--green);color:white;text-decoration:none;padding:12px 19px;border-radius:10px;font-weight:700;white-space:nowrap}.refresh:hover{background:#163a29}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.card,.panel{background:var(--paper);border:1px solid var(--line);border-radius:16px;box-shadow:0 4px 20px #233a2910}.card{padding:21px;min-height:140px}.card.highlight{background:var(--green);color:white;border-color:var(--green)}.label{font-size:13px;font-weight:650;color:var(--muted)}.highlight .label,.highlight .detail{color:#c7d9cc}.value{font-size:clamp(23px,2.7vw,30px);font-weight:750;letter-spacing:-.04em;margin:12px 0 3px}.detail{font-size:12px;color:var(--muted)}.tag{display:inline-block;background:#fff3dc;color:#8c5b0d;font-size:11px;font-weight:700;padding:3px 8px;border-radius:20px}.section-title{font-size:18px;letter-spacing:-.02em;margin:32px 0 14px}.columns{display:grid;grid-template-columns:1fr 1.6fr;gap:16px}.panel{padding:22px;min-width:0}.panel h2{font-size:17px;margin:0 0 4px}.panel p{margin:0 0 16px;font-size:13px}.ranking{list-style:none;margin:0;padding:0}.ranking li{display:flex;align-items:center;gap:12px;padding:13px 0;border-bottom:1px solid var(--line)}.ranking li:last-child{border:0}.rank{display:grid;place-items:center;width:30px;height:30px;border-radius:9px;background:#edf3ed;color:var(--green);font-weight:700}.product{flex:1}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;white-space:nowrap;text-align:left}th{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}td,th{padding:12px 9px;border-bottom:1px solid var(--line)}td:last-child,th:last-child{text-align:right}tr:last-child td{border:0}.empty{padding:25px 0;color:var(--muted)}.foot{font-size:12px;color:var(--muted);margin-top:24px}@media(max-width:850px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.columns{grid-template-columns:1fr}}@media(max-width:480px){main{padding:24px 16px 48px}.header{align-items:flex-start}.refresh{padding:10px 13px}.card{padding:16px;min-height:125px}.grid{gap:10px}.value{font-size:22px}}
    .filters{display:flex;flex-wrap:wrap;align-items:end;gap:12px;padding:18px;margin:0 0 20px}.field{display:flex;flex-direction:column;gap:5px;flex:1;min-width:150px}.field label{font-size:12px;font-weight:700;color:var(--muted)}.field select,.field input{font:inherit;color:var(--ink);background:white;border:1px solid #ccd7cd;border-radius:9px;padding:10px;min-width:0;width:100%}.filters button{border:0;cursor:pointer;font:inherit}.errors{border:1px solid #e9b2a7;background:#fff4f1;color:#8a3021;border-radius:12px;padding:12px 20px;margin:0 0 18px}.errors ul{margin:5px 0;padding-left:20px}</style>
</head>
<body>
@php
    $money = fn ($cents) => 'Bs '.number_format(((int) $cents) / 100, 2, ',', '.');
    $statuses = ['cash_collected' => 'Efectivo cobrado', 'simulated' => 'QR simulado', 'qr_verified' => 'QR verificado', 'qr_pending' => 'QR pendiente', 'unpaid' => 'Sin pagar'];
@endphp
<main>
    <header class="header"><div><div class="brand">Rustik POS / Reportes</div><h1>Resumen de ventas</h1><div class="sub">@if($summary !== null) {{ $rangeStart->format('d/m/Y') }} al {{ $rangeEnd->format('d/m/Y') }} &middot; Hora de Bolivia @else Seleccione un per&iacute;odo v&aacute;lido @endif</div></div>@if($summary !== null)<a class="refresh" href="{{ url('/reportes').'?'.http_build_query($filters) }}">&#8635; Actualizar</a>@endif</header>
    @if($errors->any())<div class="errors" role="alert"><strong>Revise los filtros:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form class="filters panel" method="get" action="{{ url('/reportes') }}">
        <div class="field"><label for="period">Per&iacute;odo</label><select id="period" name="period">
            @foreach(['today' => 'Hoy', 'yesterday' => 'Ayer', 'week' => 'Esta semana', 'month' => 'Este mes', 'custom' => 'Rango personalizado'] as $value => $label)
            <option value="{{ $value }}" @selected(($filters['period'] ?? 'today') === $value)>{{ $label }}</option>
            @endforeach
        </select></div>
        <div class="field"><label for="start_date">Desde (rango personalizado)</label><input id="start_date" type="date" name="start_date" value="{{ $filters['start_date'] ?? '' }}"></div>
        <div class="field"><label for="end_date">Hasta (rango personalizado)</label><input id="end_date" type="date" name="end_date" value="{{ $filters['end_date'] ?? '' }}"></div>
        <button class="refresh" type="submit">Consultar</button>
    </form>
    @if($summary !== null)
    <div class="grid">
        <section class="card highlight"><div class="label">Total vendido</div><div class="value">{{ $money($summary['total_cents']) }}</div><div class="detail">Valor de los pedidos del per&iacute;odo</div></section>
        <section class="card"><div class="label">Pedidos</div><div class="value">{{ number_format($summary['order_count'], 0, ',', '.') }}</div><div class="detail">Registrados en el per&iacute;odo</div></section>
        <section class="card"><div class="label">Efectivo cobrado</div><div class="value">{{ $money($summary['cash_cents']) }}</div><div class="detail">Pagos en efectivo cobrados</div></section>
        <section class="card"><div class="label">QR_STATIC verificado</div><div class="value">{{ $money($summary['verified_qr_cents']) }}</div><div class="detail">{{ $summary['verified_qr_count'] }} pagos verificados</div></section>
        <section class="card"><div class="label">QR_SIMULATED</div><div class="value">{{ $money($summary['simulated_qr_cents']) }}</div><span class="tag">Simulado · no es cobro real</span></section>
        <section class="card"><div class="label">Pedidos con pago pendiente</div><div class="value">{{ $summary['unpaid_count'] }}</div><div class="detail">Sin pagar o QR pendiente</div></section>
        <section class="card"><div class="label">Producto más vendido</div><div class="value" style="font-size:20px;overflow-wrap:anywhere">{{ $products->first()->product_name ?? 'Sin ventas' }}</div><div class="detail">{{ $products->isNotEmpty() ? $products->first()->quantity.' unidades' : 'Aún no hay productos' }}</div></section>
    </div>
    <h2 class="section-title">Actividad del per&iacute;odo</h2>
    <div class="columns">
        <section class="panel"><h2>Top 5 productos</h2><p class="muted">Ordenados por unidades vendidas</p>
            @if($products->isEmpty()) <div class="empty">Todavía no hay productos vendidos en el per&iacute;odo.</div> @else
            <ol class="ranking">@foreach($products as $product)<li><span class="rank">{{ $loop->iteration }}</span><span class="product">{{ $product->product_name }}</span><strong>{{ $product->quantity }} uds.</strong></li>@endforeach</ol>@endif
        </section>
        <section class="panel"><h2>Últimas 10 ventas</h2><p class="muted">Pedidos registrados en el per&iacute;odo</p>
            @if($sales->isEmpty()) <div class="empty">Todavía no hay ventas registradas en el per&iacute;odo.</div> @else
            <div class="table-wrap"><table><thead><tr><th>Pedido</th><th>Fecha y hora</th><th>Pago</th><th>Total</th></tr></thead><tbody>@foreach($sales as $sale)<tr><td>#{{ $sale->id }}</td><td>{{ \Carbon\CarbonImmutable::parse($sale->created_at, 'UTC')->setTimezone('America/La_Paz')->format('d/m/Y H:i') }}</td><td>{{ $statuses[$sale->payment_status] ?? $sale->payment_status }}</td><td>{{ $money($sale->total_cents) }}</td></tr>@endforeach</tbody></table></div>@endif
        </section>
    </div>
    <p class="foot">Actualizado a las {{ $now->format('H:i:s') }} · America/La_Paz. El total vendido incluye pedidos pendientes y pagos simulados; no representa únicamente dinero cobrado.</p>
    @endif
</main>
</body>
</html>
