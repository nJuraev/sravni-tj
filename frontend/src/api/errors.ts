import type { ApiErrorBody } from '@/types/api'

/**
 * Typed API error. Carries HTTP status + parsed error envelope from the
 * frozen contract (422 errors{}, 404, 500). UI branches on `status` and
 * reads `fieldErrors` to place messages under form fields.
 */
export class ApiError extends Error {
  readonly status: number
  readonly fieldErrors: Record<string, string[]>

  constructor(status: number, body?: ApiErrorBody) {
    super(body?.message ?? `HTTP ${status}`)
    this.name = 'ApiError'
    this.status = status
    this.fieldErrors = body?.errors ?? {}
  }

  /** Validation failure (422) — show messages under fields. */
  get isValidation(): boolean {
    return this.status === 422
  }

  /** Resource gone / hidden (404). */
  get isNotFound(): boolean {
    return this.status === 404
  }

  /** Network failure (no HTTP response). */
  get isNetwork(): boolean {
    return this.status === 0
  }
}
