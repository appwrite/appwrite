---
layout: article
title: Geo queries
description: Query geographic data with distance, intersects, overlaps, and other location-based operations using spatial columns.
---

Geo queries let you perform location-based operations on geographic data stored in your database. Find nearby locations, check if coordinates fall within boundaries, calculate distances between points, and more.

Appwrite supports geo queries through spatial columns that store coordinates, shapes, and areas as first-class data types.

{% info title="Also called spatial queries" %}
In database terminology, these could also be known as **spatial queries**.
{% /info %}

Coordinates are specified as `[longitude, latitude]` arrays. Distance measurements can be specified in meters or degrees.

# Use cases {% #use-cases %}

Use geo queries for location-based features:

- **Search nearby**: Find all bus stops within 200 meters of a location
- **Geofencing**: Check if delivery vehicles enter or exit designated zones
- **Routing coverage**: Determine service areas and delivery radiuses
- **Region lookups**: Match addresses to administrative boundaries
- **Asset tracking**: Monitor equipment locations and movements
- **Compliance zones**: Verify operations within permitted areas

# Spatial columns {% #spatial-columns %}

Appwrite provides first-class geo types that can be stored as table columns:

- **Point**: Represents a single geographic coordinate as `[longitude, latitude]`
- **Line**: Represents a series of connected geographic coordinates forming a path or route
- **Polygon**: Represents a closed area defined by a series of coordinates forming a boundary and optional hole punches within that boundary. Polygons must be **closed** (first and last points are the same), and holes must lie completely inside the boundary, with no overlaps.

# Spatial index {% #spatial-index %}

For optimal performance, create spatial indexes on columns you'll query. Spatial indexes use optimized data structures designed for geographic operations. Spatial indexes are **strongly recommended** if you plan to query your spatial data in any way.

Create spatial indexes through the [Appwrite Console](/docs/products/databases/tables#indexes), [Server SDK](/docs/sdks#server), or [CLI](/docs/tooling/command-line/tables#commands).

# Query operations {% #geo-queries %}

Appwrite supports these geo query operations:

- **Distance queries**: Find locations within, beyond, or exactly at a specified distance
- **Geometric relationships**: Check if shapes intersect, overlap, touch, or cross each other
- **Boundary queries**: Determine if points fall within defined areas

For complete documentation and examples of all geo query operations, see [Queries](/docs/products/databases/queries#geo-queries).

{% info title="Self-hosted installations" %}
If you're self-hosting Appwrite, you will have to choose **MariaDB** as your preferred database to use geo-queries. Self-hosted Appwrite instances using **MongoDB** do not currently support spatial columns or indexes.
{% /info %}