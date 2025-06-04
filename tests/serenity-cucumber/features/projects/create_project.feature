# language: es
Característica: Crear Proyecto

Escenario: Como administrador, puedo crear un nuevo proyecto de Appwrite
  Dado que la API está disponible
  Y dado que no existe un proyecto con nombre "ProyectoPrueba"
  Cuando el Actor envía un POST a /v1/projects con:
    | name   | ProyectoPrueba      |
    | teamId | 683e70eb00345a2898b3        |
    | email  | "admin@ejemplo.com" |
  Entonces el código de respuesta debe ser 201
  Y el JSON retornado debe incluir un campo projectId
