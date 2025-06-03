# language: es
Característica: Actualizar Proyecto

Escenario: Como administrador, puedo renombrar un proyecto existente
  Dado que la API está disponible
  Y dado que existe un proyecto "ProyectoPrueba" con ID $projectId
  Cuando el Actor envía PATCH /v1/projects/$projectId con:
    | name | "ProyectoRenombrado" |
  Entonces el código de respuesta debe ser 200
  Y el JSON retornado debe tener name "ProyectoRenombrado"
