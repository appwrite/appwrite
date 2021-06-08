// Include gulp
const { src, dest, series } = require('gulp');

// Plugins
const gulpConcat = require('gulp-concat');
const gulpJsmin = require('gulp-jsmin');
const gulpLess = require('gulp-less');
const gulpCleanCSS = require('gulp-clean-css');

// Config

const configApp  = {
    mainFile: 'app.js',
    src: [
        'public/scripts/dependencies/litespeed.js',

        'public/scripts/init.js',

        'public/scripts/services/alerts.js',
        'public/scripts/services/api.js',
        'public/scripts/services/console.js',
        'public/scripts/services/date.js',
        'public/scripts/services/env.js',
        'public/scripts/services/form.js',
        'public/scripts/services/markdown.js',
        'public/scripts/services/rtl.js',
        'public/scripts/services/sdk.js',
        'public/scripts/services/search.js',
        'public/scripts/services/timezone.js',
        'public/scripts/services/realtime.js',

        'public/scripts/routes.js',
        'public/scripts/filters.js',
        'public/scripts/app.js',

        'public/scripts/views/service.js',

        'public/scripts/views/analytics/event.js',
        'public/scripts/views/analytics/activity.js',
        'public/scripts/views/analytics/pageview.js',

        'public/scripts/views/forms/clone.js',
        'public/scripts/views/forms/add.js',
        'public/scripts/views/forms/chart.js',
        'public/scripts/views/forms/code.js',
        'public/scripts/views/forms/color.js',
        'public/scripts/views/forms/copy.js',
        'public/scripts/views/forms/document.js',
        'public/scripts/views/forms/document-preview.js',
        'public/scripts/views/forms/filter.js',
        'public/scripts/views/forms/headers.js',
        'public/scripts/views/forms/key-value.js',
        'public/scripts/views/forms/move-down.js',
        'public/scripts/views/forms/move-up.js',
        'public/scripts/views/forms/nav.js',
        'public/scripts/views/forms/oauth-apple.js',
        'public/scripts/views/forms/password-meter.js',
        'public/scripts/views/forms/pell.js',
        'public/scripts/views/forms/remove.js',
        'public/scripts/views/forms/run.js',
        'public/scripts/views/forms/select-all.js',
        'public/scripts/views/forms/show-secret.js',
        'public/scripts/views/forms/switch.js',
        'public/scripts/views/forms/tags.js',
        'public/scripts/views/forms/text-count.js',
        'public/scripts/views/forms/text-direction.js',
        'public/scripts/views/forms/text-resize.js',
        'public/scripts/views/forms/upload.js',

        'public/scripts/views/general/cookies.js',
        'public/scripts/views/general/page-title.js',
        'public/scripts/views/general/scroll-to.js',
        'public/scripts/views/general/scroll-direction.js',
        'public/scripts/views/general/setup.js',
        'public/scripts/views/general/switch.js',
        'public/scripts/views/general/theme.js',
        'public/scripts/views/general/version.js',
        
        'public/scripts/views/paging/back.js',
        'public/scripts/views/paging/next.js',

        'public/scripts/views/ui/highlight.js',
        'public/scripts/views/ui/loader.js',
        'public/scripts/views/ui/modal.js',
        'public/scripts/views/ui/open.js',
        'public/scripts/views/ui/phases.js',
        'public/scripts/views/ui/trigger.js',
    ],
    dest: './public/dist/scripts'
};

const configDep = {
    mainFile: 'app-dep.js',
    src: [
        //'node_modules/appwrite/src/sdk.js',
        'public/scripts/dependencies/appwrite.js',
        'public/scripts/dependencies/chart.js',
        'public/scripts/dependencies/markdown-it.js',
        'public/scripts/dependencies/pell.js',
        'public/scripts/dependencies/prism.js',
        'public/scripts/dependencies/turndown.js',
    ],
    dest: './public/dist/scripts'
};

const config = {
    mainFile: 'app-all.js',
    src: [
        'public/dist/scripts/app-dep.js',
        'public/dist/scripts/app.js'
    ],
    dest: './public/dist/scripts'
};

function lessLTR () {
    return src('./public/styles/default-ltr.less')
        .pipe(gulpLess())
        .pipe(gulpCleanCSS({compatibility: 'ie8'}))
        .pipe(dest('./public/dist/styles'));
}

function lessRTL () {
    return src('./public/styles/default-rtl.less')
        .pipe(gulpLess())
        .pipe(gulpCleanCSS({compatibility: 'ie8'}))
        .pipe(dest('./public/dist/styles'));
}

function concatApp () {
    return src(configApp.src)
        .pipe(gulpConcat(configApp.mainFile))
        .pipe(gulpJsmin())
        .pipe(dest(configApp.dest));
}

function concatDep () {
    return src(configDep.src)
        .pipe(gulpConcat(configDep.mainFile))
        .pipe(gulpJsmin())
        .pipe(dest(configDep.dest));
}

function concat () {
    return src(config.src)
        .pipe(gulpConcat(config.mainFile))
        .pipe(dest(config.dest));
}

exports.import  = series(concatDep);
exports.less    = series(lessLTR, lessRTL);
exports.build   = series(concatApp, concat);