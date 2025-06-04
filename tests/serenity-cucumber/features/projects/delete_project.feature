# language: es
Característica: Eliminar Proyecto

Escenario: Como administrador, puedo eliminar un proyecto por ID
  Dado que la API está disponible
  Y dado que existe un proyecto "ProyectoRenombrado" con ID $projectId
  Cuando el Actor envía DELETE /v1/projects/$projectId
  Entonces el código de respuesta debe ser 204
  Y una solicitud GET /v1/projects/$projectId devuelve 404
