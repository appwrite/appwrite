#!/bin/bash

# Appwrite Development Helper Script
# This script helps with common development tasks

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

print_header() {
    echo -e "${BLUE}================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}================================${NC}"
}

# Check if Docker is running
check_docker() {
    if ! docker info > /dev/null 2>&1; then
        print_error "Docker is not running. Please start Docker first."
        exit 1
    fi
    print_status "Docker is running ✓"
}

# Setup development environment
setup() {
    print_header "Setting up Appwrite Development Environment"
    
    check_docker
    
    print_status "Initializing git submodules..."
    git submodule update --init
    
    print_status "Building Docker containers..."
    docker compose build
    
    print_status "Starting services..."
    docker compose up -d
    
    print_status "Waiting for services to be ready..."
    sleep 30
    
    print_status "Development environment is ready!"
    echo -e "${GREEN}Appwrite Console:${NC} http://localhost"
    echo -e "${GREEN}API Endpoint:${NC} http://localhost/v1"
}

# Run tests
run_tests() {
    local test_type=${1:-"unit"}
    
    print_header "Running $test_type tests"
    
    check_docker
    
    case $test_type in
        "unit")
            docker compose exec appwrite test /usr/src/code/tests/unit
            ;;
        "e2e")
            docker compose exec appwrite test /usr/src/code/tests/e2e
            ;;
        "all")
            docker compose exec appwrite test
            ;;
        *)
            print_error "Unknown test type: $test_type"
            echo "Available types: unit, e2e, all"
            exit 1
            ;;
    esac
}

# Code formatting and linting
format_code() {
    local file_path=${1:-""}
    
    print_header "Formatting Code"
    
    if [ -z "$file_path" ]; then
        print_status "Formatting all files..."
        composer format
    else
        print_status "Formatting file: $file_path"
        composer format "$file_path"
    fi
}

lint_code() {
    local file_path=${1:-""}
    
    print_header "Linting Code"
    
    if [ -z "$file_path" ]; then
        print_status "Linting all files..."
        composer lint
    else
        print_status "Linting file: $file_path"
        composer lint "$file_path"
    fi
}

# Show logs
show_logs() {
    local service=${1:-"appwrite"}
    
    print_header "Showing logs for $service"
    docker compose logs -f "$service"
}

# Clean up
cleanup() {
    print_header "Cleaning up Docker Resources"
    
    print_status "Stopping containers..."
    docker compose down
    
    print_status "Removing volumes (this will delete all data)..."
    read -p "Are you sure? (y/N): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        docker compose down -v
        print_status "Volumes removed."
    else
        print_status "Volumes preserved."
    fi
}

# Search for TODOs
find_todos() {
    print_header "Searching for TODOs and FIXMEs"
    
    print_status "Searching for TODO comments..."
    grep -r "TODO\|FIXME" app/ src/ --include="*.php" | head -20
    
    print_warning "Consider fixing these TODOs for easy contributions!"
}

# Show development status
status() {
    print_header "Development Status"
    
    check_docker
    
    print_status "Container Status:"
    docker compose ps
    
    echo ""
    print_status "Recent TODOs found:"
    find_todos
}

# Main menu
show_help() {
    echo "Appwrite Development Helper"
    echo ""
    echo "Usage: $0 [command] [options]"
    echo ""
    echo "Commands:"
    echo "  setup              Setup development environment"
    echo "  test [type]        Run tests (unit, e2e, all)"
    echo "  format [file]      Format code"
    echo "  lint [file]         Lint code"
    echo "  logs [service]     Show logs for service"
    echo "  todos              Find TODOs in codebase"
    echo "  status             Show development status"
    echo "  cleanup            Clean up Docker resources"
    echo "  help               Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0 setup           # Initial setup"
    echo "  $0 test unit       # Run unit tests"
    echo "  $0 format src/     # Format files in src/"
    echo "  $0 logs appwrite   # Show appwrite logs"
}

# Main script logic
case ${1:-"help"} in
    "setup")
        setup
        ;;
    "test")
        run_tests "${2:-"unit"}"
        ;;
    "format")
        format_code "$2"
        ;;
    "lint")
        lint_code "$2"
        ;;
    "logs")
        show_logs "$2"
        ;;
    "todos")
        find_todos
        ;;
    "status")
        status
        ;;
    "cleanup")
        cleanup
        ;;
    "help"|*)
        show_help
        ;;
esac
