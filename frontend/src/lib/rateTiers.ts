import type { Currency, RateTier } from '@/types/api'

function inRange(value: number, from: number | null, to: number | null): boolean {
  const lower = from ?? Number.NEGATIVE_INFINITY
  const upper = to ?? Number.POSITIVE_INFINITY
  return value >= lower && value <= upper
}

/**
 * Resolve the rate tier matching given amount + term + currency.
 * Used to highlight the relevant grid cell (RateTierTable) — exact match only,
 * null if no cell matches (nothing to highlight).
 */
export function findRateTier(
  tiers: RateTier[],
  amount: number,
  term: number,
  currency: Currency,
): RateTier | null {
  const matches = tiers.filter(
    (t) =>
      t.currency === currency &&
      inRange(amount, t.amount_from, t.amount_to) &&
      inRange(term, t.term_from, t.term_to),
  )
  if (matches.length === 0) return null
  return matches.reduce((best, t) => (t.rate < best.rate ? t : best))
}

function termDistance(term: number, from: number | null, to: number | null): number {
  const lower = from ?? Number.NEGATIVE_INFINITY
  const upper = to ?? Number.POSITIVE_INFINITY
  if (term < lower) return lower - term
  if (term > upper) return term - upper
  return 0
}

/**
 * Rate for the calculator: exact tier match (findRateTier) when one exists;
 * otherwise the nearest tier by term (same currency, amount-matching tiers
 * preferred) — NOT the product's rate_min. Some banks price purely by term
 * with no amount tiers at all (e.g. Eskhata «Срочный депозит»: ≤12 мес =
 * 12%, >12 мес = 15%, любая сумма) — defaulting to rate_min for any term
 * outside the grid would silently show the low-term rate for a long-term
 * deposit. Falls back to `fallbackRate` only when no tier for the currency
 * exists at all (e.g. rate_tiers wasn't parsed).
 */
export function resolveEffectiveRate(
  tiers: RateTier[],
  amount: number,
  term: number,
  currency: Currency,
  fallbackRate: number,
): number {
  const exact = findRateTier(tiers, amount, term, currency)
  if (exact) return exact.rate

  const sameCurrency = tiers.filter((t) => t.currency === currency)
  if (sameCurrency.length === 0) return fallbackRate

  const amountMatch = sameCurrency.filter((t) => inRange(amount, t.amount_from, t.amount_to))
  const pool = amountMatch.length > 0 ? amountMatch : sameCurrency

  return pool.reduce((best, t) =>
    termDistance(term, t.term_from, t.term_to) < termDistance(term, best.term_from, best.term_to) ? t : best,
  ).rate
}
