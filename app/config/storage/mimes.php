<?php

return [
    "image/jpeg",
    "image/jpeg",
    "image/gif",
    "image/png",
    "image/webp",
    "image/heic",
    "image/heic-sequence",
    "image/avif",

    // Video Files
    "video/mp4",
    "video/x-flv",
    "video/webm",
    "application/x-mpegURL",
    "video/MP2T",
    "video/3gpp",
    "video/quicktime",
    "video/x-msvideo",
    "video/x-ms-wmv",

    // Audio Files

    "audio/basic", // au snd RFC 2046
    "auido/L24", // Linear PCM RFC 3190
    "audio/mid", // mid rmi
    "audio/mpeg", // mp3 RFC 3003
    "audio/mp4", // mp4 audio
    "audio/x-aiff", // aif aifc aiff
    "audio/x-mpegurl", // m3u
    "audio/vnd.rn-realaudio", // ra ram
    "audio/ogg", // Ogg Vorbis RFC 5334
    "audio/vorbis", // Vorbis RFC 5215
    "audio/vnd.wav", // wav RFC 2361
    "audio/x-wav", // php reads .wav as this - https://svn.apache.org/repos/asf/httpd/httpd/trunk/docs/conf/mime.types
    "audio/aac", //AAC audio
    "audio/x-hx-aac-adts", // AAC audio

    // Microsoft Word
    "application/msword",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.template",
    "application/vnd.ms-word.document.macroEnabled.12",

    // Microsoft Excel
    "application/vnd.ms-excel",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.template",
    "application/vnd.ms-excel.sheet.macroEnabled.12",
    "application/vnd.ms-excel.template.macroEnabled.12",
    "application/vnd.ms-excel.addin.macroEnabled.12",
    "application/vnd.ms-excel.sheet.binary.macroEnabled.12",

    // Microsoft Power Point
    "application/vnd.ms-powerpoint",
    "application/vnd.openxmlformats-officedocument.presentationml.presentation",
    "application/vnd.openxmlformats-officedocument.presentationml.template",
    "application/vnd.openxmlformats-officedocument.presentationml.slideshow",
    "application/vnd.ms-powerpoint.addin.macroEnabled.12",
    "application/vnd.ms-powerpoint.presentation.macroEnabled.12",
    "application/vnd.ms-powerpoint.template.macroEnabled.12",
    "application/vnd.ms-powerpoint.slideshow.macroEnabled.12",

    // Microsoft Access
    "application/vnd.ms-access",

    // Adobe PDF
    "application/pdf",
];
