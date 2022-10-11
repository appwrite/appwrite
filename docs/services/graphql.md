The GraphQL services allows you to manipulate your Appwrite instance through a single endpoint using GraphQL queries and mutations, asking for exactly what you need and nothing more. You can perform any action available in the REST API, as well as directly manipulate your custom collections.

> ## GraphQL API vs REST API
> 
> The major difference comes from the way the data is returned. GraphQL returns the data in a structured format, giving you only the nodes you ask for, while REST returns the data in a flat format.
> 
> GraphQL has a single endpoint for all queries and mutations except file uploads; the REST API has multiple endpoints for each type of action.
> 
> Both APIs are fully compatible with each other, and you can use them together in the same project.
> 
> Both GraphQL and REST have pros and cons. For example, GraphQL requests are very flexible and can be more efficient. However, REST has better support for caching, error handling, and versioning.