import { createI18n, type I18n } from 'vue-i18n'
import type { Locale } from '@/types/api'
import ru from './locales/ru.json'
import tj from './locales/tj.json'

const STORAGE_KEY = 'sravni.locale'

export const SUPPORTED_LOCALES: Locale[] = ['ru', 'tj']

function loadLocale(): Locale {
  // Node SSR has no localStorage — server always renders from the URL (see router),
  // this fallback only matters for the initial client-only singleton below.
  if (typeof window === 'undefined') return 'ru'
  const stored = localStorage.getItem(STORAGE_KEY)
  if (stored === 'ru' || stored === 'tj') return stored
  return 'ru'
}

/** Fresh i18n instance factory — used per-request under SSR (app.ts) to avoid a shared singleton. */
export function createI18nInstance(locale: Locale = loadLocale()) {
  return createI18n({
    legacy: false,
    locale,
    fallbackLocale: 'ru',
    messages: { ru, tj },
  })
}

export const i18n = createI18nInstance()

/**
 * Switch locale on a given i18n instance (defaults to the module singleton);
 * persists the choice client-side only. `<html lang>` is not set here — it's
 * owned centrally by App.vue via @unhead/vue (SSR-safe; this module has no
 * DOM access under Node).
 */
export function setLocale(locale: Locale, target: I18n = i18n): void {
  target.global.locale.value = locale
  if (typeof window === 'undefined') return
  localStorage.setItem(STORAGE_KEY, locale)
}

// Persist the initial locale (client-only; no-op under SSR).
setLocale(i18n.global.locale.value as Locale)
