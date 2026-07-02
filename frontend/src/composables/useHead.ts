/**
 * Императивный head-composable без внешних зависимостей (unhead/vue-meta).
 * Временное решение для клиентского SPA-рендера — как только появится
 * prerender/SSG, мета переедет на серверную генерацию, а этот composable
 * можно будет удалить.
 */

interface HeadOptions {
  title: string
  description: string
  jsonLd?: Record<string, unknown>[]
}

function setMetaTag(attr: 'name' | 'property', key: string, content: string): void {
  let el = document.head.querySelector<HTMLMetaElement>(`meta[${attr}="${key}"]`)
  if (!el) {
    el = document.createElement('meta')
    el.setAttribute(attr, key)
    document.head.appendChild(el)
  }
  el.setAttribute('content', content)
}

function setJsonLd(schemas: Record<string, unknown>[]): () => void {
  const nodes = schemas.map((schema) => {
    const script = document.createElement('script')
    script.type = 'application/ld+json'
    script.textContent = JSON.stringify(schema)
    document.head.appendChild(script)
    return script
  })
  return () => nodes.forEach((n) => n.remove())
}

/**
 * Выставляет title/description/OG/JSON-LD на текущую страницу.
 * Вызывать в onMounted; возвращённая функция снимает JSON-LD при размонтировании.
 */
export function useHead({ title, description, jsonLd }: HeadOptions): () => void {
  const previousTitle = document.title
  document.title = title

  setMetaTag('name', 'description', description)
  setMetaTag('property', 'og:title', title)
  setMetaTag('property', 'og:description', description)

  const clearJsonLd = jsonLd ? setJsonLd(jsonLd) : () => {}

  return () => {
    document.title = previousTitle
    clearJsonLd()
  }
}
