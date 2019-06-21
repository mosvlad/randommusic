var gulp         = require('gulp'),
    sass         = require('gulp-sass'),
    plumber      = require('gulp-plumber'),
    uglify       = require('gulp-uglify-es').default,
    jshint       = require('gulp-jshint'),
    imagemin     = require('gulp-imagemin'),
    cssnano      = require('gulp-cssnano'),
    autoprefixer = require('gulp-autoprefixer'),
    browserSync  = require('browser-sync'),

    cache        = require('gulp-cache'),
    notify       = require('gulp-notify'),
    del          = require('del');

var config = {
  devFolder:  'dev/',
  buildFolder: 'build/'
};

// Error handler
var onError = function(err) {
  notify.onError({
    title: "Error in " + err.plugin,
    message: err.message
  })(err);
  this.emit('end');
};



// DEV TASKS


// Watcher (dev)
/* 
  known problems: 
  1. Creating a new directories will cause "html:dev" triggering on any actions in devFolder. 
  Will help gulp restarting
  2. New sass files created during watching will not be processed. Will help gulp restarting. 
  By the way, files that included through @import will be processed
*/
gulp.task('watch', function(){
  gulp.watch("**/*.html",       {cwd: config.devFolder}, ['html:dev']);
  gulp.watch("sass/**/*.sass",  {cwd: config.devFolder}, ['sass:dev']);
  gulp.watch("js/**/*.js",      {cwd: config.devFolder}, ['script:dev']);
  gulp.watch("img/**/*.*",      {cwd: config.devFolder}, ['img:dev']);
  gulp.watch("fonts/**/*.*",    {cwd: config.devFolder}, ['fonts:dev']);
});

// Html (dev)
gulp.task('html:dev', function(){
  return browserSync.reload()
});

// Sass + autoprefixer (dev)
gulp.task('sass:dev', function(){
  return gulp
    .src(config.devFolder + "sass/**/*.sass")
    .pipe(plumber({ errorHandler: onError }))
    .pipe(sass({ outputStyle: "expanded" }))
    .pipe(autoprefixer({
      browsers: ['defaults']
    }))
    .pipe(gulp.dest(config.devFolder + 'css'))
    .pipe(browserSync.stream())
});

// JS (dev)
gulp.task('script:dev', function(){
  gulp
    .src(config.devFolder + "js/**/*.js")
    .pipe(plumber({ errorHandler: onError }))
    /*
     Jshint - detects errors and potential problems in JavaScript code.
     Errors are output in the console with syntax highlighting.
     You can add a list of ignored files on - .jshintignore (file is hidden)
     Also, you can comment on 2 lines below if you dont need jshint.
    */
    .pipe(jshint())
    .pipe(jshint.reporter('jshint-stylish'));
  return browserSync.reload()
});

// Imgs (dev)
gulp.task('img:dev', function(){
  return browserSync.reload()
});

// Fonts (dev)
gulp.task('fonts:dev', function(){
  return browserSync.reload()
});

// Server (dev)
gulp.task('serve', function(){
  browserSync.init({
    server: config.devFolder,
    open: false,
    // notify: false // notifies on reloads or files changing
  })
});


// DEV TASKS end





// BUILD TASKS

// Html (build)
gulp.task('html:build', function(){
  return gulp
    .src(config.devFolder + "**/*.{html,php}")
    .pipe(gulp.dest(config.buildFolder))
});

// Style (build)
gulp.task('style:build', function(){
  return gulp
    .src(config.devFolder + "sass/**/*.sass")
    .pipe(plumber({ errorHandler: onError }))
    .pipe(sass({ outputStyle: "expanded" }))
    .pipe(autoprefixer({
      browsers: ['defaults']
    }))
    .pipe(cssnano())
    .pipe(gulp.dest(config.buildFolder + 'css'))
});

// JS (build)
gulp.task('script:build', function(){
  return gulp
    .src(config.devFolder + 'js/**/*.js')
    .pipe(plumber({ errorHandler: onError }))
    .pipe(uglify())
    .pipe(gulp.dest(config.buildFolder + 'js'))
});

// Imgs (build)
gulp.task('img:build', function(){
  return gulp
    .src(config.devFolder + 'img/**/*.{png,jpg,gif}')
    .pipe(cache(imagemin([
      imagemin.optipng({optimizationLevel: 3}),
      imagemin.jpegtran({progressive: true})
    ])))
    .pipe(gulp.dest(config.buildFolder + 'img'))
});

// Fonts (build)
gulp.task('fonts:build', function(){
  return gulp
    .src(config.devFolder + 'fonts/**/*.*')
    .pipe(gulp.dest(config.buildFolder + 'fonts'));
});

// Copy other files
gulp.task('copy-other-files', function(){
  return gulp
    .src([
      // exclude already processed formats
      '!' + config.devFolder + '**/*.{png,jpg,gif,css,html,js,sass}',
      // and copy the rest
      config.devFolder + '**/*.*'
    ]).pipe(gulp.dest(config.buildFolder));
});

// BUILD TASKS end





// COMMON TASKS

// Clean buildFolder
gulp.task('clean', function(){
  return del.sync(config.buildFolder)
});

// COMMON TASKS end





// MAIN TASKS

// gulp
gulp.task('default', ['sass:dev','serve','watch']);

// build (min)
gulp.task('build', 
  ['clean','html:build','style:build','script:build','img:build','fonts:build','copy-other-files']);

// cache
gulp.task('cache', function(){
  return cache.clearAll();
});

// MAIN TASKS end



