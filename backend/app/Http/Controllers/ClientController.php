<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clients = Client::where('company_id', $request->user()->company_id)
            ->latest()
            ->get();

        return response()->json($clients);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:clients,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
        ]);

        $client = Client::create([
            ...$data,
            'company_id' => $request->user()->company_id,
        ]);

        return response()->json($client, 201);
    }

    public function show(Request $request, Client $client): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $client);

        return response()->json($client);
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $client);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address' => ['sometimes', 'nullable', 'string'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $client->update($data);

        return response()->json($client->fresh());
    }

    public function destroy(Request $request, Client $client): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $client);
        $client->delete();

        return response()->json([
            'message' => 'Client supprimé avec succès.',
        ]);
    }

    private function ensureBelongsToCompany(Request $request, Client $client): void
    {
        abort_unless(
            $client->company_id === $request->user()->company_id,
            404
        );
    }
}
