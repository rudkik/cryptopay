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
 * swagger-ui already ships a light theme, so this block no longer repaints
 * every surface — it only pulls the defaults onto the app palette: our warm
 * borders and greys, the violet accent, and the shared radii/typography.
 * Scoped + :deep() keeps it inside this view, and the whole block (plus
 * swagger-ui.css) lands in the route's async chunk.
 */
.swagger-shell {
  --cp-surface: #ffffff;
  --cp-surface-2: #f1efe8;
  --cp-border: #e5e2d9;
  --cp-border-strong: #d6d2c6;
  --cp-primary: #6d4df2;
  --cp-primary-ink: #5a3bdc;
  --cp-text: #1c1b1f;
  --cp-muted: #63616c;
  --cp-danger: #b91c1c;
}

.swagger-shell :deep(.swagger-ui) {
  color: var(--cp-text);
  font-family: inherit;
}

/* --- Typography: swagger-ui hard-codes its own near-black / grey scale. */
.swagger-shell :deep(.swagger-ui .info .title),
.swagger-shell :deep(.swagger-ui .info h1),
.swagger-shell :deep(.swagger-ui .info h2),
.swagger-shell :deep(.swagger-ui .info h3),
.swagger-shell :deep(.swagger-ui .info h4),
.swagger-shell :deep(.swagger-ui .info h5),
.swagger-shell :deep(.swagger-ui .info li),
.swagger-shell :deep(.swagger-ui .info p),
.swagger-shell :deep(.swagger-ui .opblock-tag),
.swagger-shell :deep(.swagger-ui .opblock .opblock-section-header h4),
.swagger-shell :deep(.swagger-ui .opblock .opblock-summary-path),
.swagger-shell :deep(.swagger-ui .responses-inner h4),
.swagger-shell :deep(.swagger-ui .responses-inner h5),
.swagger-shell :deep(.swagger-ui table thead tr td),
.swagger-shell :deep(.swagger-ui table thead tr th),
.swagger-shell :deep(.swagger-ui .model-title),
.swagger-shell :deep(.swagger-ui label) {
  color: var(--cp-text);
}

.swagger-shell :deep(.swagger-ui .parameter__type),
.swagger-shell :deep(.swagger-ui .parameter__deprecated),
.swagger-shell :deep(.swagger-ui .prop-format),
.swagger-shell :deep(.swagger-ui .response-col_description),
.swagger-shell :deep(.swagger-ui .opblock-tag small),
.swagger-shell :deep(.swagger-ui .opblock .opblock-summary-description),
.swagger-shell :deep(.swagger-ui .info .base-url) {
  color: var(--cp-muted);
}

.swagger-shell :deep(.swagger-ui .info a),
.swagger-shell :deep(.swagger-ui a.nostyle),
.swagger-shell :deep(.swagger-ui .markdown a),
.swagger-shell :deep(.swagger-ui .renderedMarkdown a) {
  color: var(--cp-primary-ink);
}

/* Inline `code` in descriptions defaults to a pale pink pill. */
.swagger-shell :deep(.swagger-ui .markdown code),
.swagger-shell :deep(.swagger-ui .renderedMarkdown code) {
  background: var(--cp-surface-2);
  color: var(--cp-primary-ink);
}

/* --- Surfaces: warm borders, our radius, no drop shadows inside the card. */
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
  border-radius: 12px;
  box-shadow: none;
}

.swagger-shell :deep(.swagger-ui .opblock .opblock-summary) {
  border-color: var(--cp-border);
}

/*
 * Method tints. swagger-ui's defaults are saturated blues/greens; these keep a
 * readable hue on the warm ground and put the brand violet on POST.
 */
.swagger-shell :deep(.swagger-ui .opblock.opblock-get) {
  background: #f2f6ff;
  border-color: #c9d8f7;
}
.swagger-shell :deep(.swagger-ui .opblock.opblock-get .opblock-summary-method) {
  background: #1d4ed8;
}
.swagger-shell :deep(.swagger-ui .opblock.opblock-post) {
  background: #f5f2ff;
  border-color: #d6cbfb;
}
.swagger-shell :deep(.swagger-ui .opblock.opblock-post .opblock-summary-method) {
  background: var(--cp-primary);
}
.swagger-shell :deep(.swagger-ui .opblock.opblock-put) {
  background: #fdf6ea;
  border-color: #f0dcb8;
}
.swagger-shell :deep(.swagger-ui .opblock.opblock-put .opblock-summary-method) {
  background: #a24a08;
}
.swagger-shell :deep(.swagger-ui .opblock.opblock-delete) {
  background: #fdf0f0;
  border-color: #f2cccc;
}
.swagger-shell :deep(.swagger-ui .opblock.opblock-delete .opblock-summary-method) {
  background: var(--cp-danger);
}

.swagger-shell :deep(.swagger-ui .opblock-tag) {
  border-color: var(--cp-border);
}
.swagger-shell :deep(.swagger-ui .opblock-tag:hover),
.swagger-shell :deep(.swagger-ui section.models .model-container:hover) {
  background: var(--cp-surface-2);
}

.swagger-shell :deep(.swagger-ui section.models .model-container),
.swagger-shell :deep(.swagger-ui section.models h4) {
  background: transparent;
  border-color: var(--cp-border);
}

.swagger-shell :deep(.swagger-ui .model-box) {
  background: var(--cp-surface-2);
}

.swagger-shell :deep(.swagger-ui table tbody tr td) {
  border-color: var(--cp-border);
}

/* --- Form controls. */
.swagger-shell :deep(.swagger-ui input[type='text']),
.swagger-shell :deep(.swagger-ui input[type='password']),
.swagger-shell :deep(.swagger-ui input[type='search']),
.swagger-shell :deep(.swagger-ui input[type='email']),
.swagger-shell :deep(.swagger-ui input[type='file']),
.swagger-shell :deep(.swagger-ui textarea),
.swagger-shell :deep(.swagger-ui select),
.swagger-shell :deep(.swagger-ui .filter .operation-filter-input),
.swagger-shell :deep(.swagger-ui .servers > label select) {
  background: var(--cp-surface);
  border-color: var(--cp-border-strong);
  border-radius: 10px;
  color: var(--cp-text);
}

.swagger-shell :deep(.swagger-ui textarea:focus),
.swagger-shell :deep(.swagger-ui input[type='text']:focus),
.swagger-shell :deep(.swagger-ui select:focus) {
  border-color: var(--cp-primary);
  outline: 2px solid rgba(109, 77, 242, 0.4);
  outline-offset: 1px;
}

/* --- Buttons. */
.swagger-shell :deep(.swagger-ui .btn) {
  background: var(--cp-surface);
  border-color: var(--cp-border-strong);
  border-radius: 10px;
  color: var(--cp-text);
  box-shadow: none;
}
.swagger-shell :deep(.swagger-ui .btn:hover) {
  border-color: var(--cp-primary);
}
.swagger-shell :deep(.swagger-ui .btn.authorize) {
  color: var(--cp-primary-ink);
  border-color: var(--cp-primary);
}
.swagger-shell :deep(.swagger-ui .btn.authorize svg) {
  fill: var(--cp-primary-ink);
}
.swagger-shell :deep(.swagger-ui .btn.execute) {
  background: var(--cp-primary);
  border-color: var(--cp-primary);
  color: #fff;
}
.swagger-shell :deep(.swagger-ui .btn.cancel) {
  background: var(--cp-surface);
  border-color: var(--cp-danger);
  color: var(--cp-danger);
}

.swagger-shell :deep(.swagger-ui svg.arrow),
.swagger-shell :deep(.swagger-ui .expand-methods svg),
.swagger-shell :deep(.swagger-ui .expand-operation svg),
.swagger-shell :deep(.swagger-ui .authorization__btn svg) {
  fill: var(--cp-muted);
}

.swagger-shell :deep(.swagger-ui .response-control-media-type--accept-controller select) {
  border-color: var(--cp-primary);
}

/* Code samples keep swagger-ui's dark microlight block — it is a terminal
   sample, and inverting it would cost the syntax highlighting. */
.swagger-shell :deep(.swagger-ui .highlight-code > .microlight),
.swagger-shell :deep(.swagger-ui .model-example pre) {
  border-radius: 10px;
}

/* --- Auth dialog. */
.swagger-shell :deep(.swagger-ui .dialog-ux .modal-ux-header),
.swagger-shell :deep(.swagger-ui .auth-container) {
  border-color: var(--cp-border);
}
.swagger-shell :deep(.swagger-ui .dialog-ux .backdrop-ux) {
  background: rgba(28, 27, 31, 0.35);
}

/* --- Misc chrome: keep swagger-ui's own breathing room inside our card. */
.swagger-shell :deep(.swagger-ui .wrapper) {
  padding: 0 24px;
  max-width: none;
}
.swagger-shell :deep(.swagger-ui .information-container) {
  padding: 0;
}
.swagger-shell :deep(.swagger-ui .info) {
  margin: 28px 0;
}
.swagger-shell :deep(.swagger-ui .info .title) {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}
.swagger-shell :deep(.swagger-ui .info .title small) {
  top: 0;
}
/*
 * `.version` is excluded on purpose: swagger-ui renders the "1.0.0" / "OAS 3.1"
 * stamps as a <pre class="version"> with white ink inside a coloured <small>
 * pill. Repainting every .info pre light made that text white-on-cream
 * (1.15:1 — invisible) and the padding inflated the pill to 45px tall.
 */
.swagger-shell :deep(.swagger-ui .info pre:not(.version)),
.swagger-shell :deep(.swagger-ui .markdown pre),
.swagger-shell :deep(.swagger-ui .renderedMarkdown pre) {
  padding: 12px 14px;
  border-radius: 8px;
  background: var(--cp-surface-2);
  white-space: pre-wrap;
}

.swagger-shell :deep(.swagger-ui .info .title pre.version) {
  margin: 0;
  padding: 0;
  background: transparent;
  /* Inherit the stamp's dark ink: swagger-ui's own white sits at 2.2:1 on its
     lime "OAS 3.1" pill, which is below AA even for the vendor default. */
  color: inherit;
}
.swagger-shell :deep(.swagger-ui .scheme-container) {
  margin: 0 0 20px;
  padding: 20px 24px;
  border-radius: 12px;
  border: 1px solid var(--cp-border);
  background: var(--cp-surface-2);
  box-shadow: none;
}
.swagger-shell :deep(.swagger-ui .opblock-tag-section) {
  padding: 0 0 8px;
}
.swagger-shell :deep(.swagger-ui .opblock-tag) {
  padding: 10px 12px;
}
.swagger-shell :deep(.swagger-ui .opblock) {
  margin: 0 0 12px;
}
.swagger-shell :deep(.swagger-ui .opblock .opblock-summary) {
  padding: 6px 10px;
}
.swagger-shell :deep(.swagger-ui .opblock .opblock-section-header) {
  padding: 10px 20px;
  box-shadow: none;
  border-bottom: 1px solid var(--cp-border);
}
.swagger-shell :deep(.swagger-ui .servers-title) {
  color: var(--cp-text);
}
.swagger-shell :deep(.swagger-ui hr) {
  border-color: var(--cp-border);
}
</style>
