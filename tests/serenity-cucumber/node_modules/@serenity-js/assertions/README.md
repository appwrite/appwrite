# Serenity/JS

[![Follow Serenity/JS on LinkedIn](https://img.shields.io/badge/Follow-Serenity%2FJS%20-0077B5?logo=linkedin)](https://www.linkedin.com/company/serenity-js)
[![Watch Serenity/JS on YouTube](https://img.shields.io/badge/Watch-@serenity--js-E62117?logo=youtube)](https://www.youtube.com/@serenity-js)
[![Join Serenity/JS Community Chat](https://img.shields.io/badge/Chat-Serenity%2FJS%20Community-FBD30B?logo=matrix)](https://matrix.to/#/#serenity-js:gitter.im)
[![Support Serenity/JS on GitHub](https://img.shields.io/badge/Support-@serenity--js-703EC8?logo=github)](https://github.com/sponsors/serenity-js)

[Serenity/JS](https://serenity-js.org) is an innovative open-source framework designed to make acceptance and regression testing
of complex software systems faster, more collaborative and easier to scale.

⭐️ Get started with Serenity/JS!
- [Serenity/JS web testing tutorial](https://serenity-js.org/handbook/web-testing/your-first-web-scenario)
- [Serenity/JS Handbook](https://serenity-js.org/handbook)
- [API documentation](https://serenity-js.org/api/)
- [Serenity/JS Project Templates](https://serenity-js.org/handbook/project-templates/)

👋 Join the Serenity/JS Community!
- Meet other Serenity/JS developers and maintainers on the [Serenity/JS Community chat channel](https://matrix.to/#/#serenity-js:gitter.im),
- Find answers to your Serenity/JS questions on the [Serenity/JS Forum](https://github.com/orgs/serenity-js/discussions/categories/how-do-i),
- Learn how to [contribute to Serenity/JS](https://serenity-js.org/community/contributing/),
- Support the project and gain access to [Serenity/JS Playbooks](https://github.com/serenity-js/playbooks) by becoming a [Serenity/JS GitHub Sponsor](https://github.com/sponsors/serenity-js)!

## Serenity/JS Assertions

[`@serenity-js/assertions`](https://serenity-js.org/api/rest/) is an assertions library implementing the [Screenplay Pattern](https://serenity-js.org/handbook/design/screenplay-pattern/).

### Installation

To install this module, run the following command in your computer terminal:

```sh
npm install --save-dev @serenity-js/core @serenity-js/assertions
```


### Performing verifications using `Ensure`

```typescript
import { Ensure, endsWith } from '@serenity-js/assertions'
import { actorCalled } from '@serenity-js/core'
import { Navigate, Page } from '@serenity-js/web'

await actorCalled('Erica').attemptsTo(
    Navigate.to('https://serenity-js.org'),
    Ensure.that(
        Page.current().title(), 
        endsWith('Serenity/JS')
    ),
)
```

### Controlling execution flow using `Check`

```typescript
import { actorCalled } from '@serenity-js/core'
import { Check } from '@serenity-js/assertions' 
import { Click, isVisible } from '@serenity-js/protractor'

await actorCalled('Erica').attemptsTo(
    Check.whether(NewsletterModal, isVisible())
        .andIfSo(Click.on(CloseModalButton)),
)
```

### Synchronising the test with the System Under Test using `Wait`

```typescript
import { actorCalled } from '@serenity-js/core'
import { Click, isVisible, Wait } from '@serenity-js/protractor'

await actorCalled('Erica').attemptsTo(
    Wait.until(CloseModalButton, isVisible()),
    Click.on(CloseModalButton)
)
```

### Defining custom expectations using `Expectation.thatActualShould`

```typescript
import { actorCalled } from '@serenity-js/core'
import { Expectation, Ensure } from '@serenity-js/assertions'

function isDivisibleBy(expected: Answerable<number>): Expectation<number> {
    return Expectation.thatActualShould<number, number>('have value divisible by', expected)
        .soThat((actualValue, expectedValue) => actualValue % expectedValue === 0)
}

await actorCalled('Erica').attemptsTo(
    Ensure.that(4, isDivisibleBy(2)),
)
```

### Composing expectations using `Expectation.to`

```typescript
import { actorCalled } from '@serenity-js/core'
import { Expectation, Ensure, and, or, isGreaterThan, isLessThan, equals  } from '@serenity-js/assertions'

function isWithin(lowerBound: number, upperBound: number) {
    return Expectation
        .to(`have value within ${ lowerBound } and ${ upperBound }`)
        .soThatActual(and(
           or(isGreaterThan(lowerBound), equals(lowerBound)),
           or(isLessThan(upperBound), equals(upperBound)),
        ))
}

await actorCalled('Erica').attemptsTo(
    Ensure.that(5, isWithin(3, 6)),
)
```

## 📣 Stay up to date

New features, tutorials, and demos are coming soon!
Follow [Serenity/JS on LinkedIn](https://www.linkedin.com/company/serenity-js),
subscribe to [Serenity/JS channel on YouTube](https://www.youtube.com/@serenity-js) and join the [Serenity/JS Community Chat](https://matrix.to/#/#serenity-js:gitter.im) to stay up to date!
Please also make sure to star ⭐️ [Serenity/JS on GitHub](https://github.com/serenity-js/serenity-js) to help others discover the framework!

[![Follow Serenity/JS on LinkedIn](https://img.shields.io/badge/Follow-Serenity%2FJS%20-0077B5?logo=linkedin)](https://www.linkedin.com/company/serenity-js)
[![Watch Serenity/JS on YouTube](https://img.shields.io/badge/Watch-@serenity--js-E62117?logo=youtube)](https://www.youtube.com/@serenity-js)
[![Join Serenity/JS Community Chat](https://img.shields.io/badge/Chat-Serenity%2FJS%20Community-FBD30B?logo=matrix)](https://matrix.to/#/#serenity-js:gitter.im)
[![GitHub stars](https://img.shields.io/github/stars/serenity-js/serenity-js?label=Serenity%2FJS&logo=github&style=badge)](https://github.com/serenity-js/serenity-js)

## 💛 Support Serenity/JS

If you appreciate all the effort that goes into making sophisticated tools easy to work with, please support our work and become a Serenity/JS GitHub Sponsor today!

[![GitHub Sponsors](https://img.shields.io/badge/Support%20@serenity%2FJS-703EC8?style=for-the-badge&logo=github&logoColor=white)](https://github.com/sponsors/serenity-js)

