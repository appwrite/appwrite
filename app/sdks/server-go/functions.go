package appwrite

import (
	"strings"
)

// Functions service
type Functions struct {
	client Client
}

func NewFunctions(clt Client) Functions {  
    service := Functions{
		client: clt,
	}

    return service
}

// List
func (srv *Functions) List(Search string, Limit int, Offset int, OrderType string) (map[string]interface{}, error) {
	path := "/functions"

	params := map[string]interface{}{
		"search": Search,
		"limit": Limit,
		"offset": Offset,
		"orderType": OrderType,
	}

	return srv.client.Call("GET", path, nil, params)
}

// Create
func (srv *Functions) Create(Name string, Vars object, Events []interface{}, Schedule string, Timeout int) (map[string]interface{}, error) {
	path := "/functions"

	params := map[string]interface{}{
		"name": Name,
		"vars": Vars,
		"events": Events,
		"schedule": Schedule,
		"timeout": Timeout,
	}

	return srv.client.Call("POST", path, nil, params)
}

// Get
func (srv *Functions) Get(FunctionId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{functionId}", FunctionId)
	path := r.Replace("/functions/{functionId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// Update
func (srv *Functions) Update(FunctionId string, Name string, Vars object, Events []interface{}, Schedule string, Timeout int) (map[string]interface{}, error) {
	r := strings.NewReplacer("{functionId}", FunctionId)
	path := r.Replace("/functions/{functionId}")

	params := map[string]interface{}{
		"name": Name,
		"vars": Vars,
		"events": Events,
		"schedule": Schedule,
		"timeout": Timeout,
	}

	return srv.client.Call("PUT", path, nil, params)
}

// Delete
func (srv *Functions) Delete(FunctionId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{functionId}", FunctionId)
	path := r.Replace("/functions/{functionId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}

// ListExecutions
func (srv *Functions) ListExecutions(FunctionId string, Search string, Limit int, Offset int, OrderType string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{functionId}", FunctionId)
	path := r.Replace("/functions/{functionId}/executions")

	params := map[string]interface{}{
		"search": Search,
		"limit": Limit,
		"offset": Offset,
		"orderType": OrderType,
	}

	return srv.client.Call("GET", path, nil, params)
}

// CreateExecution
func (srv *Functions) CreateExecution(FunctionId string, Async int) (map[string]interface{}, error) {
	r := strings.NewReplacer("{functionId}", FunctionId)
	path := r.Replace("/functions/{functionId}/executions")

	params := map[string]interface{}{
		"async": Async,
	}

	return srv.client.Call("POST", path, nil, params)
}

// GetExecution
func (srv *Functions) GetExecution(FunctionId string, ExecutionId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{functionId}", FunctionId, "{executionId}", ExecutionId)
	path := r.Replace("/functions/{functionId}/executions/{executionId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// UpdateTag
func (srv *Functions) UpdateTag(FunctionId string, Tag string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{functionId}", FunctionId)
	path := r.Replace("/functions/{functionId}/tag")

	params := map[string]interface{}{
		"tag": Tag,
	}

	return srv.client.Call("PATCH", path, nil, params)
}

// ListTags
func (srv *Functions) ListTags(FunctionId string, Search string, Limit int, Offset int, OrderType string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{functionId}", FunctionId)
	path := r.Replace("/functions/{functionId}/tags")

	params := map[string]interface{}{
		"search": Search,
		"limit": Limit,
		"offset": Offset,
		"orderType": OrderType,
	}

	return srv.client.Call("GET", path, nil, params)
}

// CreateTag
func (srv *Functions) CreateTag(FunctionId string, Env string, Command string, Code string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{functionId}", FunctionId)
	path := r.Replace("/functions/{functionId}/tags")

	params := map[string]interface{}{
		"env": Env,
		"command": Command,
		"code": Code,
	}

	return srv.client.Call("POST", path, nil, params)
}

// GetTag
func (srv *Functions) GetTag(FunctionId string, TagId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{functionId}", FunctionId, "{tagId}", TagId)
	path := r.Replace("/functions/{functionId}/tags/{tagId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// DeleteTag
func (srv *Functions) DeleteTag(FunctionId string, TagId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{functionId}", FunctionId, "{tagId}", TagId)
	path := r.Replace("/functions/{functionId}/tags/{tagId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}
