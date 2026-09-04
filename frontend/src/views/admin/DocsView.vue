<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { AlertTriangle, CheckCircle2, Info, List, X } from 'lucide-vue-next'
import CodeBlock from '@/components/CodeBlock.vue'
import PageHeader from '@/components/PageHeader.vue'
import { API_DOCS } from '@/content/apiDocs'
import type { DocBlock } from '@/content/docsTypes'
import { useAuthStore } from '@/stores/auth'
import { authApi } from '@/api/auth'
import type { AppFeatures } from '@/api/types'

const auth = useAuthStore()

/**
 * Module flags: a signed-in admin already has them; an anonymous integrator
 * (this page is public) reads them from the unauthenticated /public/config.
 */
const features = ref<AppFeatures>({ ...auth.features })
onMounted(async () => {
  if (auth.isAuthenticated) return
  try {
    features.value = (await authApi.publicConfig()).features
  } catch {
    // Flags stay all-off: optional sections are simply not shown.
  }
})

/** Sections behind a disabled module are dropped entirely (SPEC §8). */
const docs = computed(() =>
  API_DOCS.map((group) => ({
    ...group,
    sections: group.sections.filter((section) => !section.feature || features.value[section.feature]),
  })).filter((group) => group.sections.length > 0),
)

const activeId = ref<string>(API_DOCS[0]?.sections[0]?.id ?? '')
const tocOpen = ref(false)
let observer: IntersectionObserver | null = null

const allSections = computed(() => docs.value.flatMap((group) => group.sections))

const METHOD_TONES: Record<string, string> = {
  GET: 'border-success/25 bg-success-soft text-success',
  POST: 'border-primary/25 bg-primary-soft text-primary-hover',
  PUT: 'border-warning/25 bg-warning-soft text-warning',
  DELETE: 'border-danger/25 bg-danger-soft text-danger',
}

const CALLOUT_TONES = {
  info: { wrap: 'border-info/25 bg-info-soft text-info', icon: Info },
  warning: { wrap: 'border-warning/25 bg-warning-soft text-warning', icon: AlertTriangle },
  success: { wrap: 'border-success/25 bg-success-soft text-success', icon: CheckCircle2 },
} as const

/** Split `text with \`code\`` into runs so backticks render as inline code. */
function inlineRuns(value: string): { text: string; code: boolean }[] {
  return value.split(/(`[^`]+`)/g).filter(Boolean).map((part) =>
    part.startsWith('`') && part.endsWith('`')
      ? { text: part.slice(1, -1), code: true }
      : { text: part, code: false },
  )
}

function isBlock<K extends DocBlock['kind']>(block: DocBlock, kind: K): block is Extract<DocBlock, { kind: K }> {
  return block.kind === kind
}

function scrollTo(id: string): void {
  tocOpen.value = false
  const element = document.getElementById(id)
  if (!element) return
  element.scrollIntoView({ behavior: 'smooth', block: 'start' })
  activeId.value = id
}

onMounted(() => {
  observer = new IntersectionObserver(
    (entries) => {
      const visible = entries
        .filter((entry) => entry.isIntersecting)
        .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)
      if (visible[0]?.target.id) activeId.value = visible[0].target.id
    },
    { rootMargin: '-88px 0px -70% 0px', threshold: 0 },
  )
  for (const section of allSections.value) {
    const element = document.getElementById(section.id)
    if (element) observer.observe(element)
  }
})

onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <div class="space-y-6">
    <PageHeader
      title="Merchant API"
      description="Everything an integrator needs: authentication, endpoints, webhooks and signature verification."
    >
      <template #actions>
        <button type="button" class="btn-secondary btn-sm xl:hidden" @click="tocOpen = !tocOpen">
          <component :is="tocOpen ? X : List" :size="14" aria-hidden="true" />
          Contents
        </button>
      </template>
    </PageHeader>

    <div class="flex flex-col gap-8 xl:flex-row-reverse">
      <!-- Table of contents -->
      <nav
        class="shrink-0 xl:sticky xl:top-24 xl:h-[calc(100vh-8rem)] xl:w-64 xl:overflow-y-auto"
        :class="tocOpen ? 'block' : 'hidden xl:block'"
        aria-label="Table of contents"
      >
        <div class="card p-4 xl:border-0 xl:bg-transparent xl:p-0 xl:shadow-none">
          <p class="px-2 pb-2 text-[10px] font-semibold uppercase tracking-[0.12em] text-muted">
            On this page
          </p>
          <ul class="space-y-4">
            <li v-for="group in docs" :key="group.id">
              <p class="px-2 pb-1 text-xs font-semibold text-text">{{ group.title }}</p>
              <ul class="space-y-0.5 border-l border-border pl-0">
                <li v-for="section in group.sections" :key="section.id">
                  <button
                    type="button"
                    class="-ml-px block w-full border-l-2 px-3 py-1.5 text-left text-[13px] transition-colors"
                    :class="
                      activeId === section.id
                        ? 'border-primary font-medium text-primary-hover'
                        : 'border-transparent text-muted hover:border-border-strong hover:text-text'
                    "
                    :aria-current="activeId === section.id ? 'true' : undefined"
                    @click="scrollTo(section.id)"
                  >
                    {{ section.title }}
                  </button>
                </li>
              </ul>
            </li>
          </ul>
        </div>
      </nav>

      <!-- Content -->
      <div class="min-w-0 flex-1 space-y-10">
        <section v-for="group in docs" :key="group.id" class="space-y-8">
          <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-primary-hover">
            {{ group.title }}
          </h2>

          <article
            v-for="section in group.sections"
            :id="section.id"
            :key="section.id"
            class="card scroll-mt-24 space-y-4 p-5 sm:p-6"
          >
            <h3 class="text-base font-semibold tracking-tight">{{ section.title }}</h3>

            <template v-for="(block, index) in section.blocks" :key="index">
              <p v-if="isBlock(block, 'text')" class="text-sm leading-relaxed text-muted">
                <template v-for="(run, i) in inlineRuns(block.value)" :key="i">
                  <code
                    v-if="run.code"
                    class="mono rounded border border-border bg-surface-2 px-1 py-0.5 text-[12px] text-text"
                    >{{ run.text }}</code
                  >
                  <template v-else>{{ run.text }}</template>
                </template>
              </p>

              <div
                v-else-if="isBlock(block, 'endpoint')"
                class="flex flex-wrap items-center gap-2.5 rounded-xl border border-border bg-surface-2 px-3.5 py-3"
              >
                <span
                  class="mono rounded-md border px-2 py-0.5 text-[11px] font-semibold"
                  :class="METHOD_TONES[block.method]"
                  >{{ block.method }}</span
                >
                <code class="mono min-w-0 break-all text-[13px] text-text">{{ block.path }}</code>
                <span class="w-full text-xs text-muted sm:w-auto sm:border-l sm:border-border sm:pl-2.5">
                  {{ block.summary }}
                </span>
              </div>

              <div
                v-else-if="isBlock(block, 'callout')"
                class="flex items-start gap-2.5 rounded-xl border px-3.5 py-3"
                :class="CALLOUT_TONES[block.tone].wrap"
              >
                <component
                  :is="CALLOUT_TONES[block.tone].icon"
                  :size="15"
                  class="mt-0.5 shrink-0"
                  aria-hidden="true"
                />
                <div class="min-w-0 text-xs leading-relaxed">
                  <p v-if="block.title" class="font-semibold">{{ block.title }}</p>
                  <p :class="block.title ? 'mt-0.5' : ''">
                    <template v-for="(run, i) in inlineRuns(block.value)" :key="i">
                      <code v-if="run.code" class="mono rounded bg-surface/80 px-1 py-0.5 font-medium">{{ run.text }}</code>
                      <template v-else>{{ run.text }}</template>
                    </template>
                  </p>
                </div>
              </div>

              <component
                :is="block.ordered ? 'ol' : 'ul'"
                v-else-if="isBlock(block, 'list')"
                class="space-y-1.5 pl-5 text-sm text-muted"
                :class="block.ordered ? 'list-decimal' : 'list-disc'"
              >
                <li v-for="(item, i) in block.items" :key="i" class="pl-1 leading-relaxed">
                  <template v-for="(run, j) in inlineRuns(item)" :key="j">
                    <code
                      v-if="run.code"
                      class="mono rounded border border-border bg-surface-2 px-1 py-0.5 text-[12px] text-text"
                      >{{ run.text }}</code
                    >
                    <template v-else>{{ run.text }}</template>
                  </template>
                </li>
              </component>

              <div v-else-if="isBlock(block, 'table')" class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                  <thead>
                    <tr class="border-b border-border bg-surface-2">
                      <th
                        v-for="header in block.headers"
                        :key="header"
                        scope="col"
                        class="whitespace-nowrap px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-muted"
                      >
                        {{ header }}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, i) in block.rows" :key="i" class="border-b border-border last:border-0">
                      <td v-for="(cell, j) in row" :key="j" class="px-3 py-2.5 align-top text-muted">
                        <template v-for="(run, k) in inlineRuns(cell)" :key="k">
                          <code
                            v-if="run.code"
                            class="mono rounded border border-border bg-surface-2 px-1 py-0.5 text-[12px] text-text"
                            >{{ run.text }}</code
                          >
                          <template v-else>{{ run.text }}</template>
                        </template>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <CodeBlock
                v-else-if="isBlock(block, 'code')"
                :code="block.code"
                :language="block.language"
                :title="block.title"
              />
            </template>
          </article>
        </section>
      </div>
    </div>
  </div>
</template>
