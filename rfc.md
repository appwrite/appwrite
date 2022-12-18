# Query Database Timeouts

* Creator: [Shmuel, Jake]
* Relevant Branch:  https://github.com/utopia-php/database/pull/220

## Summary
We want to add for Appwrite's users queries api flexible options for making any query they desire with no limitations, today we restrict the query to have a specific indexes for queries.
The problem begin with collections with a relative big amount of data inside, which can make queries with no indexes or `bad` queries in sense of sql with operators as `not in` or `<>`

## Resources
Google, Mysql workbench tool, Mongosh.

## Implementation
We will limit specific `select` type queries executions or set by session a timeout for all select queries during the connection session for a specific timeout in milliseconds.
In case the select operation exceeds time limit, an Exception will be thrown with an error code value per adapter (mysql, mongo..etc) that we will catch.
We will audit the error throw with data from the api , such as the queries variable, user making the query, host.
After a number of times wee will block this call.
We will have to show on the console this lists of audit , so the user can try to fix the queries , or perhaps we can add a recommendation of adding a specific index.
<!-- Write an overview to explain the suggested implementation -->

### API Changes
If case we want to limit time execution for a whole api call we can set by session the execution time limit for all select queries in that connection thread.
<!-- Do we need new API endpoints? List and describe them and their API signatures -->

###  Workers / Commands
Perhaps a worker sending the index he has a problem on a specific end point?

<!-- Do we need new workers or commands for this feature? List and describe them and their API signatures -->

###  Supporting Libraries
<!-- Do we need new libraries for this feature? Mention which, define the file structure, and different interfaces -->

### Data Structures
No change
<!-- Do we need new data structures for this feature? Describe and explain the new collections and attributes -->

### Breaking Changes
In case we use the injection sql timeouts a change to sql syntax will be changed.
<!-- Will this feature introduce any breaking changes? How can we achieve backward compatability -->

### Documentation & Content
I guess some relevant docs how to index queries better.
<!-- What documentation do we need to update or add for this feature? -->

## Reliability

### Security
All changes are internal , so no major security changes here.
<!-- How will we secure this feature? -->

### Scaling
Not relevant
<!-- How will we scale this feature? -->

### Benchmark
<!-- How will we benchmark this feature? -->

### Tests (UI, Unit, E2E)
We need to add test how to be able to mock the data better, 
currently mocks are hardcoded. except mongo db where I was able to add sleep condition in queries.
<!-- How will we test this feature? -->