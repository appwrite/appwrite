# Appwrite Web Installer

The Appwrite Web Installer provides a modern, user-friendly web interface for installing Appwrite. It runs in a temporary Docker container with its own web server before the Appwrite stack is created.

## Architecture

The web installer runs as part of the install task in a lightweight PHP built-in web server:

```
docker run (temporary container)
  └── Install Task (PHP)
       └── Built-in Web Server (port 80)
            ├── Serves installer UI
            ├── Handles form submission
            ├── Creates docker-compose.yml and .env
            ├── Starts Appwrite containers
            └── Shuts down automatically
```

## Features

- **Self-contained**: Runs in its own temporary container before Appwrite starts
- **No dependencies**: Uses PHP's built-in server, no external services needed
- **Step-by-step guided installation**: Walk through server configuration, domain setup, and security settings
- **Pink UI Design System**: Consistent with Appwrite Console design
- **Input validation**: Real-time validation of all configuration parameters
- **Auto-generated secrets**: Secure random keys generated automatically
- **Progress tracking**: Visual progress bar showing installation progress
- **Responsive design**: Works on desktop and mobile devices
- **Dark mode support**: Automatic theme detection

## Installation Steps

The installer guides you through 5 steps:

1. **Server Configuration**: Configure HTTP and HTTPS ports
2. **Domain Configuration**: Set up your Appwrite hostname and SSL certificate email
3. **Security Configuration**: Review auto-generated encryption keys
4. **Review & Install**: Review your configuration before installation
5. **Complete**: Installation success confirmation

## Usage

### Starting the Installer

Run the Docker install command to launch the web installer:

```bash
docker run -it --rm \
    -p 80:80 \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite:1.7.4
```

This will:
1. Start a temporary container
2. Launch a web server on port 80
3. **Automatically open your browser** to the installer at http://localhost

### Using the Installer

1. The installer opens automatically in your default browser
2. Fill in the installation form:
   - HTTP Port (default: 80)
   - HTTPS Port (default: 443)
   - Appwrite Hostname (e.g., appwrite.example.com)
   - SSL Certificate Email
   - Secret Key (auto-generated)
3. Click through each step
4. Review your configuration
5. Click "Install Appwrite"
6. Wait for installation to complete
7. The web server will shut down automatically
8. Access your Appwrite console at `http://localhost/console`

### CLI Mode Fallback

You can still use the traditional CLI mode by passing `--interactive N`:

```bash
docker run -it --rm \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite:1.7.4 \
    --interactive N
```

**Note:** In CLI mode, the `-p 80:80` flag is not needed.

## Testing

### Prerequisites

The web installer needs to be running to execute the full test suite:

```bash
# Terminal 1: Start the installer
docker run -it --rm \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    -p 80:80 \
    --entrypoint="install" \
    appwrite/appwrite:1.7.4

# Terminal 2: Run tests
npm install
npx playwright install
npm run test:installer
```

### Running Tests

```bash
# Run all installer tests
npm run test:installer

# Run tests in headed mode (see browser)
npm run test:headed

# Run tests with UI mode
npm run test:ui

# Run all tests
npm test
```

### Test Coverage

The test suite includes:

- Page loading and rendering
- Step-by-step navigation
- Form validation (ports, email, required fields)
- Progress bar updates
- Back button functionality
- Complete installation flow
- API endpoint testing
- Pink UI integration verification

## Files

### Frontend

- **`app/views/install/installer.phtml`**: Single-file HTML interface with inline JavaScript and CSS

### Backend

- **`src/Appwrite/Platform/Tasks/Install.php`**: Install task with embedded web server
  - `startWebServer()`: Launches PHP built-in server
  - `openBrowser()`: Opens browser automatically based on OS
  - `createRouterScript()`: Generates router for handling HTTP requests
  - `performInstallation()`: Public method that executes the actual installation
- **`app/views/install/compose.phtml`**: Docker Compose template
- **`app/views/install/env.phtml`**: Environment variables template

### Tests

- **`tests/playwright/installer.spec.js`**: Playwright E2E tests
- **`playwright.config.js`**: Playwright configuration

## Technical Details

### Web Server

The installer uses PHP's built-in web server for simplicity:

```php
php -S 0.0.0.0:80 /tmp/appwrite-installer-router.php
```

### Router Script

A temporary router script is generated that handles:

- `GET /` - Serves the single-file installer UI (HTML + inline CSS + inline JavaScript)
- `POST /install` - Handles installation requests by calling `performInstallation()` directly

### Installation Flow

1. User fills out form in browser
2. Form data is sent via POST to `/install`
3. Router script validates and processes the request
4. Calls `performInstallation()` public method directly (no reflection needed)
5. Generates `docker-compose.yml` and `.env` files
6. Starts Docker containers
7. Returns success response
8. Server shuts down after 2 seconds (allowing response to be sent)

### Port Configuration

- **Installer web server**: `80` (temporary, for installation only)
- **Appwrite HTTP**: `80` (default, configurable after installation)
- **Appwrite HTTPS**: `443` (default, configurable after installation)

## Security Considerations

- The installer generates secure random keys using `random_bytes()`
- All secrets are transmitted within the Docker network
- The temporary web server shuts down after installation
- Generated keys should be backed up securely
- The installer should only be run during initial setup

## Troubleshooting

### Installer Web Server Won't Start

- Check that port 80 is available
- Verify PHP is available in the Docker image
- Check Docker logs for errors

### Can't Access http://localhost

- Ensure you're exposing port 80: Add `-p 80:80` to docker run command
- Check firewall settings
- Try `http://127.0.0.1` instead

### Installation Fails

- Check Docker is installed and running
- Verify write permissions for the appwrite directory
- Review browser console for errors
- Check Docker socket is mounted correctly

### Tests Failing

- Ensure the installer web server is running
- Verify Playwright browsers are installed: `npx playwright install`
- Check that port 80 is available
- Review test output for specific failures

## Advantages Over CLI Installer

1. **Better UX**: Visual interface instead of text prompts
2. **Validation**: Real-time field validation
3. **Review**: See all settings before committing
4. **Accessibility**: Works on any device with a browser
5. **Modern**: Consistent with Appwrite Console design

## Future Enhancements

Potential improvements:

- [ ] Multi-language support
- [ ] Advanced configuration options
- [ ] Installation progress streaming
- [ ] Backup and restore existing installations
- [ ] One-click update functionality
- [ ] Pre-flight checks (Docker, ports, etc.)
- [ ] Custom theme support
- [ ] Installation templates

## Support

For issues or questions:

- [GitHub Issues](https://github.com/appwrite/appwrite/issues)
- [Discord Community](https://appwrite.io/discord)
- [Documentation](https://appwrite.io/docs)
