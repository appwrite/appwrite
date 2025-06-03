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

## Serenity/JS Cucumber

[`@serenity-js/cucumber`](https://serenity-js.org/api/cucumber/) contains a set of adapters you register with [Cucumber CLI runners](https://github.com/cucumber/cucumber-js/) to enable integration and reporting between Cucumber.js and Serenity/JS.

**Please note:** To use Cucumber and Serenity/JS to execute web-based acceptance tests, you should register Serenity/JS Cucumber adapter using Protractor configuration file. 

Learn more about integrating Serenity/JS Cucumber:
- with [Protractor and Cucumber.js](https://serenity-js.org/handbook/test-runners/protractor/),
- with [Cucumber.js](https://serenity-js.org/handbook/test-runners/cucumber/).

### Installation

To install this module, run:
```
npm install --save-dev @serenity-js/cucumber @serenity-js/core
```

This module reports test scenarios executed by **any version of Cucumber.js**, from 0.x to 9.x, which you need to install
separately.

To install [Cucumber 9.x](https://www.npmjs.com/package/@cucumber/cucumber), run:
```
npm install --save-dev @cucumber/cucumber 
```

To install [Cucumber 6.x](https://www.npmjs.com/package/cucumber) or earlier, run:
```
npm install --save-dev cucumber 
```


### Command line usage

#### Cucumber 7.x and newer

```
cucumber-js --format @serenity-js/cucumber \
    --require ./features/support/setup.js \
    --require ./features/step_definitions/sample.steps.js 
```

```
'--format-options', `{ "specDirectory": "${ path.resolve(__dirname, '../../cucumber-specs/features') }" }`,
```

#### Cucumber 3.x to 6.x

```
cucumber-js --format node_modules/@serenity-js/cucumber \
    --require ./features/support/setup.js \
    --require ./features/step_definitions/sample.steps.js 
```

#### Cucumber 0.x to 2.x

```
cucumber-js --require=node_modules/@serenity-js/cucumber/lib/index.js \
    --require ./features/support/setup.js \
    --require ./features/step_definitions/sample.steps.js 
```

### Configuration

When used with a configuration file written in JavaScript:

```javascript
// features/support/setup.js

const { configure } = require('@serenity-js/core')

configure({
    // ... configure Serenity/JS 
})
```

When used with a configuration file written in TypeScript:

```typescript
// features/support/setup.ts

import { configure } from '@serenity-js/core'

configure({
    // ... configure Serenity/JS 
})
```

### Integration

This module can be integrated with:
- [`@serenity-js/serenity-bdd`](https://serenity-js.org/api/serenity-bdd) to produce HTML reports and living documentation,
- [`@serenity-js/console-reporter`](https://serenity-js.org/api/console-reporter) to print test execution reports to your computer terminal,
- [`@serenity-js/protractor`](https://serenity-js.org/api/protractor) to implement Cucumber scenarios interacting with Web applications.

Learn more about [Serenity/JS modular architecture](https://serenity-js.org/handbook/about/architecture).

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
