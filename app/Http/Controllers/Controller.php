<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Workers App Uganda API',
    description: 'API for the Workers App Uganda platform, serving worker and homeowner mobile clients.'
)]
#[OA\Server(
    url: L5_SWAGGER_CONST_HOST,
    description: 'API server'
)]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'apiKey',
    name: 'Authorization',
    in: 'header',
    description: 'Enter token in format: Bearer {token}'
)]
abstract class Controller
{
    //
}