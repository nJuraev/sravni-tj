import { useI18n } from 'vue-i18n'
import type { Locale } from '@/types/api'

interface LocalizedPair {
  name_ru: string
  name_tg: string | null
}

/**
 * Pick a multilingual field by active locale with fallback to *_ru
 * (contracts.md: *_tg may be null; *_ru always present).
 */
export function localizedName(item: LocalizedPair, locale: Locale): string {
  if (locale === 'tj' && item.name_tg) return item.name_tg
  return item.name_ru
}

/** Generic: choose the *_tg field when present and the UI locale is tj, else ru. */
export function localizedValue(
  ru: string | null,
  tg: string | null,
  locale: Locale,
): string {
  if (locale === 'tj' && tg) return tg
  return ru ?? ''
}

/** Composable returning reactive helpers bound to the current locale. */
export function useLocalizedField() {
  const { locale } = useI18n()
  return {
    name: (item: LocalizedPair) => localizedName(item, locale.value as Locale),
    value: (ru: string | null, tg: string | null) =>
      localizedValue(ru, tg, locale.value as Locale),
  }
}
