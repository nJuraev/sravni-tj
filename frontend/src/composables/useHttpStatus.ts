import { inject, type InjectionKey } from 'vue'

export interface HttpStatusContext {
  status: number
}

export const HTTP_STATUS_KEY: InjectionKey<HttpStatusContext> = Symbol('httpStatus')

/**
 * Lets a view (e.g. NotFoundView) signal a non-200 response. The SSR entry
 * point provides the context and reads `.status` after renderToString to set
 * the real HTTP status — there's no `res` object reachable from a component
 * otherwise. No-op under plain CSR/tests, where nothing provides it.
 */
export function useHttpStatus(code: number): void {
  const ctx = inject(HTTP_STATUS_KEY, null)
  if (ctx) ctx.status = code
}
