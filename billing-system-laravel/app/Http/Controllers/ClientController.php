<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $clients = DB::table('clients')->where('user_id', $this->userId($request))->orderBy('id')->get()
                ->map(fn ($row) => $this->clientJson($row));
            return response()->json(['message' => 'Clients retrieved successfully', 'count' => $clients->count(), 'clients' => $clients]);
        } catch (\Exception $e) { return $this->error($e->getMessage()); }
    }

    public function show(Request $request, int $clientId): JsonResponse
    {
        try {
            $client = DB::table('clients')->where('id', $clientId)->first();
            if (!$client) throw new \InvalidArgumentException('Client not found');
            $this->authorizeOwner($request, $client);
            return response()->json(['message' => 'Client retrieved successfully', 'client' => $this->clientJson($client)]);
        } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage()); }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $name = $request->input('clientName');
            if ($name === null || trim((string) $name) === '') throw new \InvalidArgumentException('Client name is mandatory');
            $name = trim((string) $name);
            $this->assertNameAvailable($request, $name);
            $phone = $this->normalizePhone($request->input('phoneNumber', ''));
            $id = DB::table('clients')->insertGetId(['clientName' => $name, 'phoneNumber' => $phone,
                'address' => $request->input('address', ''), 'user_id' => $this->userId($request),
                'registered_client_id' => DB::table('registered_clients')->where('phone_number', $phone)->value('id'),
                'created_at' => now(), 'updated_at' => now()]);
            return response()->json(['message' => 'Client added successfully', 'client' => $this->clientById($id)], 201);
        } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage()); }
    }

    public function update(Request $request, int $clientId): JsonResponse
    {
        try {
            $client = DB::table('clients')->where('id', $clientId)->first();
            if (!$client) throw new \InvalidArgumentException('Client not found');
            $this->authorizeOwner($request, $client);
            $updates = ['updated_at' => now()];
            $name = $request->input('clientName');
            if ($name !== null && trim((string) $name) !== '') {
                $name = trim((string) $name); $this->assertNameAvailable($request, $name, $clientId); $updates['clientName'] = $name;
            }
            $phone = $request->input('phoneNumber');
            if ($phone !== null && trim((string) $phone) !== '') {
                $phone = $this->normalizePhone($phone); $updates['phoneNumber'] = $phone;
                $updates['registered_client_id'] = DB::table('registered_clients')->where('phone_number', $phone)->value('id');
            }
            $address = $request->input('address');
            if ($address !== null && trim((string) $address) !== '') $updates['address'] = $address;
            DB::table('clients')->where('id', $clientId)->update($updates);
            return response()->json(['message' => 'Client updated successfully', 'client' => $this->clientById($clientId)]);
        } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage()); }
    }

    public function destroy(Request $request, int $clientId): JsonResponse
    {
        try {
            $client = DB::table('clients')->where('id', $clientId)->first();
            if (!$client) throw new \InvalidArgumentException('Client not found');
            $this->authorizeOwner($request, $client);
            DB::table('clients')->where('id', $clientId)->delete();
            return response()->json(['message' => 'Client deleted successfully']);
        } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage()); }
    }

    private function normalizePhone(mixed $phone): string
    {
        if ($phone === null || trim((string) $phone) === '') throw new \InvalidArgumentException('Phone number is required');
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strlen($digits) > 10) $digits = substr($digits, -10);
        if (strlen($digits) !== 10) throw new \InvalidArgumentException('Phone number must be 10 digits after removing country code');
        return $digits;
    }

    private function assertNameAvailable(Request $request, string $name, ?int $exceptId = null): void
    {
        $query = DB::table('clients')->where('user_id', $this->userId($request))->where('clientName', $name);
        if ($exceptId !== null) $query->where('id', '<>', $exceptId);
        if ($query->exists()) throw new \InvalidArgumentException('Client name already exists for this user');
    }

    private function authorizeOwner(Request $request, object $client): void
    {
        if ((int) $client->user_id !== $this->userId($request))
            throw new \InvalidArgumentException('Unauthorized: Client does not belong to this user');
    }

    private function clientById(int $id): array { return $this->clientJson(DB::table('clients')->where('id', $id)->first()); }

    private function clientJson(object $row): array
    {
        // The Java entity exposes this relation; Phase 3 will provide its table.
        // TODO Phase 3: map the complete Spring Order JSON shape once that table/entity is staged.
        // Exposing raw DB rows here would leak snake_case fields and break source compatibility.
        $orders = app(\App\Services\ClientNestedOrderSerializer::class)->serialize((int) $row->id);
        return ['id' => (int) $row->id, 'clientName' => $row->clientName, 'phoneNumber' => $row->phoneNumber,
            'address' => $row->address, 'orders' => $orders];
    }

    private function userId(Request $request): int
    {
        $user = DB::table('users')->where('email', $request->attributes->get('jwt_subject'))->first();
        if (!$user) throw new \RuntimeException('User not found');
        return (int) $user->id;
    }

    private function error(string $message): JsonResponse { return response()->json(['error' => $message], 400); }
}
