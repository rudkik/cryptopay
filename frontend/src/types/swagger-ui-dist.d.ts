/**
 * Minimal ambient types for the prebuilt swagger-ui bundle.
 *
 * `swagger-ui-dist` ships no declarations, and the typed `swagger-ui` package
 * would pull React and the whole source tree into the build. Only what
 * SwaggerView.vue actually touches is declared here.
 */
declare module 'swagger-ui-dist/swagger-ui-es-bundle.js' {
  /** Immutable.js value as swagger-ui hands it to a wrapped selector. */
  interface ImmutableList<T> {
    get(index: number): T | undefined
    size: number
    unshift(value: T): ImmutableList<T>
    some(predicate: (value: T) => boolean): boolean
  }

  interface ImmutableMap {
    get(key: string): unknown
    set(key: string, value: unknown): ImmutableMap
  }

  interface SwaggerUIPlugin {
    statePlugins?: Record<string, unknown>
    [key: string]: unknown
  }

  /** The request object swagger-ui hands to `requestInterceptor`. */
  interface SwaggerUIRequest {
    url: string
    method?: string
    headers?: Record<string, string>
    body?: unknown
  }

  interface SwaggerUIConfig {
    domNode?: Element | null
    url?: string
    spec?: Record<string, unknown>
    presets?: unknown[]
    plugins?: SwaggerUIPlugin[]
    layout?: string
    deepLinking?: boolean
    docExpansion?: 'list' | 'full' | 'none'
    defaultModelsExpandDepth?: number
    defaultModelExpandDepth?: number
    displayRequestDuration?: boolean
    filter?: boolean | string
    persistAuthorization?: boolean
    tryItOutEnabled?: boolean
    withCredentials?: boolean
    supportedSubmitMethods?: string[]
    requestInterceptor?: (request: SwaggerUIRequest) => SwaggerUIRequest
    onComplete?: () => void
    syntaxHighlight?: { activated?: boolean; theme?: string } | false
  }

  interface SwaggerUIActions {
    oas3Actions?: {
      /** Sets the server "Try it out" sends requests to. */
      setSelectedServer?: (server: string, namespace?: string) => void
    }
  }

  interface SwaggerUISystem extends SwaggerUIActions {
    getSystem?: () => SwaggerUIActions
    unmount?: () => void
  }

  interface SwaggerUIConstructor {
    (config: SwaggerUIConfig): SwaggerUISystem
    presets: { apis: unknown }
    plugins: Record<string, unknown>
  }

  const SwaggerUIBundle: SwaggerUIConstructor
  export default SwaggerUIBundle
  export type {
    ImmutableList,
    ImmutableMap,
    SwaggerUIActions,
    SwaggerUIConfig,
    SwaggerUIPlugin,
    SwaggerUIRequest,
    SwaggerUISystem,
  }
}
