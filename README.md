# Packing API

Microservice for calculating the most suitable box for packing products based on their dimensions (width, height, length, weight).

## Features

✅ **Third-party API Integration** - Uses 3dbinpacking.com API for packing calculations  
✅ **Resilient Fallback** - Local fallback algorithm when third-party API fails  
✅ **Result Caching** - Efficient database cache storing only final selected box (not per-box data)  
✅ **Comprehensive Logging** - Detailed logs for debugging and monitoring  
✅ **Full Test Coverage** - 87 tests (156 assertions) covering critical components (unit + integration)  
✅ **DDD Architecture** - Clean separation with Ports & Adapters pattern  
✅ **Test Isolation** - Separate `test_packing` database for clean test environment  
✅ **Docker Ready** - Full Docker Compose setup with MariaDB and PHP  

## Quick Start

### Prerequisites

- Docker and Docker Compose
- Make
- Git

### Installation

1. **Clone and setup environment:**
```bash
git clone <repository>
cd packing-task-stub

# Copy env template and set your UID/GID (for Docker volumes)
cp .env.example .env
printf "UID=$(id -u)\nGID=$(id -g)" >> .env

# Configure credentials in .env:
# - PACKING_API_USERNAME (3dbinpacking.com account email)
# - PACKING_API_KEY (3dbinpacking.com API key)
# - Other settings as needed
nano .env  # or your preferred editor
```

2. **Start services and Install dependencies:**
```bash
make start       # Start and install Docker containers
```

3. **Access the application:**

- **API**: `http://localhost:8080/pack`
- **API Documentation** (Swagger UI): `http://localhost:8080/docs`
- **OpenAPI Spec** (JSON): `http://localhost:8080/openapi.json`

### Makefile Commands
```bash
make up              # Start Docker services (containers + build)
make down            # Stop and remove containers
make down-v          # Stop and remove containers + volumes
make restart         # Restart services (down && up)
make bash            # Open shell in app container
make install         # Install composer dependencies
make test            # Run unit tests
make test-coverage   # Run tests with coverage report
make stan            # Run PHPStan static analysis
make cs              # Run PHP-CS-Fixer (dry-run)
make cs-fix          # Run PHP-CS-Fixer (apply fixes)
make ci              # Run all checks (tests, stan, php-cs-fixer)
```

### API Endpoint

```
POST /pack
Content-Type: application/json

Request:
{"products":[{"width":3,"height":3,"length":3,"weight":1}]}

Response (200):
{"box":{"id":2,"width":4,"height":4,"length":4,"maxWeight":20}}

Response (422 - no suitable box):
{"error":"No single usable box found for the provided products."}

Response (400 - validation error):
{"error":"Field \"products\" is required and must be an array."}
```

## How It Works

1. **Validation** - ProductRequestMapper validates input
2. **Cache Check** - Checks if result already cached for these products
3. **Box Selection**:
   - Gets all boxes sorted by volume (smallest first)
   - Filters by min dimensions and max weight
   - Calls packability checker for remaining candidates
4. **Caching** - Stores selected box ID in cache (only when a box is found by Third-party API)
5. **Response** - Returns selected box or 422 error

### ports and adapters
DDD + ports-and-adapters architecture in `src/Packing`:

- **Domain** - Core entities and invariants
  - `Box`, `Product` - value objects with validation
  - `DomainValidationException` - domain rule violations

- **Application** - Use-cases and ports (interfaces)
  - `PackProductsHandler` - main use-case with cache integration
  - `BoxCatalogPort` - box repository interface
  - `PackabilityCheckerPort` - packing validation interface
  - `PackingCachePort` - cache interface

- **Infrastructure** - Adapters (implementations)
  - `DoctrineBoxCatalogAdapter` - box catalog from database
  - `DoctrinePackingCacheAdapter` - caching with database
  - `ThirdPartyPackabilityCheckerAdapter` - 3dbinpacking.com API integration
  - `FallbackPackabilityCheckerAdapter` - local volume-based algorithm
  - `ResilientPackabilityChecker` - resilience wrapper (primary + fallback)

- **Interface** - HTTP layer
  - `ProductRequestMapper` - input validation and mapping
  - `InputValidationException` - HTTP validation errors

### Tests

**87 tests** (**156 assertions**) covering critical components:

- **Unit Tests** (`tests/Unit`):
  - `PackProductsHandler` - Main use-case logic with cache integration
  - `ThirdPartyPackabilityCheckerAdapter` - Third-party API behavior and failure paths
  - `FallbackPackabilityCheckerAdapter` - Local packing algorithm
  - `ResilientPackabilityChecker` - Resilience/fallback mechanism
  - `ProductRequestMapper` - Input validation
  - `Box` and `Product` models - Domain logic

- **Integration Tests** (`tests/Integration`):
  - Full application flow with real database
  - Cache behavior (cache hits, misses)
  - No-box-found scenario

**Run tests:**
```bash
make test                           # All tests
make test-coverage                  # With coverage report
./vendor/bin/phpunit --testsuite Unit       # Unit only
./vendor/bin/phpunit tests/Integration/     # Integration only
```

### Caching

```sql
Table: packing_calculation_cache
├─ id: SHA256 of normalized products (PRIMARY KEY)
├─ selected_box_id: ID of selected box (NOT NULL)
├─ created_at: First cache entry timestamp
└─ updated_at: Last access timestamp
```

**Key Points:**
- Cache stores **only the final selected box ID** (not per-box data)
- One row per unique set of products (efficient)
- Hash includes products only (not boxes)
- Cache entries are written **only for successful Third-party API checks**
- Type: `INT UNSIGNED` with Foreign Key to `packaging(id)`

### Tests Database

- **Separate database**: `test_packing` (isolated from production `packing`)
- **Auto-created**: Schema cloned from `packing` DB on startup
- **Clean state**: `DELETE FROM packing_calculation_cache` in `tearDown()`
- **Purpose**: Prevent test data pollution during test runs
- **Initialization**: `packaging-schema.sql` creates both databases + seeds data

### Logging

- All logs output to `stdout` (visible in Docker Desktop)
- Log levels:
  - `INFO` - Important events (box selection, cache hits)
  - `DEBUG` - Detailed information (payloads, cache operations)
  - `WARNING` - Failures and fallback usage
  - `ERROR` - Critical errors (API failures, validation)

### Third-party Packing API

**Configuration** (see `.env.example`):
- `PACKING_API_USERNAME` - 3dbinpacking.com account email
- `PACKING_API_KEY` - 3dbinpacking.com API key
- `PACKING_API_URL` - API endpoint
- `PACKING_API_TIMEOUT_SECONDS` - Request timeout (default: 4 seconds)

**Resilience:**
- Primary: 3dbinpacking.com API
- Fallback: Local volume-based algorithm (when API fails or times out)
- Fallback uses dimension and weight validation

### Database

**Structure:**
```
production: packing
├─ packaging (5 boxes)
└─ packing_calculation_cache (cached results)

testing: test_packing
├─ packaging (5 boxes, cloned from packing)
└─ packing_calculation_cache (empty, test-isolated)
```

**Boxes (Seed Data):**
| ID | Width | Height | Length | Max Weight |
|----|-------|--------|--------|-----------|
| 1  | 2.5   | 3.0    | 1.0    | 20      |
| 2  | 4.0   | 4.0    | 4.0    | 20      |
| 3  | 2.0   | 2.0    | 10.0   | 20      |
| 4  | 5.5   | 6.0    | 7.5    | 30      |
| 5  | 9.0   | 9.0    | 9.0    | 30      |

**Initialization:**
- Single source: `data/packaging-schema.sql`
- Creates both `packing` and `test_packing` databases
- Defines schema with UNSIGNED types and Foreign Keys
- Clones structure from `packing` → `test_packing` using `LIKE`
- Clones seed data using `INSERT ... SELECT`

### Environment Variables

See `.env.example` for all available configuration options:
- Database connection (host, user, password, database, port)
- Third-party API credentials and endpoint
- Request timeout settings
- Logging level (`APP_DEBUG`)
