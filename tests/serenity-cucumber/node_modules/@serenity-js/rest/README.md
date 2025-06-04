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

## Serenity/JS REST

[`@serenity-js/rest`](https://serenity-js.org/api/rest/) module lets your actors interact with and test HTTP REST APIs.

### Installation

To install this module, as well as [`axios` HTTP client](https://github.com/axios/axios),
run the following command in your computer terminal:

```sh
npm install --save-dev @serenity-js/core @serenity-js/rest @serenity-js/assertions axios
```


### Example test

```typescript
import { actorCalled } from '@serenity-js/core'
import { CallAnApi, DeleteRequest, GetRequest, LastResponse, PostRequest, Send } from '@serenity-js/rest'
import { Ensure, equals, startsWith } from '@serenity-js/assertions'

const actor = actorCalled('Apisit').whoCan(CallAnApi.at('https://myapp.com/api'))

await actor.attemptsTo(
    // no users present in the system
    Send.a(GetRequest.to('/users')),
    Ensure.that(LastResponse.status(), equals(200)),
    Ensure.that(LastResponse.body(), equals([])),

    // create a new test user account
    Send.a(PostRequest.to('/users').with({
        login: 'tester',
        password: 'P@ssword1',
    }),
    Ensure.that(LastResponse.status(), equals(201)),
    Ensure.that(LastResponse.header('Location'), startsWith('/users')),

    // delete the test user account
    Send.a(DeleteRequest.to(LastResponse.header('Location'))),
    Ensure.that(LastResponse.status(), equals(200)),
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
