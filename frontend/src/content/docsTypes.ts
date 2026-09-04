export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'DELETE'

export interface DocParagraph {
  kind: 'text'
  /** Plain text; `code spans` are rendered from backticks. */
  value: string
}

export interface DocCallout {
  kind: 'callout'
  tone: 'info' | 'warning' | 'success'
  title?: string
  value: string
}

export interface DocList {
  kind: 'list'
  items: string[]
  ordered?: boolean
}

export interface DocCode {
  kind: 'code'
  language: string
  title?: string
  code: string
}

export interface DocTable {
  kind: 'table'
  headers: string[]
  rows: string[][]
}

export interface DocEndpoint {
  kind: 'endpoint'
  method: HttpMethod
  path: string
  summary: string
}

export type DocBlock = DocParagraph | DocCallout | DocList | DocCode | DocTable | DocEndpoint

export interface DocSection {
  id: string
  title: string
  blocks: DocBlock[]
  /** Rendered only while the named server feature is enabled (SPEC §8). */
  feature?: 'token_sale'
}

export interface DocGroup {
  id: string
  title: string
  sections: DocSection[]
}
