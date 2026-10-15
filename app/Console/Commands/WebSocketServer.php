<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Chat;
use App\Models\UserFollowers;
use App\Models\User;

class WebSocketServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'websocket:serve {--port=3500 : The port to listen on} {--host=0.0.0.0 : The host address to bind to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the native PHP WebSocket server for FlatMeta real-time avatar movement, typing callouts, and live chat';

    /**
     * Array of client connections:
     * resource_id => [
     *   'socket' => resource,
     *   'handshake' => bool,
     *   'buffer' => string,
     *   'ip' => string,
     *   'userId' => string|null,
     *   'username' => string|null,
     *   'lastLocation' => array|null,
     * ]
     *
     * @var array
     */
    protected $clients = [];

    /**
     * Master server stream socket.
     *
     * @var resource
     */
    protected $master;

    /**
     * Magic GUID for WebSocket RFC 6455 handshakes.
     */
    const WS_MAGIC_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $host = $this->option('host');
        $port = (int) $this->option('port');

        $address = "tcp://{$host}:{$port}";
        $context = stream_context_create([
            'socket' => [
                'so_reuseaddr' => 1,
                'backlog' => 512
            ]
        ]);

        $errno = 0;
        $errstr = '';
        $this->master = @stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

        if (!$this->master) {
            $this->error("Failed to start WebSocket server on {$address}: [{$errno}] {$errstr}");
            return 1;
        }

        stream_set_blocking($this->master, false);

        $this->info("=================================================");
        $this->info("  FlatMeta Native PHP WebSocket Server Running  ");
        $this->info("  Listening on: {$address}");
        $this->info("  Port: {$port} | Protocol: RFC 6455");
        $this->info("  Features: Presence, Disconnect Cleanup, Chat");
        $this->info("=================================================");

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
        }

        while (true) {
            $read = [$this->master];
            $write = null;
            $except = null;

            foreach ($this->clients as $id => $client) {
                if (is_resource($client['socket'])) {
                    $read[] = $client['socket'];
                } else {
                    $this->disconnectClient($id, 'Stale socket resource');
                }
            }

            $changed = @stream_select($read, $write, $except, 0, 200000);

            if ($changed === false) {
                break;
            }

            if ($changed > 0) {
                if (in_array($this->master, $read)) {
                    $newSocket = @stream_socket_accept($this->master, 0);
                    if ($newSocket) {
                        stream_set_blocking($newSocket, false);
                        $clientId = (int) $newSocket;
                        $this->clients[$clientId] = [
                            'socket' => $newSocket,
                            'handshake' => false,
                            'buffer' => '',
                            'ip' => @stream_socket_get_name($newSocket, true),
                            'userId' => null,
                            'username' => null,
                            'lastLocation' => null,
                        ];
                        $this->line("<fg=cyan>[+]</> Client #{$clientId} connected from {$this->clients[$clientId]['ip']} (Active: " . count($this->clients) . ")");
                    }
                    unset($read[array_search($this->master, $read)]);
                }

                foreach ($read as $clientSocket) {
                    $clientId = (int) $clientSocket;
                    if (!isset($this->clients[$clientId])) {
                        continue;
                    }

                    $data = @fread($clientSocket, 65536);

                    if ($data === false || $data === '') {
                        $this->disconnectClient($clientId, 'Connection closed by client');
                        continue;
                    }

                    if (!$this->clients[$clientId]['handshake']) {
                        $this->performHandshake($clientId, $data);
                    } else {
                        $this->processClientFrames($clientId, $data);
                    }
                }
            }
        }

        return 0;
    }

    /**
     * Perform RFC 6455 WebSocket Handshake and sync active online citizens.
     */
    protected function performHandshake(int $clientId, string $data): void
    {
        $client = &$this->clients[$clientId];
        $client['buffer'] .= $data;

        $headerEndPos = strpos($client['buffer'], "\r\n\r\n");
        if ($headerEndPos === false) {
            return;
        }

        $headers = substr($client['buffer'], 0, $headerEndPos);
        $client['buffer'] = substr($client['buffer'], $headerEndPos + 4);

        if (!preg_match("/Sec-WebSocket-Key: *(.*?)\r\n/i", $headers, $match)) {
            $this->disconnectClient($clientId, 'Handshake failed: Missing Sec-WebSocket-Key');
            return;
        }

        $key = trim($match[1]);
        $accept = base64_encode(sha1($key . self::WS_MAGIC_GUID, true));

        $response = "HTTP/1.1 101 Switching Protocols\r\n";
        $response .= "Upgrade: websocket\r\n";
        $response .= "Connection: Upgrade\r\n";
        $response .= "Sec-WebSocket-Accept: {$accept}\r\n";

        if (preg_match("/Sec-WebSocket-Protocol: *(.*?)\r\n/i", $headers, $protoMatch)) {
            $requestedProtocols = array_map('trim', explode(',', $protoMatch[1]));
            if (!empty($requestedProtocols[0])) {
                $response .= "Sec-WebSocket-Protocol: {$requestedProtocols[0]}\r\n";
            }
        }

        $response .= "\r\n";

        @fwrite($client['socket'], $response);
        $client['handshake'] = true;

        $this->line("<fg=green>[✓]</> Handshake completed for client #{$clientId}");

        // Send welcome packet
        $welcome = [
            'event' => 'connected',
            'data' => [
                'clientId' => $clientId,
                'server' => 'FlatMeta Laravel WebSocket Engine',
                'timestamp' => now()->toISOString()
            ]
        ];
        $this->sendToClient($clientId, json_encode($welcome));

        // Immediately send all currently online avatars' locations to this newly joined client
        foreach ($this->clients as $otherId => $otherClient) {
            if ($otherId !== $clientId && !empty($otherClient['handshake']) && !empty($otherClient['lastLocation'])) {
                $this->sendToClient($clientId, json_encode([
                    'event' => 'location',
                    'data' => $otherClient['lastLocation']
                ]));
            }
        }

        if (strlen($client['buffer']) > 0) {
            $this->processClientFrames($clientId, '');
        }
    }

    /**
     * Process incoming WebSocket frames from client according to RFC 6455.
     */
    protected function processClientFrames(int $clientId, string $data): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }

        $client = &$this->clients[$clientId];
        $client['buffer'] .= $data;

        while (strlen($client['buffer']) >= 2) {
            $bytes = array_values(unpack('C*', substr($client['buffer'], 0, 2)));
            $opcode = $bytes[0] & 0x0F;
            $isMasked = ($bytes[1] & 0x80) === 0x80;
            $payloadLength = $bytes[1] & 0x7F;

            $offset = 2;

            if ($payloadLength === 126) {
                if (strlen($client['buffer']) < $offset + 2) {
                    return;
                }
                $lenData = unpack('nlen', substr($client['buffer'], $offset, 2));
                $payloadLength = $lenData['len'];
                $offset += 2;
            } elseif ($payloadLength === 127) {
                if (strlen($client['buffer']) < $offset + 8) {
                    return;
                }
                $lenData = unpack('Jlen', substr($client['buffer'], $offset, 8));
                $payloadLength = $lenData['len'];
                $offset += 8;
            }

            $maskingKey = '';
            if ($isMasked) {
                if (strlen($client['buffer']) < $offset + 4) {
                    return;
                }
                $maskingKey = substr($client['buffer'], $offset, 4);
                $offset += 4;
            }

            if (strlen($client['buffer']) < $offset + $payloadLength) {
                return;
            }

            $rawPayload = substr($client['buffer'], $offset, $payloadLength);
            $client['buffer'] = substr($client['buffer'], $offset + $payloadLength);

            $payload = '';
            if ($isMasked && strlen($maskingKey) === 4) {
                for ($i = 0; $i < $payloadLength; $i++) {
                    $payload .= $rawPayload[$i] ^ $maskingKey[$i % 4];
                }
            } else {
                $payload = $rawPayload;
            }

            switch ($opcode) {
                case 0x1: // Text frame
                    $this->handleTextMessage($clientId, $payload);
                    break;

                case 0x8: // Close connection
                    $this->sendFrame($clientId, '', 0x8);
                    $this->disconnectClient($clientId, 'Client requested close');
                    return;

                case 0x9: // Ping
                    $this->sendFrame($clientId, $payload, 0xA);
                    break;

                case 0xA: // Pong
                    break;

                default:
                    break;
            }
        }
    }

    /**
     * Dispatch incoming message according to payload format.
     */
    protected function handleTextMessage(int $clientId, string $message): void
    {
        $decoded = json_decode($message, true);

        if (!$decoded || !is_array($decoded)) {
            return;
        }

        $event = $decoded['event'] ?? $decoded['type'] ?? null;
        $data = $decoded['data'] ?? $decoded['payload'] ?? $decoded;

        if (!$event) {
            if (isset($decoded['message']) || isset($decoded['roomId'])) {
                $event = 'message';
            } elseif (isset($decoded['location']) || isset($decoded['lat']) || isset($decoded['userId'])) {
                $event = 'location';
            } else {
                $event = 'message';
            }
        }

        switch ($event) {
            case 'message':
            case 'chatMessage':
                $this->handleChatMessage($clientId, is_array($data) ? $data : []);
                break;

            case 'location':
            case 'emitLocation':
                $this->handleLocationUpdate($clientId, is_array($data) ? $data : []);
                break;

            case 'typing':
                $this->handleTypingUpdate($clientId, is_array($data) ? $data : []);
                break;

            case 'user_disconnect':
                $uid = is_array($data) ? ($data['userId'] ?? null) : $data;
                if ($uid) {
                    $this->broadcast(json_encode([
                        'event' => 'user_disconnect',
                        'data' => ['userId' => (string) $uid]
                    ]), $clientId);
                }
                break;

            case 'ping':
                $this->sendToClient($clientId, json_encode(['event' => 'pong', 'timestamp' => now()->toISOString()]));
                break;

            default:
                $broadcastPayload = json_encode([
                    'event' => $event,
                    'data' => $data
                ]);
                $this->broadcast($broadcastPayload, $clientId);
                break;
        }
    }

    /**
     * Process chat messages, preserve rich sender/expression metadata, and persist to DB.
     */
    protected function handleChatMessage(int $clientId, array $data): void
    {
        $roomId = $data['roomId'] ?? $data['room_id'] ?? 'global_nearby';
        $senderId = $data['senderId'] ?? $data['sender_id'] ?? ($this->clients[$clientId]['userId'] ?? null);
        $msgText = trim($data['message'] ?? $data['text'] ?? '');
        $createdAt = $data['created_at'] ?? $data['createdAt'] ?? now()->toISOString();

        if ($msgText === '') {
            return;
        }

        // Resolve numeric room ID if string like "room_X"
        $numericRoomId = null;
        if (is_numeric($roomId)) {
            $numericRoomId = (int) $roomId;
        } elseif (is_string($roomId) && preg_match('/^room_(\d+)$/', $roomId, $m)) {
            $otherUserId = (int) $m[1];
            if (is_numeric($senderId)) {
                $myId = (int) $senderId;
                $conv = UserFollowers::where(function ($q) use ($myId, $otherUserId) {
                    $q->where('user_id', $myId)->where('follower_user_id', $otherUserId);
                })->orWhere(function ($q) use ($myId, $otherUserId) {
                    $q->where('user_id', $otherUserId)->where('follower_user_id', $myId);
                })->first();

                if (!$conv && User::where('id', $otherUserId)->exists() && User::where('id', $myId)->exists()) {
                    try {
                        $conv = UserFollowers::create([
                            'user_id' => $myId,
                            'follower_user_id' => $otherUserId,
                            'status' => '1',
                        ]);
                    } catch (\Throwable $t) {}
                }

                if ($conv) {
                    $numericRoomId = $conv->id;
                    $roomId = $conv->id;
                }
            }
        }

        if ($numericRoomId && is_numeric($senderId)) {
            try {
                Chat::create([
                    'room_id' => $numericRoomId,
                    'sender_id' => (int) $senderId,
                    'message' => $msgText,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                $this->warn('Chat DB insert notice: ' . $e->getMessage());
            }
        }

        $payload = [
            'id' => $data['id'] ?? ('msg_' . time() . '_' . mt_rand(100, 999)),
            'roomId' => $roomId,
            'room_id' => $roomId,
            'scope' => $data['scope'] ?? 'nearby',
            'senderId' => $senderId,
            'sender_id' => $senderId,
            'senderName' => $data['senderName'] ?? $data['fullname'] ?? ($this->clients[$clientId]['username'] ?? 'Citizen'),
            'senderUsername' => $data['senderUsername'] ?? $data['username'] ?? ($this->clients[$clientId]['username'] ?? 'citizen'),
            'senderAvatar' => $data['senderAvatar'] ?? $data['image'] ?? '',
            'recipientId' => $data['recipientId'] ?? null,
            'message' => $msgText,
            'expression' => $data['expression'] ?? null,
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'created_at' => $createdAt,
            'createdAt' => $createdAt
        ];

        $json = json_encode([
            'event' => 'message',
            'data' => $payload
        ]);

        $this->line("<fg=yellow>[CHAT]</> Client #{$clientId} (Room: {$roomId}): " . substr($msgText, 0, 40));

        $this->broadcast($json);
    }

    /**
     * Process live typing callouts and broadcast to nearby citizens.
     */
    protected function handleTypingUpdate(int $clientId, array $data): void
    {
        $payload = [
            'userId' => $data['userId'] ?? ($this->clients[$clientId]['userId'] ?? null),
            'username' => $data['username'] ?? ($this->clients[$clientId]['username'] ?? 'Citizen'),
            'text' => $data['text'] ?? '',
        ];

        $this->broadcast(json_encode([
            'event' => 'typing',
            'data' => $payload
        ]), $clientId);
    }

    /**
     * Process avatar location updates, store last known state for new joiners, and broadcast.
     */
    protected function handleLocationUpdate(int $clientId, array $data): void
    {
        $locData = $data['data'] ?? $data;
        if (isset($this->clients[$clientId]) && is_array($locData)) {
            if (!empty($locData['userId'])) {
                $this->clients[$clientId]['userId'] = (string) $locData['userId'];
            }
            if (!empty($locData['username'])) {
                $this->clients[$clientId]['username'] = (string) $locData['username'];
            }
            $this->clients[$clientId]['lastLocation'] = $locData;
        }

        $json = json_encode([
            'event' => 'location',
            'data' => $locData
        ]);

        $this->broadcast($json, $clientId);
    }

    /**
     * Broadcast WebSocket frame to connected clients.
     */
    protected function broadcast(string $message, ?int $excludeClientId = null): void
    {
        foreach ($this->clients as $id => $client) {
            if ($excludeClientId !== null && $id === $excludeClientId) {
                continue;
            }
            if ($client['handshake']) {
                $this->sendToClient($id, $message);
            }
        }
    }

    /**
     * Send text frame to specific client.
     */
    protected function sendToClient(int $clientId, string $message): void
    {
        $this->sendFrame($clientId, $message, 0x1);
    }

    /**
     * Encode and send RFC 6455 frame to client.
     */
    protected function sendFrame(int $clientId, string $payload, int $opcode = 0x1): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }

        $length = strlen($payload);
        $header = chr(0x80 | $opcode);

        if ($length <= 125) {
            $header .= chr($length);
        } elseif ($length <= 65535) {
            $header .= chr(126) . pack('n', $length);
        } else {
            $header .= chr(127) . pack('J', $length);
        }

        $frame = $header . $payload;
        @fwrite($this->clients[$clientId]['socket'], $frame);
    }

    /**
     * Cleanly disconnect a client and broadcast `user_disconnect` so zombie avatars are removed.
     */
    protected function disconnectClient(int $clientId, string $reason = ''): void
    {
        if (isset($this->clients[$clientId])) {
            $disconnectedUserId = $this->clients[$clientId]['userId'] ?? null;

            if (is_resource($this->clients[$clientId]['socket'])) {
                @fclose($this->clients[$clientId]['socket']);
            }
            unset($this->clients[$clientId]);

            $this->line("<fg=red>[-]</> Client #{$clientId} disconnected ({$reason}). Active: " . count($this->clients));

            if ($disconnectedUserId) {
                // Only broadcast disconnect if this userId has no other open socket
                $stillConnected = false;
                foreach ($this->clients as $c) {
                    if (($c['userId'] ?? null) === $disconnectedUserId) {
                        $stillConnected = true;
                        break;
                    }
                }
                if (!$stillConnected) {
                    $this->broadcast(json_encode([
                        'event' => 'user_disconnect',
                        'data' => ['userId' => (string) $disconnectedUserId]
                    ]));
                }
            }
        }
    }

    /**
     * Graceful server shutdown.
     */
    public function shutdown(): void
    {
        $this->info("\nShutting down FlatMeta WebSocket server...");
        foreach (array_keys($this->clients) as $clientId) {
            $this->disconnectClient($clientId, 'Server shutting down');
        }
        if (is_resource($this->master)) {
            @fclose($this->master);
        }
        exit(0);
    }
}
