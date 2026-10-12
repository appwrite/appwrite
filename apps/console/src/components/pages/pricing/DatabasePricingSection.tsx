'use client'

import type { MouseEvent, ReactNode } from 'react'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  DATABASE_PRICING_COMPARISON_ROWS,
  DEDICATED_DATABASE_PRICING_TIERS,
  PRICING_DATABASE_ANCHOR_ID,
} from '@/lib/pricing/dedicated-databases'
import { DATABASE_COMPUTE_CREDITS_NOTE } from '@/lib/database-create-pricing'
import { useT } from '@/lib/i18n/translate'
import { scrollToComparisonSection } from '@/lib/pricing/comparison-scroll'
import { getComparisonTableAnchorId } from '@/lib/pricing/comparison-sections'
import { PricingSectionHeading } from './_components/PricingSectionHeading'

const databasesCompareAnchorId = getComparisonTableAnchorId('Databases')

const compareHeadClassName =
  'px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground sm:px-6'

const compareValueCellClassName =
  'px-4 py-3 align-top text-center text-[13px] leading-5 text-muted-foreground whitespace-pre-line break-words sm:px-6'

function CompareLink({
  href,
  children,
}: {
  href: string
  children: ReactNode
}) {
  return (
    <a
      href={href}
      className="link-neutral"
      onClick={(event: MouseEvent<HTMLAnchorElement>) => {
        event.preventDefault()
        scrollToComparisonSection(href.slice(1))
      }}
    >
      {children}
    </a>
  )
}

export function DatabasePricingSection() {
  const t = useT()
  return (
    <section
      id={PRICING_DATABASE_ANCHOR_ID}
      className="scroll-mt-28 border-b border-border bg-background py-16 sm:py-20"
    >
      <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
        <PricingSectionHeading
          align="left"
          title={t('Database pricing')}
          description={t(
            'Choose serverless or dedicated compute for each database. Pay for usage when traffic is variable, or pick a fixed monthly tier when you need reserved resources and predictable costs.',
          )}
          className="max-w-3xl"
        />

        <div className="mt-8 overflow-visible rounded-xl border border-border bg-card/45">
          <Table withScrollContainer={false} className="w-full table-fixed">
            <colgroup>
              <col className="w-[34%]" />
              <col />
              <col />
            </colgroup>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className={compareHeadClassName} />
                <TableHead className={`${compareHeadClassName} text-center`}>
                  {t('Serverless')}
                </TableHead>
                <TableHead className={`${compareHeadClassName} text-center`}>
                  {t('Dedicated')}
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {DATABASE_PRICING_COMPARISON_ROWS.map((row) => (
                <TableRow
                  key={row.label}
                  className="hover:bg-transparent border-b border-border last:border-b-0"
                >
                  <TableCell className="px-4 py-3 align-top text-[13px] font-medium text-foreground sm:px-6">
                    {t(row.label)}
                  </TableCell>
                  <TableCell className={compareValueCellClassName}>
                    {t(row.serverless)}
                  </TableCell>
                  <TableCell className={compareValueCellClassName}>
                    {t(row.dedicated)}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          <div className="border-t border-border px-4 py-3 sm:px-6">
            <p className="text-[13px] leading-5 text-muted-foreground">
              {t(DATABASE_COMPUTE_CREDITS_NOTE)}{' '}
              {t('Serverless read/write quotas and overage rates are in')}{' '}
              <CompareLink href={`#${databasesCompareAnchorId}`}>
                {t('Compare plans')}
              </CompareLink>
              .
            </p>
          </div>
        </div>

        <div className="mt-8 overflow-hidden rounded-xl border border-border bg-card/45">
          <div className="border-b border-border px-4 py-3 sm:px-6">
            <h3 className="text-[14px] font-semibold text-foreground">
              {t('Dedicated compute tiers')}
            </h3>
          </div>
          <div className="overflow-x-auto">
            <Table withScrollContainer={false} className="w-full min-w-[520px]">
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className={compareHeadClassName}>{t('Tier')}</TableHead>
                  <TableHead className={compareHeadClassName}>{t('CPU')}</TableHead>
                  <TableHead className={compareHeadClassName}>{t('Memory')}</TableHead>
                  <TableHead
                    className={`${compareHeadClassName} text-end`}
                  >
                    {t('Price')}
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {DEDICATED_DATABASE_PRICING_TIERS.map((tier) => (
                  <TableRow
                    key={tier.id}
                    className="hover:bg-transparent border-b border-border last:border-b-0"
                  >
                    <TableCell className="px-4 py-3 text-[13px] font-medium text-foreground sm:px-6">
                      {t(tier.label)}
                    </TableCell>
                    <TableCell className="px-4 py-3 text-[13px] text-muted-foreground sm:px-6">
                      {t(tier.cpu)}
                    </TableCell>
                    <TableCell className="px-4 py-3 text-[13px] text-muted-foreground sm:px-6">
                      {t(tier.memory)}
                    </TableCell>
                    <TableCell className="px-4 py-3 text-end text-[13px] font-medium text-foreground sm:px-6">
                      {t(tier.price)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </div>
      </div>
    </section>
  )
}
