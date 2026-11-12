<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\User;
use App\Services\SpecialPriceAuthorizations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SpecialPriceController extends Controller
{
    public function index()
    {
        $authorizations = DB::table('special_price_authorizations as a')->join('products as p', 'p.id', '=', 'a.product_id')
            ->join('users as seller', 'seller.id', '=', 'a.seller_id')->join('users as admin', 'admin.id', '=', 'a.authorized_by')
            ->select('a.*', 'p.name as product_name', 'seller.name as seller_name', 'admin.name as admin_name')
            ->orderByDesc('a.id')->paginate(25);

        return view('admin.special-prices', ['products' => Product::where('is_active', true)->whereHas('category', fn ($q) => $q->where('is_active', true))->orderBy('name')->get(),
            'sellers' => User::where('is_active', true)->whereIn('role', [Role::Seller->value, Role::Kitchen->value])->orderBy('name')->get(),
            'authorizations' => $authorizations, 'operation' => (string) Str::uuid()]);
    }

    public function store(Request $request, SpecialPriceAuthorizations $service)
    {
        $data = $request->validate(['operation_id' => ['required', 'uuid'], 'order_uuid' => ['required', 'uuid'], 'product_id' => ['required', 'integer', 'min:1'],
            'seller_id' => ['required', 'integer', 'min:1'], 'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'price' => ['required', 'regex:/^\d{1,7}(?:\.\d{1,2})?$/'], 'reason' => ['required', 'string', 'max:500'], 'confirm' => ['required', 'accepted']]);
        [$whole, $fraction] = array_pad(explode('.', $data['price'], 2), 2, '');
        $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($cents > 100000000) {
            throw ValidationException::withMessages(['price' => 'El precio supera el m?ximo permitido.']);
        }
        $authorization = $service->issue($request->user(), ['operation_id' => $data['operation_id'], 'order_uuid' => $data['order_uuid'],
            'product_id' => (int) $data['product_id'], 'seller_id' => (int) $data['seller_id'], 'quantity' => (int) $data['quantity'],
            'authorized_cents' => $cents, 'reason' => $data['reason']]);

        return redirect()->route('special-prices.index')->with('success', 'Autorizaci?n #'.$authorization->id.' registrada.');
    }
}
