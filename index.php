<?php
set_time_limit(0);

// ---------------------------------------------------------------------------
// Daemonise ourself if possible, so the relay keeps running even after the
// console that launched it is closed, and no orphaned PHP process stays tied
// to the caller (avoids leftover "zombie-like" copies).
//   Unix:    pcntl_fork() + setsid()
//   Windows: pcntl doesn't exist there, so relaunch detached in a new
//            minimized console window (`start`) instead.
// ---------------------------------------------------------------------------
$daemonized = in_array('--daemonized', $argv ?? [], true);
$no_daemon  = in_array('--no-daemon', $argv ?? [], true);   // debug: stay in foreground

if (!$daemonized && !$no_daemon) {
    if (function_exists('pcntl_fork') && function_exists('posix_setsid')) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            echo "WARNING: Failed to daemonise (fork failed)\n";
        } else {
            if ($pid > 0) {
                exit(0);    // Parent exits; child carries on detached
            }
            posix_setsid(); // Child: detach from the controlling terminal
        }
    } elseif (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        echo "Detaching relay into its own minimized console window...\n";
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $cmdline = 'start "PHP Relay" /MIN ' . escapeshellarg($php) . ' ' .
                   escapeshellarg(__FILE__) . ' --daemonized';
        pclose(popen($cmdline, 'r'));
        exit(0);
    }
}

// Where the Python relay server is running
$ip = pick_server_ip();
$port = 9000;
$chunk_size = 1400;

/**
 * Pick the server address for "any scenario":
 *   - Same machine (Windows PHP):            127.0.0.1
 *   - PHP inside WSL2 + Python on Windows:   Windows host = the default
 *     gateway of the WSL2 virtual network (127.0.0.1 would be WSL itself,
 *     and reports "Connection refused").
 *   - Other machines: pass --ip=1.2.3.4 on the command line.
 */
function pick_server_ip() {
    global $argv;
    foreach ((array)($argv ?? []) as $a) {
        if (preg_match('/^--ip=(.+)$/', $a, $m)) {
            return $m[1];
        }
    }

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        return '127.0.0.1';
    }

    // Inside WSL? (WSL sets WSL_DISTRO_NAME / WSL_INTEROP environment vars)
    if (getenv('WSL_DISTRO_NAME') !== false || getenv('WSL_INTEROP') !== false) {
        $route = @shell_exec('ip route show default 2>/dev/null');
        if ($route !== null && preg_match('/default via ([0-9.]+)/', $route, $m)) {
            return $m[1]; // Windows host as seen from the WSL side
        }
        // Couldn't detect — staying on loopback is wrong on WSL2, so warn:
        echo "WARNING: In WSL but couldn't detect the Windows host IP. " .
             "Run with --ip=<windowsIP>\n";
    }

    return '127.0.0.1';
}

/**
 * Pick the shell to spawn based on the environment.
 * Covers any scenario: Windows, Linux, macOS, bash/zsh/sh present or not.
 * To force a specific shell, set SHELL_OVERRIDE before launching, e.g.:
 *   php index.php --shell="powershell.exe"  (see below)
 */
function pick_shell() {
    // Manual override from CLI for testing/edge cases: --shell="..."
    global $argv;
    foreach ((array)($argv ?? []) as $a) {
        if (preg_match('/^--shell=(.+)$/', $a, $m)) {
            return $m[1];
        }
    }

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // cmd.exe is always present on Windows (PowerShell may not be)
        return 'cmd.exe';
    }

    // Unix: prefer an interactive bash/zsh, fall back to sh
    $base = null;
    foreach (array(
        '/bin/bash -i',
        '/usr/bin/bash -i',
        '/bin/zsh -i',
        '/usr/bin/zsh -i',
        '/bin/sh -i',
    ) as $candidate) {
        $bin = strpos($candidate, ' ') !== false ? substr($candidate, 0, strpos($candidate, ' ')) : $candidate;
        if (@is_executable($bin)) {
            $base = $candidate;
            break;
        }
    }
    if ($base === null) {
        $envShell = getenv('SHELL');
        $base = ($envShell !== false && $envShell !== '') ? $envShell . ' -i' : 'sh -i';
    }

    // Wrap in a PTY (util-linux 'script') if available so that programs that
    // need a terminal (sudo password prompts, su, nano, etc.) work through
    // the pipe-relay too.
    if (@is_executable('/usr/bin/script')) {
        return '/usr/bin/script -qec ' . escapeshellarg($base) . ' /dev/null';
    }
    return $base;
}

// Shell to spawn (auto-detected above)
$shell = pick_shell();

// Connect to the Python server
echo "Connecting to $ip:$port...\n";
$sock = @fsockopen($ip, $port, $errno, $errstr, 10);
if (!$sock) {
    echo "Connect failed: $errstr ($errno)\n";
    exit(1);
}

// Consume the Python server's one-line banner before relaying
$banner = fgets($sock);
if ($banner === false) {
    echo "WARNING: no server banner received (check that only ONE server is running)\n";
}

// ---------------------------------------------------------------------------
// On Windows, PHP ignores stream_set_blocking() on proc_open pipes and blocks
// forever when reading an empty pipe. So instead of reading the shell's
// stdout/stderr from pipes, we redirect them to temp files and tail those.
// Only stdin stays a pipe (it only ever receives what PHP already has).
// ---------------------------------------------------------------------------
$outFile = tempnam(sys_get_temp_dir(), 'shell_out_');
$errFile = tempnam(sys_get_temp_dir(), 'shell_err_');

$descriptorspec = array(
    0 => array("pipe", "r"),           // stdin: pipe the shell reads commands from
    1 => array("file", $outFile, "w"), // stdout: file the shell writes output to
    2 => array("file", $errFile, "w")  // stderr: file the shell writes errors to
);

$process = proc_open($shell, $descriptorspec, $pipes);

if (!is_resource($process)) {
    echo "ERROR: Can't spawn shell\n";
    exit(1);
}

stream_set_blocking($sock, false);

$outPos = 0;
$errPos = 0;

/**
 * Send any new bytes appended to $file (past $pos) to the socket.
 * File reads never block, unlike pipes on Windows.
 */
function tail_send($file, &$pos, $sock) {
    clearstatcache(true, $file);
    $sz = @filesize($file);
    if ($sz === false || $sz <= $pos) {
        return;
    }
    $fh = @fopen($file, 'rb');
    if (!$fh) {
        return;
    }
    fseek($fh, $pos);
    $d = @fread($fh, $sz - $pos);
    @fclose($fh);
    if ($d !== false && $d !== '') {
        $pos += strlen($d);
        @fwrite($sock, $d);
    }
}

echo "Shell spawned, relaying between $ip:$port and the local shell\n";

while (1) {
    // Wait up to 200ms for data coming from the Python side
    $read_a  = array($sock);
    $write_a = null;
    $error_a = null;
    $num = @stream_select($read_a, $write_a, $error_a, 0, 200000);

    if ($num === false) {
        break;
    }

    // Data from Python -> shell's STDIN
    if (in_array($sock, $read_a)) {
        $input = @fread($sock, $chunk_size);
        if ($input === false || ($input === '' && feof($sock))) {
            echo "ERROR: relay connection terminated\n";
            break;
        }
        if ($input !== '') {
            @fwrite($pipes[0], $input);
        }
    }

    // Shell STDOUT/STDERR (tail the temp files) -> Python
    tail_send($outFile, $outPos, $sock);
    tail_send($errFile, $errPos, $sock);

    // Shell exited?
    $status = @proc_get_status($process);
    if (is_array($status) && !$status['running']) {
        echo "Shell process terminated\n";
        break;
    }
}

@fclose($sock);
@fclose($pipes[0]);
proc_terminate($process);
proc_close($process);
@unlink($outFile);
@unlink($errFile);
echo "Ended\n";
