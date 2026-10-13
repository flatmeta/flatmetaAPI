<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Chat;
use App\Models\UserFollowers;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    protected $description = 'Start the native PHP WebSocket server for FlatMeta real-time avatar movement and live chat';

    /**
     * Array of client connections: resource_id => ['socket' => resource, 'handshake' => bool, 'buffer' => string]
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
        $this->info("  Node.js dependency: ELIMINATED");
        $this->info("=================================================");

        // Graceful signal handling
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
                    unset($this->clients[$id]);
                }
            }

            // Stream select with 200ms timeout
            $changed = @stream_select($read, $write, $except, 0, 200000);

            if ($changed === false) {
                break;
            }

            if ($changed > 0) {
                // New incoming client connection
                if (in_array($this->master, $read)) {
                    $newSocket = @stream_socket_accept($this->master, 0);
                    if ($newSocket) {
                        stream_set_blocking($newSocket, false);
                        $clientId = (int) $newSocket;
                        $this->clients[$clientId] = [
                            'socket' => $newSocket,
                            'handshake' => false,
                            'buffer' => '',
                            'ip' => stream_socket_get_name($newSocket, true)
                        ];
                        $this->line("<fg=cyan>[+]</> Client #{$clientId} connected from {$this->clients[$clientId]['ip']} (Active: " . count($this->clients) . ")");
                    }
                    unset($read[array_search($this->master, $read)]);
                }

                // Process client data
                foreach ($read as $clientSocket) {
                    $clientId = (int) $clientSocket;
                    if (!isset($this->clients[$clientId])) {
                        continue;
                    }

                    $data = @fread($clientSocket, 65536);

                    if ($data === false || $data === '') {
                        $this->disconnectClient($clientId, "Connection closed by client");
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
     * Perform RFC 6455 WebSocket Handshake.
     */
    protected function performHandshake(int $clientId, string $data): void
    {
        $client = &$this->clients[$clientId];
        $client['buffer'] .= $data;

        // Check if full HTTP header has been received
        $headerEndPos = strpos($client['buffer'], "\r\n\r\n");
        if ($headerEndPos === false) {
            return;
        }

        $headers = substr($client['buffer'], 0, $headerEndPos);
        $client['buffer'] = substr($client['buffer'], $headerEndPos + 4);

        if (!preg_match("/Sec-WebSocket-Key: *(.*?)\r\n/i", $headers, $match)) {
            $this->disconnectClient($clientId, "Handshake failed: Missing Sec-WebSocket-Key");
            return;
        }

        $key = trim($match[1]);
        $accept = base64_encode(sha1($key . self::WS_MAGIC_GUID, true));

        $response = "HTTP/1.1 101 Switching Protocols\r\n";
        $response .= "Upgrade: websocket\r\n";
        $response .= "Connection: Upgrade\r\n";
        $response .= "Sec-WebSocket-Accept: {$accept}\r\n";

        // RFC 6455: Only include Sec-WebSocket-Protocol if the client requested it
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

        // Process any frames received immediately after headers
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
            $fin = ($bytes[0] & 0x80) === 0x80;
            $opcode = $bytes[0] & 0x0F;
            $isMasked = ($bytes[1] & 0x80) === 0x80;
            $payloadLength = $bytes[1] & 0x7F;

            $offset = 2;

            if ($payloadLength === 126) {
                if (strlen($client['buffer']) < $offset + 2) {
                    return; // Wait for more data
                }
                $lenData = unpack('nlen', substr($client['buffer'], $offset, 2));
                $payloadLength = $lenData['len'];
                $offset += 2;
            } elseif ($payloadLength === 127) {
                if (strlen($client['buffer']) < $offset + 8) {
                    return; // Wait for more data
                }
                $lenData = unpack('Jlen', substr($client['buffer'], $offset, 8));
                $payloadLength = $lenData['len'];
                $offset += 8;
            }

            $maskingKey = '';
            if ($isMasked) {
                if (strlen($client['buffer']) < $offset + 4) {
                    return; // Wait for more data
                }
                $maskingKey = substr($client['buffer'], $offset, 4);
                $offset += 4;
            }

            if (strlen($client['buffer']) < $offset + $payloadLength) {
                return; // Full payload not yet arrived
            }

            $rawPayload = substr($client['buffer'], $offset, $payloadLength);
            $client['buffer'] = substr($client['buffer'], $offset + $payloadLength);

            // Unmask payload if masked
            $payload = '';
            if ($isMasked && strlen($maskingKey) === 4) {
                for ($i = 0; $i < $payloadLength; $i++) {
                    $payload .= $rawPayload[$i] ^ $maskingKey[$i % 4];
                }
            } else {
                $payload = $rawPayload;
            }

            // Handle opcodes
            switch ($opcode) {
                case 0x1: // Text frame
                    $this->handleTextMessage($clientId, $payload);
                    break;

                case 0x8: // Close connection
                    $this->sendFrame($clientId, '', 0x8);
                    $this->disconnectClient($clientId, "Client requested close");
                    return;

                case 0x9: // Ping
                    $this->sendFrame($clientId, $payload, 0xA); // Respond with Pong
                    break;

                case 0xA: // Pong
                    // Keep-alive received
                    break;

                default:
                    // Unsupported opcode
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

        // If no explicit 'event' key, infer event from payload structure
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
                $this->handleChatMessage($clientId, $data);
                break;

            case 'location':
            case 'emitLocation':
                $this->handleLocationUpdate($clientId, $data);
                break;

            case 'ping':
                $this->sendToClient($clientId, json_encode(['event' => 'pong', 'timestamp' => now()->toISOString()]));
                break;

            default:
                // Relay generic event to all other clients
                $broadcastPayload = json_encode([
                    'event' => $event,
                    'data' => $data
                ]);
                $this->broadcast($broadcastPayload, $clientId);
                break;
        }
    }

    /**
     * Process chat messages and persist to database.
     */
    protected function handleChatMessage(int $clientId, array $data): void
    {
        $roomId = $data['roomId'] ?? $data['room_id'] ?? null;
        $senderId = $data['senderId'] ?? $data['sender_id'] ?? null;
        $msgText = $data['message'] ?? $data['text'] ?? '';
        $createdAt = now()->toISOString();

        if (empty($msgText)) {
            return;
        }

        // Resolve numeric room ID if string like "room_X" or numeric
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

        // Persist to database if senderId and numericRoomId are valid database foreign keys
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
                $this->warn("Chat DB insert notice: " . $e->getMessage());
            }
        }

        $payload = [
            'roomId' => $roomId,
            'room_id' => $roomId,
            'senderId' => $senderId,
            'sender_id' => $senderId,
            'message' => $msgText,
            'created_at' => $createdAt,
            'createdAt' => $createdAt
        ];

        $json = json_encode([
            'event' => 'message',
            'data' => $payload
        ]);

        $this->line("<fg=yellow>[CHAT]</> Client #{$clientId} (Room: {$roomId}): " . substr($msgText, 0, 40));

        // Broadcast to all connected clients
        $this->broadcast($json);
    }

    /**
     * Process avatar location updates and broadcast to metaverse clients.
     */
    protected function handleLocationUpdate(int $clientId, array $data): void
    {
        $locData = $data['data'] ?? $data;

        $json = json_encode([
            'event' => 'location',
            'data' => $locData
        ]);

        // Broadcast to all clients EXCEPT the sender to prevent jitter
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
        $header = chr(0x80 | $opcode); // FIN = 1, Opcode

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
     * Cleanly disconnect a client.
     */
    protected function disconnectClient(int $clientId, string $reason = ''): void
    {
        if (isset($this->clients[$clientId])) {
            if (is_resource($this->clients[$clientId]['socket'])) {
                @fclose($this->clients[$clientId]['socket']);
            }
            unset($this->clients[$clientId]);
            $this->line("<fg=red>[-]</> Client #{$clientId} disconnected ({$reason}). Active: " . count($this->clients));
        }
    }

    /**
     * Graceful server shutdown.
     */
    public function shutdown(): void
    {
        $this->info("\nShutting down FlatMeta WebSocket server...");
        foreach (array_keys($this->clients) as $clientId) {
            $this->disconnectClient($clientId, "Server shutting down");
        }
        if (is_resource($this->master)) {
            @fclose($this->master);
        }
        exit(0);
    }
}
