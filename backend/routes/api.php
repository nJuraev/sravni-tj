<?php

use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\BankController as AdminBankController;
use App\Http\Controllers\Admin\LeadController as AdminLeadController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\BankController;
use App\Http\Controllers\Api\BankReviewController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (префикс /api)
|--------------------------------------------------------------------------
|
| Контракт: docs/api/contracts.md (ЗАМОРОЖЕН). Без авторизации (MVP).
| Каталог — только чтение; единственная запись — POST /api/leads.
|
*/

// Каталог разнесён по типам продукта (каждый со своей дефолтной сортировкой).
Route::get('/products/credits', [ProductController::class, 'credits']);
Route::get('/products/deposits', [ProductController::class, 'deposits']);
Route::get('/products/installments', [ProductController::class, 'installments']);
Route::get('/products/{product}', [ProductController::class, 'show'])
    ->whereNumber('product');

Route::get('/banks', [BankController::class, 'index']);
Route::get('/banks/{bank}', [BankController::class, 'show'])->whereNumber('bank');

// Курсы валют (только чтение). /best — лучший курс под операцию клиента.
Route::get('/rates/best', [RateController::class, 'best']);
Route::get('/rates', [RateController::class, 'index']);

Route::get('/banks/{bank}/reviews', [BankReviewController::class, 'index'])
    ->whereNumber('bank');
Route::post('/banks/{bank}/reviews', [BankReviewController::class, 'store'])
    ->whereNumber('bank');

Route::post('/leads', [LeadController::class, 'store']);

/*
|--------------------------------------------------------------------------
| Админ-панель (/api/admin) — token-guard (auth:admin)
|--------------------------------------------------------------------------
|
| Управление данными БД через UI: банки, продукты, лиды, пользователи.
| Логин публичен; остальное под Authorization: Bearer <token>.
|
*/
Route::prefix('admin')->group(function (): void {
    Route::post('login', [AdminAuthController::class, 'login']);

    Route::middleware('auth:admin')->group(function (): void {
        Route::get('me', [AdminAuthController::class, 'me']);
        Route::post('logout', [AdminAuthController::class, 'logout']);

        // Банки + продукты конкретного банка.
        Route::get('banks/{bank}/products', [AdminProductController::class, 'index'])
            ->whereNumber('bank');
        Route::apiResource('banks', AdminBankController::class)->whereNumber('bank');

        // Продукты (CRUD без index — список идёт через банк) + быстрый toggle.
        Route::patch('products/{product}/toggle', [AdminProductController::class, 'toggle'])
            ->whereNumber('product');
        Route::apiResource('products', AdminProductController::class)
            ->except('index')
            ->whereNumber('product');

        // Заявки (просмотр + удаление).
        Route::get('leads', [AdminLeadController::class, 'index']);
        Route::get('leads/{lead}', [AdminLeadController::class, 'show'])->whereNumber('lead');
        Route::delete('leads/{lead}', [AdminLeadController::class, 'destroy'])->whereNumber('lead');

        // Пользователи админки (только роль admin).
        Route::apiResource('users', AdminUserController::class)
            ->except('show')
            ->whereNumber('user');
    });
});
