@php
    /** @var \App\Models\Lead $lead */
    /** @var \App\Models\Product|null $product */
    /** @var \App\Models\Bank|null $bank */
@endphp
<x-mail::message>
# Новая заявка

Поступила новая заявка через Sravni.tj.

**Заявитель**

- ФИО: {{ $lead->full_name }}
- Телефон: {{ $lead->phone }}
- Согласие на обработку ПД: {{ $lead->consent ? 'да' : 'нет' }}
- Дата: {{ optional($lead->created_at)->format('Y-m-d H:i') }} (UTC)

**Продукт**

@if ($product)
- Название (ru): {{ $product->name_ru ?? '—' }}
- Название (tg): {{ $product->name_tg ?? '—' }}
- Категория: {{ $product->category }}
- Валюта: {{ $product->currency }}
- Ставка: {{ rtrim(rtrim((string) $product->rate_min, '0'), '.') }}–{{ rtrim(rtrim((string) $product->rate_max, '0'), '.') }}%
@else
- Продукт недоступен (id: {{ $lead->product_id ?? '—' }})
@endif

@if ($bank)
**Банк:** {{ $bank->name_ru }}
@endif

<x-mail::panel>
ID заявки: {{ $lead->id }} · ID продукта: {{ $lead->product_id ?? '—' }} · ID банка: {{ $lead->bank_id }}
</x-mail::panel>

Это автоматическое уведомление. Свяжитесь с заявителем по указанному телефону.

Спасибо,<br>
{{ config('app.name') }}
</x-mail::message>
