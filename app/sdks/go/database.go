package appwrite

import (
	"strings"
)

// Database service
type Database struct {
	client Client
}

func NewDatabase(clt Client) Database {  
    service := Database{
		client: clt,
	}

    return service
}

// ListCollections get a list of all the user collections. You can use the
// query params to filter your results. On admin mode, this endpoint will
// return a list of all of the project collections. [Learn more about
// different API modes](/docs/admin).
func (srv *Database) ListCollections(Search string, Limit int, Offset int, OrderType string) (map[string]interface{}, error) {
	path := "/database"

	params := map[string]interface{}{
		"search": Search,
		"limit": Limit,
		"offset": Offset,
		"orderType": OrderType,
	}

	return srv.client.Call("GET", path, nil, params)
}

// CreateCollection create a new Collection.
func (srv *Database) CreateCollection(Name string, Read []interface{}, Write []interface{}, Rules []interface{}) (map[string]interface{}, error) {
	path := "/database"

	params := map[string]interface{}{
		"name": Name,
		"read": Read,
		"write": Write,
		"rules": Rules,
	}

	return srv.client.Call("POST", path, nil, params)
}

// GetCollection get collection by its unique ID. This endpoint response
// returns a JSON object with the collection metadata.
func (srv *Database) GetCollection(CollectionId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{collectionId}", CollectionId)
	path := r.Replace("/database/{collectionId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// UpdateCollection update collection by its unique ID.
func (srv *Database) UpdateCollection(CollectionId string, Name string, Read []interface{}, Write []interface{}, Rules []interface{}) (map[string]interface{}, error) {
	r := strings.NewReplacer("{collectionId}", CollectionId)
	path := r.Replace("/database/{collectionId}")

	params := map[string]interface{}{
		"name": Name,
		"read": Read,
		"write": Write,
		"rules": Rules,
	}

	return srv.client.Call("PUT", path, nil, params)
}

// DeleteCollection delete a collection by its unique ID. Only users with
// write permissions have access to delete this resource.
func (srv *Database) DeleteCollection(CollectionId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{collectionId}", CollectionId)
	path := r.Replace("/database/{collectionId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}

// ListDocuments get a list of all the user documents. You can use the query
// params to filter your results. On admin mode, this endpoint will return a
// list of all of the project documents. [Learn more about different API
// modes](/docs/admin).
func (srv *Database) ListDocuments(CollectionId string, Filters []interface{}, Offset int, Limit int, OrderField string, OrderType string, OrderCast string, Search string, First int, Last int) (map[string]interface{}, error) {
	r := strings.NewReplacer("{collectionId}", CollectionId)
	path := r.Replace("/database/{collectionId}/documents")

	params := map[string]interface{}{
		"filters": Filters,
		"offset": Offset,
		"limit": Limit,
		"order-field": OrderField,
		"order-type": OrderType,
		"order-cast": OrderCast,
		"search": Search,
		"first": First,
		"last": Last,
	}

	return srv.client.Call("GET", path, nil, params)
}

// CreateDocument create a new Document.
func (srv *Database) CreateDocument(CollectionId string, Data string, Read []interface{}, Write []interface{}, ParentDocument string, ParentProperty string, ParentPropertyType string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{collectionId}", CollectionId)
	path := r.Replace("/database/{collectionId}/documents")

	params := map[string]interface{}{
		"data": Data,
		"read": Read,
		"write": Write,
		"parentDocument": ParentDocument,
		"parentProperty": ParentProperty,
		"parentPropertyType": ParentPropertyType,
	}

	return srv.client.Call("POST", path, nil, params)
}

// GetDocument get document by its unique ID. This endpoint response returns a
// JSON object with the document data.
func (srv *Database) GetDocument(CollectionId string, DocumentId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{collectionId}", CollectionId, "{documentId}", DocumentId)
	path := r.Replace("/database/{collectionId}/documents/{documentId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// UpdateDocument
func (srv *Database) UpdateDocument(CollectionId string, DocumentId string, Data string, Read []interface{}, Write []interface{}) (map[string]interface{}, error) {
	r := strings.NewReplacer("{collectionId}", CollectionId, "{documentId}", DocumentId)
	path := r.Replace("/database/{collectionId}/documents/{documentId}")

	params := map[string]interface{}{
		"data": Data,
		"read": Read,
		"write": Write,
	}

	return srv.client.Call("PATCH", path, nil, params)
}

// DeleteDocument delete document by its unique ID. This endpoint deletes only
// the parent documents, his attributes and relations to other documents.
// Child documents **will not** be deleted.
func (srv *Database) DeleteDocument(CollectionId string, DocumentId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{collectionId}", CollectionId, "{documentId}", DocumentId)
	path := r.Replace("/database/{collectionId}/documents/{documentId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}
