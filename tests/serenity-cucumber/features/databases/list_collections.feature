# language: es
Característica: Listar Colecciones

Escenario: Como administrador, puedo listar todas las colecciones dentro de una base de datos
  Dado que la API está disponible
  Y dado que existe una base de datos "MiBasePrueba" con ID $databaseId
  Y existe una colección "MiColeccion" con ID $collectionId
  Cuando el Actor solicita GET /v1/databases/$databaseId/collections
  Entonces el código de respuesta debe ser 200
  Y la lista devuelta de colecciones debe incluir "MiColeccion"
