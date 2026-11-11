<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $categories = DB::table('categories')->where('user_id', $this->userId($request))
                ->where('active', true)->orderBy('name')->get()
                ->map(fn ($row) => $this->categoryJson($row));
            return response()->json(['categories' => $categories]);
        } catch (Throwable $e) { return $this->error($e->getMessage()); }
    }

    public function show(Request $request, int $categoryId): JsonResponse
    {
        try { return response()->json(['category' => $this->ownedCategory($request, $categoryId)]); }
        catch (Throwable $e) { return $this->error($e->getMessage()); }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $name = $this->name($request->input('name'));
            $this->assertUnique($request, $name);
            $id = DB::table('categories')->insertGetId(['user_id' => $this->userId($request), 'name' => $name,
                'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            return response()->json(['message' => 'Category created successfully', 'category' => $this->ownedCategory($request, $id)], 201);
        } catch (Throwable $e) { return $this->error($e->getMessage()); }
    }

    public function update(Request $request, int $categoryId): JsonResponse
    {
        try {
            $category = $this->ownedCategory($request, $categoryId);
            $name = $this->name($request->input('name'));
            $this->assertUnique($request, $name, $categoryId);
            DB::table('categories')->where('id', $categoryId)->update(['name' => $name, 'updated_at' => now()]);
            return response()->json(['message' => 'Category updated successfully', 'category' => $this->ownedCategory($request, $categoryId)]);
        } catch (Throwable $e) { return $this->error($e->getMessage()); }
    }

    public function destroy(Request $request, int $categoryId): JsonResponse
    {
        try {
            $this->ownedCategory($request, $categoryId);
            DB::table('categories')->where('id', $categoryId)->update(['active' => false, 'updated_at' => now()]);
            return response()->json(['message' => 'Category deleted successfully']);
        } catch (Throwable $e) { return $this->error($e->getMessage()); }
    }

    private function name(mixed $name): string
    {
        if ($name === null || trim((string) $name) === '') throw new \InvalidArgumentException('Category name is required');
        return trim((string) $name);
    }

    private function assertUnique(Request $request, string $name, ?int $exceptId = null): void
    {
        $query = DB::table('categories')->where('user_id', $this->userId($request))->where('active', true)
            ->whereRaw('LOWER(name) = LOWER(?)', [$name]);
        if ($exceptId !== null) $query->where('id', '<>', $exceptId);
        if ($query->exists()) throw new \InvalidArgumentException('Category already exists');
    }

    private function ownedCategory(Request $request, int $id): array
    {
        $row = DB::table('categories')->where('id', $id)->where('user_id', $this->userId($request))->where('active', true)->first();
        if (!$row) throw new \InvalidArgumentException('Category not found');
        return $this->categoryJson($row);
    }

    private function categoryJson(object $row): array
    {
        return ['id' => (int) $row->id, 'name' => $row->name, 'active' => (bool) $row->active,
            'createdAt' => $this->localDateTime($row->created_at), 'updatedAt' => $this->localDateTime($row->updated_at)];
    }

    private function userId(Request $request): int
    {
        $user = DB::table('users')->where('email', $request->attributes->get('jwt_subject'))->first();
        if (!$user) throw new \RuntimeException('User not found');
        return (int) $user->id;
    }

    private function localDateTime(string $value): string
    {
        return str_replace(' ', 'T', substr($value, 0, 19));
    }

    private function error(string $message): JsonResponse { return response()->json(['error' => $message], 400); }
}
