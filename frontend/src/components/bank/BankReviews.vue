<script setup lang="ts">
import { computed, onMounted, reactive, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import type { BankReview } from '@/types/api'
import { api } from '@/api/client'
import { ApiError } from '@/api/errors'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseCard from '@/components/ui/BaseCard.vue'
import BaseCheckbox from '@/components/ui/BaseCheckbox.vue'
import BaseTextField from '@/components/ui/BaseTextField.vue'

const props = defineProps<{ bankId: number }>()

const { t, locale } = useI18n()
const bodyId = useId()

const reviews = ref<BankReview[]>([])
const reviewsLoaded = ref(false)
// Политика: рейтинг/отзывы на витрине показываем только при ≥3 одобренных —
// иначе пустой список убивает доверие сильнее, чем его отсутствие.
const showList = computed(() => reviewsLoaded.value && reviews.value.length >= 3)

onMounted(async () => {
  try {
    const res = await api.getBankReviews(props.bankId)
    reviews.value = res.data
  } catch {
    /* список отзывов не критичен для страницы банка */
  } finally {
    reviewsLoaded.value = true
  }
})

function formatDate(iso: string | null): string {
  if (!iso) return ''
  try {
    return new Intl.DateTimeFormat(locale.value === 'tj' ? 'tg-Cyrl-TJ' : 'ru-RU', {
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    }).format(new Date(iso))
  } catch {
    return ''
  }
}

const form = reactive({ author_name: '', rating: 0, body: '', consent: false })
const fieldErrors = reactive<Record<string, string | undefined>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)
const success = ref(false)

const canSubmit = computed(
  () =>
    form.consent &&
    form.author_name.trim().length >= 2 &&
    form.rating >= 1 &&
    form.body.trim().length >= 10,
)

function clearErrors() {
  fieldErrors.author_name = undefined
  fieldErrors.rating = undefined
  fieldErrors.body = undefined
  fieldErrors.consent = undefined
  generalError.value = null
}

async function submit() {
  if (!canSubmit.value || submitting.value) return
  clearErrors()
  submitting.value = true
  try {
    await api.createBankReview(props.bankId, {
      author_name: form.author_name.trim(),
      rating: form.rating,
      body: form.body.trim(),
      consent: form.consent,
    })
    success.value = true
  } catch (err) {
    if (err instanceof ApiError && err.isValidation) {
      for (const [field, messages] of Object.entries(err.fieldErrors)) {
        fieldErrors[field] = messages[0]
      }
    } else {
      generalError.value = t('bank.reviewNetworkError')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="reviews">
    <BaseCard v-if="reviewsLoaded">
      <h2 class="reviews__title">{{ t('bank.reviewsTitle') }}</h2>
      <div v-if="showList" class="reviews__list">
        <article v-for="r in reviews" :key="r.id" class="review">
          <div class="review__head">
            <span class="review__author">{{ r.author_name }}</span>
            <span class="review__stars" aria-hidden="true">
              <span v-for="i in 5" :key="i" class="review__star" :class="{ 'is-filled': i <= r.rating }">★</span>
            </span>
          </div>
          <p class="review__body">{{ r.body }}</p>
          <span class="review__date">{{ formatDate(r.created_at) }}</span>
        </article>
      </div>
      <p v-else class="reviews__empty">{{ t('bank.reviewsEmpty') }}</p>
    </BaseCard>

    <BaseCard>
      <h2 class="reviews__title">{{ t('bank.reviewFormTitle') }}</h2>

      <div v-if="success" class="reviewform__success" role="status">
        <p class="reviewform__success-title">{{ t('bank.reviewSuccessTitle') }}</p>
        <p class="reviewform__success-text">{{ t('bank.reviewSuccessText') }}</p>
      </div>

      <form v-else class="reviewform" novalidate @submit.prevent="submit">
        <BaseTextField
          v-model="form.author_name"
          :label="t('bank.reviewAuthor')"
          :placeholder="t('bank.reviewAuthorPlaceholder')"
          :error="fieldErrors.author_name"
        />

        <div class="reviewform__field">
          <span class="reviewform__label">{{ t('bank.reviewRating') }}</span>
          <div class="reviewform__stars" role="radiogroup" :aria-label="t('bank.reviewRating')">
            <button
              v-for="i in 5"
              :key="i"
              type="button"
              class="reviewform__star"
              :class="{ 'is-filled': i <= form.rating }"
              :aria-pressed="i <= form.rating"
              @click="form.rating = i"
            >
              ★
            </button>
          </div>
          <p v-if="fieldErrors.rating" class="reviewform__error" role="alert">{{ fieldErrors.rating }}</p>
        </div>

        <div class="reviewform__field">
          <label class="reviewform__label" :for="bodyId">{{ t('bank.reviewBody') }}</label>
          <textarea
            :id="bodyId"
            v-model="form.body"
            class="reviewform__textarea"
            :placeholder="t('bank.reviewBodyPlaceholder')"
            rows="4"
          />
          <p v-if="fieldErrors.body" class="reviewform__error" role="alert">{{ fieldErrors.body }}</p>
        </div>

        <BaseCheckbox v-model="form.consent" :error="fieldErrors.consent">
          {{ t('bank.reviewConsentPrefix') }}
          <a href="#" @click.prevent>{{ t('bank.reviewConsentPolicy') }}</a>
        </BaseCheckbox>

        <p v-if="generalError" class="reviewform__general-error" role="alert">{{ generalError }}</p>

        <BaseButton type="submit" :disabled="!canSubmit" :loading="submitting">
          {{ submitting ? t('bank.reviewSubmitting') : t('bank.reviewSubmit') }}
        </BaseButton>
      </form>
    </BaseCard>
  </div>
</template>

<style scoped>
.reviews {
  display: flex;
  flex-direction: column;
  gap: var(--space-5);
}
.reviews__title {
  font-size: var(--fs-lg);
  margin-bottom: var(--space-4);
}
.reviews__empty {
  color: var(--color-text-secondary);
}
.reviews__list {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}
.review {
  padding-bottom: var(--space-4);
  border-bottom: 1px solid var(--color-border-subtle);
}
.review:last-child {
  padding-bottom: 0;
  border-bottom: none;
}
.review__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  margin-bottom: var(--space-1);
}
.review__author {
  font-weight: 700;
}
.review__star {
  color: var(--color-border);
}
.review__star.is-filled {
  color: #f5a623;
}
.review__body {
  color: var(--color-text-secondary);
  margin-bottom: var(--space-1);
}
.review__date {
  font-size: var(--fs-xs);
  color: var(--color-text-muted);
}

.reviewform {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}
.reviewform__field {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}
.reviewform__label {
  font-size: var(--fs-sm);
  font-weight: 600;
  color: var(--color-text-secondary);
}
.reviewform__stars {
  display: flex;
  gap: var(--space-1);
}
.reviewform__star {
  font-size: var(--fs-xl);
  line-height: 1;
  background: none;
  border: none;
  cursor: pointer;
  color: var(--color-border);
  padding: 2px;
}
.reviewform__star.is-filled {
  color: #f5a623;
}
.reviewform__textarea {
  width: 100%;
  padding: var(--space-3) var(--space-4);
  border: 1.5px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-bg);
  color: var(--color-text-primary);
  font: inherit;
  resize: vertical;
}
.reviewform__textarea:focus {
  outline: none;
  border-color: var(--color-primary);
  box-shadow: var(--shadow-focus);
}
.reviewform__error {
  font-size: var(--fs-sm);
  color: var(--color-danger);
}
.reviewform__general-error {
  padding: var(--space-3);
  border-radius: var(--radius-md);
  background: var(--color-danger-soft);
  color: var(--color-danger);
  font-size: var(--fs-sm);
}
.reviewform__success {
  text-align: center;
  padding-block: var(--space-4);
}
.reviewform__success-title {
  font-family: var(--font-display);
  font-weight: 700;
  font-size: var(--fs-lg);
  margin-bottom: var(--space-2);
}
.reviewform__success-text {
  color: var(--color-text-secondary);
}
</style>
