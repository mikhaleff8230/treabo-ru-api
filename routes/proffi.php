<?php

use App\Http\Controllers\Api\MoldovaLocationController;
use App\Http\Controllers\Proffi\AdminController;
use App\Http\Controllers\Api\AiJobDraftController;
use App\Http\Controllers\Proffi\AiCategorySchemaController;
use App\Http\Controllers\Proffi\AiChatKnowledgeController;
use App\Http\Controllers\Proffi\ApplicationController;
use App\Http\Controllers\Proffi\AuthController;
use App\Http\Controllers\Proffi\CategoryAttributeController;
use App\Http\Controllers\Proffi\CategoryController;
use App\Http\Controllers\Proffi\ChatController;
use App\Http\Controllers\Proffi\JobAttributeController;
use App\Http\Controllers\Proffi\SpecialistController;
use App\Http\Controllers\Proffi\TaskController;
use App\Http\Controllers\SellerBalanceController;
use App\Http\Controllers\Proffi\UploadController;
use App\Http\Middleware\ProffiAdminToken;
use Illuminate\Support\Facades\Route;

Route::get('/proffi-health', function () {
    return response()->json([
        'app_env' => config('app.env'),
        'db_host' => config('database.connections.mysql.host'),
        'db_database' => config('database.connections.mysql.database'),
        'cache' => config('cache.default'),
        'redis_client' => config('database.redis.client'),
    ]);
});

Route::prefix('auth')->group(function () {
    Route::post('/check-phone', [AuthController::class, 'checkPhone']);
    Route::post('/register-phone', [AuthController::class, 'registerPhone']);
    Route::post('/register', [AuthController::class, 'registerEmail']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify', [AuthController::class, 'verify']);
    Route::get('/oauth/{provider}/redirect', [AuthController::class, 'oauthRedirect']);
    Route::get('/oauth/{provider}/callback', [AuthController::class, 'oauthCallback']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/stats', [AuthController::class, 'stats']);
        Route::patch('/profile', [AuthController::class, 'updateProfile']);
        Route::post('/profile', [AuthController::class, 'updateProfile']);
    });
});

Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/locations/moldova/search', [MoldovaLocationController::class, 'search']);
Route::get('/categories/{category}/attributes', [CategoryAttributeController::class, 'index']);
Route::get('/ai/categories/{category}/schema', [AiCategorySchemaController::class, 'show']);
Route::post('/ai/job-draft', [AiJobDraftController::class, 'generate'])->middleware('throttle:10,1');
Route::get('/stories', [CategoryController::class, 'stories']);
Route::get('/files/{path}', [UploadController::class, 'show'])->where('path', '.*');
Route::get('/tasks', [TaskController::class, 'index']);
Route::get('/jobs/{job}/attributes', [JobAttributeController::class, 'show'])->whereNumber('job');
Route::get('/tasks/{job}/attributes', [JobAttributeController::class, 'show'])->whereNumber('job');
Route::get('/tasks/{task}', [TaskController::class, 'show'])->whereNumber('task');
Route::get('/specialists', [SpecialistController::class, 'index']);
Route::get('/specialists/{user}', [SpecialistController::class, 'show'])->whereNumber('user');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/uploads', [UploadController::class, 'store']);

    Route::post('/tasks', [TaskController::class, 'store']);
    Route::post('/jobs/{job}/attributes', [JobAttributeController::class, 'store'])->whereNumber('job');
    Route::post('/tasks/{job}/attributes', [JobAttributeController::class, 'store'])->whereNumber('job');
    Route::get('/tasks/mine', [TaskController::class, 'mine']);
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);
    Route::get('/tasks/{task}/applications/preview', [ApplicationController::class, 'preview']);
    Route::get('/tasks/{task}/applications', [TaskController::class, 'applications']);
    Route::post('/tasks/{task}/applications', [ApplicationController::class, 'store']);
    Route::get('/tasks/{task}/specialist-info', [TaskController::class, 'specialistInfo']);

    Route::get('/applications/mine', [ApplicationController::class, 'mine']);
    Route::post('/applications/{application}/accept', [ApplicationController::class, 'accept']);

    Route::get('/balance', [SellerBalanceController::class, 'get']);
    Route::post('/balance/deposit', [SellerBalanceController::class, 'deposit']);
    Route::get('/balance/check-pending', [SellerBalanceController::class, 'checkPending']);

    Route::get('/chats', [ChatController::class, 'index']);
    Route::get('/chats/{chat}', [ChatController::class, 'show']);
    Route::get('/chats/{chat}/messages', [ChatController::class, 'messages']);
    Route::post('/chats/{chat}/messages', [ChatController::class, 'send']);
});

Route::middleware(ProffiAdminToken::class)->prefix('admin')->group(function () {
    Route::get('/stats', [AdminController::class, 'stats']);
    Route::get('/users', [AdminController::class, 'users']);
    Route::get('/customers', [AdminController::class, 'customers']);
    Route::get('/specialists', [AdminController::class, 'specialists']);
    Route::post('/users', [AdminController::class, 'createUser']);
    Route::put('/users/{user}', [AdminController::class, 'updateUser']);
    Route::delete('/users/{user}', [AdminController::class, 'deleteUser']);

    Route::get('/categories', [AdminController::class, 'categories']);
    Route::post('/categories', [AdminController::class, 'createCategory']);
    Route::put('/categories/{id}', [AdminController::class, 'updateCategory']);
    Route::delete('/categories/{id}', [AdminController::class, 'deleteCategory']);

    Route::get('/filters', [AdminController::class, 'filters']);
    Route::post('/filters', [AdminController::class, 'createFilter']);
    Route::put('/filters/{id}', [AdminController::class, 'updateFilter']);
    Route::delete('/filters/{id}', [AdminController::class, 'deleteFilter']);

    Route::get('/response-settings', [AdminController::class, 'responseSettings']);
    Route::put('/response-settings', [AdminController::class, 'updateResponseSettings']);

    Route::get('/tasks', [AdminController::class, 'tasks']);
    Route::post('/tasks', [AdminController::class, 'createTask']);
    Route::put('/tasks/{task}', [AdminController::class, 'updateTask']);
    Route::delete('/tasks/{task}', [AdminController::class, 'deleteTask']);
    Route::get('/applications', [AdminController::class, 'applications']);
    Route::get('/chats', [AdminController::class, 'chats']);
    Route::get('/chats/{chat}/messages', [AdminController::class, 'chatMessages']);
    Route::get('/ai-chat/knowledge', [AiChatKnowledgeController::class, 'index']);
    Route::post('/ai-chat/knowledge', [AiChatKnowledgeController::class, 'store']);
    Route::put('/ai-chat/knowledge/{knowledge}', [AiChatKnowledgeController::class, 'update']);
    Route::delete('/ai-chat/knowledge/{knowledge}', [AiChatKnowledgeController::class, 'destroy']);
});
