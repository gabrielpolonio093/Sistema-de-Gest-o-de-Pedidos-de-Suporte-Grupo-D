import os
import sqlite3

DB_PATH = os.path.join("instance", "suporte.db")


def init_db():
    os.makedirs("instance", exist_ok=True)
    with sqlite3.connect(DB_PATH) as conn:
        conn.execute("""
            CREATE TABLE IF NOT EXISTS pedidos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                titulo TEXT NOT NULL,
                descricao TEXT NOT NULL,
                categoria TEXT NOT NULL,
                prioridade TEXT NOT NULL,
                estado TEXT NOT NULL DEFAULT 'Aberto'
            )
        """)