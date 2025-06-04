# language: es
Característica: Crear Base de Datos

Escenario: Como administrador, puedo crear una nueva base de datos
  Dado que la API está disponible
  Cuando el Actor intenta crear una base de datos con:
    | nombre   | MiBasePrueba  |
    | permisos | ["owner:any"] |
  Entonces el código de respuesta debe ser 201
  Y la respuesta debe incluir un campo databaseId
