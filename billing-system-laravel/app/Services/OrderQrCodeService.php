<?php

namespace App\Services;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Physical PNG QR generation seam matching Spring's /files/{filename} URL. */
class OrderQrCodeService
{
    public function create(int $orderId): string
    {
        $filename = 'qr_'.Str::uuid().'.png';
        $code = QrCode::create((string) $orderId)->setSize(300)->setMargin(10);
        $bytes = (new PngWriter())->write($code)->getString();
        if (!Storage::disk('local')->put('uploads/'.$filename, $bytes)) throw new \RuntimeException('Failed to generate QR Code');
        $base = rtrim((string) config('app.url'), '/');
        return $base.'/files/'.$filename;
    }
}
