<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeadRequest;
use App\Mail\LeadReceived;
use App\Models\Lead;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Приём заявок. Единственный записывающий эндпоинт backend.
 *
 * Порядок (backend.md §3.3): транзакция → запись Lead (bank_id определяется
 * СЕРВЕРОМ по product_id) → постановка письма в очередь best-effort.
 * 201 означает «лид сохранён»; сбой/пустой email почты не теряет лид и не
 * меняет HTTP-код.
 */
class LeadController extends Controller
{
    /**
     * POST /api/leads.
     */
    public function store(StoreLeadRequest $request): JsonResponse
    {
        $data = $request->validated();

        // bank_id берётся НА СЕРВЕРЕ из products.bank_id по product_id (целостность).
        // Продукт гарантированно видим (проверено в StoreLeadRequest).
        /** @var Product $product */
        $product = Product::query()
            ->visible()
            ->findOrFail($data['product_id']);

        $lead = DB::transaction(function () use ($data, $product): Lead {
            return Lead::create([
                'product_id' => $product->id,
                'bank_id' => $product->bank_id,
                'full_name' => $data['full_name'],
                'phone' => $data['phone'],
                'consent' => true,
            ]);
        });

        // Доставка письма — best-effort. Адрес берётся из bank_source_urls.email
        // (по категории продукта), независимо от is_partner. Пустой email/сбой
        // очереди не теряет лид и не меняет HTTP-код.
        $this->dispatchNotification($lead, $product);

        return response()->json([
            'data' => [
                'id' => (int) $lead->id,
                'product_id' => $lead->product_id !== null ? (int) $lead->product_id : null,
                'bank_id' => (int) $lead->bank_id,
                'full_name' => $lead->full_name,
                'phone' => $lead->phone,
                'consent' => (bool) $lead->consent,
                'created_at' => optional($lead->created_at)->toIso8601ZuluString(),
            ],
            'message' => 'Заявка принята.',
        ], Response::HTTP_CREATED);
    }

    /**
     * Ставит письмо банку в очередь. Любой сбой логируется и проглатывается —
     * лид уже сохранён, клиенту отдаётся 201.
     */
    private function dispatchNotification(Lead $lead, Product $product): void
    {
        // Адрес доставки — email источника (bank_source_urls.email) ДЛЯ категории
        // продукта, а не общий контакт банка. Нет источника/email → лид сохранён,
        // письмо не отправляем.
        $product->loadMissing('sourceUrl');
        $email = $product->sourceUrl?->email;

        if (empty($email)) {
            Log::warning('Lead saved but delivery email (source) is empty; notification skipped.', [
                'lead_id' => $lead->id,
                'bank_id' => $lead->bank_id,
                'source_url_id' => $product->source_url_id,
            ]);

            return;
        }

        try {
            // Эажерим связи, чтобы шаблон письма не делал лишних запросов.
            $lead->setRelation('product', $product);
            $lead->setRelation('bank', $product->bank);

            Mail::to($email)->queue(new LeadReceived($lead));
        } catch (\Throwable $e) {
            Log::error('Failed to queue lead notification email.', [
                'lead_id' => $lead->id,
                'bank_id' => $lead->bank_id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
