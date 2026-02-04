# Appwrite Web Installer

The Appwrite Web Installer provides a modern, user-friendly web interface for installing Appwrite. It runs in a
temporary Docker container with its own web server before the Appwrite stack is created.

## Architecture

The web installer runs as part of the installation task in a lightweight PHP built-in web server:

```
docker run (temporary container)
  └── Server.php
       ├── PHP built-in server (0.0.0.0:20080)
       ├── HttpHandler (routing, CSRF, SSE)
       ├── State (progress tracking)
       └── Config (installation settings)
```

## Features

- Retry failed steps with payload validation
- Step-by-step guided installation with real-time validation
- Progress tracking with Server-Sent Events and polling fallback

## Usage

### Docker

```bash
docker run -it --rm \
    --name appwrite-installer \
    -p 20080:20080 \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite:<version>
```

Then open http://localhost:20080 in your browser.

### Composer (Development)

```bash
composer installer:dev              # Start web installer in Docker
composer installer:dev-upgrade      # Start web installer in upgrade mode
composer installer:clean            # Clean up installer containers and temp files
```

### CLI Mode

```bash
docker run -it --rm \
    --name appwrite-installer \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite:<version> \
    --interactive N
```

## Files

### Frontend

- `app/views/install/installer.phtml` - Main layout
- `app/views/install/installer/` - UI components (templates, CSS, JS, icons)

### Backend

- `src/Appwrite/Platform/Installer/**/*.php` - Installer backend (Server, HttpHandler, State, Config)
- `src/Appwrite/Platform/Tasks/Install.php` - Installation task execution
- `app/views/install/*.phtml` - Docker Compose and env templates

## Technical Details

### Endpoints

- `GET /` - Installer UI
- `POST /install` - Start installation (SSE stream or JSON)
- `GET /install/status?installId=...` - Progress polling
- `POST /install/validate` - CSRF validation
- `POST /install/complete` - Notify completion

### URL Parameters

- `?step=1..5` - Jump to specific installation step

### Installation Flow

1. User submits form → CSRF validation
2. Acquire global lock (prevents concurrent installs)
3. Stream progress via SSE (or poll if SSE fails)
4. Generate `docker-compose.yml` and `.env`
5. Run `docker compose up -d`
6. Redirect to console, shutdown server

## Security

- **CSRF**: Cookie tokens with timing-safe comparison
- **CSP**: Strict headers prevent XSS
- **Validation**: Ports (1-65535), emails, domains, env var names
- **Escaping**: All shell commands use `escapeshellarg()`
- **Path protection**: `realpath()` + `str_starts_with()` checks
- **File permissions**: Config files created with mode 0600
- **Global lock**: File-based with exception-safe cleanup

## Troubleshooting

### ERR_EMPTY_RESPONSE

Server binds to `0.0.0.0` (not `localhost`) for Docker port mapping. If `localhost` resolves to IPv6 `::1`, connection
will fail from host.

### Can't access localhost:20080

Ensure `-p 20080:20080` is in docker run command. Try `http://127.0.0.1:20080` instead.

### Installation fails

- Check Docker is running
- Verify write permissions for appwrite directory
- Check browser console for errors
- Review Docker logs: `docker logs appwrite-installer`

## Support

For issues or questions:

- [GitHub Issues](https://github.com/appwrite/appwrite/issues)
- [Discord Community](https://appwrite.io/discord)
- [Documentation](https://appwrite.io/docs)
