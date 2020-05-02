package appwrite

import (
)

// Health service
type Health struct {
	client Client
}

func NewHealth(clt Client) Health {  
    service := Health{
		client: clt,
	}

    return service
}

// Get check the Appwrite HTTP server is up and responsive.
func (srv *Health) Get() (map[string]interface{}, error) {
	path := "/health"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// GetCache check the Appwrite in-memory cache server is up and connection is
// successful.
func (srv *Health) GetCache() (map[string]interface{}, error) {
	path := "/health/cache"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// GetDB check the Appwrite database server is up and connection is
// successful.
func (srv *Health) GetDB() (map[string]interface{}, error) {
	path := "/health/db"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// GetQueueCertificates get the number of certificates that are waiting to be
// issued against [Letsencrypt](https://letsencrypt.org/) in the Appwrite
// internal queue server.
func (srv *Health) GetQueueCertificates() (map[string]interface{}, error) {
	path := "/health/queue/certificates"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// GetQueueLogs get the number of logs that are waiting to be processed in the
// Appwrite internal queue server.
func (srv *Health) GetQueueLogs() (map[string]interface{}, error) {
	path := "/health/queue/logs"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// GetQueueTasks get the number of tasks that are waiting to be processed in
// the Appwrite internal queue server.
func (srv *Health) GetQueueTasks() (map[string]interface{}, error) {
	path := "/health/queue/tasks"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// GetQueueUsage get the number of usage stats that are waiting to be
// processed in the Appwrite internal queue server.
func (srv *Health) GetQueueUsage() (map[string]interface{}, error) {
	path := "/health/queue/usage"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// GetQueueWebhooks get the number of webhooks that are waiting to be
// processed in the Appwrite internal queue server.
func (srv *Health) GetQueueWebhooks() (map[string]interface{}, error) {
	path := "/health/queue/webhooks"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// GetStorageAntiVirus check the Appwrite Anti Virus server is up and
// connection is successful.
func (srv *Health) GetStorageAntiVirus() (map[string]interface{}, error) {
	path := "/health/storage/anti-virus"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// GetStorageLocal check the Appwrite local storage device is up and
// connection is successful.
func (srv *Health) GetStorageLocal() (map[string]interface{}, error) {
	path := "/health/storage/local"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}

// GetTime check the Appwrite server time is synced with Google remote NTP
// server. We use this technology to smoothly handle leap seconds with no
// disruptive events. The [Network Time
// Protocol](https://en.wikipedia.org/wiki/Network_Time_Protocol) (NTP) is
// used by hundreds of millions of computers and devices to synchronize their
// clocks over the Internet. If your computer sets its own clock, it likely
// uses NTP.
func (srv *Health) GetTime() (map[string]interface{}, error) {
	path := "/health/time"

	params := map[string]interface{}{
	}

	return srv.client.Call("GET", path, nil, params)
}
