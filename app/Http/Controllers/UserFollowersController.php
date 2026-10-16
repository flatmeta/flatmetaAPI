<?php

namespace App\Http\Controllers;

use DateTime;
use App\Models\Chat;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\UserFollowers;

class UserFollowersController extends Controller
{
    protected function baseUrl(): string
    {
        $base = config('app.url') ?: env('APP_URL', url('/'));
        return rtrim($base, '/') . '/';
    }

    protected function formatUserImage(?string $image): string
    {
        $userfilePath = $this->baseUrl() . 'assets/uploads/users/';
        if (empty($image)) {
            return $userfilePath . 'noimage.png';
        }
        if (str_starts_with($image, 'data:') || str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
            return $image;
        }
        return $userfilePath . ltrim($image, '/');
    }

    public function SendFriendRequest(Request $request)
    {
        $userdata = $request->user();

        try {
            $myId = $userdata['id'];
            $follower_user_id = $request->follower_user_id;

            if (!$myId || !$follower_user_id || (int) $follower_user_id === (int) $myId) {
                return response()->json(['status' => false, 'data' => ['message' => 'Invalid user IDs']]);
            }

            $existing = UserFollowers::where(function ($q) use ($myId, $follower_user_id) {
                $q->where('user_id', $myId)->where('follower_user_id', $follower_user_id);
            })->orWhere(function ($q) use ($myId, $follower_user_id) {
                $q->where('user_id', $follower_user_id)->where('follower_user_id', $myId);
            })->first();

            if ($existing) {
                if ($existing->status == '3') {
                    $existing->status = '2';
                    $existing->save();
                }
                return response()->json([
                    'status' => true,
                    'data' => ['message' => 'Friend request already active.'],
                ]);
            }

            $followers = new UserFollowers();
            $followers->user_id = $myId;
            $followers->follower_user_id = $follower_user_id;
            $followers->status = '2';

            if ($followers->save()) {
                $data['message'] = 'Friend request sent successfully.';
                return response()->json(['status' => true, 'data' => $data]);
            } else {
                $data['message'] = 'Something went wrong.';
                return response()->json(['status' => false, 'data' => $data]);
            }
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function GetAllFriendRequest(Request $request)
    {
        $userdata = $request->user();

        try {
            $myId = $userdata['id'];
            $friendrequests = UserFollowers::where('follower_user_id', $myId)->get();
            $data = ['requests' => []];

            if (!empty($friendrequests)) {
                foreach ($friendrequests as $friend) {
                    $followerData = User::where('id', $friend->user_id)->first();
                    if ($followerData) {
                        $data['requests'][] = [
                            'request_id' => $friend->id,
                            'user_id' => $followerData->id,
                            'username' => $followerData->username,
                            'fullname' => $followerData->fullname,
                            'image' => $this->formatUserImage($followerData->image),
                            'message' => $followerData->username . ' sent you a friend request.',
                            'status' => $friend->status,
                            'created_at' => $this->time_elapsed_string($friend->created_at),
                        ];
                    }
                }
            }

            return response()->json(['status' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    /** Accepted connections in either direction, with the chat room id for each. */
    public function GetFriends(Request $request)
    {
        $myId = (int) $request->user()->id;
        $rows = UserFollowers::where('status', '1')
            ->where(function ($q) use ($myId) {
                $q->where('user_id', $myId)->orWhere('follower_user_id', $myId);
            })
            ->get();

        $friends = [];
        foreach ($rows as $row) {
            $otherId = (int) $row->user_id === $myId ? (int) $row->follower_user_id : (int) $row->user_id;
            $other = User::where('id', $otherId)->where('status', '!=', '3')->first();
            if ($other) {
                $friends[] = [
                    'user_id' => $other->id,
                    'username' => $other->username,
                    'fullname' => $other->fullname,
                    'image' => $this->formatUserImage($other->image),
                    'room_id' => $row->id,
                ];
            }
        }
        return response()->json(['status' => true, 'data' => ['friends' => $friends]]);
    }

    public function UpdateFriendRequestStatus(Request $request)
    {
        try {
            $id = $request->request_id;
            // Only the user the request was sent to may accept ('1') or decline ('3') it.
            $friendrequests = UserFollowers::where('id', $id)
                ->where('follower_user_id', $request->user()->id)
                ->first();
            if (!$friendrequests) {
                return response()->json(['status' => false, 'data' => ['message' => 'Request not found']]);
            }
            if (!in_array((string) $request->status, ['1', '3'], true)) {
                return response()->json(['status' => false, 'data' => ['message' => 'Invalid status']]);
            }

            $friendrequests->status = $request->status;
            $data['message'] = $friendrequests->status == '1'
                ? 'Friend request accepted successfully'
                : 'Friend request declined successfully';

            if ($friendrequests->save()) {
                return response()->json(['status' => true, 'data' => $data]);
            } else {
                return response()->json(['status' => false, 'data' => $data]);
            }
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function time_elapsed_string($datetime, $full = false)
    {
        if (empty($datetime)) {
            return 'just now';
        }
        $now = new DateTime();
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);

        $diff->w = floor($diff->d / 7);
        $diff->d -= $diff->w * 7;

        $string = [
            'y' => 'year',
            'm' => 'month',
            'w' => 'week',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'minute',
            's' => 'second',
        ];
        foreach ($string as $k => &$v) {
            if ($diff->$k) {
                $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }

        if (!$full) {
            $string = array_slice($string, 0, 1);
        }
        return $string ? implode(', ', $string) . ' ago' : 'just now';
    }

    public function GetRoomId(Request $request)
    {
        $myId = (int) $request->user()->id;
        $otherId = (int) $request->follower_user_id;

        try {
            if (!$otherId || $otherId === $myId || !User::where('id', $otherId)->exists()) {
                return response()->json(['status' => false, 'message' => 'Invalid user ID']);
            }

            $conversation = UserFollowers::where(function ($q) use ($myId, $otherId) {
                $q->where('user_id', $myId)->where('follower_user_id', $otherId);
            })->orWhere(function ($q) use ($myId, $otherId) {
                $q->where('user_id', $otherId)->where('follower_user_id', $myId);
            })->first();

            // Opening a chat creates a pending friend request at most; acceptance only happens
            // through UpdateFriendRequestStatus by the recipient.
            if (!$conversation) {
                $conversation = UserFollowers::create([
                    'user_id' => $myId,
                    'follower_user_id' => $otherId,
                    'status' => '2',
                ]);
            }

            $roomId = $conversation->id;
            $messages = Chat::where('room_id', $roomId)->orderBy('id', 'asc')->get();

            $data = [
                'room_id' => $roomId,
                'roomId' => $roomId,
                'messages' => [],
            ];

            foreach ($messages as $message) {
                $data['messages'][] = [
                    'id' => $message->id,
                    'roomId' => $message->room_id,
                    'room_id' => $message->room_id,
                    'senderId' => $message->sender_id,
                    'sender_id' => $message->sender_id,
                    'message' => $message->message,
                    'createdAt' => $message->created_at ? $message->created_at->toISOString() : null,
                    'created_at' => $message->created_at ? $message->created_at->toISOString() : null,
                ];
            }

            return response()->json(['status' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }
}
