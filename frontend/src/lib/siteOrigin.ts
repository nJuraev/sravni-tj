/** Public site origin (no trailing slash) — for absolute canonical/hreflang/OG/sitemap URLs. */
export const SITE_ORIGIN = (import.meta.env.VITE_PUBLIC_SITE_ORIGIN ?? 'https://sravni.tj').replace(/\/$/, '')
