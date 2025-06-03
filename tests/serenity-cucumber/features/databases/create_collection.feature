# language: es
Característica: Crear Colección en Base de Datos

Escenario: Como administrador, puedo crear una colección dentro de una base de datos
  Dado que la API está disponible
  Y dado que existe una base de datos "MiBasePrueba" con ID $databaseId
  Cuando el Actor crea una colección en esa base de datos con:
    | nombre   | "MiColeccion"                 |
    | permisos | ["document:read('role:any')"] |
  Entonces el código de respuesta debe ser 201
  Y la respuesta debe incluir un campo collectionId
