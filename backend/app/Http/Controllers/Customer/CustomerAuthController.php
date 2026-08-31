<?php

namespace App\Http\Controllers\Customer;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CustomerLoginRequest;
use App\Http\Requests\Customer\CustomerRegisterRequest;
use App\Models\Customer;
use App\Models\Store;
use App\Support\CustomerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Customer authentication on the "customer" Sanctum guard/provider — a
 * third, structurally separate identity domain from platform_admins/users,
 * per CLAUDE.md. Mirrors MerchantAuthController/PlatformAuthController's
 * shape exactly; the one structural difference is that customers.email is
 * unique only per store, so register/login are store-scoped by a
 * client-supplied store_id rather than a bare email lookup — see
 * CustomerLoginRequest/CustomerRegisterRequest's docblocks.
 */
class CustomerAuthController extends Controller
{
    public function register(CustomerRegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $store = Store::where('id', $data['store_id'])->first();

        if (! $store || $store->organization->status !== OrganizationStatus::Active) {
            return response()->json([
                'message' => 'This store is not currently accepting new customers.',
            ], 403);
        }

        $customer = new Customer;
        $customer->organization_id = $store->organization_id;
        $customer->store_id = $store->id;
        $customer->name = $data['name'];
        $customer->email = $data['email'];
        $customer->phone = $data['phone'] ?? null;
        $customer->password = $data['password'];
        $customer->save();

        $token = $customer->createToken('customer-session')->plainTextToken;

        return response()->json([
            'token' => $token,
            'customer' => $this->present($customer),
        ], 201);
    }

    public function login(CustomerLoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $customer = Customer::where('store_id', $credentials['store_id'])
            ->where('email', $credentials['email'])
            ->first();

        if (! $customer || ! Hash::check($credentials['password'], $customer->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $token = $customer->createToken('customer-session')->plainTextToken;

        return response()->json([
            'token' => $token,
            'customer' => $this->present($customer),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user('customer')->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }

    public function me(): JsonResponse
    {
        $context = app(CustomerContext::class);

        return response()->json([
            'customer' => $this->present($context->customer),
            'store' => [
                'id' => $context->store->id,
                'name' => $context->store->name,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'store_id' => $customer->store_id,
        ];
    }
}
