<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\PlatformAdminLoginRequest;
use App\Models\PlatformAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PlatformAuthController extends Controller
{
    public function login(PlatformAdminLoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $admin = PlatformAdmin::where('email', $credentials['email'])->first();

        if (! $admin || ! Hash::check($credentials['password'], $admin->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $token = $admin->createToken('platform-admin-session')->plainTextToken;

        return response()->json([
            'token' => $token,
            'platform_admin' => $this->present($admin),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user('platform_admin')->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'platform_admin' => $this->present($request->user('platform_admin')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PlatformAdmin $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
        ];
    }
}
