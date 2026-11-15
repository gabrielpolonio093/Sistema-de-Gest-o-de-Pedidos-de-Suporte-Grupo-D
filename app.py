import tkinter as tk
from database import init_db

init_db()

root = tk.Tk()
root.title("Gestão de Pedidos de Suporte - Grupo D")
root.geometry("600x400")

tk.Label(root, text="Sistema de Gestão de Pedidos de Suporte", font=("Arial", 14)).pack(pady=20)

root.mainloop()