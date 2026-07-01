import { createI18n } from 'vue-i18n'
import type { Locale } from '@/types/api'
import { setApiLocale } from '@/api/client'
import ru from './locales/ru.json'
import tg from './locales/tg.json'

const STORAGE_KEY = 'sravni.locale'

export const SUPPORTED_LOCALES: Locale[] = ['ru', 'tg']

function loadLocale(): Locale {
  const stored = localStorage.getItem(STORAGE_KEY)
  if (stored === 'ru' || stored === 'tg') return stored
  return 'ru'
}

export const i18n = createI18n({
  legacy: false,
  locale: loadLocale(),
  fallbackLocale: 'ru',
  messages: { ru, tg },
})

/** Switch locale: updates i18n, persists, sets <html lang>, syncs API header. */
export function setLocale(locale: Locale): void {
  i18n.global.locale.value = locale
  localStorage.setItem(STORAGE_KEY, locale)
  document.documentElement.setAttribute('lang', locale)
  setApiLocale(locale)
}

// Initialize side effects for the loaded locale.
setLocale(i18n.global.locale.value as Locale)
