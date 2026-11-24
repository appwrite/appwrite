type InvoiceItemLike = { projectId?: string | null; amount: number }

export type ProjectBreakdownTotal<T extends InvoiceItemLike> = {
  projectId: string
  items: T[]
  total: number
}

/**
 * Per-project invoice totals, most expensive first, and their subtotal.
 * Amounts are in cents; totals are returned in currency units.
 */
export function getProjectBreakdownTotals<T extends InvoiceItemLike>(
  projectIds: string[],
  items: T[],
): { projects: ProjectBreakdownTotal<T>[]; subtotal: number } {
  let subtotalInCents = 0
  const projects = projectIds
    .map((projectId) => {
      const projectItems = items.filter((item) => item.projectId === projectId)
      const totalInCents = projectItems.reduce(
        (sum, item) => sum + item.amount,
        0,
      )
      subtotalInCents += totalInCents
      return { projectId, items: projectItems, total: totalInCents / 100 }
    })
    // Array.prototype.sort is stable, so ties keep the incoming order.
    .sort((a, b) => b.total - a.total)
  return { projects, subtotal: subtotalInCents / 100 }
}
