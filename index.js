/**
 * DEPRECATED / LEGACY NOTICE:
 * Node.js & Socket.io have been eliminated.
 * Real-time metaverse avatar movement & live chat now run natively in PHP via:
 * 
 *   php artisan websocket:serve
 *   (or: composer websocket)
 * 
 * This file is retained only for historical reference.
 */
require('dotenv').config();
const express = require("express");
const bodyParser = require('body-parser');
const methodOverride = require('method-override')
const app = express();
const cors = require('cors');
const mysql = require('mysql');

let io = require('socket.io')();

const PORT = process.env.NODE_PORT || 3500;

app.use(bodyParser.json());
app.use(methodOverride());
app.use(cors());

//connecting to DataBase
var con = mysql.createConnection({
    host: process.env.DB_HOST,
    user: process.env.DB_USERNAME,
    password: process.env.DB_PASSWORD,
    database: process.env.DB_DATABASE
});

//to connect to database.
con.connect(function(err) {
    if (err) throw err;
    //console.log("DB Connected!");
});

const serverInstance = app.listen(PORT, () => {
    //console.log(`Server Running at port: ${PORT}`);
})

io.attach(serverInstance, {
    cors: {
        origin: '*',
    }
});

io.on('connection', (socket) => {
    // Handle incoming chat messages (supports both camelCase and snake_case)
    socket.on('message', async (message) => {
        try {
            if (!message) return;
            const roomId = message.roomId || message.room_id || null;
            const senderId = message.senderId || message.sender_id || null;
            const msgText = message.message || message.text || '';
            const now = new Date();

            if (roomId && senderId && msgText) {
                const sql = "INSERT INTO chats(room_id, sender_id, message, created_at) VALUES (?, ?, ?, ?)";
                con.query(sql, [roomId, senderId, msgText, now], (err, result) => {
                    if (err) {
                        console.error("Chat insert error:", err.message);
                    }
                });
            }

            const payload = {
                roomId: roomId,
                room_id: roomId,
                senderId: senderId,
                sender_id: senderId,
                message: msgText,
                created_at: now.toISOString(),
                createdAt: now.toISOString()
            };

            // Broadcast to all clients (both direct object and wrapped for compatibility)
            io.emit('message', payload);
            io.emit('chatMessage', payload);
        } catch (err) {
            console.error("Socket message error:", err);
        }
    });

    // Handle roaming avatar location updates
    const handleLocation = (data) => {
        try {
            if (!data) return;
            const locationData = data.data || data;
            io.emit('location', { data: locationData });
            io.emit('emitLocation', { data: locationData });
        } catch (err) {
            console.error("Socket location error:", err);
        }
    };

    socket.on('location', handleLocation);
    socket.on('emitLocation', handleLocation);

    socket.on('disconnect', () => {
        // Handled silently
    });
});