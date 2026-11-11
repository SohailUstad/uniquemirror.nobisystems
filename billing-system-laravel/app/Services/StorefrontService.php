<?php

namespace App\Services;

use App\Support\PasswordVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StorefrontService
{
    public function categories(): array
    {
        return DB::table('product_categories')->where('active', true)->orderBy('name')->get()->all();
    }

    public function products(?string $category = null, bool $featured = false): array
    {
        $query = DB::table('products')->where('products.active', true)->leftJoin('product_categories as c', 'c.id', '=', 'products.category_id')
            ->select('products.*', 'c.name as category_name', 'c.slug as category_slug')->orderBy('products.display_order')->orderBy('products.name');
        if ($category !== null && trim($category) !== '') {
            $categoryRow = DB::table('product_categories')->where(['slug' => trim($category), 'active' => true])->first();
            if (!$categoryRow) throw new \InvalidArgumentException('Category not found');
            $query->where('products.category_id', $categoryRow->id);
        }
        if ($featured) $query->where('products.featured', true)->limit(8);
        return $query->get()->map(fn ($p) => $this->productDto($p))->all();
    }

    public function product(string $slug): array
    {
        $p = DB::table('products')->leftJoin('product_categories as c', 'c.id', '=', 'products.category_id')
            ->select('products.*', 'c.name as category_name', 'c.slug as category_slug')->where(['products.slug' => $slug, 'products.active' => true])->first();
        if (!$p) throw new \InvalidArgumentException('Product not found');
        return $this->productDto($p);
    }

    public function cart(array $lines): array
    {
        $items = [];
        foreach ($lines as $line) {
            $product = DB::table('products')->where(['id' => $line['productId'], 'active' => true])->first();
            if (!$product) throw new \InvalidArgumentException('Product not found');
            $size = DB::table('product_size_options')->where(['id' => $line['sizeOptionId'], 'product_id' => $product->id, 'active' => true])->first();
            if (!$size) throw new \InvalidArgumentException('Size option not found');
            $price = $size->price_override ?? $product->base_price;
            $image = DB::table('product_images')->where('product_id', $product->id)->orderByDesc('primary_image')->orderBy('display_order')->value('image_url') ?? '';
            $items[] = ['productId' => (int)$product->id, 'sizeOptionId' => (int)$size->id, 'productName' => $product->name,
                'productSlug' => $product->slug, 'imageUrl' => $image, 'sizeLabel' => $size->label, 'height' => $size->height,
                'width' => $size->width, 'unitPrice' => $price, 'quantity' => $line['quantity'], 'totalPrice' => number_format((float)$price * $line['quantity'], 2, '.', '')];
        }
        return ['items' => $items, 'subtotal' => number_format(array_sum(array_map(fn ($i) => (float)$i['totalPrice'], $items)), 2, '.', ''),
            'itemCount' => array_sum(array_column($items, 'quantity'))];
    }

    public function normalizePhone(?string $phone): string
    {
        if ($phone === null || trim($phone) === '') throw new \InvalidArgumentException('Phone number is required');
        return preg_replace('/[^0-9+]/', '', trim($phone));
    }

    public function sendOtp(string $phone): void
    {
        $phone = $this->normalizePhone($phone);
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::table('public_customer_otps')->insert(['phone_number' => $phone, 'otp_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(5), 'verified' => false, 'attempts' => 0, 'created_at' => now()]);
        logger()->info('Public customer OTP for '.$phone.': '.$otp);
    }

    public function verifyOtp(string $phone, string $otp): array
    {
        return DB::transaction(function () use ($phone, $otp): array {
            $phone = $this->normalizePhone($phone);
            if (trim($otp) === '') throw new \InvalidArgumentException('OTP is required');
            $row = DB::table('public_customer_otps')->where(['phone_number' => $phone, 'verified' => false])->orderByDesc('created_at')->lockForUpdate()->first();
            if (!$row) throw new \InvalidArgumentException('OTP not found. Please request a new OTP.');
            if (now()->greaterThan($row->expires_at)) throw new \InvalidArgumentException('OTP expired. Please request a new OTP.');
            if ((int)$row->attempts >= 5) throw new \InvalidArgumentException('Too many OTP attempts. Please request a new OTP.');
            DB::table('public_customer_otps')->where('id', $row->id)->increment('attempts');
            if (!PasswordVerifier::check(trim($otp), $row->otp_hash)) throw new \InvalidArgumentException('Invalid OTP');
            DB::table('public_customer_otps')->where('id', $row->id)->update(['verified' => true]);
            $customer = DB::table('public_customers')->where('phone_number', $phone)->first();
            $token = Str::uuid()->toString();
            if ($customer) DB::table('public_customers')->where('id', $customer->id)->update(['remember_token' => $token, 'updated_at' => now()]);
            else {
                $id = DB::table('public_customers')->insertGetId(['phone_number' => $phone, 'remember_token' => $token, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                $customer = DB::table('public_customers')->where('id', $id)->first();
            }
            if (!$customer->active) throw new \InvalidArgumentException('Customer account is inactive');
            return ['customer' => (array)$customer, 'token' => $token];
        });
    }

    public function customer(?string $token): ?array
    {
        if (!$token) return null;
        $row = DB::table('public_customers')->where(['remember_token' => $token, 'active' => true])->first();
        return $row ? (array)$row : null;
    }

    public function saveAddress(int $customerId, array $input): void
    {
        foreach (['recipientName' => 'Recipient name is required', 'phoneNumber' => 'Phone number is required', 'addressLine1' => 'Address is required'] as $key => $message) {
            if (trim((string)($input[$key] ?? '')) === '') throw new \InvalidArgumentException($message);
        }
        DB::transaction(function () use ($customerId, $input): void {
            $id = isset($input['id']) && $input['id'] !== '' ? (int)$input['id'] : null;
            if ($id && !DB::table('public_customer_addresses')->where(['id' => $id, 'customer_id' => $customerId])->exists()) throw new \InvalidArgumentException('Address not found');
            $default = filter_var($input['defaultAddress'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($default) DB::table('public_customer_addresses')->where('customer_id', $customerId)->update(['default_address' => false]);
            $data = ['label' => $input['label'] ?? null, 'recipient_name' => trim($input['recipientName']), 'phone_number' => trim($input['phoneNumber']),
                'address_line1' => trim($input['addressLine1']), 'address_line2' => $input['addressLine2'] ?? null, 'city' => $input['city'] ?? null,
                'state' => $input['state'] ?? null, 'pincode' => $input['pincode'] ?? null, 'default_address' => $default, 'customer_id' => $customerId];
            if ($id) DB::table('public_customer_addresses')->where('id', $id)->update($data); else DB::table('public_customer_addresses')->insert($data);
        });
    }

    public function placeOrder(array $customer, int $addressId, ?string $note, array $cart): string
    {
        if (!$cart['items']) throw new \InvalidArgumentException('Cart is empty');
        $address = DB::table('public_customer_addresses')->where(['id' => $addressId, 'customer_id' => $customer['id']])->first();
        if (!$address) throw new \InvalidArgumentException('Address not found');
        return DB::transaction(function () use ($customer, $address, $note, $cart): string {
            $number = 'WEB-'.now()->format('YmdHis').'-'.random_int(1000, 9999);
            $parts = array_filter([$address->address_line1, $address->address_line2, $address->city, $address->state, $address->pincode], fn ($v) => $v !== null && trim((string)$v) !== '');
            $id = DB::table('public_orders')->insertGetId(['order_number' => $number, 'customer_id' => $customer['id'],
                'customer_name_snapshot' => trim($address->recipient_name) !== '' ? $address->recipient_name : ($customer['name'] ?? ''),
                'phone_snapshot' => $address->phone_number, 'address_snapshot' => implode(', ', $parts), 'status' => 'PENDING',
                'subtotal' => $cart['subtotal'], 'total_amount' => $cart['subtotal'], 'note' => $note, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($cart['items'] as $i) DB::table('public_order_items')->insert(['order_id' => $id, 'product_id' => $i['productId'],
                'product_name_snapshot' => $i['productName'], 'product_image_snapshot' => $i['imageUrl'] ?: null, 'selected_size_label' => $i['sizeLabel'],
                'selected_height' => $i['height'], 'selected_width' => $i['width'], 'quantity' => $i['quantity'], 'unit_price' => $i['unitPrice'],
                'total_price' => $i['totalPrice']]);
            return $number;
        });
    }

    public function orders(int $customerId): array
    {
        return DB::table('public_orders')->where('customer_id', $customerId)->orderByDesc('created_at')->get()->map(fn ($o) => $this->orderDto($o))->all();
    }

    public function order(int $customerId, string $number): array
    {
        $o = DB::table('public_orders')->where(['customer_id' => $customerId, 'order_number' => $number])->first();
        if (!$o) throw new \InvalidArgumentException('Order not found');
        return $this->orderDto($o);
    }

    private function orderDto(object $o): array
    {
        $o->items = DB::table('public_order_items')->where('order_id', $o->id)->orderBy('id')->get()->all();
        return (array)$o;
    }

    private function productDto(object $p): array
    {
        $p->images = DB::table('product_images')->where('product_id', $p->id)->orderBy('display_order')->orderBy('id')->get()->all();
        $p->sizeOptions = DB::table('product_size_options')->where('product_id', $p->id)->orderBy('display_order')->orderBy('id')->get()->all();
        $p->category = $p->category_id ? ['name' => $p->category_name, 'slug' => $p->category_slug] : null;
        return (array)$p;
    }
}
