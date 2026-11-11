<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiCommandService
{
    public function __construct(private readonly AiTokenCounter $tokens) {}

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are an intent router, not a conversational assistant.
Call an available tool only when it matches the user's request.
Never invent tool arguments. Omit optional arguments unless the user supplied them.
Use the payment tool only when the user explicitly asks to record a payment received from a B2B client.
Use order tools only for read-only order questions. Never perform or imply an order write operation.
Never answer the request yourself. If no tool matches, do not call a tool.
PROMPT;

    public function route(string $command): array
    {
        $maxInput = (int) config('services.ai.max_input_tokens', 64);
        $this->tokens->assertWithinLimit($command, $maxInput);
        $url = rtrim((string) config('services.ai.endpoint'), '/');
        $key = (string) config('services.ai.api_key');
        $model = (string) config('services.ai.model', 'gpt-4o-mini');
        if ($url === '' || $key === '') throw new RuntimeException('AI provider endpoint and API key must be configured');
        $url .= str_ends_with($url, '/chat/completions') ? '' : '/chat/completions';
        $response = Http::withToken($key)->acceptJson()->timeout(30)->post($url, [
            'model' => $model,
            'messages' => [['role' => 'system', 'content' => self::SYSTEM_PROMPT], ['role' => 'user', 'content' => $command]],
            'tools' => $this->tools(), 'tool_choice' => 'auto', 'parallel_tool_calls' => false,
            'max_completion_tokens' => (int) config('services.ai.max_output_tokens', 128),
        ]);
        if (!$response->successful()) throw new RuntimeException('AI provider request failed');
        $calls = $response->json('choices.0.message.tool_calls');
        if (!is_array($calls) || count($calls) !== 1) throw new \InvalidArgumentException('The command must select exactly one supported tool');
        $name = $calls[0]['function']['name'] ?? null;
        $args = json_decode($calls[0]['function']['arguments'] ?? '', true);
        if (!is_array($args)) throw new \InvalidArgumentException('The AI selected an unsupported tool');
        if (!in_array($name, ['getCurrentTime', 'prepareClientPayment', 'countOrders'], true)) throw new \InvalidArgumentException('The AI selected an unsupported tool');
        return [$name, $args];
    }

    private function tools(): array
    {
        return [
            ['type'=>'function','function'=>['name'=>'getCurrentTime','description'=>'Returns the current time in India','parameters'=>['type'=>'object','properties'=>new \stdClass(),'additionalProperties'=>false]]],
            ['type'=>'function','function'=>['name'=>'prepareClientPayment','description'=>'Prepare a payment received from a B2B client. Use only when the user explicitly asks to record, add, log, or receive a client payment. This creates a preview and does not record the payment until separately confirmed.','parameters'=>['type'=>'object','properties'=>[
                'clientId'=>['type'=>'integer','description'=>'Exact numeric client ID, if supplied by the user'],
                'clientName'=>['type'=>'string','description'=>'Exact B2B client name, if supplied by the user'],
                'amount'=>['type'=>'number','description'=>'Positive payment amount without currency symbols'],
                'paymentDate'=>['type'=>'string','description'=>'Payment date in YYYY-MM-DD format; omit to use today'],
                'paymentMode'=>['type'=>'string','enum'=>['CASH','UPI','BANK_TRANSFER','CARD','CHEQUE','OTHER']],
                'referenceNo'=>['type'=>'string'],'note'=>['type'=>'string'],
            ],'required'=>['amount'],'additionalProperties'=>false]]],
            ['type'=>'function','function'=>['name'=>'countOrders','description'=>'Count the authenticated user\'s orders, optionally for one exact order stage. Use only for read-only questions asking how many orders exist. Map common lowercase stage words to title case, for example pending to Pending, delivered to Delivered, and billed to Billed.','parameters'=>['type'=>'object','properties'=>['stage'=>['type'=>'string','description'=>'Optional exact order stage, such as Pending, Delivered, or Billed']],'additionalProperties'=>false]]],
        ];
    }
}
