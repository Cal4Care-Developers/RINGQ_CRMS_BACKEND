require('./servermode')('sso'); // [server-mode] production/debug logging, see servermode.js
const redis = require("redis");
const { Server } = require("socket.io");
const { io } = require("socket.io-client");
const admin = require("firebase-admin");
const serviceAccount2 = require("./secret.json");




admin.initializeApp({
  credential: admin.credential.cert(serviceAccount2),
});



let redisClient;

const sso = new Server({
  maxHttpBufferSize: 6e6,
  reconnection: true, // Default is true
  reconnectionAttempts: 5, // Number of attempts before giving up
  reconnectionDelay: 1000, // Initial delay in ms
  reconnectionDelayMax: 3000, // Maximum delay between attempts
});




(async () => {
  redisClient = redis.createClient();
  redisClient.on("error", (error) => console.error(`Error : ${error}`));
  await redisClient.connect();
})();

sso.listen(4050);
sso.use(async (socket, next) => {
  if (socket.handshake.headers["user-agent"] == "node-XMLHttpRequest") {
    next();
  } else {
    next();
  }
}).on("connection", (socket) => {
   const PUSH_TOKEN = "chvl2Vp8RZaWKIBGUn6jOY:APA91bFaIiqp0QNY_aMFtQ-zSYrAv-Dp8TBAas8G9tbiXU7e93AxjGH4ws8TIbC_aljhYP8VnVV_Aa-SjRdGbrmx6Qu3Wz5gs1n0uSKElshxsybJWgS4Q-c";
  socket.on("sso-initiate", async (payload, callback) => {
    console.log(payload);
    sso.to(payload["socket"]).emit("sso", {forgot: payload["forgot"], email: payload["email"], fqdn: payload["fqdn"]});
    // const response = await admin.messaging().sendEachForMulticast(
    //   batch.message,
    // );
      try {
        const response = await admin.messaging().sendEachForMulticast({
          tokens: [PUSH_TOKEN],
          notification: {
            title: "SSO Request",
            body: "You have a new sign-in request.",
          },
          android: {
            notification: {
              icon: "ic_notification", // Android drawable resource name, no extension
              color: "#0066CC",
              imageUrl: "https://multi.flowersconradplc.ringq.cloud/cloudapp/icons/Icon-192.png", // Optional image
            },
          },
          data: {
            forgot: String(payload["forgot"] ?? ""),
            email: String(payload["email"] ?? ""),
            fqdn: String(payload["fqdn"] ?? ""),
          },
        });
    
        console.log("Push result:", response);
      } catch (error) {
        console.error("Failed to send push notification:", error);
      }
  });
  socket.on("sso-initiate2", async (payload, callback) => {
    console.log(payload);
    sso.to(payload["socket"]).emit("sso2", payload["forgot"]);
  });
});
