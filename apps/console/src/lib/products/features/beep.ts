import type { ProductFeatureContent } from '@/lib/products/features/types'

/**
 * Beep product page sections, in page order: an alert from the moment it
 * fires to the moment it is handled, then the rest of the app. Facts follow
 * the Beep app (appwrite/beep 0.22.0) and Cloud's notifications worker.
 * Critical alerts ring through silent mode on Android only; keep that
 * explicit until the iOS entitlement ships.
 */
export const beepProductFeatures: ProductFeatureContent[] = [
  {
    id: 'push',
    title: 'Know the moment a monitor fires',
    description:
      'Usage monitors, budget thresholds, and endpoint checks push to every phone you are signed in on, with the severity in the title and the reading in the body. Acknowledge, snooze for an hour, or mute the monitor right from the notification.',
    brandLight: 'pink',
  },
  {
    id: 'alerts',
    title: 'Know how bad it really is',
    description:
      'Open an alert for the reading against its threshold, its peak, and the hours around it. Endpoint checks show the result, the response time, and when the certificate expires. Acknowledge it so your team sees you have it, adjust the threshold, or jump to the project.',
  },
  {
    id: 'escalations',
    title: 'Page the next person in line',
    description:
      'Give a monitor an escalation and Beep notifies each step’s roles and people in turn, right away or after a wait you choose, until someone acknowledges the alert or it resolves. Create, edit, and assign escalations from your phone.',
  },
  {
    id: 'home',
    title: 'Appwrite Cloud, at a glance',
    description:
      'Home shows what is firing, how Appwrite Cloud is doing, and two weeks of alerts by severity, with your projects one tap away. Switch organizations from the menu and everything follows.',
    brandLight: 'pink',
  },
  {
    id: 'projects',
    title: 'Usage for every project',
    description:
      'Search and filter every project in the organization, with firing ones first. Open one for bandwidth, requests, storage, executions, and compute over the last day, week, or month, next to the databases, functions, sites, and users it holds.',
  },
  {
    id: 'choose',
    title: 'You choose what reaches you',
    description:
      'Turn organizations and projects on or off, decide whether resolved alerts and Appwrite updates reach you, and mute a single noisy monitor. Your choices belong to your account, so they apply on every device and in the Console.',
  },
  {
    id: 'snooze',
    title: 'Quiet the noise while you fix it',
    description:
      'When one incident sends a burst of pushes, Home offers to snooze. Pause one organization or all of them for up to 24 hours. Alerts keep firing and showing in Beep, the Console, and email, and Beep tells you when notifications are back on.',
  },
  {
    id: 'critical',
    title: 'Ring through silent mode',
    description:
      'On Android, monitors your team sets to Critical ring like an alarm, at alarm volume, even on silent, and in Do Not Disturb once you allow it. Everything else stays a normal notification.',
    brandLight: 'pink',
  },
  {
    id: 'updates',
    title: 'Keep up with every release',
    description:
      'The Updates tab shows Appwrite Cloud incidents as they happen and every changelog entry, with a push when something new ships. Read it in the app, or turn product updates off in settings.',
  },
  {
    id: 'devices',
    title: 'On every device you carry',
    description:
      'Beep runs on iPhone, iPad, Android phones and foldables, and Macs with Apple silicon. Sign in once with your Appwrite account and every device gets your alerts.',
    layout: 'stacked',
    centered: true,
    hideVisual: true,
    wideCompanion: true,
    dottedBackground: true,
  },
]
