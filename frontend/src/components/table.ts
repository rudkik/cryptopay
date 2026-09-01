/** Column descriptor shared by every DataTable instance. */
export interface Column {
  key: string
  label: string
  /** Extra classes applied to header and body cells (alignment, width). */
  class?: string
  headerClass?: string
  /** Hide the column below this breakpoint. */
  hideBelow?: 'sm' | 'md' | 'lg'
}
