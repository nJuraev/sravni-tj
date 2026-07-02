import { createI18n } from 'vue-i18n'
import { WIRE_LOCALE, type Locale } from '@/types/api'
import { setApiLocale } from '@/api/client'
import ru from './locales/ru.json'
import tj from './locales/tj.json'

const STORAGE_KEY = 'sravni.locale'

export const SUPPORTED_LOCALES: Locale[] = ['ru', 'tj']

function loadLocale(): Locale {
  const stored = localStorage.getItem(STORAGE_KEY)
  if (stored === 'ru' || stored === 'tj') return stored
  return 'ru'
}

export const i18n = createI18n({
  legacy: false,
  locale: loadLocale(),
  fallbackLocale: 'ru',
  messages: { ru, tj },
})

/** Switch locale: updates i18n, persists, sets <html lang>, syncs API header. */
export function setLocale(locale: Locale): void {
  i18n.global.locale.value = locale
  localStorage.setItem(STORAGE_KEY, locale)
  // <html lang> — технически верный код (tg), не внутренний код фронта (tj).
  document.documentElement.setAttribute('lang', WIRE_LOCALE[locale])
  setApiLocale(locale)
}

// Initialize side effects for the loaded locale.
setLocale(i18n.global.locale.value as Locale)
