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
  /** interest before tax — the headline figure */
  income: number
  /** principal + income (gross) */
  total: number
  /** estimated withholding, 12% of income (ст. 238 НК РТ) — banks apply it at payout, shown as a footnote */
  tax: number
}

/** Final withholding tax on deposit interest for individuals, ст. 238 НК РТ. */
export const DEPOSIT_TAX_RATE = 0.12

/**
 * Inputs valid per §5.3: P > 0, n >= 1, 0 <= r <= 100.
 * rate = 0 is valid (installment/рассрочка products carry a 0% tier).
 */
export function isValidCalcInput(input: { amount: number; termMonths: number; rate: number }): boolean {
  return (
    Number.isFinite(input.amount) &&
    input.amount > 0 &&
    Number.isFinite(input.termMonths) &&
    input.termMonths >= 1 &&
    Number.isFinite(input.rate) &&
    input.rate >= 0 &&
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
 * Deposit income (gross, before tax).
 * Simple: income = P*(r/100)*(n/12); total = P + income.
 * Compound (m per year): total = P*(1 + (r/100)/m)^(m*n/12); income = total - P.
 * `tax` is an estimate of the 12% withholding banks apply at payout (ст. 238
 * НК РТ) — informational footnote, already excluded from `income`/`total`.
 */
export function calcDeposit(input: DepositInput): DepositResult | null {
  const { amount: P, termMonths: n, rate: r, capitalize, periodsPerYear: m } = input
  if (!isValidCalcInput({ amount: P, termMonths: n, rate: r })) return null

  const years = n / 12
  const income =
    capitalize && m >= 1 ? P * Math.pow(1 + r / 100 / m, m * years) - P : P * (r / 100) * years

  return { income, total: P + income, tax: income * DEPOSIT_TAX_RATE }
}

export interface ScheduleRow {
  index: number
  date: Date
  payment: number
  interest: number
  principal: number
  balance: number
}

function round2(v: number): number {
  return Math.round(v * 100) / 100
}

/** Same calendar day next month, clamped to that month's last day (no day rollover). */
function addMonthClamped(date: Date, months: number): Date {
  const day = date.getDate()
  const d = new Date(date.getFullYear(), date.getMonth() + months, 1)
  const lastDay = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate()
  d.setDate(Math.min(day, lastDay))
  return d
}

/** First payment date: tomorrow, shifted one month forward (per product policy). */
export function firstPaymentDate(from: Date = new Date()): Date {
  const tomorrow = new Date(from)
  tomorrow.setDate(tomorrow.getDate() + 1)
  return addMonthClamped(tomorrow, 1)
}

/**
 * Month-by-month repayment schedule.
 * Estimate only: assumes standard annuity (interest on remaining balance, i = r/100/12)
 * with no day-count adjustment or grace period — matches most banks but not guaranteed exact.
 * rate = 0 (installment) splits principal evenly, remainder absorbed by the last row.
 */
export function generateCreditSchedule(input: CreditInput, from: Date = new Date()): ScheduleRow[] {
  const { amount: P, termMonths: n, rate: r } = input
  if (!(P > 0) || !(n >= 1) || r < 0 || r > 100) return []

  const start = firstPaymentDate(from)
  const rows: ScheduleRow[] = []
  let balance = P

  if (r === 0) {
    const base = Math.floor((P / n) * 100) / 100
    for (let k = 1; k <= n; k++) {
      const principal = k === n ? round2(balance) : base
      balance = round2(balance - principal)
      rows.push({ index: k, date: addMonthClamped(start, k - 1), payment: principal, interest: 0, principal, balance })
    }
    return rows
  }

  const credit = calcCredit(input)
  if (!credit) return []
  const i = r / 100 / 12

  for (let k = 1; k <= n; k++) {
    const interest = round2(balance * i)
    let principal = round2(credit.monthlyPayment - interest)
    let payment = round2(credit.monthlyPayment)
    if (k === n) {
      principal = balance
      payment = round2(principal + interest)
    }
    balance = round2(balance - principal)
    rows.push({ index: k, date: addMonthClamped(start, k - 1), payment, interest, principal, balance })
  }
  return rows
}
