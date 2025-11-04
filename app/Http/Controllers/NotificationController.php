<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    public function enqueue(Request $request)
    {
        $v = Validator::make($request->all(), [
            'recipient' => 'required|string',
            'channel' => 'required|in:email,sms',
            'message' => 'required|string',
            'idempotency_key' => 'nullable|string|max:255'
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $payload = $request->only(['recipient','channel','message']);
        $idempotencyKey = $request->input('idempotency_key');

        return DB::transaction(function () use ($payload, $idempotencyKey) {
            if ($idempotencyKey) {
                $existing = DB::table('notification_jobs')->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return response()->json(['job_id' => $existing->id, 'status' => $existing->status], 201);
                }
            }
            $id = DB::table('notification_jobs')->insertGetId([
                'channel' => $payload['channel'],
                'recipient' => $payload['recipient'],
                'message' => $payload['message'],
                'status' => 'PENDING',
                'attempts' => 0,
                'max_attempts' => 5,
                'next_run_at' => now(),
                'idempotency_key' => $idempotencyKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return response()->json(['job_id' => $id, 'status' => 'PENDING'], 201);
        });
    }

    public function stats()
    {
        $rows = DB::table('notification_jobs')
            ->selectRaw("status, COUNT(*) as c, AVG(attempts) as avg_attempts")
            ->groupBy('status')->get();

        $map = ['PENDING'=>0,'RETRY'=>0,'PROCESSING'=>0,'SUCCESS'=>0,'FAILED'=>0];
        $avgSuccess = 0.0;
        foreach ($rows as $r) {
            $map[$r->status] = (int)$r->c;
            if ($r->status === 'SUCCESS') $avgSuccess = (float)$r->avg_attempts;
        }
        return response()->json([
            'pending'=>$map['PENDING'],
            'retry'=>$map['RETRY'],
            'processing'=>$map['PROCESSING'],
            'success'=>$map['SUCCESS'],
            'failed'=>$map['FAILED'],
            'avg_attempts_success'=> round($avgSuccess,2)
        ]);
    }
}
