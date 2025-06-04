import type { Actor } from '../screenplay';

/**
 * Serenity/JS uses the concept of a _**cast of actors**_ to centralise the process of configuring the [actors](https://serenity-js.org/api/core/class/Actor/) and assigning their [abilities](https://serenity-js.org/api/core/class/Ability/).
 *
 * When you invoke [`actorCalled`](https://serenity-js.org/api/core/function/actorCalled/) for the first time in a test scenario,
 * Serenity/JS [instantiates a new actor](https://serenity-js.org/api/core/class/Actor/)
 * and passes it through the [`Cast.prepare`](https://serenity-js.org/api/core/class/Cast/#prepare) method.
 * Specifying a **custom cast** gives you an opportunity to configure the actor with the abilities
 * they need before it's returned to the caller,
 * or configure the actors differently **depending on their name**.
 * It also helps you to avoid having to configure abilities individually in every test scenario.
 *
 * :::tip Remember
 * A **cast** is responsible for assigning **abilities** to **actors** in a central location.
 * :::
 *
 * ## Configuring a cast of actors for the entire test suite
 *
 * When working with relatively **simple scenarios** where all the actors should always receive the same set of abilities,
 * you can [`engage`](https://serenity-js.org/api/core/function/engage/) Serenity/JS to use a generic [`Cast.where`](https://serenity-js.org/api/core/class/Cast/#where):
 *
 * ```typescript
 * import { Cast, configure } from '@serenity-js/core'
 * import { CallAnApi } from '@serenity-js/rest'
 *
 * configure({
 *   actors: Cast.where(actor => actor.whoCan(
 *     CallAnApi.at('http://api.example.org'),
 *     // other abilities
 *   ))
 * })
 * ```
 *
 * If you're using Serenity/JS with one of the [supported test runners](https://serenity-js.org/handbook/test-runners/),
 * you might prefer to use your test runner's native configuration mechanism
 * instead of invoking [`engage`](https://serenity-js.org/api/core/function/engage/) explicitly.
 *
 * :::tip configure vs engage
 * Calling [`configure`](https://serenity-js.org/api/core/function/configure/) resets the entire Serenity/JS configuration
 * and should be done exactly once in your entire test suite.
 * If you want to retain the configuration but reset the cast, use [`engage`](https://serenity-js.org/api/core/function/engage/) instead.
 * :::
 *
 * Learn more about configuring Serenity/JS with:
 * - [Cucumber.js](https://serenity-js.org/handbook/test-runners/cucumber)
 * - [Jasmine](https://serenity-js.org/handbook/test-runners/jasmine)
 * - [Mocha](https://serenity-js.org/handbook/test-runners/mocha)
 * - [Playwright Test](https://serenity-js.org/handbook/test-runners/playwright-test)
 * - [Protractor](https://serenity-js.org/handbook/test-runners/protractor)
 * - [WebdriverIO](https://serenity-js.org/handbook/test-runners/webdriverio)
 *
 * ## Engaging a cast of actors for the specific scenario
 *
 * If you want to retain Serenity/JS configuration, but set a different [cast](https://serenity-js.org/api/core/class/Cast/)
 * for the given test scenario you should use [`engage`](https://serenity-js.org/api/core/function/engage/) instead of [`configure`](https://serenity-js.org/api/core/function/configure/).
 *
 * This approach is useful for example when your entire test suite is dedicated to interacting with the system
 * under test via its REST APIs, and you have a handful of scenarios that need a web browser.
 *
 * ```ts
 * import { describe, beforeEach } from 'mocha'
 * import { engage, Cast } from '@serenity-js/core';
 * import { BrowseTheWebWithPlaywright } from '@serenity-js/playwright'
 * import { Browser, chromium } from 'playwright'
 *
 * describe('My UI feature', () => {
 *   beforeEach(async () => {
 *     const browser = await chromium.launch({ headless: true })
 *     engage(Cast.where(actor => actor.whoCan(BrowseTheWebWithPlaywright.using(browser))))
 *   })
 *
 *   // test scenarios
 * })
 * ```
 *
 * ## Writing custom casts for complex scenarios
 *
 * In **complex scenarios** that involve multiple **actors with different abilities**,
 * you should create a custom implementation of the [cast](https://serenity-js.org/api/core/class/Cast/).
 *
 * Examples of such scenarios include those where actors use separate browser instances, interact with different REST APIs,
 * or start with different data in their [notepads](https://serenity-js.org/api/core/class/Notepad/).
 *
 * ### Defining a custom cast of actors interacting with a Web UI
 *
 * ```ts
 * import { beforeEach } from 'mocha'
 * import { engage, Actor, Cast } from '@serenity-js/core'
 * import { BrowseTheWebWithPlaywright, PlaywrightOptions } from '@serenity-js/playwright'
 * import { Browser, chromium } from 'playwright'
 *
 * export class UIActors implements Cast {
 *   constructor(
 *     private readonly browser: Browser,
 *     private readonly options?: PlaywrightOptions,
 *   ) {
 *   }
 *
 *   prepare(actor: Actor): Actor {
 *     return actor.whoCan(
 *       BrowseTheWebWithPlaywright.using(this.browser, this.options),
 *     )
 *   }
 * }
 *
 * beforeEach(async () => {
 *   const browser = await chromium.launch({ headless: true })
 *   engage(new UIActors(browser));
 * });
 * ```
 *
 * ### Preparing actors differently based on their name
 *
 * ```ts
 * import { beforeEach } from 'mocha'
 * import { actorCalled, engage, Cast } from '@serenity-js/core'
 * import { BrowseTheWebWithPlaywright } from '@serenity-js/playwright'
 * import { CallAnApi } from '@serenity-js/rest'
 * import { Browser, chromium } from 'playwright'
 *
 * class Actors implements Cast {
 *   constructor(
 *     private readonly browser: Browser,
 *     private readonly options: PlaywrightOptions,
 *   ) {
 *   }
 *
 *   prepare(actor: Actor) {
 *     switch (actor.name) {
 *       case 'James':
 *         return actor.whoCan(BrowseTheWebWithPlaywright.using(this.browser, this.options));
 *       default:
 *         return actor.whoCan(CallAnApi.at(this.options.baseURL));
 *     }
 *   }
 * }
 *
 * beforeEach(async () => {
 *   const browser = await chromium.launch({ headless: true })
 *   engage(new Actors(browser, { baseURL: 'https://example.org' }));
 * });
 *
 * actorCalled('James') // returns an actor using a browser
 * actorCalled('Alice') // returns an actor interacting with an API
 * ```
 *
 * @group Stage
 */
export abstract class Cast {

    /**
     * Creates a generic `Cast` implementation, where new actors receive the abilities
     * configured by the `prepareFunction`.
     *
     * @param prepareFunction
     */
    static where(prepareFunction: (actor: Actor) => Actor): Cast {
        return new class GenericCast extends Cast {
            prepare(actor: Actor): Actor {
                return prepareFunction(actor);
            }
        }
    }

    /**
     * Configures an [`Actor`](https://serenity-js.org/api/core/class/Actor/) instantiated when [`Stage.actor`](https://serenity-js.org/api/core/class/Stage/#actor) is invoked.
     *
     * @param actor
     *
     * #### Learn more
     * - [`engage`](https://serenity-js.org/api/core/function/engage/)
     */
    abstract prepare(actor: Actor): Actor;
}
