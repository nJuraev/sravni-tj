# Sravni.tj — Frontend

Витрина сравнения банковских продуктов Таджикистана (кредиты / депозиты / рассрочка).

Стек: **Vue 3 + Vite + TypeScript + Pinia + Vue Router + Vue I18n**.

## Требования

- Node.js 22+
- npm

## Установка и запуск

```bash
npm install
npm run dev      # http://localhost:5173 — работает НА МОКАХ, без бэкенда
npm run build    # vue-tsc -b && vite build → dist/
npm run preview  # предпросмотр собранного dist/
npm run test     # vitest run
```

## Режим данных (моки vs реальный backend)

Источник данных управляется переменными окружения (`VITE_*`). Реальные `.env`
в репозитории нет — см. `.env.example` и при необходимости создайте `.env.local`.

| Переменная | Назначение | Значение по умолчанию |
|---|---|---|
| `VITE_USE_MOCKS` | Использовать встроенный in-memory мок-бэкенд | `dev` → `true`, `build` → `false` |
| `VITE_API_BASE_URL` | База REST API (Laravel) при выключенных моках | `http://localhost:8000/api` |

- **`npm run dev`** по умолчанию поднимает приложение **на моках** — бэкенд не нужен.
  Моки содержат продукты всех трёх категорий (`credit`, `deposit`, `installment`)
  с тарифными сетками (`rate_tiers`) и признаками (`features`), включая
  беспроцентную рассрочку (бейдж «Рассрочка» вместо «0%»).
- **Переключение на реальный backend:** запустите с `VITE_USE_MOCKS=false`
  (и при необходимости задайте `VITE_API_BASE_URL`). В production-сборке моки
  выключены по умолчанию — приложение обращается к `VITE_API_BASE_URL`.

Пример запуска dev против реального API:

```bash
# PowerShell
$env:VITE_USE_MOCKS="false"; $env:VITE_API_BASE_URL="http://localhost:8000/api"; npm run dev
```

## Структура

```
src/
  api/          # client + типизированные ошибки + моки (fixtures/handlers)
  assets/styles # tokens.css (палитра eskhata, CSS-переменные) + base.css
  components/   # ui/ (BaseButton, BaseCard, …), layout/, catalog/, product/, lead/
  composables/  # useCatalogQuery (URL↔фильтры), useLocalizedField, useProductDisplay
  i18n/         # vue-i18n, locales/ru.json + tg.json
  lib/          # calculator (аннуитет/проценты), format, rateTiers
  router/       # маршруты: / → /credit, /:category, /product/:id, /compare
  stores/       # Pinia: compare (лимит 4), leadModal
  types/        # api.ts — отражает docs/api/contracts.md (ЗАМОРОЖЕНО)
  views/        # CatalogView, ProductDetailView, CompareView, NotFoundView
```

## Контракт API

Типы в `src/types/api.ts` строго отражают `docs/api/contracts.md` (заморожен).
Сериализация query-параметров каталога — в `src/api/client.ts` (`buildProductParams`)
и `src/composables/useCatalogQuery.ts`. Не меняйте контракт.

## Дизайн

Палитра — по референсу eskhata.com, объявлена как CSS-переменные в
`src/assets/styles/tokens.css`. В компонентах используются только переменные,
без хардкод-hex.
