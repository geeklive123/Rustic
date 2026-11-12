@extends('admin.layout')
@section('content')
<h1>Movimientos de caja #{{ $shift->id }}</h1>
<p><a href="{{ route('cash.show', $shift) }}">Volver a la caja</a></p>
@if($summary)
<div class="grid">
@foreach(['Entradas'=>$summary['cash_inflows_cents'] ?? 0,'Salidas'=>$summary['cash_outflows_cents'] ?? 0,'Bancos verificados'=>$summary['bank_verified_cents'] ?? $summary['methods_cents']['QR_STATIC_VERIFIED']] as $label=>$value)
<div class="card"><span>{{ $label }}</span><strong>Bs {{ number_format($value/100, 2) }}</strong></div>
@endforeach
</div>
@endif
@if($shift->closed_at && $summary)
<div class="panel"><h2>Conciliación posterior al cierre</h2>
<p>Efectivo esperado al cierre: Bs {{ number_format($shift->expected_cents / 100, 2) }}<br>Ajustes posteriores: Bs {{ number_format($adjustment / 100, 2) }}<br>Esperado conciliado: Bs {{ number_format(($shift->expected_cents + $adjustment) / 100, 2) }}</p>
<p class="muted">El ajuste corrige el registro histórico; no representa dinero recibido o entregado en una caja abierta. Solo se admite compensar una vez una entrada o salida completa. Las transferencias permanecen bloqueadas.</p>
<form method="post" action="{{ route('cash.movements.compensate', $shift) }}" data-once data-confirm="¿Confirmas la compensación sin modificar el cierre?">@csrf
<input type="hidden" name="uuid" value="{{ old('uuid', $uuid) }}"><input type="hidden" name="operation_id" value="{{ old('operation_id', $operation) }}">
<label>ID del movimiento original<input name="original_movement_id" type="number" min="1" required value="{{ old('original_movement_id') }}"></label>
<label>Motivo<textarea name="reason" maxlength="500" required>{{ old('reason') }}</textarea></label>
<label><input type="checkbox" name="confirmed" value="1" required> Confirmo la compensación del movimiento completo.</label><button type="submit">Registrar compensación</button></form></div>
@endif
@if(!$shift->closed_at)
<div class="panel"><h2>Registrar movimiento</h2><p class="muted">Responsable y autorización: {{ auth()->user()->name }}. La fecha se registra en el servidor.</p>
<form method="post" action="{{ route('cash.movements.store', $shift) }}" data-once data-confirm="¿Confirmas este movimiento?">@csrf
<input type="hidden" name="uuid" value="{{ old('uuid', $uuid) }}"><input type="hidden" name="operation_id" value="{{ old('operation_id', $operation) }}">
<label>Tipo<select name="type" required><option value="IN" @selected(old('type') === 'IN')>Entrada de efectivo</option><option value="OUT" @selected(old('type') === 'OUT')>Salida de efectivo</option><option value="TRANSFER" @selected(old('type') === 'TRANSFER')>Transferencia verificada con entrega</option></select></label>
<label>Importe físico en Bs<input name="amount" inputmode="decimal" value="{{ old('amount') }}" required pattern="[0-9]+([.][0-9]{1,2})?"></label>
<label>Motivo<textarea name="reason" maxlength="500" required>{{ old('reason') }}</textarea></label>
<fieldset><legend>Solo para transferencia verificada</legend><label>Importe total transferido en Bs<input name="bank_amount" value="{{ old('bank_amount') }}" inputmode="decimal"></label>
<label>Referencia bancaria<input name="bank_reference" maxlength="255" value="{{ old('bank_reference') }}"></label>
<label>Comprobación bancaria real<textarea name="verification_evidence" maxlength="500">{{ old('verification_evidence') }}</textarea></label>
<label>ID del pago QR verificado vinculado (opcional)<input name="payment_id" type="number" min="1" value="{{ old('payment_id') }}"></label>
<label><input type="checkbox" name="verified" value="1"> Confirmo que comprobé la recepción real de los fondos.</label></fieldset><button type="submit">Registrar</button></form></div>
@endif
<div class="panel table"><h2>Historial</h2><table><thead><tr><th>Fecha Bolivia</th><th>Tipo</th><th>Importe físico</th><th>Responsable</th><th>Motivo</th><th>Conciliación bancaria</th></tr></thead><tbody>
@forelse($movements as $movement)<tr><td>{{ \Carbon\CarbonImmutable::parse($movement->created_at, 'UTC')->timezone('America/La_Paz')->format('d/m/Y H:i') }}</td><td>#{{ $movement->id }} {{ $movement->type }} @if($movement->original_movement_id)<br>Compensa #{{ $movement->original_movement_id }}@endif</td><td>@if($movement->type === 'COMPENSATION')Ajuste Bs {{ number_format($movement->cash_adjustment_cents/100,2) }}@else Bs {{ number_format($movement->amount_cents/100,2) }}@endif</td><td>{{ $movement->creator }}</td><td>{{ $movement->reason }}</td><td>@if($movement->bank_amount_cents !== null)Bs {{ number_format($movement->bank_amount_cents/100,2) }} / {{ $movement->bank_reference }}<br>{{ $movement->verification_evidence }} @if($movement->payment_id)<br>Pago #{{ $movement->payment_id }}@endif @else — @endif</td></tr>@empty<tr><td colspan="6">Sin movimientos.</td></tr>@endforelse</tbody></table>{{ $movements->links() }}</div>
@endsection
