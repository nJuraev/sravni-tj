/**
 * Client-side financial calculator. Pure functions, no API access.
 * Formulas & edge cases per docs/specs/frontend.md §5.
 */

export interface CreditInput {
  /** principal P > 0 */
  amount: number
  /** term n in months, n >= 1 */
  termMonths: number
  /** annual rate r%, 0 < r <= 100 */
  rate: number
}

export interface CreditResult {
  monthlyPayment: number
  totalPaid: number
  overpayment: number
}

export interface DepositInput {
  amount: number
  termMonths: number
  rate: number
  /** compound interest with capitalization, else simple interest */
  capitalize: boolean
  /** capitalization periods per year (e.g. 12 = monthly); used only when capitalize */
  periodsPerYear: number
}

export interface DepositResult {
  income: number
  total: number
}

/** Inputs valid per §5.3: P > 0, n >= 1, 0 < r <= 100. */
export function isValidCalcInput(input: { amount: number; termMonths: number; rate: number }): boolean {
  return (
    Number.isFinite(input.amount) &&
    input.amount > 0 &&
    Number.isFinite(input.termMonths) &&
    input.termMonths >= 1 &&
    Number.isFinite(input.rate) &&
    input.rate > 0 &&
    input.rate <= 100
  )
}

/**
 * Annuity monthly payment.
 * i = r/100/12; if i > 0: A = P*i*(1+i)^n / ((1+i)^n - 1); if i = 0: A = P/n.
 * Note: isValidCalcInput requires rate > 0, but the i=0 branch is kept for
 * direct callers that bypass validation (e.g. rate exactly 0 edge case).
 */
export function calcCredit(input: CreditInput): CreditResult | null {
  const { amount: P, termMonths: n, rate: r } = input
  if (!(P > 0) || !(n >= 1) || r < 0 || r > 100) return null

  const i = r / 100 / 12
  let A: number
  if (i > 0) {
    const factor = Math.pow(1 + i, n)
    A = (P * i * factor) / (factor - 1)
  } else {
    A = P / n
  }
  const totalPaid = A * n
  return {
    monthlyPayment: A,
    totalPaid,
    overpayment: totalPaid - P,
  }
}

/**
 * Deposit income.
 * Simple: income = P*(r/100)*(n/12); total = P + income.
 * Compound (m per year): total = P*(1 + (r/100)/m)^(m*n/12); income = total - P.
 */
export function calcDeposit(input: DepositInput): DepositResult | null {
  const { amount: P, termMonths: n, rate: r, capitalize, periodsPerYear: m } = input
  if (!isValidCalcInput({ amount: P, termMonths: n, rate: r })) return null

  const years = n / 12
  if (capitalize && m >= 1) {
    const total = P * Math.pow(1 + r / 100 / m, m * years)
    return { income: total - P, total }
  }
  const income = P * (r / 100) * years
  return { income, total: P + income }
}
