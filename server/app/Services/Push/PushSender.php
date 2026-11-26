<?php

namespace App\Services\Push;

use App\Models\PushToken;
use Illuminate\Support\Facades\Log;

/**
 * Sends a push to one account's devices (any role), or to every customer's, and deletes
 * the tokens FCM reports dead. Called from the queue (SendPushNotification,
 * PushToEveryCustomer), so no request waits on Firebase.
 *
 * Without Firebase credentials it does nothing: the in-app notification is
 * the record, and the push is only a way of drawing attention to it.
 */
class PushSender
{
    private const CHUNK = 500;

    public function __construct(private FcmClient $fcm) {}

    public function isConfigured(): bool
    {
        return $this->fcm->isConfigured();
    }

    /**
     * One account's devices, whatever its role.
     *
     * @param  array<string, string|int|null>  $data
     */
    public function toUser(int $userId, string $title, string $body, array $data = []): PushResult
    {
        $tokens = PushToken::query()
            ->where('user_id', $userId)
            ->pluck('token')
            ->all();

        return $this->deliver($tokens, $title, $body, $data);
    }

    /** @param  array<string, string|int|null>  $data */
    public function toEveryCustomer(string $title, string $body, array $data = []): PushResult
    {
        $result = new PushResult;

        PushToken::query()
            ->forCustomers()
            ->select(['id', 'token'])
            ->chunkById(self::CHUNK, function ($rows) use ($result, $title, $body, $data) {
                $result->merge($this->deliver($rows->pluck('token')->all(), $title, $body, $data));
            });

        return $result;
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, string|int|null>  $data
     */
    private function deliver(array $tokens, string $title, string $body, array $data): PushResult
    {
        if ($tokens === [] || ! $this->fcm->isConfigured()) {
            return new PushResult;
        }

        $result = $this->fcm->send($tokens, $title, $body, array_filter(
            $data,
            fn ($value) => $value !== null && $value !== ''
        ));

        if ($result->invalid !== []) {
            PushToken::query()->whereIn('token', $result->invalid)->delete();
        }

        if ($result->failed !== []) {
            // Reasons only: a token is a delivery address and stays out of the log.
            Log::warning('Some pushes could not be delivered.', [
                'failed' => count($result->failed),
                'reasons' => array_values(array_unique($result->failed)),
            ]);
        }

        return $result;
    }
}
