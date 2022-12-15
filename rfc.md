# Query Databse Timeouts

* Creator: [Shmuel, Jake]
* Relevant Branch:  https://github.com/utopia-php/database/pull/220

## Summary
We want to give users who use Appwrite queries api, to have flexible options for making any query they desire with no limitations, today we restrict the query to have a specific indexes for queries.
The problem begin with collections with a relative big amount of data inside, which can make queries with no indexes or `bad` queries in sense of sql with operators as `not in` or `<>`

## Resources
Google, Mysql workbench tool, Mongosh.

## Implementation
We will limit specific `select` type queries executions or set by session a timeout for all select queries in during the connection session for a specific timeout in milliseconds.
In case the select operation exceeds time limit, an Exception will be thrown with an error code value per adapter (mysql, mongo..) that we will catch.
We will audit the errors with data 
<!-- Write an overview to explain the suggested implementation -->

### API Changes
<!-- Do we need new API endpoints? List and describe them and their API signatures -->

###  Workers / Commands
<!-- Do we need new workers or commands for this feature? List and describe them and their API signatures -->

###  Supporting Libraries
<!-- Do we need new libraries for this feature? Mention which, define the file structure, and different interfaces -->

### Data Structures
<!-- Do we need new data structures for this feature? Describe and explain the new collections and attributes -->

### Breaking Changes
<!-- Will this feature introduce any breaking changes? How can we achieve backward compatability -->

### Documentation & Content
<!-- What documentation do we need to update or add for this feature? -->

## Reliability

### Security
<!-- How will we secure this feature? -->

### Scaling
<!-- How will we scale this feature? -->

### Benchmark
<!-- How will we benchmark this feature? -->

### Tests (UI, Unit, E2E)
<!-- How will we test this feature? -->