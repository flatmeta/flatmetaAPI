<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Real-time layer for the map: avatar positions, typing callouts, nearby chat
 * and direct messages, plus "land changed" notices.
 *
 * Who a client is comes from the `hello` event. Signed-in users send their API
 * token and are identified by their user id; guests get a `guest_…` id and can
 * never claim a numeric (signed-in) id. Every relayed event is re-stamped with
 * the sender's identity, so nobody can speak or move as someone else. Direct
 * messages only go to the recipient's sockets. Messages are not stored here;
 * the app saves direct messages through the authenticated REST API.
 */
class WebSocketServer extends Command
{
    protected $signature = 'websocket:serve {--port=3500 : The port to listen on} {--host=0.0.0.0 : The host address to bind to}';

    protected $description = 'Start the FlatMeta WebSocket server (avatars, typing, chat)';

    const WS_MAGIC_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** Largest single message we accept (bytes). */
    const MAX_PAYLOAD = 65536;
    /** Largest amount of unprocessed data we keep per client (bytes). */
    const MAX_BUFFER = 262144;
    /** Messages per client per second before we start dropping them. */
    const RATE_LIMIT = 30;

    /**
     * resource id => [socket, handshake, buffer, ip, userId, verified, username, fullname,
     *                 avatar, image, lastLocation, rateWindow, rateCount, tilesNotices]
     */
    protected $clients = [];

    protected $master;

    public function handle()
    {
        $address = "tcp://{$this->option('host')}:" . (int) $this->option('port');
        $context = stream_context_create(['socket' => ['so_reuseaddr' => 1, 'backlog' => 512]]);
        $this->master = @stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if (!$this->master) {
            $this->error("Failed to start WebSocket server on {$address}: [{$errno}] {$errstr}");
            return 1;
        }
        stream_set_blocking($this->master, false);
        $this->info("FlatMeta WebSocket server listening on {$address}");

        // Clean shutdown on Ctrl+C / supervisor stop. Some hosts (e.g. HestiaCP) disable part of pcntl;
        // without it the process still stops on SIGTERM, just without closing sockets first.
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
        }

        while (true) {
            $read = [$this->master];
            $write = $except = null;
            foreach ($this->clients as $id => $client) {
                if (is_resource($client['socket'])) {
                    $read[] = $client['socket'];
                } else {
                    $this->disconnectClient($id, 'stale socket');
                }
            }

            if (@stream_select($read, $write, $except, 0, 200000) === false) {
                break;
            }

            foreach ($read as $socket) {
                if ($socket === $this->master) {
                    $this->acceptClient();
                    continue;
                }
                $clientId = (int) $socket;
                if (!isset($this->clients[$clientId])) {
                    continue;
                }
                $data = @fread($socket, 65536);
                if ($data === false || $data === '') {
                    $this->disconnectClient($clientId, 'closed by client');
                    continue;
                }
                if (strlen($this->clients[$clientId]['buffer']) + strlen($data) > self::MAX_BUFFER) {
                    $this->disconnectClient($clientId, 'buffer limit');
                    continue;
                }
                if (!$this->clients[$clientId]['handshake']) {
                    $this->performHandshake($clientId, $data);
                } else {
                    $this->processClientFrames($clientId, $data);
                }
            }
        }

        return 0;
    }

    protected function acceptClient(): void
    {
        $socket = @stream_socket_accept($this->master, 0);
        if (!$socket) {
            return;
        }
        stream_set_blocking($socket, false);
        $id = (int) $socket;
        $this->clients[$id] = [
            'socket' => $socket,
            'handshake' => false,
            'buffer' => '',
            'ip' => @stream_socket_get_name($socket, true),
            'userId' => null,
            'verified' => false,
            'username' => null,
            'fullname' => null,
            'avatar' => null,
            'image' => '',
            'lastLocation' => null,
            'rateWindow' => 0,
            'rateCount' => 0,
            'tilesNotices' => [],
        ];
    }

    // -------------------------------------------------------------------------
    // RFC 6455 framing
    // -------------------------------------------------------------------------

    protected function performHandshake(int $clientId, string $data): void
    {
        $client = &$this->clients[$clientId];
        $client['buffer'] .= $data;
        $end = strpos($client['buffer'], "\r\n\r\n");
        if ($end === false) {
            if (strlen($client['buffer']) > 8192) {
                $this->disconnectClient($clientId, 'oversized handshake');
            }
            return;
        }
        $headers = substr($client['buffer'], 0, $end);
        $client['buffer'] = substr($client['buffer'], $end + 4);

        if (!preg_match('/^GET /', $headers)
            || !preg_match('/^Upgrade:\s*websocket\s*$/mi', $headers)
            || !preg_match("/Sec-WebSocket-Key: *(.*?)\r\n/i", $headers . "\r\n", $match)) {
            @fwrite($client['socket'], "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
            $this->disconnectClient($clientId, 'bad handshake');
            return;
        }

        $accept = base64_encode(sha1(trim($match[1]) . self::WS_MAGIC_GUID, true));
        $this->writeRaw($clientId, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");
        $client['handshake'] = true;

        $this->send($clientId, 'connected', ['clientId' => $clientId, 'timestamp' => now()->toISOString()]);
        // Show the newcomer everyone who is already on the map.
        foreach ($this->clients as $otherId => $other) {
            if ($otherId !== $clientId && $other['handshake'] && $other['lastLocation']) {
                $this->send($clientId, 'location', $other['lastLocation']);
            }
        }

        if ($client['buffer'] !== '') {
            $this->processClientFrames($clientId, '');
        }
    }

    protected function processClientFrames(int $clientId, string $data): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }
        $client = &$this->clients[$clientId];
        $client['buffer'] .= $data;

        while (strlen($client['buffer']) >= 2) {
            $b0 = ord($client['buffer'][0]);
            $b1 = ord($client['buffer'][1]);
            $opcode = $b0 & 0x0F;
            $masked = ($b1 & 0x80) === 0x80;
            $length = $b1 & 0x7F;
            $offset = 2;

            if ($length === 126) {
                if (strlen($client['buffer']) < 4) {
                    return;
                }
                $length = unpack('n', substr($client['buffer'], 2, 2))[1];
                $offset = 4;
            } elseif ($length === 127) {
                if (strlen($client['buffer']) < 10) {
                    return;
                }
                $length = unpack('J', substr($client['buffer'], 2, 8))[1];
                $offset = 10;
            }

            // Browsers always mask client frames; anything else is not a real client.
            if (!$masked || $length > self::MAX_PAYLOAD) {
                $this->disconnectClient($clientId, $masked ? 'payload too large' : 'unmasked frame');
                return;
            }
            if (strlen($client['buffer']) < $offset + 4 + $length) {
                return;
            }

            $mask = substr($client['buffer'], $offset, 4);
            $raw = substr($client['buffer'], $offset + 4, $length);
            $client['buffer'] = (string) substr($client['buffer'], $offset + 4 + $length);
            $payload = $raw ^ str_repeat($mask, intdiv($length, 4) + 1);
            $payload = substr($payload, 0, $length);

            switch ($opcode) {
                case 0x1:
                    $this->handleTextMessage($clientId, $payload);
                    break;
                case 0x8:
                    $this->sendFrame($clientId, '', 0x8);
                    $this->disconnectClient($clientId, 'client closed');
                    return;
                case 0x9:
                    $this->sendFrame($clientId, $payload, 0xA);
                    break;
                default:
                    break; // pong / continuation / binary: ignored
            }
            if (!isset($this->clients[$clientId])) {
                return;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Events
    // -------------------------------------------------------------------------

    protected function handleTextMessage(int $clientId, string $message): void
    {
        if (!$this->withinRateLimit($clientId)) {
            return;
        }
        $decoded = json_decode($message, true);
        if (!is_array($decoded)) {
            return;
        }
        $event = (string) ($decoded['event'] ?? $decoded['type'] ?? '');
        $data = $decoded['data'] ?? [];
        if (!is_array($data)) {
            $data = [];
        }

        switch ($event) {
            case 'hello':
                $this->handleHello($clientId, $data);
                break;
            case 'location':
            case 'emitLocation':
                $this->ensureIdentity($clientId, $data);
                $this->handleLocation($clientId, $data);
                break;
            case 'typing':
                $this->ensureIdentity($clientId, $data);
                $this->handleTyping($clientId, $data);
                break;
            case 'message':
            case 'chatMessage':
                $this->ensureIdentity($clientId, $data);
                $this->handleChat($clientId, $data);
                break;
            case 'tiles_updated':
                $this->handleTilesUpdated($clientId, $data);
                break;
            case 'ping':
                $this->send($clientId, 'pong', ['timestamp' => now()->toISOString()]);
                break;
            default:
                // Unknown events are dropped instead of relayed to everyone.
                break;
        }
    }

    /**
     * Identifies the client. A valid API token makes it the signed-in user;
     * otherwise it is a guest whose id must look like guest_xxxx.
     */
    protected function handleHello(int $clientId, array $data): void
    {
        $client = &$this->clients[$clientId];
        $previousId = $client['userId'];

        $user = !empty($data['token']) && is_string($data['token']) ? $this->userFromToken($data['token']) : null;
        if ($user) {
            $client['userId'] = (string) $user->id;
            $client['verified'] = true;
            $client['username'] = $user->username;
            $client['fullname'] = $user->fullname;
        } else {
            $client['userId'] = $this->guestId($data['userId'] ?? null, $clientId);
            $client['verified'] = false;
            $client['username'] = $this->cleanText($data['username'] ?? '', 40) ?: $client['userId'];
            $client['fullname'] = $this->cleanText($data['fullname'] ?? '', 60) ?: 'Guest';
        }
        $client['avatar'] = $this->cleanAvatar($data['avatar'] ?? null);
        $client['image'] = $this->cleanImage($data['image'] ?? '');

        if ($previousId && $previousId !== $client['userId'] && !$this->userOnline($previousId, $clientId)) {
            $this->broadcast('user_disconnect', ['userId' => $previousId], $clientId);
        }
        $this->send($clientId, 'welcome', ['userId' => $client['userId'], 'verified' => $client['verified']]);
    }

    /** Older clients send identity inside location/message payloads instead of a hello. */
    protected function ensureIdentity(int $clientId, array $data): void
    {
        if ($this->clients[$clientId]['userId'] === null) {
            $this->handleHello($clientId, array_diff_key($data, ['token' => true]));
        }
    }

    protected function handleLocation(int $clientId, array $data): void
    {
        $loc = $data['location'] ?? $data;
        $lat = isset($loc['lat']) ? (float) $loc['lat'] : (isset($loc[0]) ? (float) $loc[0] : null);
        $lng = isset($loc['lng']) ? (float) $loc['lng'] : (isset($loc[1]) ? (float) $loc[1] : null);
        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
            return;
        }
        $client = &$this->clients[$clientId];
        $payload = $this->identity($clientId) + [
            'expression' => $this->cleanText($data['expression'] ?? '', 20) ?: null,
            'location' => ['lat' => round($lat, 6), 'lng' => round($lng, 6)],
        ];
        $client['lastLocation'] = $payload;
        $this->broadcast('location', $payload, $clientId);
    }

    protected function handleTyping(int $clientId, array $data): void
    {
        $this->broadcast('typing', [
            'userId' => $this->clients[$clientId]['userId'],
            'username' => $this->clients[$clientId]['username'],
            'text' => $this->cleanText($data['text'] ?? '', 140),
        ], $clientId);
    }

    protected function handleChat(int $clientId, array $data): void
    {
        $text = $this->cleanText($data['message'] ?? $data['text'] ?? '', 1000);
        if ($text === '') {
            return;
        }
        $client = $this->clients[$clientId];
        $direct = ($data['scope'] ?? '') === 'direct' || !empty($data['recipientId']);
        $recipient = $direct ? $this->cleanText((string) ($data['recipientId'] ?? ''), 40) : null;
        if ($direct && $recipient === '') {
            return;
        }

        $payload = [
            'id' => $this->cleanText($data['id'] ?? '', 80) ?: ('msg_' . $client['userId'] . '_' . microtime(true)),
            'scope' => $direct ? 'direct' : 'nearby',
            'roomId' => $direct ? 'direct' : 'global_nearby',
            'senderId' => $client['userId'],
            'senderName' => $client['fullname'],
            'senderUsername' => $client['username'],
            'recipientId' => $recipient,
            'message' => $text,
            'lat' => isset($data['lat']) && is_numeric($data['lat']) ? (float) $data['lat'] : null,
            'lng' => isset($data['lng']) && is_numeric($data['lng']) ? (float) $data['lng'] : null,
            'created_at' => now()->toISOString(),
        ];

        if ($direct) {
            // Only the recipient (all their tabs) and the sender's other tabs get a private message.
            foreach ($this->clients as $id => $other) {
                if ($id !== $clientId && $other['handshake'] && in_array($other['userId'], [$recipient, $client['userId']], true)) {
                    $this->send($id, 'message', $payload);
                }
            }
            return;
        }
        $this->broadcast('message', $payload, $clientId);
    }

    /**
     * "My land changed": tells everyone else to reload it. `bounds`
     * {minLat, minLng, maxLat, maxLng} is the changed area, so clients only
     * refetch when it overlaps what they are showing; without it (or when it is
     * malformed or huge) clients reload their current view.
     */
    protected function handleTilesUpdated(int $clientId, array $data): void
    {
        $now = microtime(true);
        // Up to 5 notices per 2 seconds per connection (claim + image + details can follow each other).
        $recent = array_values(array_filter($this->clients[$clientId]['tilesNotices'], fn ($t) => $now - $t < 2.0));
        if (count($recent) >= 5) {
            return;
        }
        $recent[] = $now;
        $this->clients[$clientId]['tilesNotices'] = $recent;

        $payload = ['by' => $this->clients[$clientId]['userId']];
        $bounds = $this->cleanBounds($data['bounds'] ?? null);
        if ($bounds) {
            $payload['bounds'] = $bounds;
        }
        $this->broadcast('tiles_updated', $payload, $clientId);
    }

    /** {minLat, minLng, maxLat, maxLng} as floats, or null when malformed or wider than 10 degrees. */
    protected function cleanBounds($b): ?array
    {
        if (!is_array($b)) {
            return null;
        }
        $out = [];
        foreach (['minLat', 'minLng', 'maxLat', 'maxLng'] as $k) {
            if (!isset($b[$k]) || !is_numeric($b[$k])) {
                return null;
            }
            $out[$k] = round((float) $b[$k], 7);
        }
        if ($out['minLat'] < -90 || $out['maxLat'] > 90 || $out['minLat'] > $out['maxLat']
            || $out['minLng'] < -180 || $out['maxLng'] > 180 || $out['minLng'] > $out['maxLng']
            || $out['maxLat'] - $out['minLat'] > 10 || $out['maxLng'] - $out['minLng'] > 10) {
            return null;
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function identity(int $clientId): array
    {
        $c = $this->clients[$clientId];
        return [
            'userId' => $c['userId'],
            'username' => $c['username'],
            'fullname' => $c['fullname'],
            'avatar' => $c['avatar'],
            'image' => $c['image'],
            'verified' => $c['verified'],
        ];
    }

    protected function userFromToken(string $token)
    {
        try {
            Auth::forgetGuards();
            $request = Request::create('/', 'GET');
            $request->headers->set('Authorization', 'Bearer ' . $token);
            $request->headers->set('Accept', 'application/json');
            return Auth::guard('sanctum')->setRequest($request)->user();
        } catch (\Throwable $e) {
            // The DB connection may have dropped in this long-running process; retry once.
            try {
                DB::reconnect();
                Auth::forgetGuards();
                return Auth::guard('sanctum')->setRequest($request)->user();
            } catch (\Throwable $e2) {
                $this->warn('Token check failed: ' . $e2->getMessage());
                return null;
            }
        }
    }

    protected function guestId($claimed, int $clientId): string
    {
        if (is_string($claimed) && preg_match('/^guest_[a-z0-9]{3,24}$/', $claimed)) {
            return $claimed;
        }
        return 'guest_c' . $clientId;
    }

    protected function cleanText($value, int $max): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        // Strip control characters; the app escapes HTML when rendering.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        return mb_substr($text, 0, $max);
    }

    protected function cleanAvatar($avatar): ?array
    {
        if (!is_array($avatar)) {
            return null;
        }
        $clean = [];
        foreach ($avatar as $key => $value) {
            if (count($clean) >= 12 || !is_string($key) || !preg_match('/^[a-zA-Z]{1,20}$/', $key) || !is_string($value)) {
                continue;
            }
            if (preg_match('/^(#[0-9a-fA-F]{3,8}|[A-Za-z0-9]{1,40})$/', $value)) {
                $clean[$key] = $value;
            }
        }
        return $clean ?: null;
    }

    protected function cleanImage($image): string
    {
        return is_string($image) && strlen($image) <= 500 && preg_match('#^https?://[^\s"\'<>]+$#i', $image) ? $image : '';
    }

    protected function withinRateLimit(int $clientId): bool
    {
        $c = &$this->clients[$clientId];
        $second = time();
        if ($c['rateWindow'] !== $second) {
            $c['rateWindow'] = $second;
            $c['rateCount'] = 0;
        }
        return ++$c['rateCount'] <= self::RATE_LIMIT;
    }

    protected function userOnline(string $userId, int $exceptClientId): bool
    {
        foreach ($this->clients as $id => $c) {
            if ($id !== $exceptClientId && $c['userId'] === $userId) {
                return true;
            }
        }
        return false;
    }

    protected function send(int $clientId, string $event, $data): void
    {
        $this->sendFrame($clientId, json_encode(['event' => $event, 'data' => $data]), 0x1);
    }

    protected function broadcast(string $event, $data, ?int $exceptClientId = null): void
    {
        $json = json_encode(['event' => $event, 'data' => $data]);
        foreach ($this->clients as $id => $client) {
            if ($id !== $exceptClientId && $client['handshake']) {
                $this->sendFrame($id, $json, 0x1);
            }
        }
    }

    protected function sendFrame(int $clientId, string $payload, int $opcode = 0x1): void
    {
        $length = strlen($payload);
        $header = chr(0x80 | $opcode);
        if ($length <= 125) {
            $header .= chr($length);
        } elseif ($length <= 65535) {
            $header .= chr(126) . pack('n', $length);
        } else {
            $header .= chr(127) . pack('J', $length);
        }
        $this->writeRaw($clientId, $header . $payload);
    }

    /** Non-blocking sockets can accept only part of a write; keep writing for a moment. */
    protected function writeRaw(int $clientId, string $bytes): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }
        $socket = $this->clients[$clientId]['socket'];
        $deadline = microtime(true) + 0.5;
        while ($bytes !== '') {
            $written = @fwrite($socket, $bytes);
            if ($written === false) {
                $this->disconnectClient($clientId, 'write failed');
                return;
            }
            $bytes = (string) substr($bytes, $written);
            if ($bytes !== '') {
                if (microtime(true) > $deadline) {
                    $this->disconnectClient($clientId, 'slow client');
                    return;
                }
                usleep(1000);
            }
        }
    }

    protected function disconnectClient(int $clientId, string $reason = ''): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }
        $userId = $this->clients[$clientId]['userId'];
        if (is_resource($this->clients[$clientId]['socket'])) {
            @fclose($this->clients[$clientId]['socket']);
        }
        unset($this->clients[$clientId]);

        // Only remove the avatar once the user has no other open tab.
        if ($userId && !$this->userOnline($userId, -1)) {
            $this->broadcast('user_disconnect', ['userId' => $userId]);
        }
    }

    public function shutdown(): void
    {
        foreach (array_keys($this->clients) as $clientId) {
            $this->disconnectClient($clientId, 'server shutting down');
        }
        if (is_resource($this->master)) {
            @fclose($this->master);
        }
        exit(0);
    }
}
