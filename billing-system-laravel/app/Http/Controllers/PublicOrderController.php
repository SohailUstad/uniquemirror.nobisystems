<?php

namespace App\Http\Controllers;

use App\Services\PublicOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicOrderController
{
    private const TRACKER = 'https://fintrix-billing-tracker.web.app';
    private const SHARE = 'https://billing.uniquemirror.in/share/orders';
    private const FALLBACK = 'View your Unique Mirror order details';

    public function __construct(private readonly PublicOrderService $service) {}

    public function index(Request $request)
    {
        if (!$request->has('phone')) return response()->json(['error' => 'Required request parameter phone is not present'], 400);
        $page = $request->query('page', '0');
        if (!preg_match('/^-?\d+$/', (string) $page)) return response()->json(['error' => 'Invalid page'], 400);
        if ((int) $page < 0) return response()->json(['error' => 'Page index must not be less than zero'], 500);
        return response()->json($this->service->ordersForPhone((string) $request->query('phone'), (int) $page));
    }

    public function show(int $orderId)
    {
        $order = $this->service->serializedOrder($orderId);
        return $order === null ? response()->noContent(200) : response()->json($order);
    }

    public function voiceNotes(int $orderId)
    {
        $notes = $this->service->voiceNotes($orderId);
        return $notes === null ? response()->json(['error' => 'Order not found'], 400) : response()->json(['voiceNotes' => $notes]);
    }

    public function streamVoiceNote(int $orderId, int $noteId)
    {
        if (!$this->service->order($orderId)) return response()->json(['error' => 'Order not found'], 400);
        $note = $this->service->voiceNote($orderId, $noteId);
        if (!$note) return response()->json(['error' => 'Voice note not found'], 400);
        // The matching upload service stores voice notes on the local disk.
        $disk = Storage::disk('local');
        $key = $note->storage_key;
        if (!$key || basename($key) === '' || !$disk->exists($key)) return response()->json(['error' => 'Recording file is missing'], 404);
        $mime = $note->mime_type && preg_match('/^[A-Za-z0-9!#$&^_.+-]+\/[A-Za-z0-9!#$&^_.+-]+$/', $note->mime_type)
            ? $note->mime_type : 'application/octet-stream';
        /** @var BinaryFileResponse $response */
        $response = response()->file($disk->path($key), [
            'Content-Type' => $mime, 'Content-Disposition' => 'inline',
            'Cache-Control' => 'public, max-age=3600', 'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPublic();
        return $response;
    }

    public function saveTracking(Request $request)
    {
        $input = $request->all();
        foreach (['orderId', 'userId'] as $field) {
            if (isset($input[$field]) && filter_var($input[$field], FILTER_VALIDATE_INT) === false)
                return response()->json(['error' => 'Invalid '.$field], 400);
        }
        if (isset($input['trackingTime']) && (!is_string($input['trackingTime']) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?$/', $input['trackingTime'])))
            return response()->json(['error' => 'Invalid trackingTime'], 400);
        $this->service->saveTracking($input);
        return response('Saved', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function tracking(int $orderId)
    {
        return response()->json($this->service->tracking($orderId));
    }

    public function billOrders(int $billId)
    {
        $page = $this->service->billOrders($billId);
        return $page === null ? response('', 400) : response()->json($page);
    }

    public function share(string $id)
    {
        $order = preg_match('/^-?\d+$/', $id) ? $this->service->order((int) $id) : null;
        $title = $order && trim((string) $order->description) !== '' ? $order->description
            : ($order && trim((string) $order->orderId) !== '' ? 'Order '.$order->orderId
            : ($order ? 'Order '.$order->id : self::FALLBACK));
        $image = $order && str_starts_with((string) $order->imagePath, 'https://')
            ? $order->imagePath : self::TRACKER.'/logo512.png';
        $safeId = preg_replace('/[^A-Za-z0-9._~-]/', '', $id);
        $url = self::SHARE.'/'.$safeId;
        $redirect = self::TRACKER.'/orders/'.$safeId;
        $e = fn (string $value) => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $js = str_replace(['\\', "'", '"', "\r", "\n", '</'], ['\\\\', "\\'", '\\"', '\\r', '\\n', '<\\/'], $redirect);
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.$e($title).'</title><meta property="og:type" content="website">'
            .'<meta property="og:title" content="'.$e($title).'">'
            .'<meta property="og:description" content="'.$e(self::FALLBACK).'">'
            .'<meta property="og:image" content="'.$e($image).'">'
            .'<meta property="og:url" content="'.$e($url).'">'
            .'<meta name="twitter:card" content="summary_large_image">'
            .'<meta name="twitter:title" content="'.$e($title).'">'
            .'<meta name="twitter:description" content="'.$e(self::FALLBACK).'">'
            .'<meta name="twitter:image" content="'.$e($image).'">'
            ."<script>window.location.replace('".$js."');</script>"
            .'<noscript><meta http-equiv="refresh" content="0;url='.$e($redirect).'"></noscript>'
            .'</head><body><p>'.$e(self::FALLBACK).'</p><p><a href="'.$e($redirect).'">Open order</a></p></body></html>';
        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'public, max-age=300, s-maxage=600']);
    }
}
