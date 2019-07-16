<?php

$appRoutes = function () {
    Route::get('/', 'HomeController@index');
    Route::get('/resume', 'ResumeController@index')->name('resume.pdf');
    Route::any('{query}', function () {
        return redirect('/');
    })->where('query', '.*');
};

Route::group(['domain' => config('app.url')], $appRoutes);

Route::group(['domain' => 'www.jjweiting.com'], $appRoutes);
