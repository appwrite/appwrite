The Database service allows you to create structured collections of documents, query and filter lists of documents, and manage an advanced set of read and write access permissions.

All the data in the database service is stored in structured JSON documents. The Appwrite database service also allows you to nest child documents in parent documents and use deep filters to both search and query your data.

Each database document structure in your project is defined using the Appwrite [collection attributes](/docs/database#attributes). The collection attributes help you ensure all your user-submitted data is validated and stored according to the collection structure.

Using Appwrite permissions architecture, you can assign read or write access to each collection or document in your project for either a specific user, team, user role, or even grant it with public access (`role:all`). You can learn more about [how Appwrite handles permissions and access control](/docs/permissions).
