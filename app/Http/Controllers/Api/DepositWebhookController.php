<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepositWebhookController extends Controller
{
    public function __invoke(Request $request, DepositService $service): JsonResponse
    {
        throw new \RuntimeException('TODO: задача 2, см. docblock в tests/Feature/DepositWebhookTest.php');
    }
}
