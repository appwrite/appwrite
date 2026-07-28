'use client'

import { useState } from 'react'
import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  ComparisonCellValue,
  ComparisonRowLabel,
  getPlanCtaHref,
  getPlanCtaLabel,
  outlineTierButtonClassName,
} from './_components/PricingShared'
import { PricingSectionHeading } from './_components/PricingSectionHeading'
import { CompareToc } from './CompareToc'
import {
  analyticsAttrs,
  type AnalyticsActionId,
} from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { PRICING_PLAN_COLUMNS } from '@/lib/pricing/constants'
import { comparisonTables } from '@/lib/pricing/comparison-data'
import { getComparisonTableAnchorId } from '@/lib/pricing/comparison-sections'
import type { ComparisonTable, PlanId } from '@/lib/pricing/types'
import { cn } from '@/lib/utils'

const PRICING_COMPARE_CTA_ACTIONS: Record<PlanId, AnalyticsActionId> = {
  free: 'pricing-compare-start-free',
  pro: 'pricing-compare-start-pro',
  enterprise: 'pricing-compare-contact-enterprise',
}

const compareTableClassName = 'w-full table-fixed'
const compareStickyHeadClassName =
  'sticky top-0 z-10 bg-background px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground shadow-[inset_0_-1px_0_var(--border)] sm:px-6'

function MobilePlanTabs({
  activePlan,
  onPlanChange,
}: {
  activePlan: PlanId
  onPlanChange: (plan: PlanId) => void
}) {
  const t = useT()
  return (
    <Tabs
      value={activePlan}
      onValueChange={(value) => onPlanChange(value as PlanId)}
      className="lg:hidden"
    >
      <TabsList className="grid h-10 w-full grid-cols-3">
        {PRICING_PLAN_COLUMNS.map((column) => (
          <TabsTrigger
            key={column.id}
            value={column.id}
            className="text-[12px] capitalize"
          >
            {t(column.label)}
          </TabsTrigger>
        ))}
      </TabsList>
    </Tabs>
  )
}

function CompareCategoryTable({
  table,
  mobilePlan,
}: {
  table: ComparisonTable
  mobilePlan: PlanId
}) {
  const t = useT()
  return (
    <div
      id={getComparisonTableAnchorId(table.title)}
      className="scroll-mt-28 overflow-visible rounded-xl border border-border bg-card/45"
    >
      <div className="border-b border-border px-4 py-4 sm:px-6">
        <h3 className="font-aeonik-pro text-[16px] font-normal text-foreground sm:text-[17px]">
          {t(table.title)}
        </h3>
      </div>

      <Table withScrollContainer={false} className={cn('hidden lg:table', compareTableClassName)}>
        <colgroup>
          <col className="w-[32%]" />
          <col />
          <col />
          <col />
        </colgroup>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className={cn(compareStickyHeadClassName, 'text-start')}>
              {t('Feature')}
            </TableHead>
            {PRICING_PLAN_COLUMNS.map((column) => (
              <TableHead
                key={column.id}
                className={cn(compareStickyHeadClassName, 'text-center')}
              >
                {t(column.label)}
              </TableHead>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody>
          {table.rows.map((row) => (
            <TableRow key={row.title} className="hover:bg-transparent border-b border-border">
              <TableCell className="px-4 py-3 align-middle sm:px-6">
                <ComparisonRowLabel title={row.title} info={row.info} />
              </TableCell>
              {PRICING_PLAN_COLUMNS.map((column) => (
                <TableCell
                  key={column.id}
                  className="px-4 py-3 text-center align-middle sm:px-6"
                >
                  <ComparisonCellValue value={row[column.id]} />
                </TableCell>
              ))}
            </TableRow>
          ))}
        </TableBody>
      </Table>

      <div className="lg:hidden">
        {table.rows.map((row, index) => (
          <div
            key={row.title}
            className={cn(
              'flex items-start justify-between gap-4 px-4 py-3 sm:px-6',
              index > 0 && 'border-t border-border',
            )}
          >
            <ComparisonRowLabel title={row.title} info={row.info} />
            <div className="shrink-0 text-end">
              <ComparisonCellValue value={row[mobilePlan]} />
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

export function ComparePlansSection() {
  const t = useT()
  const [mobilePlan, setMobilePlan] = useState<PlanId>('pro')

  return (
    <section
      id="compare"
      className="overflow-visible border-b border-border bg-background py-16 sm:py-20"
    >
      <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
        <PricingSectionHeading
          align="left"
          title={t('Compare plans')}
          description={t("Discover our plans and find the one that fits your project's needs.")}
          className="max-w-2xl"
        />
      </div>

      <div className="mx-auto w-full max-w-7xl overflow-visible px-4 sm:px-6">
        <div className="mt-8 lg:hidden">
          <MobilePlanTabs activePlan={mobilePlan} onPlanChange={setMobilePlan} />
        </div>

        <div
          className="mt-8 grid items-start gap-8 lg:grid-cols-[220px_minmax(0,1fr)] lg:gap-10 xl:grid-cols-[240px_minmax(0,1fr)]"
        >
          <CompareToc />

          <div className="min-w-0 space-y-6 overflow-visible sm:space-y-8">
            {comparisonTables.map((table) => (
              <CompareCategoryTable
                key={table.title}
                table={table}
                mobilePlan={mobilePlan}
              />
            ))}
          </div>
        </div>

        <div className="mt-8 flex flex-col items-stretch gap-3 sm:flex-row sm:justify-center lg:hidden">
          {PRICING_PLAN_COLUMNS.map((column) => {
            const href = getPlanCtaHref(column.id)
            const label = getPlanCtaLabel(column.id)
            const isEnterprise = column.id === 'enterprise'

            if (isEnterprise) {
              return (
                <Button
                  key={column.id}
                  variant="outline"
                  className={cn('h-10 flex-1 text-[13px]', outlineTierButtonClassName)}
                  asChild
                >
                  <Link
                    to={href}
                    {...analyticsAttrs(PRICING_COMPARE_CTA_ACTIONS[column.id])}
                  >
                    {t(label)}
                  </Link>
                </Button>
              )
            }

            return (
              <Button
                key={column.id}
                variant={column.id === 'pro' ? 'brandCta' : 'outline'}
                className={cn(
                  'h-10 flex-1 text-[13px]',
                  column.id !== 'pro' && outlineTierButtonClassName,
                )}
                asChild
              >
                <Link
                  to="/sign-up"
                  search={{ redirect: '/' }}
                  {...analyticsAttrs(PRICING_COMPARE_CTA_ACTIONS[column.id])}
                >
                  {t(label)}
                </Link>
              </Button>
            )
          })}
        </div>
      </div>
    </section>
  )
}
