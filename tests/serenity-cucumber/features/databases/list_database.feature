# language: es
Característica: Listar Bases de Datos

Escenario: Como administrador, puedo listar todas las bases de datos
  Dado que la API está disponible
  Y dado que existe una base de datos con nombre "MiBasePrueba"
  Cuando el Actor solicita GET /v1/databases
  Entonces el código de respuesta debe ser 200
  Y la lista devuelta de bases de datos debe incluir "MiBasePrueba"
