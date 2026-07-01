import { useI18n } from 'vue-i18n'
import type { Category, FeatureKey, Product, Subcategory } from '@/types/api'

export const FEATURE_KEYS: FeatureKey[] = [
  'online_application',
  'no_guarantor',
  'capitalization',
  'replenishment',
]

/**
 * Subcategory codes available per category (installment has none).
 * `other` is the shared fallback and offered for both filterable categories.
 */
export const SUBCATEGORIES_BY_CATEGORY: Record<Category, Subcategory[]> = {
  credit: ['consumer', 'mortgage', 'auto', 'business', 'agro', 'education', 'refinance', 'pawn', 'other'],
  deposit: ['term', 'savings', 'demand', 'kids', 'other'],
  installment: [],
}

/** Helpers for rendering product attributes consistently across views. */
export function useProductDisplay() {
  const { t } = useI18n()

  /** Active feature keys (value === true). */
  function activeFeatures(product: Product): FeatureKey[] {
    return FEATURE_KEYS.filter((k) => product.features[k] === true)
  }

  function featureLabel(key: FeatureKey): string {
    return t(`features.${key}`)
  }

  function categoryLabel(product: Product): string {
    if (product.category === 'installment') return t('product.categoryInstallment')
    if (product.category === 'deposit') return t('product.categoryDeposit')
    return t('product.categoryCredit')
  }

  function subcategoryLabel(code: Subcategory): string {
    return t(`subcategory.${code}`)
  }

  return { activeFeatures, featureLabel, categoryLabel, subcategoryLabel }
}
