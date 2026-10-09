<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Feature-тесты POST /api/articles/publish — внешняя публикация статьи
 * (Bearer-токен + rate limit 3/час), см. Api\ArticlePublishController.
 */
class ArticlePublishTest extends TestCase
{
    use RefreshDatabase;

    private ArticleCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        // Rate-limiter cache (array store) не сбрасывается RefreshDatabase —
        // чистим вручную, иначе квота из одного теста утекает в следующий.
        Cache::flush();

        Config::set('services.article_publish.token', 'secret-token');
        Config::set('services.telegram.frontend_url', 'https://sravni.tj');

        $this->category = ArticleCategory::create([
            'name_ru' => 'Новости', 'slug' => 'news', 'is_active' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title_ru' => 'Заголовок статьи',
            'slug' => 'zagolovok-statyi',
            'body_ru' => '<p>Текст статьи</p>',
            'category_id' => $this->category->id,
            'status' => 'published',
        ], $overrides);
    }

    public function test_valid_token_creates_published_article(): void
    {
        $this->postJson('/api/articles/publish', $this->payload(), [
            'Authorization' => 'Bearer secret-token',
        ])
            ->assertCreated()
            ->assertJson(['data' => [
                'slug' => 'zagolovok-statyi',
                'status' => 'published',
                'url' => 'https://sravni.tj/blog/zagolovok-statyi',
            ]]);

        $this->assertDatabaseHas('articles', ['slug' => 'zagolovok-statyi', 'status' => 'published']);
    }

    public function test_missing_token_returns_401(): void
    {
        $this->postJson('/api/articles/publish', $this->payload())
            ->assertStatus(401);

        $this->assertDatabaseCount('articles', 0);
    }

    public function test_wrong_token_returns_401(): void
    {
        $this->postJson('/api/articles/publish', $this->payload(), [
            'Authorization' => 'Bearer wrong-token',
        ])->assertStatus(401);

        $this->assertDatabaseCount('articles', 0);
    }

    public function test_empty_configured_token_always_rejects(): void
    {
        Config::set('services.article_publish.token', null);

        $this->postJson('/api/articles/publish', $this->payload(), [
            'Authorization' => 'Bearer anything',
        ])->assertStatus(401);
    }

    public function test_invalid_payload_returns_422(): void
    {
        $this->postJson('/api/articles/publish', $this->payload(['title_ru' => '']), [
            'Authorization' => 'Bearer secret-token',
        ])->assertStatus(422);
    }

    public function test_body_html_is_sanitized(): void
    {
        $this->postJson('/api/articles/publish', $this->payload([
            'slug' => 'xss-test',
            'body_ru' => '<p>text</p><script>alert(1)</script>',
        ]), ['Authorization' => 'Bearer secret-token'])->assertCreated();

        $article = Article::query()->where('slug', 'xss-test')->firstOrFail();
        $this->assertStringNotContainsString('<script>', $article->body_ru);
    }

    public function test_fourth_request_within_an_hour_is_rate_limited(): void
    {
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/articles/publish', $this->payload(['slug' => "article-{$i}"]), [
                'Authorization' => 'Bearer secret-token',
            ])->assertCreated();
        }

        $this->postJson('/api/articles/publish', $this->payload(['slug' => 'article-4']), [
            'Authorization' => 'Bearer secret-token',
        ])->assertStatus(429);

        $this->assertDatabaseCount('articles', 3);
    }

    public function test_rate_limit_is_shared_regardless_of_valid_or_invalid_token(): void
    {
        // Неверные попытки не должны жечь квоту (middleware проверяет токен
        // раньше throttle) — три валидных запроса подряд всё ещё проходят.
        $this->postJson('/api/articles/publish', $this->payload(), [
            'Authorization' => 'Bearer wrong-token',
        ])->assertStatus(401);

        foreach (range(1, 3) as $i) {
            $this->postJson('/api/articles/publish', $this->payload(['slug' => "ok-{$i}"]), [
                'Authorization' => 'Bearer secret-token',
            ])->assertCreated();
        }
    }
}
