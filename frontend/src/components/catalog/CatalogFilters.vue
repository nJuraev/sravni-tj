<script setup lang="ts">
import { reactive, watch, computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Bank, Currency, FeatureKey, ProductQuery, Subcategory } from '@/types/api'
import { FEATURE_KEYS, SUBCATEGORIES_BY_CATEGORY } from '@/composables/useProductDisplay'
import { DEFAULT_SORT } from '@/composables/useCatalogQuery'
import { useLocalizedField } from '@/composables/useLocalizedField'
import { useApi } from '@/composables/useApi'
import { bankLogoUrl } from '@/lib/bankIcon'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseTextField from '@/components/ui/BaseTextField.vue'
import BaseCheckbox from '@/components/ui/BaseCheckbox.vue'
import BankPicker from '@/components/ui/BankPicker.vue'
import BaseButton from '@/components/ui/BaseButton.vue'

const props = defineProps<{ query: ProductQuery }>()
const emit = defineEmits<{
  apply: [query: ProductQuery]
  reset: []
}>()

const { t } = useI18n()
const api = useApi()
const { name } = useLocalizedField()

const banks = ref<Bank[]>([])
const bankTiles = computed(() =>
  banks.value.map((b) => ({ id: b.id, name: name(b), icon: bankLogoUrl(b) })),
)
onMounted(async () => {
  try {
    banks.value = (await api.getBanks()).data
  } catch {
    /* список банков не критичен — фильтр просто не покажется */
  }
})

const CURRENCIES: Currency[] = ['TJS', 'USD', 'EUR']
const TERM_OPTIONS_MONTHS = [3, 6, 12, 24, 36, 60, 120]

// Subcategory codes for the current (route-owned) category; empty for installment.
const subcategoryOptions = computed<Subcategory[]>(
  () => SUBCATEGORIES_BY_CATEGORY[props.query.category ?? 'credit'] ?? [],
)

// Всё, кроме суммы/срока, скрыто по умолчанию (§ prodengi.kz-style); открывается шестерёнкой.
// Открывается автоматически, если в query уже есть что-то из скрытого — иначе состояние потеряется из виду.
const advancedOpen = ref(false)

// Local editable copy; numeric fields use '' when empty for clean inputs.
const local = reactive({
  bank_id: [] as number[],
  subcategory: [] as Subcategory[],
  currency: '' as '' | Currency,
  amount: '' as number | '',
  term: '' as number | '',
  rate_min: '' as number | '',
  rate_max: '' as number | '',
  features: [] as FeatureKey[],
  special: false,
})

// «Особые» (аномальные) — только у кредитов; галочка по умолчанию выкл.
const showSpecial = computed(() => (props.query.category ?? 'credit') === 'credit')

const activeAdvancedCount = computed(() => {
  let n = 0
  if (local.currency) n++
  if (local.subcategory.length) n++
  if (local.bank_id.length) n++
  if (local.special) n++
  if (local.rate_min !== '' || local.rate_max !== '') n++
  if (local.features.length) n++
  return n
})

function toggleAdvanced() {
  advancedOpen.value = !advancedOpen.value
}

function hydrate(q: ProductQuery) {
  local.bank_id = [...(q.bank_id ?? [])]
  // Drop any codes not valid for the current category (e.g. after switching tabs).
  local.subcategory = (q.subcategory ?? []).filter((c) => subcategoryOptions.value.includes(c))
  local.currency = q.currency ?? ''
  local.amount = q.amount_min ?? q.amount_max ?? ''
  local.term = q.term_min ?? q.term_max ?? ''
  local.rate_min = q.rate_min ?? ''
  local.rate_max = q.rate_max ?? ''
  local.features = [...(q.features ?? [])]
  local.special = q.special ?? false
  if (activeAdvancedCount.value > 0) advancedOpen.value = true
}

watch(() => props.query, hydrate, { immediate: true, deep: true })

const currencyOptions = computed(() => [
  { value: '', label: t('common.all') },
  ...CURRENCIES.map((c) => ({ value: c, label: c })),
])

const termOptions = computed(() => [
  { value: '', label: t('filters.any') },
  ...TERM_OPTIONS_MONTHS.map((m) => ({ value: String(m), label: `${m} ${t('common.months')}` })),
])

// BaseSelect работает со string — мостик к числовому local.term.
const termSelectValue = computed({
  get: () => (local.term === '' ? '' : String(local.term)),
  set: (v: string) => {
    local.term = v === '' ? '' : Number(v)
    applyNow()
  },
})

// Block invalid ranges (min > max) before they ever reach the API (§3.2).
const rateInvalid = computed(
  () => local.rate_min !== '' && local.rate_max !== '' && local.rate_min > local.rate_max,
)
const hasInvalid = computed(() => rateInvalid.value)

function toNum(v: number | ''): number | undefined {
  return v === '' ? undefined : v
}

function toggleFeature(key: FeatureKey, checked: boolean) {
  if (checked) {
    if (!local.features.includes(key)) local.features.push(key)
  } else {
    local.features = local.features.filter((f) => f !== key)
  }
}

function toggleSubcategory(code: Subcategory) {
  if (local.subcategory.includes(code)) {
    local.subcategory = local.subcategory.filter((c) => c !== code)
  } else {
    local.subcategory = [...local.subcategory, code]
  }
  applyNow()
}

function onCurrencyChange(value: string) {
  local.currency = value as '' | Currency
  applyNow()
}

function buildQuery(): ProductQuery {
  return {
    category: props.query.category,
    subcategory: [...local.subcategory],
    bank_id: [...local.bank_id],
    currency: local.currency || undefined,
    amount_min: toNum(local.amount),
    amount_max: toNum(local.amount),
    term_min: toNum(local.term),
    term_max: toNum(local.term),
    rate_min: toNum(local.rate_min),
    rate_max: toNum(local.rate_max),
    features: [...local.features],
    special: local.special || undefined,
    sort: props.query.sort ?? DEFAULT_SORT,
    per_page: props.query.per_page,
  }
}

function submit() {
  if (hasInvalid.value) return
  emit('apply', buildQuery())
}

// Сброс чистит и local (визуал), и query через emit — иначе несохранённые
// (не применённые кнопкой "Применить") чипы/поля остаются "нажатыми".
function resetAll() {
  local.bank_id = []
  local.subcategory = []
  local.currency = ''
  local.amount = ''
  local.term = ''
  local.rate_min = ''
  local.rate_max = ''
  local.features = []
  local.special = false
  advancedOpen.value = false
  emit('reset')
}

/** Применить немедленно (для «живых» контролов: сумма, срок, банки, особые). */
function applyNow() {
  if (!hasInvalid.value) emit('apply', buildQuery())
}

// Выбор банка применяется сразу: кликнул банк → получил его продукты.
function onBanksChange(ids: number[]) {
  local.bank_id = ids
  applyNow()
}

function clearBanks() {
  local.bank_id = []
  applyNow()
}

function onSpecialChange(checked: boolean) {
  local.special = checked
  applyNow()
}

// Сумма — «живой» ввод: фильтруем автоматически, но с debounce, чтобы не
// слать запрос на каждое нажатие клавиши.
let amountDebounce: ReturnType<typeof setTimeout> | undefined
watch(
  () => local.amount,
  () => {
    clearTimeout(amountDebounce)
    amountDebounce = setTimeout(applyNow, 400)
  },
)
onBeforeUnmount(() => clearTimeout(amountDebounce))
</script>

<template>
  <form class="filters" novalidate @submit.prevent="submit">
    <div class="filters__quick">
      <h2 class="filters__title">{{ t('filters.pickTitle') }}</h2>
      <div class="filters__quick-row">
        <BaseTextField
          v-model="local.amount"
          type="number"
          inputmode="numeric"
          :min="0"
          :label="t('filters.amount')"
          :placeholder="t('filters.amountPlaceholder')"
          class="filters__quick-field"
        />
        <BaseSelect
          v-model="termSelectValue"
          :label="t('filters.term')"
          :options="termOptions"
          class="filters__quick-field"
        />
        <div class="filters__quick-actions">
          <BaseButton type="submit" class="filters__quick-submit">{{ t('filters.pick') }}</BaseButton>
          <button
            type="button"
            class="filters__gear"
            :aria-expanded="advancedOpen"
            :aria-label="advancedOpen ? t('filters.hideAdvanced') : t('filters.showAdvanced')"
            @click="toggleAdvanced"
          >
            <svg viewBox="0 0 20 20" aria-hidden="true">
              <path
                d="M3 6h9M15 6h2M3 10h5M9 10h8M3 14h11M16 14h1"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linecap="round"
              />
              <circle cx="12" cy="6" r="2" fill="none" stroke="currentColor" stroke-width="1.6" />
              <circle cx="7" cy="10" r="2" fill="none" stroke="currentColor" stroke-width="1.6" />
              <circle cx="13.5" cy="14" r="2" fill="none" stroke="currentColor" stroke-width="1.6" />
            </svg>
            <span v-if="activeAdvancedCount" class="filters__badge">{{ activeAdvancedCount }}</span>
          </button>
        </div>
      </div>
    </div>

    <div v-show="advancedOpen" class="filters__advanced">
      <div class="filters__advanced-head">
        <h3>{{ t('filters.title') }}</h3>
        <BaseButton type="button" variant="ghost" size="sm" @click="resetAll">
          {{ t('common.reset') }}
        </BaseButton>
      </div>

      <BaseSelect
        :model-value="local.currency"
        :label="t('filters.currency')"
        :options="currencyOptions"
        @update:model-value="onCurrencyChange"
      />

      <fieldset v-if="subcategoryOptions.length" class="filters__group">
        <legend>{{ t('filters.subcategory') }}</legend>
        <div class="filters__chips">
          <button
            v-for="code in subcategoryOptions"
            :key="code"
            type="button"
            class="filters__chip"
            :class="{ 'filters__chip--on': local.subcategory.includes(code) }"
            :aria-pressed="local.subcategory.includes(code)"
            @click="toggleSubcategory(code)"
          >
            {{ t(`subcategory.${code}`) }}
          </button>
        </div>
      </fieldset>

      <fieldset v-if="bankTiles.length" class="filters__group">
        <legend>{{ t('filters.banks') }}</legend>
        <BankPicker :model-value="local.bank_id" :banks="bankTiles" @update:model-value="onBanksChange" />
        <div v-if="local.bank_id.length" class="filters__bankfoot">
          <span>{{ t('filters.banksSelected', { count: local.bank_id.length }) }}</span>
          <button type="button" class="filters__linkbtn" @click="clearBanks">
            {{ t('common.reset') }}
          </button>
        </div>
      </fieldset>

      <fieldset v-if="showSpecial" class="filters__group">
        <legend>{{ t('filters.special') }}</legend>
        <BaseCheckbox :model-value="local.special" @update:model-value="onSpecialChange">
          {{ t('filters.specialShow') }}
        </BaseCheckbox>
      </fieldset>

      <fieldset class="filters__group">
        <legend>{{ t('filters.rate') }}</legend>
        <div class="filters__pair">
          <BaseTextField
            v-model="local.rate_min"
            type="number"
            inputmode="decimal"
            :min="0"
            :placeholder="t('common.from')"
            :error="rateInvalid ? t('filters.invalidRange') : ''"
          />
          <BaseTextField
            v-model="local.rate_max"
            type="number"
            inputmode="decimal"
            :min="0"
            :placeholder="t('common.to')"
          />
        </div>
      </fieldset>

      <fieldset class="filters__group filters__group--features">
        <legend>{{ t('filters.features') }}</legend>
        <BaseCheckbox
          v-for="key in FEATURE_KEYS"
          :key="key"
          :model-value="local.features.includes(key)"
          @update:model-value="(v) => toggleFeature(key, v)"
        >
          {{ t(`features.${key}`) }}
        </BaseCheckbox>
      </fieldset>

      <BaseButton type="submit" block :disabled="hasInvalid">{{ t('filters.apply') }}</BaseButton>
    </div>
  </form>
</template>

<style scoped>
.filters {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  padding: var(--space-4);
  background: var(--color-bg);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  font-size: var(--fs-sm);
}
.filters__quick {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}
.filters__title {
  font-size: var(--fs-lg);
}
.filters__quick-row {
  display: grid;
  grid-template-columns: 1fr 1fr auto;
  align-items: end;
  gap: var(--space-3);
}
.filters__quick-field {
  min-width: 0;
}
.filters__quick-actions {
  display: flex;
  align-items: stretch;
  gap: var(--space-2);
}
.filters__quick-submit {
  white-space: nowrap;
}
.filters__gear {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  padding: 0;
  border: 1.5px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-bg);
  color: var(--color-primary);
  cursor: pointer;
  transition: border-color var(--transition-fast);
}
.filters__gear:hover {
  border-color: var(--color-primary);
}
.filters__gear svg {
  width: 20px;
  height: 20px;
}
.filters__badge {
  position: absolute;
  top: -6px;
  right: -6px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: var(--radius-pill, 999px);
  background: var(--color-primary);
  color: #fff;
  font-size: 11px;
  font-weight: 700;
}
.filters__advanced {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  padding-top: var(--space-3);
  border-top: 1px solid var(--color-border);
}
.filters__advanced-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.filters__group {
  border: 0;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}
.filters__group legend {
  padding: 0;
  font-size: var(--fs-sm);
  font-weight: 600;
  color: var(--color-text-secondary);
}
.filters__group--features {
  gap: var(--space-3);
}
.filters__pair {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-2);
}
.filters__bankfoot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: var(--fs-xs);
  color: var(--color-text-secondary);
}
.filters__linkbtn {
  padding: 0;
  border: 0;
  background: none;
  color: var(--color-primary);
  font: inherit;
  cursor: pointer;
}
.filters__linkbtn:hover {
  text-decoration: underline;
}
.filters__chips {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}
.filters__chip {
  padding: 4px 12px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill, 999px);
  background: var(--color-bg);
  color: var(--color-text-secondary);
  font: inherit;
  font-size: var(--fs-xs);
  cursor: pointer;
  transition: background 0.12s ease, border-color 0.12s ease, color 0.12s ease;
}
.filters__chip:hover {
  border-color: var(--color-primary-light);
}
.filters__chip--on {
  background: var(--color-primary);
  border-color: var(--color-primary);
  color: #fff;
}
@media (max-width: 720px) {
  .filters__quick-row {
    grid-template-columns: 1fr 1fr;
  }
  .filters__quick-actions {
    grid-column: 1 / -1;
  }
  .filters__quick-submit {
    flex: 1;
  }
}
</style>
