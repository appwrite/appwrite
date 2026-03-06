# Appwrite Development Helper Script (PowerShell)
# This script helps with common development tasks

param(
    [string]$Command = "help",
    [string]$Option = ""
)

# Colors for output
$Colors = @{
    Red = "Red"
    Green = "Green"
    Yellow = "Yellow"
    Blue = "Blue"
    White = "White"
}

function Write-Status {
    param([string]$Message)
    Write-Host "[INFO] $Message" -ForegroundColor $Colors.Green
}

function Write-Warning {
    param([string]$Message)
    Write-Host "[WARNING] $Message" -ForegroundColor $Colors.Yellow
}

function Write-Error {
    param([string]$Message)
    Write-Host "[ERROR] $Message" -ForegroundColor $Colors.Red
}

function Write-Header {
    param([string]$Title)
    Write-Host "================================" -ForegroundColor $Colors.Blue
    Write-Host "$Title" -ForegroundColor $Colors.Blue
    Write-Host "================================" -ForegroundColor $Colors.Blue
}

# Check if Docker is running
function Test-DockerRunning {
    try {
        docker info | Out-Null
        Write-Status "Docker is running ✓"
        return $true
    }
    catch {
        Write-Error "Docker is not running. Please start Docker Desktop first."
        return $false
    }
}

# Setup development environment
function Setup-Environment {
    Write-Header "Setting up Appwrite Development Environment"
    
    if (-not (Test-DockerRunning)) {
        exit 1
    }
    
    Write-Status "Initializing git submodules..."
    git submodule update --init
    
    Write-Status "Building Docker containers..."
    docker compose build
    
    Write-Status "Starting services..."
    docker compose up -d
    
    Write-Status "Waiting for services to be ready..."
    Start-Sleep -Seconds 30
    
    Write-Status "Development environment is ready!"
    Write-Host "Appwrite Console: http://localhost" -ForegroundColor $Colors.Green
    Write-Host "API Endpoint: http://localhost/v1" -ForegroundColor $Colors.Green
}

# Run tests
function Invoke-Tests {
    param([string]$TestType = "unit")
    
    Write-Header "Running $TestType tests"
    
    if (-not (Test-DockerRunning)) {
        exit 1
    }
    
    switch ($TestType) {
        "unit" {
            docker compose exec appwrite test /usr/src/code/tests/unit
        }
        "e2e" {
            docker compose exec appwrite test /usr/src/code/tests/e2e
        }
        "all" {
            docker compose exec appwrite test
        }
        default {
            Write-Error "Unknown test type: $TestType"
            Write-Host "Available types: unit, e2e, all"
            exit 1
        }
    }
}

# Code formatting
function Format-Code {
    param([string]$FilePath = "")
    
    Write-Header "Formatting Code"
    
    if ([string]::IsNullOrEmpty($FilePath)) {
        Write-Status "Formatting all files..."
        composer format
    }
    else {
        Write-Status "Formatting file: $FilePath"
        composer format $FilePath
    }
}

# Code linting
function Test-CodeLint {
    param([string]$FilePath = "")
    
    Write-Header "Linting Code"
    
    if ([string]::IsNullOrEmpty($FilePath)) {
        Write-Status "Linting all files..."
        composer lint
    }
    else {
        Write-Status "Linting file: $FilePath"
        composer lint $FilePath
    }
}

# Show logs
function Show-Logs {
    param([string]$Service = "appwrite")
    
    Write-Header "Showing logs for $Service"
    docker compose logs -f $Service
}

# Clean up
function Invoke-Cleanup {
    Write-Header "Cleaning up Docker Resources"
    
    Write-Status "Stopping containers..."
    docker compose down
    
    $choice = Read-Host "Remove volumes? This will delete all data (y/N)"
    if ($choice -eq 'y' -or $choice -eq 'Y') {
        docker compose down -v
        Write-Status "Volumes removed."
    }
    else {
        Write-Status "Volumes preserved."
    }
}

# Search for TODOs
function Find-Todos {
    Write-Header "Searching for TODOs and FIXMEs"
    
    Write-Status "Searching for TODO comments..."
    $todos = Select-String -Path "app\*.php", "src\*.php" -Pattern "TODO|FIXME" | Select-Object -First 20
    
    if ($todos) {
        $todos | ForEach-Object {
            Write-Host "$($_.Path):$($_.LineNumber) - $($_.Line)" -ForegroundColor $Colors.Yellow
        }
    }
    else {
        Write-Status "No TODOs found!"
    }
    
    Write-Warning "Consider fixing these TODOs for easy contributions!"
}

# Show development status
function Show-Status {
    Write-Header "Development Status"
    
    if (Test-DockerRunning) {
        Write-Status "Container Status:"
        docker compose ps
        
        Write-Host ""
        Find-Todos
    }
}

# Show help
function Show-Help {
    Write-Host "Appwrite Development Helper (PowerShell)"
    Write-Host ""
    Write-Host "Usage: .\dev-helper.ps1 [command] [options]"
    Write-Host ""
    Write-Host "Commands:"
    Write-Host "  setup              Setup development environment"
    Write-Host "  test [type]        Run tests (unit, e2e, all)"
    Write-Host "  format [file]      Format code"
    Write-Host "  lint [file]         Lint code"
    Write-Host "  logs [service]     Show logs for service"
    Write-Host "  todos              Find TODOs in codebase"
    Write-Host "  status             Show development status"
    Write-Host "  cleanup            Clean up Docker resources"
    Write-Host "  help               Show this help message"
    Write-Host ""
    Write-Host "Examples:"
    Write-Host "  .\dev-helper.ps1 setup           # Initial setup"
    Write-Host "  .\dev-helper.ps1 test unit       # Run unit tests"
    Write-Host "  .\dev-helper.ps1 format src\     # Format files in src\"
    Write-Host "  .\dev-helper.ps1 logs appwrite   # Show appwrite logs"
}

# Main script logic
switch ($Command) {
    "setup" {
        Setup-Environment
    }
    "test" {
        Invoke-Tests -TestType $(if ([string]::IsNullOrEmpty($Option)) { "unit" } else { $Option })
    }
    "format" {
        Format-Code -FilePath $Option
    }
    "lint" {
        Test-CodeLint -FilePath $Option
    }
    "logs" {
        Show-Logs -Service $(if ([string]::IsNullOrEmpty($Option)) { "appwrite" } else { $Option })
    }
    "todos" {
        Find-Todos
    }
    "status" {
        Show-Status
    }
    "cleanup" {
        Invoke-Cleanup
    }
    "help" {
        Show-Help
    }
    default {
        Write-Error "Unknown command: $Command"
        Show-Help
    }
}
