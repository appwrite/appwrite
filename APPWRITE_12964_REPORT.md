# Issue #12964 — First-Class Self-Hosted Multi-Region (Meta + Regional APIs)

## Resumen

- **Issue**: [#12964](https://github.com/appwrite/appwrite/issues/12964) — Feature request for official self-hosted multi-region support.
- **Estado actual**: Appwrite OSS assumes a single-region monolith. Region primitives exist (`region` attribute on projects, `_APP_REGION` env var, realtime region checks, `Pool::dsn()` filtering) but are undocumented and incomplete.
- **Objetivo**: Enable a documented "meta" (control plane) + "regional" (data plane) topology, matching Appwrite Cloud semantics where OSS already has hooks.

## Análisis de la Arquitectura Actual

### 1. Configuración de Regiones

**Archivo**: `app/config/regions.php`
```php
return [
    'default' => [
        '$id' => 'default',
        'name' => 'default',
        'disabled' => false,
        'default' => true,
    ],
];
```
Solo existe una región `default`. El archivo es estático — no se puede sobrescribir vía entorno. Cargado en `app/init/configs.php:32` mediante `Config::load('regions', ...)`.

### 2. Variables de Entorno Relevantes

| Variable | Uso Actual | ¿En docker-compose? |
|---|---|---|
| `_APP_REGION` | Identidad del servidor actual. Default `'default'`. | NO |
| `_APP_PROJECT_REGIONS` | Allow-list de regiones válidas al crear proyectos (separado por comas). Default `'default'`. | NO |
| `_APP_DATABASE_KEYS` | Pool names regionales para MariaDB (substring matching). Ej: `fra-main,nyc-main`. | NO |
| `_APP_DATABASE_OVERRIDE` | Forzar pool específico en creación de proyecto. | NO |
| `_APP_DATABASE_SHARED_TABLES` | Hosts en modo shared tables. | NO |
| `_APP_CONNECTIONS_DATABASE_DOCUMENTSDB` | DSNs para MongoDB document DB (multi-DSN vía comas). | NO |
| `_APP_CONNECTIONS_DATABASE_VECTORSDB` | DSNs para PostgreSQL vector DB (multi-DSN vía comas). | NO |
| `_APP_DATABASE_SHARED_NAMESPACE` | Namespace para shared tables. | NO |

**Hallazgo crítico**: No existe `_APP_CONNECTIONS_DATABASE` para el pool principal `database` (MariaDB de proyectos). El pool `database` en `app/init/registers.php:193-198` usa `$fallbackForDB` (construido desde `_APP_DB_HOST`, etc.) — un solo DSN, no una lista.

### 3. Sistema de Pools de Base de Datos

**Archivo**: `app/init/registers.php` (líneas 150-402)

El sistema registra 4 grupos de conexiones database:
- `console` — single DSN (MariaDB de la consola)
- `database` — **falta `_APP_CONNECTIONS_DATABASE`**, siempre usa fallback single DSN
- `documentsdb` — multi-DSN vía `_APP_CONNECTIONS_DATABASE_DOCUMENTSDB`
- `vectorsdb` — multi-DSN vía `_APP_CONNECTIONS_DATABASE_VECTORSDB`
- `logs` — single DSN

Para multi-DSN, el formato es: `name1=scheme://host1:port, name2=scheme://host2:port`. Los pools se registran como `database_name1`, `database_name2`, etc.

### 4. Selección Regional de Base de Datos (Creación de Proyectos)

**Archivo**: `src/Appwrite/Platform/Modules/Projects/Http/Projects/Create.php` (líneas 121-137)

```php
if ($region !== 'default') {
    $databaseKeys = System::getEnv('_APP_DATABASE_KEYS', '');
    $keys = explode(',', $databaseKeys);
    $databases = array_filter($keys, function ($value) use ($region) {
        return str_contains($value, $region);
    });
}
```

Selecciona un pool de base de datos basado en coincidencia de substring del nombre del pool con el nombre de la región. El DSN seleccionado se almacena en el proyecto como `project['database']`.

**Archivo**: `src/Appwrite/Platform/Modules/Databases/Pool.php` — método `dsn()` (líneas 12-99)

Lógica similar para `documentsdb` y `vectorsdb`: filtra `_APP_DATABASE_DOCUMENTSDB_KEYS` y `_APP_DATABASE_VECTORSDB_KEYS` por substring de región.

### 5. Validación de Región en Realtime

**Archivo**: `app/realtime.php` (líneas 932-936)
```php
$projectRegion = $project->getAttribute('region', '');
$currentRegion = System::getEnv('_APP_REGION', 'default');
if (!empty($projectRegion) && $projectRegion !== $currentRegion) {
    throw new AppwriteException(..., 'Project is not accessible in this region.');
}
```

Cada servidor realtime solo maneja conexiones de proyectos en su región.

### 6. API de Variables de Consola

**Archivo**: `src/Appwrite/Platform/Modules/Console/Http/Variables/Get.php`

Expone variables de entorno al frontend vía `GET /v1/console/variables`. **Actualmente NO expone `_APP_REGION` ni información de regiones disponibles**. El modelo `ConsoleVariables` (en `src/Appwrite/Utopia/Response/Model/ConsoleVariables.php`) no tiene campos de región.

### 7. Workers y Tareas Programadas

Varios workers filtran por `_APP_REGION` para ejecutar tareas solo en la región correspondiente (Maintenance, Deletes, Interval, ScheduleBase). Esto ya es compatible con despliegues multi-región — cada stack regional ejecuta sus propios workers.

### 8. Docker Compose

**Archivo**: `docker-compose.yml`

No pasa ninguna variable de entorno relacionada con regiones (`_APP_REGION`, `_APP_PROJECT_REGIONS`, `_APP_DATABASE_KEYS`, `_APP_CONNECTIONS_DATABASE*`) a ningún servicio. Todas las conexiones DB apuntan a un solo host.

---

## Puntos de Extensión Identificados

### 1. Catálogo de Regiones Configurable por Entorno

**Problema**: `app/config/regions.php` es estático y requiere rebuild de la imagen.

**Solución**: Nueva variable `_APP_REGIONS` que acepta JSON con la definición de regiones. En `app/init/configs.php`, cargar regiones desde env con fallback al archivo PHP:
```php
$regionsJson = System::getEnv('_APP_REGIONS', '');
if (!empty($regionsJson)) {
    Config::setParam('regions', json_decode($regionsJson, true));
} else {
    Config::load('regions', __DIR__ . '/../config/regions.php', $configAdapter);
}
```

Ejemplo de `_APP_REGIONS`:
```json
{
  "fra": { "$id": "fra", "name": "Frankfurt", "disabled": false, "default": true },
  "nyc": { "$id": "nyc", "name": "New York", "disabled": false, "default": false }
}
```

**Archivos a modificar**:
- `app/init/configs.php` — cargar regiones desde env
- `app/config/regions.php` — mantener como fallback

### 2. Pool de Base de Datos Multi-Región para `database`

**Problema**: El pool `database` no soporta múltiples DSNs. No existe `_APP_CONNECTIONS_DATABASE`.

**Solución**: Añadir `_APP_CONNECTIONS_DATABASE` para el pool `database`, con el mismo comportamiento multi-DSN que `documentsdb` y `vectorsdb`.

**Archivos a modificar**:
- `app/init/registers.php:195` — cambiar `$fallbackForDB` por `System::getEnv('_APP_CONNECTIONS_DATABASE', $fallbackForDB)`

### 3. Creación de Proyectos Cross-Región desde Meta

**Problema**: La creación de proyectos funciona pero no está documentada. El meta necesita comunicación con DBs regionales.

**Solución**: Documentar y robustecer el flujo actual. El meta se conecta a todas las DBs regionales (listadas en `_APP_CONNECTIONS_DATABASE`). Al crear un proyecto con `region=fra`, selecciona el pool `database_fra_main` y crea las colecciones allí.

**Archivos**: principalmento documentación. El código en `Create.php` ya funciona.

### 4. Identidad Regional del Proceso (`_APP_REGION`)

**Problema**: `_APP_REGION` debe ser configurable por despliegue pero no está en docker-compose.

**Solución**: Añadir `_APP_REGION=<id-region>` a cada stack regional en docker-compose.

**Archivos a modificar**:
- `docker-compose.yml` — añadir `_APP_REGION` a todos los servicios

### 5. Variables de Consola para Multi-Región

**Problema**: `/v1/console/variables` no expone regiones al frontend.

**Solución**: Añadir al endpoint:
- `_APP_REGION` — región actual del servidor
- `_APP_PROJECT_REGIONS` — lista de regiones permitidas
- (opcional) `_APP_REGIONS` — catálogo completo de regiones

**Archivos a modificar**:
- `src/Appwrite/Platform/Modules/Console/Http/Variables/Get.php` — añadir campos al Document
- `src/Appwrite/Utopia/Response/Model/ConsoleVariables.php` — añadir reglas de validación

### 6. Documentación y Topología de Ejemplo

**Problema**: No hay documentación ni ejemplos de despliegue multi-región.

**Solución**: Crear guía de topología meta + regionales.

---

## Propuesta de Implementación

### Arquitectura Propuesta

```
                    ┌──────────────────────┐
                    │       META STACK      │
                    │  (Control Plane)      │
                    │                       │
                    │  _APP_REGION=meta     │
                    │  _APP_ROLE=meta       │  ← NEW
                    │  _APP_CONNECTIONS_*   │
                    │    → platform DB      │
                    │    → DBs for fra, nyc │
                    └────────┬─────────────┘
                             │
            ┌────────────────┼────────────────┐
            │                │                 │
   ┌────────▼────────┐ ┌────▼─────────┐ ┌─────▼─────────┐
   │  REGION: fra     │ │  REGION: nyc  │ │  REGION: ...   │
   │  _APP_REGION=fra │ │ _APP_REGION=  │ │               │
   │  → local DB      │ │ nyc           │ │               │
   │  → platform DB   │ │ → local DB    │ │               │
   │    (read-only?)  │ │ → platform DB │ │               │
   └─────────────────┘ └───────────────┘ └───────────────┘
```

### Cambios por Archivo

#### Fase 1: Infraestructura de Regiones

| Archivo | Cambio |
|---|---|
| `app/config/regions.php` | Mantener como fallback (ejemplo expandido con `fra`, `nyc`) |
| `app/init/configs.php` | Leer `_APP_REGIONS` JSON de env; si existe, sobrescribe config |
| `app/init/registers.php:195` | Añadir `_APP_CONNECTIONS_DATABASE` env var para pool `database` |
| `.env` | Añadir `_APP_REGIONS`, `_APP_CONNECTIONS_DATABASE` ejemplos comentados |

#### Fase 2: Consola Multi-Región

| Archivo | Cambio |
|---|---|
| `src/Appwrite/.../Console/Variables/Get.php` | Exponer `_APP_REGION`, `_APP_PROJECT_REGIONS`, lista de regiones |
| `src/Appwrite/.../Model/ConsoleVariables.php` | Añadir reglas para nuevos campos |

#### Fase 3: Despliegue

| Archivo | Cambio |
|---|---|
| `docker-compose.yml` | Añadir `_APP_REGION`, `_APP_PROJECT_REGIONS`, `_APP_CONNECTIONS_DATABASE*` a todos los servicios |
| `docs/` (nuevo) | Guía de topología meta + regionales |

### Nuevas Variables de Entorno

| Variable | Propósito | Default |
|---|---|---|
| `_APP_REGIONS` | Catálogo de regiones en JSON | leer de `regions.php` |
| `_APP_CONNECTIONS_DATABASE` | DSNs multi-región para MariaDB | `_APP_DB_HOST/compat` |
| `_APP_ROLE` | `meta` o `regional` (opcional) | `regional` |

### Inconsistencias Detectadas

1. **Error message en `app/config/errors.php:1197`**: Referencia `_APP_REGIONS` (plural) pero la variable real es `_APP_PROJECT_REGIONS`.
2. **Pool `database` no tiene multi-DSN**: `_APP_CONNECTIONS_DATABASE` no existe, a diferencia de `_APP_CONNECTIONS_DATABASE_DOCUMENTSDB` y `_APP_CONNECTIONS_DATABASE_VECTORSDB`.
3. **Docker Compose no pasa variables de región**: Ninguna variable `_APP_REGION*` ni `_APP_CONNECTIONS_DATABASE*` está en docker-compose.yml.

---

## Estado

- [x] Repositorio clonado y analizado.
- [x] Puntos de extensión identificados.
- [x] Propuesta de implementación esbozada.
- [x] Implementación completada (ver cambios abajo).

## Cambios Realizados

| Archivo | Cambio |
|---|---|
| `app/init/registers.php:195` | Añadido `_APP_CONNECTIONS_DATABASE` env var para pool `database` (multi-DSN support) |
| `app/init/configs.php:33-39` | Carga dinámica de regiones desde `_APP_REGIONS` JSON env var |
| `app/config/regions.php` | Expandido con regiones `fra` y `nyc` como ejemplo/fallback |
| `app/config/errors.php:1196` | Corregido `_APP_REGIONS` → `_APP_PROJECT_REGIONS` |
| `src/.../Variables/Get.php` | Añadido `_APP_REGION`, `_APP_PROJECT_REGIONS`, `_APP_REGIONS` al response |
| `src/.../Model/ConsoleVariables.php` | Añadidas reglas de validación para los 3 nuevos campos |
| `.env` | Añadidos ejemplos comentados de `_APP_REGIONS`, `_APP_CONNECTIONS_DATABASE`, `_APP_DATABASE_KEYS` |
| `docker-compose.yml` | Añadidas vars multi-región a servicios `appwrite` y `appwrite-realtime` |
