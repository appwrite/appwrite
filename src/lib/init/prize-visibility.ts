import type { LaunchEventPrizes, LaunchSchedulePlatform } from './types'

/** True when a daily swag prize is visible for the current Init day. */
export function isInitDailyPrizeRevealed(currentDay: number, prizeDay: number): boolean {
  return currentDay > 0 && currentDay >= prizeDay
}

/** Platform-specific placeholder so future-day announcements are not spoiled. */
export function getInitMaskedSessionTitle(
  platform: LaunchSchedulePlatform,
  day: number,
): string {
  switch (platform) {
    case 'youtube':
      return 'Product tour'
    case 'reddit':
      return `Day ${day} AMA`
    case 'discord':
      return 'Closing party'
  }
}

export function applyInitPrizesVisibility(
  prizes: LaunchEventPrizes,
  currentDay: number,
): LaunchEventPrizes {
  return {
    ...prizes,
    dailyGiveaways: prizes.dailyGiveaways.map((giveaway) => ({
      ...giveaway,
      sessionTitle: isInitDailyPrizeRevealed(currentDay, giveaway.day)
        ? giveaway.sessionTitle
        : getInitMaskedSessionTitle(giveaway.platform, giveaway.day),
    })),
    grandPrize: {
      ...prizes.grandPrize,
      sessionTitle:
        prizes.grandPrize.sessionTitle &&
        isInitDailyPrizeRevealed(currentDay, prizes.grandPrize.day)
          ? prizes.grandPrize.sessionTitle
          : prizes.grandPrize.platform
            ? getInitMaskedSessionTitle(prizes.grandPrize.platform, prizes.grandPrize.day)
            : prizes.grandPrize.sessionTitle,
    },
  }
}
