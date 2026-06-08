'use client'

import { useState } from 'react'
import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  ComparisonCellValue,
  ComparisonRowLabel,
  getPlanCtaHref,
  getPlanCtaLabel,
  outlineTierButtonClassName,
} from './_components/PricingShared'
import { PricingSectionHeading } from './_components/PricingSectionHeading'
import { PRICING_PLAN_COLUMNS } from '@/lib/pricing/constants'
import { comparisonTables } from '@/lib/pricing/comparison-data'
import type { ComparisonTable, PlanId } from '@/lib/pricing/types'
import { cn } from '@/lib/utils'

function MobilePlanTabs({
  activePlan,
  onPlanChange,
}: {
  activePlan: PlanId
  onPlanChange: (plan: PlanId) => void
}) {
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
            {column.label}
          </TabsTrigger>
        ))}
      </TabsList>
    </Tabs>
  )
}

function CompareTableHeader() {
  return (
    <div className="hidden border-b border-border lg:grid lg:grid-cols-[minmax(220px,1.2fr)_repeat(3,minmax(0,1fr))]">
      <div className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground sm:px-6">
        Feature
      </div>
      {PRICING_PLAN_COLUMNS.map((column) => (
        <div
          key={column.id}
          className="flex items-center justify-center px-4 py-3 text-center sm:px-6"
        >
          <span className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
            {column.label}
          </span>
        </div>
      ))}
    </div>
  )
}

function CompareCategoryTable({
  table,
  mobilePlan,
}: {
  table: ComparisonTable
  mobilePlan: PlanId
}) {
  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/45">
      <div className="border-b border-border px-4 py-4 sm:px-6">
        <h3 className="font-aeonik-pro text-[16px] font-normal text-foreground sm:text-[17px]">
          {table.title}
        </h3>
      </div>

      <CompareTableHeader />

      <div className="hidden lg:block">
        {table.rows.map((row, index) => (
          <div
            key={row.title}
            className={cn(
              'grid grid-cols-[minmax(220px,1.2fr)_repeat(3,minmax(0,1fr))]',
              index > 0 && 'border-t border-border',
            )}
          >
            <div className="px-4 py-3 sm:px-6">
              <ComparisonRowLabel title={row.title} info={row.info} />
            </div>
            {PRICING_PLAN_COLUMNS.map((column) => (
              <div
                key={column.id}
                className="flex items-center justify-center px-4 py-3 text-center sm:px-6"
              >
                <ComparisonCellValue value={row[column.id]} />
              </div>
            ))}
          </div>
        ))}
      </div>

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
            <div className="shrink-0 text-right">
              <ComparisonCellValue value={row[mobilePlan]} />
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

export function ComparePlansSection() {
  const [mobilePlan, setMobilePlan] = useState<PlanId>('pro')

  return (
    <section id="compare" className="border-b border-border bg-background py-16 sm:py-20">
      <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
        <PricingSectionHeading
          align="left"
          title="Compare plans"
          description="Discover our plans and find the one that fits your project's needs."
          className="max-w-2xl"
        />

        <div className="mt-8 lg:hidden">
          <MobilePlanTabs activePlan={mobilePlan} onPlanChange={setMobilePlan} />
        </div>

        <div className="mt-8 space-y-6 sm:space-y-8">
          {comparisonTables.map((table) => (
            <CompareCategoryTable
              key={table.title}
              table={table}
              mobilePlan={mobilePlan}
            />
          ))}
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
                  <a href={href} target="_blank" rel="noopener noreferrer">
                    {label}
                  </a>
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
                <Link to="/sign-up" search={{ redirect: '/' }}>
                  {label}
                </Link>
              </Button>
            )
          })}
        </div>
      </div>
    </section>
  )
}
