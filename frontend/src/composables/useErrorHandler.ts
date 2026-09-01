import { ApiError, isApiError } from '@/api/http'
import { toast } from '@/utils/toast'

/** Human message for any thrown value. */
export function errorMessage(error: unknown, fallback = 'Something went wrong'): string {
  if (isApiError(error)) return error.message || fallback
  if (error instanceof Error) return error.message || fallback
  return fallback
}

/** Field-level validation errors from the SPEC error envelope. */
export function fieldErrors(error: unknown): Record<string, string> {
  if (!isApiError(error) || !error.isValidation) return {}
  const out: Record<string, string> = {}
  for (const [field, messages] of Object.entries(error.details)) {
    if (Array.isArray(messages) && messages.length) out[field] = messages[0]!
  }
  return out
}

/**
 * Surface an error as a toast. Validation errors are shown compactly because the
 * form itself renders the per-field messages.
 */
export function reportError(error: unknown, fallback = 'Something went wrong'): ApiError | null {
  if (isApiError(error)) {
    if (error.status === 401) return error // the interceptor already redirects
    const details = error.isValidation
      ? Object.values(error.details).flat().slice(0, 2).join(' ')
      : undefined
    toast.error(error.message || fallback, details)
    return error
  }
  toast.error(errorMessage(error, fallback))
  return null
}
