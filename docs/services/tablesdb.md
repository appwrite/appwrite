The TablesDB service allows you to create structured tables of rows, query and filter lists of rows, and manage an advanced set of read and write access permissions.

All data returned by the TablesDB service are represented as structured JSON objects.

The TablesDB service can contain multiple databases, each database can contain multiple tables. A table is a group of similarly structured rows. The accepted structure of rows is defined by [table columns](https://appwrite.io/docs/databases#columns). The table columns help you ensure all your user-submitted data is validated and stored according to the table structure.

Using Appwrite permissions architecture, you can assign read or write access to each table or row in your project for either a specific user, team, user role, or even grant it with public access (`any`). You can learn more about [how Appwrite handles permissions and access control](https://appwrite.io/docs/permissions).