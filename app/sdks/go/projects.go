package appwrite

import (
	"strings"
)

// Projects service
type Projects struct {
	client *Client
}

// ListProjects
func (srv *Projects) ListProjects() (map[string]interface{}, error) {
	path := "/projects"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// CreateProject
func (srv *Projects) CreateProject(Name string, TeamId string, Description string, Logo string, Url string, LegalName string, LegalCountry string, LegalState string, LegalCity string, LegalAddress string, LegalTaxId string) (map[string]interface{}, error) {
	path := "/projects"

	params := map[string]interface{}{
		"name": Name,
		"teamId": TeamId,
		"description": Description,
		"logo": Logo,
		"url": Url,
		"legalName": LegalName,
		"legalCountry": LegalCountry,
		"legalState": LegalState,
		"legalCity": LegalCity,
		"legalAddress": LegalAddress,
		"legalTaxId": LegalTaxId,
	}

	return srv.client.Call("POST", path, nil, params)
}

// GetProject
func (srv *Projects) GetProject(ProjectId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId)
	path := r.Replace("/projects/{projectId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// UpdateProject
func (srv *Projects) UpdateProject(ProjectId string, Name string, Description string, Logo string, Url string, LegalName string, LegalCountry string, LegalState string, LegalCity string, LegalAddress string, LegalTaxId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId)
	path := r.Replace("/projects/{projectId}")

	params := map[string]interface{}{
		"name": Name,
		"description": Description,
		"logo": Logo,
		"url": Url,
		"legalName": LegalName,
		"legalCountry": LegalCountry,
		"legalState": LegalState,
		"legalCity": LegalCity,
		"legalAddress": LegalAddress,
		"legalTaxId": LegalTaxId,
	}

	return srv.client.Call("PATCH", path, nil, params)
}

// DeleteProject
func (srv *Projects) DeleteProject(ProjectId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId)
	path := r.Replace("/projects/{projectId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}

// ListKeys
func (srv *Projects) ListKeys(ProjectId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId)
	path := r.Replace("/projects/{projectId}/keys")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// CreateKey
func (srv *Projects) CreateKey(ProjectId string, Name string, Scopes []interface{}) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId)
	path := r.Replace("/projects/{projectId}/keys")

	params := map[string]interface{}{
		"name": Name,
		"scopes": Scopes,
	}

	return srv.client.Call("POST", path, nil, params)
}

// GetKey
func (srv *Projects) GetKey(ProjectId string, KeyId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId, "{keyId}", KeyId)
	path := r.Replace("/projects/{projectId}/keys/{keyId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// UpdateKey
func (srv *Projects) UpdateKey(ProjectId string, KeyId string, Name string, Scopes []interface{}) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId, "{keyId}", KeyId)
	path := r.Replace("/projects/{projectId}/keys/{keyId}")

	params := map[string]interface{}{
		"name": Name,
		"scopes": Scopes,
	}

	return srv.client.Call("PUT", path, nil, params)
}

// DeleteKey
func (srv *Projects) DeleteKey(ProjectId string, KeyId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId, "{keyId}", KeyId)
	path := r.Replace("/projects/{projectId}/keys/{keyId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}

// UpdateProjectOAuth
func (srv *Projects) UpdateProjectOAuth(ProjectId string, Provider string, AppId string, Secret string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId)
	path := r.Replace("/projects/{projectId}/oauth")

	params := map[string]interface{}{
		"provider": Provider,
		"appId": AppId,
		"secret": Secret,
	}

	return srv.client.Call("PATCH", path, nil, params)
}

// ListPlatforms
func (srv *Projects) ListPlatforms(ProjectId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId)
	path := r.Replace("/projects/{projectId}/platforms")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// CreatePlatform
func (srv *Projects) CreatePlatform(ProjectId string, Type string, Name string, Key string, Store string, Url string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId)
	path := r.Replace("/projects/{projectId}/platforms")

	params := map[string]interface{}{
		"type": Type,
		"name": Name,
		"key": Key,
		"store": Store,
		"url": Url,
	}

	return srv.client.Call("POST", path, nil, params)
}

// GetPlatform
func (srv *Projects) GetPlatform(ProjectId string, PlatformId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId, "{platformId}", PlatformId)
	path := r.Replace("/projects/{projectId}/platforms/{platformId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// UpdatePlatform
func (srv *Projects) UpdatePlatform(ProjectId string, PlatformId string, Name string, Key string, Store string, Url string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId, "{platformId}", PlatformId)
	path := r.Replace("/projects/{projectId}/platforms/{platformId}")

	params := map[string]interface{}{
		"name": Name,
		"key": Key,
		"store": Store,
		"url": Url,
	}

	return srv.client.Call("PUT", path, nil, params)
}

// DeletePlatform
func (srv *Projects) DeletePlatform(ProjectId string, PlatformId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId, "{platformId}", PlatformId)
	path := r.Replace("/projects/{projectId}/platforms/{platformId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}

// ListTasks
func (srv *Projects) ListTasks(ProjectId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId)
	path := r.Replace("/projects/{projectId}/tasks")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// CreateTask
func (srv *Projects) CreateTask(ProjectId string, Name string, Status string, Schedule string, Security int, HttpMethod string, HttpUrl string, HttpHeaders []interface{}, HttpUser string, HttpPass string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId)
	path := r.Replace("/projects/{projectId}/tasks")

	params := map[string]interface{}{
		"name": Name,
		"status": Status,
		"schedule": Schedule,
		"security": Security,
		"httpMethod": HttpMethod,
		"httpUrl": HttpUrl,
		"httpHeaders": HttpHeaders,
		"httpUser": HttpUser,
		"httpPass": HttpPass,
	}

	return srv.client.Call("POST", path, nil, params)
}

// GetTask
func (srv *Projects) GetTask(ProjectId string, TaskId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId, "{taskId}", TaskId)
	path := r.Replace("/projects/{projectId}/tasks/{taskId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// UpdateTask
func (srv *Projects) UpdateTask(ProjectId string, TaskId string, Name string, Status string, Schedule string, Security int, HttpMethod string, HttpUrl string, HttpHeaders []interface{}, HttpUser string, HttpPass string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId, "{taskId}", TaskId)
	path := r.Replace("/projects/{projectId}/tasks/{taskId}")

	params := map[string]interface{}{
		"name": Name,
		"status": Status,
		"schedule": Schedule,
		"security": Security,
		"httpMethod": HttpMethod,
		"httpUrl": HttpUrl,
		"httpHeaders": HttpHeaders,
		"httpUser": HttpUser,
		"httpPass": HttpPass,
	}

	return srv.client.Call("PUT", path, nil, params)
}

// DeleteTask
func (srv *Projects) DeleteTask(ProjectId string, TaskId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId, "{taskId}", TaskId)
	path := r.Replace("/projects/{projectId}/tasks/{taskId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}

// GetProjectUsage
func (srv *Projects) GetProjectUsage(ProjectId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId)
	path := r.Replace("/projects/{projectId}/usage")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// ListWebhooks
func (srv *Projects) ListWebhooks(ProjectId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId)
	path := r.Replace("/projects/{projectId}/webhooks")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// CreateWebhook
func (srv *Projects) CreateWebhook(ProjectId string, Name string, Events []interface{}, Url string, Security int, HttpUser string, HttpPass string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId)
	path := r.Replace("/projects/{projectId}/webhooks")

	params := map[string]interface{}{
		"name": Name,
		"events": Events,
		"url": Url,
		"security": Security,
		"httpUser": HttpUser,
		"httpPass": HttpPass,
	}

	return srv.client.Call("POST", path, nil, params)
}

// GetWebhook
func (srv *Projects) GetWebhook(ProjectId string, WebhookId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId, "{webhookId}", WebhookId)
	path := r.Replace("/projects/{projectId}/webhooks/{webhookId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// UpdateWebhook
func (srv *Projects) UpdateWebhook(ProjectId string, WebhookId string, Name string, Events []interface{}, Url string, Security int, HttpUser string, HttpPass string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId, "{webhookId}", WebhookId)
	path := r.Replace("/projects/{projectId}/webhooks/{webhookId}")

	params := map[string]interface{}{
		"name": Name,
		"events": Events,
		"url": Url,
		"security": Security,
		"httpUser": HttpUser,
		"httpPass": HttpPass,
	}

	return srv.client.Call("PUT", path, nil, params)
}

// DeleteWebhook
func (srv *Projects) DeleteWebhook(ProjectId string, WebhookId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{projectId}", ProjectId, "{webhookId}", WebhookId)
	path := r.Replace("/projects/{projectId}/webhooks/{webhookId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}
