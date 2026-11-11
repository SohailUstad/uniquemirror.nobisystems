<?php

namespace App\Http\Controllers;

use App\Services\StorefrontService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WebsiteController extends Controller
{
    public function __construct(private readonly StorefrontService $storefront) {}

    private function common(Request $request): array
    {
        $cart = $this->storefront->cart($request->session()->get('PUBLIC_CART', []));
        $customer = $this->storefront->customer($request->cookie('PUBLIC_CUSTOMER_TOKEN'));
        return compact('cart', 'customer');
    }

    private function signedIn(Request $request): array
    {
        return $this->storefront->customer($request->cookie('PUBLIC_CUSTOMER_TOKEN'))
            ?? throw new \InvalidArgumentException('Please login first');
    }

    public function home(Request $request) { return view('website.index', $this->common($request) + ['products' => $this->storefront->products(null, true), 'categories' => $this->storefront->categories()]); }
    public function products(Request $request) {
        $category = $request->query('category');
        return view('website.products', $this->common($request) + ['categories' => $this->storefront->categories(), 'selectedCategory' => $category ? DB::table('product_categories')->where(['slug' => trim($category), 'active' => true])->first() : null, 'products' => $this->storefront->products($category)]);
    }
    public function categories(Request $request) { return view('website.categories', $this->common($request) + ['categories' => $this->storefront->categories()]); }
    public function productDetail(Request $request, string $slug) { return view('website.product-detail', $this->common($request) + ['product' => $this->storefront->product($slug)]); }
    public function addToCart(Request $request) {
        $productId = (int)$request->input('productId'); $sizeId = (int)$request->input('sizeOptionId');
        if (!$productId || !$sizeId) throw new \InvalidArgumentException('Product and size are required');
        $cart = $request->session()->get('PUBLIC_CART', []); $key = $productId.':'.$sizeId;
        $cart[$key] = ['productId' => $productId, 'sizeOptionId' => $sizeId, 'quantity' => ($cart[$key]['quantity'] ?? 0) + max(1, (int)$request->input('quantity', 1))];
        $request->session()->put('PUBLIC_CART', $cart);
        return redirect('/api/cart', 303)->with('message', 'Added to cart');
    }
    public function removeFromCart(Request $request) {
        $cart = $request->session()->get('PUBLIC_CART', []); unset($cart[(int)$request->input('productId').':'.(int)$request->input('sizeOptionId')]);
        $request->session()->put('PUBLIC_CART', $cart); return redirect('/api/cart', 303);
    }
    public function cart(Request $request) { return view('website.cart', $this->common($request)); }
    public function login(Request $request) { return view('website.login', $this->common($request) + ['phoneNumber' => $request->session()->get('phoneNumber')]); }
    public function sendOtp(Request $request) {
        try { $this->storefront->sendOtp((string)$request->input('phoneNumber')); $phone = $this->storefront->normalizePhone($request->input('phoneNumber')); return redirect('/api/login', 303)->with('phoneNumber', $phone)->with('message', 'OTP generated. Check the backend log.'); }
        catch (\InvalidArgumentException $e) { return redirect('/api/login', 303)->withInput()->with('error', $e->getMessage()); }
    }
    public function verifyOtp(Request $request) {
        try { $result = $this->storefront->verifyOtp((string)$request->input('phoneNumber'), (string)$request->input('otp')); return redirect('/api/account', 303)->withCookie(cookie('PUBLIC_CUSTOMER_TOKEN', $result['token'], 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'Lax')); }
        catch (\InvalidArgumentException $e) { return redirect('/api/login', 303)->withInput()->with('error', $e->getMessage()); }
    }
    public function logout(Request $request) {
        $customer = $this->storefront->customer($request->cookie('PUBLIC_CUSTOMER_TOKEN'));
        if ($customer) DB::table('public_customers')->where('id', $customer['id'])->update(['remember_token' => null, 'updated_at' => now()]);
        return redirect('/api', 303)->withCookie(cookie()->forget('PUBLIC_CUSTOMER_TOKEN', '/'));
    }
    public function checkout(Request $request) {
        $customer = $this->signedIn($request);
        return view('website.checkout', $this->common($request) + ['addresses' => DB::table('public_customer_addresses')->where('customer_id', $customer['id'])->orderByDesc('default_address')->orderByDesc('id')->get(), 'currentCustomer' => (object)$customer]);
    }
    public function saveAddress(Request $request) {
        try { $customer = $this->signedIn($request); $this->storefront->saveAddress((int)$customer['id'], $request->all()); return redirect('/api/checkout', 303)->with('message', 'Address saved'); }
        catch (\InvalidArgumentException $e) { return redirect('/api/checkout', 303)->withInput()->with('error', $e->getMessage()); }
    }
    public function placeOrder(Request $request) {
        try { $customer = $this->signedIn($request); $cart = $this->storefront->cart($request->session()->get('PUBLIC_CART', [])); $number = $this->storefront->placeOrder($customer, (int)$request->input('addressId'), $request->input('note'), $cart); $request->session()->forget('PUBLIC_CART'); return redirect('/api/account/orders/'.$number, 303); }
        catch (\InvalidArgumentException $e) { return redirect('/api/checkout', 303)->withInput()->with('error', $e->getMessage()); }
    }
    public function account(Request $request) {
        $customer = $this->signedIn($request);
        return view('website.account', $this->common($request) + ['currentCustomer' => (object)$customer, 'orders' => $this->storefront->orders((int)$customer['id']), 'addresses' => DB::table('public_customer_addresses')->where('customer_id', $customer['id'])->orderByDesc('default_address')->orderByDesc('id')->get()]);
    }
    public function updateProfile(Request $request) {
        $customer = $this->signedIn($request);
        DB::table('public_customers')->where('id', $customer['id'])->update(['name' => trim((string)$request->input('name')) ?: $customer['name'], 'email' => trim((string)$request->input('email')), 'updated_at' => now()]);
        return redirect('/api/account', 303)->with('message', 'Profile updated');
    }
    public function orderHistory(Request $request) { $customer = $this->signedIn($request); return view('website.order-history', $this->common($request) + ['orders' => $this->storefront->orders((int)$customer['id'])]); }
    public function orderDetail(Request $request, string $orderNumber) { $customer = $this->signedIn($request); return view('website.order-detail', $this->common($request) + ['order' => $this->storefront->order((int)$customer['id'], $orderNumber)]); }
}
