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
        origin: 'http://localhost:8100',
    }
});

io.on('connection', (socket) => {

    socket.on('message', async(message) => {
        try {

            //Mysql Insert
            //let sql = "SELECT * FROM  users where (email = '" + req.body.email + "')";
            let sql = "INSERT INTO chats(room_id, sender_id, message) VALUES ('" + message.room_id + "','" + message.sender_id + "','" + message.message + "')";
            con.query(sql, (err, result) => {
                if (err) throw err;
            });

            io.emit('message', { data: message });
        } catch (err) {
            console.error(err)
        }
    });

    socket.on('location', async(message) => {
        try {
            io.emit('location', { data: message });
        } catch (err) {
            console.error(err)
        }
    });

});