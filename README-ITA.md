> Sta per diventare nuvoloso! 🌩 ☂️
> L'Appwrite Cloud è in arrivo! Puoi saperne di più sulla nostra prossima soluzione in hosting e registrarti per crediti gratuiti all'indirizzo: https://appwrite.io/cloud

<br />
<p align="center">
    <a href="https://appwrite.io" target="_blank"><img width="260" height="39" src="https://appwrite.io/images/appwrite.svg" alt="Appwrite Logo"></a>
    <br />
    <br />
    <b>Una soluzione back-end completa per il tuo [Flutter / Vue / Angular / React / iOS / Android / *
QUALSIASI ALTRO*] app</b>
    <br />
    <br />
</p>


<!-- [![Build Status](https://img.shields.io/travis/com/appwrite/appwrite?style=flat-square)](https://travis-ci.com/appwrite/appwrite) -->

[![Hacktoberfest](https://img.shields.io/static/v1?label=hacktoberfest&message=ready&color=191120&style=flat-square)](https://hacktoberfest.appwrite.io)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord&style=flat-square)](https://appwrite.io/discord?r=Github)
[![Build Status](https://img.shields.io/github/workflow/status/appwrite/appwrite/Tests?label=tests&style=flat-square)](https://github.com/appwrite/appwrite/actions)
[![Twitter Account](https://img.shields.io/twitter/follow/appwrite?color=00acee&label=twitter&style=flat-square)](https://twitter.com/appwrite)

<!-- [![Docker Pulls](https://img.shields.io/docker/pulls/appwrite/appwrite?color=f02e65&style=flat-square)](https://hub.docker.com/r/appwrite/appwrite) -->
<!-- [![Translate](https://img.shields.io/badge/translate-f02e65?style=flat-square)](docs/tutorials/add-translations.md) -->
<!-- [![Swag Store](https://img.shields.io/badge/swag%20store-f02e65?style=flat-square)](https://store.appwrite.io) -->

[English](README.md) | [简体中文](README-CN.md) | Italiana

[**Appwrite 1.0 è stato rilasciato! Scopri cosa c'è di nuovo!**](https://appwrite.io/1.0)

Appwrite è un server back-end end-to-end per app Web, Mobili, Native o back-end in un pacchetto di microservizi Docker<nobr>. Appwrite astrae la complessità e la ripetitività necessarie per creare da zero una moderna API back-end e ti consente di creare app sicure più velocemente.

Utilizzando Appwrite, puoi integrare facilmente la tua app con l'autenticazione utente e metodi di accesso multipli, un database per archiviare e interrogare utenti e dati del team, archiviazione e gestione dei file, manipolazione delle immagini, funzioni Cloud e [altri servizi](https://appwrite.io/docs).

<p align="center">
    <br />
    <a href="https://www.producthunt.com/posts/appwrite-2?utm_source=badge-top-post-badge&utm_medium=badge&utm_souce=badge-appwrite-2" target="_blank"><img src="https://api.producthunt.com/widgets/embed-image/v1/top-post-badge.svg?post_id=360315&theme=light&period=daily" alt="Appwrite - 100&#0037;&#0032;open&#0032;source&#0032;alternative&#0032;for&#0032;Firebase | Product Hunt" style="width: 250px; height: 54px;" width="250" height="54" /></a>
    <br />
    <br />
</p>

![Appwrite](public/images/github.png)

Scopri di più su: [https://appwrite.io](https://appwrite.io)

Tabella dei Contenuti:

- [Installazione](#installazione)
  - [Unix](#unix)
  - [Windows](#windows)
    - [CMD](#cmd)
    - [PowerShell](#powershell)
  - [Aggiornamento da una Versione Precedente](#aggiornamento-da-una-versione-precedente)
- [Iniziare](#iniziare)
  - [Servizi](#services)
  - [SDKs](#sdks)
    - [Cliente](#cliente)
    - [Server](#server)
    - [Comunità](#comunità)
- [Architettura](#architettura)
- [Contribuendo](#contribuendo)
- [Sicurezza](#sicurezza)
- [Seguici](#seguici)
- [Licenza](#licenza)

## Installazione

Il server back-end di Appwrite è progettato per essere eseguito in un ambiente container. Eseguire il tuo server è facile come eseguire un comando dal tuo terminale. Puoi eseguire Appwrite sul tuo localhost usando docker-compose o su qualsiasi altro strumento di orchestrazione di container come Kubernetes, Docker Swarm o Rancher.

Il modo più semplice per iniziare a eseguire il server Appwrite è eseguire il nostro file di composizione Docker. Prima di eseguire il comando di installazione, assicurati di averlo [Docker](https://www.docker.com/products/docker-desktop) installato sul tuo computer:

### Unix

```bash
docker run -it --rm \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite:1.0.3
```

### Windows

#### CMD

```cmd
docker run -it --rm ^
    --volume //var/run/docker.sock:/var/run/docker.sock ^
    --volume "%cd%"/appwrite:/usr/src/code/appwrite:rw ^
    --entrypoint="install" ^
    appwrite/appwrite:1.0.3
```

#### PowerShell

```powershell
docker run -it --rm `
    --volume /var/run/docker.sock:/var/run/docker.sock `
    --volume ${pwd}/appwrite:/usr/src/code/appwrite:rw `
    --entrypoint="install" `
    appwrite/appwrite:1.0.3
```

Una volta completata l'installazione di Docker, vai su http://localhost per accedere alla console di Appwrite dal tuo browser. Si noti che su host nativi non Linux, l'avvio del server potrebbe richiedere alcuni minuti al termine dell'installazione.

Per la produzione avanzata e l'installazione personalizzata, dai un'occhiata al nostro Docker [environment variables](https://appwrite.io/docs/environment-variables) docs. Puoi anche utilizzare il nostro pubblico [docker-compose.yml](https://appwrite.io/install/compose) e [.env](https://appwrite.io/install/env) file per configurare manualmente un ambiente.

### Aggiornamento da una Versione Precedente

Se stai aggiornando il tuo server Appwrite da una versione precedente, dovresti utilizzare lo strumento di migrazione Appwrite una volta completata la configurazione. Per ulteriori informazioni in merito, controlla il [Installation Docs](https://appwrite.io/docs/installation).

## Un-Click Impostare

Oltre a eseguire Appwrite in locale, puoi anche avviare Appwrite utilizzando una configurazione preconfigurata. Ciò ti consente di iniziare rapidamente a utilizzare Appwrite senza installare Docker sul tuo computer locale.

Scegli tra uno dei fornitori di seguito:

<table border="0">
  <tr>
    <td align="center" width="100" height="100">
      <a href="https://marketplace.digitalocean.com/apps/appwrite">
        <img width="50" height="39" src="public/images/integrations/digitalocean-logo.svg" alt="DigitalOcean Logo" />
          <br /><sub><b>DigitalOcean</b></sub></a>
        </a>
    </td>
    <td align="center" width="100" height="100">
      <a href="https://gitpod.io/#https://github.com/appwrite/integration-for-gitpod">
        <img width="50" height="39" src="public/images/integrations/gitpod-logo.svg" alt="Gitpod Logo" />
          <br /><sub><b>Gitpod</b></sub></a>    
      </a>
    </td>
  </tr>
</table>

## Iniziare

Iniziare con Appwrite è facile come creare un nuovo progetto, scegliere la piattaforma e integrare il relativo SDK nel codice. Puoi iniziare facilmente con la tua piattaforma preferita leggendo uno dei nostri tutorial per iniziare.

- [Guida introduttiva al Web](https://appwrite.io/docs/getting-started-for-web)
- [Guida introduttiva al Flutter](https://appwrite.io/docs/getting-started-for-flutter)
- [Guida introduttiva al Apple](https://appwrite.io/docs/getting-started-for-apple)
- [Guida introduttiva al Android](https://appwrite.io/docs/getting-started-for-android)
- [Guida introduttiva al Server](https://appwrite.io/docs/getting-started-for-server)
- [Guida introduttiva al CLI](https://appwrite.io/docs/command-line)

### Servizi

- [**Account**](https://appwrite.io/docs/client/account) - Gestire l'autenticazione e l'account dell'utente corrente. Traccia e gestisci sessioni utente, dispositivi, metodi di accesso e registri di sicurezza.
- [**Utenti**](https://appwrite.io/docs/server/users) - Gestisci ed elenca tutti gli utenti del progetto durante la creazione di integrazioni back-end con Server SDK.
- [**Squadre**](https://appwrite.io/docs/client/teams) - Gestisci e raggruppa gli utenti in team. Gestisci iscrizioni, inviti e ruoli utente all'interno di un team.
- [**Banche dati**](https://appwrite.io/docs/client/databases) - Gestire database, raccolte e documenti. Leggere, creare, aggiornare ed eliminare documenti e filtrare elenchi di raccolte di documenti utilizzando filtri avanzati.
- [**Magazzino**](https://appwrite.io/docs/client/storage) - Gestisci i file di archiviazione. Leggere, creare, eliminare e visualizzare in anteprima i file. Manipola l'anteprima dei tuoi file per adattarla perfettamente alla tua app. Tutti i file vengono scansionati da ClamAV e archiviati in modo sicuro e crittografato.
- [**Funzioni**](https://appwrite.io/docs/server/functions) - Personalizza il tuo server Appwrite eseguendo il tuo codice personalizzato in un ambiente sicuro e isolato. Puoi attivare il tuo codice su qualsiasi evento del sistema Appwrite, manualmente o utilizzando una pianificazione CRON.
- [**Tempo reale**](https://appwrite.io/docs/realtime) - Ascolta gli eventi in tempo reale per tutti i tuoi servizi Appwrite inclusi utenti, archiviazione, funzioni, database e altro ancora.
- [**Locale**](https://appwrite.io/docs/client/locale) - Tieni traccia della posizione dell'utente e gestisci i dati basati sulla localizzazione dell'app.
- [**Avatar**](https://appwrite.io/docs/client/avatars) - Gestisci gli avatar dei tuoi utenti, le bandiere dei paesi, le icone del browser, i simboli delle carte di credito e genera i codici QR.

Per la documentazione completa dell'API, visitare [https://appwrite.io/docs](https://appwrite.io/docs). Per ulteriori tutorial, notizie e annunci dai un'occhiata al nostro [blog](https://medium.com/appwrite-io) e [Discord Server](https://discord.gg/GSeTUeA).

### SDKs

Di seguito è riportato un elenco delle piattaforme e delle lingue attualmente supportate. Se desideri aiutarci ad aggiungere supporto alla tua piattaforma preferita, puoi passare al nostro [SDK Generator](https://github.com/appwrite/sdk-generator) progetto e visualizzare il nostro [contribution guide](https://github.com/appwrite/sdk-generator/blob/master/CONTRIBUTING.md).

#### Cliente

- ✅ &nbsp; [Web](https://github.com/appwrite/sdk-for-web) (Gestito dal team di Appwrite)
- ✅ &nbsp; [Flutter](https://github.com/appwrite/sdk-for-flutter) (Gestito dal team di Appwrite)
- ✅ &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) - **Beta** (Gestito dal team di Appwrite)
- ✅ &nbsp; [Android](https://github.com/appwrite/sdk-for-android) (Gestito dal team di Appwrite)

#### Server

- ✅ &nbsp; [NodeJS](https://github.com/appwrite/sdk-for-node) (Gestito dal team di Appwrite)
- ✅ &nbsp; [PHP](https://github.com/appwrite/sdk-for-php) (Gestito dal team di Appwrite)
- ✅ &nbsp; [Dart](https://github.com/appwrite/sdk-for-dart) - (Gestito dal team di Appwrite)
- ✅ &nbsp; [Deno](https://github.com/appwrite/sdk-for-deno) - **Beta** (Gestito dal team di Appwrite)
- ✅ &nbsp; [Ruby](https://github.com/appwrite/sdk-for-ruby) (Gestito dal team di Appwrite)
- ✅ &nbsp; [Python](https://github.com/appwrite/sdk-for-python) (Gestito dal team di Appwrite)
- ✅ &nbsp; [Kotlin](https://github.com/appwrite/sdk-for-kotlin) - **Beta** (Gestito dal team di Appwrite)
- ✅ &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) - **Beta** (Gestito dal team di Appwrite)
- ✅ &nbsp; [.NET](https://github.com/appwrite/sdk-for-dotnet) - **Experimental** (Gestito dal team di Appwrite)

#### Comunità

- ✅ &nbsp; [Appcelerator Titanium](https://github.com/m1ga/ti.appwrite) (Gestito dal [Michael Gangolf](https://github.com/m1ga/))
- ✅ &nbsp; [Godot Engine](https://github.com/GodotNuts/appwrite-sdk) (Gestito dal [fenix-hub @GodotNuts](https://github.com/fenix-hub))

Alla ricerca di più SDKs? - Aiutaci contribuendo con una richiesta pull al nostro [SDK Generator](https://github.com/appwrite/sdk-generator)!

## Architettura

![Appwrite Architecture](docs/specs/overview.drawio.svg)

Appwrite usa un'architettura di microservizi progettata per una facile scalabilità e delega di responsabilità. Inoltre, Appwrite supporta più API (REST, WebSocket e GraphQL-soon) per consentirti di interagire con le tue risorse sfruttando le tue conoscenze esistenti e i protocolli di tua scelta.

Il livello dell'API di Appwrite è stato progettato per essere estremamente veloce sfruttando la memorizzazione nella cache in memoria e delegando qualsiasi attività di sollevamento pesante ai lavoratori in background di Appwrite. Gli operatori in background consentono inoltre di controllare con precisione la capacità di elaborazione e i costi utilizzando una coda di messaggi per gestire il carico. Puoi saperne di più sulla nostra architettura nel [contribution guide](CONTRIBUTING.md#architecture-1).

## Contribuendo

Tutti i contributi al codice, inclusi quelli delle persone che hanno accesso al commit, devono passare attraverso una richiesta pull ed essere approvati da uno sviluppatore principale prima di essere uniti. Questo per garantire una corretta revisione di tutto il codice.

Noi veramente ❤️ tiriamo le richieste! Se desideri aiutare, puoi saperne di più su come puoi contribuire a questo progetto nel [contribution guide](CONTRIBUTING.md).

## Sicurezza

Per problemi di sicurezza, inviaci un'e-mail all'indirizzo [security@appwrite.io](mailto:security@appwrite.io) invece di pubblicare un problema pubblico su GitHub.

## Seguici

Unisciti alla nostra comunità in crescita in tutto il mondo! Vedi il nostro ufficiale [Blog](https://medium.com/appwrite-io). Seguici su [Twitter](https://twitter.com/appwrite), [Facebook Page](https://www.facebook.com/appwrite.io), [Facebook Group](https://www.facebook.com/groups/appwrite.developers/), [Dev Community](https://dev.to/appwrite) o unisciti al nostro live [Discord server](https://discord.gg/GSeTUeA) per ulteriore aiuto, idee e discussioni.

## Licenza

Questo repository è disponibile sotto il [BSD 3-Clause License](./LICENSE).
