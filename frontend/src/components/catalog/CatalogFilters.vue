<script setup lang="ts">
import { reactive, watch, computed, ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Bank, Currency, FeatureKey, ProductQuery, Subcategory } from '@/types/api'
import { FEATURE_KEYS, SUBCATEGORIES_BY_CATEGORY } from '@/composables/useProductDisplay'
import { DEFAULT_SORT } from '@/composables/useCatalogQuery'
import { useLocalizedField } from '@/composables/useLocalizedField'
import { api } from '@/api/client'
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
const { name } = useLocalizedField()

function faviconUrl(website?: string | null): string {
  if (!website) return ''
  try {
    return `https://www.google.com/s2/favicons?sz=64&domain=${new URL(website).hostname}`
  } catch {
    return ''
  }
}

const banks = ref<Bank[]>([])
const bankTiles = computed(() =>
  banks.value.map((b) => ({ id: b.id, name: name(b), icon: faviconUrl(b.website) })),
)
onMounted(async () => {
  try {
    banks.value = (await api.getBanks()).data
  } catch {
    /* список банков не критичен — фильтр просто не покажется */
  }
})

const CURRENCIES: Currency[] = ['TJS', 'USD', 'EUR']

// Subcategory codes for the current (route-owned) category; empty for installment.
const subcategoryOptions = computed<Subcategory[]>(
  () => SUBCATEGORIES_BY_CATEGORY[props.query.category ?? 'credit'] ?? [],
)

// Local editable copy; numeric fields use '' when empty for clean inputs.
const local = reactive({
  bank_id: [] as number[],
  subcategory: [] as Subcategory[],
  currency: '' as '' | Currency,
  amount_min: '' as number | '',
  amount_max: '' as number | '',
  term_min: '' as number | '',
  term_max: '' as number | '',
  rate_min: '' as number | '',
  rate_max: '' as number | '',
  features: [] as FeatureKey[],
  special: false,
  sort: DEFAULT_SORT,
})

// «Особые» (аномальные) — только у кредитов; галочка по умолчанию выкл.
const showSpecial = computed(() => (props.query.category ?? 'credit') === 'credit')

function hydrate(q: ProductQuery) {
  local.bank_id = [...(q.bank_id ?? [])]
  // Drop any codes not valid for the current category (e.g. after switching tabs).
  local.subcategory = (q.subcategory ?? []).filter((c) => subcategoryOptions.value.includes(c))
  local.currency = q.currency ?? ''
  local.amount_min = q.amount_min ?? ''
  local.amount_max = q.amount_max ?? ''
  local.term_min = q.term_min ?? ''
  local.term_max = q.term_max ?? ''
  local.rate_min = q.rate_min ?? ''
  local.rate_max = q.rate_max ?? ''
  local.features = [...(q.features ?? [])]
  local.special = q.special ?? false
  local.sort = q.sort ?? DEFAULT_SORT
}

watch(() => props.query, hydrate, { immediate: true, deep: true })

const currencyOptions = computed(() => [
  { value: '', label: t('common.all') },
  ...CURRENCIES.map((c) => ({ value: c, label: c })),
])

const sortOptions = computed(() => [
  { value: 'rate_min', label: t('catalog.sort.rate_min') },
  { value: '-rate_max', label: t('catalog.sort.-rate_max') },
  { value: 'amount_min', label: t('catalog.sort.amount_min') },
  { value: 'term_min', label: t('catalog.sort.term_min') },
])

// Block invalid ranges (min > max) before they ever reach the API (§3.2).
const amountInvalid = computed(
  () => local.amount_min !== '' && local.amount_max !== '' && local.amount_min > local.amount_max,
)
const termInvalid = computed(
  () => local.term_min !== '' && local.term_max !== '' && local.term_min > local.term_max,
)
const rateInvalid = computed(
  () => local.rate_min !== '' && local.rate_max !== '' && local.rate_min > local.rate_max,
)
const hasInvalid = computed(() => amountInvalid.value || termInvalid.value || rateInvalid.value)

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
}

function buildQuery(): ProductQuery {
  return {
    category: props.query.category,
    subcategory: [...local.subcategory],
    bank_id: [...local.bank_id],
    currency: local.currency || undefined,
    amount_min: toNum(local.amount_min),
    amount_max: toNum(local.amount_max),
    term_min: toNum(local.term_min),
    term_max: toNum(local.term_max),
    rate_min: toNum(local.rate_min),
    rate_max: toNum(local.rate_max),
    features: [...local.features],
    special: local.special || undefined,
    sort: local.sort,
    per_page: props.query.per_page,
  }
}

function submit() {
  if (hasInvalid.value) return
  emit('apply', buildQuery())
}

/** Применить немедленно (для «живых» контролов: банки, особые). */
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

// Sort changes apply immediately (no submit button needed for it).
watch(
  () => local.sort,
  () => {
    if (!hasInvalid.value) emit('apply', buildQuery())
  },
)
</script>

<template>
  <form class="filters" novalidate @submit.prevent="submit">
    <div class="filters__top">
      <h2 class="filters__title">{{ t('filters.title') }}</h2>
      <BaseButton type="button" variant="ghost" size="sm" @click="emit('reset')">
        {{ t('common.reset') }}
      </BaseButton>
    </div>

    <BaseSelect v-model="local.currency" :label="t('filters.currency')" :options="currencyOptions" />

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
      <legend>{{ t('filters.amount') }}</legend>
      <div class="filters__pair">
        <BaseTextField
          v-model="local.amount_min"
          type="number"
          inputmode="numeric"
          :min="0"
          :placeholder="t('common.from')"
          :error="amountInvalid ? t('filters.invalidRange') : ''"
        />
        <BaseTextField
          v-model="local.amount_max"
          type="number"
          inputmode="numeric"
          :min="0"
          :placeholder="t('common.to')"
        />
      </div>
    </fieldset>

    <fieldset class="filters__group">
      <legend>{{ t('filters.term') }}</legend>
      <div class="filters__pair">
        <BaseTextField
          v-model="local.term_min"
          type="number"
          inputmode="numeric"
          :min="1"
          :placeholder="t('common.from')"
          :error="termInvalid ? t('filters.invalidRange') : ''"
        />
        <BaseTextField
          v-model="local.term_max"
          type="number"
          inputmode="numeric"
          :min="1"
          :placeholder="t('common.to')"
        />
      </div>
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

    <div class="filters__sort">
      <BaseSelect v-model="local.sort" :label="t('catalog.sort.label')" :options="sortOptions" />
    </div>

    <BaseButton type="submit" block :disabled="hasInvalid">{{ t('filters.apply') }}</BaseButton>
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
.filters__top {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.filters__title {
  font-size: var(--fs-lg);
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
</style>
