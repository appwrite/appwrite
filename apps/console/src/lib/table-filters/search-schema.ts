/**
 * Table filters and search – Zod schema for list route search params (see TABLE_FILTERS_AND_SEARCH.md).
 *
 * Use this in route validateSearch so list pages have a single contract: search, query, page, limit.
 */

import { z } from 'zod'

/** Schema for list view URL search params (search, filters, pagination, sort). */
export const listSearchSchema = z.object({
  search: z.string().optional().catch(undefined),
  query: z.string().optional().catch(undefined),
  page: z.coerce.number().int().min(1).optional().catch(undefined),
  limit: z.coerce.number().int().min(1).max(100).optional().catch(undefined),
  sort: z.string().optional().catch(undefined),
  /** When set to "1", rows view opens the create-row drawer once then strips this param */
  openRowCreate: z.literal('1').optional().catch(undefined),
})

export type ListSearch = z.infer<typeof listSearchSchema>
