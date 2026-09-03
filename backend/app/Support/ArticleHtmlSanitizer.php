<?php

declare(strict_types=1);

namespace App\Support;

use Mews\Purifier\Facades\Purifier;

/**
 * Санитизация HTML тела статьи (body_ru/body_tg) перед записью в БД —
 * Tiptap-редактор на фронте формирует безопасный HTML в обычном случае, но
 * $request->validate() пропускает body_ru/body_tg как обычную строку без
 * проверки содержимого: редактор с скомпрометированным аккаунтом (или прямой
 * POST в /api/admin/articles мимо UI) мог бы записать <script>/onerror и
 * заразить каждого посетителя публичной страницы (body рендерится через
 * v-html). HTMLPurifier вырезает всё за пределами явного whitelist тегов.
 */
class ArticleHtmlSanitizer
{
    /**
     * Ровно тот набор тегов/атрибутов, что производит ArticleEditor.vue
     * (Tiptap StarterKit + Image + Link). Всё остальное — вырезается.
     */
    private const CONFIG = [
        'HTML.Allowed' => 'p,br,strong,em,s,h2,h3,ul,ol,li,blockquote,a[href|rel|target],img[src|alt]',
        'HTML.TargetBlank' => true,
        'AutoFormat.RemoveEmpty' => false,
        'Cache.DefinitionImpl' => null,
    ];

    public static function clean(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        return Purifier::clean($html, self::CONFIG);
    }
}
