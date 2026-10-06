<?php

namespace App\Http\Controllers\BioTime;

use App\Http\Controllers\Controller;
use App\Http\Resources\BioTime\TransactionResource;
use App\Models\AttendancePunch;
use App\Models\BioTimeClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'regex:/^(last|[1-9][0-9]{0,8})$/D'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'emp_code' => ['sometimes', 'string', 'max:255'],
            'terminal_sn' => ['sometimes', 'string', 'max:255'],
            'terminal_alias' => ['sometimes', 'string', 'max:255'],
            'start_time' => ['sometimes', 'date_format:Y-m-d H:i:s'],
            'end_time' => ['sometimes', 'date_format:Y-m-d H:i:s'],
        ]);

        if (isset($filters['start_time'], $filters['end_time']) && $filters['start_time'] > $filters['end_time']) {
            throw ValidationException::withMessages(['end_time' => 'The end time must be after or equal to the start time.']);
        }

        $query = $this->query($request);

        if (isset($filters['emp_code'])) {
            $employeeCode = $filters['emp_code'];
            $query->where(function (Builder $query) use ($employeeCode): void {
                $query->whereHas('employee', fn (Builder $employee) => $employee->where('employee_number', $employeeCode))
                    ->orWhere(fn (Builder $unmapped) => $unmapped->whereDoesntHave('employee')->where('pin', $employeeCode));
            });
        }

        foreach (['terminal_sn' => 'serial_number', 'terminal_alias' => 'name'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->whereHas('device', fn (Builder $device) => $device->where($column, $filters[$filter]));
            }
        }

        foreach (['start_time' => '>=', 'end_time' => '<='] as $filter => $operator) {
            if (isset($filters[$filter])) {
                $query->where('occurred_at_local', $operator, $filters[$filter]);
            }
        }

        $pageSize = (int) ($filters['page_size'] ?? $filters['limit'] ?? 10);
        $count = $query->count();
        $lastPage = max(1, (int) ceil($count / $pageSize));
        $page = ($filters['page'] ?? '1') === 'last' ? $lastPage : (int) ($filters['page'] ?? 1);

        if ($page > $lastPage) {
            return response()->json(['detail' => 'Invalid page.'], 404);
        }

        $punches = $query->orderBy('id')->forPage($page, $pageSize)->get();

        return response()->json([
            'count' => $count,
            'next' => $page < $lastPage ? $this->pageUrl($request, $page + 1) : null,
            'previous' => $page > 1 ? $this->pageUrl($request, $page - 1) : null,
            'msg' => '',
            'code' => 0,
            'data' => TransactionResource::collection($punches)->resolve($request),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $punch = $this->query($request)->findOrFail($id);

        return response()->json((new TransactionResource($punch))->resolve($request));
    }

    private function pageUrl(Request $request, int $page): string
    {
        $parameters = $request->query();
        if ($page === 1) {
            unset($parameters['page']);
        } else {
            $parameters['page'] = $page;
        }
        ksort($parameters);

        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC1738);
        $url = rtrim(route('biotime.transactions.index'), '/').'/';

        return $query === '' ? $url : $url.'?'.$query;
    }

    /** @return Builder<AttendancePunch> */
    private function query(Request $request): Builder
    {
        /** @var BioTimeClient $client */
        $client = $request->attributes->get('biotime_client');

        return AttendancePunch::query()->where('company_id', $client->company_id)->with(['device', 'employee']);
    }
}
