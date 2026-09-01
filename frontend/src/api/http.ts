import axios, { AxiosError, type AxiosInstance, type AxiosRequestConfig } from 'axios'
import type { ApiErrorEnvelope, ApiErrorPayload } from './types'

export const TOKEN_STORAGE_KEY = 'cryptopay.admin.token'

export function readStoredToken(): string | null {
  try {
    return window.localStorage.getItem(TOKEN_STORAGE_KEY)
  } catch {
    return null
  }
}

export function writeStoredToken(token: string | null): void {
  try {
    if (token) window.localStorage.setItem(TOKEN_STORAGE_KEY, token)
    else window.localStorage.removeItem(TOKEN_STORAGE_KEY)
  } catch {
    /* storage unavailable (private mode) — requests still work for this session */
  }
}

/** Normalised error surfaced to every caller. */
export class ApiError extends Error {
  readonly code: string
  readonly status: number
  readonly details: Record<string, string[]>

  constructor(payload: ApiErrorPayload, status: number) {
    super(payload.message)
    this.name = 'ApiError'
    this.code = payload.code
    this.status = status
    this.details = payload.details ?? {}
  }

  /** First validation message for a field, if any. */
  fieldError(field: string): string | undefined {
    return this.details[field]?.[0]
  }

  get isValidation(): boolean {
    return this.status === 422 || this.code === 'validation_error'
  }
}

export function isApiError(error: unknown): error is ApiError {
  return error instanceof ApiError
}

const FALLBACK_MESSAGES: Record<number, string> = {
  400: 'The request could not be processed.',
  401: 'Your session has expired. Please sign in again.',
  403: 'You do not have permission to perform this action.',
  404: 'The requested resource was not found.',
  409: 'This action conflicts with the current state.',
  422: 'Please check the highlighted fields.',
  429: 'Too many requests — slow down and try again shortly.',
  500: 'Something went wrong on the server.',
  503: 'The service is temporarily unavailable.',
}

function toApiError(error: AxiosError<ApiErrorEnvelope>): ApiError {
  const status = error.response?.status ?? 0
  const envelope = error.response?.data

  if (envelope && typeof envelope === 'object' && 'error' in envelope && envelope.error) {
    return new ApiError(envelope.error, status)
  }
  if (status === 0) {
    return new ApiError(
      { code: 'network_error', message: 'Cannot reach the API. Check your connection.' },
      0,
    )
  }
  return new ApiError(
    { code: 'server_error', message: FALLBACK_MESSAGES[status] ?? error.message },
    status,
  )
}

type UnauthorizedHandler = () => void
let onUnauthorized: UnauthorizedHandler | null = null

/** Registered from the router/auth bootstrap so http.ts stays dependency-free. */
export function setUnauthorizedHandler(handler: UnauthorizedHandler): void {
  onUnauthorized = handler
}

export const http: AxiosInstance = axios.create({
  baseURL: '/api',
  headers: { Accept: 'application/json' },
  timeout: 30_000,
})

http.interceptors.request.use((config) => {
  const token = readStoredToken()
  if (token) {
    config.headers.set('Authorization', `Bearer ${token}`)
  }
  return config
})

http.interceptors.response.use(
  (response) => response,
  (error: AxiosError<ApiErrorEnvelope>) => {
    const apiError = toApiError(error)
    const url = error.config?.url ?? ''
    const isLoginCall = url.includes('/auth/login')
    const isPublicCall = url.startsWith('/public') || url.includes('/api/public')

    if (apiError.status === 401 && !isLoginCall && !isPublicCall) {
      writeStoredToken(null)
      onUnauthorized?.()
    }
    return Promise.reject(apiError)
  },
)

/** Thin typed wrappers so resource modules stay declarative. */
export async function get<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
  const { data } = await http.get<T>(url, config)
  return data
}

export async function post<T>(url: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> {
  const { data } = await http.post<T>(url, body, config)
  return data
}

export async function put<T>(url: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> {
  const { data } = await http.put<T>(url, body, config)
  return data
}

export async function del<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
  const { data } = await http.delete<T>(url, config)
  return data
}

/**
 * Laravel `JsonResource` wraps a single resource in `{ "data": ... }` (collections add
 * `meta`/`links` on top). SPEC §6 documents the resource itself, so single-resource
 * callers unwrap the envelope; paginated callers keep it and read `data`/`meta`.
 */
type Wrapped<T> = T | { data: T }

export function unwrap<T>(payload: Wrapped<T>): T {
  if (payload && typeof payload === 'object' && 'data' in payload) {
    return (payload as { data: T }).data
  }
  return payload as T
}

/** GET/POST/PUT returning a single resource envelope. */
export async function getOne<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
  return unwrap<T>(await get<Wrapped<T>>(url, config))
}

export async function postOne<T>(
  url: string,
  body?: unknown,
  config?: AxiosRequestConfig,
): Promise<T> {
  return unwrap<T>(await post<Wrapped<T>>(url, body, config))
}

export async function putOne<T>(
  url: string,
  body?: unknown,
  config?: AxiosRequestConfig,
): Promise<T> {
  return unwrap<T>(await put<Wrapped<T>>(url, body, config))
}

/** Drops empty filter values so the query string stays clean. */
export type QueryParams = Record<string, string | number | boolean | null | undefined>

export function cleanParams(params: QueryParams): Record<string, string | number | boolean> {
  const out: Record<string, string | number | boolean> = {}
  for (const [key, value] of Object.entries(params)) {
    if (value === null || value === undefined || value === '') continue
    out[key] = value
  }
  return out
}
