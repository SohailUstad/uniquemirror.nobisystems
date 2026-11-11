<?php

namespace App\Http\Controllers;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    private const MASTER = [
        'categories' => ['table'=>'expense_categories','singular'=>'category','label'=>'Expense category','fields'=>['name'=>100,'description'=>500]],
        'vendors' => ['table'=>'expense_vendors','singular'=>'vendor','label'=>'Expense vendor/payee','fields'=>['name'=>150,'phone'=>40,'email'=>150,'description'=>500]],
        'paymentModes' => ['table'=>'expense_payment_modes','singular'=>'paymentMode','label'=>'Expense payment mode','fields'=>['name'=>100,'description'=>500]],
        'paymentStatuses' => ['table'=>'expense_payment_statuses','singular'=>'paymentStatus','label'=>'Expense payment status','fields'=>['name'=>100,'description'=>500]],
    ];

    private function fail(string $message, int $status=400): never
    {
        throw new HttpResponseException(response()->json(['error'=>$message], $status));
    }

    private function userId(Request $request): int
    {
        $subject = $request->attributes->get('jwt_subject');
        $id = DB::table('users')->where('email', $subject)->value('id');
        if (!$id) $this->fail('User not found', 500);
        return (int)$id;
    }

    private function validateApi(Request $request, array $rules): array
    {
        try { return $request->validate($rules); }
        catch (ValidationException $e) { $this->fail((string)collect($e->errors())->flatten()->first()); }
    }

    private function stamp(?string $value): ?string { return $value === null ? null : str_replace(' ', 'T', $value); }

    private function master(Request $request): array
    {
        return self::MASTER[$request->route('resource')] ?? $this->fail('Not found', 404);
    }

    private function masterNotFound(string $resource): string
    {
        return ['categories'=>'Expense category not found','vendors'=>'Expense vendor/payee not found',
            'paymentModes'=>'Expense payment mode not found','paymentStatuses'=>'Expense payment status not found'][$resource];
    }

    private function masterDto(object $row): array
    {
        $dto = ['id'=>$row->id,'name'=>$row->name,'description'=>$row->description,'active'=>(bool)$row->active,
            'createdAt'=>$this->stamp($row->created_at),'updatedAt'=>$this->stamp($row->updated_at)];
        foreach (['phone','email'] as $field) if (property_exists($row,$field)) $dto[$field]=$row->$field;
        return $dto;
    }

    private function masterData(Request $request, array $master, bool $creating): array
    {
        $rules=[];
        foreach ($master['fields'] as $field=>$max) {
            $rules[$field] = 'sometimes|nullable|string|max:'.$max;
        }
        $data=$this->validateApi($request,$rules);
        foreach ($data as $field=>$value) if (is_string($value)) $data[$field]=trim($value);
        if ($creating && trim((string)($data['name'] ?? '')) === '') $this->fail('name is required');
        return $data;
    }

    private function ensureUnique(array $master, int $userId, string $name, ?int $except=null): void
    {
        $query=DB::table($master['table'])->where('user_id',$userId)->where('active',true)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)]);
        if ($except !== null) $query->where('id','!=',$except);
        if ($query->exists()) $this->fail($master['label'].' name already exists');
    }

    public function masterIndex(Request $request)
    {
        $master=$this->master($request);
        $rows=DB::table($master['table'])->where('user_id',$this->userId($request))->where('active',true)
            ->orderBy('name')->get()->map(fn($row)=>$this->masterDto($row));
        return response()->json([$request->route('resource')=>$rows]);
    }

    public function masterCreate(Request $request)
    {
        $master=$this->master($request); $userId=$this->userId($request);
        $data=$this->masterData($request,$master,true);
        $this->ensureUnique($master,$userId,$data['name']);
        $id=DB::table($master['table'])->insertGetId($data+['active_name_key'=>Str::lower($data['name']),'user_id'=>$userId,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['message'=>$master['label'].' created successfully',
            $master['singular']=>$this->masterDto(DB::table($master['table'])->find($id))],201);
    }

    public function masterUpdate(Request $request, int $id)
    {
        $master=$this->master($request); $userId=$this->userId($request); $resource=$request->route('resource');
        $row=DB::table($master['table'])->where('user_id',$userId)->where('active',true)->find($id);
        if (!$row) $this->fail($this->masterNotFound($resource));
        $data=$this->masterData($request,$master,false);
        if (array_key_exists('name',$data)) {
            if ($data['name']===null || $data['name']==='') $this->fail('name is required');
            $this->ensureUnique($master,$userId,$data['name'],$id);
            $data['active_name_key']=Str::lower($data['name']);
        }
        if ($data) DB::table($master['table'])->where('id',$id)->update($data+['updated_at'=>now()]);
        return response()->json(['message'=>$master['label'].' updated successfully',
            $master['singular']=>$this->masterDto(DB::table($master['table'])->find($id))]);
    }

    public function masterArchive(Request $request, int $id)
    {
        $master=$this->master($request); $resource=$request->route('resource');
        $changed=DB::table($master['table'])->where('user_id',$this->userId($request))->where('active',true)
            ->where('id',$id)->update(['active'=>false,'active_name_key'=>null,'updated_at'=>now()]);
        if (!$changed) $this->fail($this->masterNotFound($resource));
        return ['message'=>$master['label'].' deleted successfully'];
    }

    private function cents(mixed $amount): int
    {
        $s=(string)$amount;
        if (!preg_match('/^\+?(\d+)(?:\.(\d{1,2}))?$/',$s,$m)) $this->fail('Amount must be a valid number');
        $c=((int)$m[1])*100+(int)str_pad($m[2]??'',2,'0');
        if ($c<=0) $this->fail('Amount must be greater than zero');
        return $c;
    }

    private function amountNumber(int $cents): float { return $cents/100; }

    public function store(Request $request)
    {
        $userId=$this->userId($request);
        $data=$this->validateApi($request,['amount'=>'sometimes','expenseDate'=>'sometimes|date_format:Y-m-d',
            'categoryId'=>'sometimes','vendorId'=>'sometimes','paymentModeId'=>'sometimes','paymentStatusId'=>'sometimes',
            'referenceNo'=>'nullable|string|max:255','note'=>'nullable|string|max:1000','receiptUrl'=>'nullable|string|max:1000']);
        if (!array_key_exists('amount',$data) || $data['amount']===null) $this->fail('Amount is required');
        if (!array_key_exists('expenseDate',$data) || trim((string)$data['expenseDate'])==='') $this->fail('expenseDate is required');
        $data['amount']=$this->amountNumber($this->cents($data['amount']));
        $fields=$this->expenseFields($userId,$data,true);
        $id=DB::table('expenses')->insertGetId($fields+['user_id'=>$userId,'archived'=>false,'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['message'=>'Expense created successfully','expense'=>$this->expense($id,$userId)],201);
    }

    public function index(Request $request)
    {
        $query=$this->filtered($request); $page=max(0,(int)$request->query('page',0)); $size=max(1,(int)$request->query('size',100));
        [$column,$direction]=$this->sort($request->query('sort','expenseDate,desc'));
        $total=(clone $query)->count();
        $rows=$query->orderBy($column,$direction)->orderBy('id',$direction)->offset($page*$size)->limit($size)->get()->map(fn($row)=>$this->dto($row));
        return ['content'=>$rows,'expenses'=>$rows,'totalElements'=>$total,'number'=>$page,'size'=>$size,'totalPages'=>(int)ceil($total/$size)];
    }

    public function show(Request $request, int $id) { return ['expense'=>$this->expense($id,$this->userId($request))]; }

    public function update(Request $request, int $id)
    {
        $userId=$this->userId($request); $this->expense($id,$userId);
        $data=$this->validateApi($request,['amount'=>'sometimes','expenseDate'=>'sometimes|date_format:Y-m-d',
            'categoryId'=>'sometimes','vendorId'=>'sometimes','paymentModeId'=>'sometimes','paymentStatusId'=>'sometimes',
            'referenceNo'=>'sometimes|nullable|string|max:255','note'=>'sometimes|nullable|string|max:1000','receiptUrl'=>'sometimes|nullable|string|max:1000']);
        if (array_key_exists('amount',$data)) $data['amount']=$this->amountNumber($this->cents($data['amount']));
        $fields=$this->expenseFields($userId,$data,false);
        if ($fields) DB::table('expenses')->where('id',$id)->update($fields+['updated_at'=>now()]);
        return ['message'=>'Expense updated successfully','expense'=>$this->expense($id,$userId)];
    }

    public function destroy(Request $request, int $id)
    {
        $this->expense($id,$this->userId($request));
        DB::table('expenses')->where('id',$id)->update(['archived'=>true,'updated_at'=>now()]);
        return ['message'=>'Expense deleted successfully'];
    }

    private function expenseFields(int $userId, array $data, bool $creating): array
    {
        $fields=[];
        foreach (['amount'=>'amount','expenseDate'=>'expense_date','referenceNo'=>'reference_no','note'=>'note','receiptUrl'=>'receipt_url'] as $input=>$column)
            if (array_key_exists($input,$data)) $fields[$column]=$data[$input];
        $references=['categoryId'=>['expense_categories','category','Expense category not found'],
            'vendorId'=>['expense_vendors','vendor','Expense vendor/payee not found'],
            'paymentModeId'=>['expense_payment_modes','payment_mode','Expense payment mode not found'],
            'paymentStatusId'=>['expense_payment_statuses','payment_status','Expense payment status not found']];
        foreach ($references as $input=>[$table,$prefix,$notFound]) {
            if (!$creating && !array_key_exists($input,$data)) continue;
            if (!isset($data[$input])) $this->fail('Master data id is required');
            $row=DB::table($table)->where('user_id',$userId)->where('active',true)->where('id',$data[$input])->first();
            if (!$row) $this->fail($notFound);
            $fields[$prefix.'_id']=$row->id; $fields[$prefix.'_name']=$row->name;
        }
        return $fields;
    }

    private function expense(int $id, int $userId): array
    {
        $row=DB::table('expenses')->where('user_id',$userId)->where('archived',false)->find($id);
        if (!$row) $this->fail('Expense not found');
        return $this->dto($row);
    }

    private function dto(object $row): array
    {
        return ['id'=>$row->id,'amount'=>$row->amount,'expenseDate'=>$row->expense_date,
            'categoryId'=>$row->category_id,'categoryName'=>$row->category_name,'vendorId'=>$row->vendor_id,'vendorName'=>$row->vendor_name,
            'paymentModeId'=>$row->payment_mode_id,'paymentModeName'=>$row->payment_mode_name,
            'paymentStatusId'=>$row->payment_status_id,'paymentStatusName'=>$row->payment_status_name,
            'referenceNo'=>$row->reference_no,'note'=>$row->note,'receiptUrl'=>$row->receipt_url,'archived'=>(bool)$row->archived,
            'createdAt'=>$this->stamp($row->created_at),'updatedAt'=>$this->stamp($row->updated_at)];
    }

    private function filtered(Request $request)
    {
        $query=DB::table('expenses')->where('user_id',$this->userId($request))->where('archived',false);
        foreach (['categoryId'=>'category_id','vendorId'=>'vendor_id','paymentModeId'=>'payment_mode_id','paymentStatusId'=>'payment_status_id'] as $input=>$column)
            if ($request->filled($input)) $query->where($column,$request->query($input));
        $from=$request->query('fromDate',$request->query('startDate')); $to=$request->query('toDate',$request->query('endDate'));
        if ($from) $query->whereDate('expense_date','>=',$from); if ($to) $query->whereDate('expense_date','<=',$to);
        if ($request->query('minAmount')!==null) $query->where('amount','>=',$request->query('minAmount'));
        if ($request->query('maxAmount')!==null) $query->where('amount','<=',$request->query('maxAmount'));
        $keyword=trim((string)$request->query('keyword',''));
        if ($keyword!=='') $query->where(function($sub)use($keyword){ foreach(['category_name','vendor_name','payment_mode_name','payment_status_name','reference_no','note'] as $column) $sub->orWhere($column,'like','%'.$keyword.'%'); });
        return $query;
    }

    private function sort(string $sort): array
    {
        $allowed=['expenseDate'=>'expense_date','amount'=>'amount','categoryName'=>'category_name','vendorName'=>'vendor_name',
            'paymentModeName'=>'payment_mode_name','paymentStatusName'=>'payment_status_name','createdAt'=>'created_at','updatedAt'=>'updated_at','id'=>'id'];
        $parts=explode(',',$sort); return [$allowed[trim($parts[0]??'')]??'expense_date',strtolower(trim($parts[1]??''))==='asc'?'asc':'desc'];
    }

    private function sumCents(iterable $rows): int { $sum=0; foreach($rows as $row) $sum+=$this->cents($row->amount); return $sum; }

    private function breakdown($rows, string $idColumn, string $nameColumn): array
    {
        $groups=$rows->groupBy(fn($row)=>(($row->$idColumn??'null').':'.$row->$nameColumn)); $result=[];
        foreach($groups as $group) $result[]=['id'=>$group[0]->$idColumn,'name'=>$group[0]->$nameColumn,
            'total'=>$this->amountNumber($this->sumCents($group)),'count'=>$group->count()];
        usort($result,fn($a,$b)=>$b['total']<=>$a['total']); return $result;
    }

    public function summary(Request $request)
    {
        $rows=$this->filtered($request)->get(); $today=now()->toDateString(); $monthStart=now()->startOfMonth()->toDateString();
        $hasFilters=$request->filled('categoryId') || $request->filled('vendorId') || $request->filled('paymentModeId') || $request->filled('paymentStatusId')
            || $request->filled('fromDate') || $request->filled('toDate') || $request->filled('startDate') || $request->filled('endDate')
            || $request->query('minAmount')!==null || $request->query('maxAmount')!==null || trim((string)$request->query('keyword',''))!=='';
        $breakdownRows=$hasFilters?$rows:$rows->filter(fn($row)=>$row->expense_date>=$monthStart && $row->expense_date<=$today)->values();
        return ['todayTotal'=>$this->amountNumber($this->sumCents($rows->filter(fn($row)=>$row->expense_date===$today))),
            'monthTotal'=>$this->amountNumber($this->sumCents($rows->filter(fn($row)=>$row->expense_date>=$monthStart && $row->expense_date<=$today))),
            'byCategory'=>$this->breakdown($breakdownRows,'category_id','category_name'),
            'byVendor'=>$this->breakdown($breakdownRows,'vendor_id','vendor_name'),
            'byPaymentStatus'=>$this->breakdown($breakdownRows,'payment_status_id','payment_status_name')];
    }
}
