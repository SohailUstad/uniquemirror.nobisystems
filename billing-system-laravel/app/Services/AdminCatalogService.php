<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminCatalogService
{
    private const ORDER_STATUSES = ['PENDING', 'CONFIRMED', 'IN_PROGRESS', 'READY', 'DELIVERED', 'CANCELLED'];

    public function categories(int $userId): array
    {
        return DB::table('product_categories')->where('user_id', $userId)->orderBy('name')->get()
            ->map(fn ($r) => $this->categoryDto($r))->all();
    }

    public function saveCategory(int $userId, ?int $id, array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') throw new \InvalidArgumentException('Category name is required');
        if ($id !== null && !DB::table('product_categories')->where(['id' => $id, 'user_id' => $userId])->exists()) {
            throw new \InvalidArgumentException('Category not found');
        }
        $data = ['name' => $name, 'slug' => $this->slug($name), 'active' => $this->boolValue($input['active'] ?? true)];
        if ($id === null) {
            $id = (int) DB::table('product_categories')->insertGetId($data + ['user_id' => $userId]);
        } else {
            DB::table('product_categories')->where(['id' => $id, 'user_id' => $userId])->update($data);
        }
        return $this->categoryDto(DB::table('product_categories')->where('id', $id)->first());
    }

    public function products(int $userId): array
    {
        return DB::table('products')->where('user_id', $userId)->orderBy('display_order')->orderBy('name')->get()
            ->map(fn ($r) => $this->productDto($r))->all();
    }

    public function saveProduct(int $userId, ?int $id, array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') throw new \InvalidArgumentException('Product name is required');
        $existing = $id === null ? null : DB::table('products')->where(['id' => $id, 'user_id' => $userId])->first();
        if ($id !== null && !$existing) throw new \InvalidArgumentException('Product not found');
        $categoryId = isset($input['categoryId']) && $input['categoryId'] !== '' ? (int) $input['categoryId'] : null;
        if ($categoryId !== null && !DB::table('product_categories')->where(['id' => $categoryId, 'user_id' => $userId])->exists()) {
            throw new \InvalidArgumentException('Category not found');
        }
        $data = [
            'name' => $name, 'description' => $input['description'] ?? null, 'keywords' => $input['keywords'] ?? null,
            'base_price' => $input['basePrice'] ?? 0, 'active' => $this->boolValue($input['active'] ?? true),
            'featured' => $this->boolValue($input['featured'] ?? false),
            'display_order' => $input['displayOrder'] ?? 0, 'category_id' => $categoryId, 'updated_at' => now(),
        ];
        if ($id === null) {
            $data += ['slug' => 'pending-' . bin2hex(random_bytes(12)), 'user_id' => $userId, 'created_at' => now()];
            $id = (int) DB::table('products')->insertGetId($data);
            $slug = $this->slug($name) . '-' . now()->format('Ymd') . '-' . $id;
            DB::table('products')->where('id', $id)->update(['slug' => $slug]);
        } else {
            if (str_starts_with($existing->slug, 'pending-')) $data['slug'] = $this->slug($name) . '-' . now()->format('Ymd') . '-' . $id;
            DB::table('products')->where(['id' => $id, 'user_id' => $userId])->update($data);
        }
        return $this->productDto(DB::table('products')->where('id', $id)->first());
    }

    public function deleteProduct(int $userId, int $id): void
    {
        if (!DB::table('products')->where(['id' => $id, 'user_id' => $userId])->exists()) throw new \InvalidArgumentException('Product not found');
        DB::table('products')->where(['id' => $id, 'user_id' => $userId])->delete();
    }

    public function addImage(int $userId, int $productId, array $input): array
    {
        $this->ownedProduct($userId, $productId);
        $url = trim((string) ($input['imageUrl'] ?? ''));
        if ($url === '') throw new \InvalidArgumentException('Image URL is required');
        $id = DB::table('product_images')->insertGetId(['product_id' => $productId, 'image_url' => $url,
            'primary_image' => $this->boolValue($input['primaryImage'] ?? false), 'display_order' => $input['displayOrder'] ?? 0]);
        return $this->imageDto(DB::table('product_images')->where('id', $id)->first());
    }

    public function addSize(int $userId, int $productId, array $input): array
    {
        $this->ownedProduct($userId, $productId);
        $label = trim((string) ($input['label'] ?? ''));
        if ($label === '') throw new \InvalidArgumentException('Size label is required');
        if (!isset($input['height'], $input['width']) || $input['height'] === '' || $input['width'] === '') {
            throw new \InvalidArgumentException('Height and width are required');
        }
        $id = DB::table('product_size_options')->insertGetId(['product_id' => $productId, 'label' => $label,
            'height' => $input['height'], 'width' => $input['width'], 'price_override' => $input['priceOverride'] ?? null,
            'active' => $this->boolValue($input['active'] ?? true), 'display_order' => $input['displayOrder'] ?? 0]);
        return $this->sizeDto(DB::table('product_size_options')->where('id', $id)->first());
    }

    public function orders(?string $status): array
    {
        $query = DB::table('public_orders')->orderByDesc('created_at');
        if ($status !== null && trim($status) !== '') {
            $status = strtoupper(trim($status));
            if (!in_array($status, self::ORDER_STATUSES, true)) throw new \InvalidArgumentException('No enum constant com.uniquemirro.entity.PublicOrderStatus.' . $status);
            $query->where('status', $status);
        }
        return $query->get()->map(fn ($r) => $this->orderDto($r))->all();
    }

    public function updateOrderStatus(int $id, array $input): array
    {
        $raw = $input['status'] ?? null;
        if (!is_string($raw)) throw new \InvalidArgumentException('Cannot invoke "String.trim()" because the return value of "java.util.Map.get(Object)" is null');
        $status = strtoupper(trim($raw));
        if (!in_array($status, self::ORDER_STATUSES, true)) throw new \InvalidArgumentException('No enum constant com.uniquemirro.entity.PublicOrderStatus.' . $status);
        if (!DB::table('public_orders')->where('id', $id)->exists()) throw new \InvalidArgumentException('Order not found');
        DB::table('public_orders')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
        return $this->orderDto(DB::table('public_orders')->where('id', $id)->first());
    }

    public function customers(): array
    {
        return DB::table('public_customers')->orderBy('id')->get()->map(function ($r): array {
            $dto = $this->customerDto($r, true);
            $dto['addresses'] = DB::table('public_customer_addresses')->where('customer_id', $r->id)->orderByDesc('default_address')->orderByDesc('id')
                ->get()->map(fn ($a) => $this->addressDto($a))->all();
            return $dto;
        })->all();
    }

    private function ownedProduct(int $userId, int $productId): object
    {
        return DB::table('products')->where(['id' => $productId, 'user_id' => $userId])->first()
            ?? throw new \InvalidArgumentException('Product not found');
    }

    private function productDto(object $r): array
    {
        $category = $r->category_id ? DB::table('product_categories')->where('id', $r->category_id)->first() : null;
        $user = DB::table('users')->where('id', $r->user_id)->first();
        return ['id' => (int) $r->id, 'name' => $r->name, 'slug' => $r->slug, 'description' => $r->description, 'keywords' => $r->keywords,
            'basePrice' => (float) $r->base_price, 'active' => (bool) $r->active, 'featured' => (bool) $r->featured, 'displayOrder' => (int) $r->display_order,
            'createdAt' => $this->date($r->created_at), 'updatedAt' => $this->date($r->updated_at),
            'category' => $category ? $this->categoryDto($category, false) : null,
            'user' => $user ? ['id' => (int) $user->id, 'email' => $user->email, 'globalWorkerRatePerSquareFoot' => $user->global_worker_rate_per_square_foot ?? null] : null,
            'images' => DB::table('product_images')->where('product_id', $r->id)->orderBy('display_order')->orderBy('id')->get()->map(fn ($i) => $this->imageDto($i))->all(),
            'sizeOptions' => DB::table('product_size_options')->where('product_id', $r->id)->orderBy('display_order')->orderBy('id')->get()->map(fn ($s) => $this->sizeDto($s))->all()];
    }

    private function categoryDto(object $r, bool $withUser = true): array
    {
        $out = ['id' => (int) $r->id, 'name' => $r->name, 'slug' => $r->slug, 'active' => (bool) $r->active];
        if ($withUser) {
            $u = DB::table('users')->where('id', $r->user_id)->first();
            $out['user'] = $u ? ['id' => (int) $u->id, 'email' => $u->email, 'globalWorkerRatePerSquareFoot' => $u->global_worker_rate_per_square_foot ?? null] : null;
        }
        return $out;
    }

    private function imageDto(object $r): array
    { return ['id' => (int) $r->id, 'imageUrl' => $r->image_url, 'displayOrder' => (int) $r->display_order, 'primaryImage' => (bool) $r->primary_image]; }

    private function sizeDto(object $r): array
    { return ['id' => (int) $r->id, 'label' => $r->label, 'height' => (float) $r->height, 'width' => (float) $r->width, 'priceOverride' => $r->price_override === null ? null : (float) $r->price_override, 'active' => (bool) $r->active, 'displayOrder' => (int) $r->display_order]; }

    private function customerDto(object $r, bool $withAddresses): array
    { $dto = ['id' => (int) $r->id, 'name' => $r->name, 'phoneNumber' => $r->phone_number, 'email' => $r->email, 'active' => (bool) $r->active,
        'createdAt' => $this->date($r->created_at), 'updatedAt' => $this->date($r->updated_at)];
      if ($withAddresses) { $dto['rememberToken'] = $r->remember_token; $dto['addresses'] = []; }
      return $dto; }

    private function addressDto(object $r): array
    { return ['id' => (int) $r->id, 'label' => $r->label, 'recipientName' => $r->recipient_name, 'phoneNumber' => $r->phone_number,
        'addressLine1' => $r->address_line1, 'addressLine2' => $r->address_line2, 'city' => $r->city, 'state' => $r->state, 'pincode' => $r->pincode, 'defaultAddress' => (bool) $r->default_address]; }

    private function orderDto(object $r): array
    {
        $customer = DB::table('public_customers')->where('id', $r->customer_id)->first();
        return ['id' => (int) $r->id, 'orderNumber' => $r->order_number,
            'customer' => $customer ? $this->customerDto($customer, false) : null,
            'customerNameSnapshot' => $r->customer_name_snapshot, 'phoneSnapshot' => $r->phone_snapshot, 'addressSnapshot' => $r->address_snapshot,
            'status' => $r->status, 'subtotal' => (float) $r->subtotal, 'totalAmount' => (float) $r->total_amount, 'note' => $r->note,
            'createdAt' => $this->date($r->created_at), 'updatedAt' => $this->date($r->updated_at),
            'items' => DB::table('public_order_items')->where('order_id', $r->id)->orderBy('id')->get()->map(function ($i): array {
                $product = $i->product_id ? DB::table('products')->where('id', $i->product_id)->first() : null;
                return ['id' => (int) $i->id, 'product' => $product ? ['id' => (int) $product->id, 'name' => $product->name, 'slug' => $product->slug,
                    'description' => $product->description, 'keywords' => $product->keywords, 'basePrice' => (float) $product->base_price, 'active' => (bool) $product->active,
                    'featured' => (bool) $product->featured, 'displayOrder' => (int) $product->display_order, 'createdAt' => $this->date($product->created_at), 'updatedAt' => $this->date($product->updated_at)] : null,
                    'productNameSnapshot' => $i->product_name_snapshot, 'productImageSnapshot' => $i->product_image_snapshot,
                    'selectedSizeLabel' => $i->selected_size_label, 'selectedHeight' => $i->selected_height, 'selectedWidth' => $i->selected_width,
                    'quantity' => (int) $i->quantity, 'unitPrice' => $i->unit_price, 'totalPrice' => $i->total_price];
            })->all()];
    }

    private function slug(string $value): string
    { $slug = Str::slug($value); return $slug === '' ? 'item' : $slug; }

    private function date(?string $value): ?string
    { return $value === null ? null : date('Y-m-d\TH:i:s', strtotime($value)); }

    private function boolValue(mixed $value): bool
    { return is_string($value) ? strtolower($value) === 'true' : (bool) $value; }
}
