<?php

Route::group([
    'prefix'     => 'database',
    'middleware' => 'auth:api',
    'namespace'  => config('dbm.controller_namespace'),
], function () {

    // Helpers Route
    Route::get('assets', 'ManagerController@assets')->name('dbm.asset');
    // Database
    Route::get('/tables', 'DatabaseController@all');
    Route::get('/table/{name}', 'DatabaseController@getTable');
    Route::post('/table', 'DatabaseController@create');
    Route::put('/table', 'DatabaseController@update');
    Route::delete('/table', 'DatabaseController@delete');
    // Backup
    Route::get('/getBackups', 'BackupController@backups');
    Route::post('/backup', 'BackupController@backup');
    Route::put('/backup', 'BackupController@restore');
    Route::delete('/backup', 'BackupController@delete');
    Route::get('/download', 'BackupController@download');
    // Permissions
    Route::get('/permissions', 'PermissionController@all');
    Route::post('/permissions/assignUserPermissions', 'PermissionController@assignUserPermissions');
    Route::put('/permissions/syncUserPermissions', 'PermissionController@syncUserPermissions');
    Route::delete('/permissions/deleteUserPermissions', 'PermissionController@deleteUserPermissions');
    // Template
    Route::post('/template', 'DatabaseController@saveTemplate');
    Route::delete('/template', 'DatabaseController@removeTemplate');
    // Unique ID
    Route::get('getUniqueId', 'DatabaseController@getUniqueId');
    /*
     * C = Create
     * R = Read
     * U = Update/Edit
     * D = Delete
     */
    Route::get('/crud/tables', 'CrudController@all');
    Route::get('/crud/details/{table}', 'CrudController@getObjectDetails');
    Route::post('/crud', 'CrudController@storeOrUpdate');
    Route::delete('/crud/{table}', 'CrudController@delete');
    // Relationship
    Route::get('/relationship', 'RelationController@getRelation');
    Route::post('/relationship', 'RelationController@addRelation');
    Route::put('/relationship', 'RelationController@updateRelation');
    Route::delete('/relationship', 'RelationController@deleteRelation');
    // Table
    Route::group(['prefix' => 'table'], function () {
        Route::get('/details/{table}', 'RecordController@getTableDetails');
        Route::get('/columns/{table}', 'DatabaseController@getTableColumns');
    });
    // Record
    Route::post('/record', 'RecordController@store');
    Route::put('/record', 'RecordController@update');
    Route::delete('/record', 'RecordController@delete');
    //User
    Route::delete('/logout', 'UserController@logout')->middleware('auth:api');
});

Route::group([
    'prefix'    => 'database',
    'namespace' => config('dbm.controller_namespace'),
], function () {
    // User
    Route::post('/login', 'UserController@login');
    Route::post('/oauth/token', 'UserController@getPersonalAccessToken');
});
