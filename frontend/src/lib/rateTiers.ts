import type { Currency, RateTier } from '@/types/api'

function inRange(value: number, from: number, to: number | null): boolean {
  const upper = to ?? Number.POSITIVE_INFINITY
  return value >= from && value <= upper
}

/**
 * Resolve the rate tier matching given amount + term + currency.
 * Used to highlight the relevant grid cell and to feed the calculator with the
 * exact rate (frontend.md §3.1, §5.3). Returns the lowest matching rate when
 * multiple tiers overlap. null if no cell matches.
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
