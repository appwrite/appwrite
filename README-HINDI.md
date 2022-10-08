> बादल छाए रहेंगे!! 🌩 ☂️
> The Appwrite Cloud जल्द ही आ रहा है! आप हमारे आगामी होस्टेड समाधान के बारे में अधिक जान सकते हैं और मुफ्त क्रेडिट के लिए साइनअप कर सकते हैं: https://appwrite.io/cloud

<br />
<p align="center">
    <a href="https://appwrite.io" target="_blank"><img width="260" height="39" src="https://appwrite.io/images/appwrite.svg" alt="Appwrite Logo"></a>
    <br />
    <br />
    <b> आपके लिए एक पूर्ण बैकएंड समाधान [Flutter / Vue / Angular / React / iOS / Android /  *कोई और* ] app के लिए</b>
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

[English](README.md) | [简体中文](README-CN.md) | [हिन्दी](README-HINDI.md)

[**Appwrite 1.0 has been released! Learn what's new!**](https://appwrite.io/1.0)

Appwrite ये एक end-to-end backend server है जिसका Web, Mobile, Native, or Backend apps packaged as a set of Docker<nobr> microservices आदि मैं उपयोग होता है. Appwrite प्रारंभ से आधुनिक बैकएंड एपीआई बनाने के लिए आवश्यक जटिलता और दोहराव को सारगर्भित करता है और आपको तेजी से सुरक्षित ऐप्स बनाने की अनुमति देता है.

Appwrite का उपयोग करते हुए, आप आसानी से अपने ऐप को उपयोगकर्ता प्रमाणीकरण और एकाधिक साइन-इन विधियों, उपयोगकर्ताओं और टीम डेटा को संग्रहीत करने और क्वेरी करने के लिए एक डेटाबेस, भंडारण और फ़ाइल प्रबंधन, छवि हेरफेर, क्लाउड फ़ंक्शंस के साथ एकीकृत कर सकते हैं  तथा [अधिक सेवाएं](https://appwrite.io/docs).

<p align="center">
    <br />
    <a href="https://www.producthunt.com/posts/appwrite-2?utm_source=badge-top-post-badge&utm_medium=badge&utm_souce=badge-appwrite-2" target="_blank"><img src="https://api.producthunt.com/widgets/embed-image/v1/top-post-badge.svg?post_id=360315&theme=light&period=daily" alt="Appwrite - 100&#0037;&#0032;open&#0032;source&#0032;alternative&#0032;for&#0032;Firebase | Product Hunt" style="width: 250px; height: 54px;" width="250" height="54" /></a>
    <br />
    <br />
</p>

![Appwrite](public/images/github.png)

और अधिक जानकारी प्राप्त करें : [https://appwrite.io](https://appwrite.io)

विषयसूची:

- [Installation](#installation)
  - [Unix](#unix)
  - [Windows](#windows)
    - [CMD](#cmd)
    - [PowerShell](#powershell)
  - [Upgrade from an Older Version](#upgrade-from-an-older-version)
- [Getting Started](#getting-started)
  - [Services](#services)
  - [SDKs](#sdks)
    - [Client](#client)
    - [Server](#server)
    - [Community](#community)
- [Architecture](#architecture)
- [Contributing](#contributing)
- [Security](#security)
- [Follow Us](#follow-us)
- [License](#license)

## Installation / स्थापित करना

Appwrite backend server एक कंटेनर वातावरण में चलाने के लिए डिज़ाइन किया गया है.आप या तो  Appwrite को आपके localhost पर  docker-compose या किसी अन्य container orchestration tool जैसे Kubernetes, Docker Swarm, or Rancher की मदद से run कर सकते हैं . 

सबसे आसान तरीका आपके Appwrite server को run करनेका है हमारी docker-compose file. इंस्टालेशन कमांड चलाने से पहले, आप सुनिश्चित करें कि आपके पास [Docker](https://www.docker.com/products/docker-desktop) आपकी मशीन पर स्थापित है:

### Unix

```bash
docker run -it --rm \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite:1.0.2
```

### Windows

#### CMD

```cmd
docker run -it --rm ^
    --volume //var/run/docker.sock:/var/run/docker.sock ^
    --volume "%cd%"/appwrite:/usr/src/code/appwrite:rw ^
    --entrypoint="install" ^
    appwrite/appwrite:1.0.2
```

#### PowerShell

```powershell
docker run -it --rm `
    --volume /var/run/docker.sock:/var/run/docker.sock `
    --volume ${pwd}/appwrite:/usr/src/code/appwrite:rw `
    --entrypoint="install" `
    appwrite/appwrite:1.0.2
```

एक बार डॉकर इंस्टॉलेशन पूरा हो जाने पर,अपने ब्राउज़र से Appwrite कंसोल तक पहुँचने के लिए के लिए जाओ http://localhost पर. कृपया ध्यान दें कि non-Linux native hosts पर, स्थापना पूर्ण होने के बाद सर्वर को शुरू होने में कुछ मिनट लग सकते हैं.

उन्नत उत्पादन और कस्टम स्थापना के लिए, हमारे विवरण यहां देखें Docker [environment variables](https://appwrite.io/docs/environment-variables) docs. आप हमारी जनता का भी उपयोग कर सकते हैं [docker-compose.yml](https://appwrite.io/install/compose) और [.env](https://appwrite.io/install/env) फ़ाइलों को मैन्युअल रूप से एक वातावरण स्थापित करने के लिए.

### Upgrade from an Older Version / पुराने संस्करण से अपग्रेड करें

If you are upgrading your Appwrite server from an older version, you should use the Appwrite migration tool once your setup is completed. For more information regarding this, check out the [Installation Docs](https://appwrite.io/docs/installation).

## One-Click Setups

स्थानीय रूप से Appwrite चलाने के अलावा, आप पूर्व-कॉन्फ़िगर किए गए सेटअप का उपयोग करके Appwrite भी लॉन्च कर सकते हैं। यह आपको अपने स्थानीय मशीन पर डॉकर को स्थापित किए बिना जल्दी से ऐप्राइट के साथ उठने और चलने की अनुमति देता है।

नीचे दिए गए प्रदाताओं में से किसी एक को चुनें:

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

## Getting Started / शुरू करना

Appwrite के साथ शुरुआत करना उतना ही आसान है जितना कि एक नया प्रोजेक्ट बनाना, अपना प्लेटफॉर्म चुनना और अपने एसडीके को अपने कोड में एकीकृत करना। आप हमारे गेटिंग स्टार्टेड ट्यूटोरियल्स में से किसी एक को पढ़कर आसानी से अपनी पसंद के प्लेटफॉर्म के साथ शुरुआत कर सकते हैं।

- [Getting Started for Web](https://appwrite.io/docs/getting-started-for-web)
- [Getting Started for Flutter](https://appwrite.io/docs/getting-started-for-flutter)
- [Getting Started for Apple](https://appwrite.io/docs/getting-started-for-apple)
- [Getting Started for Android](https://appwrite.io/docs/getting-started-for-android)
- [Getting Started for Server](https://appwrite.io/docs/getting-started-for-server)
- [Getting Started for CLI](https://appwrite.io/docs/command-line)

### Services / सेवाएं

- [**Account**](https://appwrite.io/docs/client/account) - वर्तमान उपयोगकर्ता प्रमाणीकरण और खाता प्रबंधित करें। उपयोगकर्ता सत्रों, उपकरणों, साइन-इन विधियों और सुरक्षा लॉग को ट्रैक और प्रबंधित करें.
- [**Users**](https://appwrite.io/docs/server/users) - व्यवस्थापक मोड में होने पर सभी प्रोजेक्ट उपयोगकर्ताओं को प्रबंधित और सूचीबद्ध करें.
- [**Teams**](https://appwrite.io/docs/client/teams) - टीमों में उपयोगकर्ताओं को प्रबंधित और समूहित करें। एक टीम के भीतर सदस्यता, आमंत्रण और उपयोगकर्ता भूमिकाएं प्रबंधित करें.
- [**Databases**](https://appwrite.io/docs/client/databases) डेटाबेस, संग्रह और दस्तावेज़ प्रबंधित करें। दस्तावेज़ों को पढ़ें, बनाएं, अपडेट करें, और हटाएं और उन्नत फ़िल्टर का उपयोग करके दस्तावेज़ संग्रह की फ़िल्टर सूचियाँ.
- [**Storage**](https://appwrite.io/docs/client/storage) - Storage फ़ाइलें प्रबंधित करें। फ़ाइलें पढ़ें, बनाएं, हटाएं और पूर्वावलोकन करें। अपने ऐप को पूरी तरह से फिट करने के लिए अपनी फाइलों के पूर्वावलोकन में हेरफेर करें। क्लैमएवी द्वारा सभी फाइलों को स्कैन किया जाता है और सुरक्षित और एन्क्रिप्टेड तरीके से संग्रहीत किया जाता है.
- [**Functions**](https://appwrite.io/docs/server/functions) - एक सुरक्षित, अलग वातावरण में अपना कस्टम कोड निष्पादित करके अपने Appwrite Service को अनुकूलित करें। आप अपने कोड को किसी भी ऐप्राइट सिस्टम ईवेंट पर मैन्युअल रूप से या CRON शेड्यूल का उपयोग करके ट्रिगर कर सकते हैं.
- [**Realtime**](https://appwrite.io/docs/realtime) - उपयोगकर्ताओं, भंडारण, कार्यों, डेटाबेस और अधिक सहित अपनी किसी भी ऐप्राइट सेवाओं के लिए रीयल-टाइम ईवेंट सुनें.
- [**Locale**](https://appwrite.io/docs/client/locale) - अपने उपयोगकर्ता के स्थान को ट्रैक करें, और अपना ऐप लोकेल-आधारित डेटा प्रबंधित करें.
- [**Avatars**](https://appwrite.io/docs/client/avatars) - अपने उपयोगकर्ताओं के अवतार, देशों के झंडे, ब्राउज़र आइकन, क्रेडिट कार्ड के प्रतीकों को प्रबंधित करें और क्यूआर कोड उत्पन्न करें.

संपूर्ण API दस्तावेज़ीकरण के लिए, देखे [https://appwrite.io/docs](https://appwrite.io/docs). अधिक ट्यूटोरियल, समाचार और घोषणाओं के लिए हमारे  [blog](https://medium.com/appwrite-io) और [Discord Server](https://discord.gg/GSeTUeA) देखें.

### SDKs



नीचे वर्तमान में समर्थित प्लेटफॉर्म और भाषाओं की सूची दी गई है. यदि आप अपनी पसंद के प्लेटफॉर्म पर समर्थन जोड़ने में हमारी मदद करना चाहते हैं, आप हमारे  [SDK Generator](https://github.com/appwrite/sdk-generator) project और देखो हमारा [contribution guide](https://github.com/appwrite/sdk-generator/blob/master/CONTRIBUTING.md) जा सकते हैं.

#### Client

- ✅ &nbsp; [Web](https://github.com/appwrite/sdk-for-web) (Maintained by the Appwrite Team)
- ✅ &nbsp; [Flutter](https://github.com/appwrite/sdk-for-flutter) (Maintained by the Appwrite Team)
- ✅ &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) - **Beta** (Maintained by the Appwrite Team)
- ✅ &nbsp; [Android](https://github.com/appwrite/sdk-for-android) (Maintained by the Appwrite Team)

#### Server

- ✅ &nbsp; [NodeJS](https://github.com/appwrite/sdk-for-node) (Maintained by the Appwrite Team)
- ✅ &nbsp; [PHP](https://github.com/appwrite/sdk-for-php) (Maintained by the Appwrite Team)
- ✅ &nbsp; [Dart](https://github.com/appwrite/sdk-for-dart) - (Maintained by the Appwrite Team)
- ✅ &nbsp; [Deno](https://github.com/appwrite/sdk-for-deno) - **Beta** (Maintained by the Appwrite Team)
- ✅ &nbsp; [Ruby](https://github.com/appwrite/sdk-for-ruby) (Maintained by the Appwrite Team)
- ✅ &nbsp; [Python](https://github.com/appwrite/sdk-for-python) (Maintained by the Appwrite Team)
- ✅ &nbsp; [Kotlin](https://github.com/appwrite/sdk-for-kotlin) - **Beta** (Maintained by the Appwrite Team)
- ✅ &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) - **Beta** (Maintained by the Appwrite Team)
- ✅ &nbsp; [.NET](https://github.com/appwrite/sdk-for-dotnet) - **Experimental** (Maintained by the Appwrite Team)

#### Community

- ✅ &nbsp; [Appcelerator Titanium](https://github.com/m1ga/ti.appwrite) (Maintained by [Michael Gangolf](https://github.com/m1ga/))
- ✅ &nbsp; [Godot Engine](https://github.com/GodotNuts/appwrite-sdk) (Maintained by [fenix-hub @GodotNuts](https://github.com/fenix-hub))

Looking for more SDKs? - Help us by contributing a pull request to our [SDK Generator](https://github.com/appwrite/sdk-generator)!

## Architecture

![Appwrite Architecture](docs/specs/overview.drawio.svg)

Appwrite uses a microservices architecture that was designed for easy scaling and delegation of responsibilities. In addition, Appwrite supports multiple APIs (REST, WebSocket, and GraphQL-soon) to allow you to interact with your resources by leveraging your existing knowledge and protocols of choice.

The Appwrite API layer was designed to be extremely fast by leveraging in-memory caching and delegating any heavy-lifting tasks to the Appwrite background workers. The background workers also allow you to precisely control your compute capacity and costs using a message queue to handle the load. You can learn more about our architecture in the [contribution guide](CONTRIBUTING.md#architecture-1).

## Contributing

सभी कोड योगदानों के लिए - जिसमें वे लोग भी शामिल हैं जिनके पास  commit access है -  एक पुल अनुरोध के माध्यम से जाना चाहिए और विलय होने से पहले एक core developer द्वारा अनुमोदित होना चाहिए। यह सभी code की उचित समीक्षा सुनिश्चित करने के लिए है।.

हम सच में pull requests से ❤️  है! यदि आप मदद करना चाहते हैं, तो आप इस बारे में अधिक जान सकते हैं कि आप इस परियोजना में कैसे योगदान कर सकते हैं[contribution guide](CONTRIBUTING.md).

## Security

सुरक्षा मुद्दों के लिए, कृपया हमें यहां ईमेल करें [security@appwrite.io](mailto:security@appwrite.io) GitHub पर एक सार्वजनिक मुद्दा पोस्ट करने के बजाय.

## Follow Us

दुनिया भर में हमारे बढ़ते समुदाय में शामिल हों ! हमारे अधिकृत links देखें  [Blog](https://medium.com/appwrite-io). Follow us on [Twitter](https://twitter.com/appwrite), [Facebook Page](https://www.facebook.com/appwrite.io), [Facebook Group](https://www.facebook.com/groups/appwrite.developers/) , [Dev Community](https://dev.to/appwrite) or join our live [Discord server](https://discord.gg/GSeTUeA) for more help, ideas, and discussions.

## License


यह Repository [BSD 3-Clause License](./LICENSE)  के तहत उपलब्ध है.
