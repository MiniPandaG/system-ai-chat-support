<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\Presenters;
use App\Http\Controllers\Api\V1\Concerns\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListRequest;
use App\Http\Requests\Api\StoreCustomerRequest;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;

/**
 * Los clientes de la empresa de la llave.
 */
class CustomerController extends Controller
{
    use Paginates;

    public function index(ListRequest $request): JsonResponse
    {
        $size = $request->pageSize();
        $cursor = $request->cursor();
        $email = $request->filled('email') ? mb_strtolower(trim($request->string('email')->toString())) : null;
        $like = $request->filled('q') ? '%'.addcslashes(trim($request->string('q')->toString()), '%_\\').'%' : null;

        $customers = Customer::query()
            ->when($cursor !== null, fn ($query) => $query->where('id', '<', $cursor))
            ->when($email !== null, fn ($query) => $query->where('email', $email))
            ->when($like !== null, fn ($query) => $query->where(fn ($inner) => $inner->where('name', 'like', $like)->orWhere('email', 'like', $like)))
            ->orderByDesc('id')
            ->limit($size + 1)
            ->get();

        return $this->page(array_values($customers->all()), $size, Presenters::customer(...));
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = Customer::query()->create([
            'name' => trim($request->string('name')->toString()),
            'email' => $request->filled('email') ? $request->string('email')->toString() : null,
        ]);

        return response()->json(['data' => Presenters::customer($customer)], 201, ['Location' => url('/api/v1/customers/'.$customer->id)]);
    }

    public function show(Customer $customer): JsonResponse
    {
        return response()->json(['data' => Presenters::customer($customer)]);
    }
}
