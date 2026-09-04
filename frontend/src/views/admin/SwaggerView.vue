<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { ExternalLink, FileJson } from 'lucide-vue-next'
import PageHeader from '@/components/PageHeader.vue'
import Spinner from '@/components/Spinner.vue'
// Both come from the package, never a CDN: the app is served under
// `script-src 'self'`, so an external <script> would simply not run.
// The bundle itself is CSP-clean — its only `new Function("return this")`
// sits behind a `typeof globalThis === "object"` check that always wins in a
// browser that supports it (and is wrapped in try/catch besides).
import SwaggerUIBundle from 'swagger-ui-dist/swagger-ui-es-bundle.js'
import type {
  ImmutableList,
  ImmutableMap,
  SwaggerUIPlugin,
  SwaggerUIRequest,
  SwaggerUISystem,
} from 'swagger-ui-dist/swagger-ui-es-bundle.js'
import 'swagger-ui-dist/swagger-ui.css'

/** Static file from `public/`, so it is never touched by the build. */
const SPEC_URL = '/openapi.yaml'

const container = ref<HTMLElement | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const ui = shallowRef<SwaggerUISystem | null>(null)

/**
 * Puts the origin of the tab that is actually open at the top of the "Servers"
 * dropdown, so "Try it out" hits this deployment instead of the `localhost:8095`
 * placeholder the spec ships with. It also matters for CSP: production runs
 * under `connect-src 'self'`, and a cross-origin call would be blocked.
 *
 * Written against the existing Immutable values rather than an Immutable import
 * (`servers.get(0).set(...)`), so it does not care which Immutable version the
 * bundle carries. Any failure falls back to the servers from the spec.
 */
function currentOriginServerPlugin(): SwaggerUIPlugin {
  return {
    statePlugins: {
      spec: {
        wrapSelectors: {
          servers:
            (oriSelector: (...args: unknown[]) => ImmutableList<ImmutableMap>) =>
            (...args: unknown[]) => {
              const servers = oriSelector(...args)

              try {
                const origin = window.location.origin
                const first = servers?.get?.(0)

                if (!first || typeof first.set !== 'function') return servers
                if (servers.some?.((server) => server.get('url') === origin)) return servers

                return servers.unshift(
                  first.set('url', origin).set('description', 'текущий origin (эта вкладка)'),
                )
              } catch {
                return servers
              }
            },
        },
      },
    },
  }
}

onMounted(() => {
  if (!container.value) return

  try {
    ui.value = SwaggerUIBundle({
      domNode: container.value,
      url: SPEC_URL,
      presets: [SwaggerUIBundle.presets.apis],
      plugins: [currentOriginServerPlugin()],
      // No `layout` override: the standalone preset only adds the topbar with
      // its "explore any URL" box, which would let the page load a spec from
      // an arbitrary host.
      deepLinking: true,
      docExpansion: 'list',
      defaultModelsExpandDepth: 1,
      defaultModelExpandDepth: 3,
      displayRequestDuration: true,
      filter: true,
      tryItOutEnabled: false,
      withCredentials: false,
      // Deliberately off: the merchant API key is a live credential, and
      // persisting it would write it into localStorage of the admin origin.
      persistAuthorization: false,
      // The guarantee behind the dropdown. The selected server lives in oas3
      // state, and nudging it from `onComplete` turned out not to stick — the
      // executed request still went to the spec's `servers[0]`, which
      // `connect-src 'self'` then blocked (seen in headless Chrome under the
      // production CSP). Rewriting the origin here is deterministic and does
      // not depend on swagger-ui internals. Nothing is lost by forcing it:
      // under that CSP a cross-origin call from this page cannot succeed at
      // all, so the alternative is not "a request elsewhere" but no request.
      requestInterceptor: (request: SwaggerUIRequest) => {
        try {
          const target = new URL(request.url, window.location.origin)

          if (target.origin !== window.location.origin) {
            request.url = `${window.location.origin}${target.pathname}${target.search}`
          }
        } catch {
          // Leave an unparseable URL alone; swagger-ui reports the failure.
        }

        return request
      },
      onComplete: () => {
        loading.value = false
        // Cosmetic counterpart to the interceptor: point the dropdown at the
        // origin so it shows where requests really go. Optional chaining all
        // the way down — this reaches into swagger-ui internals.
        try {
          const system = ui.value?.getSystem?.() ?? ui.value
          system?.oas3Actions?.setSelectedServer?.(window.location.origin)
        } catch {
          // Non-fatal: the request still goes to the right place.
        }
      },
    })
  } catch (e) {
    loading.value = false
    error.value = e instanceof Error ? e.message : String(e)
  }
})

onBeforeUnmount(() => {
  if (typeof ui.value?.unmount === 'function') ui.value.unmount()
  ui.value = null
})
</script>

<template>
  <div class="space-y-6">
    <PageHeader
      title="Swagger"
      description="Интерактивная спецификация OpenAPI 3.1: Merchant API, hosted checkout и вебхуки."
    >
      <template #actions>
        <a :href="SPEC_URL" class="btn-secondary" target="_blank" rel="noopener">
          <FileJson :size="15" aria-hidden="true" />
          openapi.yaml
          <ExternalLink :size="13" aria-hidden="true" />
        </a>
      </template>
    </PageHeader>

    <p class="text-sm text-muted">
      Нажмите <span class="font-medium text-text">Authorize</span> и вставьте ключ мерчанта
      <code class="rounded bg-surface-2 px-1.5 py-0.5 font-mono text-xs text-text">cp_live_…</code>,
      чтобы выполнять запросы прямо отсюда. Первый сервер в списке — origin этой вкладки; публичные
      эндпоинты <code class="font-mono text-xs">/api/public/*</code> работают без ключа.
    </p>

    <div class="card overflow-hidden">
      <div v-if="loading && !error" class="flex items-center justify-center gap-3 py-24 text-muted">
        <Spinner />
        <span class="text-sm">Загружаем спецификацию…</span>
      </div>

      <div v-if="error" class="px-6 py-16 text-center">
        <p class="text-sm text-danger">Не удалось отрисовать Swagger UI: {{ error }}</p>
        <p class="mt-2 text-sm text-muted">
          Спецификация доступна как файл:
          <a :href="SPEC_URL" class="text-primary hover:underline" target="_blank" rel="noopener">
            {{ SPEC_URL }}
          </a>
        </p>
      </div>

      <!-- swagger-ui mounts its React tree here; v-show keeps the node alive. -->
      <div v-show="!loading && !error" ref="container" class="swagger-shell" />
    </div>
  </div>
</template>

<style scoped>
/*
 * swagger-ui ships a light theme with hard-coded colours and no CSS variables,
 * so every surface has to be repainted in the app palette. Scoped + :deep()
 * keeps all of it inside this view, and the whole block (plus swagger-ui.css
 * itself) lands in the route's async chunk — no other page pays for it.
 */
.swagger-shell {
  --cp-bg: #0a0613;
  --cp-surface: #150d27;
  --cp-surface-2: #1e1438;
  --cp-border: #2d2050;
  --cp-primary: #8b5cf6;
  --cp-text: #ece8f6;
  --cp-muted: #9d94b8;
}

.swagger-shell :deep(.swagger-ui) {
  color: var(--cp-text);
  font-family: inherit;
}

/* --- Typography: swagger-ui hard-codes near-black on almost every text node. */
.swagger-shell :deep(.swagger-ui .info .title),
.swagger-shell :deep(.swagger-ui .info h1),
.swagger-shell :deep(.swagger-ui .info h2),
.swagger-shell :deep(.swagger-ui .info h3),
.swagger-shell :deep(.swagger-ui .info h4),
.swagger-shell :deep(.swagger-ui .info h5),
.swagger-shell :deep(.swagger-ui .info li),
.swagger-shell :deep(.swagger-ui .info p),
.swagger-shell :deep(.swagger-ui .info table),
.swagger-shell :deep(.swagger-ui .info td),
.swagger-shell :deep(.swagger-ui .info th),
.swagger-shell :deep(.swagger-ui .opblock-tag),
.swagger-shell :deep(.swagger-ui .opblock-tag small),
.swagger-shell :deep(.swagger-ui .opblock .opblock-section-header h4),
.swagger-shell :deep(.swagger-ui .opblock .opblock-section-header > label),
.swagger-shell :deep(.swagger-ui .opblock .opblock-summary-description),
.swagger-shell :deep(.swagger-ui .opblock .opblock-summary-operation-id),
.swagger-shell :deep(.swagger-ui .opblock .opblock-summary-path),
.swagger-shell :deep(.swagger-ui .opblock .opblock-summary-path__deprecated),
.swagger-shell :deep(.swagger-ui .opblock-description-wrapper p),
.swagger-shell :deep(.swagger-ui .opblock-external-docs-wrapper p),
.swagger-shell :deep(.swagger-ui .opblock-title_normal p),
.swagger-shell :deep(.swagger-ui .parameter__name),
.swagger-shell :deep(.swagger-ui .parameter__extension),
.swagger-shell :deep(.swagger-ui .parameter__in),
.swagger-shell :deep(.swagger-ui .response-col_links),
.swagger-shell :deep(.swagger-ui .response-col_status),
.swagger-shell :deep(.swagger-ui .responses-inner h4),
.swagger-shell :deep(.swagger-ui .responses-inner h5),
.swagger-shell :deep(.swagger-ui .scheme-container .schemes-title),
.swagger-shell :deep(.swagger-ui .tab li),
.swagger-shell :deep(.swagger-ui table thead tr td),
.swagger-shell :deep(.swagger-ui table thead tr th),
.swagger-shell :deep(.swagger-ui .markdown p),
.swagger-shell :deep(.swagger-ui .markdown li),
.swagger-shell :deep(.swagger-ui .renderedMarkdown p),
.swagger-shell :deep(.swagger-ui .renderedMarkdown li),
.swagger-shell :deep(.swagger-ui .dialog-ux .modal-ux-content h4),
.swagger-shell :deep(.swagger-ui .dialog-ux .modal-ux-content p),
.swagger-shell :deep(.swagger-ui .dialog-ux .modal-ux-header h3),
.swagger-shell :deep(.swagger-ui label),
.swagger-shell :deep(.swagger-ui .model-title),
.swagger-shell :deep(.swagger-ui .model) {
  color: var(--cp-text);
}

.swagger-shell :deep(.swagger-ui .parameter__type),
.swagger-shell :deep(.swagger-ui .parameter__deprecated),
.swagger-shell :deep(.swagger-ui .prop-format),
.swagger-shell :deep(.swagger-ui .response-col_description),
.swagger-shell :deep(.swagger-ui .opblock-tag small),
.swagger-shell :deep(.swagger-ui .info .base-url),
.swagger-shell :deep(.swagger-ui .info .title small pre) {
  color: var(--cp-muted);
}

.swagger-shell :deep(.swagger-ui .info a),
.swagger-shell :deep(.swagger-ui a.nostyle),
.swagger-shell :deep(.swagger-ui .markdown a),
.swagger-shell :deep(.swagger-ui .renderedMarkdown a) {
  color: var(--cp-primary);
}

/* Inline `code` in descriptions defaults to a pale pink pill. */
.swagger-shell :deep(.swagger-ui .markdown code),
.swagger-shell :deep(.swagger-ui .renderedMarkdown code) {
  background: var(--cp-surface-2);
  color: #c4b5fd;
}

/* --- Surfaces. */
.swagger-shell :deep(.swagger-ui .scheme-container),
.swagger-shell :deep(.swagger-ui section.models),
.swagger-shell :deep(.swagger-ui .dialog-ux .modal-ux),
.swagger-shell :deep(.swagger-ui .opblock .opblock-section-header) {
  background: var(--cp-surface);
  border-color: var(--cp-border);
  box-shadow: none;
}

.swagger-shell :deep(.swagger-ui .opblock) {
  background: var(--cp-surface);
  border-color: var(--cp-border);
  box-shadow: none;
}

.swagger-shell :deep(.swagger-ui .opblock .opblock-summary) {
  border-color: var(--cp-border);
}

/* Method tints: keep the hue, drop the milky background. */
.swagger-shell :deep(.swagger-ui .opblock.opblock-get) {
  background: rgba(56, 189, 248, 0.06);
  border-color: rgba(56, 189, 248, 0.4);
}
.swagger-shell :deep(.swagger-ui .opblock.opblock-post) {
  background: rgba(52, 211, 153, 0.06);
  border-color: rgba(52, 211, 153, 0.4);
}
.swagger-shell :deep(.swagger-ui .opblock.opblock-put) {
  background: rgba(251, 191, 36, 0.06);
  border-color: rgba(251, 191, 36, 0.4);
}
.swagger-shell :deep(.swagger-ui .opblock.opblock-delete) {
  background: rgba(248, 113, 113, 0.06);
  border-color: rgba(248, 113, 113, 0.4);
}

.swagger-shell :deep(.swagger-ui .opblock-tag) {
  border-color: var(--cp-border);
}
.swagger-shell :deep(.swagger-ui .opblock-tag:hover) {
  background: rgba(139, 92, 246, 0.08);
}

.swagger-shell :deep(.swagger-ui section.models .model-container),
.swagger-shell :deep(.swagger-ui section.models h4) {
  background: transparent;
  border-color: var(--cp-border);
}
.swagger-shell :deep(.swagger-ui section.models .model-container:hover) {
  background: rgba(139, 92, 246, 0.08);
}

.swagger-shell :deep(.swagger-ui .model-box) {
  background: var(--cp-surface-2);
}

.swagger-shell :deep(.swagger-ui table tbody tr td) {
  border-color: var(--cp-border);
  color: var(--cp-text);
}

.swagger-shell :deep(.swagger-ui .responses-inner),
.swagger-shell :deep(.swagger-ui .opblock-body) {
  color: var(--cp-text);
}

/* --- Form controls. */
.swagger-shell :deep(.swagger-ui input[type='text']),
.swagger-shell :deep(.swagger-ui input[type='password']),
.swagger-shell :deep(.swagger-ui input[type='search']),
.swagger-shell :deep(.swagger-ui input[type='email']),
.swagger-shell :deep(.swagger-ui input[type='file']),
.swagger-shell :deep(.swagger-ui textarea),
.swagger-shell :deep(.swagger-ui select) {
  background: var(--cp-bg);
  border-color: var(--cp-border);
  color: var(--cp-text);
}

.swagger-shell :deep(.swagger-ui textarea:focus),
.swagger-shell :deep(.swagger-ui input[type='text']:focus),
.swagger-shell :deep(.swagger-ui select:focus) {
  border-color: var(--cp-primary);
  outline: none;
}

.swagger-shell :deep(.swagger-ui .filter .operation-filter-input) {
  background: var(--cp-bg);
  border-color: var(--cp-border);
  color: var(--cp-text);
}

/* --- Buttons. */
.swagger-shell :deep(.swagger-ui .btn) {
  background: transparent;
  border-color: var(--cp-border);
  color: var(--cp-text);
  box-shadow: none;
}
.swagger-shell :deep(.swagger-ui .btn:hover) {
  border-color: var(--cp-primary);
}
.swagger-shell :deep(.swagger-ui .btn.authorize) {
  color: var(--cp-primary);
  border-color: var(--cp-primary);
}
.swagger-shell :deep(.swagger-ui .btn.authorize svg) {
  fill: var(--cp-primary);
}
.swagger-shell :deep(.swagger-ui .btn.execute) {
  background: var(--cp-primary);
  border-color: var(--cp-primary);
  color: #fff;
}
.swagger-shell :deep(.swagger-ui .btn.cancel) {
  background: transparent;
  border-color: #f87171;
  color: #f87171;
}

/* Arrows, locks and the expand carets are black SVGs by default. */
.swagger-shell :deep(.swagger-ui svg.arrow),
.swagger-shell :deep(.swagger-ui .expand-methods svg),
.swagger-shell :deep(.swagger-ui .expand-operation svg),
.swagger-shell :deep(.swagger-ui .model-toggle:after) {
  fill: var(--cp-muted);
}
.swagger-shell :deep(.swagger-ui .authorization__btn svg) {
  fill: var(--cp-muted);
}

/* --- Tables of parameters / responses. */
.swagger-shell :deep(.swagger-ui .parameters-col_description input[type='text']) {
  background: var(--cp-bg);
}

.swagger-shell :deep(.swagger-ui .response-control-media-type--accept-controller select) {
  border-color: var(--cp-primary);
}

/* --- Code samples: microlight is already dark, only the frame needs work. */
.swagger-shell :deep(.swagger-ui .highlight-code > .microlight),
.swagger-shell :deep(.swagger-ui .model-example pre) {
  background: #120b22;
}

.swagger-shell :deep(.swagger-ui .copy-to-clipboard) {
  background: rgba(45, 32, 80, 0.9);
}

/* --- Auth dialog. */
.swagger-shell :deep(.swagger-ui .dialog-ux .modal-ux-header) {
  border-color: var(--cp-border);
}
.swagger-shell :deep(.swagger-ui .auth-container) {
  border-color: var(--cp-border);
}
.swagger-shell :deep(.swagger-ui .auth-container .wrapper) {
  color: var(--cp-text);
}
.swagger-shell :deep(.swagger-ui .dialog-ux .backdrop-ux) {
  background: rgba(10, 6, 19, 0.8);
}

/* --- Misc chrome swagger-ui paints white. */
.swagger-shell :deep(.swagger-ui .wrapper) {
  padding: 0;
  max-width: none;
}
.swagger-shell :deep(.swagger-ui .information-container) {
  padding: 0;
}
.swagger-shell :deep(.swagger-ui .info) {
  margin: 1.25rem 0;
}
.swagger-shell :deep(.swagger-ui .scheme-container) {
  margin: 0 0 1.25rem;
  padding: 1rem;
}
.swagger-shell :deep(.swagger-ui .servers > label select) {
  background: var(--cp-bg);
  border-color: var(--cp-border);
  color: var(--cp-text);
}
.swagger-shell :deep(.swagger-ui .servers-title) {
  color: var(--cp-text);
}
.swagger-shell :deep(.swagger-ui hr) {
  border-color: var(--cp-border);
}
</style>
