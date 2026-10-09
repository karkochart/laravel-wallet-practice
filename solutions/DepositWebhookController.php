<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DepositService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepositWebhookController extends Controller
{
    public function __invoke(Request $request, DepositService $service): JsonResponse
    {
        $data = $request->validate([
            'chain' => 'required|in:btc,eth,tron',
            'txid' => 'required|string|max:128',
            'vout' => 'sometimes|integer|min:0',
            'address' => 'required|string|max:128',
            'amount' => ['required', 'regex:/^[1-9][0-9]*$/'],
            'confirmations' => 'required|integer|min:0',
        ]);

        try {
            ['deposit' => $d, 'created' => $created] = $service->handle(
                $data['chain'], $data['txid'], (int) ($data['vout'] ?? 0),
                $data['address'], $data['amount'], (int) $data['confirmations'],
            );
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'unknown address'], 404);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['status' => $d->status, 'confirmations' => $d->confirmations], $created ? 201 : 200);
    }
}
