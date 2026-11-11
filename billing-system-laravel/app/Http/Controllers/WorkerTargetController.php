<?php

namespace App\Http\Controllers;

use App\Services\WorkerTargetService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkerTargetController extends Controller
{
    public function __construct(private WorkerTargetService $service) {}

    private function run(Request $request, callable $action)
    {
        try {
            $uid = DB::table('users')->where('email', $request->attributes->get('jwt_subject'))->value('id');
            if (!$uid) throw new \InvalidArgumentException('User not found');
            return $action((int) $uid);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage() ?: 'Request failed'], 400);
        }
    }

    private function date(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || CarbonImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') !== $value) throw new \InvalidArgumentException('Invalid date');
        return $value;
    }

    private function target(mixed $value): string
    {
        if ($value === null || $value === '') throw new \InvalidArgumentException('Daily target is required');
        if (!is_numeric($value)) throw new \InvalidArgumentException('Invalid daily target');
        if (bccomp((string) $value, '0', 8) < 0) throw new \InvalidArgumentException('Daily target cannot be negative');
        return bcadd((string) $value, '0.00005', 4);
    }

    private function days(Request $request): array
    {
        $days = $request->input('workingDays');
        if (!is_array($days) || !array_is_list($days)) throw new \InvalidArgumentException('workingDays is required');
        foreach ($days as $day) if (!is_numeric($day) || (int) $day < 1 || (int) $day > 7) throw new \InvalidArgumentException('Working days must be between 1 and 7');
        return array_map('intval', $days);
    }

    public function progress(Request $request)
    {
        return $this->run($request, function (int $uid) use ($request) {
            $date = $request->query('date');
            if ($date !== null && $date !== '') $start = $end = $this->date($date);
            elseif (($request->query('startDate') ?? '') === '' && ($request->query('endDate') ?? '') === '') $start = $end = now()->toDateString();
            else {
                if (!$request->query('startDate') || !$request->query('endDate')) throw new \InvalidArgumentException('Both startDate and endDate are required');
                $start = $this->date($request->query('startDate')); $end = $this->date($request->query('endDate'));
            }
            return $this->service->progress($uid, $request->query('workerId') === null ? null : (int) $request->query('workerId'), $start, $end);
        });
    }

    public function workingDays(Request $r) { return $this->run($r, fn ($u) => $this->service->workingDays($u)); }
    public function setGlobalWorkingDays(Request $r) { return $this->run($r, fn ($u) => ['global' => $this->service->setWorkingDays($u, null, $this->days($r))]); }
    public function setWorkerWorkingDays(Request $r, int $workerId) { return $this->run($r, fn ($u) => ['worker' => $this->service->setWorkingDays($u, $workerId, $this->days($r))]); }
    public function clearWorkerWorkingDays(Request $r, int $workerId) { return $this->run($r, function ($u) use ($workerId) { $this->service->clearWorkingDays($u, $workerId); return response()->noContent(); }); }
    public function resetCarryForward(Request $r) { return $this->run($r, fn ($u) => $this->service->reset($u, $r->input('workerId') === null ? null : (int) $r->input('workerId'), $r->input('date') === null ? now()->toDateString() : $this->date($r->input('date')))); }
    public function history(Request $r, int $workerId) { return $this->run($r, fn ($u) => $this->service->history($u, $workerId)); }
    public function setTarget(Request $r, int $workerId) { return $this->run($r, fn ($u) => ['target' => $this->service->setTarget($u, $workerId, $r->input('effectiveDate') === null ? now()->toDateString() : $this->date($r->input('effectiveDate')), $this->target($r->input('targetSquareFeet')))]); }
    public function setOverride(Request $r, int $workerId, string $date) { return $this->run($r, fn ($u) => ['override' => $this->service->setOverride($u, $workerId, $this->date($date), $this->target($r->input('targetSquareFeet')))]); }
    public function deleteOverride(Request $r, int $workerId, string $date) { return $this->run($r, function ($u) use ($workerId, $date) { $this->service->deleteOverride($u, $workerId, $this->date($date)); return response()->noContent(); }); }
}
