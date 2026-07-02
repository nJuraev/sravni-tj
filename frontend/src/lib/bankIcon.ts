import type { Bank } from '@/types/api'

/**
 * Иконка банка: сначала его собственный логотип (logo_url из БД), иначе —
 * favicon сайта, чтобы у плитки всегда была картинка, а не голая буква.
 */
export function bankLogoUrl(bank: Pick<Bank, 'logo_url' | 'website'>): string {
  if (bank.logo_url) return bank.logo_url
  return faviconUrl(bank.website)
}

function faviconUrl(website?: string | null): string {
  if (!website) return ''
  try {
    // website в БД может лежать без схемы (bank.tj) — достраиваем, иначе URL бросит.
    const url = /^https?:\/\//i.test(website) ? website : `https://${website}`
    return `https://www.google.com/s2/favicons?sz=64&domain=${new URL(url).hostname}`
  } catch {
    return ''
  }
}
