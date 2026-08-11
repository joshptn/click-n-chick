from fastapi import WebSocket, WebSocketDisconnect, FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware
import json
import uvicorn
from dotenv import load_dotenv
import os

app = FastAPI()
load_dotenv()

# CORS
origins = os.getenv("CORS_ORIGINS", "").split(",")
app.add_middleware(
    CORSMiddleware,
    allow_origins=[origin.strip() for origin in origins if origin.strip()],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


# -------------------------
# Connection Manager
# -------------------------
class ConnectionManager:
    def __init__(self, isUserID: bool = False):
        self.isUserID = isUserID
        if self.isUserID:
            # Store connections per user_id
            self.active_connections: dict[int, list[WebSocket]] = {}
        else:
            self.active_connections: list[WebSocket] = []

    async def connect(self, websocket: WebSocket, user_id: int = None):
        await websocket.accept()
        if self.isUserID:
            if user_id is None:
                raise ValueError("user_id is required for per-user connections")
            if user_id not in self.active_connections:
                self.active_connections[user_id] = []
            self.active_connections[user_id].append(websocket)
            print(f"🔗 User {user_id} connected. Total: {len(self.active_connections[user_id])}")
        else:
            self.active_connections.append(websocket)
            print(f"🔗 Client connected. Total: {len(self.active_connections)}")

    def disconnect(self, websocket: WebSocket, user_id: int = None):
        if self.isUserID:
            if user_id in self.active_connections and websocket in self.active_connections[user_id]:
                self.active_connections[user_id].remove(websocket)
                print(f"❌ User {user_id} disconnected. Remaining: {len(self.active_connections[user_id])}")
                if not self.active_connections[user_id]:
                    del self.active_connections[user_id]
        else:
            if websocket in self.active_connections:
                self.active_connections.remove(websocket)
                print(f"❌ Client disconnected. Total: {len(self.active_connections)}")

    async def broadcast(self, message: str, sender: WebSocket = None, user_id: int = None):
        to_remove = []

        if self.isUserID:
            if user_id not in self.active_connections:
                print(f"⚠️ No active connections for user {user_id}")
                return
            for conn in self.active_connections[user_id]:
                try:
                    await conn.send_text(message)
                except Exception as e:
                    print(f"⚠️ Failed to send to user {user_id}: {e}")
                    to_remove.append(conn)
            for conn in to_remove:
                self.disconnect(conn, user_id)
        else:
            for conn in self.active_connections:
                if conn != sender:
                    try:
                        await conn.send_text(message)
                    except Exception as e:
                        print(f"⚠️ Failed to send to client: {e}")
                        to_remove.append(conn)
            for conn in to_remove:
                self.disconnect(conn)


# Managers for order and food
order_manager = ConnectionManager(isUserID=True)
admin_order_manager = ConnectionManager()
food_manager = ConnectionManager()
notification_manager = ConnectionManager(isUserID=True)


# -------------------------
# WebSocket Routes
# -------------------------
@app.websocket("/ws/order/{user_id}")
async def order_ws(websocket: WebSocket, user_id: int):
    await order_manager.connect(websocket, user_id=user_id)
    try:
        while True:
            data = await websocket.receive_text()
            print(data)
            await order_manager.broadcast(
                f"Client: {data}", sender=websocket
            )
    except WebSocketDisconnect:
        order_manager.disconnect(websocket, user_id=user_id)
        
@app.websocket("/ws/order")
async def admin_order_ws(websocket: WebSocket):
    await admin_order_manager.connect(websocket)
    try:
        while True:
            data = await websocket.receive_text()
            print(data)
            await admin_order_manager.broadcast(
                f"Client: {data}", sender=websocket
            )
    except WebSocketDisconnect:
        admin_order_manager.disconnect(websocket)
        
@app.websocket("/ws/notify/{user_id}")
async def notify_ws(websocket: WebSocket, user_id: int):
    await notification_manager.connect(websocket, user_id=user_id)
    try:
        while True:
            data = await websocket.receive_text()
            print(data)
            await notification_manager.broadcast(
                f"Client {user_id}: {data}", sender=websocket
            )
    except WebSocketDisconnect:
        notification_manager.disconnect(websocket, user_id=user_id)



@app.websocket("/ws/food")
async def food_ws(websocket: WebSocket):
    await food_manager.connect(websocket)
    try:
        while True:
            data = await websocket.receive_text()
            print(data)
            await food_manager.broadcast(f"Client: {data}", sender=websocket)
    except WebSocketDisconnect:
        food_manager.disconnect(websocket)


# -------------------------
# HTTP Broadcast Routes
# -------------------------
@app.post("/broadcast/order")
async def broadcast_order(request: Request):
    data = await request.json()
    payload = {
        "type": "order",
        "event": data.get("event", ""),
        "order": data.get("order", {}),
        "user_id": data.get("user_id", ""),
    }
    print(payload)
    await order_manager.broadcast(json.dumps(payload), user_id=payload["user_id"])
    await admin_order_manager.broadcast(json.dumps(payload))
    return {"status": "ok", "broadcasted": payload}


@app.post("/broadcast/notify")
async def broadcast_notification(request: Request):
    data = await request.json()
    payload = {
        "type": "notification",
        "event": data.get("event", ""),
        "order": data.get("order", {}),
        "user_id": data.get("user_id", ""),
    }
    await notification_manager.broadcast(json.dumps(payload), user_id=int(payload["user_id"]))
    return {"status": "ok", "broadcasted": payload}

@app.post("/broadcast/food")
async def broadcast_food(request: Request):
    data = await request.json()
    payload = {
        "type": "food",
        "event": data.get("event", ""),
        "food": data.get("food", {}),
    }
    await food_manager.broadcast(json.dumps(payload))
    return {"status": "ok", "broadcasted": payload}


# -------------------------
# Run app
# -------------------------
if __name__ == "__main__":
    uvicorn.run("main:app", host="0.0.0.0", port=8001, reload=True)
