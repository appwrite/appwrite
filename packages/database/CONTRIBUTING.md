# Contributing

We would ❤️ for you to contribute to Utopia-php and help make it better! We want contributing to Utopia-php to be fun, enjoyable, and educational for anyone and everyone. All contributions are welcome, including issues, new docs as well as updates and tweaks, blog posts, workshops, and more.

## How to Start?

If you are worried or don’t know where to start, check out our next section explaining what kind of help we could use and where can you get involved. You can reach out with questions to [Eldad Fux (@eldadfux)](https://twitter.com/eldadfux) or anyone from the [Appwrite team on Discord](https://discord.gg/GSeTUeA). You can also submit an issue, and a maintainer can guide you!

## Code of Conduct

Help us keep the community open and inclusive. Please read and follow our [Code of Conduct](https://github.com/appwrite/appwrite/blob/master/CODE_OF_CONDUCT.md).

## Submit a Pull Request 🚀

Branch naming convention is as following 

`TYPE-ISSUE_ID-DESCRIPTION`

example:
```
doc-548-submit-a-pull-request-section-to-contribution-guide
```

When `TYPE` can be:

- **feat** - is a new feature
- **doc** - documentation only changes
- **cicd** - changes related to CI/CD system
- **fix** - a bug fix
- **refactor** - code change that neither fixes a bug nor adds a feature

**All PRs must include a commit message with the changes description!** 

For the initial start, fork the project and use the git clone command to download the repository to your computer. A standard procedure for working on an issue would be to:

1. `git pull`, before creating a new branch, pull the changes from upstream. Your main needs to be up to date.
```
$ git pull
```
2. Create new branch from `main` like: `doc-548-submit-a-pull-request-section-to-contribution-guide`<br/>
```
$ git checkout -b [name_of_your_new_branch]
```
3. Work - commit - repeat ( be sure to be in your branch )

4. Push changes to GitHub 
```
$ git push origin [name_of_your_new_branch]
```

5. Submit your changes for review. If you go to your repository on GitHub, you'll see a `Compare & pull request` button. Click on that button.
6. Start a Pull Request
Now submit the pull request and click on `Create pull request`.
7. Get a code review approval/reject
8. After approval, merge your PR
9. GitHub will automatically delete the branch after the merge is done. (they can still be restored).

## Introducing New Features

We would 💖 you to contribute to Utopia-php, but we would also like to make sure Utopia-php is as great as possible and loyal to its vision and mission statement 🙏.

For us to find the right balance, please open an issue explaining your ideas before introducing a new pull request.

This will allow the Utopia-php community to have sufficient discussion about the new feature value and how it fits in the product roadmap and vision.

This is also important for the Utopia-php lead developers to be able to give technical input and different emphasis regarding the feature design and architecture. Some bigger features might need to go through our [RFC process](https://github.com/appwrite/rfc).

## Adding A New Adapter

You can follow our [Adding new Database Adapter](docs/add-new-adapter.md) tutorial to add new database support in this library.

## Tests

To run tests, you first need to bring up the example Docker stack with the following command:

```bash
docker compose up -d --build
```

To run all unit tests, use the following Docker command:

```bash
docker compose exec tests vendor/bin/phpunit --configuration phpunit.xml tests
```

To run tests for a single file, use the following Docker command structure:

```bash
docker compose exec tests vendor/bin/phpunit --configuration phpunit.xml tests/Database/[FILE_PATH]
```

To run static code analysis, use the following phpstan command:

```bash
composer check
```

### Load testing

Three commands have been added to `bin/` to fill, index, and query the DB to test changes:

- `bin/load` invokes `bin/tasks/load.php`
- `bin/index` invokes `bin/tasks/index.php`
- `bin/query` invokes `bin/tasks/query.php`

To test your DB changes under load:

#### Load the database

```bash
docker compose exec tests bin/load --adapter=[adapter] --limit=[limit] [--name=[name]]

# [adapter]: either 'mongodb' or 'mariadb', no quotes
# [limit]: integer of total documents to generate
# [name]: (optional) name for new database
```

#### Create indexes

```bash
docker compose exec tests bin/index --adapter=[adapter] --name=[name]

# [adapter]: either 'mongodb' or 'mariadb', no quotes
# [name]: name of filled database by bin/load
```

#### Run Query Suite

```bash
docker compose exec tests bin/query --adapter=[adapter] --limit=[limit] --name=[name]

# [adapter]: either 'mongodb' or 'mariadb', no quotes
# [limit]: integer of query limit (default 25)
# [name]: name of filled database by bin/load
```

#### Visualize Query Results

```bash
docker compose exec tests bin/compare
```

Navigate to `localhost:8708` to visualize query results.


## Other Ways to Help

Pull requests are great, but there are many other areas where you can help Utopia-php.

### Blogging & Speaking

Blogging, speaking about, or creating tutorials about one of Utopia-php’s many features is great way to contribute and help our project grow.

### Presenting at Meetups

Presenting at meetups and conferences about your Utopia-php projects. Your unique challenges and successes in building things with Utopia-php can provide great speaking material. We’d love to review your talk abstract/CFP, so get in touch with us if you’d like some help!

### Sending Feedbacks & Reporting Bugs

Sending feedback is a great way for us to understand your different use cases of Utopia-php better. If you had any issues, bugs, or want to share about your experience, feel free to do so on our GitHub issues page or at our [Discord channel](https://discord.gg/GSeTUeA).

### Submitting New Ideas

If you think Utopia-php could use a new feature, please open an issue on our GitHub repository, stating as much information as you can think about your new idea and it's implications. We would also use this issue to gather more information, get more feedback from the community, and have a proper discussion about the new feature.

### Improving Documentation

Submitting documentation updates, enhancements, designs, or bug fixes. Spelling or grammar fixes will be very much appreciated.

### Helping Someone

Searching for Utopia-php, GitHub or StackOverflow and helping someone else who needs help. You can also help by teaching others how to contribute to Utopia-php's repo!
