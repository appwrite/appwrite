import { afterAll, afterEach, describe, expect, test } from 'bun:test'
import { GlobalRegistrator } from '@happy-dom/global-registrator'

GlobalRegistrator.register()
const { cleanup, render } = await import('@testing-library/react')
const { Pagination } = await import(
  '../../src/components/global/shared/Pagination.tsx'
)
const { OverviewListCard, overviewListBodyHeight } = await import(
  '../../src/components/pages/projects/$projectId/overview/_components/OverviewListCard.tsx'
)
const { Users } = await import('lucide-react')

/** The clamp is the server's `APP_LIMIT_COUNT`, so locale separators are the only unknown. */
const cap = (5000).toLocaleString()

afterEach(() => cleanup())

// Every DOM test file in this suite shares one process, so the next one can register its own.
afterAll(async () => {
  await GlobalRegistrator.unregister()
})

describe('a clamped list total on screen', () => {
  // A project with far more than APP_LIMIT_COUNT users: `users.list` counts up to the clamp, so
  // `total` comes back at 5,000 and the list reports rows past the page it was asked for.
  test('the pagination summary reads as a floor, not as the exact total', () => {
    const { container } = render(
      <Pagination
        currentPage={1}
        totalItems={5000}
        hasNextPage
        pageSize={25}
        onPageChange={() => {}}
        onPageSizeChange={() => {}}
        itemLabel="users"
      />,
    )

    expect(container.textContent).toContain(`1-25 of ${cap}+`)
    // The clamp cannot say how many pages there are, so no page count is claimed.
    expect(container.textContent).not.toContain(`of 200`)
  })

  test('the overview card count reads as a floor, not as the exact total', () => {
    const { container } = render(
      <OverviewListCard
        icon={Users}
        title="Auth"
        count={5000}
        bodyHeight={overviewListBodyHeight(5)}
      >
        <div />
      </OverviewListCard>,
    )

    expect(container.textContent).toContain(`${cap}+`)
  })

  test('a count below the clamp is still shown as exact', () => {
    const { container } = render(
      <OverviewListCard
        icon={Users}
        title="Auth"
        count={4999}
        bodyHeight={overviewListBodyHeight(5)}
      >
        <div />
      </OverviewListCard>,
    )

    expect(container.textContent).toContain((4999).toLocaleString())
    expect(container.textContent).not.toContain('+')
  })
})
