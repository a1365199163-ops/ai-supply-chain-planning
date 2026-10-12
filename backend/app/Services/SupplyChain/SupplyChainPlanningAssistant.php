<?php

namespace App\Services\SupplyChain;

use App\Models\Material;
use App\Models\WorkOrder;
use App\Services\Material\NetRequirementsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only planning assistant that routes questions to the existing MRP and
 * work-order data. Recommendations include the records and rules behind them;
 * this prototype never changes schedules or creates purchase orders.
 */
class SupplyChainPlanningAssistant
{
    public function __construct(private readonly NetRequirementsService $requirements) {}

    public function snapshot(int $horizonDays = 30): array
    {
        $horizonDays = in_array($horizonDays, [7, 30, 90], true) ? $horizonDays : 30;
        $from = today()->startOfDay();
        $to = today()->addDays($horizonDays)->endOfDay();
        $mrp = $this->requirements->report($from, $to);

        $orders = WorkOrder::query()
            ->with('line:id,name')
            ->whereIn('status', [
                WorkOrder::STATUS_PENDING,
                WorkOrder::STATUS_ACCEPTED,
                WorkOrder::STATUS_IN_PROGRESS,
                WorkOrder::STATUS_BLOCKED,
                WorkOrder::STATUS_PAUSED,
                WorkOrder::STATUS_CHANGE_HOLD,
            ])
            ->whereNotNull('due_date')
            ->where('due_date', '<=', $to)
            ->orderBy('due_date')
            ->limit(100)
            ->get(['id', 'order_no', 'line_id', 'status', 'planned_qty', 'produced_qty', 'due_date', 'priority']);

        $today = today();
        $deliveryOrders = $orders->map(function (WorkOrder $order) use ($today) {
            $planned = (float) $order->planned_qty;
            $produced = (float) $order->produced_qty;
            $progress = $planned > 0 ? min(100, (int) round($produced / $planned * 100)) : 0;
            $daysUntilDue = (int) $today->diffInDays($order->due_date->copy()->startOfDay(), false);
            $open = $produced < $planned;
            $overdue = $open && $daysUntilDue < 0;
            $nearDue = $open && $daysUntilDue <= 7;
            $blocked = in_array($order->status, [WorkOrder::STATUS_BLOCKED, WorkOrder::STATUS_PAUSED, WorkOrder::STATUS_CHANGE_HOLD], true);

            return [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'line' => $order->line?->name,
                'status' => $order->status,
                'due_date' => $order->due_date->toDateString(),
                'planned_qty' => $planned,
                'produced_qty' => $produced,
                'progress' => $progress,
                'days_until_due' => $daysUntilDue,
                'is_overdue' => $overdue,
                'is_near_due' => $nearDue,
                'is_blocked' => $blocked,
                'attention' => $overdue || $blocked || ($nearDue && $progress < 80),
            ];
        });

        $shortageIds = collect($mrp['shortages'])->pluck('material_id')->filter()->all();
        $materialDetails = Material::query()->whereIn('id', $shortageIds)
            ->get(['id', 'supplier_name', 'supplier_code', 'unit_price', 'price_currency'])
            ->keyBy('id');

        $shortages = collect($mrp['shortages'])->map(function (array $row) use ($materialDetails) {
            $material = $materialDetails->get($row['material_id'] ?? null);
            $row['supplier_name'] = $material?->supplier_name;
            $row['supplier_code'] = $material?->supplier_code;
            $row['unit_price'] = $material?->unit_price === null ? null : (float) $material->unit_price;
            $row['currency'] = $material?->price_currency;
            $row['estimated_cost'] = $row['unit_price'] === null ? null : round($row['unit_price'] * (float) $row['net_qty'], 2);

            return $row;
        })->values();

        $attentionOrders = $deliveryOrders->filter(fn (array $order) => $order['attention'])
            ->sortBy(fn (array $order) => [
                $order['is_overdue'] ? 0 : 1,
                $order['is_blocked'] ? 0 : 1,
                $order['days_until_due'],
            ])->values();

        return [
            'period' => ['start' => $from->toDateString(), 'end' => $to->toDateString(), 'days' => $horizonDays],
            'model_available' => $this->modelConfigured(),
            'metrics' => [
                'planned_orders' => $mrp['totals']['work_orders'],
                'shortage_materials' => $mrp['totals']['shortage_components'],
                'total_shortfall' => $mrp['totals']['total_shortfall'],
                'attention_orders' => $attentionOrders->count(),
                'overdue_orders' => $deliveryOrders->where('is_overdue', true)->count(),
            ],
            'shortages' => $shortages->take(12)->all(),
            'requirements' => collect($mrp['requirements'])->take(12)->values()->all(),
            'orders' => $deliveryOrders->values()->all(),
            'attention_orders' => $attentionOrders->take(12)->all(),
            'recommendations' => $this->recommendations($shortages, $attentionOrders),
            'sources' => [
                'mrp' => "未来 {$horizonDays} 天待排产工单 × BOM × 物料库存",
                'delivery' => '工单交期、计划数量、已产数量与当前状态',
            ],
        ];
    }

    public function answer(string $question, int $horizonDays = 30): array
    {
        $snapshot = $this->snapshot($horizonDays);
        $text = mb_strtolower($question);

        if ($this->modelConfigured()) {
            try {
                return $this->modelAnswer($question, $snapshot);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if (preg_match('/缺料|物料|采购|库存|短缺|mrp/u', $text)) {
            return [...$this->shortageAnswer($snapshot), 'mode' => 'rules'];
        }

        if (preg_match('/交期|延期|逾期|交付|延期风险/u', $text)) {
            return [...$this->deliveryAnswer($snapshot), 'mode' => 'rules'];
        }

        if (preg_match('/工单|生产|计划|排产|概况|整体/u', $text)) {
            return [...$this->overviewAnswer($snapshot), 'mode' => 'rules'];
        }

        return [
            'intent' => 'clarify',
            'title' => '我可以帮你查看计划与供应风险',
            'answer' => '你可以问“未来 30 天有哪些物料缺口？”、“哪些工单临近交期？”或“当前计划整体情况如何？”。',
            'evidence' => [],
            'actions' => ['查看物料缺口', '查看交期风险', '查看生产计划'],
            'mode' => 'rules',
        ];
    }

    private function modelConfigured(): bool
    {
        return filled(config('services.supply_chain_ai.base_url'))
            && filled(config('services.supply_chain_ai.api_key'))
            && filled(config('services.supply_chain_ai.model'));
    }

    /** Route a question to one or more read-only planning tools, then ground the response in their results. */
    private function modelAnswer(string $question, array $snapshot): array
    {
        $baseUrl = rtrim((string) config('services.supply_chain_ai.base_url'), '/');
        $endpoint = str_ends_with($baseUrl, '/chat/completions') ? $baseUrl : $baseUrl.'/chat/completions';
        $tools = [
            ['type' => 'function', 'function' => [
                'name' => 'get_plan_summary',
                'description' => '读取所选计划周期内的生产计划、物料缺口和交期关注汇总。',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'get_material_shortages',
                'description' => '读取所选计划周期内基于工单、BOM和库存计算的物料净缺口及关联工单。',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'get_delivery_risks',
                'description' => '读取逾期、受阻或临近交期且进度偏低的工单及触发原因。',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false],
            ]],
        ];
        $system = '你是制造业供应链计划助手。先调用一个或多个只读工具获取数据，再根据工具结果回答。不得编造数量、供应商交期、产能、成本或订单状态；没有数据时明确说明。回答用中文，简明列出判断依据和建议动作。采购或排程建议必须由计划员确认，不得声称系统已经执行。';
        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $question],
        ];

        $routed = Http::timeout((int) config('services.supply_chain_ai.timeout', 25))
            ->withToken((string) config('services.supply_chain_ai.api_key'))
            ->post($endpoint, [
                'model' => config('services.supply_chain_ai.model'),
                'messages' => $messages,
                'tools' => $tools,
                'tool_choice' => 'auto',
                'temperature' => 0.1,
            ])->throw()->json();

        $toolCalls = data_get($routed, 'choices.0.message.tool_calls', []);
        if ($toolCalls === []) {
            throw new \RuntimeException('The configured model did not select a planning data tool.');
        }

        $messages[] = data_get($routed, 'choices.0.message');
        $used = [];
        $groundedEvidence = [];
        foreach (array_slice($toolCalls, 0, 3) as $call) {
            $name = data_get($call, 'function.name');
            $data = match ($name) {
                'get_plan_summary' => $this->toolSummary($snapshot),
                'get_material_shortages' => $this->toolShortages($snapshot),
                'get_delivery_risks' => $this->toolDeliveryRisks($snapshot),
                default => ['error' => 'Unknown tool.'],
            };
            $used[] = $name;
            array_push($groundedEvidence, ...$this->formatToolEvidence($name, $data, $snapshot));
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => data_get($call, 'id'),
                'content' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        $completed = Http::timeout((int) config('services.supply_chain_ai.timeout', 25))
            ->withToken((string) config('services.supply_chain_ai.api_key'))
            ->post($endpoint, [
                'model' => config('services.supply_chain_ai.model'),
                'messages' => [...$messages, ['role' => 'user', 'content' => '请严格依据刚才的工具结果，用 JSON 返回对象：title（标题）、answer（结论）、evidence（字符串数组，列出数据依据）、actions（字符串数组，列出人工后续动作）。不要输出 Markdown 代码块。']],
                'temperature' => 0.1,
            ])->throw()->json();

        $content = data_get($completed, 'choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new \RuntimeException('The configured model returned an empty answer.');
        }
        $parsed = json_decode($content, true);
        if (! is_array($parsed) || ! filled($parsed['answer'] ?? null)) {
            $parsed = ['title' => '计划分析结果', 'answer' => $content, 'evidence' => [], 'actions' => []];
        }

        return [
            'intent' => 'agent',
            'title' => (string) ($parsed['title'] ?? '计划分析结果'),
            'answer' => (string) $parsed['answer'],
            'evidence' => array_values(array_unique($groundedEvidence)),
            'actions' => array_values(array_filter((array) ($parsed['actions'] ?? []), 'is_string')),
            'mode' => 'llm',
            'nodes' => ['意图识别', '工具选择', ...array_values(array_unique($used)), '依据生成'],
        ];
    }

    private function toolSummary(array $snapshot): array
    {
        return [
            'period' => $snapshot['period'],
            'metrics' => $snapshot['metrics'],
            'recommendations' => $snapshot['recommendations'],
            'rules' => ['物料需求：未来30天待接单/已接单工单按BOM逐层展开后与库存净额', '交期关注：逾期、受阻，或7天内到期且进度低于80%'],
        ];
    }

    private function toolShortages(array $snapshot): array
    {
        return ['period' => $snapshot['period'], 'items' => $snapshot['shortages'], 'count' => $snapshot['metrics']['shortage_materials']];
    }

    private function toolDeliveryRisks(array $snapshot): array
    {
        return ['items' => $snapshot['attention_orders'], 'overdue_count' => $snapshot['metrics']['overdue_orders'], 'attention_count' => $snapshot['metrics']['attention_orders']];
    }

    private function formatToolEvidence(string $tool, array $data, array $snapshot): array
    {
        if ($tool === 'get_plan_summary') {
            return [sprintf(
                '未来 %d 天：待排产工单 %d 张，物料净缺口 %d 种，交期关注 %d 张，逾期 %d 张。',
                $snapshot['period']['days'],
                $snapshot['metrics']['planned_orders'],
                $snapshot['metrics']['shortage_materials'],
                $snapshot['metrics']['attention_orders'],
                $snapshot['metrics']['overdue_orders'],
            )];
        }

        if ($tool === 'get_material_shortages') {
            if (($data['items'] ?? []) === []) {
                return [sprintf('未来 %d 天 MRP 净需求计算未返回物料缺口明细。', $snapshot['period']['days'])];
            }

            return array_map(fn (array $row) => sprintf(
                '%s %s：需求 %s，可用 %s，净缺口 %s %s；关联工单 %s。',
                $row['code'] ?? '',
                $row['name'] ?? '未命名物料',
                $row['required_qty'] ?? 0,
                $row['available_qty'] ?? 0,
                $row['net_qty'] ?? 0,
                $row['unit_of_measure'] ?? '',
                ($row['related_work_orders'] ?? []) ? implode('、', $row['related_work_orders']) : '未关联',
            ), array_slice($data['items'], 0, 6));
        }

        if ($tool === 'get_delivery_risks') {
            if (($data['items'] ?? []) === []) {
                return ['交期关注查询未返回命中规则的工单。'];
            }

            return array_map(fn (array $order) => sprintf(
                '%s：交期 %s，进度 %d%%，状态 %s。',
                $order['order_no'],
                $order['due_date'],
                $order['progress'],
                $this->statusLabel($order['status']),
            ), array_slice($data['items'], 0, 6));
        }

        return [];
    }

    private function shortageAnswer(array $snapshot): array
    {
        $rows = $snapshot['shortages'];
        if ($rows === []) {
            $days = $snapshot['period']['days'];
            $answer = $snapshot['metrics']['planned_orders'] === 0
                ? "未来 {$days} 天没有可用于物料需求计算的待排产工单，暂时无法判断缺料。请先维护工单交期、产品 BOM 和物料库存。"
                : "按未来 {$days} 天待排产工单、BOM 和当前物料库存计算，暂未发现净需求缺口。";

            return ['intent' => 'shortage', 'title' => '物料需求分析', 'answer' => $answer, 'evidence' => [], 'actions' => ['检查 BOM 用量', '更新物料库存']];
        }

        $evidence = array_map(function (array $row) {
            $orders = $row['related_work_orders'] ?? [];
            $supplier = $row['supplier_name'] ? '；供应商：'.$row['supplier_name'] : '';

            return sprintf(
                '%s %s：需求 %s，可用 %s，净缺口 %s %s；关联工单：%s%s',
                $row['code'] ?? '',
                $row['name'] ?? '未命名物料',
                $row['required_qty'] ?? 0,
                $row['available_qty'] ?? 0,
                $row['net_qty'] ?? 0,
                $row['unit_of_measure'] ?? '',
                $orders ? implode('、', $orders) : '未关联',
                $supplier,
            );
        }, array_slice($rows, 0, 5));

        return [
            'intent' => 'shortage',
            'title' => '物料缺口与采购跟进',
            'answer' => sprintf('未来 %d 天识别到 %d 种物料存在净缺口。建议先按关联工单核对需求日期，再向供应商确认到货时间；必要时评估替代料或调整工单顺序。系统只生成建议，不会自动下采购单。', $snapshot['period']['days'], $snapshot['metrics']['shortage_materials']),
            'evidence' => $evidence,
            'actions' => ['联系供应商确认交期', '评估替代料', '复核关联工单顺序'],
        ];
    }

    private function deliveryAnswer(array $snapshot): array
    {
        $rows = $snapshot['attention_orders'];
        if ($rows === []) {
            return [
                'intent' => 'delivery',
                'title' => '工单交期关注',
                'answer' => '当前数据中未发现逾期、阻塞或 7 天内交期且进度低于 80% 的工单。该判断依据工单交期、状态和已产数量，不包含产能预测。',
                'evidence' => [],
                'actions' => ["检查未来 {$snapshot['period']['days']} 天工单交期", '维护生产进度'],
            ];
        }

        $evidence = array_map(fn (array $order) => sprintf(
            '%s：交期 %s，进度 %d%%，状态 %s，产线 %s',
            $order['order_no'],
            $order['due_date'],
            $order['progress'],
            $this->statusLabel($order['status']),
            $order['line'] ?: '未分配',
        ), array_slice($rows, 0, 6));

        return [
            'intent' => 'delivery',
            'title' => '交期风险排查',
            'answer' => sprintf('有 %d 张工单需要优先确认：规则识别逾期、生产受阻，或 7 天内交期且完成进度低于 80%% 的订单。建议先核实物料齐套、产线状态和剩余产能，再决定是否调整排程。', $snapshot['metrics']['attention_orders']),
            'evidence' => $evidence,
            'actions' => ['核对物料齐套情况', '确认产线与班次能力', '必要时调整排程'],
        ];
    }

    private function overviewAnswer(array $snapshot): array
    {
        return [
            'intent' => 'overview',
            'title' => '供应链计划概况',
            'answer' => sprintf(
                '未来 %d 天纳入物料需求计算的待排产工单 %d 张；识别到 %d 种物料有净缺口；%d 张工单需要交期或状态关注，其中逾期 %d 张。建议先处理与高优先级工单关联的缺料，再复核临期和受阻工单。',
                $snapshot['period']['days'],
                $snapshot['metrics']['planned_orders'],
                $snapshot['metrics']['shortage_materials'],
                $snapshot['metrics']['attention_orders'],
                $snapshot['metrics']['overdue_orders'],
            ),
            'evidence' => [
                "物料需求范围：未来 {$snapshot['period']['days']} 天待排产工单，按 BOM 展开并与物料库存净额计算。",
                '交期关注规则：逾期、受阻，或 7 天内到期且完成进度低于 80%。',
            ],
            'actions' => array_column($snapshot['recommendations'], 'title'),
        ];
    }

    private function recommendations($shortages, $attentionOrders): array
    {
        $items = [];
        if ($shortages->isNotEmpty()) {
            $items[] = [
                'level' => 'high',
                'title' => '先核实缺料对工单的影响',
                'detail' => sprintf('%d 种物料存在净缺口，按关联工单确认需求日期与供应商到货时间。', $shortages->count()),
                'source' => 'MRP 净需求：待排产工单 + BOM + 当前库存',
            ];
        }

        $overdue = $attentionOrders->where('is_overdue', true)->count();
        if ($overdue > 0) {
            $items[] = [
                'level' => 'high',
                'title' => '复核已逾期工单',
                'detail' => sprintf('%d 张未完成工单已超过交期，建议确认停线、缺料或产量回报等原因。', $overdue),
                'source' => '工单 due_date、status、planned_qty 与 produced_qty',
            ];
        }

        $blocked = $attentionOrders->where('is_blocked', true)->count();
        if ($blocked > 0) {
            $items[] = [
                'level' => 'medium',
                'title' => '处理受阻生产任务',
                'detail' => sprintf('%d 张工单处于受阻、暂停或变更暂停状态，建议先确认恢复条件。', $blocked),
                'source' => '工单当前状态',
            ];
        }

        if ($items === []) {
            $items[] = [
                'level' => 'low',
                'title' => '暂未发现突出风险',
                'detail' => '保持工单交期、BOM 和库存数据更新，计划建议会随业务数据刷新。',
                'source' => '当前工单与物料主数据',
            ];
        }

        return $items;
    }

    private function statusLabel(string $status): string
    {
        return [
            WorkOrder::STATUS_PENDING => '待接单',
            WorkOrder::STATUS_ACCEPTED => '已接单',
            WorkOrder::STATUS_IN_PROGRESS => '生产中',
            WorkOrder::STATUS_BLOCKED => '受阻',
            WorkOrder::STATUS_PAUSED => '已暂停',
            WorkOrder::STATUS_CHANGE_HOLD => '变更暂停',
        ][$status] ?? $status;
    }
}
