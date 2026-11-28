from django.urls import path

from . import views

urlpatterns = [
    path("", views.root),
    path("echo", views.echo),
    path("env", views.env),
]
