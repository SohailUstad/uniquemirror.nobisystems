<?php

namespace App\Http\Controllers;

use App\Services\DailyOverviewService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DailyOverviewController extends Controller
{
    public function __construct(private DailyOverviewService $service) {}

    public function overview(Request $request) { return $this->run($request, false); }
    public function workers(Request $request) { return $this->run($request, true); }

    private function run(Request $request, bool $workers)
    {
        try {
            [$start, $end] = $this->range($request);
            $flows = $this->flows($request);
            $uid = DB::table('users')->where('email', $request->attributes->get('jwt_subject'))->value('id');
            if (!$uid) throw new \InvalidArgumentException('User not found');
            return response()->json($workers
                ? $this->service->workers((int) $uid, $start, $end, $flows)
                : $this->service->overview((int) $uid, $start, $end, $flows));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    private function date(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            || CarbonImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Invalid date');
        }
        return $value;
    }

    private function range(Request $request): array
    {
        $date = $request->query('date'); $start = $request->query('startDate'); $end = $request->query('endDate');
        if ($start === null && $end === null) {
            $day = $date === null ? now(config('app.business_time_zone', 'Asia/Kolkata'))->toDateString() : $this->date($date);
            return [$day, $day];
        }
        if ($date !== null) throw new \InvalidArgumentException('Use either date or startDate and endDate');
        if ($start === null || $end === null) throw new \InvalidArgumentException('Start and end dates are required');
        $start = $this->date($start); $end = $this->date($end);
        if ($end < $start) throw new \InvalidArgumentException('End date cannot be before start date');
        if (CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) >= 366) throw new \InvalidArgumentException('Date range cannot exceed 366 days');
        return [$start, $end];
    }

    private function flows(Request $request): array
    {
        $ids = [];
        foreach (['productionStageFlowId', 'productionStageFlowIds'] as $key) {
            $raw = $request->query($key);
            if ($raw === null) continue;
            foreach (is_array($raw) ? $raw : [$raw] as $part) {
                foreach (explode(',', (string) $part) as $value) {
                    if (!preg_match('/^[0-9]+$/', $value)) throw new \InvalidArgumentException('Invalid production stage flow ID');
                    $ids[(int) $value] = (int) $value;
                }
            }
        }
        return array_values($ids);
    }
}
