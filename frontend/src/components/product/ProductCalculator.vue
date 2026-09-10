<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Locale, Product } from '@/types/api'
import { calcCredit, calcDeposit, generateCreditSchedule, isValidCalcInput } from '@/lib/calculator'
import { findRateTier } from '@/lib/rateTiers'
import { formatDateShort, formatMoney, formatPercent } from '@/lib/format'
import BaseTextField from '@/components/ui/BaseTextField.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseCheckbox from '@/components/ui/BaseCheckbox.vue'

const props = defineProps<{ product: Product }>()

const { t, locale } = useI18n()
const loc = computed(() => locale.value as Locale)

const isDeposit = computed(() => props.product.category === 'deposit')
const canCapitalize = computed(() => props.product.features.capitalization === true)

// Sensible defaults from the product's own ranges.
const state = reactive({
  amount: props.product.amount_min ?? props.product.amount_max ?? 1000,
  term: props.product.term_min ?? props.product.term_max ?? 12,
  capitalize: canCapitalize.value,
  periodsPerYear: 12,
})

// Pick the exact tier rate for the entered amount/term; fall back to the
// product's min aggregate when no cell matches (e.g. out-of-range inputs).
const effectiveRate = computed(() => {
  const tier = findRateTier(
    props.product.rate_tiers,
    Number(state.amount),
    Number(state.term),
    props.product.currency,
  )
  return tier ? tier.rate : props.product.rate_min
})

const inputValid = computed(() =>
  isValidCalcInput({
    amount: Number(state.amount),
    termMonths: Number(state.term),
    rate: effectiveRate.value,
  }),
)

const periodOptions = computed(() => [
  { value: '12', label: t('calc.period.monthly') },
  { value: '4', label: t('calc.period.quarterly') },
  { value: '1', label: t('calc.period.annually') },
])

const creditResult = computed(() => {
  if (isDeposit.value || !inputValid.value) return null
  return calcCredit({
    amount: Number(state.amount),
    termMonths: Number(state.term),
    rate: effectiveRate.value,
  })
})

const depositResult = computed(() => {
  if (!isDeposit.value || !inputValid.value) return null
  return calcDeposit({
    amount: Number(state.amount),
    termMonths: Number(state.term),
    rate: effectiveRate.value,
    capitalize: canCapitalize.value && state.capitalize,
    periodsPerYear: Number(state.periodsPerYear),
  })
})

const periodsModel = computed({
  get: () => String(state.periodsPerYear),
  set: (v: string) => (state.periodsPerYear = Number(v)),
})

const showSchedule = ref(false)

const schedule = computed(() => {
  if (isDeposit.value || !inputValid.value || !showSchedule.value) return []
  return generateCreditSchedule({
    amount: Number(state.amount),
    termMonths: Number(state.term),
    rate: effectiveRate.value,
  })
})

function money(value: number): string {
  return formatMoney(value, props.product.currency, loc.value)
}

function date(value: Date): string {
  return formatDateShort(value, loc.value)
}
</script>

<template>
  <section class="calc">
    <h3 class="calc__title">{{ isDeposit ? t('calc.depositTitle') : t('calc.creditTitle') }}</h3>

    <div class="calc__inputs">
      <BaseTextField
        v-model="state.amount"
        type="number"
        inputmode="numeric"
        :min="1"
        :label="t('calc.amount')"
      />
      <BaseTextField
        v-model="state.term"
        type="number"
        inputmode="numeric"
        :min="1"
        :label="t('calc.term')"
      />
    </div>

    <div v-if="isDeposit && canCapitalize" class="calc__deposit-opts">
      <BaseCheckbox v-model="state.capitalize">{{ t('calc.capitalization') }}</BaseCheckbox>
      <BaseSelect
        v-if="state.capitalize"
        v-model="periodsModel"
        :label="t('calc.periodsPerYear')"
        :options="periodOptions"
      />
    </div>

    <p class="calc__rate" :class="{ 'calc__rate--muted': !inputValid }">
      {{ t('calc.tierRate', { rate: formatPercent(effectiveRate, loc) }) }}
    </p>

    <p v-if="!inputValid" class="calc__hint">{{ t('calc.invalid') }}</p>

    <template v-else-if="creditResult">
      <dl class="calc__results">
        <div class="calc__result calc__result--primary">
          <dt>{{ t('calc.monthlyPayment') }}</dt>
          <dd class="tabular">{{ money(creditResult.monthlyPayment) }}</dd>
        </div>
        <div class="calc__result">
          <dt>{{ t('calc.overpayment') }}</dt>
          <dd class="tabular">{{ money(creditResult.overpayment) }}</dd>
        </div>
        <div class="calc__result">
          <dt>{{ t('calc.totalPaid') }}</dt>
          <dd class="tabular">{{ money(creditResult.totalPaid) }}</dd>
        </div>
      </dl>

      <button type="button" class="calc__schedule-toggle" @click="showSchedule = !showSchedule">
        {{ showSchedule ? t('calc.schedule.hide') : t('calc.schedule.show') }}
      </button>

      <div v-if="showSchedule" class="calc__schedule">
        <p class="calc__schedule-disclaimer">{{ t('calc.schedule.disclaimer') }}</p>
        <table class="calc__schedule-table">
          <thead>
            <tr>
              <th>{{ t('calc.schedule.n') }}</th>
              <th>{{ t('calc.schedule.date') }}</th>
              <th>{{ t('calc.schedule.payment') }}</th>
              <th>{{ t('calc.schedule.interest') }}</th>
              <th>{{ t('calc.schedule.principal') }}</th>
              <th>{{ t('calc.schedule.balance') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in schedule" :key="row.index">
              <td>{{ row.index }}</td>
              <td>{{ date(row.date) }}</td>
              <td class="tabular">{{ money(row.payment) }}</td>
              <td class="tabular">{{ money(row.interest) }}</td>
              <td class="tabular">{{ money(row.principal) }}</td>
              <td class="tabular">{{ money(row.balance) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <dl v-else-if="depositResult" class="calc__results">
      <div class="calc__result calc__result--primary">
        <dt>{{ t('calc.income') }}</dt>
        <dd class="tabular">{{ money(depositResult.income) }}</dd>
      </div>
      <div class="calc__result">
        <dt>{{ t('calc.total') }}</dt>
        <dd class="tabular">{{ money(depositResult.total) }}</dd>
      </div>
    </dl>
  </section>
</template>

<style scoped>
.calc {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  padding: var(--space-5);
  background: var(--color-bg-blue-tint);
  border: 1px solid var(--color-border-subtle);
  border-radius: var(--radius-lg);
}
.calc__title {
  font-size: var(--fs-lg);
}
.calc__inputs {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-3);
}
.calc__deposit-opts {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}
.calc__rate {
  font-size: var(--fs-sm);
  color: var(--color-primary-dark);
  font-weight: 600;
}
.calc__rate--muted {
  color: var(--color-text-muted);
  font-weight: 400;
}
.calc__hint {
  font-size: var(--fs-sm);
  color: var(--color-text-secondary);
}
.calc__results {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin: 0;
}
.calc__result {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--space-3);
  padding: var(--space-2) 0;
}
.calc__result dt {
  color: var(--color-text-secondary);
  font-size: var(--fs-sm);
}
.calc__result dd {
  margin: 0;
  font-family: var(--font-display);
  font-weight: 600;
}
.calc__result--primary {
  padding: var(--space-3);
  background: var(--color-bg);
  border-radius: var(--radius-md);
}
.calc__result--primary dt {
  font-weight: 600;
  color: var(--color-text-primary);
}
.calc__result--primary dd {
  font-size: var(--fs-xl);
  color: var(--color-primary);
}
.calc__schedule-toggle {
  align-self: flex-start;
  background: none;
  border: none;
  padding: 0;
  color: var(--color-primary);
  font-size: var(--fs-sm);
  font-weight: 600;
  cursor: pointer;
}
.calc__schedule-toggle:hover {
  text-decoration: underline;
}
.calc__schedule {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}
.calc__schedule-disclaimer {
  margin: 0;
  font-size: var(--fs-xs);
  color: var(--color-text-muted);
}
.calc__schedule-table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--fs-sm);
}
.calc__schedule-table th,
.calc__schedule-table td {
  padding: var(--space-2);
  text-align: right;
  border-bottom: 1px solid var(--color-border-subtle);
}
.calc__schedule-table th:first-child,
.calc__schedule-table td:first-child,
.calc__schedule-table th:nth-child(2),
.calc__schedule-table td:nth-child(2) {
  text-align: left;
}
</style>
