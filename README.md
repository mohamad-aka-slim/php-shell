# php-shell — PHP ↔ Python Relay Shell (Educational Lab)

> ⚠️ **Ethics & legality:** This project exists purely to *understand* how
> interpreters, sockets and relays work — and how to defeat them. Only ever run
> it between machines you own or with explicit written permission. Using
> shells like this on a system without consent is a crime (comparable in kind
> to breaking and entering: most countries' computer-misuse laws apply).

---

## 1. What it does

A minimal TCP relay that links two interpreters running anywhere:

```
┌──────────────────────────┐          TCP :9000         ┌──────────────────────────┐
│  Windows (host)          │ ◀────────────────────────▶ │  WSL2 / Kali             │
│  python-tcp/server.py    │                            │  index.php (relay)       │
│  = operator console      │        relays bytes        │  └─ spawns shell:        │
│     (you type commands)  │                            │     Windows: cmd.exe     │
│                          │                            │     Linux:   script -qec │
│                          │                            │           "bash -i" (PTY)│
└──────────────────────────┘                            └──────────────────────────┘
```

* **`python-tcp/server.py`** — Python TCP server. Accepts one PHP connection,
  streams everything PHP sends, and sends every line you type in its console
  to the PHP side (as `\r\n` = "Enter").
* **`index.php`** — connects to the server, spawns the *local* machine's shell,
  and permanently shuttles bytes between socket and shell. It auto-detects:
  * the right **shell** (`cmd.exe` on Windows; `bash/zsh/sh` in a PTY on Linux
    — via `script -qec` so sudo-like programs that need a TTY work),
  * the right **target address** (`127.0.0.1` normally; the *Windows host IP*
    automatically when running inside WSL2),
  * and **daemonizes itself** (Unix: `pcntl_fork`+`setsid`; Windows: detached
    `start /MIN` window) so it survives after the launcher closes.

---

## 2. Running it

```powershell
# Terminal 1 — the operator console (Windows)
python -u python-tcp\server.py

# Terminal 2 — the relay (from Windows, or inside WSL with the same file)
php index.php          # self-detaches into its own minimized window
```

Then type commands in the Python console (`dir`, `ls`, `sudo -s`, `exit`).

Debug/override flags (all in `index.php`):

| Flag | Purpose |
|---|---|
| `--no-daemon` | stay in foreground, see all output (debugging) |
| `--ip=<x>` | connect to a specific host (auto WSL detection exists) |
| `--shell="..."` | spawn a different program instead of the auto-picked shell |

---

## 3. Lessons this lab teaches (the hard way)

1. **`0.0.0.0` is a bind address, not a destination.** Connecting to it
   fails; a client must connect to a real IP such as `127.0.0.1`.
2. **A response missing `\n` deadlocks line readers** — PHP's `fgets()`
   waits forever for a newline.
3. **Windows PHP ignores `stream_set_blocking()` on `proc_open` pipes** and
   blocks forever on reads of empty pipes. `stream_select()` on pipes is
   unreliable there. Workaround used: redirect the child's stdout/stderr to
   temp *files* and tail them (files never block); keep the socket in
   `stream_select`. On Linux, the classic select-all loop works.
4. **WSL2 networking:** `127.0.0.1` inside WSL2 (NAT mode) is WSL's own VM,
   *not* Windows. To reach a Windows service, connect to the **default
   gateway** seen from WSL (`ip route show default` → `172.30.x.1`). Confirmed
   working with both a Python probe and PHP.
5. **Duplicate listeners are chaos:** with `SO_REUSEADDR` on Windows, two
   processes can bind the same port and connections get split between them
   unpredictably. One service per port per host — always.
6. **Console encodings bite** (cp1252 on Windows vs UTF-8 from bash) — use
   tolerant I/O (`sys.stdout.reconfigure(errors="replace")`).
7. **Programs needing a TTY (sudo, su, nano) refuse to run without a PTY.**
   `script -qec "..." /dev/null` provides one.

---

## 4. Protecting your systems from this class of attack

This relay is nothing exotic — it's the same pattern used by real malware
(MITRE ATT&CK **T1059 Command & Scripting Interpreter** + **T1071/T1090**
command-and-control/backchannel). Successful use of it against you means an
attacker can already run PHP — so *hardening focuses on preventing the chain*,
not only the relay itself.

### 4.1 Remove the capabilities that make this possible

| Control | Why it works |
|---|---|
| **Disable PHP "execute-program" functions** in `php.ini`: `disable_functions = exec,shell_exec,system,proc_open,popen,passthru,pcntl_*` | `proc_open` is the core of `index.php`. No `proc_open` → no relay. |
| **Don't run PHP web apps as Administrator/root**, and never grant the web user a sudoers rule | Least privilege makes the relay strategically useless even if somehow started |
| Remove `script`/`util-linux` from minimal hosts where not needed; keep WSL distributions minimal | Removes the PTY capability that lets sudo run through pipes |
| **Kill the network channel**: block outbound connections from `python.exe`/`php.exe` in user-writable paths (Windows Defender Firewall egress rules); on corporate networks use destination allowlists | A relay is useless if neither side can reach the other |

### 4.2 Detect it (what it looks like on the wire/process list)

* A `python`/`php` process with a **LISTENING TCP port** that the user never
  intentionally opened (`netstat -ano`, `Get-NetTCPConnection -State Listen`).
* `php` under a `proc_open` of `cmd.exe`/`script`/`bash` (Sysmon Event ID 1:
  `Image: php.exe` + `ChildProcess` chains).
* **Long-lived socket** in `ESTABLISHED` on an odd port (`9000` here but
  anything will do), matching a "console".
* Fork storms or a `php` process in a minimized window with no UI
  (`Get-Process php`, Event ID 4688: *new process created*).
* `sudo`/`su` authentication events outside helpdesk hours on unusual shells.

### 4.3 Practice defense with this lab (blue-team reps)

Because both endpoints can be run on one machine, this project is a great
safe *detection target*: start a relay, then hunt it down with netstat,
Sysmon, and by searching for the exact artifacts listed in 4.2, and confirm
that the mitigations in 4.1 block each step.

---

## 5. Files

```
php-shell/
├── index.php             # relay client: connects, spawns shell, shuttles bytes
├── python-tcp/
│   └── server.py         # operator console server on tcp/9000
└── README.md             # this file
```
