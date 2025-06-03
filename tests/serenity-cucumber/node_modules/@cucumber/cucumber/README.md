<h1 align="center">
  <img src="./docs/images/logo.svg" alt="">
  <br>
  Cucumber
</h1>
<p align="center">
  <b>Automated tests in plain language, for Node.js</b>
</p>

[![#StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://vshymanskyy.github.io/StandWithUkraine)
[![npm](https://img.shields.io/npm/v/@cucumber/cucumber.svg)](https://www.npmjs.com/package/@cucumber/cucumber)
[![build](https://github.com/cucumber/cucumber-js/workflows/Build/badge.svg)](https://github.com/cucumber/cucumber-js/actions)
[![coverage](https://coveralls.io/repos/github/cucumber/cucumber-js/badge.svg?branch=master)](https://coveralls.io/github/cucumber/cucumber-js?branch=master)
[![backers](https://opencollective.com/cucumber/backers/badge.svg)](https://opencollective.com/cucumber)
[![sponsors](https://opencollective.com/cucumber/sponsors/badge.svg)](https://opencollective.com/cucumber)

[Cucumber](https://github.com/cucumber) is a tool for running automated tests written in plain language. Because they're
written in plain language, they can be read by anyone on your team. Because they can be
read by anyone, you can use them to help improve communication, collaboration and trust on
your team.

This is the JavaScript implementation of Cucumber. It runs on [maintained versions](https://github.com/nodejs/Release) of Node.js. You can [quickly try it via CodeSandbox](https://codesandbox.io/s/cucumber-js-demo-2p3vrl?file=/features/greeting.feature), or read on to get started locally in a couple of minutes.

Looking to contribute? Read our [code of conduct](https://github.com/cucumber/.github/blob/main/CODE_OF_CONDUCT.md) first, then check the [contributing guide](./CONTRIBUTING.md) to get up and running.

## Install

Cucumber is [available on npm](https://www.npmjs.com/package/@cucumber/cucumber):

```shell
$ npm install @cucumber/cucumber
```

## Get Started

Let's take this example of something to test:


First, write your main code in `src/index.js`:

```js
class Greeter {
  sayHello() {
    return 'hello'
  }
}

module.exports = {
  Greeter
}
```

Then, write your feature in `features/greeting.feature`:

```gherkin
Feature: Greeting

  Scenario: Say hello
    When the greeter says hello
    Then I should have heard "hello"
```

Next, implement your steps in `features/support/steps.js`:

```js
const assert = require('assert')
const { When, Then } = require('@cucumber/cucumber')
const { Greeter } = require('../../src')

When('the greeter says hello', function () {
  this.whatIHeard = new Greeter().sayHello()
});

Then('I should have heard {string}', function (expectedResponse) {
  assert.equal(this.whatIHeard, expectedResponse)
});
```

Finally, run Cucumber:

```shell
$ npx cucumber-js
```

And see the output:

![Terminal output showing a successful test run with 1 scenario and 2 steps, all passing](./docs/images/readme-output.png)

If you learn best by example, we have [a repo with several example projects](https://github.com/cucumber/cucumber-js-examples), that might help you get going.

## Documentation

The following documentation is for `main`, which might contain some unreleased features. See [documentation for older versions](./docs/older_versions.md) if you need it.

* [Installation](./docs/installation.md)
* [CLI](./docs/cli.md)
* [Configuration](./docs/configuration.md)
* Support Code
  * [API Reference](./docs/support_files/api_reference.md)
  * [Attachments](./docs/support_files/attachments.md)
  * [Data Tables](./docs/support_files/data_table_interface.md)
  * [Hooks](./docs/support_files/hooks.md)
  * [Step Definitions](./docs/support_files/step_definitions.md)
  * [Timeouts](./docs/support_files/timeouts.md)
  * [World](./docs/support_files/world.md)
* Guides
  * [Debugging](./docs/debugging.md)
  * [Dry run](./docs/dry_run.md)
  * [ES Modules](./docs/esm.md)
  * [Failing fast](./docs/fail_fast.md)
  * [Filtering which scenarios run](./docs/filtering.md)
  * [Formatters for feedback and reporting](./docs/formatters.md)
  * [Parallel running for speed](./docs/parallel.md)
  * [Profiles for composable configuration](./docs/profiles.md)
  * [Rerunning just failures](./docs/rerun.md)
  * [Retrying flaky scenarios](./docs/retry.md)
  * [JavaScript API for running programmatically](./docs/javascript_api.md)
  * [Snippets for undefined steps](./docs/snippets.md)
  * [Transpiling (from TypeScript etc)](./docs/transpiling.md)
* [FAQ](./docs/faq.md)

## Support

Support is [available from the community](https://cucumber.io/tools/cucumber-open/support/) if you need it.
