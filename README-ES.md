> ¡Excelentes noticias! ¡Appwrite Cloud ya está en beta pública! Regístrate en [cloud.appwrite.io](https://cloud.appwrite.io) para disfrutar de una experiencia de alojamiento sin problemas. ¡Únete a nosotros en la nube hoy mismo! ☁️🎉

<br />
<p align="center">
    <a href="https://appwrite.io" target="_blank"><img src="./public/images/banner.png" alt="Logotipo de Appwrite"></a>
    <br />
    <br />
    <b>Appwrite es una plataforma de backend para el desarrollo de aplicaciones web, móviles y Flutter. Construida con la comunidad de código abierto y optimizada para la experiencia de desarrollo en los lenguajes de programación que te encantan.</b>
    <br />
    <br />
</p>

<!-- [![Estado de compilación](https://img.shields.io/travis/com/appwrite/appwrite?style=flat-square)](https://travis-ci.com/appwrite/appwrite) -->

[![Estamos contratando](https://img.shields.io/static/v1?label=Estamos&message=contratando&color=azul&style=flat-square)](https://appwrite.io/company/careers)
[![Hacktoberfest](https://img.shields.io/static/v1?label=hacktoberfest&message=ready&color=191120&style=flat-square)](https://hacktoberfest.appwrite.io)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord&style=flat-square)](https://appwrite.io/discord?r=Github)
[![Estado de compilación](https://img.shields.io/github/actions/workflow/status/appwrite/appwrite/tests.yml?branch=master&label=tests&style=flat-square)](https://github.com/appwrite/appwrite/actions)
[![Cuenta de Twitter](https://img.shields.io/twitter/follow/appwrite?color=00acee&label=twitter&style=flat-square)](https://twitter.com/appwrite)

<!-- [![Descargas de Docker](https://img.shields.io/docker/pulls/appwrite/appwrite?color=f02e65&style=flat-square)](https://hub.docker.com/r/appwrite/appwrite) -->
<!-- [![Traducir](https://img.shields.io/badge/traducir-f02e65?style=flat-square)](docs/tutorials/add-translations.md) -->
<!-- [![Tienda de regalos](https://img.shields.io/badge/tienda%20de%20regalos-f02e65?style=flat-square)](https://store.appwrite.io) -->

[English](README.md) | Español | [简体中文](README-CN.md)

[**¡Anunciamos la beta pública de Appwrite Cloud! ¡Regístrate hoy!**](https://cloud.appwrite.io)

Appwrite es un servidor de backend de extremo a extremo para aplicaciones web, móviles, nativas o de backend, empaquetado como un conjunto de microservicios de Docker. Appwrite abstrae la complejidad y la repetitividad necesaria para construir una API de backend moderna desde cero y te permite construir aplicaciones seguras más rápido.

Usando Appwrite, puedes integrar fácilmente tu aplicación con autenticación de usuario y múltiples métodos de inicio de sesión, una base de datos para almacenar y consultar usuarios y datos de equipo, almacenamiento y gestión de archivos, manipulación de imágenes, Funciones en la Nube y [más servicios](https://appwrite.io/docs).

<p align="center">
    <br />
    <a href="https://www.producthunt.com/posts/appwrite-2?utm_source=badge-top-post-badge&utm_medium=badge&utm_souce=badge-appwrite-2" target="_blank"><img src="https://api.producthunt.com/widgets/embed-image/v1/top-post-badge.svg?post_id=360315&theme=light&period=daily" alt="Appwrite - 100&#0037;&#0032;open&#0032;source&#0032;alternative&#0032;for&#0032;Firebase | Product Hunt" style="width: 250px; height: 54px;" width="250" height="54" /></a>
    <br />
    <br />
</p>

![Appwrite](public/images/github.png)

Descubre más en: [https://appwrite.io](https://appwrite.io)

Tabla de contenidos:

- [Instalación](#instalación)
  - [Unix](#unix)
  - [Windows](#windows)
    - [CMD](#cmd)
    - [PowerShell](#powershell)
  - [Actualización desde una versión anterior](#actualización-desde-una-versión-anterior)
- [Configuraciones en un clic](#configuraciones-en-un-clic)
- [Primeros pasos](#primeros-pasos)
  - [Servicios](#servicios)
  - [SDKs](#sdks)
    - [Cliente](#cliente)
    - [Servidor](#servidor)
    - [Comunidad](#comunidad)
- [Arquitectura](#arquitectura)
- [Contribuciones](#contribuciones)
- [Seguridad](#seguridad)
- [Síguenos](#síguenos)
- [Licencia](#licencia)

## Instalación

Appwrite está diseñado para funcionar en un entorno contenerizado. Ejecutar tu servidor es tan sencillo como ejecutar un comando desde tu terminal. Puedes ejecutar Appwrite en tu localhost utilizando docker-compose o en cualquier otra herramienta de orquestación de contenedores, como [Kubernetes](https://kubernetes.io/docs/home/), [Docker Swarm](https://docs.docker.com/engine/swarm/), o [Rancher](https://rancher.com/docs/).

La forma más sencilla de comenzar a ejecutar tu servidor de Appwrite es ejecutando nuestro archivo docker-compose. Antes de ejecutar el comando de instalación, asegúrate de tener [Docker](https://www.docker.com/products/docker-desktop) instalado en tu máquina:

### Unix

```bash
docker run -it --rm \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite:1.4.7
```

### Windows

#### CMD

```cmd
docker run -it --rm ^
    --volume //var/run/docker.sock:/var/run/docker.sock ^
    --volume "%cd%"/appwrite:/usr/src/code/appwrite:rw ^
    --entrypoint="install" ^
    appwrite/appwrite:1.4.7
```

#### PowerShell

```powershell
docker run -it --rm `
    --volume /var/run/docker.sock:/var/run/docker.sock `
    --volume ${pwd}/appwrite:/usr/src/code/appwrite:rw `
   

 --entrypoint="install" `
    appwrite/appwrite:1.4.7
```

Una vez que se complete la instalación de Docker, ve a http://localhost para acceder a la consola de Appwrite desde tu navegador. Ten en cuenta que en hosts no nativos de Linux, el servidor podría tardar unos minutos en iniciarse después de completar la instalación.

Para una instalación avanzada en producción o personalizada, consulta nuestra documentación de [variables de entorno de Docker](https://appwrite.io/docs/environment-variables). También puedes utilizar nuestros archivos [docker-compose.yml](https://appwrite.io/install/compose) y [.env](https://appwrite.io/install/env) públicos para configurar manualmente un entorno.

### Actualización desde una versión anterior

Si estás actualizando tu servidor de Appwrite desde una versión anterior, debes usar la herramienta de migración de Appwrite una vez que se haya completado tu configuración. Para obtener más información al respecto, consulta la [documentación de instalación](https://appwrite.io/docs/installation).

## Configuraciones en un clic

Además de ejecutar Appwrite localmente, también puedes lanzar Appwrite utilizando una configuración predefinida. Esto te permite comenzar rápidamente con Appwrite sin necesidad de instalar Docker en tu máquina local.

Elige uno de los proveedores a continuación:

<table border="0">
  <tr>
    <td align="center" width="100" height="100">
      <a href="https://marketplace.digitalocean.com/apps/appwrite">
        <img width="50" height="39" src="public/images/integrations/digitalocean-logo.svg" alt="Logotipo de DigitalOcean" />
          <br /><sub><b>DigitalOcean</b></sub></a>
        </a>
    </td>
    <td align="center" width="100" height="100">
      <a href="https://gitpod.io/#https://github.com/appwrite/integration-for-gitpod">
        <img width="50" height="39" src="public/images/integrations/gitpod-logo.svg" alt="Logotipo de Gitpod" />
          <br /><sub><b>Gitpod</b></sub></a>    
      </a>
    </td>
    <td align="center" width="100" height="100">
      <a href="https://www.linode.com/marketplace/apps/appwrite/appwrite/">
        <img width="50" height="39" src="public/images/integrations/akamai-logo.svg" alt="Logotipo de Akamai" />
          <br /><sub><b>Akamai Compute</b></sub></a>    
      </a>
    </td>
  </tr>
</table>

## Primeros pasos

Comenzar con Appwrite es tan sencillo como crear un nuevo proyecto, elegir tu plataforma y luego integrar su SDK en tu código. Puedes empezar fácilmente con la plataforma de tu elección leyendo uno de nuestros tutoriales de primeros pasos.

- [Primeros pasos para la web](https://appwrite.io/docs/getting-started-for-web)
- [Primeros pasos para Flutter](https://appwrite.io/docs/getting-started-for-flutter)
- [Primeros pasos para Apple](https://appwrite.io/docs/getting-started-for-apple)
- [Primeros pasos para Android](https://appwrite.io/docs/getting-started-for-android)
- [Primeros pasos para el servidor](https://appwrite.io/docs/getting-started-for-server)
- [Primeros pasos para la CLI](https://appwrite.io/docs/command-line)

### Servicios

- [**Cuenta**](https://appwrite.io/docs/references/cloud/client-web/account) - Gestiona la autenticación y la cuenta del usuario actual. Realiza un seguimiento y gestiona las sesiones del usuario, los dispositivos, los métodos de inicio de sesión y los registros de seguridad.
- [**Usuarios**](https://appwrite.io/docs/server/users) - Gestiona y enumera a todos los usuarios del proyecto al crear integraciones de backend con los SDK de servidor.
- [**Equipos**](https://appwrite.io/docs/references/cloud/client-web/teams) - Gestiona y agrupa a los usuarios en equipos. Administra las membresías, invitaciones y roles de usuario dentro de un equipo.
- [**Bases de datos**](https://appwrite.io/docs/references/cloud/client-web/databases) - Gestiona bases de datos, colecciones y documentos. Lee, crea, actualiza y elimina documentos y filtra listas de colecciones de documentos usando filtros avanzados.
- [**Almacenamiento**](https://appwrite.io/docs/references/cloud/client-web/storage) - Gestiona archivos de almacenamiento. Lee, crea, elimina y previsualiza archivos. Manipula la vista previa de tus archivos para que se ajusten perfectamente a tu aplicación. Todos los archivos se escanean con ClamAV y se almacenan de manera segura y cifrada.
- [**Funciones**](https://appwrite.io/docs/server/functions) - Personaliza tu servidor de Appwrite ejecutando tu código personalizado en un entorno seguro y aislado. Puedes desencadenar tu código en cualquier evento del sistema de Appwrite, ya sea manualmente o utilizando una programación CRON.
- [**Tiempo real**](https://appwrite.io/docs/realtime) - Escucha eventos en tiempo real para cualquiera de los servicios de Appwrite, incluyendo usuarios, almacenamiento, funciones, bases de datos y más.
- [**Ubicación**](https://appwrite.io/docs/references/cloud/client-web/locale) - Realiza un seguimiento de la ubicación de tu usuario y gestiona los datos de la aplicación basados en la ubicación.
- [**Avatares**](https://appwrite.io/docs/references/cloud/client-web/avatars) - Gestiona los avatares de tus usuarios, las banderas de los países, los iconos del navegador y los símbolos de las tarjetas de crédito. Genera códigos QR a partir de enlaces o cadenas de texto sin formato.

Para obtener la documentación completa de la API, visita [https://appwrite.io/docs](https://appwrite.io/docs). Para obtener más tutoriales, noticias y anuncios, consulta nuestro [blog](https://medium.com/appwrite-io) y nuestro [servidor de Discord](https://discord.gg/GSeTUeA).

### SDKs

A continuación se muestra una lista de plataformas y lenguajes actualmente admitidos. Si deseas ayudarnos a agregar soporte para tu plataforma de elección, puedes visitar nuestro proyecto [Generador de SDK](https://github.com/appwrite/sdk-generator) y consultar nuestra [guía de contribución](https://github.com/appwrite/sdk-generator/blob/master/CONTRIBUTING.md).

#### Cliente

- ✅ &nbsp; [Web](https://github.com/appwrite/sdk-for-web) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Flutter](https://github.com/appwrite/sdk-for-flutter) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) - **Beta** (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Android](https://github.com/appwrite/sdk-for-android) (Mantenido por el equipo de Appwrite)

#### Servidor

- ✅ &nbsp; [NodeJS](https://github.com/appwrite/sdk-for-node) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [PHP](https://github.com/appwrite/sdk-for-php) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Dart](https://github.com/appwrite/sdk-for-dart) - (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Deno](https://github.com/appwrite/sdk-for-deno) - **Beta** (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Ruby](https://github.com/appwrite/sdk-for-ruby) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Python](https://github.com/appwrite/sdk-for-python) (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Kotlin](https://github.com/appwrite/sdk-for-kotlin) - **Beta** (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) - **Beta** (Mantenido por el equipo de Appwrite)
- ✅ &nbsp; [.NET](https://github.com/appwrite/sdk-for-dotnet) - **Experimental** (Mantenido por el equipo de Appwrite)

#### Comunidad

- ✅ &nbsp; [Appcelerator Titanium](https://github.com/m1ga/ti.appwrite) (Mantenido por [Michael Gangolf](https://github.com/m1ga/))
- ✅ &nbsp; [Godot Engine](https://github.com/GodotNuts/appwrite-sdk) (Mantenido por [fenix-hub @GodotNuts](https://github.com/fenix-hub))

¿Buscas más SDKs? - ¡Ayúdanos contribuyendo con una solicitud de extracción a nuestro [Generador de SDK](https://github.com/appwrite/sdk-generator)!

## Arquitectura

![Arquitectura de Appwrite](docs/specs/overview.drawio.svg)

Appwrite utiliza una arquitectura de microservicios diseñada para escalar fácilmente y delegar responsabilidades. Además, Appwrite admite múltiples API, como REST, WebSocket y GraphQL, para permitirte interactuar con tus recursos aprovechando tus conocimientos y protocolos existentes.

La capa de API de Appwrite fue diseñada para ser extremadamente rápida al aprovechar el almacenamiento en caché en memoria y delegar tareas de gran carga a los trabajadores en segundo plano de Appwrite. Los trabajadores en segundo plano también te permiten controlar con precisión tu capacidad de cómputo y costos mediante una cola de mensajes para manejar la carga. Puedes obtener más información sobre nuestra arquitectura en la [guía de contribución](CONTRIBUTING.md#architecture-1).

## Contribución

Todas las contribuciones de código, incluidas las de personas con acceso de confirmación, deben pasar por una solicitud de extracción y ser aprobadas por un desarrollador principal antes de fusionarse. Esto garantiza una revisión adecuada de todo el código.

¡Realmente ❤️ las solicitudes de extracción! Si deseas ayudar, puedes obtener más información sobre cómo puedes contribuir a este proyecto en la [guía de contribución](CONTRIBUTING.md).

## Seguridad

Para problemas de seguridad, por favor envíanos un correo electrónico a [security@appwrite.io](mailto:security@appwrite.io) en lugar de publicar un problema público en GitHub.

## Síguenos

¡Únete a nuestra creciente comunidad en todo el mundo! Echa un vistazo a nuestro [Blog oficial](https://medium.com/appwrite-io). Síguenos en [Twitter](https://twitter.com/appwrite), [Página de Facebook](https://www.facebook.com/appwrite.io), [Grupo de Facebook](https://www.facebook.com/groups/appwrite.developers/), [Dev Community](https://dev.to/appwrite) o únete a nuestro [servidor Discord en vivo](https://discord.gg/GSeTUeA) para obtener más ayuda, ideas y discusiones.

## Licencia

Este repositorio está disponible bajo la [Licencia BSD de 3 Cláusulas](./LICENSE).
