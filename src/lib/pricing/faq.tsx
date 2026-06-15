import type { ReactNode } from 'react'
import { BlogPageAnchor } from '@/components/global/shared/BlogPageAnchor'

export type FaqItem = {
  question: string
  answer: ReactNode
}

const linkClassName =
  'link-neutral'

export const pricingFaqItems: readonly FaqItem[] = [
  {
    question: 'What payment methods does Appwrite support?',
    answer: (
      <>
        Appwrite currently supports{' '}
        <a
          className={linkClassName}
          href="https://appwrite.io/docs/advanced/billing/payments#payment-methods"
          target="_blank"
          rel="noopener noreferrer"
        >
          credit and debit card payments
        </a>
        . We are actively working on adding support for more methods. Please{' '}
        <a
          className={linkClassName}
          href="https://appwrite.io/contact-us"
          target="_blank"
          rel="noopener noreferrer"
        >
          contact us
        </a>{' '}
        in case this is an issue for you.
      </>
    ),
  },
  {
    question: 'What happens if I reach a resource limit in my Pro plan?',
    answer: (
      <>
        Your project will continue to run, and additional charges will apply. You
        can find the costs for additional resources in the pricing plans
        comparison below. We will also send you email reminders when you hit 75%
        and 100% of your resource limits. To avoid unexpected payments, you can
        set up a{' '}
        <a
          className={linkClassName}
          href="https://appwrite.io/docs/advanced/billing/pro#budget-cap"
          target="_blank"
          rel="noopener noreferrer"
        >
          budget cap
        </a>{' '}
        on your organization.{' '}
        <a
          className={linkClassName}
          href="https://appwrite.io/docs/advanced/billing/pro#reaching-resource-limits"
          target="_blank"
          rel="noopener noreferrer"
        >
          Learn more in our docs
        </a>
        .
      </>
    ),
  },
  {
    question: 'What happens if I reach a resource limit in my Free plan?',
    answer: (
      <>
        Your project will freeze, and Appwrite Console will continue running in
        read-only mode. You need to upgrade to Pro, remove resources that exceed
        their limit, or wait for the next billing cycle, which resets usage
        limits.{' '}
        <a
          className={linkClassName}
          href="https://appwrite.io/docs/advanced/billing/pro#reaching-resource-limits"
          target="_blank"
          rel="noopener noreferrer"
        >
          Learn more in our docs
        </a>
        .
      </>
    ),
  },
  {
    question: 'Why does Appwrite ask for payment verification for up to $150?',
    answer: (
      <>
        The Reserve Bank of India (RBI) mandates additional security measures for
        recurring payments on Indian cards. Appwrite is obligated to ask for
        verification before billing your card. Appwrite asks for verification for
        up to $150 in case you use add-ons, but will not charge more than the
        actual amount used or your budget cap. If you need higher limits,{' '}
        <a className={linkClassName} href="mailto:billing@appwrite.io">
          contact us
        </a>
        .
      </>
    ),
  },
  {
    question: 'How can I join the OSS program?',
    answer: (
      <>
        The OSS program is exclusively for active open-source maintainers using
        Appwrite Cloud. You can find more information on how to join the program
        in our{' '}
        <BlogPageAnchor
          className={linkClassName}
          href="/blog/post/announcing-the-appwrite-oss-program"
        >
          announcement blog
        </BlogPageAnchor>
        .
      </>
    ),
  },
  {
    question: 'How can I join the Startups program?',
    answer: (
      <>
        Are you a founder looking to build with Appwrite? Learn more about our
        Startups program on our Startups{' '}
        <a
          className={linkClassName}
          href="https://appwrite.io/startups"
          target="_blank"
          rel="noopener noreferrer"
        >
          landing page
        </a>
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
        Yes, you can sign a BAA with Appwrite. Learn more about our security and
        compliance in our{' '}
        <a
          className={linkClassName}
          href="https://appwrite.io/docs/advanced/security"
          target="_blank"
          rel="noopener noreferrer"
        >
          documentation
        </a>
        .
      </>
    ),
  },
]
