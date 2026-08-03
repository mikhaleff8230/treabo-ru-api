<?php

use App\Http\Controllers\Api\MoldovaLocationController;
use App\Http\Controllers\Api\RussiaLocationController;
use App\Http\Controllers\Proffi\AdminController;
use App\Http\Controllers\Api\AiJobDraftController;
use App\Http\Controllers\Proffi\AiCategorySchemaController;
use App\Http\Controllers\Proffi\AiChatKnowledgeController;
use App\Http\Controllers\Proffi\AiKnowledgeLabController;
use App\Http\Controllers\Proffi\AiOperationsController;
use App\Http\Controllers\Proffi\ProffiWorkController;
use App\Http\Controllers\Proffi\ProffiWorkQuestionController;
use App\Http\Controllers\Proffi\QuestionFlowController;
use App\Http\Controllers\Proffi\ApplicationController;
use App\Http\Controllers\Proffi\AuthController;
use App\Http\Controllers\Proffi\PushTokenController;
use App\Http\Controllers\Proffi\PushLoginController;
use App\Http\Controllers\Proffi\RequestDraftController;
use App\Http\Controllers\Proffi\CategoryAttributeController;
use App\Http\Controllers\Proffi\CategoryController;
use App\Http\Controllers\Proffi\ChatController;
use App\Http\Controllers\Proffi\FavoriteController;
use App\Http\Controllers\Proffi\HomeController;
use App\Http\Controllers\Proffi\IdentityVerificationController;
use App\Http\Controllers\Proffi\JobAttributeController;
use App\Http\Controllers\Proffi\SpecialistController;
use App\Http\Controllers\Proffi\SpecialistReviewController;
use App\Http\Controllers\Proffi\TaskController;
use App\Http\Controllers\Proffi\TaskAiFeedbackController;
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

$proffiAdminRoutes = function () {
    Route::get('/stats', [AdminController::class, 'stats']);
    Route::get('/users', [AdminController::class, 'users']);
    Route::get('/customers', [AdminController::class, 'customers']);
    Route::get('/specialists', [AdminController::class, 'specialists']);
    Route::post('/specialists/{user}/balance/virtual-deposit', [AdminController::class, 'virtualBalanceDeposit']);
    Route::post('/users', [AdminController::class, 'createUser']);
    Route::put('/users/{user}', [AdminController::class, 'updateUser']);
    Route::delete('/users/{user}', [AdminController::class, 'deleteUser']);

    Route::get('/categories', [AdminController::class, 'categories']);
    Route::post('/categories', [AdminController::class, 'createCategory']);
    Route::put('/categories/{id}', [AdminController::class, 'updateCategory']);
    Route::delete('/categories/{id}', [AdminController::class, 'deleteCategory']);

    Route::get('/branding-settings', [AdminController::class, 'brandingSettings']);
    Route::put('/branding-settings', [AdminController::class, 'updateBrandingSettings']);

    Route::get('/response-settings', [AdminController::class, 'responseSettings']);
    Route::put('/response-settings', [AdminController::class, 'updateResponseSettings']);
    Route::get('/matching-settings', [AdminController::class, 'matchingSettings']);
    Route::put('/matching-settings', [AdminController::class, 'updateMatchingSettings']);
    Route::get('/mobile-update-settings', [AdminController::class, 'mobileUpdateSettings']);
    Route::put('/mobile-update-settings', [AdminController::class, 'updateMobileUpdateSettings']);
    Route::get('/balance-deposits', [AdminController::class, 'balanceDeposits']);

    Route::get('/tasks', [AdminController::class, 'tasks']);
    Route::post('/tasks', [AdminController::class, 'createTask']);
    Route::put('/tasks/{task}', [AdminController::class, 'updateTask']);
    Route::delete('/tasks/{task}', [AdminController::class, 'deleteTask']);
    Route::get('/applications', [AdminController::class, 'applications']);
    Route::get('/chats', [AdminController::class, 'chats']);
    Route::get('/chats/{chat}/messages', [AdminController::class, 'chatMessages']);
    Route::get('/works', [ProffiWorkController::class, 'index']);
    Route::post('/works', [ProffiWorkController::class, 'store']);
    Route::put('/works/{work}', [ProffiWorkController::class, 'update']);
    Route::delete('/works/{work}', [ProffiWorkController::class, 'destroy']);

    Route::get('/questions', [ProffiWorkQuestionController::class, 'index']);
    Route::post('/questions', [ProffiWorkQuestionController::class, 'store']);
    Route::put('/questions/{question}', [ProffiWorkQuestionController::class, 'update']);
    Route::delete('/questions/{question}', [ProffiWorkQuestionController::class, 'destroy']);
    Route::get('/question-flow', [QuestionFlowController::class, 'index']);
    Route::post('/question-flow/preview', [QuestionFlowController::class, 'preview']);
    Route::post('/question-groups', [QuestionFlowController::class, 'storeGroup']);
    Route::put('/question-groups/{group}', [QuestionFlowController::class, 'updateGroup']);
    Route::delete('/question-groups/{group}', [QuestionFlowController::class, 'destroyGroup']);
    Route::post('/question-rules', [QuestionFlowController::class, 'storeRule']);
    Route::put('/question-rules/{rule}', [QuestionFlowController::class, 'updateRule']);
    Route::delete('/question-rules/{rule}', [QuestionFlowController::class, 'destroyRule']);

    Route::get('/ai-chat/knowledge', [AiChatKnowledgeController::class, 'index']);
    Route::post('/ai-chat/knowledge', [AiChatKnowledgeController::class, 'store']);
    Route::put('/ai-chat/knowledge/{knowledge}', [AiChatKnowledgeController::class, 'update']);
    Route::delete('/ai-chat/knowledge/{knowledge}', [AiChatKnowledgeController::class, 'destroy']);

    Route::get('/ai-lab/imports', [AiKnowledgeLabController::class, 'imports']);
    Route::post('/ai-lab/imports', [AiKnowledgeLabController::class, 'storeImport']);
    Route::get('/ai-lab/imports/{import}', [AiKnowledgeLabController::class, 'showImport']);
    Route::post('/ai-lab/imports/{import}/analyze', [AiKnowledgeLabController::class, 'analyze']);
    Route::post('/ai-lab/imports/{import}/cancel', [AiKnowledgeLabController::class, 'cancel']);
    Route::get('/ai-lab/proposals', [AiKnowledgeLabController::class, 'proposals']);
    Route::get('/ai-lab/proposals/{proposal}', [AiKnowledgeLabController::class, 'showProposal']);
    Route::put('/ai-lab/proposals/{proposal}', [AiKnowledgeLabController::class, 'updateProposal']);
    Route::post('/ai-lab/proposals/{proposal}/accept', [AiKnowledgeLabController::class, 'acceptProposal']);
    Route::post('/ai-lab/proposals/{proposal}/reject', [AiKnowledgeLabController::class, 'rejectProposal']);
    Route::post('/ai-lab/proposals/bulk-review', [AiKnowledgeLabController::class, 'bulkReview']);
    Route::post('/ai-lab/proposals/{proposal}/answers', [AiKnowledgeLabController::class, 'answerProposal']);
    Route::get('/ai-lab/terms', [AiKnowledgeLabController::class, 'terms']);
    Route::get('/ai-lab/versions', [AiKnowledgeLabController::class, 'versions']);
    Route::post('/ai-lab/versions', [AiKnowledgeLabController::class, 'createVersion']);
    Route::post('/ai-lab/versions/{version}/evaluate', [AiKnowledgeLabController::class, 'evaluateVersion']);
    Route::post('/ai-lab/versions/{version}/publish', [AiKnowledgeLabController::class, 'publishVersion']);
    Route::post('/ai-lab/versions/{version}/rollback', [AiKnowledgeLabController::class, 'rollbackVersion']);
    Route::post('/ai-lab/retrieve', [AiKnowledgeLabController::class, 'retrieve']);
    Route::get('/ai-operations/analytics', [AiOperationsController::class, 'analytics']);
    Route::get('/ai-operations/learning-events', [AiOperationsController::class, 'learningEvents']);
    Route::post('/ai-operations/learning-events/{event}/promote', [AiOperationsController::class, 'promote']);
    Route::put('/ai-operations/learning-events/{event}', [AiOperationsController::class, 'reviewEvent']);
    Route::get('/ai-operations/evaluations', [AiOperationsController::class, 'evaluations']);
    Route::post('/ai-operations/evaluations/{version}', [AiOperationsController::class, 'runEvaluation']);

    Route::get('/reviews', [AdminController::class, 'reviews']);
    Route::post('/reviews', [AdminController::class, 'createReview']);
    Route::put('/reviews/{review}', [AdminController::class, 'updateReview'])->whereNumber('review');
    Route::delete('/reviews/{review}', [AdminController::class, 'deleteReview'])->whereNumber('review');

    Route::get('/verifications', [AdminController::class, 'verifications']);
    Route::post('/verifications/{verification}/approve', [AdminController::class, 'approveVerification'])->whereNumber('verification');
    Route::post('/verifications/{verification}/reject', [AdminController::class, 'rejectVerification'])->whereNumber('verification');
};

Route::prefix('proffi')->group(function () use ($proffiAdminRoutes) {
Route::prefix('auth')->group(function () {
    Route::prefix('customer')->group(function () {
        Route::post('/check-phone', [AuthController::class, 'customerCheckPhone']);
        Route::post('/register-phone', [AuthController::class, 'customerRegisterPhone'])->middleware('throttle:5,1');
        Route::post('/login', [AuthController::class, 'customerLogin'])->middleware('throttle:10,1');
        Route::post('/phone/send-otp', [AuthController::class, 'customerSendPhoneOtp'])->middleware('throttle:3,10');
        Route::post('/phone/verify-otp', [AuthController::class, 'verifyPhoneOtp']);
        Route::post('/password/send-code', [AuthController::class, 'sendCustomerPasswordResetOtp'])->middleware('throttle:3,10');
        Route::post('/password/reset', [AuthController::class, 'resetCustomerPassword'])->middleware('throttle:5,10');
    });
    Route::prefix('specialist')->group(function () {
        Route::post('/check-phone', [AuthController::class, 'specialistCheckPhone']);
        Route::post('/register-phone', [AuthController::class, 'specialistRegisterPhone'])->middleware('throttle:5,1');
        Route::post('/login', [AuthController::class, 'specialistLogin'])->middleware('throttle:10,1');
        Route::post('/phone/send-otp', [AuthController::class, 'specialistSendPhoneOtp'])->middleware('throttle:3,10');
        Route::post('/phone/verify-otp', [AuthController::class, 'verifyPhoneOtp']);
        Route::post('/push-login/request', [PushLoginController::class, 'request'])
            ->middleware('throttle:specialist-push-login-request');
        Route::get('/push-login/{id}', [PushLoginController::class, 'status'])
            ->middleware('throttle:specialist-push-login-status');
    });
    Route::post('/verify', [AuthController::class, 'verify']);
    Route::post('/phone/verify-otp', [AuthController::class, 'verifyPhoneOtp']);
    Route::get('/oauth/{provider}/redirect', [AuthController::class, 'oauthRedirect']);
    Route::get('/oauth/{provider}/callback', [AuthController::class, 'oauthCallback']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/stats', [AuthController::class, 'stats']);
        Route::patch('/profile', [AuthController::class, 'updateProfile']);
        Route::post('/profile', [AuthController::class, 'updateProfile']);
        Route::post('/phone/change/send-otp', [AuthController::class, 'sendChangePhoneOtp']);
        Route::post('/push-tokens', [PushTokenController::class, 'store']);
        Route::delete('/push-tokens', [PushTokenController::class, 'destroy']);
        Route::post('/push-login/{login}/approve', [PushLoginController::class, 'approve']);
        Route::post('/push-login/{login}/reject', [PushLoginController::class, 'reject']);
    });
});

Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/works', [ProffiWorkController::class, 'index']);
Route::get('/questions', [ProffiWorkQuestionController::class, 'index']);
Route::get('/locations/moldova/search', [MoldovaLocationController::class, 'search']);
Route::get('/locations/russia/search', [RussiaLocationController::class, 'search']);
Route::get('/locations/search', [RussiaLocationController::class, 'search']);
Route::get('/categories/{category}/attributes', [CategoryAttributeController::class, 'index']);
Route::get('/ai/categories/{category}/schema', [AiCategorySchemaController::class, 'show']);
Route::post('/ai/job-draft', [AiJobDraftController::class, 'generate']);
Route::post('/request-drafts', [RequestDraftController::class, 'store']);
Route::get('/request-drafts/latest', [RequestDraftController::class, 'latest']);
Route::get('/request-drafts/{draft}', [RequestDraftController::class, 'show']);
Route::post('/request-drafts/{draft}/turns', [RequestDraftController::class, 'turn']);
Route::patch('/request-drafts/{draft}', [RequestDraftController::class, 'update']);
Route::get('/home/stats', [HomeController::class, 'stats']);
Route::get('/home/top-specialists', [HomeController::class, 'topSpecialists']);
Route::get('/site-settings', [HomeController::class, 'siteSettings']);
Route::get('/mobile-version', [HomeController::class, 'mobileVersion']);
Route::get('/mobile-version/{appType}', [HomeController::class, 'mobileVersion'])
    ->whereIn('appType', ['specialist', 'client']);
Route::get('/stories', [CategoryController::class, 'stories']);
Route::get('/files/{path}', [UploadController::class, 'show'])->where('path', '.*');
Route::get('/tasks', [TaskController::class, 'index']);
Route::get('/jobs/{job}/attributes', [JobAttributeController::class, 'show'])->whereNumber('job');
Route::get('/tasks/{job}/attributes', [JobAttributeController::class, 'show'])->whereNumber('job');
Route::get('/tasks/{task}', [TaskController::class, 'show'])->whereNumber('task');
Route::get('/tasks/{task}/recommended-specialists', [TaskController::class, 'recommendedSpecialists'])->whereNumber('task');
Route::get('/specialists', [SpecialistController::class, 'index']);
Route::get('/specialists/{user}', [SpecialistController::class, 'show'])->whereNumber('user');
Route::get('/specialists/{user}/reviews', [SpecialistReviewController::class, 'index'])->whereNumber('user');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/request-drafts/{draft}/confirm', [RequestDraftController::class, 'confirm']);
    Route::post('/uploads', [UploadController::class, 'store']);

    Route::post('/tasks', [TaskController::class, 'store']);
    Route::patch('/tasks/{task}/budget', [TaskController::class, 'updateBudget'])->whereNumber('task');
    Route::post('/tasks/{task}/close', [TaskController::class, 'close'])->whereNumber('task');
    Route::post('/tasks/{task}/ai-feedback', [TaskAiFeedbackController::class, 'store'])->whereNumber('task');
    Route::post('/jobs/{job}/attributes', [JobAttributeController::class, 'store'])->whereNumber('job');
    Route::post('/tasks/{job}/attributes', [JobAttributeController::class, 'store'])->whereNumber('job');
    Route::get('/tasks/mine', [TaskController::class, 'mine']);
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);
    Route::get('/tasks/{task}/applications/preview', [ApplicationController::class, 'preview']);
    Route::get('/tasks/{task}/applications', [TaskController::class, 'applications']);
    Route::post('/tasks/{task}/applications', [ApplicationController::class, 'store']);
    Route::get('/tasks/{task}/specialist-info', [TaskController::class, 'specialistInfo']);
    Route::post('/tasks/{task}/contact-specialist/{specialist}', [TaskController::class, 'contactSpecialist'])
        ->whereNumber('task')
        ->whereNumber('specialist');

    Route::get('/applications/mine', [ApplicationController::class, 'mine']);
    Route::post('/applications/{application}/accept', [ApplicationController::class, 'accept']);
    Route::post('/specialists/{user}/reviews', [SpecialistReviewController::class, 'store'])->whereNumber('user');
    Route::post('/specialists/{user}/contact', [SpecialistController::class, 'contact'])->whereNumber('user');

    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/favorites/{task}', [FavoriteController::class, 'store'])->whereNumber('task');
    Route::delete('/favorites/{task}', [FavoriteController::class, 'destroy'])->whereNumber('task');

    Route::get('/identity-verification', [IdentityVerificationController::class, 'show']);
    Route::post('/identity-verification', [IdentityVerificationController::class, 'submit']);

    Route::get('/balance', [SellerBalanceController::class, 'get']);
    Route::get('/balance/transactions', [SellerBalanceController::class, 'transactions']);
    Route::post('/balance/deposit', [SellerBalanceController::class, 'deposit']);
    Route::post('/balance/deposit/report', [SellerBalanceController::class, 'reportManualPayment']);
    Route::get('/balance/check-pending', [SellerBalanceController::class, 'checkPending']);

    Route::get('/chats', [ChatController::class, 'index']);
    Route::get('/chats/{chat}', [ChatController::class, 'show']);
    Route::get('/chats/{chat}/customer-contact', [ChatController::class, 'customerContact'])->middleware('throttle:10,1');
    Route::get('/chats/{chat}/specialist-contact', [ChatController::class, 'specialistContact'])->middleware('throttle:10,1');
    Route::get('/chats/{chat}/messages', [ChatController::class, 'messages']);
    Route::post('/chats/{chat}/messages', [ChatController::class, 'send']);
    Route::post('/chats/{chat}/read', [ChatController::class, 'read']);
    Route::post('/chats/{chat}/typing', [ChatController::class, 'typing']);
    Route::post('/presence/heartbeat', [ChatController::class, 'presenceHeartbeat']);
});

Route::middleware(ProffiAdminToken::class)->prefix('admin')->group($proffiAdminRoutes);
});

Route::middleware(ProffiAdminToken::class)->prefix('admin')->group($proffiAdminRoutes);
