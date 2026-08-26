<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\HomeownerApplicationController;
use App\Http\Controllers\Api\HomeownerJobCompletionController;
use App\Http\Controllers\Api\HomeownerJobController;
use App\Http\Controllers\Api\JobCancellationController;
use App\Http\Controllers\Api\HomeownerInvitationController;
use App\Http\Controllers\Api\HomeownerProfileController;
use App\Http\Controllers\Api\CompanyProfileController;
use App\Http\Controllers\Api\HomeownerProfileSectionController;
use App\Http\Controllers\Api\HomeownerReviewController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\SavedWorkerController;
use App\Http\Controllers\Api\WorkerActiveJobController;
use App\Http\Controllers\Api\WorkerDashboardController;
use App\Http\Controllers\Api\WorkerHomeController;
use App\Http\Controllers\Api\WorkerJobController;
use App\Http\Controllers\Api\WorkerInvitationController;
use App\Http\Controllers\Api\WorkerMarketplaceController;
use App\Http\Controllers\Api\WorkerProfileController;
use App\Http\Controllers\Api\WorkerProfileSectionController;
use App\Http\Controllers\Api\WorkerPublicProfileController;
use App\Http\Controllers\Api\WorkerServiceController;
use App\Http\Controllers\Api\GuestWorkerController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HiringRequestController;
use App\Http\Controllers\Api\WorkWantedPostController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminWorkerVerificationController;
use App\Http\Controllers\Api\AdminCompanyVerificationController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AccountControlController;
use App\Http\Controllers\Api\AccountAppealController;
use App\Http\Controllers\Api\AdminAccountAppealController;

/*
|--------------------------------------------------------------------------
| Public Authentication Routes
|--------------------------------------------------------------------------
*/

Route::post('/register', [
    AuthController::class,
    'register',
]);

Route::post('/login', [
    AuthController::class,
    'login',
]);

/*
|--------------------------------------------------------------------------
| Public Media Route
|--------------------------------------------------------------------------
*/

Route::get('/media/{path}', [
    MediaController::class,
    'show',
])->where('path', '.*');

/*
|--------------------------------------------------------------------------
| Public Guest Marketplace
|--------------------------------------------------------------------------
*/

Route::get('/guest/service-categories', [
    GuestWorkerController::class,
    'categories',
]);

Route::get('/guest/workers', [
    GuestWorkerController::class,
    'index',
]);

Route::get('/guest/workers/{worker}/profile', [
    GuestWorkerController::class,
    'show',
]);
/*
|--------------------------------------------------------------------------
| Account Status and Suspension Appeals
|--------------------------------------------------------------------------
|
| These routes must remain accessible to authenticated suspended users.
| Do NOT add the account.active middleware here.
|
*/

Route::middleware(['auth:sanctum'])->group(function () {

    Route::get('/account/status', [
        AccountAppealController::class,
        'status',
    ]);

    Route::post('/account/appeals', [
        AccountAppealController::class,
        'store',
    ]);


    Route::post('/account/deactivate', [
        AccountControlController::class,
        'deactivate',
    ]);

    Route::post('/account/request-deletion', [
        AccountControlController::class,
        'requestDeletion',
    ]);
});
/*
|--------------------------------------------------------------------------
| Protected API Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'account.active'])->group(function () {

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);


    /*
    |--------------------------------------------------------------------------
    | Firebase Device Tokens
    |--------------------------------------------------------------------------
    */

    Route::get('/device-tokens', [DeviceTokenController::class, 'index']);
    Route::post('/device-tokens', [DeviceTokenController::class, 'store']);
    Route::delete('/device-tokens', [DeviceTokenController::class, 'destroy']);


    /*
    |--------------------------------------------------------------------------
    | Authentication and Account Security
    |--------------------------------------------------------------------------
    */

    Route::get('/me', [
        AuthController::class,
        'me',
    ]);

    Route::post('/logout', [
        AuthController::class,
        'logout',
    ]);

    Route::put('/account/password', [
        AuthController::class,
        'changePassword',
    ]);

    Route::post('/account/logout-other-devices', [
        AuthController::class,
        'logoutOtherDevices',
    ]);

    Route::post('/account/logout-all-devices', [
        AuthController::class,
        'logoutAllDevices',
    ]);
Route::prefix('hiring')->group(function () {
    Route::get(
        '/homeowner',
        [HiringRequestController::class, 'homeownerIndex']
    );

    Route::get(
        '/worker',
        [HiringRequestController::class, 'workerIndex']
    );

    Route::get(
        '/available-jobs',
        [HiringRequestController::class, 'availableJobs']
    );

    Route::post(
        '/requests',
        [HiringRequestController::class, 'store']
    );

    Route::post(
        '/quick-requests',
        [HiringRequestController::class, 'storeQuick']
    );

    Route::post(
        '/direct-offers',
        [HiringRequestController::class, 'storeDirect']
    );

    Route::get(
        '/requests/{hiringRequest}',
        [HiringRequestController::class, 'show']
    );

    Route::post(
        '/requests/{hiringRequest}/accept',
        [HiringRequestController::class, 'accept']
    );

    Route::post(
        '/requests/{hiringRequest}/decline',
        [HiringRequestController::class, 'decline']
    );

    Route::post(
        '/requests/{hiringRequest}/cancel',
        [HiringRequestController::class, 'cancel']
    );

    Route::post(
        '/requests/{hiringRequest}/complete',
        [HiringRequestController::class, 'complete']
    );
});

    /* Looking for Work */
    Route::get('/worker/work-wanted', [WorkWantedPostController::class, 'mine']);
    Route::post('/worker/work-wanted', [WorkWantedPostController::class, 'store']);
    Route::put('/worker/work-wanted/{post}', [WorkWantedPostController::class, 'update']);
    Route::patch('/worker/work-wanted/{post}/status', [WorkWantedPostController::class, 'status']);
    Route::get('/homeowner/work-wanted', [WorkWantedPostController::class, 'index']);

    /*
    |--------------------------------------------------------------------------
    | Service Categories and Worker Services
    |--------------------------------------------------------------------------
    */

    Route::get('/service-categories', [
        WorkerServiceController::class,
        'categories',
    ]);

    Route::get('/worker/services', [
        WorkerServiceController::class,
        'index',
    ]);

    Route::put('/worker/services', [
        WorkerServiceController::class,
        'update',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Worker Marketplace
    |--------------------------------------------------------------------------
    */

    Route::get('/workers', [
        WorkerMarketplaceController::class,
        'index',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Public Worker Profile
    |--------------------------------------------------------------------------
    */

    Route::get('/workers/{worker}/profile', [
        WorkerPublicProfileController::class,
        'show',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Worker Home and Dashboard
    |--------------------------------------------------------------------------
    */

    Route::get('/worker/home', [
        WorkerHomeController::class,
        'index',
    ]);

    Route::get('/worker/dashboard', [
        WorkerDashboardController::class,
        'index',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Worker Profile
    |--------------------------------------------------------------------------
    */

    Route::get('/worker/profile', [
        WorkerProfileController::class,
        'show',
    ]);

    Route::post('/worker/profile', [
        WorkerProfileController::class,
        'store',
    ]);

    Route::post('/worker/profile/resubmit-verification', [
        WorkerProfileController::class,
        'resubmitVerification',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Modular Worker Profile Management
    |--------------------------------------------------------------------------
    */

    Route::post('/worker/profile/personal', [
        WorkerProfileSectionController::class,
        'personal',
    ]);

    Route::put('/worker/profile/professional', [
        WorkerProfileSectionController::class,
        'professional',
    ]);

    Route::post('/worker/profile/verification', [
        WorkerProfileSectionController::class,
        'verification',
    ]);

    Route::post('/worker/profile/gallery', [
        WorkerProfileSectionController::class,
        'gallery',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Worker Jobs and Applications
    |--------------------------------------------------------------------------
    */

    Route::get('/worker/jobs/{job}', [
        WorkerJobController::class,
        'show',
    ]);

    Route::post('/worker/jobs/{job}/apply', [
        WorkerJobController::class,
        'apply',
    ]);

    Route::get('/worker/applications', [
        WorkerJobController::class,
        'applications',
    ]);

    Route::patch(
        '/worker/applications/{application}/withdraw',
        [
            WorkerJobController::class,
            'withdraw',
        ]
    );

    Route::patch(
        '/worker/invitations/{application}/accept',
        [
            WorkerInvitationController::class,
            'accept',
        ]
    );

    Route::patch(
        '/worker/invitations/{application}/decline',
        [
            WorkerInvitationController::class,
            'decline',
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | Worker Active Jobs
    |--------------------------------------------------------------------------
    */

    Route::get('/worker/active-jobs/{job}', [
        WorkerActiveJobController::class,
        'show',
    ]);

    Route::patch('/worker/active-jobs/{job}/start', [
        WorkerActiveJobController::class,
        'start',
    ]);

    Route::patch('/worker/active-jobs/{job}/complete', [
        WorkerActiveJobController::class,
        'complete',
    ]);

    Route::patch('/worker/active-jobs/{job}/withdraw', [
        JobCancellationController::class,
        'workerWithdraw',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Homeowner Profile
    |--------------------------------------------------------------------------
    */

    Route::get('/homeowner/profile', [
        HomeownerProfileController::class,
        'show',
    ]);

    Route::post('/homeowner/profile', [
        HomeownerProfileController::class,
        'store',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Company Profile
    |--------------------------------------------------------------------------
    */

    Route::get('/company/profile', [
        CompanyProfileController::class,
        'show',
    ]);

    Route::post('/company/profile', [
        CompanyProfileController::class,
        'store',
    ]);

    Route::post('/company/profile/resubmit-verification', [
        CompanyProfileController::class,
        'resubmitVerification',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Modular Homeowner Profile Management
    |--------------------------------------------------------------------------
    */

    Route::post('/homeowner/profile/personal', [
        HomeownerProfileSectionController::class,
        'personal',
    ]);

    Route::put('/homeowner/profile/location', [
        HomeownerProfileSectionController::class,
        'location',
    ]);

    Route::put('/homeowner/profile/preferences', [
        HomeownerProfileSectionController::class,
        'preferences',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Homeowner Jobs
    |--------------------------------------------------------------------------
    */

    Route::get('/homeowner/jobs', [
        HomeownerJobController::class,
        'index',
    ]);

    Route::post('/homeowner/jobs', [
        HomeownerJobController::class,
        'store',
    ]);

    Route::get('/homeowner/jobs/{job}', [
        HomeownerJobController::class,
        'show',
    ]);

    Route::put('/homeowner/jobs/{job}', [
        HomeownerJobController::class,
        'update',
    ]);

    Route::delete('/homeowner/jobs/{job}', [
        HomeownerJobController::class,
        'destroy',
    ]);

    Route::post(
        '/homeowner/jobs/{job}/invite-worker',
        [
            HomeownerInvitationController::class,
            'store',
        ]
    );

    Route::patch(
        '/homeowner/jobs/{job}/confirm-completion',
        [
            HomeownerJobCompletionController::class,
            'confirm',
        ]
    );


    Route::patch('/homeowner/active-jobs/{job}/cancel', [
        JobCancellationController::class,
        'homeownerCancel',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Homeowner Job Applications
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/homeowner/jobs/{job}/applications',
        [
            HomeownerApplicationController::class,
            'index',
        ]
    );

    Route::patch(
        '/homeowner/applications/{application}/accept',
        [
            HomeownerApplicationController::class,
            'accept',
        ]
    );

    Route::patch(
        '/homeowner/applications/{application}/decline',
        [
            HomeownerApplicationController::class,
            'decline',
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | Homeowner Reviews
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/homeowner/jobs/{job}/review',
        [
            HomeownerReviewController::class,
            'show',
        ]
    );

    Route::post(
        '/homeowner/jobs/{job}/review',
        [
            HomeownerReviewController::class,
            'store',
        ]
    );

    Route::put(
        '/homeowner/jobs/{job}/review',
        [
            HomeownerReviewController::class,
            'update',
        ]
    );

    Route::delete(
        '/homeowner/jobs/{job}/review',
        [
            HomeownerReviewController::class,
            'destroy',
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | Saved Workers
    |--------------------------------------------------------------------------
    */

    Route::get('/homeowner/saved-workers', [
        SavedWorkerController::class,
        'index',
    ]);

    Route::post('/homeowner/saved-workers/{worker}', [
        SavedWorkerController::class,
        'store',
    ]);

    Route::delete('/homeowner/saved-workers/{worker}', [
        SavedWorkerController::class,
        'destroy',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Conversations
    |--------------------------------------------------------------------------
    */

    Route::get('/conversations', [
        ConversationController::class,
        'index',
    ]);

    Route::post('/conversations', [
        ConversationController::class,
        'store',
    ]);

    Route::get('/conversations/{conversation}', [
        ConversationController::class,
        'show',
    ]);

    Route::patch('/conversations/{conversation}/archive', [
        ConversationController::class,
        'archive',
    ]);

    Route::patch('/conversations/{conversation}/restore', [
        ConversationController::class,
        'restore',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Messages
    |--------------------------------------------------------------------------
    */

    Route::get('/conversations/{conversation}/messages', [
        MessageController::class,
        'index',
    ]);

    Route::post('/conversations/{conversation}/messages', [
        MessageController::class,
        'store',
    ]);

    Route::patch('/conversations/{conversation}/read', [
        MessageController::class,
        'markRead',
    ]);

    Route::put('/messages/{message}', [
        MessageController::class,
        'update',
    ]);

    Route::delete('/messages/{message}', [
        MessageController::class,
        'destroy',
    ]);
});

/*
|--------------------------------------------------------------------------
| API Health Check
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Administration
|--------------------------------------------------------------------------
|
| These routes are available only to authenticated administrators.
|
*/

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function () {
        Route::get(
            '/dashboard',
            [AdminDashboardController::class, 'index']
        );

        Route::get(
            '/worker-verifications',
            [AdminWorkerVerificationController::class, 'index']
        );

        Route::get(
            '/worker-verifications/{workerProfile}',
            [AdminWorkerVerificationController::class, 'show']
        );

        Route::post(
            '/worker-verifications/{workerProfile}/approve',
            [AdminWorkerVerificationController::class, 'approve']
        );

        Route::post(
            '/worker-verifications/{workerProfile}/reject',
            [AdminWorkerVerificationController::class, 'reject']
        );

        Route::get(
            '/company-verifications',
            [AdminCompanyVerificationController::class, 'index']
        );

        Route::get(
            '/company-verifications/{companyProfile}',
            [AdminCompanyVerificationController::class, 'show']
        );

        Route::post(
            '/company-verifications/{companyProfile}/approve',
            [AdminCompanyVerificationController::class, 'approve']
        );

        Route::post(
            '/company-verifications/{companyProfile}/reject',
            [AdminCompanyVerificationController::class, 'reject']
        );

        Route::get('/users', [AdminUserController::class, 'index']);
        Route::get('/users/{user}', [AdminUserController::class, 'show']);
        Route::post('/users/{user}/suspend', [AdminUserController::class, 'suspend']);
        Route::post('/users/{user}/activate', [AdminUserController::class, 'activate']);
        Route::post('/users/{user}/deactivate', [AdminUserController::class, 'deactivate']);
        /*
        |--------------------------------------------------------------------------
        | Suspension Appeals
        |--------------------------------------------------------------------------
        */

        Route::get('/account-appeals', [
            AdminAccountAppealController::class,
            'index',
        ]);

        Route::post('/account-appeals/{appeal}/approve', [
            AdminAccountAppealController::class,
            'approve',
        ]);

        Route::post('/account-appeals/{appeal}/reject', [
            AdminAccountAppealController::class,
            'reject',
        ]);
    });


/*
|--------------------------------------------------------------------------
| API Status
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return response()->json([
        'success' => true,
        'message' => 'WorkLink Africa API is running.',
        'version' => '1.0.0',
    ]);
});
