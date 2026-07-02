/** Форматирует ввод под +992 (99) 999-99-99; страна всегда +992. */
export function formatTajikPhone(raw: string): string {
  let digits = raw.replace(/\D/g, '')
  if (digits.startsWith('992')) digits = digits.slice(3)
  digits = digits.slice(0, 9)

  if (digits.length === 0) return '+992 '

  let out = `+992 (${digits.slice(0, 2)}`
  if (digits.length >= 2) out += ')'
  if (digits.length > 2) out += ` ${digits.slice(2, 5)}`
  if (digits.length > 5) out += `-${digits.slice(5, 7)}`
  if (digits.length > 7) out += `-${digits.slice(7, 9)}`
  return out
}

export function tajikPhoneDigits(formatted: string): string {
  const digits = formatted.replace(/\D/g, '')
  return digits.startsWith('992') ? digits.slice(3) : digits
}

export const TAJIK_PHONE_DEFAULT = '+992 '
