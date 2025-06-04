/**
 * Configuration options to be passed to [Cucumber CLI](https://github.com/cucumber/cucumber-js/blob/master/docs/cli.md).
 * You can specify the options using either camelCase (i.e. `retryTagFilter`) or kebab-case (i.e. `retry-tag-filter`)
 * as Serenity/JS will convert them to an appropriate format for you.
 *
 * @group Configuration
 */
export interface CucumberConfig {

    /**
     * Paths to where your feature files are. Note that you don't need to specify the paths when
     * using Serenity/JS with WebdriverIO or Protractor, as their respective adapters will do it for you.
     *
     * #### Learn more
     * - [Cucumber docs: configuration](https://github.com/cucumber/cucumber-js/blob/main/docs/configuration.md)
     */
    paths?: string[];

    /**
     * Prepare a test run but don't run it
     *
     * #### Learn more
     * - [Cucumber docs: configuration](https://github.com/cucumber/cucumber-js/blob/main/docs/configuration.md)
     * - [Cucumber docs: dry run mode](https://github.com/cucumber/cucumber-js/blob/main/docs/dry_run.md)
     *
     * @version 8.x
     */
    dryRun?: boolean;

    /**
     * Stop running tests when a test fails
     *
     * #### Learn more
     * - [Cucumber docs: configuration](https://github.com/cucumber/cucumber-js/blob/main/docs/configuration.md)
     * - [Cucumber docs: fail fast](https://github.com/cucumber/cucumber-js/blob/main/docs/fail_fast.md)
     *
     * @version 8.x
     */
    failFast?: boolean;

    /**
     * Enable/disable colors in output. Cucumber 1.x only!
     * For Cucumber 2.x and above use `formatOptions: { colorsEnabled: false }`
     *
     * **Note** For Cucumber 2.x and above use the [`CucumberConfig.formatOptions`](https://serenity-js.org/api/cucumber-adapter/interface/CucumberConfig/#formatOptions) instead.
     *
     * #### Disable colors in output in Cucumber 1.x
     *
     * ```ts
     * colors: false
     * ```
     *
     * #### Disable colors in output in Cucumber 2.x and above
     *
     *  ```ts
     *  formatOptions: { colorsEnabled: false }
     *  ```
     *
     * #### Learn more
     * - [Cucumber 1.x CLI options](https://github.com/cucumber/cucumber-js/blob/1.x/lib/cucumber/cli.js#L38)
     *
     * @version 1.x
     */
    colors?: boolean

    /**
     * Step definitions and support files can be written in languages that transpile to JavaScript.
     * To do set the `compiler` option to `<file_extension>:<module_name>`
     *
     * **NoteL** For Cucumber 4.x and above use the [`CucumberConfig.require`](https://serenity-js.org/api/cucumber-adapter/interface/CucumberConfig/#require) option instead.
     *
     * #### Enable TypeScript support in Cucumber 1.x - 3.x
     * ```ts
     * compiler: 'ts:ts-node/register'
     * ```
     *
     * #### Learn more
     * - [Cucumber 3.x documentation](https://github.com/cucumber/cucumber-js/blob/3.x/docs/cli.md#transpilers)
     *
     * @version 1.x - 3.x
     */
    compiler?: string;

    /**
     * Specify additional output formats, optionally supply PATH to redirect formatter output
     *
     * #### Learn more
     * - [Cucumber output formats](https://github.com/cucumber/cucumber-js/blob/master/docs/cli.md#formats)
     */
    format?: string[] | string;

    /**
     * Provide options for formatters
     *
     * #### Cucumber 1.x
     * ```ts
     * formatOptions: JSON.stringify({ option: 'value' })
     * ```
     *
     * #### Learn more
     * - [Cucumber format options](https://github.com/cucumber/cucumber-js/blob/master/docs/cli.md#format-options)
     */
    formatOptions?: object | string;    // eslint-disable-line @typescript-eslint/ban-types

    /**
     * Only execute the scenarios with name matching the expression.
     *
     * #### Learn more
     * - [Cucumber docs: running specific features](https://github.com/cucumber/cucumber-js/blob/master/docs/cli.md#running-specific-features)
     */
    name?: string[];

    /**
     * In order to store and reuse commonly used CLI options,
     * you can add a `cucumber.js` file to your project root directory.
     * The file should export an object where the key is the profile name
     * and the value is a string of CLI options.
     *
     * The profile can be applied with `-p <NAME>` or `--profile <NAME>`.
     * This will prepend the profile's CLI options to the ones provided by the command line.
     * Multiple profiles can be specified at a time.
     *
     * If no profile is specified and a profile named default exists,
     * it will be applied.
     *
     * #### Learn more
     * - [Cucumber profiles](https://github.com/cucumber/cucumber-js/blob/master/docs/cli.md#profiles)
     */
    profile?: string[];

    /**
     * The number of times to retry a failing scenario before marking it as failed.
     *
     * #### Cucumber 7.x
     *
     * ```ts
     * retry: 3
     * ```
     *
     * #### Learn more
     * - [Cucumber docs: retry failing tests](https://github.com/cucumber/cucumber-js/blob/master/docs/cli.md#retry-failing-tests)
     *
     * @version 7.x
     */
    retry?: number;

    /**
     * Relative path to an output file produced by Cucumber.js [`rerun` formatter](https://github.com/cucumber/cucumber-js/blob/master/features/rerun_formatter.feature).
     *
     * **Note:** that the name of the output file *must* start with an `@` symbol.
     *
     * #### Saving details of failed scenarios to `@rerun-output.txt`
     *
     * ```ts
     * format: [ 'rerun:@rerun-output.txt' ]
     * ```
     *
     * #### Re-running scenarios saved to `@rerun-output.txt`
     * ```ts
     * rerun: '@rerun-output.txt'
     * ```
     */
    rerun?: string;

    /**
     * Only retry tests matching the given [tag expression](https://github.com/cucumber/cucumber/tree/master/tag-expressions).
     *
     * #### Cucumber 7.x
     * ```ts
     * retry: 3,
     * retryTagFilter: '@flaky',
     * ```
     *
     * #### Learn more
     * - [Cucumber docs: retry failing tests](https://github.com/cucumber/cucumber-js/blob/master/docs/cli.md#retry-failing-tests)
     *
     * @version 7.x
     */
    retryTagFilter?: string

    /**
     * Require files or node modules before executing features
     *
     * #### Enable TypeScript support in Cucumber 4.x and above
     * ```ts
     * require: 'ts:ts-node/register'
     * ```
     * #### Learn more
     * - [Cucumber docs: requiring support files](https://github.com/cucumber/cucumber-js/blob/master/docs/cli.md#requiring-support-files)
     */
    require?: string[];

    /**
     * Paths to where your support code is.
     *
     * #### Learn more
     * - [Cucumber docs: configuration](https://github.com/cucumber/cucumber-js/blob/main/docs/configuration.md)
     *
     * @version 8.x
     */
    import?: string[];

    /**
     * Names of transpilation modules to load, loaded via require()
     *
     * #### Learn more
     * - [Cucumber docs: transpiling](https://github.com/cucumber/cucumber-js/blob/main/docs/transpiling.md)
     *
     * @version 8.x
     */
    requireModule?: string[],

    /**
     * Default language for your feature files
     *
     * #### Learn more
     * - [Cucumber docs: configuration](https://github.com/cucumber/cucumber-js/blob/main/docs/configuration.md)
     *
     * @version 8.x
     */
    language?: string;

    /**
     * Only run scenarios that match the given tags.
     *
     * **Note**: Cucumber 1.x requires the `tags` option to be an array of Cucumber tags,
     * while Cucumber 2.x and above uses a `string`
     * with a [tag expression](https://github.com/cucumber/cucumber/tree/master/tag-expressions).
     *
     * #### Cucumber 1.x
     * ```ts
     * // Run all scenarios tagged with `@smoketest`, but not with `@wip`:
     * tag: [ '@smoketest', '~@wip' ]
     * ```
     *
     * #### Cucumber >= 2.x
     * ```ts
     * // Run all scenarios tagged with `@smoketest`, but not with `@wip`:
     * tag: '@smoketest and not @wip'
     * ```
     *
     * #### Learn more
     *
     * - [Cucumber 1.x docs: tags](https://github.com/cucumber/cucumber-js/blob/1.x/docs/cli.md#tags)
     * - [Cucumber 2.x docs: tags](https://github.com/cucumber/cucumber-js/blob/2.x/docs/cli.md#tags)
     * - [Cucumber docs: tag expressions](https://github.com/cucumber/cucumber/tree/master/tag-expressions)
     */
    tags?: string[] | string;

    /**
     * Fail if there are any undefined or pending steps
     */
    strict?: boolean;

    /**
     * Provide parameters that will be passed to the world constructor
     *
     * #### Specifying `worldParameters` as `string`
     * ```ts
     * worldParameters: JSON.stringify({ isDev: process.env.NODE_ENV !== 'production' })
     * ```
     *
     * #### Specifying `worldParameters` as `object`
     * ```ts
     * worldParameters: { isDev: process.env.NODE_ENV !== 'production' }
     * ```
     *
     * #### Learn more
     *
     * - [Cucumber docs: world parameters](https://github.com/cucumber/cucumber-js/blob/master/docs/cli.md#world-parameters)
     */
    worldParameters?: object | string;  // eslint-disable-line @typescript-eslint/ban-types
}
