<?php

namespace App\Http\Controllers;

use App\Services\AdminCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCatalogController
{
    public function __construct(private readonly AdminCatalogService $catalog) {}

    public function categories(Request $request): JsonResponse
    { return response()->json($this->catalog->categories($this->userId($request))); }

    public function createCategory(Request $request): JsonResponse
    { return $this->run(fn () => $this->catalog->saveCategory($this->userId($request), null, $request->all())); }

    public function updateCategory(Request $request, int $id): JsonResponse
    { return $this->run(fn () => $this->catalog->saveCategory($this->userId($request), $id, $request->all())); }

    public function products(Request $request): JsonResponse
    { return response()->json($this->catalog->products($this->userId($request))); }

    public function createProduct(Request $request): JsonResponse
    { return $this->run(fn () => $this->catalog->saveProduct($this->userId($request), null, $request->all())); }

    public function updateProduct(Request $request, int $id): JsonResponse
    { return $this->run(fn () => $this->catalog->saveProduct($this->userId($request), $id, $request->all())); }

    public function deleteProduct(Request $request, int $id): JsonResponse
    {
        try { $this->catalog->deleteProduct($this->userId($request), $id); return response()->json(['message' => 'Product deleted successfully']); }
        catch (\InvalidArgumentException $e) { return response()->json(['error' => $e->getMessage()], 400); }
    }

    public function addImage(Request $request, int $productId): JsonResponse
    { return $this->run(fn () => $this->catalog->addImage($this->userId($request), $productId, $request->all())); }

    public function addSize(Request $request, int $productId): JsonResponse
    { return $this->run(fn () => $this->catalog->addSize($this->userId($request), $productId, $request->all())); }

    public function orders(Request $request): JsonResponse
    { return $this->run(fn () => $this->catalog->orders($request->query('status'))); }

    public function updateOrderStatus(Request $request, int $id): JsonResponse
    { return $this->run(fn () => $this->catalog->updateOrderStatus($id, $request->all())); }

    public function customers(): JsonResponse
    { return response()->json($this->catalog->customers()); }

    private function userId(Request $request): int
    {
        $id = \Illuminate\Support\Facades\DB::table('users')->where('email', $request->attributes->get('jwt_subject'))->value('id');
        if (!$id) throw new \RuntimeException('User not found');
        return (int) $id;
    }

    private function run(callable $callback): JsonResponse
    {
        try { return response()->json($callback()); }
        catch (\InvalidArgumentException $e) { return response()->json(['error' => $e->getMessage()], 400); }
    }
}
