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

        'public/scripts/sdk.js',

        'public/scripts/init.js',

        'public/scripts/services/alerts.js',
        'public/scripts/services/console.js',
        'public/scripts/services/date.js',
        'public/scripts/services/di.js',
        'public/scripts/services/env.js',
        'public/scripts/services/markdown.js',
        'public/scripts/services/sdk.js',
        'public/scripts/services/timezone.js',

        'public/scripts/routes.js',
        'public/scripts/filters.js',
        'public/scripts/app.js',
        'public/scripts/appwrite.js',

        'public/scripts/views/count.js',
        'public/scripts/views/wait.js',

        'public/scripts/views/analytics/event.js',
        'public/scripts/views/analytics/pageview.js',

        'public/scripts/views/forms/clone.js',
        'public/scripts/views/forms/color.js',
        'public/scripts/views/forms/copy.js',
        'public/scripts/views/forms/draft.js',
        'public/scripts/views/forms/filter.js',
        'public/scripts/views/forms/parent-down.js',
        'public/scripts/views/forms/parent-remove.js',
        'public/scripts/views/forms/parent-up.js',
        'public/scripts/views/forms/password-meter.js',
        'public/scripts/views/forms/pell.js',
        'public/scripts/views/forms/recaptcha.js',
        'public/scripts/views/forms/remove.js',
        'public/scripts/views/forms/switch.js',
        'public/scripts/views/forms/text-count.js',
        'public/scripts/views/forms/text-direction.js',
        'public/scripts/views/forms/text-resize.js',
        'public/scripts/views/forms/upload.js',
        'public/scripts/views/forms/upload-multi.js',

        'public/scripts/views/general/page-title.js',
        'public/scripts/views/general/setup.js',
        'public/scripts/views/general/switch.js',

        'public/scripts/views/ui/gravatar.js',
        'public/scripts/views/ui/highlight.js',
        'public/scripts/views/ui/modal.js',
        'public/scripts/views/ui/open.js',
        'public/scripts/views/ui/paging.js',
        'public/scripts/views/ui/phases.js',
        'public/scripts/views/ui/scrollTo.js',
        'public/scripts/views/ui/slide.js'
    ],
    dest: './public/dist/scripts'
};

const configDep = {
    mainFile: 'app-dep.js',
    src: [
        'public/scripts/dependencies/chart.js',
        'public/scripts/dependencies/markdown-it.js',
        'public/scripts/dependencies/pell.js',
        'public/scripts/dependencies/prism.js',
        'public/scripts/dependencies/turndown.js',

        'public/scripts/polyfills/date-input.js',
        'public/scripts/polyfills/datalist.js',
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
    return src('./public/styles/default-ltr.less')
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