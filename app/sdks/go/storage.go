package appwrite

import (
	"strings"
)

// Storage service
type Storage struct {
	client Client
}

// ListFiles get a list of all the user files. You can use the query params to
// filter your results. On admin mode, this endpoint will return a list of all
// of the project files. [Learn more about different API modes](/docs/admin).
func (srv *Storage) ListFiles(Search string, Limit int, Offset int, OrderType string) (map[string]interface{}, error) {
	path := "/storage/files"

	params := map[string]interface{}{
		"search": Search,
		"limit": Limit,
		"offset": Offset,
		"orderType": OrderType,
	}

	return srv.client.Call("GET", path, nil, params)
}

// CreateFile create a new file. The user who creates the file will
// automatically be assigned to read and write access unless he has passed
// custom values for read and write arguments.
func (srv *Storage) CreateFile(File string, Read []interface{}, Write []interface{}) (map[string]interface{}, error) {
	path := "/storage/files"

	params := map[string]interface{}{
		"file": File,
		"read": Read,
		"write": Write,
	}

	return srv.client.Call("POST", path, nil, params)
}

// GetFile get file by its unique ID. This endpoint response returns a JSON
// object with the file metadata.
func (srv *Storage) GetFile(FileId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{fileId}", FileId)
	path := r.Replace("/storage/files/{fileId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// UpdateFile update file by its unique ID. Only users with write permissions
// have access to update this resource.
func (srv *Storage) UpdateFile(FileId string, Read []interface{}, Write []interface{}) (map[string]interface{}, error) {
	r := strings.NewReplacer("{fileId}", FileId)
	path := r.Replace("/storage/files/{fileId}")

	params := map[string]interface{}{
		"read": Read,
		"write": Write,
	}

	return srv.client.Call("PUT", path, nil, params)
}

// DeleteFile delete a file by its unique ID. Only users with write
// permissions have access to delete this resource.
func (srv *Storage) DeleteFile(FileId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{fileId}", FileId)
	path := r.Replace("/storage/files/{fileId}")

	params := map[string]interface{}{
	}

	return srv.client.Call("DELETE", path, nil, params)
}

// GetFileDownload get file content by its unique ID. The endpoint response
// return with a 'Content-Disposition: attachment' header that tells the
// browser to start downloading the file to user downloads directory.
func (srv *Storage) GetFileDownload(FileId string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{fileId}", FileId)
	path := r.Replace("/storage/files/{fileId}/download")

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// GetFilePreview get a file preview image. Currently, this method supports
// preview for image files (jpg, png, and gif), other supported formats, like
// pdf, docs, slides, and spreadsheets, will return the file icon image. You
// can also pass query string arguments for cutting and resizing your preview
// image.
func (srv *Storage) GetFilePreview(FileId string, Width int, Height int, Quality int, Background string, Output string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{fileId}", FileId)
	path := r.Replace("/storage/files/{fileId}/preview")

	params := map[string]interface{}{
		"width": Width,
		"height": Height,
		"quality": Quality,
		"background": Background,
		"output": Output,
	}

	return srv.client.Call("GET", path, nil, params)
}

// GetFileView get file content by its unique ID. This endpoint is similar to
// the download method but returns with no  'Content-Disposition: attachment'
// header.
func (srv *Storage) GetFileView(FileId string, As string) (map[string]interface{}, error) {
	r := strings.NewReplacer("{fileId}", FileId)
	path := r.Replace("/storage/files/{fileId}/view")

	params := map[string]interface{}{
		"as": As,
	}

	return srv.client.Call("GET", path, nil, params)
}
