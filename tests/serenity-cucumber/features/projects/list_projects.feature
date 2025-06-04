# language: es
Característica: Listar Proyectos

Escenario: Como administrador, puedo obtener la lista de todos los proyectos
  Dado que la API está disponible
  Y dado que existe un proyecto con nombre "ProyectoPrueba" y ID $projectId
  Cuando el Actor solicita GET /v1/projects
  Entonces el código de respuesta debe ser 200
  Y la lista devuelta de proyectos debe incluir "ProyectoPrueba"
