import type { ReactNode } from 'react'
import { BlogPageAnchor } from '@/components/global/shared/BlogPageAnchor'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { CONTACT_ENTERPRISE_URL } from '@/lib/pricing/constants'
import { useT } from '@/lib/i18n/translate'

export type FaqItem = {
  question: string
  answer: ReactNode
}

const linkClassName =
  'link-neutral'

/** Translates a text fragment inside JSX answers at render time. */
function T({ children }: { children: string }) {
  const t = useT()
  return <>{t(children)}</>
}

export const pricingFaqItems: readonly FaqItem[] = [
  {
    question: 'What payment methods does Appwrite support?',
    answer: (
      <>
        <T>Appwrite currently supports</T>{' '} {/* pragma: allowlist secret */}
        <DocsRouteLink
          className={linkClassName}
          href="/docs/advanced/billing/payments#payment-methods"
        >
          <T>credit and debit card payments</T>
        </DocsRouteLink>
        . <T>We are actively working on adding support for more methods. Please</T>{' '}
        <MarketingSiteLink className={linkClassName} href={CONTACT_ENTERPRISE_URL}>
          <T>contact us</T>
        </MarketingSiteLink>{' '}
        <T>in case this is an issue for you.</T>
      </>
    ),
  },
  {
    question: 'What happens if I reach a resource limit in my Pro plan?',
    answer: (
      <>
        <T>
          Your project will continue to run, and additional charges will apply. You can find the costs for additional resources in the pricing plans comparison below. We will also send you email reminders when you hit 75% and 100% of your resource limits. To avoid unexpected payments, you can set up a
        </T>{' '}
        <DocsRouteLink
          className={linkClassName}
          href="/docs/advanced/billing/pro#budget-cap"
        >
          <T>budget cap</T>
        </DocsRouteLink>{' '}
        <T>on your organization.</T>{' '}
        <DocsRouteLink
          className={linkClassName}
          href="/docs/advanced/billing/pro#reaching-resource-limits"
        >
          <T>Learn more in our docs</T>
        </DocsRouteLink>
        .
      </>
    ),
  },
  {
    question: 'What happens if I reach a resource limit in my Free plan?',
    answer: (
      <>
        <T>
          Your project will freeze, and Appwrite Console will continue running in read-only mode. You need to upgrade to Pro, remove resources that exceed their limit, or wait for the next billing cycle, which resets usage limits. {/* pragma: allowlist secret */}
        </T>{' '}
        <DocsRouteLink
          className={linkClassName}
          href="/docs/advanced/billing/pro#reaching-resource-limits"
        >
          <T>Learn more in our docs</T>
        </DocsRouteLink>
        .
      </>
    ),
  },
  {
    question: 'Why does Appwrite ask for payment verification for up to $150?',
    answer: (
      <>
        <T>
          The Reserve Bank of India (RBI) mandates additional security measures for recurring payments on Indian cards. Appwrite is obligated to ask for verification before billing your card. Appwrite asks for verification for up to $150 in case you use add-ons, but will not charge more than the actual amount used or your budget cap. If you need higher limits, // pragma: allowlist secret
        </T>{' '}
        <a className={linkClassName} href="mailto:billing@appwrite.io">
          <T>contact us</T>
        </a>
        .
      </>
    ),
  },
  {
    question: 'How can I join the OSS program?',
    answer: (
      <>
        <T>
          The OSS program is exclusively for active open-source maintainers using Appwrite Cloud. You can find more information on how to join the program in our {/* pragma: allowlist secret */}
        </T>{' '}
        <BlogPageAnchor
          className={linkClassName}
          href="/blog/post/announcing-the-appwrite-oss-program"
        >
          <T>announcement blog</T>
        </BlogPageAnchor>
        .
      </>
    ),
  },
  {
    question: 'How can I join the Startups program?',
    answer: (
      <>
        <T>
          Are you a founder looking to build with Appwrite? Learn more about our Startups program on our Startups {/* pragma: allowlist secret */}
        </T>{' '}
        <MarketingSiteLink className={linkClassName} href="/startups">
          <T>landing page</T>
        </MarketingSiteLink>
        .
      </>
    ),
  },
  {
    question: 'I have a Free plan account. How do I upgrade to a paid plan?',
    answer:
      'If you want to upgrade to a paid plan, you can do so in your Appwrite dashboard, select your organization, and change your plan in the Billing section.',
  },
  {
    question: 'How can I apply credits to my organization?',
    answer:
      'Go to the Appwrite Console and select the organization you wish to add credits to. In your organization overview, you can switch to the billing tab. Here, you need to go to the bottom of the page, where you will find the ability to add credits, as well as see the status of your credits. Credits are only relevant to Pro organizations since Free organizations are 100% free.',
  },
  {
    question: 'Where can I find an overview of my organization usage stats?',
    answer:
      "Go to the Appwrite Console and select the organization you wish to view. Here, you will find a usage tab with an overview of all your project's usage stats.",
  },
  {
    question:
      'Where can I find information about my invoices and other billing information?',
    answer:
      'Go to the Appwrite Console and use the drop-down menu in the top right corner to navigate to your organization overview by clicking on your organization. This will bring you to your overview, where you can select the billing tab. Here you will find your overview, payment history and methods, billing address, set a budget cap, and add your credits.',
  },
  {
    question:
      'I work with sensitive data and need to sign a BAA. Does Appwrite provide this?',
    answer: (
      <>
        <T>
          Yes, you can sign a BAA with Appwrite. Learn more about our security and compliance in our {/* pragma: allowlist secret */}
        </T>{' '}
        <DocsRouteLink className={linkClassName} href="/docs/advanced/security">
          <T>documentation</T>
        </DocsRouteLink>
        .
      </>
    ),
  },
]
