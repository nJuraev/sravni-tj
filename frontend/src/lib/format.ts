import type { Currency, Locale } from '@/types/api'

/** Map app locale to a BCP-47 tag (tg has weak Intl support → use ru numerics). */
function intlLocale(locale: Locale): string {
  return locale === 'tg' ? 'tg-Cyrl-TJ' : 'ru-RU'
}

/** Money formatting in product currency, locale-aware, 2 decimals. */
export function formatMoney(value: number, currency: Currency, locale: Locale): string {
  try {
    return new Intl.NumberFormat(intlLocale(locale), {
      style: 'currency',
      currency,
      maximumFractionDigits: 2,
    }).format(value)
  } catch {
    // Fallback if Intl lacks the currency/locale combination.
    return `${formatNumber(value, locale, 2)} ${currency}`
  }
}

export function formatNumber(value: number, locale: Locale, maxFractionDigits = 0): string {
  return new Intl.NumberFormat(intlLocale(locale === 'tg' ? 'tg' : 'ru'), {
    maximumFractionDigits: maxFractionDigits,
  }).format(value)
}

/** Percent like "12.5%" (value is already a percentage number). */
export function formatPercent(value: number, locale: Locale): string {
  return `${formatNumber(value, locale, 2)}%`
}

/** Rate range "10–16.5%" or single value when min == max. */
export function formatRateRange(min: number, max: number, locale: Locale): string {
  if (min === max) return formatPercent(min, locale)
  return `${formatNumber(min, locale, 2)}–${formatPercent(max, locale)}`
}

/**
 * Rate range БЕЗ знака «%» — для случаев, когда unit рисуется отдельным
 * элементом (напр. карточка: крупное число + мелкий «%»). "10–16.5" или "12.5".
 */
export function formatRateRangeValue(min: number, max: number, locale: Locale): string {
  if (min === max) return formatNumber(min, locale, 2)
  return `${formatNumber(min, locale, 2)}–${formatNumber(max, locale, 2)}`
}
