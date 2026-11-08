<?php

namespace App\Http\Controllers;

use App\Models\UserInvitation;
use App\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class InvitationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        return response()->json(UserInvitation::query()
            ->where('company_id', $request->user()->company_id)
            ->latest()->get(['id', 'email', 'role', 'invited_by', 'expires_at', 'accepted_at', 'created_at']));
    }

    public function store(Request $request, InvitationService $service): JsonResponse
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(['employee', 'comptable'])],
            'company_id' => ['prohibited'],
            'invited_by' => ['prohibited'],
            'token' => ['prohibited'],
        ]);
        $invitation = $service->invite($request->user(), $data['email'], $data['role']);

        return response()->json([
            'message' => 'Invitation envoyée.',
            'invitation' => $invitation->only(['id', 'email', 'role', 'expires_at']),
        ], 201);
    }

    public function destroy(Request $request, int $invitation): JsonResponse
    {
        $this->ensureAdmin($request);

        $deleted = DB::transaction(function () use ($request, $invitation): bool {
            $record = UserInvitation::where('company_id', $request->user()->company_id)
                ->whereKey($invitation)->lockForUpdate()->firstOrFail();
            if ($record->accepted_at !== null) {
                return false;
            }
            $record->delete();

            return true;
        }, 3);

        return $deleted
            ? response()->json(['message' => 'Invitation annulée.'])
            : response()->json(['message' => 'Cette invitation a déjà été acceptée.'], 409);
    }

    public function inspect(Request $request, InvitationService $service): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $invitation = $service->inspect($data['token']);
        if (! $invitation) {
            return response()->json(['message' => 'Invitation invalide ou expirée.'], 422);
        }

        return response()->json([
            'email' => $invitation->email,
            'role' => $invitation->role,
            'company_name' => $invitation->company->name,
            'expires_at' => $invitation->expires_at,
        ]);
    }

    public function accept(Request $request, InvitationService $service): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols(), 'confirmed'],
            'role' => ['prohibited'],
            'company_id' => ['prohibited'],
            'email' => ['prohibited'],
            'is_active' => ['prohibited'],
        ]);

        $user = $service->accept($data['token'], $data['name'], $data['password']);

        return response()->json([
            'message' => 'Compte collaborateur créé. Vous pouvez vous connecter.',
            'user' => $user->only(['id', 'name', 'email', 'role', 'company_id']),
        ], 201);
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin' && $request->user()->company_id !== null, 403);
    }
}
